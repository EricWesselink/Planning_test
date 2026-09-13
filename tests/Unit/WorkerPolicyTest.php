<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Worker;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerPolicyTest extends TestCase
{
    #[DataProvider('manageRoles')]
    public function test_only_admin_and_planner_may_manage_workers(UserRole $role, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);
        $worker = new Worker(['name' => 'Fabian']);

        $this->assertSame($allowed, $actor->can('create', Worker::class));
        $this->assertSame($allowed, $actor->can('update', $worker));
        $this->assertSame($allowed, $actor->can('delete', $worker));
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function manageRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'planner' => [UserRole::Planner, true],
            'projectleider' => [UserRole::Projectleider, false],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'vakman' => [UserRole::Vakman, false],
        ];
    }
}
