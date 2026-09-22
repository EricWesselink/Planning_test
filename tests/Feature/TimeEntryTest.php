<?php

namespace Tests\Feature;

use App\Enums\TimeEntryStatus;
use App\Enums\UserRole;
use App\Models\CrewMember;
use App\Models\Customer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TimeEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_vakman_submits_hours_from_planned_work(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();

        $this->actingAs($vakman)
            ->from(route('vakman.planning.day', '2026-09-21'))
            ->post(route('vakman.hours.store'), [
                'date' => '2026-09-21',
                'worker_assignment_id' => $assignment->id,
                'work_item_id' => $item->id,
                'hours' => '8',
                'note' => 'Hele dag linoleum',
            ])
            ->assertRedirect(route('vakman.planning.day', '2026-09-21'))
            ->assertSessionHas('status', '8u ingediend');

        $entry = TimeEntry::query()->first();
        $this->assertNotNull($entry);
        $this->assertSame(8.0, $entry->hoursValue());
        $this->assertSame(TimeEntryStatus::Submitted, $entry->status);
        $this->assertFalse($entry->is_unplanned);
        $this->assertSame($vakman->id, $entry->submitted_by);
        $this->assertSame(0, WorkProgressEntry::query()->count());
    }

    public function test_vakman_can_change_hours_before_approval(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '8',
        ]);
        $entry = TimeEntry::query()->first();

        $this->actingAs($vakman)
            ->patch(route('vakman.hours.update', $entry), [
                'hours' => '6',
                'note' => 'Eerder klaar',
            ])
            ->assertRedirect();

        $this->assertSame(6.0, $entry->fresh()->hoursValue());
        $this->assertSame(TimeEntryStatus::Submitted, $entry->fresh()->status);
    }

    public function test_vakman_cannot_change_hours_after_approval(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '8',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create();
        $this->actingAs($leader)->post(route('personnel.hours.approve', $entry));

        $this->actingAs($vakman)
            ->from(route('vakman.planning.day', '2026-09-21'))
            ->patch(route('vakman.hours.update', $entry), ['hours' => '4'])
            ->assertForbidden();

        $this->assertSame(8.0, $entry->fresh()->hoursValue());
        $this->assertTrue($entry->fresh()->isApproved());
    }

    public function test_vakman_can_split_hours_over_two_projects_on_one_day(): void
    {
        [$vakman, $first, $item] = $this->plannedVakman();
        $other = $this->makeProject('Tweede werk');
        $otherItem = WorkItem::query()->create([
            'project_id' => $other->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 40,
            'status' => 'in_uitvoering',
        ]);
        $second = WorkerAssignment::query()->create([
            'worker_id' => $first->worker_id,
            'project_id' => $other->id,
            'work_item_id' => $otherItem->id,
            'start_date' => '2026-09-21',
            'end_date' => '2026-09-21',
            'start_time' => '12:00:00',
            'end_time' => '16:00:00',
            'hours_per_day' => 4,
            'planned_hours' => 4,
        ]);

        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $first->id,
            'work_item_id' => $item->id,
            'hours' => '5',
        ])->assertRedirect();
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $second->id,
            'work_item_id' => $otherItem->id,
            'hours' => '3',
        ])->assertRedirect();

        $this->assertSame(2, TimeEntry::query()->count());
        $this->assertSame(8.0, (float) TimeEntry::query()->sum('hours'));
    }

    public function test_vakman_can_submit_unplanned_hours(): void
    {
        [$vakman] = $this->plannedVakman();
        $extra = $this->makeProject('Spoedklus');
        $item = WorkItem::query()->create([
            'project_id' => $extra->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 20,
            'status' => 'in_uitvoering',
        ]);

        $this->actingAs($vakman)
            ->post(route('vakman.hours.store'), [
                'date' => '2026-09-21',
                'project_id' => $extra->id,
                'work_item_id' => $item->id,
                'hours' => '6',
            ])
            ->assertRedirect();

        $entry = TimeEntry::query()->first();
        $this->assertTrue($entry->is_unplanned);
        $this->assertNull($entry->worker_assignment_id);
        $this->assertSame(0, WorkerAssignment::query()->where('origin', 'hours')->count());
        $this->assertSame(0, WorkProgressEntry::query()->count());
    }

    public function test_projectleider_approves_hours_and_writes_progress_once(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '6',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create();

        $this->actingAs($leader)
            ->post(route('personnel.hours.approve', $entry))
            ->assertRedirect();

        $entry = $entry->fresh();
        $this->assertTrue($entry->isApproved());
        $this->assertSame($leader->id, $entry->reviewed_by);
        $this->assertNotNull($entry->processed_at);
        $this->assertSame(1, WorkProgressEntry::query()->count());
        $progress = WorkProgressEntry::query()->first();
        $this->assertSame(6.0, (float) $progress->worked_hours);
        $this->assertSame($item->id, $progress->work_item_id);

        $this->actingAs($leader)->post(route('personnel.hours.approve', $entry));
        $this->assertSame(1, WorkProgressEntry::query()->count());
        $this->assertSame(6.0, (float) $progress->fresh()->worked_hours);
        $this->assertSame(8.0, $assignment->fresh()->plannedHoursValue());
    }

    public function test_approving_unplanned_hours_adds_actual_assignment_without_planned_hours(): void
    {
        [$vakman] = $this->plannedVakman();
        $extra = $this->makeProject('Spoedklus');
        $item = WorkItem::query()->create([
            'project_id' => $extra->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 20,
            'status' => 'in_uitvoering',
        ]);
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-22',
            'project_id' => $extra->id,
            'work_item_id' => $item->id,
            'hours' => '6',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create();

        $this->actingAs($leader)->post(route('personnel.hours.approve', $entry));

        $actual = WorkerAssignment::query()->where('origin', 'hours')->first();
        $this->assertNotNull($actual);
        $this->assertSame(0.0, $actual->plannedHoursValue());
        $this->assertSame(6.0, (float) $actual->hours_per_day);
        $this->assertSame(1, WorkProgressEntry::query()->count());
    }

    public function test_projectleider_rejects_submitted_hours(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '8',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create();

        $this->actingAs($leader)
            ->post(route('personnel.hours.reject', $entry), ['review_note' => 'Te hoog'])
            ->assertRedirect();

        $this->assertTrue($entry->fresh()->isRejected());
        $this->assertSame('Te hoog', $entry->fresh()->review_note);
        $this->assertSame(0, WorkProgressEntry::query()->count());
    }

    public function test_vakman_day_shows_hours_form_from_planning(): void
    {
        [$vakman] = $this->plannedVakman();

        $this->actingAs($vakman)
            ->get(route('vakman.planning.day', '2026-09-21'))
            ->assertOk()
            ->assertSee('Uren indienen')
            ->assertSee('name="start_time"', false)
            ->assertSee('name="end_time"', false)
            ->assertSee('name="break_minutes"', false)
            ->assertSee('>Van<', false)
            ->assertSee('>Tot<', false)
            ->assertSee('>Pauze<', false)
            ->assertSee('Uren op niet-gepland werk')
            ->assertDontSee('Gewerkte uren');
    }

    public function test_standard_registered_day_is_eight_hours_and_five_days_are_forty(): void
    {
        $this->assertSame(8.0, PlanningHours::netHours('07:30', '16:30', 60));
        $this->assertSame(6.0, PlanningHours::netHours('07:30', '14:00', 30));

        [$vakman, $assignment, $item] = $this->plannedVakman();
        $assignment->applySchedule(
            Carbon::parse('2026-09-21'),
            Carbon::parse('2026-09-25'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();

        $this->actingAs($vakman)
            ->get(route('vakman.planning.day', '2026-09-21'))
            ->assertOk()
            ->assertSee('name="start_time" required value="07:30"', false)
            ->assertSee('name="end_time" required value="16:30"', false)
            ->assertSee('name="break_minutes" min="0" max="1440" step="1" required value="60"', false);

        foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $date) {
            $this->actingAs($vakman)
                ->post(route('vakman.hours.store'), [
                    'date' => $date,
                    'worker_assignment_id' => $assignment->id,
                    'work_item_id' => $item->id,
                    'start_time' => '07:30',
                    'end_time' => '16:30',
                    'break_minutes' => 60,
                ])
                ->assertRedirect()
                ->assertSessionHas('status', '8u ingediend');
        }

        $entries = TimeEntry::query()->orderBy('date')->get();
        $this->assertCount(5, $entries);
        $this->assertSame('07:30', $entries->first()->startTimeLabel());
        $this->assertSame('16:30', $entries->first()->endTimeLabel());
        $this->assertSame(60, (int) $entries->first()->break_minutes);
        $this->assertSame(8.0, $entries->first()->submittedHoursValue());
        $this->assertSame(40.0, round($entries->sum(fn (TimeEntry $entry): float => $entry->submittedHoursValue()), 2));
    }

    public function test_extra_jobs_on_one_day_stay_blank_and_saved_hours_keep_their_clock(): void
    {
        [$vakman, $first, $item] = $this->plannedVakman();
        $secondItem = WorkItem::query()->create([
            'project_id' => $first->project_id,
            'name' => 'Plinten',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'in_uitvoering',
        ]);
        $second = new WorkerAssignment([
            'worker_id' => $first->worker_id,
            'project_id' => $first->project_id,
            'work_item_id' => $secondItem->id,
        ]);
        $second->applySchedule(
            Carbon::parse('2026-09-21'),
            Carbon::parse('2026-09-21'),
            '14:00:00',
            '16:30:00',
        );
        $second->save();

        $this->actingAs($vakman)
            ->get(route('vakman.planning.day', '2026-09-21'))
            ->assertOk()
            ->assertDontSee('value="07:30"', false)
            ->assertDontSee('value="60"', false);

        $this->actingAs($vakman)
            ->post(route('vakman.hours.store'), [
                'date' => '2026-09-21',
                'worker_assignment_id' => $first->id,
                'work_item_id' => $item->id,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'break_minutes' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '2,5u ingediend');

        $entry = TimeEntry::query()->first();
        $this->assertSame('09:00', $entry->startTimeLabel());
        $this->assertSame('12:00', $entry->endTimeLabel());
        $this->assertSame(30, (int) $entry->break_minutes);
        $this->assertSame(2.5, $entry->submittedHoursValue());

        $this->actingAs($vakman)
            ->get(route('vakman.planning.day', '2026-09-21'))
            ->assertOk()
            ->assertSee('name="start_time" required value="09:00"', false)
            ->assertSee('name="end_time" required value="12:00"', false)
            ->assertSee('name="break_minutes" min="0" max="1440" step="1" required value="30"', false)
            ->assertDontSee('value="07:30"', false);
    }

    public function test_vakman_registers_separate_works_by_clock_without_overlap(): void
    {
        [$vakman, $first, $item] = $this->plannedVakman('Peter');
        $secondItem = WorkItem::query()->create([
            'project_id' => $first->project_id,
            'name' => 'Plinten',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'in_uitvoering',
        ]);
        $second = new WorkerAssignment([
            'worker_id' => $first->worker_id,
            'project_id' => $first->project_id,
            'work_item_id' => $secondItem->id,
        ]);
        $second->applySchedule(
            Carbon::parse('2026-09-21'),
            Carbon::parse('2026-09-21'),
            '14:00:00',
            '16:30:00',
        );
        $second->save();

        $this->actingAs($vakman)
            ->post(route('vakman.hours.store'), [
                'date' => '2026-09-21',
                'worker_assignment_id' => $first->id,
                'work_item_id' => $item->id,
                'start_time' => '07:30',
                'end_time' => '14:00',
                'break_minutes' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '6,5u ingediend');

        $this->actingAs($vakman)
            ->post(route('vakman.hours.store'), [
                'date' => '2026-09-21',
                'worker_assignment_id' => $second->id,
                'work_item_id' => $secondItem->id,
                'start_time' => '14:00',
                'end_time' => '16:30',
                'break_minutes' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '2,5u ingediend');

        $entries = TimeEntry::query()->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('07:30', $entries[0]->startTimeLabel());
        $this->assertSame('14:00', $entries[0]->endTimeLabel());
        $this->assertSame(0, (int) $entries[0]->break_minutes);
        $this->assertSame(6.5, $entries[0]->submittedHoursValue());
        $this->assertSame(2.5, $entries[1]->submittedHoursValue());
        $this->assertSame(9.0, round($entries->sum(fn (TimeEntry $entry): float => $entry->submittedHoursValue()), 2));

        $this->actingAs($vakman)
            ->from(route('vakman.planning.day', '2026-09-21'))
            ->patch(route('vakman.hours.update', $entries[1]), [
                'start_time' => '13:00',
                'end_time' => '15:00',
                'break_minutes' => 0,
            ])
            ->assertRedirect(route('vakman.planning.day', '2026-09-21'))
            ->assertSessionHasErrors([
                'start_time' => 'Deze tijden overlappen met een andere urenregel op deze dag.',
            ]);

        $this->assertSame('14:00', $entries[1]->fresh()->startTimeLabel());
        $this->assertSame(2.5, $entries[1]->fresh()->submittedHoursValue());
    }

    public function test_reviewer_corrects_clock_times_and_keeps_the_submitted_times(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman('Peter');
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'start_time' => '07:30',
            'end_time' => '16:30',
            'break_minutes' => 30,
        ])->assertSessionHas('status', '8,5u ingediend');
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create(['name' => 'Eric']);
        $weekstaat = $this->weekstaatDay($assignment);

        $this->actingAs($leader)
            ->get($weekstaat)
            ->assertOk()
            ->assertSee('Gepland:')
            ->assertSee('8u')
            ->assertSee('Ingediend:')
            ->assertSee('07:30–16:30')
            ->assertSee('Pauze:')
            ->assertSee('30 min')
            ->assertSee('Netto:')
            ->assertSee('8,5u')
            ->assertSee('name="approved_start_time"', false)
            ->assertSee('name="approved_break_minutes"', false);

        $this->actingAs($leader)
            ->patch(route('personnel.hours.update', $entry), [
                'approved_start_time' => '07:30',
                'approved_end_time' => '16:00',
                'approved_break_minutes' => 30,
                'review_note' => 'Eerder gestopt',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '8u aangepast en goedgekeurd.');

        $entry = $entry->fresh();
        $this->assertSame('07:30', $entry->startTimeLabel());
        $this->assertSame('16:30', $entry->endTimeLabel());
        $this->assertSame(30, (int) $entry->break_minutes);
        $this->assertSame(8.5, $entry->submittedHoursValue());
        $this->assertSame('16:00', $entry->approvedEndLabel());
        $this->assertSame(8.0, $entry->approvedHoursValue());

        $this->actingAs($leader)
            ->patch(route('personnel.hours.update', $entry), [
                'approved_start_time' => '08:00',
                'approved_end_time' => '08:00',
                'approved_break_minutes' => 0,
                'review_note' => 'Na controle geen uren',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '0u aangepast en goedgekeurd.');

        $entry = $entry->fresh();
        $this->assertSame('07:30', $entry->startTimeLabel());
        $this->assertSame('16:30', $entry->endTimeLabel());
        $this->assertSame(30, (int) $entry->break_minutes);
        $this->assertSame(8.5, $entry->submittedHoursValue());
        $this->assertSame(0.0, $entry->approvedHoursValue());
        $this->assertSame('Na controle geen uren', $entry->review_note);
    }

    public function test_approved_hours_show_on_project_and_planning_actual_view(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '6',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create();
        $this->actingAs($leader)->post(route('personnel.hours.approve', $entry));

        $this->actingAs($leader)
            ->get(route('projects.show', $assignment->project))
            ->assertOk()
            ->assertSee('Peter – Linoleum – 6u')
            ->assertSee('Gemaakt 6u');

        $this->actingAs($leader)
            ->get(route('planning', ['week' => '2026-09-21', 'hours_view' => 'actual', 'project_id' => $assignment->project_id]))
            ->assertOk()
            ->assertSee('werkelijk 6u')
            ->assertSee('verschil -2u');

        $this->actingAs($leader)
            ->get(route('planning', ['week' => '2026-09-21', 'hours_view' => 'planned', 'project_id' => $assignment->project_id]))
            ->assertOk()
            ->assertDontSee('werkelijk 6u');
    }

    public function test_unplanned_approved_hours_appear_only_in_actual_planning(): void
    {
        [$vakman] = $this->plannedVakman();
        $extra = $this->makeProject('Spoedklus');
        $item = WorkItem::query()->create([
            'project_id' => $extra->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 20,
            'status' => 'in_uitvoering',
        ]);
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-22',
            'project_id' => $extra->id,
            'work_item_id' => $item->id,
            'hours' => '6',
        ]);
        $leader = User::factory()->projectleider()->create();
        $this->actingAs($leader)->post(route('personnel.hours.approve', TimeEntry::query()->first()));

        $this->actingAs($leader)
            ->get(route('planning', ['week' => '2026-09-21', 'hours_view' => 'planned', 'project_id' => $extra->id]))
            ->assertOk()
            ->assertDontSee('data-locked="1"', false);

        $this->actingAs($leader)
            ->get(route('planning', ['week' => '2026-09-21', 'hours_view' => 'actual', 'project_id' => $extra->id]))
            ->assertOk()
            ->assertSee('data-locked="1"', false)
            ->assertSee('Spoedklus');
    }

    public function test_weekstaat_and_approval_pages_show_submitted_hours(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman('Peter');
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '6',
            'note' => 'Korter',
        ]);
        $planner = User::factory()->create();

        $this->actingAs($planner)
            ->get(route('personnel.index', ['week' => '2026-09-21', 'tab' => 'weekstaat']))
            ->assertOk()
            ->assertSee('Weekstaat')
            ->assertSee('6u ingediend')
            ->assertSee('Te beoordelen');

        $this->actingAs($planner)
            ->get(route('personnel.index', ['week' => '2026-09-21', 'tab' => 'goedkeuren']))
            ->assertOk()
            ->assertSee('Peter')
            ->assertSee('Laakse Tuinen')
            ->assertSee('260200090')
            ->assertSee('Linoleum')
            ->assertSee('Goedkeuren')
            ->assertSee('Hele week goedkeuren');
    }

    public function test_weekstaat_day_opens_review_panel(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman('Peter');
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '6.5',
            'note' => 'Eerder klaar',
        ]);
        $planner = User::factory()->create();

        $this->actingAs($planner)
            ->get($this->weekstaatDay($assignment))
            ->assertOk()
            ->assertSee('6,5u ingediend')
            ->assertSee('Te beoordelen')
            ->assertSee('Goedgekeurde uren')
            ->assertSee('Opmerking medewerker')
            ->assertSee('Eerder klaar')
            ->assertSee('Reden/opmerking beoordelaar')
            ->assertSee('Goedkeuren')
            ->assertSee('Aanpassen & goedkeuren')
            ->assertSee('Afwijzen');
    }

    public function test_approving_hours_keeps_the_submitted_amount(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman('Peter');
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '6.5',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create(['name' => 'Eric']);

        $this->actingAs($leader)
            ->post(route('personnel.hours.approve', $entry))
            ->assertRedirect()
            ->assertSessionHas('status', '6,5u goedgekeurd.');

        $entry = $entry->fresh();
        $this->assertSame(6.5, $entry->submittedHoursValue());
        $this->assertSame(6.5, $entry->approvedHoursValue());
        $this->assertFalse($entry->isAdjusted());
        $this->assertSame($leader->id, $entry->reviewed_by);
        $this->assertNotNull($entry->reviewed_at);

        $this->actingAs($leader)
            ->get($this->weekstaatDay($assignment))
            ->assertOk()
            ->assertSee('6,5u goedgekeurd')
            ->assertSee('Goedgekeurd door Eric')
            ->assertSee('Aanpassen & goedkeuren');
    }

    public function test_adjusting_hours_requires_a_reason_and_counts_approved_hours(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman('Peter');
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '6.5',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create(['name' => 'Eric']);
        $weekstaat = $this->weekstaatDay($assignment);

        $this->actingAs($leader)
            ->from($weekstaat)
            ->patch(route('personnel.hours.update', $entry), [
                'approved_hours' => '7',
                'review_note' => '',
            ])
            ->assertRedirect($weekstaat)
            ->assertSessionHasErrors(['review_note' => 'Vul een reden in als je de uren aanpast.']);

        $this->assertSame(6.5, $entry->fresh()->submittedHoursValue());
        $this->assertNull($entry->fresh()->approvedHoursValue());
        $this->assertTrue($entry->fresh()->isSubmitted());

        $this->actingAs($leader)
            ->patch(route('personnel.hours.update', $entry), [
                'approved_hours' => '7',
                'review_note' => '30 minuten reistijd alsnog meegenomen',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '7u aangepast en goedgekeurd.');

        $entry = $entry->fresh();
        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertSame(6.5, $entry->submittedHoursValue());
        $this->assertSame(7.0, $entry->approvedHoursValue());
        $this->assertTrue($entry->isAdjusted());
        $this->assertSame('30 minuten reistijd alsnog meegenomen', $entry->review_note);
        $this->assertSame($leader->id, $entry->reviewed_by);
        $this->assertSame(7.0, (float) WorkProgressEntry::query()->value('worked_hours'));

        $this->actingAs($leader)
            ->get($weekstaat)
            ->assertOk()
            ->assertSee('7u aangepast')
            ->assertSee('Aangepast & goedgekeurd')
            ->assertSee('30 minuten reistijd alsnog meegenomen')
            ->assertSee('Ingediend door medewerker: 6,5u')
            ->assertSee('Goedgekeurd door Eric: 7u');

        $this->actingAs($leader)
            ->get(route('personnel.index', ['week' => '2026-09-21', 'tab' => 'overzicht']))
            ->assertOk()
            ->assertSee('Ingediend: 6,5u | Goedgekeurd: 7u | Verschil: +0,5u')
            ->assertSee('ingediend 6,5u');

        $this->actingAs($leader)
            ->get(route('projects.show', $assignment->project))
            ->assertOk()
            ->assertSee('Gemaakt 7u')
            ->assertDontSee('6,5u');

        $this->actingAs($leader)
            ->get(route('planning', ['week' => '2026-09-21', 'hours_view' => 'actual', 'project_id' => $assignment->project_id]))
            ->assertOk()
            ->assertSee('werkelijk 7u')
            ->assertDontSee('werkelijk 6,5u');

        $this->actingAs($vakman)
            ->get(route('vakman.planning.day', '2026-09-21'))
            ->assertOk()
            ->assertSee('Aangepast & goedgekeurd')
            ->assertSee('6,5u')
            ->assertSee('7u')
            ->assertSee('30 minuten reistijd alsnog meegenomen')
            ->assertSee('Eric')
            ->assertDontSee('Uren aanpassen');
    }

    public function test_approving_zero_hours_keeps_submitted_hours_and_the_review_note(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman('Peter');
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '8',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create(['name' => 'Eric']);
        $weekstaat = $this->weekstaatDay($assignment);

        $this->actingAs($leader)
            ->get($weekstaat)
            ->assertOk()
            ->assertSee('min="0"', false)
            ->assertSee('step="0.25"', false)
            ->assertDontSee('min="0.25"', false);

        $this->actingAs($leader)
            ->patch(route('personnel.hours.update', $entry), [
                'approved_hours' => '0',
                'review_note' => 'Na controle geen uren',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '0u aangepast en goedgekeurd.');

        $entry = $entry->fresh();
        $this->assertNotNull($entry->approved_hours);
        $this->assertSame(8.0, $entry->submittedHoursValue());
        $this->assertSame(0.0, $entry->approvedHoursValue());
        $this->assertTrue($entry->isApproved());
        $this->assertTrue($entry->isAdjusted());
        $this->assertSame('Na controle geen uren', $entry->review_note);
        $this->assertSame(0.0, (float) WorkProgressEntry::query()->value('worked_hours'));

        $this->actingAs($leader)
            ->get($weekstaat)
            ->assertOk()
            ->assertSee('Ingediend door medewerker: 8u')
            ->assertSee('Goedgekeurd door Eric: 0u')
            ->assertSee('Na controle geen uren');
    }

    public function test_approved_hours_stay_editable_and_follow_the_live_plan(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman('Peter');
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '10',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create(['name' => 'Eric']);
        $weekstaat = $this->weekstaatDay($assignment);

        $this->actingAs($leader)->post(route('personnel.hours.approve', $entry));

        $entry = $entry->fresh();
        $this->assertSame(10.0, $entry->submittedHoursValue());
        $this->assertSame(10.0, $entry->approvedHoursValue());
        $this->assertSame(8.0, $entry->planningHoursValue());

        $this->actingAs($leader)
            ->get($weekstaat)
            ->assertOk()
            ->assertSee('Gepland:')
            ->assertSee('8u')
            ->assertSee('Ingediend:')
            ->assertSee('10u')
            ->assertSee('Goedgekeurd:')
            ->assertSee('Verschil t.o.v. planning:')
            ->assertSee('+2u')
            ->assertSee('Aanpassen & goedkeuren');

        $assignment->applySchedule(
            Carbon::parse('2026-09-21'),
            Carbon::parse('2026-09-21'),
            '08:00:00',
            '12:00:00',
        );
        $assignment->save();
        $entry = $entry->fresh();
        $this->assertSame(10.0, $entry->submittedHoursValue());
        $this->assertSame(10.0, $entry->approvedHoursValue());
        $this->assertSame(4.0, $entry->planningHoursValue());

        $this->actingAs($leader)
            ->patch(route('personnel.hours.update', $entry), [
                'approved_hours' => '0',
                'review_note' => 'Na controle geen uren',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '0u aangepast en goedgekeurd.');

        $entry = $entry->fresh();
        $this->assertSame(10.0, $entry->submittedHoursValue());
        $this->assertSame(0.0, $entry->approvedHoursValue());
        $this->assertSame('Na controle geen uren', $entry->review_note);
        $this->assertSame(0.0, (float) WorkProgressEntry::query()->value('worked_hours'));
    }

    public function test_the_same_assignment_does_not_create_a_second_time_entry(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman('Peter');
        $other = WorkItem::query()->create([
            'project_id' => $assignment->project_id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 20,
            'status' => 'in_uitvoering',
        ]);

        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '4',
        ])->assertRedirect();

        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $other->id,
            'hours' => '10',
        ])->assertRedirect();

        $this->assertSame(1, TimeEntry::query()->count());
        $entry = TimeEntry::query()->first();
        $this->assertSame($assignment->id, $entry->worker_assignment_id);
        $this->assertSame(10.0, $entry->submittedHoursValue());
        $this->assertSame(8.0, $entry->planningHoursValue());
        $this->assertNull($entry->approvedHoursValue());
    }

    public function test_hours_overview_filters_people_and_totals_match_the_rows(): void
    {
        $leader = User::factory()->projectleider()->create();
        $team = Worker::query()->create([
            'name' => 'Team 2 Peter',
            'employment_type' => 'eigen',
            'specialty' => 'PVC',
            'active' => true,
            'registers_hours' => true,
        ]);
        $jose = CrewMember::query()->create([
            'worker_id' => $team->id,
            'name' => 'José',
            'sort_order' => 1,
        ]);
        $peter = CrewMember::query()->create([
            'worker_id' => $team->id,
            'name' => 'Peter',
            'sort_order' => 0,
        ]);
        $griftland = $this->makeProject('Griftland College', ['project_number' => '251000077']);
        $other = $this->makeProject('Andere klus');
        $pvc = WorkItem::query()->create([
            'project_id' => $griftland->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'in_uitvoering',
        ]);
        $tapijt = WorkItem::query()->create([
            'project_id' => $other->id,
            'name' => 'Tapijt',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'in_uitvoering',
        ]);
        TimeEntry::factory()->create([
            'worker_id' => $team->id,
            'crew_member_id' => $jose->id,
            'project_id' => $griftland->id,
            'work_item_id' => $pvc->id,
            'date' => '2026-09-21',
            'hours' => 4,
            'approved_hours' => 0,
            'status' => TimeEntryStatus::Approved,
        ]);
        TimeEntry::factory()->create([
            'worker_id' => $team->id,
            'crew_member_id' => $jose->id,
            'project_id' => $griftland->id,
            'work_item_id' => $pvc->id,
            'date' => '2026-09-21',
            'hours' => 10,
            'approved_hours' => 10,
            'status' => TimeEntryStatus::Approved,
        ]);
        TimeEntry::factory()->create([
            'worker_id' => $team->id,
            'crew_member_id' => $peter->id,
            'project_id' => $other->id,
            'work_item_id' => $tapijt->id,
            'date' => '2026-09-21',
            'hours' => 8,
            'approved_hours' => 8,
            'status' => TimeEntryStatus::Approved,
        ]);
        TimeEntry::factory()->create([
            'worker_id' => $team->id,
            'crew_member_id' => $jose->id,
            'project_id' => $griftland->id,
            'work_item_id' => $pvc->id,
            'date' => '2026-09-28',
            'hours' => 5,
            'approved_hours' => 5,
            'status' => TimeEntryStatus::Approved,
        ]);

        $this->actingAs($leader)
            ->get(route('personnel.index', ['tab' => 'overzicht', 'week' => '2026-09-21']))
            ->assertOk()
            ->assertSee('Alle medewerkers')
            ->assertSeeInOrder(['José', 'Peter'])
            ->assertDontSee('Team 2 Peter')
            ->assertDontSee('name="work_item_id"', false)
            ->assertSee('Werkzaamheid')
            ->assertSee('font-semibold">José</td>', false)
            ->assertSee('font-semibold">Peter</td>', false)
            ->assertSee('Ingediend: 22u | Goedgekeurd: 18u | Verschil: -4u');

        $this->actingAs($leader)
            ->get(route('personnel.index', [
                'tab' => 'overzicht',
                'week' => '2026-09-21',
                'person' => 'member-'.$jose->id,
            ]))
            ->assertOk()
            ->assertSee('font-semibold">José</td>', false)
            ->assertSee('PVC')
            ->assertDontSee('font-semibold">Peter</td>', false)
            ->assertSee('Ingediend: 14u | Goedgekeurd: 10u | Verschil: -4u');

        $this->actingAs($leader)
            ->get(route('personnel.index', [
                'tab' => 'overzicht',
                'week' => '2026-09-21',
                'person' => 'member-'.$peter->id,
            ]))
            ->assertOk()
            ->assertSee('font-semibold">Peter</td>', false)
            ->assertDontSee('font-semibold">José</td>', false)
            ->assertSee('Ingediend: 8u | Goedgekeurd: 8u | Verschil: 0u');

        $this->actingAs($leader)
            ->get(route('personnel.index', [
                'tab' => 'overzicht',
                'week' => '2026-09-28',
                'person' => 'member-'.$peter->id,
            ]))
            ->assertOk()
            ->assertSee('Geen uren gevonden')
            ->assertSee('Ingediend: 0u | Goedgekeurd: 0u | Verschil: 0u');
    }

    public function test_rejecting_hours_requires_a_reason(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '8',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create();
        $weekstaat = $this->weekstaatDay($assignment);

        $this->actingAs($leader)
            ->from($weekstaat)
            ->post(route('personnel.hours.reject', $entry), ['review_note' => ''])
            ->assertRedirect($weekstaat)
            ->assertSessionHasErrors(['review_note' => 'Vul een reden in om af te wijzen.']);

        $this->assertTrue($entry->fresh()->isSubmitted());
        $this->assertNull($entry->fresh()->review_note);

        $this->actingAs($leader)
            ->post(route('personnel.hours.reject', $entry), [
                'review_note' => 'Te hoog <script>alert(1)</script>',
            ])
            ->assertRedirect();

        $entry = $entry->fresh();
        $this->assertTrue($entry->isRejected());
        $this->assertNull($entry->approvedHoursValue());
        $this->assertSame(0, WorkProgressEntry::query()->count());

        $this->actingAs($vakman)
            ->get(route('vakman.planning.day', '2026-09-21'))
            ->assertOk()
            ->assertSee('Afgewezen / Ter correctie')
            ->assertSee('Te hoog')
            ->assertSee('Uren aanpassen')
            ->assertDontSee('<script>alert(1)</script>', false);

        $this->actingAs($leader)
            ->get($weekstaat)
            ->assertOk()
            ->assertSee('8u afgewezen')
            ->assertSee('Afgewezen / Ter correctie')
            ->assertSee('Te hoog')
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_vakman_cannot_approve_or_correct_hours(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '6.5',
        ]);
        $entry = TimeEntry::query()->first();

        $this->actingAs($vakman)
            ->post(route('personnel.hours.approve', $entry))
            ->assertForbidden();

        $this->actingAs($vakman)
            ->patch(route('personnel.hours.update', $entry), [
                'approved_hours' => '7',
                'review_note' => 'zelf goedgekeurd',
            ])
            ->assertForbidden();

        $fresh = $entry->fresh();
        $this->assertTrue($fresh->isSubmitted());
        $this->assertSame(6.5, $fresh->submittedHoursValue());
        $this->assertNull($fresh->approvedHoursValue());
    }

    public function test_vakman_can_resubmit_hours_after_rejection(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $this->actingAs($vakman)->post(route('vakman.hours.store'), [
            'date' => '2026-09-21',
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $item->id,
            'hours' => '8',
        ]);
        $entry = TimeEntry::query()->first();
        $leader = User::factory()->projectleider()->create();
        $this->actingAs($leader)->post(route('personnel.hours.reject', $entry), [
            'review_note' => 'Te hoog',
        ]);

        $this->actingAs($vakman)
            ->patch(route('vakman.hours.update', $entry), [
                'hours' => '7',
                'note' => 'Aangepast na afwijzing',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '7u ingediend');

        $fresh = $entry->fresh();
        $this->assertSame($entry->id, $fresh->id);
        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertSame(7.0, $fresh->submittedHoursValue());
        $this->assertNull($fresh->approvedHoursValue());
        $this->assertNull($fresh->review_note);
        $this->assertTrue($fresh->isSubmitted());

        $this->actingAs($leader)
            ->get($this->weekstaatDay($assignment))
            ->assertOk()
            ->assertSee('7u ingediend')
            ->assertSee('Te beoordelen')
            ->assertSee('Goedkeuren');
    }

    public function test_vakman_without_hours_setting_cannot_submit(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $vakman->worker->update(['registers_hours' => false]);
        $vakman->worker->crewPeople()->update(['registers_hours' => false]);

        $this->actingAs($vakman->fresh())
            ->post(route('vakman.hours.store'), [
                'date' => '2026-09-21',
                'worker_assignment_id' => $assignment->id,
                'work_item_id' => $item->id,
                'hours' => '8',
            ])
            ->assertForbidden();
    }

    public function test_vakman_cannot_submit_another_workers_hours(): void
    {
        [$vakman, $assignment, $item] = $this->plannedVakman();
        $other = $this->makeWorker('Andere');
        $intruder = User::factory()->vakman($other->id)->create();

        $this->actingAs($intruder)
            ->post(route('vakman.hours.store'), [
                'date' => '2026-09-21',
                'worker_assignment_id' => $assignment->id,
                'work_item_id' => $item->id,
                'hours' => '8',
            ])
            ->assertSessionHasErrors('worker_assignment_id');

        $this->assertSame(0, TimeEntry::query()->count());
    }

    #[DataProvider('reviewRoles')]
    public function test_review_hours_gate_matches_role(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->canReviewHours());
    }

    /**
     * @return array<string, array{0: UserRole, 1: bool}>
     */
    public static function reviewRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'planner' => [UserRole::Planner, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, true],
            'vakman' => [UserRole::Vakman, false],
            'alleen_lezen' => [UserRole::AlleenLezen, false],
        ];
    }

    private function weekstaatDay(WorkerAssignment $assignment): string
    {
        return route('personnel.index', [
            'week' => '2026-09-21',
            'tab' => 'weekstaat',
            'worker_id' => $assignment->worker_id,
            'crew_member_id' => CrewMember::query()->where('worker_id', $assignment->worker_id)->value('id'),
            'day' => '2026-09-21',
        ]);
    }

    /**
     * @return array{0: User, 1: WorkerAssignment, 2: WorkItem}
     */
    private function plannedVakman(string $name = 'Peter'): array
    {
        $this->travelTo('2026-09-21 08:00:00');
        $worker = $this->makeWorker($name);
        $project = $this->makeProject('Laakse Tuinen Amersfoort', ['project_number' => '260200090']);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-21'),
            Carbon::parse('2026-09-21'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();
        $vakman = User::factory()->vakman($worker->id)->create(['name' => $name]);

        return [$vakman, $assignment, $item];
    }

    private function makeWorker(string $name): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeProject(string $name, array $attributes = []): Project
    {
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Gemeente']);

        return Project::query()->create(array_merge([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
        ], $attributes));
    }
}
