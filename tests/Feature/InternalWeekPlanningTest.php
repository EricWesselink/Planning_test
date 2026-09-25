<?php

namespace Tests\Feature;

use App\Enums\AssignmentKind;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalWeekPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_sync_internal_week(): void
    {
        $this->postJson(route('planning.internal.week'), [
            'worker_id' => 1,
            'week' => '2026-09-28',
            'dates' => ['2026-09-28'],
        ])->assertUnauthorized();

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_uitvoerder_cannot_sync_internal_week(): void
    {
        $worker = $this->worker('Peter Korteschiel');

        $this->actingAs(User::factory()->uitvoerder()->create())
            ->postJson(route('planning.internal.week'), $this->payload($worker, ['2026-09-28']))
            ->assertForbidden();

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_week_sync_uses_the_viewed_week_and_rejects_other_dates(): void
    {
        $worker = $this->worker('Peter Korteschiel');

        $this->actingAs(User::factory()->create())
            ->postJson(route('planning.internal.week'), [
                ...$this->payload($worker, ['2026-10-05']),
                'week' => '2026-09-30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kies alleen dagen van deze week.');

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_checking_monday_creates_a_full_internal_day_in_the_viewed_week(): void
    {
        $worker = $this->worker('Peter Korteschiel', 6);

        $this->actingAs(User::factory()->create())
            ->postJson(route('planning.internal.week'), $this->payload($worker, ['2026-09-28']))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $assignment = WorkerAssignment::query()->firstOrFail();
        $this->assertSame(AssignmentKind::Internal, $assignment->kind);
        $this->assertNull($assignment->project_id);
        $this->assertSame('2026-09-28', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-28', $assignment->end_date->toDateString());
        $this->assertSame(6.0, (float) $assignment->hours_per_day);
        $this->assertSame('08:00:00', $assignment->startTimeValue());
        $this->assertSame('14:00:00', $assignment->endTimeValue());
    }

    public function test_several_days_can_be_checked_together(): void
    {
        $worker = $this->worker('Peter Korteschiel');

        $this->actingAs(User::factory()->create())
            ->postJson(route('planning.internal.week'), $this->payload($worker, [
                '2026-09-28',
                '2026-09-29',
                '2026-10-01',
            ]))
            ->assertOk();

        $dates = WorkerAssignment::query()
            ->where('kind', AssignmentKind::Internal)
            ->orderBy('start_date')
            ->get()
            ->map(fn (WorkerAssignment $assignment): string => $assignment->start_date->toDateString())
            ->all();

        $this->assertSame(['2026-09-28', '2026-09-29', '2026-10-01'], $dates);
    }

    public function test_existing_internal_days_are_shown_for_the_selected_worker(): void
    {
        $user = User::factory()->create();
        $peter = $this->worker('Peter Korteschiel');
        $jan = $this->worker('Jan de Vries');
        $this->internal($peter, '2026-09-28', '2026-09-29');
        $this->internal($jan, '2026-10-01', '2026-10-01');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('Intern – inzet', false)
            ->assertSee('Ma 28-09', false)
            ->assertSee('Za 03-10', false)
            ->assertSee('>Peter Korteschiel<', false)
            ->assertSee('"'.$peter->id.'":["2026-09-28","2026-09-29"]', false)
            ->assertSee('"'.$jan->id.'":["2026-10-01"]', false);
    }

    public function test_unchecking_a_day_removes_only_that_internal_day(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker('Peter Korteschiel');
        $this->internal($worker, '2026-09-28', '2026-09-30');

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), $this->payload($worker, [
                '2026-09-28',
                '2026-10-01',
            ]))
            ->assertOk();

        $dates = WorkerAssignment::query()
            ->where('worker_id', $worker->id)
            ->where('kind', AssignmentKind::Internal)
            ->orderBy('start_date')
            ->get()
            ->map(fn (WorkerAssignment $assignment): string => $assignment->start_date->toDateString().' '.$assignment->end_date->toDateString())
            ->all();

        $this->assertSame(['2026-09-28 2026-09-28', '2026-10-01 2026-10-01'], $dates);
    }

    public function test_other_weeks_and_project_assignments_stay_untouched(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker('Peter Korteschiel');
        $project = $this->project();
        $item = $this->work($project);
        $projectAssignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-30',
            'end_date' => '2026-09-30',
            'people_count' => 1,
            'hours_per_day' => 8,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);
        $nextWeek = $this->internal($worker, '2026-10-05', '2026-10-07');
        $this->internal($worker, '2026-09-28', '2026-09-29');

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), $this->payload($worker, ['2026-09-28']))
            ->assertOk();

        $projectAssignment->refresh();
        $nextWeek->refresh();
        $this->assertSame('2026-09-30', $projectAssignment->start_date->toDateString());
        $this->assertSame($project->id, $projectAssignment->project_id);
        $this->assertSame('2026-10-05', $nextWeek->start_date->toDateString());
        $this->assertSame('2026-10-07', $nextWeek->end_date->toDateString());
        $this->assertFalse(
            WorkerAssignment::query()
                ->where('worker_id', $worker->id)
                ->where('kind', AssignmentKind::Internal)
                ->whereDate('start_date', '2026-09-29')
                ->exists()
        );

        $spanning = $this->internal($worker, '2026-10-02', '2026-10-07');
        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), $this->payload($worker, ['2026-09-28']))
            ->assertOk();

        $spanning->refresh();
        $this->assertSame('2026-10-05', $spanning->start_date->toDateString());
        $this->assertSame('2026-10-07', $spanning->end_date->toDateString());
    }

    public function test_internal_day_updates_availability_without_a_second_row_for_the_same_day(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker('Peter Korteschiel');

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), $this->payload($worker, ['2026-09-28']))
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), $this->payload($worker, ['2026-09-28']))
            ->assertOk();

        $this->assertSame(1, WorkerAssignment::query()->where('kind', AssignmentKind::Internal)->count());

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('planning-available-count">4</span>', false)
            ->assertSee('>Bezet</button>', false)
            ->assertSee('Intern – inzet', false);

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), $this->payload($worker, []))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('planning-available-count">5</span>', false);
    }

    public function test_a_checked_partial_internal_day_keeps_its_hours(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker('Peter Korteschiel');
        $partial = $this->internal($worker, '2026-09-28', '2026-09-28', '08:00:00', '12:00:00');

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), $this->payload($worker, ['2026-09-28']))
            ->assertOk();

        $partial->refresh();
        $this->assertSame(4.0, (float) $partial->hours_per_day);
        $this->assertSame('12:00:00', $partial->endTimeValue());
        $this->assertSame(1, WorkerAssignment::query()->count());
    }

    public function test_internal_days_are_separate_blocks_with_short_names(): void
    {
        $user = User::factory()->create();
        $peter = $this->worker('Peter Korteschiel');
        $this->internal($peter, '2026-09-28', '2026-10-01');

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('data-label-full="Peter K."', false)
            ->assertSee('Intern - inzet', false)
            ->assertSee('Peter Korteschiel', false)
            ->assertDontSee('T1 · 1 man', false)
            ->getContent();

        $this->assertSame(4, substr_count($html, 'data-label-full="Peter K."'));
        $this->assertSame(0, substr_count($html, 'data-internal="1" data-span="4"'));
        $this->assertDoesNotMatchRegularExpression('/data-internal="1"[^>]*data-span="[2-9]"/', $html);
    }

    public function test_unchecking_tuesday_removes_only_that_internal_day_block(): void
    {
        $user = User::factory()->create();
        $peter = $this->worker('Peter Korteschiel');
        $this->internal($peter, '2026-09-28', '2026-09-29');
        $this->internal($peter, '2026-10-01', '2026-10-01');

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), $this->payload($peter, [
                '2026-09-28',
                '2026-10-01',
            ]))
            ->assertOk();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('data-label-full="Peter K."', false)
            ->assertDontSee('data-start-date="2026-09-29"', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-label-full="Peter K."'));
    }

    public function test_two_people_on_the_same_internal_day_share_one_block(): void
    {
        $user = User::factory()->create();
        $peter = $this->worker('Peter Korteschiel');
        $jose = $this->worker('José da Costa');
        $this->internal($peter, '2026-09-29', '2026-09-29');
        $this->internal($jose, '2026-09-29', '2026-09-29');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('data-label-full="Peter K. · José D."', false)
            ->assertSee('Peter Korteschiel', false)
            ->assertSee('José da Costa', false);
    }

    public function test_a_partial_internal_day_keeps_its_hours_on_the_block(): void
    {
        $user = User::factory()->create();
        $peter = $this->worker('Peter Korteschiel');
        $this->internal($peter, '2026-09-28', '2026-09-28', '08:00:00', '12:00:00');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('data-label-full="Peter K."', false)
            ->assertSee('data-end-offset="0.5"', false)
            ->assertSee('4 uur', false);
    }

    public function test_a_project_bar_stays_one_span_when_internal_days_are_split(): void
    {
        $user = User::factory()->create();
        $peter = $this->worker('Peter Korteschiel');
        $project = $this->project();
        $item = $this->work($project);
        WorkerAssignment::query()->create([
            'worker_id' => $peter->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-28',
            'end_date' => '2026-09-30',
            'people_count' => 1,
            'hours_per_day' => 8,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);
        $this->internal($peter, '2026-10-05', '2026-10-07');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->assertSee('data-span="3"', false)
            ->assertSee('data-label-full="Peter K."', false);
    }

    public function test_a_new_internal_day_still_conflicts_with_project_work(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker('Peter Korteschiel');
        $project = $this->project();
        $item = $this->work($project);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-28',
            'end_date' => '2026-09-28',
            'people_count' => 1,
            'hours_per_day' => 8,
            'start_time' => PlanningHours::DAY_START.':00',
            'end_time' => PlanningHours::DAY_END.':00',
        ]);

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), $this->payload($worker, ['2026-09-28']))
            ->assertStatus(409)
            ->assertJsonPath('conflict', true);

        $this->assertSame(0, WorkerAssignment::query()->where('kind', AssignmentKind::Internal)->count());
        $this->assertSame(1, WorkerAssignment::query()->where('project_id', $project->id)->count());
    }

    public function test_ongoing_internal_starts_at_the_chosen_date_and_stops_after_next_year(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker('Peter Korteschiel');
        $this->internal($worker, '2026-09-28', '2026-09-28');

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), [
                'worker_id' => $worker->id,
                'ongoing_from' => '2026-09-28',
            ])
            ->assertOk();

        $this->assertSame(1, WorkerAssignment::query()->whereDate('start_date', '2026-09-28')->whereDate('end_date', '2026-09-28')->count());
        $next = WorkerAssignment::query()->whereDate('start_date', '2026-09-29')->firstOrFail();
        $this->assertSame('2027-12-31', $next->end_date->toDateString());
        $this->assertTrue($next->coversDate(Carbon::parse('2026-10-05')));
        $this->assertTrue($next->coversDate(Carbon::parse('2026-10-10')));
        $this->assertFalse($next->coversDate(Carbon::parse('2028-01-03')));
    }

    public function test_ongoing_internal_does_not_replace_project_work(): void
    {
        $user = User::factory()->create();
        $worker = $this->worker('Peter Korteschiel');
        $project = $this->project();
        $item = $this->work($project);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-10-06',
            'end_date' => '2026-10-06',
            'people_count' => 1,
            'hours_per_day' => 8,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->actingAs($user)
            ->postJson(route('planning.internal.week'), [
                'worker_id' => $worker->id,
                'ongoing_from' => '2026-09-28',
            ])
            ->assertStatus(409)
            ->assertJsonPath('conflict', true);

        $this->assertSame(0, WorkerAssignment::query()->where('kind', AssignmentKind::Internal)->count());
        $this->assertSame(1, WorkerAssignment::query()->where('project_id', $project->id)->count());
    }

    /**
     * @param  list<string>  $dates
     * @return array<string, mixed>
     */
    private function payload(Worker $worker, array $dates): array
    {
        return [
            'worker_id' => $worker->id,
            'week' => '2026-09-28',
            'dates' => $dates,
        ];
    }

    private function worker(string $name, float $hours = 8): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'people_count' => 1,
            'default_hours_per_day' => $hours,
            'active' => true,
        ]);
    }

    private function internal(
        Worker $worker,
        string $start,
        string $end,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00',
    ): WorkerAssignment {
        $assignment = new WorkerAssignment;
        $assignment->worker_id = $worker->id;
        $assignment->kind = AssignmentKind::Internal;
        $assignment->people_count = 1;
        $assignment->origin = 'planned';
        $assignment->applySchedule(
            Carbon::parse($start),
            Carbon::parse($end),
            $startTime,
            $endTime,
            false,
            false,
        );
        $assignment->save();

        return $assignment;
    }

    private function project(): Project
    {
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);

        return Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
        ]);
    }

    private function work(Project $project): WorkItem
    {
        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
    }
}
