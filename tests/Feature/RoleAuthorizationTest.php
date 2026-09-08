<?php

namespace Tests\Feature;

use App\Enums\SnagStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Project;
use App\Models\SnagItem;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('purgeRoles')]
    public function test_project_purge_authorization(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);
        $project = $this->makeProject();

        $response = $this->actingAs($user)->delete(route('projects.destroy', $project));

        if ($allowed) {
            $response->assertRedirect(route('projects.index'));
            $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        } else {
            $response->assertForbidden();
            $this->assertModelExists($project);
        }
    }

    #[DataProvider('archiveRoles')]
    public function test_project_archive_authorization(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);
        $project = $this->makeProject();

        $response = $this->actingAs($user)->post(route('projects.archive', $project));

        if ($allowed) {
            $response->assertRedirect(route('projects.archived'));
            $this->assertNotNull($project->fresh()->archived_at);
        } else {
            $response->assertForbidden();
            $this->assertNull($project->fresh()->archived_at);
        }
    }

    #[DataProvider('createSnagRoles')]
    public function test_snag_create_authorization(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$project, $worker] = $this->makeProjectWithWorker();

        $response = $this->actingAs($user)->postJson(route('projects.snags.store', $project), [
            'x' => 0.22,
            'y' => 0.41,
            'drawing_page' => 1,
            'description' => 'Kim niet recht',
            'assigned_worker_id' => $worker->id,
        ]);

        if ($allowed) {
            $response->assertCreated();
            $this->assertDatabaseHas('snag_items', [
                'project_id' => $project->id,
                'description' => 'Kim niet recht',
            ]);
        } else {
            $response->assertForbidden();
            $this->assertDatabaseMissing('snag_items', [
                'project_id' => $project->id,
                'description' => 'Kim niet recht',
            ]);
        }
    }

    #[DataProvider('updateSnagRoles')]
    public function test_snag_update_authorization(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$project, $worker] = $this->makeProjectWithWorker();
        $snag = $this->makeSnag($project, $worker, SnagStatus::Open);

        $response = $this->actingAs($user)->patchJson(route('projects.snags.update', [$project, $snag]), [
            'description' => 'Nieuwe omschrijving',
            'assigned_worker_id' => $worker->id,
        ]);

        if ($allowed) {
            $response->assertOk();
            $this->assertSame('Nieuwe omschrijving', $snag->fresh()->description);
        } else {
            $response->assertForbidden();
            $this->assertSame('Herstel nodig', $snag->fresh()->description);
        }
    }

    #[DataProvider('reportSnagRoles')]
    public function test_snag_reported_done_authorization(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$project, $worker] = $this->makeProjectWithWorker();
        $snag = $this->makeSnag($project, $worker, SnagStatus::InProgress);

        $response = $this->actingAs($user)->postJson(route('projects.snags.report', [$project, $snag]), [
            'note' => 'Klaar',
        ]);

        if ($allowed) {
            $response->assertOk();
            $this->assertSame(SnagStatus::ReportedDone, $snag->fresh()->status);
        } else {
            $response->assertForbidden();
            $this->assertSame(SnagStatus::InProgress, $snag->fresh()->status);
        }
    }

    #[DataProvider('closeSnagRoles')]
    public function test_snag_closed_authorization(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$project, $worker] = $this->makeProjectWithWorker();
        $snag = $this->makeSnag($project, $worker, SnagStatus::ReportedDone);

        $response = $this->actingAs($user)->postJson(route('projects.snags.approve', [$project, $snag]));

        if ($allowed) {
            $response->assertOk();
            $this->assertSame(SnagStatus::Closed, $snag->fresh()->status);
        } else {
            $response->assertForbidden();
            $this->assertSame(SnagStatus::ReportedDone, $snag->fresh()->status);
        }
    }

    #[DataProvider('closeSnagRoles')]
    public function test_snag_patch_closed_authorization(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);
        [$project, $worker] = $this->makeProjectWithWorker();
        $snag = $this->makeSnag($project, $worker, SnagStatus::ReportedDone);

        $response = $this->actingAs($user)->patchJson(route('projects.snags.update', [$project, $snag]), [
            'status' => SnagStatus::Closed->value,
        ]);

        if ($allowed) {
            $response->assertOk();
            $this->assertSame(SnagStatus::Closed, $snag->fresh()->status);
        } else {
            $response->assertForbidden();
            $this->assertSame(SnagStatus::ReportedDone, $snag->fresh()->status);
        }
    }

    public function test_guest_can_use_the_public_zzp_link(): void
    {
        [$project, $worker] = $this->makeProjectWithWorker();
        $snag = $this->makeSnag($project, $worker, SnagStatus::Assigned);

        $this->get(route('snags.public.show', $snag->public_token))
            ->assertOk()
            ->assertSee('Opleverpunt #'.$snag->number)
            ->assertSee('Gereed melden')
            ->assertDontSee('Dashboard');

        $this->post(route('snags.public.progress', $snag->public_token))->assertRedirect();
        $this->assertSame(SnagStatus::InProgress, $snag->fresh()->status);

        $this->post(route('snags.public.comment', $snag->public_token), [
            'note' => 'Kom vanmiddag.',
        ])->assertRedirect();

        $this->post(route('snags.public.complete', $snag->public_token), [
            'note' => 'Hersteld',
        ])->assertRedirect();

        $this->assertSame(SnagStatus::ReportedDone, $snag->fresh()->status);
    }

    public function test_guest_cannot_close_a_snag_via_the_public_link(): void
    {
        [$project, $worker] = $this->makeProjectWithWorker();
        $snag = $this->makeSnag($project, $worker, SnagStatus::ReportedDone);

        $this->post(route('snags.public.complete', $snag->public_token), [
            'note' => 'Toch afhandelen',
        ])->assertForbidden();

        $this->assertSame(SnagStatus::ReportedDone, $snag->fresh()->status);
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function purgeRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, false],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'planner' => [UserRole::Planner, false],
        ];
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function archiveRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'planner' => [UserRole::Planner, false],
        ];
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function createSnagRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, true],
            'planner' => [UserRole::Planner, false],
        ];
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function updateSnagRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, true],
            'planner' => [UserRole::Planner, true],
        ];
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function reportSnagRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, true],
            'planner' => [UserRole::Planner, false],
        ];
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function closeSnagRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'planner' => [UserRole::Planner, false],
        ];
    }

    private function makeProject(): Project
    {
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => '260200090',
            'customer_id' => $customer->id,
            'name' => 'Kindcentrum Veldhoeve',
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ]);
    }

    /** @return array{0: Project, 1: Worker} */
    private function makeProjectWithWorker(): array
    {
        $project = $this->makeProject();
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        return [$project, $worker];
    }

    private function makeSnag(Project $project, Worker $worker, SnagStatus $status): SnagItem
    {
        return SnagItem::query()->create([
            'project_id' => $project->id,
            'drawing_page' => 1,
            'x' => 0.2,
            'y' => 0.3,
            'number' => 1,
            'description' => 'Herstel nodig',
            'assigned_worker_id' => $worker->id,
            'status' => $status,
        ]);
    }
}
