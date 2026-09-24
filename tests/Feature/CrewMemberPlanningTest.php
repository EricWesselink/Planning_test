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

class CrewMemberPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_teammates_with_different_hours_can_share_a_monday(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Jansen Vloeren',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'people_count' => 3,
            'crew_members' => [
                ['name' => 'Piet', 'phone' => ''],
                ['name' => 'Kees', 'phone' => ''],
                ['name' => 'Jan', 'phone' => ''],
            ],
            'specialty' => 'Linoleum, PVC',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $projectA = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Project A',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-08',
        ]);
        $projectB = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $customer->id,
            'name' => 'Project B',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-08',
        ]);
        $workA = WorkItem::query()->create([
            'project_id' => $projectA->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
        $workB = WorkItem::query()->create([
            'project_id' => $projectB->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 80,
            'status' => 'in_uitvoering',
        ]);
        $people = $worker->crewPeople()->orderBy('sort_order')->get();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $projectA->id,
                'work_item_id' => $workA->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'crew_member_ids' => [$people[0]->id, $people[1]->id],
                'crew_hours' => [
                    $people[0]->id => 8,
                    $people[1]->id => 4,
                ],
                'hours' => 8,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $projectB->id,
                'work_item_id' => $workB->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'crew_member_ids' => [$people[2]->id],
                'hours' => 4,
                'slot' => 'afternoon',
            ])
            ->assertOk();

        $this->assertSame(3, WorkerAssignment::query()->count());

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Jansen Vloeren · Piet · 1 man', false)
            ->assertSee('Jansen Vloeren · Kees · 1 man', false)
            ->assertSee('Jansen Vloeren · Jan · 1 man', false)
            ->assertSee('8 geplande uren', false)
            ->assertSee('4 geplande uren', false)
            ->assertDontSee('meer personen ingepland dan het team')
            ->assertDontSee('staat overlappend');
    }
}
