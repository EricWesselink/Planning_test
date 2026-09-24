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

class PlanningBarLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_bar_shows_first_name_and_last_initial(): void
    {
        $user = User::factory()->create();
        $assignment = $this->assignment('Team 2 Peter', [
            'Peter Korteschiel',
            'José da Costa',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $assignment->project_id]))
            ->assertOk()
            ->assertSee('data-label-full="T2 · Peter K. · José D. · 2 man"', false)
            ->assertSee('data-label-short="T2 · 2 man"', false)
            ->assertSee('Team 2', false)
            ->assertSee('Peter Korteschiel', false)
            ->assertSee('José da Costa', false)
            ->assertSee('2 vakmensen', false)
            ->assertSee('geplande uren', false)
            ->assertDontSee('Peter Korteschiel · José', false);
    }

    public function test_planning_bar_uses_the_second_word_as_the_last_name_initial(): void
    {
        $user = User::factory()->create();
        $assignment = $this->assignment('Team 1 Nick', [
            'Nick Seine',
            'Mahmoud Khairallah Sulaiman',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $assignment->project_id]))
            ->assertOk()
            ->assertSee('data-label-full="T1 · Nick S. · Mahmoud K. · 2 man"', false)
            ->assertSee('Nick Seine', false)
            ->assertSee('Mahmoud Khairallah Sulaiman', false);
    }

    public function test_one_person_bar_keeps_the_name_and_the_count(): void
    {
        $user = User::factory()->create();
        $assignment = $this->assignment('Team 2', ['José da Costa']);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $assignment->project_id]))
            ->assertOk()
            ->assertSee('data-label-full="T2 · José D. · 1 man"', false)
            ->assertSee('data-label-short="T2 · 1 man"', false)
            ->assertSee('1 vakman', false);
    }

    public function test_duplicate_crew_names_are_counted_once(): void
    {
        $user = User::factory()->create();
        $assignment = $this->assignment('Team 3', [
            'Peter Korteschiel',
            'Peter Korteschiel',
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $assignment->project_id]))
            ->assertOk()
            ->assertSee('data-label-full="T3 · Peter K. · 1 man"', false)
            ->assertSee('1 vakman', false)
            ->assertDontSee('2 man', false);
    }

    /**
     * @param  list<string>  $names
     */
    private function assignment(string $team, array $names): WorkerAssignment
    {
        $worker = Worker::query()->create([
            'name' => $team,
            'employment_type' => 'eigen',
            'people_count' => count($names),
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Griftland']);
        $project = Project::query()->create([
            'project_number' => 'TF29047',
            'customer_id' => $customer->id,
            'name' => 'Griftland college',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-11',
        ]);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 10,
            'status' => 'in_uitvoering',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
            'hours_per_day' => 8,
            'people_count' => count($names),
        ]);
        $ids = [];
        foreach ($names as $index => $name) {
            $ids[] = CrewMember::query()->create([
                'worker_id' => $worker->id,
                'name' => $name,
                'sort_order' => $index,
            ])->id;
        }
        $assignment->syncPresentCrew($ids);

        return $assignment;
    }
}
