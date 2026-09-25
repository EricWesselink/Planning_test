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

class DayBudgetPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_activity_keeps_an_eight_hour_day(): void
    {
        [$user, $worker, $project, $primen] = $this->scene();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), $this->payload($worker, $project, [$primen->id], [8]))
            ->assertOk();

        $assignment = WorkerAssignment::query()->firstOrFail();
        $this->assertSame(8.0, $assignment->hoursOnDate($assignment->start_date));
        $this->assertSame(1, WorkerAssignment::query()->count());
    }

    public function test_two_activities_start_as_an_even_split_of_one_day(): void
    {
        [$user, $worker, $project, $primen, $linoleum] = $this->scene();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), $this->payload($worker, $project, [$primen->id, $linoleum->id]))
            ->assertOk();

        $assignment = WorkerAssignment::query()->with('workItems')->firstOrFail();
        $this->assertSame(1, WorkerAssignment::query()->count());
        $this->assertEquals(4.0, $assignment->hoursForWorkItem($primen->id));
        $this->assertEquals(4.0, $assignment->hoursForWorkItem($linoleum->id));
        $this->assertSame(8.0, $assignment->hoursOnDate($assignment->start_date));
    }

    public function test_manual_split_of_two_and_six_is_one_eight_hour_day(): void
    {
        [$user, $worker, $project, $primen, $linoleum] = $this->scene();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), $this->payload($worker, $project, [$primen->id, $linoleum->id], [
                $primen->id => 2,
                $linoleum->id => 6,
            ]))
            ->assertOk();

        $assignment = WorkerAssignment::query()->with('workItems')->firstOrFail();
        $this->assertSame(8.0, $assignment->plannedHoursValue());
        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->getContent();
        preg_match_all('/data-label-full="([^"]*)"/', $html, $labels);
        $this->assertSame(['Eric · 2u', 'Eric · 6u'], $labels[1]);
    }

    public function test_manual_split_of_one_and_seven_stays_within_the_day(): void
    {
        [$user, $worker, $project, $primen, $linoleum] = $this->scene();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), $this->payload($worker, $project, [$primen->id, $linoleum->id], [
                $primen->id => 1,
                $linoleum->id => 7,
            ]))
            ->assertOk();

        $this->assertSame(8.0, WorkerAssignment::query()->firstOrFail()->plannedHoursValue());
    }

    public function test_a_partial_day_of_six_hours_is_allowed(): void
    {
        [$user, $worker, $project, $primen, $linoleum] = $this->scene();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), $this->payload($worker, $project, [$primen->id, $linoleum->id], [
                $primen->id => 2,
                $linoleum->id => 4,
            ]))
            ->assertOk();

        $this->assertSame(6.0, WorkerAssignment::query()->firstOrFail()->hoursOnDate(now()->parse('2026-09-07')));
    }

    public function test_ten_hours_on_an_eight_hour_day_is_rejected(): void
    {
        [$user, $worker, $project, $primen, $linoleum] = $this->scene();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), $this->payload($worker, $project, [$primen->id, $linoleum->id], [
                $primen->id => 4,
                $linoleum->id => 6,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Eric is voor 10 uur ingepland terwijl 8 uur beschikbaar is.');

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_three_and_five_hours_on_two_projects_stay_within_the_day(): void
    {
        [$user, $worker, $project, $primen] = $this->scene();
        $other = $this->otherProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                ...$this->payload($worker, $project, [$primen->id]),
                'hours' => 3,
                'start_time' => '08:00',
                'end_time' => '11:00',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $other->id,
                'work_item_id' => $other->workItems()->firstOrFail()->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
                'hours' => 5,
                'start_time' => '11:00',
                'end_time' => '16:00',
            ])
            ->assertOk();

        $this->assertSame(8.0, round((float) WorkerAssignment::query()->get()->sum(fn (WorkerAssignment $row): float => $row->hoursOnDate($row->start_date)), 2));
    }

    public function test_six_and_five_hours_on_two_projects_are_blocked(): void
    {
        [$user, $worker, $project, $primen] = $this->scene();
        $other = $this->otherProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                ...$this->payload($worker, $project, [$primen->id]),
                'hours' => 6,
                'start_time' => '08:00',
                'end_time' => '14:00',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $other->id,
                'work_item_id' => $other->workItems()->firstOrFail()->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
                'hours' => 5,
                'start_time' => '11:00',
                'end_time' => '16:00',
            ])
            ->assertStatus(409)
            ->assertSee('11 uur', false);
    }

    public function test_resizing_one_slice_moves_the_adjacent_slice_of_the_same_day(): void
    {
        [$user, $worker, $project, $primen, $linoleum] = $this->scene();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), $this->payload($worker, $project, [$primen->id, $linoleum->id]))
            ->assertOk();

        $assignment = WorkerAssignment::query()->firstOrFail();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $worker->id,
                'work_item_id' => $primen->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '14:00',
                'people_count' => 1,
                'work_hours' => [
                    $primen->id => 6,
                    $linoleum->id => 2,
                ],
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame(1, WorkerAssignment::query()->count());
        $this->assertEquals(6.0, $assignment->hoursForWorkItem($primen->id));
        $this->assertEquals(2.0, $assignment->hoursForWorkItem($linoleum->id));
        $this->assertSame(8.0, $assignment->hoursOnDate($assignment->start_date));

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->getContent();
        preg_match_all('/data-label-full="([^"]*)"/', $html, $labels);
        $this->assertSame(['Eric · 6u', 'Eric · 2u'], $labels[1]);
        $this->assertStringContainsString('data-end-time="14:00"', $html);
        $this->assertStringContainsString('data-start-time="14:00"', $html);

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $worker->id,
                'work_item_id' => $primen->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '11:00',
                'people_count' => 1,
                'work_hours' => [
                    $primen->id => 3,
                    $linoleum->id => 5,
                ],
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertEquals(3.0, $assignment->hoursForWorkItem($primen->id));
        $this->assertEquals(5.0, $assignment->hoursForWorkItem($linoleum->id));
        $this->assertSame(8.0, $assignment->hoursOnDate($assignment->start_date));
    }

    public function test_browser_resize_of_two_overlapping_visits_moves_the_boundary(): void
    {
        [$user, $worker, $project, $primen, $linoleum] = $this->scene();
        $primenVisit = $this->clockVisit($worker, $project, $primen, '08:00', '16:00');
        $linoleumVisit = $this->clockVisit($worker, $project, $linoleum, '08:00', '16:00');

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $primenVisit), [
                'worker_id' => $worker->id,
                'work_item_id' => $primen->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'start_time' => '08:00',
                'end_time' => '14:00',
                'people_count' => 1,
                'linked_assignment' => [
                    'id' => $linoleumVisit->id,
                    'work_item_id' => $linoleum->id,
                    'start_time' => '14:00',
                    'end_time' => '16:00',
                ],
            ])
            ->assertOk();

        $primenVisit->refresh();
        $linoleumVisit->refresh();
        $this->assertSame('08:00', substr((string) $primenVisit->start_time, 0, 5));
        $this->assertSame('14:00', substr((string) $primenVisit->end_time, 0, 5));
        $this->assertSame('14:00', substr((string) $linoleumVisit->start_time, 0, 5));
        $this->assertSame('16:00', substr((string) $linoleumVisit->end_time, 0, 5));
        $this->assertSame(8.0, round($primenVisit->hoursOnDate($primenVisit->start_date) + $linoleumVisit->hoursOnDate($linoleumVisit->start_date), 2));

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'project_id' => $project->id]))
            ->assertOk()
            ->assertDontSee('staat op meerdere werken')
            ->getContent();
        $this->assertStringContainsString('data-end-time="14:00"', $html);
        $this->assertStringContainsString('data-start-time="14:00"', $html);
    }

    /**
     * @param  list<int>  $workItemIds
     * @param  array<int, float>|list<float>  $hours
     * @return array<string, mixed>
     */
    private function payload(Worker $worker, Project $project, array $workItemIds, array $hours = []): array
    {
        $map = [];
        if ($hours !== [] && array_is_list($hours)) {
            $map[$workItemIds[0]] = $hours[0];
        } else {
            $map = $hours;
        }

        return [
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $workItemIds[0],
            'work_item_ids' => $workItemIds,
            'work_hours' => $map,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
            'people_count' => 1,
            'hours' => 8,
            'start_time' => '08:00',
            'end_time' => '16:00',
        ];
    }

    /**
     * @return array{0: User, 1: Worker, 2: Project, 3: WorkItem, 4: WorkItem}
     */
    private function scene(): array
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Eric',
            'employment_type' => 'eigen',
            'people_count' => 1,
            'default_hours_per_day' => 8,
            'specialty' => 'Primen & egaliseren, Linoleum, Tapijt',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen',
            'status' => 'in_uitvoering',
        ]);
        $primen = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);
        $linoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'status' => 'in_uitvoering',
        ]);

        return [$user, $worker, $project, $primen, $linoleum];
    }

    private function clockVisit(Worker $worker, Project $project, WorkItem $item, string $start, string $end): WorkerAssignment
    {
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $item->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
            'start_time' => $start,
            'end_time' => $end,
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);
        $assignment->syncLinkedWorkItems([$item->id]);

        return $assignment;
    }

    private function otherProject(): Project
    {
        $customer = Customer::query()->create(['name' => 'Andere klant']);
        $project = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $customer->id,
            'name' => 'Ander project',
            'status' => 'in_uitvoering',
        ]);
        WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Tapijt',
            'unit' => 'm2',
            'ordered_quantity' => 40,
            'status' => 'in_uitvoering',
        ]);

        return $project;
    }
}
