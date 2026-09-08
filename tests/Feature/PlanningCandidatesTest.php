<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningCandidatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_page_exposes_the_candidates_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee(route('planning.candidates'), false);
    }

    public function test_candidates_filter_on_the_clicked_werkzaamheid(): void
    {
        $user = User::factory()->create();
        $this->makeWorker('Kees Jansen', 'Linoleum');
        $this->makeWorker('Nick Seine', 'PVC');
        $linoleum = $this->makeWorkItem('Linoleum');
        $this->makeWorkItem('PVC', $linoleum->project);

        $this->actingAs($user)
            ->getJson(route('planning.candidates', [
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '16:00',
            ]))
            ->assertOk()
            ->assertJsonPath('specialty.label', 'Linoleum')
            ->assertJsonFragment([
                'name' => 'Kees Jansen',
                'selectable' => true,
                'status_label' => 'Beschikbaar',
            ])
            ->assertJsonFragment([
                'name' => 'Nick Seine',
                'selectable' => false,
                'status_label' => 'Geen vakkennis: Linoleum',
            ]);
    }

    public function test_candidates_use_pvc_when_that_row_is_chosen(): void
    {
        $user = User::factory()->create();
        $this->makeWorker('Kees Jansen', 'Linoleum');
        $nick = $this->makeWorker('Nick Seine', 'PVC');
        $pvc = $this->makeWorkItem('PVC');

        $this->actingAs($user)
            ->getJson(route('planning.candidates', [
                'work_item_id' => $pvc->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '16:00',
            ]))
            ->assertOk()
            ->assertJsonPath('specialty.label', 'PVC')
            ->assertJsonFragment([
                'id' => $nick->id,
                'selectable' => true,
                'status_label' => 'Beschikbaar',
            ])
            ->assertJsonFragment([
                'name' => 'Kees Jansen',
                'status_label' => 'Geen vakkennis: PVC',
            ]);
    }

    public function test_candidates_mark_an_overlapping_person_as_busy(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'Linoleum');
        $item = $this->makeWorkItem('Linoleum');
        $assignment = new WorkerAssignment([
            'worker_id' => $peter->id,
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            '08:00:00',
            '12:00:00',
        );
        $assignment->save();

        $this->actingAs($user)
            ->getJson(route('planning.candidates', [
                'work_item_id' => $item->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '16:00',
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Peter',
                'selectable' => false,
                'status_label' => 'Bezet 08:00-12:00',
            ]);
    }

    public function test_candidates_ignore_the_assignment_being_edited(): void
    {
        $user = User::factory()->create();
        $peter = $this->makeWorker('Peter', 'Linoleum');
        $item = $this->makeWorkItem('Linoleum');
        $assignment = new WorkerAssignment([
            'worker_id' => $peter->id,
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'people_count' => 1,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-07'),
            Carbon::parse('2026-09-07'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();

        $this->actingAs($user)
            ->getJson(route('planning.candidates', [
                'work_item_id' => $item->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '16:00',
                'assignment_id' => $assignment->id,
            ]))
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Peter',
                'selectable' => true,
                'status_label' => 'Beschikbaar',
            ]);
    }

    public function test_unauthenticated_candidates_request_returns_401(): void
    {
        $item = $this->makeWorkItem('Linoleum');

        $this->getJson(route('planning.candidates', [
            'work_item_id' => $item->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
        ]))->assertUnauthorized();
    }

    public function test_forbids_uitvoerder_from_listing_candidates(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $item = $this->makeWorkItem('Linoleum');

        $this->actingAs($user)
            ->getJson(route('planning.candidates', [
                'work_item_id' => $item->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
            ]))
            ->assertForbidden();
    }

    private function makeWorker(string $name, string $specialty): Worker
    {
        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'specialty' => $specialty,
            'active' => true,
        ]);
    }

    private function makeWorkItem(string $name, ?Project $project = null): WorkItem
    {
        if ($project === null) {
            $customer = Customer::query()->create(['name' => 'Gemeente']);
            $project = Project::query()->create([
                'project_number' => '2602000'.fake()->unique()->numerify('##'),
                'customer_id' => $customer->id,
                'name' => 'Testwerk',
                'status' => 'in_uitvoering',
                'planned_start_date' => '2026-09-07',
                'planned_end_date' => '2026-09-12',
            ]);
        }

        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
    }
}
