<?php

namespace Tests\Feature;

use App\Enums\AreaStatus;
use App\Enums\UserRole;
use App\Enums\WorkPhase;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\RoomWorkSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgressApprovalTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('enterProgressRoles')]
    public function test_enter_progress_gate_matches_role(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('enter-progress'));
    }

    #[DataProvider('approveProgressRoles')]
    public function test_approve_progress_gate_matches_role(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('approve-progress'));
    }

    public function test_unauthenticated_progress_request_returns_401(): void
    {
        [$project, $area] = $this->makeBoard();
        $task = $this->firstOpenTask($area);

        $this->postJson(route('projects.areas.process', [$project, $area]), [
            'task_ids' => [$task->id],
            'worker_id' => 1,
            'date' => '2026-09-08',
        ])->assertUnauthorized();
    }

    public function test_vakman_marks_work_provisional_until_projectleider_approves(): void
    {
        [$project, $area, $worker] = $this->makeBoard();
        $vakman = User::factory()->vakman($worker->id)->create();
        $this->assignWorker($worker, $project);
        $ids = $this->openTaskIds($area);

        $this->actingAs($vakman)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => $ids,
                'worker_id' => $worker->id,
                'date' => '2026-09-08',
            ])
            ->assertOk()
            ->assertJsonPath('area.tone', 'pending')
            ->assertJsonPath('area.status_label', 'Voorlopig')
            ->assertJsonPath('groups.0.done', true)
            ->assertJsonPath('groups.0.provisional', true)
            ->assertJsonPath('groups.0.status_label', 'Voorlopig')
            ->assertJsonPath('area.dots.0.provisional', true);

        $task = AreaTask::query()->findOrFail($ids[0]);
        $this->assertSame(AreaStatus::Gereed, $task->status);
        $this->assertNull($task->approved_at);
        $this->assertTrue($task->isProvisional());
        $this->assertSame(AreaStatus::VoorlopigGereed, $area->fresh()->status);

        $leader = User::factory()->projectleider()->create();

        $this->actingAs($leader)
            ->postJson(route('projects.areas.approve', [$project, $area]), [
                'task_ids' => $ids,
            ])
            ->assertOk()
            ->assertJsonPath('area.tone', 'done')
            ->assertJsonPath('area.status_label', 'Gereed')
            ->assertJsonPath('groups.0.provisional', false)
            ->assertJsonPath('groups.0.status_label', 'Gereed')
            ->assertJsonPath('area.dots.0.provisional', false);

        $task = $task->fresh();
        $this->assertNotNull($task->approved_at);
        $this->assertSame($leader->id, $task->approved_by);
        $this->assertSame(AreaStatus::Gereed, $area->fresh()->status);
    }

    public function test_vakman_cannot_mark_progress_on_a_project_where_he_is_not_scheduled(): void
    {
        [$project, $area, $worker] = $this->makeBoard(assign: false);
        $other = $this->makeBoard()[0];
        $vakman = User::factory()->vakman($worker->id)->create();
        $this->assignWorker($worker, $other);
        $task = $this->firstOpenTask($area);

        $this->actingAs($vakman)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$task->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-08',
            ])
            ->assertForbidden();

        $this->assertSame(AreaStatus::NietGestart, $task->fresh()->status);
    }

    public function test_planner_cannot_mark_progress(): void
    {
        [$project, $area, $worker] = $this->makeBoard();
        $planner = User::factory()->create();
        $task = $this->firstOpenTask($area);

        $this->actingAs($planner)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$task->id],
                'worker_id' => $worker->id,
                'date' => '2026-09-08',
            ])
            ->assertForbidden();
    }

    public function test_uitvoerder_marks_work_definitive_without_akkoord(): void
    {
        [$project, $area, $worker] = $this->makeBoard();
        $uitvoerder = User::factory()->uitvoerder()->create();
        $ids = $this->openTaskIds($area);

        $this->actingAs($uitvoerder)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => $ids,
                'worker_id' => $worker->id,
                'date' => '2026-09-08',
            ])
            ->assertOk()
            ->assertJsonPath('area.tone', 'done')
            ->assertJsonPath('groups.0.provisional', false);

        $task = AreaTask::query()->findOrFail($ids[0]);
        $this->assertNotNull($task->approved_at);
        $this->assertSame($uitvoerder->id, $task->approved_by);
        $this->assertSame(AreaStatus::Gereed, $area->fresh()->status);
    }

    public function test_uitvoerder_cannot_approve_provisional_work(): void
    {
        [$project, $area, $worker] = $this->makeBoard();
        $vakman = User::factory()->vakman($worker->id)->create();
        $this->assignWorker($worker, $project);
        $ids = $this->openTaskIds($area);

        $this->actingAs($vakman)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => $ids,
                'worker_id' => $worker->id,
                'date' => '2026-09-08',
            ])
            ->assertOk();

        $uitvoerder = User::factory()->uitvoerder()->create();

        $this->actingAs($uitvoerder)
            ->postJson(route('projects.areas.approve', [$project, $area]), [
                'task_ids' => $ids,
            ])
            ->assertForbidden();
    }

    public function test_vakman_cannot_approve_own_work(): void
    {
        [$project, $area, $worker] = $this->makeBoard();
        $vakman = User::factory()->vakman($worker->id)->create();
        $this->assignWorker($worker, $project);
        $ids = $this->openTaskIds($area);

        $this->actingAs($vakman)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => $ids,
                'worker_id' => $worker->id,
                'date' => '2026-09-08',
            ])
            ->assertOk();

        $this->actingAs($vakman)
            ->postJson(route('projects.areas.approve', [$project, $area]), [
                'task_ids' => $ids,
            ])
            ->assertForbidden();
    }

    public function test_vakman_cannot_reopen_approved_work(): void
    {
        [$project, $area, $worker] = $this->makeBoard();
        $leader = User::factory()->projectleider()->create();
        $ids = $this->openTaskIds($area);

        $this->actingAs($leader)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => $ids,
                'worker_id' => $worker->id,
                'date' => '2026-09-08',
            ])
            ->assertOk();

        $vakman = User::factory()->vakman($worker->id)->create();
        $this->assignWorker($worker, $project);

        $this->actingAs($vakman)
            ->postJson(route('projects.areas.reopen', [$project, $area]), [
                'task_ids' => $ids,
            ])
            ->assertForbidden();

        $this->assertNotNull(AreaTask::query()->findOrFail($ids[0])->approved_at);
    }

    public function test_vakman_progress_is_always_booked_on_own_team(): void
    {
        [$project, $area, $worker] = $this->makeBoard();
        $other = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $vakman = User::factory()->vakman($worker->id)->create();
        $this->assignWorker($worker, $project);
        $task = $this->firstOpenTask($area);

        $this->actingAs($vakman)
            ->postJson(route('projects.areas.process', [$project, $area]), [
                'task_ids' => [$task->id],
                'worker_id' => $other->id,
                'date' => '2026-09-08',
            ])
            ->assertOk();

        $this->assertSame($worker->id, $task->fresh()->completed_by);
    }

    public function test_vakman_sees_provisional_progress_form_on_the_drawing(): void
    {
        [$project, $area, $worker] = $this->makeBoard();
        Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        $vakman = User::factory()->vakman($worker->id)->create();
        $this->assignWorker($worker, $project);

        $this->actingAs($vakman)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('id="complete-form"', false)
            ->assertSee('Klaar blijft voorlopig tot de projectleider akkoord geeft.')
            ->assertDontSee('Hele verdieping of hele werk aanvinken, daarna egaliseren of een vloertype.')
            ->assertSee('Klaar melden (voorlopig)')
            ->assertSee('Open kring = voorlopig, wacht op akkoord')
            ->assertSee($worker->planName())
            ->assertDontSee('Kees Jansen')
            ->assertDontSee('Alleen ter inzage');
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function enterProgressRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, true],
            'vakman' => [UserRole::Vakman, true],
            'planner' => [UserRole::Planner, false],
        ];
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function approveProgressRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'vakman' => [UserRole::Vakman, false],
            'planner' => [UserRole::Planner, false],
        ];
    }

    /**
     * @return array{0: Project, 1: ProjectArea, 2: Worker}
     */
    private function makeBoard(bool $assign = true): array
    {
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $customer = Customer::query()->first() ?? Customer::query()->create(['name' => 'Nicon vloeren']);
        $project = Project::query()->create([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
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
            'ordered_quantity' => 19.4,
            'status' => 'gepland',
            'sort_order' => 10,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => 'P.0.01',
            'name' => 'entree school',
            'square_meters' => 19.4,
            'status' => 'niet_gestart',
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $work->id,
            'ordered_quantity' => 19.4,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);
        app(RoomWorkSetup::class)->ensureProject($project);

        if ($assign) {
            $this->assignWorker($worker, $project);
        }

        return [$project->fresh(), $area->fresh(['tasks.workItem']), $worker];
    }

    private function assignWorker(Worker $worker, Project $project): void
    {
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'hours_per_day' => 8,
        ]);
    }

    /**
     * @return list<int>
     */
    private function openTaskIds(ProjectArea $area): array
    {
        return $area->fresh(['tasks'])->tasks->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function firstOpenTask(ProjectArea $area): AreaTask
    {
        $task = $area->fresh(['tasks.workItem'])->tasks
            ->first(fn (AreaTask $task) => $task->phase() === WorkPhase::Egaliseren)
            ?? $area->tasks->first();

        $this->assertNotNull($task);

        return $task;
    }
}
