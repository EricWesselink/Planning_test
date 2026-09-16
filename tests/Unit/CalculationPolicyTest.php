<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Calculation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CalculationPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_only_project_managers_may_use_calculations(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role]);
        $calculation = new Calculation;

        $this->assertSame($allowed, $user->can('viewAny', Calculation::class));
        $this->assertSame($allowed, $user->can('view', $calculation));
        $this->assertSame($allowed, $user->can('create', Calculation::class));
        $this->assertSame($allowed, $user->can('update', $calculation));
        $this->assertSame($allowed, $user->can('delete', $calculation));
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function roles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'projectleider' => [UserRole::Projectleider, true],
            'planner' => [UserRole::Planner, true],
            'uitvoerder' => [UserRole::Uitvoerder, false],
            'vakman' => [UserRole::Vakman, false],
        ];
    }
}
