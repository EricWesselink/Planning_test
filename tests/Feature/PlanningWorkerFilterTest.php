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

class PlanningWorkerFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_lists_person_names_next_to_teams(): void
    {
        $user = User::factory()->create();
        $this->makeTeam('Team 1 Nick', ['Nick', 'Mahmoud']);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('name="who"', false)
            ->assertSee('label="Teams"', false)
            ->assertSee('label="Personen"', false)
            ->assertSee('Mahmoud · Team 1 Nick')
            ->assertSee('Nick · Team 1 Nick');
    }

    public function test_person_filter_shows_only_assignments_that_include_that_person(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam('Team 1 Nick', ['Nick', 'Mahmoud']);
        $other = Worker::query()->create([
            'name' => 'Team 2 Peter',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $nick = $people->firstWhere('name', 'Nick');
        $mahmoud = $people->firstWhere('name', 'Mahmoud');
        $nickProject = $this->makeProject('Laakse Tuinen', '260210001');
        $mahmoudProject = $this->makeProject('Het Klooster', '260210002');
        $otherProject = $this->makeProject('Zuidpark', '260210003');

        $nickAssignment = WorkerAssignment::query()->create([
            'worker_id' => $team->id,
            'project_id' => $nickProject->id,
            'work_item_id' => $nickProject->workItems()->first()->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
        ]);
        $nickAssignment->syncPresentCrew([$nick->id]);

        $mahmoudAssignment = WorkerAssignment::query()->create([
            'worker_id' => $team->id,
            'project_id' => $mahmoudProject->id,
            'work_item_id' => $mahmoudProject->workItems()->first()->id,
            'start_date' => '2026-09-09',
            'end_date' => '2026-09-09',
            'hours_per_day' => 8,
        ]);
        $mahmoudAssignment->syncPresentCrew([$mahmoud->id]);

        WorkerAssignment::query()->create([
            'worker_id' => $other->id,
            'project_id' => $otherProject->id,
            'work_item_id' => $otherProject->workItems()->first()->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'who' => 'member:'.$mahmoud->id,
            ]))
            ->assertOk()
            ->assertSee('data-project-id="'.$mahmoudProject->id.'"', false)
            ->assertDontSee('data-project-id="'.$nickProject->id.'"', false)
            ->assertDontSee('data-project-id="'.$otherProject->id.'"', false)
            ->assertSee('value="member:'.$mahmoud->id.'"', false);
    }

    public function test_person_filter_keeps_team_wide_assignments_without_named_crew(): void
    {
        $user = User::factory()->create();
        $team = $this->makeTeam('Team 1 Nick', ['Nick', 'Mahmoud']);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $mahmoud = $people->firstWhere('name', 'Mahmoud');
        $shared = $this->makeProject('Laakse Tuinen', '260210004');

        WorkerAssignment::query()->create([
            'worker_id' => $team->id,
            'project_id' => $shared->id,
            'work_item_id' => $shared->workItems()->first()->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'who' => 'member:'.$mahmoud->id,
            ]))
            ->assertOk()
            ->assertSee('data-project-id="'.$shared->id.'"', false);
    }

    public function test_team_filter_via_who_still_shows_that_team(): void
    {
        $user = User::factory()->create();
        $kees = Worker::query()->create([
            'name' => 'Kees',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $other = Worker::query()->create([
            'name' => 'Piet',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $keesProject = $this->makeProject('Laakse Tuinen', '260210005');
        $otherProject = $this->makeProject('Zuidpark', '260210006');

        WorkerAssignment::query()->create([
            'worker_id' => $kees->id,
            'project_id' => $keesProject->id,
            'work_item_id' => $keesProject->workItems()->first()->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $other->id,
            'project_id' => $otherProject->id,
            'work_item_id' => $otherProject->workItems()->first()->id,
            'start_date' => '2026-09-09',
            'end_date' => '2026-09-09',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'who' => 'worker:'.$kees->id,
            ]))
            ->assertOk()
            ->assertSee('data-project-id="'.$keesProject->id.'"', false)
            ->assertDontSee('data-project-id="'.$otherProject->id.'"', false);
    }

    public function test_legacy_worker_id_filter_still_shows_that_team(): void
    {
        $user = User::factory()->create();
        $kees = Worker::query()->create([
            'name' => 'Kees',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $other = Worker::query()->create([
            'name' => 'Piet',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        $keesProject = $this->makeProject('Laakse Tuinen', '260210007');
        $otherProject = $this->makeProject('Zuidpark', '260210008');

        WorkerAssignment::query()->create([
            'worker_id' => $kees->id,
            'project_id' => $keesProject->id,
            'work_item_id' => $keesProject->workItems()->first()->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $other->id,
            'project_id' => $otherProject->id,
            'work_item_id' => $otherProject->workItems()->first()->id,
            'start_date' => '2026-09-09',
            'end_date' => '2026-09-09',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->get(route('planning', [
                'week' => '2026-09-07',
                'worker_id' => $kees->id,
            ]))
            ->assertOk()
            ->assertSee('data-project-id="'.$keesProject->id.'"', false)
            ->assertDontSee('data-project-id="'.$otherProject->id.'"', false);
    }

    /**
     * @param  list<string>  $names
     */
    private function makeTeam(string $team, array $names): Worker
    {
        return Worker::query()->create([
            'name' => $team,
            'employment_type' => 'eigen',
            'people_count' => count($names),
            'crew_members' => array_map(fn (string $name): array => ['name' => $name, 'phone' => ''], $names),
            'active' => true,
        ]);
    }

    private function makeProject(string $name, string $number): Project
    {
        $customer = Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);

        return $project->fresh('workItems');
    }
}
