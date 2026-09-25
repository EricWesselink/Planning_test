<?php

namespace Tests\Feature;

use App\Models\CrewMember;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningProjectDayCrewTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_row_counts_each_person_once_per_day(): void
    {
        $user = User::factory()->create();
        $project = $this->project();
        $pvc = $this->workItem($project, 'PVC');
        $egaliseren = $this->workItem($project, 'Primen & Egaliseren');
        $harm = $this->worker('Harm Wiegers', ['Harm Wiegers']);
        $peter = $this->worker('Peter', ['Peter Korteschiel']);

        $this->assign($harm['worker'], $harm['crew_ids'], $project, $pvc, '2026-09-07', '2026-09-07');
        $this->assign($harm['worker'], $harm['crew_ids'], $project, $pvc, '2026-09-09', '2026-09-09');
        $this->assign($harm['worker'], $harm['crew_ids'], $project, $egaliseren, '2026-09-09', '2026-09-09');
        $this->assign($peter['worker'], $peter['crew_ids'], $project, $pvc, '2026-09-09', '2026-09-09');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('data-day-crew="1,0,2,0,0,0"', false)
            ->assertSee('title="1 vakman"', false)
            ->assertSee('title="2 vakmannen"', false)
            ->assertSee('images/vakman.svg', false)
            ->assertSee('class="day-worker-count" title="1 vakman"', false)
            ->assertSee('class="day-worker-count is-empty" title="Geen vakman"><span>–</span>', false)
            ->assertDontSee('👤', false)
            ->assertDontSee('👥', false);
    }

    public function test_project_row_counts_every_planned_team_member(): void
    {
        $user = User::factory()->create();
        $project = $this->project();
        $pvc = $this->workItem($project, 'PVC');
        $team = $this->worker('Team 2', ['Harm Wiegers', 'Peter Korteschiel', 'José da Costa']);

        $this->assign($team['worker'], $team['crew_ids'], $project, $pvc, '2026-09-12', '2026-09-12', true);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertSee('data-day-crew="0,0,0,0,0,3"', false)
            ->assertSee('title="3 vakmannen"', false)
            ->assertSee('images/vakman.svg', false)
            ->assertDontSee('👥', false)
            ->assertSee('class="day-worker-count is-empty" title="Geen vakman"><span>–</span>', false);
    }

    private function project(): Project
    {
        $customer = Customer::query()->create(['name' => 'Gezondheidscentrum']);

        return Project::query()->create([
            'project_number' => 'HP24201',
            'customer_id' => $customer->id,
            'name' => 'Gezondheidscentrum Laren',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);
    }

    private function workItem(Project $project, string $name): WorkItem
    {
        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'in_uitvoering',
        ]);
    }

    /**
     * @param  list<string>  $names
     * @return array{worker: Worker, crew_ids: list<int>}
     */
    private function worker(string $team, array $names): array
    {
        $worker = Worker::query()->create([
            'name' => $team,
            'employment_type' => 'eigen',
            'people_count' => count($names),
            'active' => true,
        ]);
        $ids = [];
        foreach ($names as $index => $name) {
            $ids[] = CrewMember::query()->create([
                'worker_id' => $worker->id,
                'name' => $name,
                'sort_order' => $index + 1,
            ])->id;
        }

        return ['worker' => $worker, 'crew_ids' => $ids];
    }

    /**
     * @param  list<int>  $crewIds
     */
    private function assign(Worker $worker, array $crewIds, Project $project, WorkItem $item, string $start, string $end, bool $saturday = false): void
    {
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => $start,
            'end_date' => $end,
            'hours_per_day' => 8,
            'people_count' => count($crewIds),
            'include_saturday' => $saturday,
        ]);
        $assignment->syncPresentCrew($crewIds);
    }
}
