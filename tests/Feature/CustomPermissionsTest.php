<?php

namespace Tests\Feature;

use App\Enums\CalculationStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Calculation;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkItem;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_user_with_planning_write_can_plan_but_not_change_calculations(): void
    {
        $user = User::factory()->aangepast([
            Permission::PlanningView,
            Permission::PlanningAssign,
            Permission::PlanningUpdate,
            Permission::ProjectsView,
        ])->create();
        $project = $this->makeProject();
        $work = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => 'Linoleum',
            'unit' => 'm2',
            'ordered_quantity' => 100,
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
            'status' => 'in_uitvoering',
        ]);
        $worker = Worker::query()->create([
            'name' => 'Albert',
            'employment_type' => 'zzp',
            'company' => 'Albert Vloeren',
            'people_count' => 1,
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $calculation = Calculation::query()->create([
            'name' => 'Offerte Wepro',
            'dated_on' => '2026-03-06',
            'status' => CalculationStatus::Concept,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->get(route('planning'))->assertOk();
        $this->actingAs($user)
            ->postJson(route('planning.assignments.store'), [
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'work_item_id' => $work->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-08',
                'people_count' => 1,
            ])
            ->assertOk();
        $this->assertDatabaseHas('worker_assignments', [
            'project_id' => $project->id,
            'worker_id' => $worker->id,
        ]);

        $this->actingAs($user)->get(route('calculations.index'))->assertForbidden();
        $this->actingAs($user)
            ->patch(route('calculations.update', $calculation), ['name' => 'Gewijzigd', 'dated_on' => '2026-03-06'])
            ->assertForbidden();
        $this->assertSame('Offerte Wepro', $calculation->fresh()->name);
    }

    public function test_hidden_module_cannot_be_opened_via_url(): void
    {
        $user = User::factory()->aangepast([Permission::DashboardView])->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('planning'))->assertForbidden();
        $this->actingAs($user)->get(route('projects.index'))->assertForbidden();
    }

    public function test_admin_can_store_and_reload_custom_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->from(route('users.show', $user))
            ->patch(route('users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'role' => UserRole::Aangepast->value,
                'active' => '1',
                'project_access' => 'all',
                'permissions' => [
                    Permission::PlanningView->value,
                    Permission::PlanningAssign->value,
                ],
            ])
            ->assertRedirect(route('users.show', $user));

        $user->refresh();
        $this->assertSame(UserRole::Aangepast, $user->role);
        $this->assertSame(
            PermissionCatalog::sanitize([
                Permission::PlanningView->value,
                Permission::PlanningAssign->value,
            ]),
            $user->permissions,
        );

        $this->actingAs($user)->get(route('planning'))->assertOk();
        $this->assertTrue($user->canAssignPlanning());
        $this->assertFalse($user->canViewCalculations());
    }

    public function test_custom_user_cannot_grant_themselves_extra_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->aangepast([
            Permission::UsersView,
            Permission::UsersManage,
            Permission::DashboardView,
        ])->create();

        $this->actingAs($user)
            ->patch(route('users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'role' => UserRole::Admin->value,
                'active' => '1',
                'project_access' => 'all',
                'permissions' => PermissionCatalog::allPreset(),
            ])
            ->assertForbidden();

        $user->refresh();
        $this->assertSame(UserRole::Aangepast, $user->role);
        $this->assertFalse($user->canManagePlanning());
        $this->assertTrue($admin->canManageUsers());
    }

    public function test_admin_keeps_full_access(): void
    {
        $admin = User::factory()->admin()->create();
        $project = $this->makeProject();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('planning'))->assertOk();
        $this->actingAs($admin)->get(route('calculations.index'))->assertOk();
        $this->actingAs($admin)
            ->patch(route('projects.update', $project), [
                'city' => 'Zwolle',
            ])
            ->assertRedirect();
        $this->assertSame('Zwolle', $project->fresh()->city);
    }

    private function makeProject(): Project
    {
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);

        return Project::query()->create([
            'project_number' => '260200091',
            'customer_id' => $customer->id,
            'name' => 'Kindcentrum Veldhoeve',
            'city' => 'Amersfoort',
            'status' => 'in_uitvoering',
            'planned_start_date' => '2026-09-08',
            'planned_end_date' => '2026-09-12',
        ]);
    }
}
