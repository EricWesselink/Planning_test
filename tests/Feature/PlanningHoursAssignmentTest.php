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
            ->assertSee('· 2u', false)
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
            ->assertSee('· 8u', false)
            ->assertSee('08:00–16:00', false)
            ->assertSee('data-planned-hours="8"', false)
            ->assertSee('data-start-offset="0"', false)
            ->assertSee('data-end-offset="1"', false);
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
            ->assertSee('· 4u', false)
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
}
