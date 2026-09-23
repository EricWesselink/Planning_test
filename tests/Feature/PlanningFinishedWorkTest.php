<?php

namespace Tests\Feature;

use App\Enums\AssignmentKind;
use App\Enums\InternalBusinessUnit;
use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningFinishedWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_stand_filters_finished_works_without_hiding_internal_deployment(): void
    {
        $user = User::factory()->create();
        $active = $this->makeProject('Blijft actief', '260200091');
        $finished = $this->makeProject('Herstelwerk Zwolle', '260200092');
        $finished->finishPlanning();
        $this->internalAssignment();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('aria-label="Stand van de planning"', false)
            ->assertSee('>Actief</a>', false)
            ->assertSee('>Afgerond</a>', false)
            ->assertSee('>Alles</a>', false)
            ->assertSee('plan-project-title">Blijft actief', false)
            ->assertSee('Afronden')
            ->assertDontSee('plan-project-title">Herstelwerk Zwolle', false)
            ->assertSee('Intern – inzet', false);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'stand' => 'afgerond']))
            ->assertOk()
            ->assertSee('plan-project-title">Herstelwerk Zwolle', false)
            ->assertSee('href="'.route('projects.show', $finished).'"', false)
            ->assertSee('Weer actief zetten')
            ->assertDontSee('plan-project-title">Blijft actief', false)
            ->assertSee('Intern – inzet', false);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'stand' => 'alles']))
            ->assertOk()
            ->assertSee('plan-project-title">Blijft actief', false)
            ->assertSee('plan-project-title">Herstelwerk Zwolle', false)
            ->assertSee('plan-afgerond-mark', false)
            ->assertSee('>Afgerond</span>', false);

        $this->assertFalse($active->fresh()->isPlanningFinished());
    }

    public function test_planner_finishes_a_work_without_archiving_or_copying_it(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject('Herstelwerk Zwolle', '260200092');
        $assignment = $this->assignment($project);
        $projects = Project::query()->count();
        $works = WorkItem::query()->count();

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('verdwijnt uit Actief', false);

        $this->actingAs($user)
            ->from(route('planning', ['week' => '2026-09-07']))
            ->post(route('planning.finish', $project))
            ->assertRedirect(route('planning', ['week' => '2026-09-07']));

        $project->refresh();
        $this->assertTrue($project->isPlanningFinished());
        $this->assertNotNull($project->afgerond_at);
        $this->assertNull($project->archived_at);
        $this->assertSame($projects, Project::query()->count());
        $this->assertSame($works, WorkItem::query()->count());
        $this->assertSame(1, WorkerAssignment::query()->count());
        $this->assertSame($assignment->id, $assignment->fresh()->id);
        $this->assertSame($project->id, $assignment->fresh()->project_id);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertDontSee('plan-project-title">Herstelwerk Zwolle', false);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Herstelwerk Zwolle');

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Herstelwerk Zwolle')
            ->assertSee('Linoleum');
    }

    public function test_reactivating_puts_the_same_work_back_on_active_planning(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject('Herstelwerk Zwolle', '260200092');
        $assignment = $this->assignment($project);
        $project->finishPlanning();

        $this->actingAs($user)
            ->from(route('planning', ['week' => '2026-09-07', 'stand' => 'afgerond']))
            ->post(route('planning.reactivate', $project))
            ->assertRedirect(route('planning', ['week' => '2026-09-07', 'stand' => 'afgerond']));

        $project->refresh();
        $this->assertFalse($project->isPlanningFinished());
        $this->assertNull($project->afgerond_at);
        $this->assertNull($project->archived_at);
        $this->assertSame(1, Project::query()->count());
        $this->assertSame($assignment->id, WorkerAssignment::query()->sole()->id);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('plan-project-title">Herstelwerk Zwolle', false)
            ->assertSee('Afronden');
    }

    public function test_gereed_status_stays_on_active_planning_until_the_planner_finishes_it(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject('Productie klaar', '260200093', ProjectStatus::Gereed);

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertSee('Productie klaar')
            ->assertSee('Afronden');

        $this->assertFalse($project->fresh()->isPlanningFinished());
        $this->assertNull($project->fresh()->afgerond_at);
    }

    public function test_uitvoerder_cannot_finish_a_work(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $project = $this->makeProject('Herstelwerk Zwolle', '260200092');

        $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->assertDontSee(route('planning.finish', $project), false);

        $this->actingAs($user)
            ->post(route('planning.finish', $project))
            ->assertForbidden();

        $this->assertFalse($project->fresh()->isPlanningFinished());
    }

    public function test_archived_work_cannot_be_marked_finished_from_planning(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject('Archiefwerk', '260200094');
        $project->archive();

        $this->actingAs($user)
            ->post(route('planning.finish', $project))
            ->assertNotFound();

        $project->refresh();
        $this->assertFalse($project->isPlanningFinished());
        $this->assertNull($project->afgerond_at);
        $this->assertNotNull($project->archived_at);
    }

    public function test_guest_cannot_finish_a_work(): void
    {
        $project = $this->makeProject('Herstelwerk Zwolle', '260200092');

        $this->post(route('planning.finish', $project))
            ->assertRedirect(route('login'));

        $this->assertFalse($project->fresh()->isPlanningFinished());
    }

    private function makeProject(string $name, string $number, ProjectStatus $status = ProjectStatus::InUitvoering): Project
    {
        $customer = Customer::query()->create(['name' => 'Gemeente']);
        $project = Project::query()->create([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Amersfoort',
            'kind' => 'project',
            'status' => $status,
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

    private function assignment(Project $project): WorkerAssignment
    {
        $worker = Worker::query()->create([
            'name' => 'Kees',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        return WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'work_item_id' => $project->workItems->first()->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-09',
            'people_count' => 1,
            'hours_per_day' => 8,
        ]);
    }

    private function internalAssignment(): void
    {
        $worker = Worker::query()->create([
            'name' => 'Piet',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'kind' => AssignmentKind::Internal,
            'business_unit' => InternalBusinessUnit::Vloeren,
            'contact_name' => 'Jan Jansen',
            'description' => 'Werk voor Vloeren',
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-09',
            'people_count' => 1,
            'hours_per_day' => 8,
        ]);
    }
}
