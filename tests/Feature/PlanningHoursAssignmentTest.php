<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningHoursAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_a_two_hour_slot_from_ten_to_twelve(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
                'hours' => 2,
                'start_time' => '10:00',
                'end_time' => '12:00',
            ])
            ->assertOk();

        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'planned_hours' => 2,
            'hours_per_day' => 2,
        ]);
    }

    public function test_moves_a_two_hour_bar_from_ten_twelve_to_twelve_fourteen(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();
        $assignment->applySchedule($assignment->start_date, $assignment->end_date, '10:00:00', '12:00:00');
        $assignment->save();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '12:00',
                'end_time' => '14:00',
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame('12:00:00', $assignment->startTimeValue());
        $this->assertSame('14:00:00', $assignment->endTimeValue());
        $this->assertSame(2.0, $assignment->plannedHoursValue());
    }

    public function test_resizing_a_multi_day_bar_keeps_a_later_start_when_the_end_clock_is_earlier(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();
        $assignment->applySchedule(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-09'),
            '08:00:00',
            '10:00:00',
        );
        $assignment->save();
        $this->assertSame(18.0, $assignment->plannedHoursValue());

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-09',
                'start_time' => '14:00',
                'end_time' => '10:00',
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame('14:00:00', $assignment->startTimeValue());
        $this->assertSame('10:00:00', $assignment->endTimeValue());
        $this->assertSame(2.0, $assignment->hoursOnDate(Carbon::parse('2026-09-07')));
        $this->assertSame(8.0, $assignment->hoursOnDate(Carbon::parse('2026-09-08')));
        $this->assertSame(2.0, $assignment->hoursOnDate(Carbon::parse('2026-09-09')));
        $this->assertSame(12.0, $assignment->plannedHoursValue());

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'weeks' => 1, 'hours_view' => 'planned', 'project_id' => $assignment->project_id]))
            ->assertOk()
            ->assertSee('12u')
            ->assertDontSee('18u');
    }

    public function test_resizes_a_two_hour_bar_to_four_hours(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();
        $assignment->applySchedule($assignment->start_date, $assignment->end_date, '10:00:00', '12:00:00');
        $assignment->save();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '10:00',
                'end_time' => '14:00',
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame('10:00:00', $assignment->startTimeValue());
        $this->assertSame('14:00:00', $assignment->endTimeValue());
        $this->assertSame(4.0, $assignment->plannedHoursValue());
    }

    public function test_allows_the_same_person_on_two_projects_without_time_overlap(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();
        $assignment->applySchedule($assignment->start_date, $assignment->end_date, '08:00:00', '10:00:00');
        $assignment->save();

        $otherCustomer = Customer::query()->create(['name' => 'Andere Klant']);
        $otherProject = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $otherCustomer->id,
            'name' => 'Tweede werk',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);
        $otherWork = WorkItem::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 200,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $otherProject->id,
                'work_item_id' => $otherWork->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
                'hours' => 2,
                'start_time' => '10:00',
                'end_time' => '12:00',
            ])
            ->assertOk();

        $this->assertSame(2, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
    }

    public function test_blocks_overlapping_times_for_the_same_person_across_projects(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();
        $assignment->applySchedule($assignment->start_date, $assignment->end_date, '08:00:00', '12:00:00');
        $assignment->save();

        $otherCustomer = Customer::query()->create(['name' => 'Andere Klant']);
        $otherProject = Project::query()->create([
            'project_number' => '260200092',
            'customer_id' => $otherCustomer->id,
            'name' => 'Derde werk',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);
        $otherWork = WorkItem::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Tapijt',
            'unit' => 'm2',
            'ordered_quantity' => 120,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $otherProject->id,
                'work_item_id' => $otherWork->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
                'hours' => 4,
                'start_time' => '10:00',
                'end_time' => '14:00',
            ])
            ->assertConflict()
            ->assertJsonPath('conflict', true);
    }

    public function test_two_hour_bar_uses_a_quarter_of_the_day_cell(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();
        $assignment->applySchedule($assignment->start_date, $assignment->end_date, '10:00:00', '12:00:00');
        $assignment->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('2 geplande uren', false)
            ->assertSee('data-start-offset="0.25"', false)
            ->assertSee('data-end-offset="0.5"', false)
            ->assertSee('plan-day-times', false)
            ->assertSee('>08:00</span>', false)
            ->assertSee('>12:00</span>', false);
    }

    public function test_stores_a_full_day_as_eight_hours_from_eight_to_four(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
                'hours' => 8,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'work_item_id' => $linoleum->id,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'planned_hours' => 8,
        ]);
    }

    public function test_stores_a_half_day_as_four_morning_hours(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
                'hours' => 4,
                'slot' => 'morning',
            ])
            ->assertOk();

        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'hours_per_day' => 4,
            'planned_hours' => 4,
        ]);
    }

    public function test_resizes_an_eight_hour_bar_to_four_hours(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '12:00',
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame('08:00:00', $assignment->startTimeValue());
        $this->assertSame('12:00:00', $assignment->endTimeValue());
        $this->assertSame(4.0, $assignment->plannedHoursValue());
    }

    public function test_resizes_a_four_hour_bar_to_six_hours(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();
        $assignment->applySchedule($assignment->start_date, $assignment->end_date, '08:00:00', '12:00:00');
        $assignment->save();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '14:00',
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame(6.0, $assignment->plannedHoursValue());
        $this->assertSame('14:00:00', $assignment->endTimeValue());
    }

    public function test_allows_the_same_person_in_the_morning_and_afternoon_on_another_project(): void
    {
        $user = User::factory()->create();
        [$assignment, $linoleum, $coating] = $this->makeFullDayAssignment();
        $assignment->applySchedule($assignment->start_date, $assignment->end_date, '08:00:00', '12:00:00');
        $assignment->save();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $assignment->project_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
                'hours' => 4,
                'slot' => 'afternoon',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(2, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
        $this->assertDatabaseHas('worker_assignments', [
            'work_item_id' => $coating->id,
            'start_time' => '12:00:00',
            'end_time' => '16:00:00',
            'planned_hours' => 4,
        ]);
    }

    public function test_returns_409_when_hours_overlap_on_the_same_day(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeFullDayAssignment();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $assignment->project_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
                'hours' => 4,
                'slot' => 'afternoon',
            ])
            ->assertConflict()
            ->assertJsonPath('conflict', true);

        $this->assertSame(1, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
    }

    public function test_stores_different_hours_per_person_of_the_same_zzp_team(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject(3, ['Piet', 'Kees', 'Jan']);
        $people = $worker->crewPeople()->orderBy('sort_order')->get();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'crew_member_ids' => [$people[0]->id, $people[1]->id],
                'crew_hours' => [
                    $people[0]->id => 8,
                    $people[1]->id => 4,
                ],
                'hours' => 8,
            ])
            ->assertOk();

        $this->assertSame(2, WorkerAssignment::query()->where('worker_id', $worker->id)->count());
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'planned_hours' => 8,
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'people_count' => 1,
        ]);
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'planned_hours' => 4,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'people_count' => 1,
        ]);
        $this->assertDatabaseHas('crew_member_worker_assignment', [
            'crew_member_id' => $people[0]->id,
            'planned_hours' => 8,
        ]);
        $this->assertDatabaseHas('crew_member_worker_assignment', [
            'crew_member_id' => $people[1]->id,
            'planned_hours' => 4,
        ]);
    }

    public function test_existing_full_day_assignments_still_render_as_eight_hours(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('8 geplande uren', false)
            ->assertSee('08:00–16:00', false)
            ->assertSee('data-planned-hours="8"', false)
            ->assertSee('data-start-offset="0"', false)
            ->assertSee('data-end-offset="1"', false)
            ->assertSee('Zaterdag', false)
            ->assertSee('Zondag', false)
            ->assertDontSee('id="plan-include-saturday" value="1" checked', false);
    }

    public function test_a_range_through_the_weekend_skips_saturday_and_sunday_by_default(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-25',
                'people_count' => 1,
                'hours' => 8,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->where('worker_id', $worker->id)->first();
        $this->assertNotNull($assignment);
        $this->assertFalse($assignment->includesSaturday());
        $this->assertFalse($assignment->includesSunday());
        $this->assertSame(80.0, $assignment->plannedHoursValue());
        $this->assertSame(0.0, $assignment->hoursOnDate(Carbon::parse('2026-09-19')));
        $this->assertFalse($assignment->coversDate(Carbon::parse('2026-09-20')));
        $this->assertTrue($assignment->coversDate(Carbon::parse('2026-09-21')));
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'include_saturday' => 0,
            'include_sunday' => 0,
            'planned_hours' => 80,
        ]);
    }

    public function test_board_hides_the_bar_on_unchecked_saturdays_across_weeks(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();
        $project->forceFill([
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-25',
        ])->save();
        $linoleum->forceFill([
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-25',
        ])->save();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-25',
                'people_count' => 1,
                'hours' => 8,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->where('worker_id', $worker->id)->first();
        $this->assertNotNull($assignment);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-14', 'weeks' => 2]))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            [
                ['start' => 0, 'span' => 5],
                ['start' => 6, 'span' => 5],
            ],
            $this->assignmentBarBoxes($html, $assignment->id),
        );
    }

    public function test_board_shows_the_bar_on_saturday_when_saturday_is_checked(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();
        $project->forceFill([
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-25',
        ])->save();
        $linoleum->forceFill([
            'planned_start_date' => '2026-09-14',
            'planned_end_date' => '2026-09-25',
        ])->save();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-25',
                'people_count' => 1,
                'hours' => 8,
                'include_saturday' => true,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->where('worker_id', $worker->id)->first();
        $this->assertNotNull($assignment);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-14', 'weeks' => 2]))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            [
                ['start' => 0, 'span' => 11],
            ],
            $this->assignmentBarBoxes($html, $assignment->id),
        );
    }

    public function test_unchecking_saturday_skips_saturday_hours(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-25',
                'people_count' => 1,
                'hours' => 8,
                'include_saturday' => false,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->where('worker_id', $worker->id)->first();
        $this->assertNotNull($assignment);
        $this->assertFalse($assignment->includesSaturday());
        $this->assertSame(80.0, $assignment->plannedHoursValue());
        $this->assertSame(0.0, $assignment->hoursOnDate(Carbon::parse('2026-09-19')));
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'include_saturday' => 0,
            'planned_hours' => 80,
        ]);
    }

    public function test_checking_saturday_plans_saturday_but_not_sunday(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-25',
                'people_count' => 1,
                'hours' => 8,
                'include_saturday' => true,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->where('worker_id', $worker->id)->first();
        $this->assertNotNull($assignment);
        $this->assertTrue($assignment->includesSaturday());
        $this->assertFalse($assignment->includesSunday());
        $this->assertSame(88.0, $assignment->plannedHoursValue());
        $this->assertSame(8.0, $assignment->hoursOnDate(Carbon::parse('2026-09-19')));
        $this->assertFalse($assignment->coversDate(Carbon::parse('2026-09-20')));
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'include_saturday' => 1,
            'include_sunday' => 0,
            'planned_hours' => 88,
        ]);
    }

    public function test_checking_sunday_plans_sunday_but_not_saturday(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-25',
                'people_count' => 1,
                'hours' => 8,
                'include_sunday' => true,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->where('worker_id', $worker->id)->first();
        $this->assertNotNull($assignment);
        $this->assertFalse($assignment->includesSaturday());
        $this->assertTrue($assignment->includesSunday());
        $this->assertSame(88.0, $assignment->plannedHoursValue());
        $this->assertSame(0.0, $assignment->hoursOnDate(Carbon::parse('2026-09-19')));
        $this->assertTrue($assignment->coversDate(Carbon::parse('2026-09-20')));
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'include_saturday' => 0,
            'include_sunday' => 1,
            'planned_hours' => 88,
        ]);
    }

    public function test_checking_saturday_and_sunday_plans_both_weekend_days(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-14',
                'end_date' => '2026-09-25',
                'people_count' => 1,
                'hours' => 8,
                'include_saturday' => true,
                'include_sunday' => true,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->where('worker_id', $worker->id)->first();
        $this->assertNotNull($assignment);
        $this->assertTrue($assignment->includesSaturday());
        $this->assertTrue($assignment->includesSunday());
        $this->assertSame(96.0, $assignment->plannedHoursValue());
        $this->assertSame(8.0, $assignment->hoursOnDate(Carbon::parse('2026-09-19')));
        $this->assertTrue($assignment->coversDate(Carbon::parse('2026-09-20')));
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $worker->id,
            'include_saturday' => 1,
            'include_sunday' => 1,
            'planned_hours' => 96,
        ]);
    }

    public function test_rejects_a_weekend_only_range_unless_weekends_are_checked(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-19',
                'end_date' => '2026-09-20',
                'people_count' => 1,
                'hours' => 8,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Deze periode heeft geen werkdagen. Vink zaterdag of zondag aan of kies andere datums.',
            );

        $this->assertDatabaseMissing('worker_assignments', [
            'worker_id' => $worker->id,
        ]);
    }

    public function test_half_day_bar_uses_half_of_the_day_cell(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeFullDayAssignment();
        $assignment->applySchedule($assignment->start_date, $assignment->end_date, '08:00:00', '12:00:00');
        $assignment->save();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('4 geplande uren', false)
            ->assertSee('data-start-offset="0"', false)
            ->assertSee('data-end-offset="0.5"', false);
    }

    /**
     * @param  list<string>  $names
     * @return array{0: Worker, 1: Project, 2: WorkItem}
     */
    private function makeProject(int $people = 1, array $names = []): array
    {
        $members = [];
        foreach ($names as $name) {
            $members[] = ['name' => $name, 'phone' => ''];
        }

        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'people_count' => $people,
            'crew_members' => $members !== [] ? $members : null,
            'specialty' => 'Linoleum, PVC, Tapijt, Coating',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);
        $linoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 3168,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);

        return [$worker, $project, $linoleum];
    }

    /**
     * @return array{0: WorkerAssignment, 1: WorkItem, 2: WorkItem}
     */
    private function makeFullDayAssignment(): array
    {
        [$worker, $project, $linoleum] = $this->makeProject();
        $coating = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Coating',
            'unit' => 'm2',
            'ordered_quantity' => 400,
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $linoleum->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();

        return [$assignment, $linoleum, $coating];
    }

    /**
     * @return list<array{start: int, span: int}>
     */
    private function assignmentBarBoxes(string $html, int $assignmentId): array
    {
        preg_match_all(
            '/data-shift-id="'.$assignmentId.'"[^>]*data-start="(\d+)"[^>]*data-span="(\d+)"/',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            fn (array $match): array => [
                'start' => (int) $match[1],
                'span' => (int) $match[2],
            ],
            $matches,
        );
    }
}
