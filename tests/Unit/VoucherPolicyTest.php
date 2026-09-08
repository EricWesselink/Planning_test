<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoucherPolicyTest extends TestCase
{
    #[DataProvider('createRoles')]
    public function test_create_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, $user->can('create', [Voucher::class, new Project]));
    }

    public function test_view_is_allowed_when_user_can_access_the_project(): void
    {
        $user = User::factory()->make(['can_access_all_projects' => true]);
        $voucher = new Voucher;
        $voucher->setRelation('project', new Project);

        $this->assertTrue($user->can('view', $voucher));
    }

    public function test_view_is_denied_when_user_has_no_project_access(): void
    {
        $user = User::factory()->limitedAccess()->make();
        $voucher = new Voucher;
        $voucher->setRelation('project', new Project);

        $this->assertFalse($user->can('view', $voucher));
    }

    public function test_create_is_denied_for_an_inaccessible_project(): void
    {
        $user = User::factory()->limitedAccess()->make();

        $this->assertFalse($user->can('create', [Voucher::class, new Project]));
    }

    #[DataProvider('createRoles')]
    public function test_update_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);
        $voucher = new Voucher;
        $voucher->setRelation('project', new Project);

        $this->assertSame($allowed, $user->can('update', $voucher));
    }

    #[DataProvider('createRoles')]
    public function test_send_permission(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);
        $voucher = new Voucher;
        $voucher->setRelation('project', new Project);

        $this->assertSame($allowed, $user->can('send', $voucher));
    }

    public function test_update_is_denied_for_an_inaccessible_project(): void
    {
        $user = User::factory()->limitedAccess()->make();
        $voucher = new Voucher;
        $voucher->setRelation('project', new Project);

        $this->assertFalse($user->can('update', $voucher));
    }

    public function test_send_is_denied_for_an_inaccessible_project(): void
    {
        $user = User::factory()->limitedAccess()->make();
        $voucher = new Voucher;
        $voucher->setRelation('project', new Project);

        $this->assertFalse($user->can('send', $voucher));
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function createRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'planner' => [UserRole::Planner, true],
            'uitvoerder' => [UserRole::Uitvoerder, false],
        ];
    }
}
