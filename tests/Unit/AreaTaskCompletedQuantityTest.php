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
use App\Models\WorkProgressEntry;
use App\Services\ProjectBoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AreaTaskCompletedQuantityTest extends TestCase
{
    use RefreshDatabase;

    public function test_loaded_progress_matches_the_database_sum_for_every_task(): void
    {
        $project = $this->project();
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);
        $linoleum = $this->workItem($project, 'Linoleum');
        $pvc = $this->workItem($project, 'PVC');
        $hal = $this->area($project, $floor, '0.01', 'hal');
        $gang = $this->area($project, $floor, '0.02', 'gang');
        $empty = $this->area($project, $floor, '0.03', 'leeg');

        $halLino = $this->task($hal, $linoleum, 40.5);
        $halPvc = $this->task($hal, $pvc, 10);
        $gangLino = $this->task($gang, $linoleum, 12.25);
        $gangDone = $this->task($gang, $pvc, 8);
        $untouched = $this->task($empty, $linoleum, 3);

        $this->progress($project, $hal, $linoleum, 10.25);
        $this->progress($project, $hal, $linoleum, 5.5);
        $this->progress($project, $gang, $linoleum, 1.2);
        $this->progress($project, $gang, $pvc, 8);

        $database = [];
        foreach ([$halLino, $halPvc, $gangLino, $gangDone, $untouched] as $task) {
            $database[$task->id] = round((float) WorkProgressEntry::query()
                ->where('project_area_id', $task->project_area_id)
                ->where('work_item_id', $task->work_item_id)
                ->sum('completed_quantity'), 2);
        }

        $loaded = $project->fresh();
        $sums = 0;
        DB::listen(function ($query) use (&$sums): void {
            if (str_contains($query->sql, 'sum(`completed_quantity`)') || str_contains($query->sql, 'sum("completed_quantity")')) {
                $sums++;
            }
        });

        app(ProjectBoardService::class)->payload($loaded);

        $this->assertSame(0, $sums);
        foreach ($loaded->areas->flatMap(fn (ProjectArea $area) => $area->tasks) as $task) {
            if (! array_key_exists($task->id, $database)) {
                $this->assertEqualsWithDelta(0.0, $task->completedQuantity(), 0.001);

                continue;
            }
            $expected = $database[$task->id];
            $this->assertEqualsWithDelta($expected, $task->completedQuantity(), 0.001);
            $this->assertEqualsWithDelta(
                max(0, round((float) $task->ordered_quantity - $expected, 2)),
                $task->remainingQuantity(),
                0.001,
            );
        }
        $this->assertSame(0, $sums);
        $this->assertEqualsWithDelta(0.0, $database[$untouched->id], 0.001);
        $this->assertEqualsWithDelta(15.75, $database[$halLino->id], 0.001);
        $this->assertEqualsWithDelta(0.0, $database[$halPvc->id], 0.001);
        $this->assertEqualsWithDelta(1.2, $database[$gangLino->id], 0.001);
        $this->assertEqualsWithDelta(8.0, $database[$gangDone->id], 0.001);
        $this->assertNotSame($database[$halLino->id], $database[$gangLino->id]);
    }

    private function project(): Project
    {
        return Project::query()->create([
            'project_number' => '260800998',
            'customer_id' => Customer::query()->create(['name' => 'Som'])->id,
            'name' => 'Voortgangsom',
            'status' => 'in_uitvoering',
        ]);
    }

    private function workItem(Project $project, string $name): WorkItem
    {
        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 100,
            'status' => 'gepland',
        ]);
    }

    private function area(Project $project, ProjectFloor $floor, string $number, string $name): ProjectArea
    {
        return ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => $number,
            'name' => $name,
            'square_meters' => 10,
            'status' => AreaStatus::NietGestart,
        ]);
    }

    private function task(ProjectArea $area, WorkItem $item, float $ordered): AreaTask
    {
        return AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'ordered_quantity' => $ordered,
            'unit' => WorkUnit::SquareMeter,
            'status' => AreaStatus::NietGestart,
        ]);
    }

    private function progress(Project $project, ProjectArea $area, WorkItem $item, float $quantity): void
    {
        WorkProgressEntry::query()->create([
            'project_id' => $project->id,
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'date' => '2026-09-21',
            'completed_quantity' => $quantity,
            'unit' => WorkUnit::SquareMeter,
            'worked_hours' => 1,
        ]);
    }
}
