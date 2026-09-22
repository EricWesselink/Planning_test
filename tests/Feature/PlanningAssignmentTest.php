<?php

namespace Tests\Feature;

use App\Models\CrewMember;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\ProjectLaborCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_an_assignment_to_another_work_item_on_the_same_project(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-09',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $assignment->refresh();
        $this->assertSame($coating->id, $assignment->work_item_id);
        $this->assertSame('2026-09-08', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-09', $assignment->end_date->toDateString());
    }

    public function test_one_assignment_can_cover_several_works_without_doubling_hours(): void
    {
        $user = User::factory()->create();
        [$seed, $linoleum, $coating] = $this->makeAssignmentOnTwoWorkItems();
        $workerId = (int) $seed->worker_id;
        $projectId = (int) $seed->project_id;
        $seed->delete();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $workerId,
                'project_id' => $projectId,
                'work_item_id' => $linoleum->id,
                'work_item_ids' => [$linoleum->id, $coating->id],
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-09',
                'people_count' => 1,
                'hours' => 8,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->where('worker_id', $workerId)->first();
        $this->assertNotNull($assignment);
        $this->assertSame($linoleum->id, $assignment->work_item_id);
        $this->assertSame(16.0, $assignment->plannedHoursValue());
        $this->assertTrue($assignment->coversWorkIds([$linoleum->id, $coating->id]));
        $this->assertDatabaseHas('work_item_worker_assignment', [
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $linoleum->id,
        ]);
        $this->assertDatabaseHas('work_item_worker_assignment', [
            'worker_assignment_id' => $assignment->id,
            'work_item_id' => $coating->id,
        ]);

        $labor = app(ProjectLaborCalculator::class)->for($linoleum->project->fresh());
        $this->assertSame(16.0, $labor['planned_hours']);
        $this->assertSame(16.0, $labor['items_by_id'][$linoleum->id]['planned_hours']);
        $this->assertSame(0.0, $labor['items_by_id'][$coating->id]['planned_hours']);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('data-work-item-ids="'.$linoleum->id.','.$coating->id.'"', false);
    }

    public function test_shifts_one_planned_activity_and_leaves_the_other_on_the_original_days(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Willem',
            'employment_type' => 'zzp',
            'company' => 'Willem Vloeren',
            'specialty' => 'PVC',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '11P241267',
            'customer_id' => $customer->id,
            'name' => 'Grote vloeren',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-28',
            'planned_end_date' => '2026-09-30',
        ]);
        $primer = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 120,
            'planned_start_date' => '2026-09-28',
            'planned_end_date' => '2026-09-30',
            'status' => 'in_uitvoering',
        ]);
        $pvc = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 80,
            'planned_start_date' => '2026-09-28',
            'planned_end_date' => '2026-09-30',
            'status' => 'in_uitvoering',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $primer->id,
            'start_date' => '2026-09-28',
            'end_date' => '2026-09-30',
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);
        $assignment->syncLinkedWorkItems([$primer->id, $pvc->id]);

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-28']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-work-item-id="'.$primer->id.'"', $html);
        $this->assertStringContainsString('data-work-item-id="'.$pvc->id.'"', $html);
        $this->assertSame(2, substr_count($html, 'data-shift-id="'.$assignment->id.'"'));

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $worker->id,
                'work_item_id' => $pvc->id,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-02',
                'start_time' => '08:00',
                'end_time' => '16:00',
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame('2026-09-28', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-30', $assignment->end_date->toDateString());
        $this->assertTrue($assignment->coversWorkIds([$primer->id]));
        $this->assertFalse($assignment->coversWorkIds([$pvc->id]));

        $shifted = WorkerAssignment::query()
            ->where('worker_id', $worker->id)
            ->whereKeyNot($assignment->id)
            ->first();
        $this->assertNotNull($shifted);
        $this->assertSame($pvc->id, (int) $shifted->work_item_id);
        $this->assertSame('2026-10-01', $shifted->start_date->toDateString());
        $this->assertSame('2026-10-02', $shifted->end_date->toDateString());
        $this->assertTrue($shifted->coversWorkIds([$pvc->id]));
        $this->assertFalse($shifted->coversWorkIds([$primer->id]));
        $this->assertSame(2, WorkerAssignment::query()->count());
    }

    public function test_saving_both_activities_from_the_dialog_still_moves_the_whole_visit(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Willem',
            'employment_type' => 'zzp',
            'company' => 'Willem Vloeren',
            'specialty' => 'PVC',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '11P241267',
            'customer_id' => $customer->id,
            'name' => 'Grote vloeren',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-28',
            'planned_end_date' => '2026-09-30',
        ]);
        $primer = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Primen & Egaliseren',
            'unit' => 'm2',
            'ordered_quantity' => 120,
            'status' => 'in_uitvoering',
        ]);
        $pvc = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 80,
            'status' => 'in_uitvoering',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $primer->id,
            'start_date' => '2026-09-28',
            'end_date' => '2026-09-30',
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);
        $assignment->syncLinkedWorkItems([$primer->id, $pvc->id]);

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $worker->id,
                'work_item_id' => $pvc->id,
                'work_item_ids' => [$primer->id, $pvc->id],
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-02',
                'hours' => 8,
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame('2026-10-01', $assignment->start_date->toDateString());
        $this->assertSame('2026-10-02', $assignment->end_date->toDateString());
        $this->assertTrue($assignment->coversWorkIds([$primer->id, $pvc->id]));
        $this->assertSame(1, WorkerAssignment::query()->count());
    }

    public function test_moves_an_assignment_to_a_work_item_on_another_project_keeping_the_same_id(): void
    {
        $user = User::factory()->create();
        [$assignment, $linoleum] = $this->makeAssignmentOnTwoWorkItems();
        $other = $this->makeWorkItem('School Zwolle', '260200091', 'Linoleum');

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $other->project_id,
                'work_item_id' => $other->id,
                'start_date' => '2026-09-09',
                'end_date' => '2026-09-09',
                'hours' => 8,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $assignment->refresh();
        $this->assertSame($other->project_id, $assignment->project_id);
        $this->assertSame($other->id, $assignment->work_item_id);
        $this->assertSame('2026-09-09', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-09', $assignment->end_date->toDateString());
        $this->assertSame(1, WorkerAssignment::query()->count());

        $from = app(ProjectLaborCalculator::class)->for($linoleum->project->fresh());
        $to = app(ProjectLaborCalculator::class)->for($other->project->fresh());
        $this->assertSame(0.0, $from['planned_hours']);
        $this->assertSame(8.0, $to['planned_hours']);
        $this->assertSame(0.0, $from['items_by_id'][$linoleum->id]['planned_hours']);
        $this->assertSame(8.0, $to['items_by_id'][$other->id]['planned_hours']);
    }

    public function test_moves_only_the_checked_teammate_and_leaves_the_other_on_the_original_work(): void
    {
        $user = User::factory()->create();
        [$assignment, $eric, $harm, $workA, $workB] = $this->makeCrewAssignmentAcrossProjects();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $workB->project_id,
                'work_item_id' => $workB->id,
                'start_date' => '2026-09-09',
                'end_date' => '2026-09-09',
                'crew_member_ids' => [$eric->id],
                'hours' => 8,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $assignment->refresh()->load('crewMembers');
        $this->assertSame($workB->project_id, $assignment->project_id);
        $this->assertSame($workB->id, $assignment->work_item_id);
        $this->assertSame('2026-09-09', $assignment->start_date->toDateString());
        $this->assertSame('2026-09-09', $assignment->end_date->toDateString());
        $this->assertSame([$eric->id], $assignment->crewMembers->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $staying = WorkerAssignment::query()
            ->with('crewMembers')
            ->where('worker_id', $assignment->worker_id)
            ->where('id', '!=', $assignment->id)
            ->first();
        $this->assertNotNull($staying);
        $this->assertSame($workA->project_id, $staying->project_id);
        $this->assertSame($workA->id, $staying->work_item_id);
        $this->assertSame('2026-09-09', $staying->start_date->toDateString());
        $this->assertSame('2026-09-10', $staying->end_date->toDateString());
        $this->assertSame([$harm->id], $staying->crewMembers->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(2, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());

        $from = app(ProjectLaborCalculator::class)->for($workA->project->fresh());
        $to = app(ProjectLaborCalculator::class)->for($workB->project->fresh());
        $this->assertSame(16.0, $from['planned_hours']);
        $this->assertSame(8.0, $to['planned_hours']);
        $this->assertSame(16.0, $from['items_by_id'][$workA->id]['planned_hours']);
        $this->assertSame(8.0, $to['items_by_id'][$workB->id]['planned_hours']);
    }

    public function test_unchecking_a_teammate_without_changing_work_removes_them(): void
    {
        $user = User::factory()->create();
        [$assignment, $eric, $harm, $workA] = $this->makeCrewAssignmentAcrossProjects();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $workA->project_id,
                'work_item_id' => $workA->id,
                'start_date' => '2026-09-09',
                'end_date' => '2026-09-10',
                'crew_member_ids' => [$eric->id],
                'hours' => 8,
            ])
            ->assertOk();

        $assignment->refresh()->load('crewMembers');
        $this->assertSame([$eric->id], $assignment->crewMembers->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(1, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
        $this->assertFalse($assignment->crewMembers->contains('id', $harm->id));
    }

    public function test_rejects_moving_an_assignment_when_nobody_is_checked(): void
    {
        $user = User::factory()->create();
        [$assignment, , , $workA, $workB] = $this->makeCrewAssignmentAcrossProjects();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $workB->project_id,
                'work_item_id' => $workB->id,
                'start_date' => '2026-09-09',
                'end_date' => '2026-09-09',
                'crew_member_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Vink aan wie er naar dit werk gaat.');

        $assignment->refresh();
        $this->assertSame($workA->id, $assignment->work_item_id);
        $this->assertSame(1, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
    }

    public function test_returns_409_when_moving_onto_an_existing_overlapping_assignment(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeAssignmentOnTwoWorkItems();
        $other = $this->makeWorkItem('School Zwolle', '260200091', 'Linoleum');
        WorkerAssignment::query()->create([
            'worker_id' => $assignment->worker_id,
            'project_id' => $other->project_id,
            'work_item_id' => $other->id,
            'start_date' => '2026-09-09',
            'end_date' => '2026-09-09',
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $other->project_id,
                'work_item_id' => $other->id,
                'start_date' => '2026-09-09',
                'end_date' => '2026-09-09',
                'hours' => 8,
            ])
            ->assertConflict()
            ->assertJsonPath('conflict', true);

        $assignment->refresh();
        $this->assertNotSame($other->id, $assignment->work_item_id);
        $this->assertSame(2, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
    }

    public function test_rejects_moving_an_assignment_when_the_worker_is_unavailable(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeAssignmentOnTwoWorkItems();
        $assignment->worker->update(['unavailable' => true]);
        $other = $this->makeWorkItem('School Zwolle', '260200091', 'Linoleum');

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $other->project_id,
                'work_item_id' => $other->id,
                'start_date' => '2026-09-09',
                'end_date' => '2026-09-09',
                'hours' => 8,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kees Jansen is niet beschikbaar.');

        $assignment->refresh();
        $this->assertNotSame($other->id, $assignment->work_item_id);
    }

    public function test_planning_page_lists_work_items_from_every_active_project_for_the_edit_dialog(): void
    {
        $user = User::factory()->create();
        [, $linoleum] = $this->makeAssignmentOnTwoWorkItems();
        $other = $this->makeWorkItem('School Zwolle', '260200091', 'Linoleum');

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"id":'.$linoleum->id, $html);
        $this->assertStringContainsString('"id":'.$other->id, $html);
        $this->assertStringContainsString('"project":"Laakse Tuinen Amersfoort"', $html);
        $this->assertStringContainsString('"project":"School Zwolle"', $html);
        $this->assertStringContainsString('Niet aangevinkt blijft op het huidige werk.', $html);
        $this->assertStringContainsString('id="plan-crew-heading"', $html);
    }

    public function test_unauthenticated_assignment_update_returns_401(): void
    {
        [$assignment, $linoleum, $coating] = $this->makeAssignmentOnTwoWorkItems();

        $this->patchJson(route('planning.assignments.update', $assignment), [
            'worker_id' => $assignment->worker_id,
            'work_item_id' => $coating->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-09',
        ])->assertUnauthorized();

        $this->assertSame($linoleum->id, $assignment->fresh()->work_item_id);
    }

    public function test_forbids_uitvoerder_from_moving_an_assignment(): void
    {
        $user = User::factory()->uitvoerder()->create();
        [$assignment, $linoleum, $coating] = $this->makeAssignmentOnTwoWorkItems();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-09',
            ])
            ->assertForbidden();

        $this->assertSame($linoleum->id, $assignment->fresh()->work_item_id);
    }

    public function test_repeating_the_same_store_request_does_not_create_a_second_assignment(): void
    {
        $user = User::factory()->create();
        [$assignment, $linoleum] = $this->makeAssignmentOnTwoWorkItems();

        $payload = [
            'worker_id' => $assignment->worker_id,
            'project_id' => $assignment->project_id,
            'work_item_id' => $linoleum->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
            'people_count' => 1,
            'hours' => 8,
            'start_time' => '08:00',
            'end_time' => '16:00',
            'confirm_conflict' => true,
        ];

        $this->actingAs($user)->postJson(route('planning.assignments.store'), $payload)->assertOk();
        $this->actingAs($user)->postJson(route('planning.assignments.store'), $payload)->assertOk();

        $this->assertSame(
            1,
            WorkerAssignment::query()
                ->where('worker_id', $assignment->worker_id)
                ->where('work_item_id', $linoleum->id)
                ->whereDate('start_date', '2026-09-10')
                ->where('start_time', '08:00:00')
                ->where('end_time', '16:00:00')
                ->count(),
        );
    }

    public function test_allows_one_person_per_onderdeel_when_the_team_has_two_people(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems(people: 2);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $assignment->project_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(2, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $assignment->worker_id,
            'work_item_id' => $coating->id,
            'people_count' => 1,
        ]);
    }

    public function test_returns_409_when_planned_people_exceed_the_team_on_another_work(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeAssignmentOnTwoWorkItems(people: 2);
        $assignment->forceFill(['people_count' => 2])->save();
        $other = $this->makeWorkItem('School Zwolle', '260200091', 'Linoleum');

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $other->project_id,
                'work_item_id' => $other->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
            ])
            ->assertConflict()
            ->assertJson([
                'ok' => false,
                'conflict' => true,
            ])
            ->assertJsonFragment(['message' => 'ZZP Jansen Vloeren heeft die dag 3 van 2 personen ingepland op Laakse Tuinen Amersfoort. Toch doorgaan?']);

        $this->assertSame(1, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
    }

    public function test_plans_a_single_person_on_two_onderdelen_of_the_same_work(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems(people: 1);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $assignment->worker_id,
                'project_id' => $assignment->project_id,
                'work_item_id' => $coating->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(2, WorkerAssignment::query()->where('worker_id', $assignment->worker_id)->count());
    }

    public function test_planning_board_does_not_warn_when_a_two_person_team_is_split(): void
    {
        $user = User::factory()->create();
        [$assignment, , $coating] = $this->makeAssignmentOnTwoWorkItems(people: 2);
        WorkerAssignment::query()->create([
            'worker_id' => $assignment->worker_id,
            'project_id' => $assignment->project_id,
            'work_item_id' => $coating->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertDontSee('meer personen ingepland dan het team')
            ->assertDontSee('person-bar double', false);
    }

    public function test_planning_board_warns_when_planned_people_exceed_the_team_on_another_work(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeAssignmentOnTwoWorkItems(people: 2);
        $assignment->forceFill(['people_count' => 2])->save();
        $other = $this->makeWorkItem('School Zwolle', '260200091', 'Linoleum');
        WorkerAssignment::query()->create([
            'worker_id' => $assignment->worker_id,
            'project_id' => $other->project_id,
            'work_item_id' => $other->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'hours_per_day' => 8,
            'people_count' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Dubbele planning')
            ->assertSee('ZZP Jansen Vloeren heeft meer personen ingepland dan het team.')
            ->assertSee('person-bar double', false)
            ->assertSee('planning-warnings', false)
            ->assertSee('Bekijk dubbele planning')
            ->assertSee('double_worker='.$assignment->worker_id, false);
    }

    public function test_stores_voorman_and_werkbon_holder_from_the_selected_crew(): void
    {
        $user = User::factory()->create();
        [$assignment, $eric, $harm] = $this->makeCrewAssignmentAcrossProjects();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Voorman')
            ->assertSee('Werkbon bij')
            ->assertSee('Vakmannen');

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'start_date' => '2026-09-09',
                'end_date' => '2026-09-10',
                'crew_member_ids' => [$eric->id, $harm->id],
                'foreman_crew_member_id' => $eric->id,
                'work_ticket_crew_member_id' => $harm->id,
                'hours' => 8,
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame($eric->id, (int) $assignment->foreman_crew_member_id);
        $this->assertSame($harm->id, (int) $assignment->work_ticket_crew_member_id);
    }

    public function test_rejects_a_werkbon_holder_who_is_not_on_the_assignment(): void
    {
        $user = User::factory()->create();
        [$assignment, $eric, $harm] = $this->makeCrewAssignmentAcrossProjects();

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'start_date' => '2026-09-09',
                'end_date' => '2026-09-10',
                'crew_member_ids' => [$eric->id],
                'work_ticket_crew_member_id' => $harm->id,
                'hours' => 8,
            ])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Werkbon bij moet een vakman van deze inzet zijn.']);

        $assignment->refresh();
        $this->assertNull($assignment->work_ticket_crew_member_id);
    }

    public function test_does_not_store_voorman_or_werkbon_holder_on_zzp_assignments(): void
    {
        $user = User::factory()->create();
        [$assignment] = $this->makeAssignmentOnTwoWorkItems();
        $worker = $assignment->worker;
        $worker->forceFill([
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Kees', 'phone' => ''],
                ['name' => 'Jan', 'phone' => ''],
            ],
        ])->save();
        $people = $worker->fresh()->crewPeople()->orderBy('sort_order')->get();
        $assignment->syncPresentCrew([$people[0]->id, $people[1]->id]);

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $assignment->worker_id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-09',
                'crew_member_ids' => [$people[0]->id, $people[1]->id],
                'foreman_crew_member_id' => $people[0]->id,
                'work_ticket_crew_member_id' => $people[1]->id,
                'hours' => 8,
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertNull($assignment->foreman_crew_member_id);
        $this->assertNull($assignment->work_ticket_crew_member_id);
    }

    /**
     * @return array{0: WorkerAssignment, 1: WorkItem, 2: WorkItem}
     */
    private function makeAssignmentOnTwoWorkItems(int $people = 1): array
    {
        $worker = Worker::query()->create([
            'name' => 'Kees Jansen',
            'employment_type' => 'zzp',
            'company' => 'Jansen Vloeren',
            'people_count' => $people,
            'specialty' => 'Linoleum, Coating',
            'active' => true,
        ]);
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
        $linoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 3168,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        $coating = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Coating',
            'unit' => 'm2',
            'ordered_quantity' => 400,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $linoleum->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-09',
            'hours_per_day' => 8,
        ]);

        return [$assignment, $linoleum, $coating];
    }

    /**
     * @return array{0: WorkerAssignment, 1: CrewMember, 2: CrewMember, 3: WorkItem, 4: WorkItem}
     */
    private function makeCrewAssignmentAcrossProjects(): array
    {
        $worker = Worker::query()->create([
            'name' => 'Wesselink',
            'employment_type' => 'eigen',
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Eric Wesselink', 'phone' => ''],
                ['name' => 'Harm Wesselink', 'phone' => ''],
            ],
            'specialty' => 'Linoleum, PVC',
            'active' => true,
        ]);
        $people = $worker->crewPeople()->orderBy('sort_order')->get();
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $projectA = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Project A',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-09',
            'planned_end_date' => '2026-09-10',
        ]);
        $projectB = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $customer->id,
            'name' => 'Project B',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-09',
            'planned_end_date' => '2026-09-10',
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
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $projectA->id,
            'work_item_id' => $workA->id,
            'start_date' => '2026-09-09',
            'end_date' => '2026-09-10',
            'hours_per_day' => 8,
            'people_count' => 2,
        ]);
        $assignment->applySchedule(
            Carbon::parse('2026-09-09'),
            Carbon::parse('2026-09-10'),
            '08:00:00',
            '16:00:00',
        );
        $assignment->save();
        $assignment->syncPresentCrew([$people[0]->id, $people[1]->id]);

        return [$assignment->fresh(['crewMembers']), $people[0], $people[1], $workA, $workB];
    }

    private function makeWorkItem(string $projectName, string $projectNumber, string $workName): WorkItem
    {
        $customer = Customer::query()->create(['name' => 'Schoolbestuur']);
        $project = Project::query()->create([
            'project_number' => $projectNumber,
            'customer_id' => $customer->id,
            'name' => $projectName,
            'status' => 'gepland',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);

        return WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $workName,
            'unit' => 'm2',
            'ordered_quantity' => 200,
            'status' => 'gepland',
        ]);
    }
}
