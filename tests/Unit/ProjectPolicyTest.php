<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    #[DataProvider('purgeRoles')]
    public function test_purge_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('delete', new Project));
    }

    #[DataProvider('archiveRoles')]
    public function test_archive_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('archive', new Project));
        $this->assertSame($allowed, $user->can('restore', new Project));
    }

    #[DataProvider('manageRoles')]
    public function test_manage_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('create', Project::class));
        $this->assertSame($allowed, $user->can('update', new Project));
    }

    public function test_view_is_allowed_when_user_can_access_all_projects(): void
    {
        $user = User::factory()->make(['can_access_all_projects' => true]);

        $this->assertTrue($user->can('view', new Project));
    }

    public function test_view_is_denied_when_user_has_no_project_access(): void
    {
        $user = User::factory()->limitedAccess()->make();

        $this->assertSame(false, $user->can_access_all_projects);
        $this->assertFalse($user->canAccessProject(new Project));
        $this->assertFalse($user->can('view', new Project));
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function purgeRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, false],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'planner' => [UserRole::Planner, false],
            'vakman' => [UserRole::Vakman, false],
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
            'vakman' => [UserRole::Vakman, false],
        ];
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function manageRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'planner' => [UserRole::Planner, true],
            'vakman' => [UserRole::Vakman, false],
        ];
    }
}
