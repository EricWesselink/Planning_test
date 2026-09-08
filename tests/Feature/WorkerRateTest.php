<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_can_save_agreed_prices(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'company' => 'Harm Wesselink',
            'specialty' => 'Primen & egaliseren',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('workers.show', $worker))
            ->post(route('workers.rates.store', $worker), [
                'rates' => [
                    [
                        'specialty' => 'primen_egaliseren',
                        'unit' => 'm2',
                        'unit_price' => '4,50',
                    ],
                    [
                        'specialty' => 'plinten',
                        'unit' => 'm1',
                        'unit_price' => '',
                    ],
                ],
            ])
            ->assertRedirect(route('workers.show', $worker));

        $this->assertSame(1, $worker->rates()->count());
        $rate = $worker->rates()->first();
        $this->assertSame('primen_egaliseren', $rate->specialty);
        $this->assertSame('m2', $rate->unit->value);
        $this->assertSame('4.50', $rate->unit_price);
    }

    public function test_worker_page_shows_agreed_price_fields(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'company' => 'Harm Wesselink',
            'specialty' => 'Primen & egaliseren',
            'active' => true,
        ]);
        WorkerRate::query()->create([
            'worker_id' => $worker->id,
            'specialty' => 'primen_egaliseren',
            'unit' => 'm2',
            'unit_price' => 4.50,
        ]);

        $this->actingAs($user)
            ->get(route('workers.show', $worker))
            ->assertOk()
            ->assertSee('Afgesproken prijzen')
            ->assertSee('Uurtarief')
            ->assertSee('4.50');
    }

    public function test_planner_can_save_an_hourly_rate(): void
    {
        $user = User::factory()->create();
        $worker = Worker::query()->create([
            'name' => 'Harm Wesselink',
            'employment_type' => 'zzp',
            'company' => 'Harm Wesselink',
            'specialty' => 'Primen & egaliseren',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('workers.show', $worker))
            ->post(route('workers.rates.store', $worker), [
                'rates' => [
                    [
                        'specialty' => 'primen_egaliseren',
                        'unit' => 'm2',
                        'unit_price' => '4,50',
                    ],
                    [
                        'specialty' => 'uurtarief',
                        'unit' => 'm2',
                        'unit_price' => '45,00',
                    ],
                ],
            ])
            ->assertRedirect(route('workers.show', $worker));

        $hourly = $worker->rates()->where('specialty', 'uurtarief')->first();
        $this->assertNotNull($hourly);
        $this->assertSame('uren', $hourly->unit->value);
        $this->assertSame('45.00', $hourly->unit_price);
    }

    public function test_uitvoerder_cannot_save_agreed_prices(): void
    {
        $user = User::factory()->uitvoerder()->create();
        $worker = Worker::query()->create([
            'name' => 'Harm',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('workers.rates.store', $worker), [
                'rates' => [
                    ['specialty' => 'primen_egaliseren', 'unit' => 'm2', 'unit_price' => '4.50'],
                ],
            ])
            ->assertForbidden();

        $this->assertSame(0, WorkerRate::query()->count());
    }

    #[DataProvider('updateRoles')]
    public function test_rate_update_authorization(UserRole $role, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);
        $worker = Worker::query()->create([
            'name' => 'Harm',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('workers.rates.store', $worker), [
            'rates' => [
                ['specialty' => 'primen_egaliseren', 'unit' => 'm2', 'unit_price' => '4.50'],
            ],
        ]);

        if ($allowed) {
            $response->assertRedirect(route('workers.show', $worker));
            $this->assertSame(1, $worker->rates()->count());
        } else {
            $response->assertForbidden();
            $this->assertSame(0, $worker->rates()->count());
        }
    }

    /** @return array<string, array{0: UserRole, 1: bool}> */
    public static function updateRoles(): array
    {
        return [
            'admin' => [UserRole::Admin, true],
            'planner' => [UserRole::Planner, true],
            'projectleider' => [UserRole::Projectleider, false],
            'uitvoerder' => [UserRole::Uitvoerder, false],
        ];
    }
}
