<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_work_leaves_projects_and_planning_but_stays_in_archive(): void
    {
        $user = User::factory()->projectleider()->create();
        $project = $this->makeProject('test', '2026-002');

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('2026-002')
            ->assertSee('Archief')
            ->assertSee('Archiveren')
            ->assertDontSee('Verwijderen');

        $this->actingAs($user)
            ->get(route('planning'))
            ->assertOk()
            ->assertSee('2026-002');

        $this->actingAs($user)
            ->post(route('projects.archive', $project))
            ->assertRedirect(route('projects.archived'));

        $this->assertNotNull($project->fresh()->archived_at);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee('2026-002');

        $this->actingAs($user)
            ->get(route('planning'))
            ->assertOk()
            ->assertDontSee('2026-002');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('2026-002');

        $this->actingAs($user)
            ->get(route('projects.archived'))
            ->assertOk()
            ->assertSee('2026-002')
            ->assertSee('Terugzetten');

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('2026-002')
            ->assertSee('Archief');
    }

    public function test_archived_work_can_be_restored_to_projects_and_planning(): void
    {
        $user = User::factory()->projectleider()->create();
        $project = $this->makeProject('Apotheek Zwolle', '2024-121');
        $project->archive();

        $this->actingAs($user)
            ->post(route('projects.restore', $project))
            ->assertRedirect(route('projects.index'));

        $this->assertNull($project->fresh()->archived_at);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Apotheek Zwolle');

        $this->actingAs($user)
            ->get(route('planning'))
            ->assertOk()
            ->assertSee('Apotheek Zwolle');
    }

    public function test_archived_running_work_leaves_dashboard_and_today(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject('TMZ Meubelenbelt Fase 2', '2024-118', ProjectStatus::InUitvoering);
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);
        WorkerAssignment::query()->create([
            'worker_id' => $worker->id,
            'project_id' => $project->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-12',
            'people_count' => 1,
        ]);
        $project->archive();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('TMZ Meubelenbelt Fase 2');
    }

    public function test_guest_cannot_open_archive(): void
    {
        $this->get(route('projects.archived'))->assertRedirect(route('login'));
    }

    public function test_work_can_be_deleted_completely(): void
    {
        $user = User::factory()->admin()->create();
        $project = $this->makeProject('test', '2026-002');
        $id = $project->id;

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Verwijderen');

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Archiveren');

        $this->actingAs($user)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect(route('projects.index'));

        $this->assertDatabaseMissing('projects', ['id' => $id]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee('2026-002');

        $this->actingAs($user)
            ->get(route('planning'))
            ->assertOk()
            ->assertDontSee('2026-002');

        $this->actingAs($user)
            ->get(route('projects.archived'))
            ->assertOk()
            ->assertDontSee('2026-002');
    }

    public function test_deleting_archived_work_returns_to_archive(): void
    {
        $user = User::factory()->admin()->create();
        $project = $this->makeProject('test', '2026-002');
        $project->archive();

        $this->actingAs($user)
            ->delete(route('projects.destroy', $project))
            ->assertRedirect(route('projects.archived'));

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    private function makeProject(string $name, string $number, ProjectStatus $status = ProjectStatus::Gepland): Project
    {
        $customer = Customer::query()->create(['name' => 'Klant '.$number]);

        return Project::query()->create([
            'project_number' => $number,
            'customer_id' => $customer->id,
            'name' => $name,
            'city' => 'Zwolle',
            'status' => $status,
        ]);
    }
}
