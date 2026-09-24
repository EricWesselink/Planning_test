<?php

namespace Tests\Feature;

use App\Enums\WorkOrderType;
use App\Enums\WorkUnit;
use App\Models\Customer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SafeQueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekstaat_reuses_loaded_assignments_for_planned_hours(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Piet Dekker',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $member = $worker->crewPeople()->first();
        $project = $this->project('Kantoor');
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'gepland',
        ]);
        $assignment = new WorkerAssignment([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(Carbon::parse('2026-09-21'), Carbon::parse('2026-09-21'), '08:00:00', '16:00:00');
        $assignment->save();
        TimeEntry::factory()->create([
            'worker_id' => $worker->id,
            'crew_member_id' => $member?->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'worker_assignment_id' => $assignment->id,
            'date' => '2026-09-21',
            'hours' => 8,
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)
            ->get(route('personnel.index', [
                'week' => '2026-09-21',
                'tab' => 'weekstaat',
                'day' => '2026-09-21',
                'worker_id' => $worker->id,
                'crew_member_id' => $member?->id,
            ]))
            ->assertOk()
            ->assertSee('Piet Dekker')
            ->assertSeeText('Gepland: 8u');

        $weekLoads = $this->matching($queries, function (string $sql): bool {
            return str_contains($sql, 'worker_assignments')
                && str_contains($sql, 'start_date')
                && str_contains($sql, 'end_date');
        });
        $repeated = $this->matching($queries, function (string $sql): bool {
            return str_contains($sql, 'worker_assignments')
                && str_contains($sql, 'start_date')
                && str_contains($sql, 'end_date')
                && ! str_contains($sql, ' in (');
        });
        $wrappedDates = $this->matching($queries, function (string $sql): bool {
            $normalized = strtolower($sql);

            return str_contains($normalized, 'date(') && str_contains($normalized, 'start_date');
        });

        $this->assertCount(1, $weekLoads);
        $this->assertSame([], $repeated);
        $this->assertSame([], $wrappedDates);
    }

    public function test_planning_reuses_eager_loaded_work_orders(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Opdracht Piet',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $project = $this->project('Showroom');
        foreach (['PVC', 'Tapijt', 'Plinten'] as $name) {
            $item = WorkItem::query()->create([
                'project_id' => $project->id,
                'name' => $name,
                'unit' => WorkUnit::SquareMeter,
                'ordered_quantity' => 20,
                'status' => 'gepland',
            ]);
            WorkOrder::query()->create([
                'project_id' => $project->id,
                'work_item_id' => $item->id,
                'worker_id' => $worker->id,
                'assignment_type' => WorkOrderType::WorkItem,
                'assigned_quantity' => 20,
                'unit' => WorkUnit::SquareMeter,
                'status' => 'gepland',
            ]);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-21']))
            ->assertOk()
            ->assertSee('Opdracht Piet')
            ->assertSee('PVC');

        $perItem = $this->matching($queries, function (string $sql): bool {
            $normalized = strtolower($sql);

            return str_contains($normalized, 'work_orders')
                && str_contains($normalized, 'work_item_id')
                && ! str_contains($normalized, ' in (');
        });

        $this->assertSame([], $perItem);
    }

    public function test_dashboard_includes_an_assignment_that_ends_today(): void
    {
        $this->travelTo('2026-09-23 09:00:00');
        $user = User::factory()->create();
        $today = Worker::query()->create([
            'name' => 'Vandaag Piet',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $yesterday = Worker::query()->create([
            'name' => 'Gisteren Piet',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $project = $this->project('Hal');
        WorkerAssignment::query()->create([
            'worker_id' => $today->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-23',
            'end_date' => '2026-09-23',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $yesterday->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-22',
            'end_date' => '2026-09-22',
            'hours_per_day' => 8,
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Vandaag Piet')
            ->assertDontSee('Gisteren Piet');

        $wrappedDates = $this->matching($queries, function (string $sql): bool {
            $normalized = strtolower($sql);

            return str_contains($normalized, 'date(') && str_contains($normalized, 'start_date');
        });

        $this->assertSame([], $wrappedDates);
    }

    private function project(string $name): Project
    {
        return Project::query()->create([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => Customer::query()->create(['name' => $name])->id,
            'name' => $name,
            'city' => 'Zwolle',
            'status' => 'gepland',
            'planned_start_date' => '2026-09-21',
            'planned_end_date' => '2026-09-25',
        ]);
    }

    /**
     * @param  list<string>  $queries
     * @return list<string>
     */
    private function matching(array $queries, callable $filter): array
    {
        return array_values(array_filter($queries, $filter));
    }
}
