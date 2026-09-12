<?php

namespace Tests\Feature;

use App\Enums\AreaStatus;
use App\Enums\WorkPhase;
use App\Models\AreaTask;
use App\Models\CrewMember;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use App\Services\RoomWorkSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RoomSelectionProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_room_and_one_work_are_marked_gereed(): void
    {
        [$user, $project, $worker] = $this->makeBoardProject();
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), [
                'area_ids' => [$area->id],
                'work_keys' => ['ondergrond'],
                'worker_id' => $worker->id,
                'date' => '2026-09-12',
                'note' => 'Kamers klaar',
            ])
            ->assertOk()
            ->assertJsonPath('summary.area_count', 1)
            ->assertJsonPath('summary.worker', 'Albert');

        $primen = $primen->fresh();
        $this->assertSame(AreaStatus::Gereed, $primen->status);
        $this->assertSame($worker->id, $primen->completed_by);
        $this->assertEqualsWithDelta(0, $primen->remainingQuantity(), 0.01);
        $this->assertDatabaseCount('work_progress_entries', 1);
        $this->assertDatabaseHas('work_progress_entries', [
            'project_area_id' => $area->id,
            'worker_id' => $worker->id,
            'completed_quantity' => $primen->ordered_quantity,
        ]);
        $this->assertSame(AreaStatus::NietGestart, $area->tasks()->where('id', '!=', $primen->id)->first()?->status);
    }

    public function test_four_rooms_mark_only_egaliseren_with_each_rooms_own_quantity(): void
    {
        [$user, $project, $worker] = $this->makeBoardProject();
        $areas = $this->selectedAreas($project);
        $primen = $areas->map(
            fn (ProjectArea $area) => $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren)
        );
        $expected = round((float) $primen->sum(fn (AreaTask $task) => (float) $task->ordered_quantity), 2);

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), [
                'area_ids' => $areas->pluck('id')->all(),
                'work_keys' => ['ondergrond'],
                'worker_id' => $worker->id,
                'date' => '2026-09-12',
                'quantity' => 143.01,
            ])
            ->assertOk()
            ->assertJsonPath('summary.area_count', 4)
            ->assertJsonPath('summary.quantity', $expected);

        foreach ($primen as $task) {
            $fresh = $task->fresh();
            $this->assertSame(AreaStatus::Gereed, $fresh->status);
            $this->assertSame($worker->id, $fresh->completed_by);
            $entry = WorkProgressEntry::query()->where('project_area_id', $task->project_area_id)->first();
            $this->assertNotNull($entry);
            $this->assertEqualsWithDelta((float) $task->ordered_quantity, (float) $entry->completed_quantity, 0.01);
            $this->assertNotSame(143.01, (float) $entry->completed_quantity);
        }

        $this->assertDatabaseCount('work_progress_entries', 4);
        $this->assertEqualsWithDelta(
            $expected,
            (float) WorkProgressEntry::query()->sum('completed_quantity'),
            0.01,
        );
        $this->assertTrue(
            $areas->every(fn (ProjectArea $area) => $area->tasks()
                ->whereHas('workItem', fn ($query) => $query->where('name', 'Marmoleum Real'))
                ->where('status', AreaStatus::NietGestart->value)
                ->exists())
        );
    }

    public function test_four_rooms_mark_several_materials_that_exist_there(): void
    {
        [$user, $project, $worker] = $this->makeBoardProject();
        $areas = $this->selectedAreas($project);
        $vloerKey = $areas->first()->groupedTasks()->first(
            fn (array $group) => str_starts_with($group['key'], 'vloer')
        )['key'];

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), [
                'area_ids' => $areas->pluck('id')->all(),
                'work_keys' => ['ondergrond', $vloerKey],
                'worker_id' => $worker->id,
                'date' => '2026-09-12',
            ])
            ->assertOk()
            ->assertJsonPath('summary.area_count', 4);

        $this->assertDatabaseCount('work_progress_entries', 8);
        foreach ($areas as $area) {
            $area->unsetRelation('tasks');
            $this->assertTrue($area->tasks->every(fn (AreaTask $task) => $task->isDone()));
        }
    }

    public function test_already_gereed_work_is_not_booked_again(): void
    {
        [$user, $project, $worker] = $this->makeBoardProject();
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);
        $primen->markDone($worker, '2026-09-11', $user);

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), [
                'area_ids' => [$area->id],
                'work_keys' => ['ondergrond'],
                'worker_id' => $worker->id,
                'date' => '2026-09-12',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Deze werkzaamheden zijn in de geselecteerde ruimtes al gereed.');

        $this->assertDatabaseCount('work_progress_entries', 1);
        $this->assertSame(AreaStatus::Gereed, $primen->fresh()->status);
    }

    public function test_partially_gereed_work_books_only_the_remaining_quantity(): void
    {
        [$user, $project, $worker] = $this->makeBoardProject();
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);
        $primen->markDone($worker, '2026-09-11', $user, 18);

        $this->assertEqualsWithDelta(32.97, $primen->fresh()->remainingQuantity(), 0.01);

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), [
                'area_ids' => [$area->id],
                'work_keys' => ['ondergrond'],
                'worker_id' => $worker->id,
                'date' => '2026-09-12',
            ])
            ->assertOk();

        $this->assertSame(AreaStatus::Gereed, $primen->fresh()->status);
        $this->assertEqualsWithDelta(0, $primen->fresh()->remainingQuantity(), 0.01);
        $this->assertDatabaseCount('work_progress_entries', 2);
        $this->assertEqualsWithDelta(
            18.0,
            (float) WorkProgressEntry::query()->orderBy('id')->value('completed_quantity'),
            0.01,
        );
        $this->assertEqualsWithDelta(
            32.97,
            (float) WorkProgressEntry::query()->orderByDesc('id')->value('completed_quantity'),
            0.01,
        );
    }

    public function test_different_materials_per_room_are_only_booked_where_they_exist(): void
    {
        [$user, $project, $worker] = $this->makeBoardProject();
        $first = $project->areas()->where('area_number', '0.07')->first();
        $second = $project->areas()->where('area_number', '0.09')->first();
        $pvc = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'IVC Ultimo Chapman Oak, 24245, PVC - LVT',
            'unit' => 'm2',
            'ordered_quantity' => 50.97,
            'status' => 'gepland',
            'sort_order' => 21,
        ]);
        $pvcTask = AreaTask::query()->create([
            'project_area_id' => $first->id,
            'work_item_id' => $pvc->id,
            'ordered_quantity' => 50.97,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);
        $pvcKey = 'vloer|'.$pvc->id;

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), [
                'area_ids' => [$first->id, $second->id],
                'work_keys' => ['ondergrond', $pvcKey],
                'worker_id' => $worker->id,
                'date' => '2026-09-12',
            ])
            ->assertOk()
            ->assertJsonPath('summary.area_count', 2);

        $this->assertSame(AreaStatus::Gereed, $pvcTask->fresh()->status);
        $this->assertSame($worker->id, $pvcTask->fresh()->completed_by);
        $marmoleum = $second->fresh(['tasks.workItem'])->tasks
            ->first(fn (AreaTask $task) => $task->phase()->group() === 'vloer' && (int) $task->work_item_id !== $pvc->id);
        $this->assertNotNull($marmoleum);
        $this->assertSame(AreaStatus::NietGestart, $marmoleum->status);
        $this->assertDatabaseHas('work_progress_entries', [
            'project_area_id' => $first->id,
            'work_item_id' => $pvc->id,
            'worker_id' => $worker->id,
        ]);
        $this->assertDatabaseMissing('work_progress_entries', [
            'project_area_id' => $second->id,
            'work_item_id' => $pvc->id,
        ]);
    }

    public function test_selection_progress_rejects_areas_from_another_project(): void
    {
        [$user, $project, $worker] = $this->makeBoardProject();
        $own = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $other = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $project->customer_id,
            'name' => 'Ander project',
            'status' => 'gepland',
        ]);
        $foreign = ProjectArea::query()->create([
            'project_id' => $other->id,
            'area_number' => '9.99',
            'name' => 'vreemde ruimte',
            'square_meters' => 10,
            'status' => 'niet_gestart',
        ]);

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), [
                'area_ids' => [$own->id, $foreign->id],
                'work_keys' => ['ondergrond'],
                'worker_id' => $worker->id,
                'date' => '2026-09-12',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Een of meer ruimtes horen niet bij dit project.');

        $this->assertDatabaseCount('work_progress_entries', 0);
        $this->assertSame(
            AreaStatus::NietGestart,
            $own->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren)?->fresh()->status
        );
    }

    public function test_duplicate_selection_request_does_not_double_book(): void
    {
        [$user, $project, $worker] = $this->makeBoardProject();
        $area = $project->areas()->where('area_number', '0.07')->first();
        $payload = [
            'area_ids' => [$area->id],
            'work_keys' => ['ondergrond'],
            'worker_id' => $worker->id,
            'date' => '2026-09-12',
        ];

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), $payload)
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Deze werkzaamheden zijn in de geselecteerde ruimtes al gereed.');

        $this->assertDatabaseCount('work_progress_entries', 1);
    }

    public function test_selection_progress_stores_the_planned_team(): void
    {
        [$user, $project] = $this->makeBoardProject();
        $team = Worker::query()->create([
            'name' => 'Team 3',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'active' => true,
        ]);
        CrewMember::query()->create(['worker_id' => $team->id, 'name' => 'Arek', 'sort_order' => 0]);
        CrewMember::query()->create(['worker_id' => $team->id, 'name' => 'Sietse', 'sort_order' => 1]);
        $this->assignToProject($project, $team);
        $area = $project->areas()->where('area_number', '0.07')->first()->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren);

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Werkzaamheden bijwerken')
            ->assertSee('Team 3 – Arek / Sietse')
            ->getContent();

        $this->assertSame(1, preg_match('/id="room-progress-worker"[^>]*>.*?<\/select>/s', $html, $select));
        $this->assertStringContainsString('>Team 3 – Arek / Sietse</option>', $select[0]);

        $this->actingAs($user)
            ->postJson(route('projects.areas.selection.process', $project), [
                'area_ids' => [$area->id],
                'work_keys' => ['ondergrond'],
                'worker_id' => $team->id,
                'date' => '2026-09-12',
            ])
            ->assertOk()
            ->assertJsonPath('summary.worker', 'Team 3');

        $this->assertSame($team->id, $primen->fresh()->completed_by);
        $this->assertDatabaseHas('work_progress_entries', [
            'project_area_id' => $area->id,
            'worker_id' => $team->id,
        ]);
    }

    public function test_unauthenticated_selection_progress_returns_401(): void
    {
        [, $project, $worker] = $this->makeBoardProject();
        $area = $project->areas()->first();

        $this->postJson(route('projects.areas.selection.process', $project), [
            'area_ids' => [$area->id],
            'work_keys' => ['ondergrond'],
            'worker_id' => $worker->id,
            'date' => '2026-09-12',
        ])->assertUnauthorized();
    }

    public function test_planner_cannot_save_selection_progress(): void
    {
        [, $project, $worker] = $this->makeBoardProject();
        $planner = User::factory()->create();
        $area = $project->areas()->first();

        $this->actingAs($planner)
            ->postJson(route('projects.areas.selection.process', $project), [
                'area_ids' => [$area->id],
                'work_keys' => ['ondergrond'],
                'worker_id' => $worker->id,
                'date' => '2026-09-12',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('work_progress_entries', 0);
    }

    /**
     * @return array{0: User, 1: Project, 2: Worker, 3: WorkItem}
     */
    private function makeBoardProject(): array
    {
        $user = User::factory()->projectleider()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $work = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Marmoleum Real',
            'unit' => 'm2',
            'ordered_quantity' => 200,
            'status' => 'gepland',
            'sort_order' => 10,
        ]);
        foreach ([
            '0.07' => ['groepsruimte', 50.97],
            '0.09' => ['groepsruimte', 59],
            '0.12' => ['schoolleiding', 19],
            '0.19a' => ['administratie', 12],
        ] as $number => $row) {
            $area = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $number,
                'name' => $row[0],
                'square_meters' => $row[1],
                'status' => 'niet_gestart',
            ]);
            AreaTask::query()->create([
                'project_area_id' => $area->id,
                'work_item_id' => $work->id,
                'ordered_quantity' => $row[1],
                'unit' => 'm2',
                'status' => 'niet_gestart',
            ]);
        }
        $this->assignToProject($project, $worker, $work);
        app(RoomWorkSetup::class)->ensureProject($project);

        return [$user, $project->fresh(['areas']), $worker, $work];
    }

    /**
     * @return Collection<int, ProjectArea>
     */
    private function selectedAreas(Project $project)
    {
        return $project->areas()
            ->whereIn('area_number', ['0.07', '0.09', '0.12', '0.19a'])
            ->orderBy('id')
            ->get()
            ->load(['tasks.workItem']);
    }

    private function assignToProject(Project $project, Worker $worker, ?WorkItem $item = null): void
    {
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item?->id ?? $project->workItems()->value('id'),
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'hours_per_day' => 8,
        ]);
    }
}
