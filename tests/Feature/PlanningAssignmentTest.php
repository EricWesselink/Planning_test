<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_an_assignment_to_another_work_item_on_the_same_project(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-09',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $assignment->refresh();
        $this->assertSame($coating->id, $assignment->work_item_id);
        $this->assertSame('2026-09-08', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-09', $assignment->end_date->toDateString());
    }

    public function test_rejects_moving_an_assignment_to_a_work_item_on_another_project(): void
    {
        $user = User::factory()->create();
        [$assignment, $linoleum] = $this->makeAssignmentOnTwoWorkItems();
        $other = $this->makeWorkItem('School Zwolle', '260200091', 'Linoleum');

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'work_item_id' => $other->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-09',
            ])
            ->assertNotFound();

        $assignment->refresh();
        $this->assertSame($linoleum->id, $assignment->work_item_id);
        $this->assertSame('2026-09-08', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-09', $assignment->end_date->toDateString());
    }

    public function test_unauthenticated_assignment_update_returns_401(): void
    {
        [$assignment, $linoleum, $coating] = $this->makeAssignmentOnTwoWorkItems();

        $this->patchJson(route('planning.assignments.update', $assignment), [
            'worker_id' => $assignment->worker_id,
            'work_item_id' => $coating->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-09',
        ])->assertUnauthorized();

        $this->assertSame($linoleum->id, $assignment->fresh()->work_item_id);
    }

    public function test_forbids_uitvoerder_from_moving_an_assignment(): void
    {
        $user = User::factory()->uitvoerder()->create();
        [$assignment, $linoleum, $coating] = $this->makeAssignmentOnTwoWorkItems();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-09',
            ])
            ->assertForbidden();

        $this->assertSame($linoleum->id, $assignment->fresh()->work_item_id);
    }

    public function test_allows_one_person_per_onderdeel_when_the_team_has_two_people(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems(people: 2);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $assignment->project_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(2, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $assignment->worker_id,
            'work_item_id' => $coating->id,
            'people_count' => 1,
        ]);
    }

    public function test_returns_409_when_planned_people_exceed_the_team(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems(people: 2);
        $assignment->forceFill(['people_count' => 2])->save();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $assignment->project_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
            ])
            ->assertConflict()
            ->assertJson([
                'ok' => false,
                'conflict' => true,
            ])
            ->assertJsonFragment(['message' => 'ZZP Jansen Vloeren heeft die dag 3 van 2 personen ingepland op Laakse Tuinen Amersfoort. Toch doorgaan?']);

        $this->assertSame(1, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
    }

    public function test_returns_409_when_a_single_person_is_planned_on_two_onderdelen(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems(people: 1);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $assignment->project_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
            ])
            ->assertConflict()
            ->assertJsonPath('conflict', true);

        $this->assertSame(1, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
    }

    public function test_planning_board_does_not_warn_when_a_two_person_team_is_split(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems(people: 2);
        WorkerAssignment::query()->create([
            'worker_id' => $assignment->worker_id,
            'project_id' => $assignment->project_id,
            'work_item_id' => $coating->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertDontSee('meer personen ingepland dan het team')
            ->assertDontSee('person-bar double', false);
    }

    public function test_planning_board_warns_when_planned_people_exceed_the_team(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems(people: 2);
        $assignment->forceFill(['people_count' => 2])->save();
        WorkerAssignment::query()->create([
            'worker_id' => $assignment->worker_id,
            'project_id' => $assignment->project_id,
            'work_item_id' => $coating->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Dubbele planning')
            ->assertSee('ZZP Jansen Vloeren heeft meer personen ingepland dan het team.')
            ->assertSee('person-bar double', false)
            ->assertSee('planning-warnings', false)
            ->assertSee('data-focus-worker="'.$assignment->worker_id.'"', false);
    }

    /**
     * @return array{0: WorkerAssignment, 1: WorkItem, 2: WorkItem}
     */
    private function makeAssignmentOnTwoWorkItems(int $people = 1): array
    {
        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'people_count' => $people,
            'specialty' => 'Linoleum, Coating',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        $linoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 3168,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        $coating = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Coating',
            'unit' => 'm2',
            'ordered_quantity' => 400,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $linoleum->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-09',
            'hours_per_day' => 8,
        ]);

        return [$assignment, $linoleum, $coating];
    }

    private function makeWorkItem(string $projectName, string $projectNumber, string $workName): WorkItem
    {
        $customer = Customer::query()->create(['name' => 'Schoolbestuur']);
        $project = Project::query()->create([
            'project_number' => $projectNumber,
            'customer_id' => $customer->id,
            'name' => $projectName,
            'status' => 'gepland',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);

        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $workName,
            'unit' => 'm2',
            'ordered_quantity' => 200,
            'status' => 'gepland',
        ]);
    }
}
