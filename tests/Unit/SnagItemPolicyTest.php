<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\SnagItem;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SnagItemPolicyTest extends TestCase
{
    #[DataProvider('rolesThatMayDelete')]
    public function test_allows_site_leads_to_delete_a_snag(UserRole $role): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertTrue($user->can('delete', new SnagItem));
    }

    public function test_forbids_a_planner_from_deleting_a_snag(): void
    {
        $user = User::factory()->make(['role' => UserRole::Planner]);

        $this->assertFalse($user->can('delete', new SnagItem));
    }

    #[DataProvider('createRoles')]
    public function test_create_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('create', SnagItem::class));
    }

    #[DataProvider('closeRoles')]
    public function test_close_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('close', new SnagItem));
    }

    #[DataProvider('reportRoles')]
    public function test_report_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('report', new SnagItem));
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function createRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, true],
            'planner' => [UserRole::Planner, false],
        ];
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function closeRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'planner' => [UserRole::Planner, false],
        ];
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function reportRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'uitvoerder' => [UserRole::Uitvoerder, true],
            'planner' => [UserRole::Planner, false],
        ];
    }

    /** @return array<string, array{0: UserRole}> */
    public static function rolesThatMayDelete(): array
    {
        return [
            'admin' => [UserRole::Admin],
            'uitvoerder' => [UserRole::Uitvoerder],
            'projectleider' => [UserRole::Projectleider],
        ];
    }
}
