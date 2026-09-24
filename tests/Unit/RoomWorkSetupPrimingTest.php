<?php

namespace Tests\Unit;

use App\Enums\AreaStatus;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\WorkItem;
use App\Services\RoomWorkSetup;
use App\Support\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoomWorkSetupPrimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hard_flooring_derives_matching_priming_meters(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 50.0);
        $this->addFlooringTask($project, $area, 'Linoleum', 44.04);
        $this->addFlooringTask($project, $area, 'Plinten wit', 127.78, WorkUnit::LinearMeter);

        app(RoomWorkSetup::class)->ensureArea($area->fresh(['tasks.workItem', 'project.workItems']));

        $area = $area->fresh(['tasks.workItem']);
        $primen = $area->tasks->first(fn (AreaTask $task) => $task->workItem?->name === RoomWorkSetup::PRIMEN_EGALISEREN);
        $linoleum = $area->tasks->first(fn (AreaTask $task) => $task->workItem?->name === 'Linoleum');
        $plinten = $area->tasks->first(fn (AreaTask $task) => $task->workItem?->name === 'Plinten wit');

        $this->assertNotNull($primen);
        $this->assertEqualsWithDelta(44.04, (float) $primen->ordered_quantity, 0.001);
        $this->assertSame(RoomWorkSetup::DERIVED_FLOORING_SOURCE, $primen->quantity_source);
        $this->assertEqualsWithDelta(44.04, (float) $linoleum->ordered_quantity, 0.001);
        $this->assertEqualsWithDelta(127.78, (float) $plinten->ordered_quantity, 0.001);
        $this->assertSame(WorkUnit::LinearMeter, $plinten->unit);
    }

    public function test_multiple_hard_floorings_sum_without_counting_plinten(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 100.0);
        $this->addFlooringTask($project, $area, 'Marmoleum Real, 3120 rosato, Linoleum', 20.00);
        $this->addFlooringTask($project, $area, 'Tarkett iQ Granit, PVC', 24.04);
        $this->addFlooringTask($project, $area, 'Plinten wit', 80.0, WorkUnit::LinearMeter);

        app(RoomWorkSetup::class)->ensureArea($area->fresh(['tasks.workItem', 'project.workItems']));

        $primen = $area->fresh(['tasks.workItem'])->tasks
            ->first(fn (AreaTask $task) => $task->workItem?->name === RoomWorkSetup::PRIMEN_EGALISEREN);

        $this->assertNotNull($primen);
        $this->assertEqualsWithDelta(44.04, (float) $primen->ordered_quantity, 0.001);
    }

    public function test_soft_flooring_does_not_create_priming_task(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 40.0);
        $this->addFlooringTask($project, $area, 'Ege Reform Heritage RF 7133080, Tapijttegels', 40.0);

        app(RoomWorkSetup::class)->ensureArea($area->fresh(['tasks.workItem', 'project.workItems']));

        $ondergrond = $area->fresh(['tasks.workItem'])->tasks
            ->filter(fn (AreaTask $task) => $task->phase()->group() === 'ondergrond');

        $this->assertTrue($ondergrond->isEmpty());
        $this->assertFalse(WorkType::requiresPrimingLeveling('Ege Reform Heritage RF 7133080, Tapijttegels'));
    }

    public function test_coating_only_room_does_not_create_priming_task(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 40.0);
        $this->addFlooringTask($project, $area, 'PU gietvloer Sikkens F2.10.60, Coating', 40.0);

        app(RoomWorkSetup::class)->ensureArea($area->fresh(['tasks.workItem', 'project.workItems']));

        $ondergrond = $area->fresh(['tasks.workItem'])->tasks
            ->filter(fn (AreaTask $task) => $task->phase()->group() === 'ondergrond');

        $this->assertTrue($ondergrond->isEmpty());
    }

    public function test_coating_gietvloer_carpet_and_entreemat_are_excluded_from_priming(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 200.0);
        $this->addFlooringTask($project, $area, 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT', 100.00);
        $this->addFlooringTask($project, $area, 'PU gietvloer Sikkens F2.10.60, Coating', 40.00);
        $this->addFlooringTask($project, $area, 'Ege Reform Heritage RF 7133080, Tapijttegels', 50.00);
        $this->addFlooringTask($project, $area, '43.20.02 Coral Brush 5721-hurricane grey, Entreemat Banen', 10.00);

        app(RoomWorkSetup::class)->ensureArea($area->fresh(['tasks.workItem', 'project.workItems']));

        $primen = $area->fresh(['tasks.workItem'])->tasks
            ->first(fn (AreaTask $task) => $task->workItem?->name === RoomWorkSetup::PRIMEN_EGALISEREN);

        $this->assertNotNull($primen);
        $this->assertEqualsWithDelta(100.00, (float) $primen->ordered_quantity, 0.001);
    }

    public function test_explicit_priming_quantity_from_source_is_not_overwritten(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 50.0);
        $this->addFlooringTask($project, $area, 'Linoleum', 44.04);
        $primenItem = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => RoomWorkSetup::PRIMEN_EGALISEREN,
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 30.0,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $primenItem->id,
            'ordered_quantity' => 30.0,
            'quantity_source' => 'meetstaat',
            'unit' => WorkUnit::SquareMeter,
            'status' => AreaStatus::NietGestart,
        ]);

        app(RoomWorkSetup::class)->ensureArea($area->fresh(['tasks.workItem', 'project.workItems']));

        $primen = $area->fresh(['tasks.workItem'])->tasks
            ->first(fn (AreaTask $task) => $task->workItem?->name === RoomWorkSetup::PRIMEN_EGALISEREN);

        $this->assertEqualsWithDelta(30.0, (float) $primen->ordered_quantity, 0.001);
        $this->assertSame('meetstaat', $primen->quantity_source);
    }

    public function test_zero_priming_placeholder_is_replaced_by_derived_flooring_meters(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 0.0);
        $this->addFlooringTask($project, $area, 'Linoleum', 44.04);
        $primenItem = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => RoomWorkSetup::PRIMEN_EGALISEREN,
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 0,
            'status' => 'gepland',
            'sort_order' => 1,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $primenItem->id,
            'ordered_quantity' => 0,
            'unit' => WorkUnit::SquareMeter,
            'status' => AreaStatus::NietGestart,
        ]);

        app(RoomWorkSetup::class)->ensureArea($area->fresh(['tasks.workItem', 'project.workItems']));

        $primen = $area->fresh(['tasks.workItem'])->tasks
            ->first(fn (AreaTask $task) => $task->workItem?->name === RoomWorkSetup::PRIMEN_EGALISEREN);

        $this->assertEqualsWithDelta(44.04, (float) $primen->ordered_quantity, 0.001);
        $this->assertSame(RoomWorkSetup::DERIVED_FLOORING_SOURCE, $primen->quantity_source);
    }

    public function test_project_priming_quantity_includes_hard_flooring_without_area_tasks(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 100.0);
        $this->addFlooringTask($project, $area, 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT', 100.00);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Tarkett vinyl iQ Natural-dark warm grey, PVC Banen / Vinyl',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 50.00,
            'status' => 'gepland',
            'sort_order' => 20,
        ]);

        app(RoomWorkSetup::class)->ensureProject($project->fresh(['areas.tasks.workItem', 'workItems']));

        $primen = $project->fresh('workItems')->workItems
            ->first(fn (WorkItem $item) => $item->name === RoomWorkSetup::PRIMEN_EGALISEREN);

        $this->assertNotNull($primen);
        $this->assertEqualsWithDelta(150.00, (float) $primen->ordered_quantity, 0.001);
    }

    public function test_sluisbuurt_priming_uses_hard_floor_areas_and_skips_the_entrance_mat(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 2529.56);
        $this->addFlooringTask($project, $area, 'Marmorette R854-0045, sand beige, Linoleum', 1314.76);
        $this->addFlooringTask($project, $area, 'Lino Art Urban R893-0555, flashy street grey, Linoleum', 871.73);
        $this->addFlooringTask($project, $area, 'Lino Art Urban R893-0555 op kurk, flashy street grey, Linoleum', 120.39);
        $this->addFlooringTask($project, $area, 'Coral Classic (kleur n.t.b.), n.t.b., Entreemat Banen', 45.32);
        $this->addFlooringTask($project, $area, 'Mipolam Planet, 5438, PVC / Vinyl', 148.40);
        $this->addFlooringTask($project, $area, 'Forbo Surestep , kleur n.t.b., PVC / Vinyl', 28.96);

        app(RoomWorkSetup::class)->ensureProject($project->fresh(['areas.tasks.workItem', 'workItems']));

        $primen = $project->fresh('workItems')->workItems
            ->first(fn (WorkItem $item) => $item->name === RoomWorkSetup::PRIMEN_EGALISEREN);

        $this->assertNotNull($primen);
        $this->assertSame(WorkUnit::SquareMeter, $primen->unit);
        $this->assertEqualsWithDelta(2484.24, (float) $primen->ordered_quantity, 0.001);
    }

    public function test_ensuring_a_project_reuses_loaded_work_items_and_keeps_quantities(): void
    {
        [$project, $first] = $this->makeArea(squareMeters: 40.0);
        $this->addFlooringTask($project, $first, 'Linoleum', 40.0);
        $second = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $first->project_floor_id,
            'area_number' => '0.02',
            'name' => 'gang',
            'square_meters' => 20,
            'status' => AreaStatus::NietGestart,
        ]);
        $this->addFlooringTask($project, $second, 'Linoleum', 20.0);
        $loaded = $project->fresh(['areas.tasks.workItem', 'workItems']);
        app(RoomWorkSetup::class)->ensureProject($loaded);
        $before = $loaded->fresh('workItems')->workItems->mapWithKeys(
            fn (WorkItem $item): array => [$item->name => (float) $item->ordered_quantity]
        )->all();
        $queries = 0;
        $writes = 0;
        DB::listen(function ($query) use (&$queries, &$writes): void {
            $queries++;
            $sql = ltrim(strtolower($query->sql));
            if (str_starts_with($sql, 'insert') || str_starts_with($sql, 'update') || str_starts_with($sql, 'delete')) {
                $writes++;
            }
        });

        app(RoomWorkSetup::class)->ensureProject($loaded);
        $during = $queries;
        $after = $loaded->fresh('workItems')->workItems->mapWithKeys(
            fn (WorkItem $item): array => [$item->name => (float) $item->ordered_quantity]
        )->all();

        $this->assertSame($before, $after);
        $this->assertSame(0, $during);
        $this->assertSame(0, $writes);
        $this->assertArrayHasKey(RoomWorkSetup::PRIMEN_EGALISEREN, $after);
        $this->assertEqualsWithDelta(60.0, $after[RoomWorkSetup::PRIMEN_EGALISEREN], 0.001);
    }

    public function test_coating_work_item_without_area_tasks_is_not_added_to_project_priming(): void
    {
        [$project, $area] = $this->makeArea(squareMeters: 140.0);
        $this->addFlooringTask($project, $area, 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT', 100.00);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PU gietvloer Sikkens F2.10.60, Coating',
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 40.00,
            'status' => 'gepland',
            'sort_order' => 20,
        ]);

        app(RoomWorkSetup::class)->ensureProject($project->fresh(['areas.tasks.workItem', 'workItems']));

        $primen = $project->fresh('workItems')->workItems
            ->first(fn (WorkItem $item) => $item->name === RoomWorkSetup::PRIMEN_EGALISEREN);

        $this->assertNotNull($primen);
        $this->assertEqualsWithDelta(100.00, (float) $primen->ordered_quantity, 0.001);
    }

    /**
     * @return array{0: Project, 1: ProjectArea}
     */
    private function makeArea(float $squareMeters): array
    {
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);
        $project = Project::query()->create([
            'project_number' => '260800999',
            'customer_id' => $customer->id,
            'name' => 'Priming setup test',
            'status' => 'gepland',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $area = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.01',
            'name' => 'hal',
            'square_meters' => $squareMeters,
            'status' => AreaStatus::NietGestart,
        ]);

        return [$project->fresh('workItems'), $area];
    }

    private function addFlooringTask(
        Project $project,
        ProjectArea $area,
        string $name,
        float $quantity,
        WorkUnit $unit = WorkUnit::SquareMeter,
    ): void {
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => $unit,
            'ordered_quantity' => $quantity,
            'status' => 'gepland',
            'sort_order' => 10,
        ]);
        AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'ordered_quantity' => $quantity,
            'unit' => $unit,
            'status' => AreaStatus::NietGestart,
        ]);
        $project->unsetRelation('workItems');
        $project->load('workItems');
        $area->unsetRelation('tasks');
        $area->load('tasks.workItem');
    }
}
