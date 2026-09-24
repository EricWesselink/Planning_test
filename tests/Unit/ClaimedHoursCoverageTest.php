<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Project;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Support\AssignmentCoverage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClaimedHoursCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_claimed_hours_include_the_boundary_day_and_reuse_one_query(): void
    {
        $assignment = $this->assignment('2026-09-21', '2026-09-21');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $onTheDay = $assignment->claimedHoursOnDate(Carbon::parse('2026-09-21'));
        $loaded = count($queries);
        $again = $assignment->claimedHoursOnDate(Carbon::parse('2026-09-21'));

        $this->assertSame(8.0, $onTheDay);
        $this->assertSame(8.0, $again);
        $this->assertCount($loaded, $queries);
        $this->assertStringNotContainsString('date(', strtolower($queries[0]));
        $this->assertStringContainsString('start_date', strtolower($queries[0]));
        $this->assertStringContainsString('end_date', strtolower($queries[0]));
        $this->assertSame(0.0, $assignment->claimedHoursOnDate(Carbon::parse('2026-09-20')));
        $this->assertSame(0.0, $assignment->claimedHoursOnDate(Carbon::parse('2026-09-22')));
    }

    public function test_claimed_hours_use_the_already_loaded_week_assignments(): void
    {
        $first = $this->assignment('2026-09-21', '2026-09-21', '08:00:00', '12:00:00');
        $second = $this->assignment('2026-09-21', '2026-09-21', '10:00:00', '16:00:00', $first->worker, $first->project, $first->workItem);
        $fromQuery = $first->claimedHoursOnDate(Carbon::parse('2026-09-21'));

        $loaded = collect([$first->fresh(['crewMembers']), $second->fresh(['crewMembers'])]);
        app(AssignmentCoverage::class)->remember([(int) $first->worker_id], $loaded);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $fromMemory = $loaded->first()->claimedHoursOnDate(Carbon::parse('2026-09-21'));

        $this->assertSame($fromQuery, $fromMemory);
        $this->assertSame(0, $queries);
        $this->assertGreaterThan(0.0, $fromMemory);
    }

    private function assignment(
        string $start,
        string $end,
        string $startTime = '08:00:00',
        string $endTime = '16:00:00',
        ?Worker $worker = null,
        ?Project $project = null,
        ?WorkItem $item = null,
    ): WorkerAssignment {
        $worker ??= Worker::query()->create([
            'name' => 'Piet Dekker',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $project ??= Project::query()->create([
            'project_number' => 'P-'.fake()->unique()->numerify('######'),
            'customer_id' => Customer::query()->create(['name' => 'Opdrachtgever'])->id,
            'name' => 'Kantoor',
            'status' => 'gepland',
        ]);
        $item ??= WorkItem::query()->create([
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
        $assignment->applySchedule(Carbon::parse($start), Carbon::parse($end), $startTime, $endTime);
        $assignment->save();

        return $assignment;
    }
}
