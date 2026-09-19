<?php

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_role_allows_views_and_forbids_writes(): void
    {
        $user = User::factory()->alleenLezen()->make();

        $this->assertTrue($user->canViewDashboard());
        $this->assertTrue($user->canViewProjects());
        $this->assertTrue($user->canViewPlanning());
        $this->assertTrue($user->canViewCalculations());
        $this->assertTrue($user->canViewWorkers());
        $this->assertFalse($user->canManageProjects());
        $this->assertFalse($user->canManagePlanning());
        $this->assertFalse($user->canEnterProgress());
        $this->assertFalse($user->canManageUsers());
        $this->assertTrue($user->isReadOnlyOfficeUser());
    }

    public function test_custom_permissions_honor_stored_keys_and_view_dependencies(): void
    {
        $user = User::factory()->aangepast([Permission::PlanningAssign])->make();

        $this->assertTrue($user->hasPermission(Permission::PlanningAssign));
        $this->assertTrue($user->hasPermission(Permission::PlanningView));
        $this->assertTrue($user->canAssignPlanning());
        $this->assertFalse($user->canViewCalculations());
        $this->assertFalse($user->canManageProjects());
    }

    public function test_existing_planner_rights_are_unchanged(): void
    {
        $user = User::factory()->make(['role' => UserRole::Planner]);

        $this->assertTrue($user->canManagePlanning());
        $this->assertTrue($user->canManageProjects());
        $this->assertFalse($user->canEnterProgress());
        $this->assertFalse($user->canManageUsers());
        $this->assertFalse($user->usesPermissionMatrix());
    }

    public function test_sanitize_drops_unknown_keys_and_adds_view_rights(): void
    {
        $this->assertSame(
            [Permission::PlanningAssign->value, Permission::PlanningView->value],
            PermissionCatalog::sanitize(['planning.assign', 'not-a-permission']),
        );
    }
}
