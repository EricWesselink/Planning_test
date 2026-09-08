<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('manageRoles')]
    public function test_only_admin_may_manage_users(UserRole $role, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);
        $user = User::factory()->make();

        $this->assertSame($allowed, $actor->can('viewAny', User::class));
        $this->assertSame($allowed, $actor->can('create', User::class));
        $this->assertSame($allowed, $actor->can('view', $user));
        $this->assertSame($allowed, $actor->can('update', $user));
        $this->assertSame($allowed, $actor->can('resetPassword', $user));
    }

    public function test_admin_cannot_deactivate_or_delete_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();

        $this->assertFalse($admin->can('deactivate', $admin));
        $this->assertFalse($admin->can('delete', $admin));
    }

    public function test_forbids_demoting_or_deleting_the_last_active_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->isLastActiveAdmin());
        $this->assertFalse($admin->can('demote', $admin));
        $this->assertFalse($admin->can('deactivate', $admin));
        $this->assertFalse($admin->can('delete', $admin));
    }

    public function test_allows_demoting_another_admin_when_one_remains(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        $this->assertTrue($admin->can('demote', $other));
        $this->assertTrue($admin->can('deactivate', $other));
        $this->assertTrue($admin->can('delete', $other));
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function manageRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, false],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'planner' => [UserRole::Planner, false],
            'vakman' => [UserRole::Vakman, false],
        ];
    }
}
