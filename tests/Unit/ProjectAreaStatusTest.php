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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectAreaStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_room_without_tasks_keeps_its_status(): void
    {
        $area = $this->area();

        $area->refreshStatusFromTasks();

        $this->assertSame(AreaStatus::NietGestart, $area->fresh()->status);
    }

    public function test_open_tasks_keep_an_open_room(): void
    {
        $area = $this->area();
        $this->task($area, AreaStatus::NietGestart);

        $area->refreshStatusFromTasks();

        $this->assertSame(AreaStatus::NietGestart, $area->fresh()->status);
    }

    public function test_a_started_task_marks_the_room_in_progress_and_writes(): void
    {
        $area = $this->area();
        $this->task($area, AreaStatus::InUitvoering);
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (str_starts_with(ltrim(strtolower($query->sql)), 'update')) {
                $writes++;
            }
        });

        $area->refreshStatusFromTasks();

        $this->assertSame(1, $writes);
        $this->assertSame(AreaStatus::InUitvoering, $area->fresh()->status);
    }

    public function test_finished_unapproved_tasks_mark_the_room_provisional(): void
    {
        $area = $this->area();
        $this->task($area, AreaStatus::Gereed);

        $area->refreshStatusFromTasks();

        $this->assertSame(AreaStatus::VoorlopigGereed, $area->fresh()->status);
    }

    public function test_approved_tasks_mark_the_room_done(): void
    {
        $area = $this->area();
        $task = $this->task($area, AreaStatus::Gereed);
        $task->forceFill(['approved_at' => now()])->save();

        $area->refreshStatusFromTasks();

        $this->assertSame(AreaStatus::Gereed, $area->fresh()->status);
    }

    public function test_a_correct_status_is_not_written_again(): void
    {
        $area = $this->area();
        $this->task($area, AreaStatus::NietGestart);
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (str_starts_with(ltrim(strtolower($query->sql)), 'update')) {
                $writes++;
            }
        });

        $area->load('tasks')->refreshStatusFromTasks();

        $this->assertSame(0, $writes);
        $this->assertSame(AreaStatus::NietGestart, $area->status);
    }

    public function test_status_without_a_loaded_relation_still_follows_the_tasks(): void
    {
        $area = $this->area();
        $this->task($area, AreaStatus::InUitvoering);
        $this->assertFalse($area->relationLoaded('tasks'));

        $area->refreshStatusFromTasks();

        $this->assertSame(AreaStatus::InUitvoering, $area->fresh()->status);
    }

    public function test_ensuring_a_loaded_project_does_not_reload_tasks_per_room(): void
    {
        $project = $this->area()->project;
        $floor = $project->floors()->first();
        $second = ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.02',
            'name' => 'gang',
            'square_meters' => 8,
            'status' => AreaStatus::NietGestart,
        ]);
        $this->task($second, AreaStatus::NietGestart);
        $loaded = $project->fresh(['areas.tasks.workItem', 'workItems']);
        $perRoom = 0;
        DB::listen(function ($query) use (&$perRoom): void {
            if (str_contains($query->sql, 'area_tasks') && str_contains($query->sql, 'project_area_id') && ! str_contains($query->sql, ' in (')) {
                $perRoom++;
            }
        });

        app(RoomWorkSetup::class)->ensureProject($loaded);

        $this->assertSame(0, $perRoom);
    }

    private function area(): ProjectArea
    {
        $project = Project::query()->create([
            'project_number' => '260800997',
            'customer_id' => Customer::query()->create(['name' => 'Status'])->id,
            'name' => 'Status',
            'status' => 'in_uitvoering',
        ]);
        $floor = ProjectFloor::query()->create([
            'project_id' => $project->id,
            'name' => 'begane grond',
            'sort_order' => 1,
        ]);

        return ProjectArea::query()->create([
            'project_id' => $project->id,
            'project_floor_id' => $floor->id,
            'area_number' => '0.01',
            'name' => 'hal',
            'square_meters' => 10,
            'status' => AreaStatus::NietGestart,
        ]);
    }

    private function task(ProjectArea $area, AreaStatus $status): AreaTask
    {
        $item = WorkItem::query()->create([
            'project_id' => $area->project_id,
            'name' => 'Tapijt '.$area->id.' '.$status->value,
            'unit' => WorkUnit::SquareMeter,
            'ordered_quantity' => 10,
            'status' => 'gepland',
        ]);

        return AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'ordered_quantity' => 10,
            'unit' => WorkUnit::SquareMeter,
            'status' => $status,
        ]);
    }
}
