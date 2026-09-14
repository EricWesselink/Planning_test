<?php

namespace Tests\Feature;

use App\Enums\ProjectKind;
use App\Models\CrewMember;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningFitAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_scheduling_someone_without_vakkennis_for_the_work(): void
    {
        $user = User::factory()->create();
        $nick = $this->makeWorker('Nick Seine', 'PVC');
        [$project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $nick->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nick Seine heeft geen vakkennis voor Linoleum.');

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_schedules_someone_with_vakkennis_for_the_clicked_work(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeWorker('Kees Jansen', 'Linoleum');
        [$project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $kees->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $kees->id,
            'work_item_id' => $linoleum->id,
        ]);
    }

    public function test_schedules_winkelwerk_without_flooring_vakkennis(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeWorker('Kees Jansen', 'Linoleum');
        $customer = Customer::query()->create(['name' => 'Jansen']);
        $project = Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $customer->id,
            'name' => 'Jansen - Hengelo',
            'kind' => ProjectKind::Winkel,
            'status' => 'gepland',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);
        $screens = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Screens',
            'unit' => 'stuks',
            'ordered_quantity' => 4,
            'status' => 'gepland',
        ]);

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $kees->id,
                'project_id' => $project->id,
                'work_item_id' => $screens->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'people_count' => 1,
            ])
            ->assertOk();

        $this->assertDatabaseHas('worker_assignments', [
            'worker_id' => $kees->id,
            'work_item_id' => $screens->id,
        ]);
    }

    public function test_schedules_only_the_suitable_teammate(): void
    {
        $user = User::factory()->create();
        $team = $this->makeWorker('Team Jansen', 'Linoleum', ['Piet', 'Kees', 'Jan']);
        $people = $team->crewPeople()->orderBy('sort_order')->get();
        $jan = $people->firstWhere('name', 'Jan');
        $this->assertInstanceOf(CrewMember::class, $jan);
        $jan->forceFill(['specialty' => 'PVC'])->save();
        [$project, $linoleum] = $this->makeProject();
        $piet = $people->firstWhere('name', 'Piet');

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $team->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'crew_member_ids' => [$piet->id],
                'hours' => 8,
            ])
            ->assertOk();

        $assignment = WorkerAssignment::query()->with('crewMembers')->first();
        $this->assertNotNull($assignment);
        $this->assertSame(
            [(int) $piet->id],
            $assignment->crewMembers->map(fn (CrewMember $member): int => (int) $member->id)->values()->all(),
        );
    }

    public function test_rejects_scheduling_a_teammate_without_vakkennis(): void
    {
        $user = User::factory()->create();
        $team = $this->makeWorker('Team Jansen', 'Linoleum', ['Piet', 'Jan']);
        $jan = $team->crewPeople()->where('name', 'Jan')->first();
        $this->assertInstanceOf(CrewMember::class, $jan);
        $jan->forceFill(['specialty' => 'PVC'])->save();
        [$project, $linoleum] = $this->makeProject();

        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $team->id,
                'project_id' => $project->id,
                'work_item_id' => $linoleum->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
                'crew_member_ids' => [$jan->id],
                'hours' => 8,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Jan heeft geen vakkennis voor Linoleum.');

        $this->assertSame(0, WorkerAssignment::query()->count());
    }

    public function test_rejects_moving_an_assignment_to_work_without_vakkennis(): void
    {
        $user = User::factory()->create();
        $kees = $this->makeWorker('Kees Jansen', 'Linoleum');
        [$project, $linoleum] = $this->makeProject();
        $pvc = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'PVC',
            'unit' => 'm2',
            'ordered_quantity' => 80,
            'status' => 'in_uitvoering',
        ]);
        $assignment = WorkerAssignment::query()->create([
            'worker_id' => $kees->id,
            'project_id' => $project->id,
            'work_item_id' => $linoleum->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-07',
            'hours_per_day' => 8,
        ]);

        $this->actingAs($user)
            ->patchJson(route('planning.assignments.update', $assignment), [
                'worker_id' => $kees->id,
                'work_item_id' => $pvc->id,
                'start_date' => '2026-09-07',
                'end_date' => '2026-09-07',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Kees Jansen heeft geen vakkennis voor PVC.');

        $this->assertSame($linoleum->id, $assignment->fresh()->work_item_id);
    }

    /**
     * @param  list<string>  $names
     */
    private function makeWorker(string $name, string $specialty, array $names = []): Worker
    {
        $members = [];
        foreach ($names as $member) {
            $members[] = ['name' => $member, 'phone' => ''];
        }

        return Worker::query()->create([
            'name' => $name,
            'employment_type' => 'eigen',
            'people_count' => $names === [] ? 1 : count($names),
            'crew_members' => $members !== [] ? $members : null,
            'specialty' => $specialty,
            'active' => true,
        ]);
    }

    /**
     * @return array{0: Project, 1: WorkItem}
     */
    private function makeProject(): array
    {
        $customer = Customer::query()->create(['name' => 'Gemeente Amersfoort']);
        $project = Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-07',
            'planned_end_date' => '2026-09-12',
        ]);
        $linoleum = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 3168,
            'status' => 'in_uitvoering',
        ]);

        return [$project, $linoleum];
    }
}
