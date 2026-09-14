<?php

namespace Tests\Feature;

use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use App\Models\WorkTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_voortgang_is_removed_from_the_menu_and_other_pages_still_work(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Planning')
            ->assertSee('Productie')
            ->assertSee('Projecten')
            ->assertSee('Archief')
            ->assertSee('Vakmensen / ZZP')
            ->assertSee('https://app.decoloop.com/dossier/floor_browse', false)
            ->assertSee('images/decoloop.png', false)
            ->assertSee('Decoloop')
            ->assertDontSee('href="'.url('/voortgang').'"', false);

        $this->actingAs($user)->get(route('planning'))->assertOk();
        $this->actingAs($user)->get(route('projects.index'))->assertOk();
        $this->actingAs($user)->get(route('workers.index'))->assertOk()->assertSee('Vakmensen / ZZP');
        $this->actingAs($user)->get(route('production.index'))->assertOk();
    }

    public function test_old_voortgang_url_redirects_to_productie(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject()[1];

        $this->actingAs($user)
            ->get(route('progress.create', ['project_id' => $project->id]))
            ->assertRedirect(route('production.index', ['project_id' => $project->id]));
    }

    public function test_overview_lists_worker_project_rooms_m2_and_materials(): void
    {
        $user = User::factory()->create();
        [$albert, $jan, $project, $other] = $this->seedProduction();

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Albert')
            ->assertSee('Jan')
            ->assertSee('Laakse Tuinen Amersfoort')
            ->assertSee('School Zwolle')
            ->assertSee('0.07 groepsruimte')
            ->assertSee('50,97')
            ->assertSee('Marmoleum Real, Linoleum')
            ->assertSee('Primen & Egaliseren')
            ->assertSee('Plinten wit')
            ->assertSee('25,31');

        $this->actingAs($user)
            ->get(route('production.index', ['worker_id' => $albert->id]))
            ->assertOk()
            ->assertSee('Albert')
            ->assertSee('Laakse Tuinen Amersfoort')
            ->assertSee('0.07 groepsruimte')
            ->assertDontSee('PVC tegels');

        $this->actingAs($user)
            ->get(route('production.index', ['project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Albert')
            ->assertSee('Laakse Tuinen Amersfoort')
            ->assertDontSee('PVC tegels');

        $this->actingAs($user)
            ->get(route('workers.show', $albert))
            ->assertOk()
            ->assertSee('0.07 groepsruimte')
            ->assertSee('Marmoleum Real, Linoleum');
    }

    public function test_completing_a_room_on_the_drawing_still_lands_in_productie(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        [, $project] = $this->makeProject();
        $area = $project->areas()->where('area_number', '0.07')->first();
        $task = $area->tasks()->first();

        $task->markDone($worker, '2026-09-02', $user);

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Albert')
            ->assertSee('0.07 groepsruimte')
            ->assertSee('50,97')
            ->assertSee('Marmoleum Real, Linoleum')
            ->assertSee('Akkoord');
    }

    public function test_vakman_klaar_shows_as_waiting_for_akkoord_on_own_production_page(): void
    {
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        [, $project] = $this->makeProject();
        $leader = User::factory()->projectleider()->create();
        $area = $project->areas()->where('area_number', '0.07')->first();
        $task = $area->tasks()->first();
        $vakman = User::factory()->vakman($worker->id)->create();
        $this->assignWorker($worker, $project);

        $task->markDone($worker, '2026-09-08', $vakman);

        $this->actingAs($leader)
            ->get(route('production.index', ['worker_id' => $worker->id, 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('Harm Wesselink')
            ->assertSee('Klaar gemeld · wacht op akkoord')
            ->assertSee('Wacht op akkoord')
            ->assertSee('Akkoord voorlopig werk')
            ->assertSee('te keuren');

        $this->actingAs($vakman)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('Harm Wesselink')
            ->assertSee('Klaar gemeld · wacht op akkoord')
            ->assertDontSee('Alle vakmensen')
            ->assertDontSee('Akkoord voorlopig werk');
    }

    public function test_projectleider_approves_provisional_work_from_production(): void
    {
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        [, $project] = $this->makeProject();
        $leader = User::factory()->projectleider()->create();
        $area = $project->areas()->where('area_number', '0.07')->first();
        $task = $area->tasks()->first();
        $vakman = User::factory()->vakman($worker->id)->create();
        $this->assignWorker($worker, $project);
        $task->markDone($worker, '2026-09-08', $vakman);

        $this->actingAs($leader)
            ->from(route('production.index', ['worker_id' => $worker->id]))
            ->post(route('production.approve'), [
                'project_id' => $project->id,
                'task_ids' => [$task->id],
            ])
            ->assertRedirect(route('production.index', ['worker_id' => $worker->id]));

        $this->assertNotNull($task->fresh()->approved_at);
        $this->assertSame($leader->id, $task->fresh()->approved_by);

        $this->actingAs($leader)
            ->get(route('production.index', ['worker_id' => $worker->id]))
            ->assertOk()
            ->assertSee('Akkoord')
            ->assertDontSee('Klaar gemeld · wacht op akkoord')
            ->assertDontSee('Akkoord voorlopig werk');
    }

    public function test_planner_cannot_approve_production_progress(): void
    {
        $planner = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'active' => true,
        ]);
        [, $project] = $this->makeProject();
        $area = $project->areas()->where('area_number', '0.07')->first();
        $task = $area->tasks()->first();
        $vakman = User::factory()->vakman($worker->id)->create();
        $task->markDone($worker, '2026-09-08', $vakman);

        $this->actingAs($planner)
            ->post(route('production.approve'), [
                'project_id' => $project->id,
                'task_ids' => [$task->id],
            ])
            ->assertForbidden();

        $this->assertNull($task->fresh()->approved_at);
    }

    public function test_overview_lists_an_opdrachtbon_before_rooms_are_checked_off(): void
    {
        $user = User::factory()->create();
        [$worker, $project, $area] = $this->makeNamedProject('Het Vloerenhuis', '250100010', 'Gezondheidscentrum Laren');
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 84,
            'status' => 'gepland',
            'sort_order' => 2,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'ordered_quantity' => 84,
            'unit' => WorkUnit::SquareMeter,
            'status' => 'niet_gestart',
        ]);
        $ticket = WorkTicket::query()->create([
            'number' => 'OB-2026-0001',
            'kind' => WorkTicketKind::Opdrachtbon,
            'project_id' => $project->id,
            'worker_id' => $worker->id,
            'created_by' => $user->id,
            'billing_method' => WorkTicketBilling::Hourly,
            'hourly_rate' => 42.5,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-17',
        ]);
        $ticket->lines()->create([
            'work_item_id' => $item->id,
            'quantity' => 84,
            'unit' => WorkUnit::SquareMeter,
        ]);
        $ticket->areas()->attach($area->id);

        $this->actingAs($user)
            ->get(route('production.index'))
            ->assertOk()
            ->assertSee('OB-2026-0001')
            ->assertSee('Gezondheidscentrum Laren')
            ->assertSee('PVC')
            ->assertSee('1 ruimte')
            ->assertSee('Open')
            ->assertSee('Verwijderen');
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

    /** @return array{0: Worker, 1: Worker, 2: Project, 3: Project} */
    private function seedProduction(): array
    {
        [$albert, $project, $area] = $this->makeNamedProject('Albert', '260200090', 'Laakse Tuinen Amersfoort');
        [$jan, $other] = array_slice($this->makeNamedProject('Jan', '260200091', 'School Zwolle'), 0, 2);

        $floor = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Marmoleum Real',
            'unit' => 'm2',
            'ordered_quantity' => 50.97,
            'status' => 'in_uitvoering',
            'sort_order' => 10,
        ]);
        $primer = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 50.97,
            'status' => 'in_uitvoering',
            'sort_order' => 1,
        ]);
        $plint = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Plinten wit',
            'unit' => 'm1',
            'ordered_quantity' => 25.31,
            'status' => 'in_uitvoering',
            'sort_order' => 20,
        ]);
        $otherFloor = WorkItem::query()->create([
            'project_id' => $other->id,
            'name' => 'PVC tegels',
            'unit' => 'm2',
            'ordered_quantity' => 40,
            'status' => 'in_uitvoering',
            'sort_order' => 10,
        ]);

        foreach ([$floor, $primer, $plint] as $item) {
            WorkProgressEntry::query()->create([
                'project_id' => $project->id,
                'work_item_id' => $item->id,
                'project_area_id' => $area->id,
                'worker_id' => $albert->id,
                'date' => '2026-09-02',
                'completed_quantity' => $item->unit->value === 'm1' ? 25.31 : 50.97,
                'unit' => $item->unit,
                'worked_hours' => 8,
            ]);
        }

        $otherArea = $other->areas()->first();
        WorkProgressEntry::query()->create([
            'project_id' => $other->id,
            'work_item_id' => $otherFloor->id,
            'project_area_id' => $otherArea->id,
            'worker_id' => $jan->id,
            'date' => '2026-09-03',
            'completed_quantity' => 40,
            'unit' => 'm2',
            'worked_hours' => 8,
        ]);

        return [$albert, $jan, $project, $other];
    }

    /** @return array{0: Worker, 1: Project, 2: ProjectArea} */
    private function makeNamedProject(string $workerName, string $number, string $name): array
    {
        $worker = Worker::query()->create([
            'name' => $workerName,
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Klant '.$number]);
        $project = Project::query()->create([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.07',
            'name' => 'groepsruimte',
            'square_meters' => 50.97,
            'status' => 'in_uitvoering',
        ]);

        return [$worker, $project, $area];
    }

    /** @return array{0: User, 1: Project} */
    private function makeProject(): array
    {
        $user = User::factory()->create();
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
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.07',
            'name' => 'groepsruimte',
            'square_meters' => 50.97,
            'status' => 'niet_gestart',
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $work->id,
            'ordered_quantity' => 50.97,
            'unit' => 'm2',
            'status' => 'niet_gestart',
        ]);

        return [$user, $project->fresh(['areas.tasks.workItem'])];
    }
}
