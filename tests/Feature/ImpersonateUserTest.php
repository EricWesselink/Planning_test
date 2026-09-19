<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ImpersonateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_index_shows_takeover_for_other_users_not_self(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Eric']);
        $nick = User::factory()->create(['name' => 'Nick']);

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Account overnemen')
            ->assertSee(route('users.impersonate.start', $nick), false)
            ->assertDontSee(route('users.impersonate.start', $admin), false);
    }

    public function test_admin_can_take_over_an_account_and_sees_the_banner(): void
    {
        $admin = User::factory()->admin()->create();
        $nick = User::factory()->create(['name' => 'Nick']);

        Log::spy();

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $nick))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($nick);
        $this->assertSame($admin->id, (int) session('impersonator_id'));
        $this->assertSame($nick->id, (int) session('impersonate_id'));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Je bekijkt het account van Nick')
            ->assertSee('Terug naar mijn account');

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($admin, $nick): bool {
            return $message === 'Account overgenomen'
                && $context['actor_id'] === $admin->id
                && $context['target_id'] === $nick->id;
        });
    }

    public function test_takeover_does_not_record_last_login_for_the_target(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $nick = User::factory()->create([
            'last_login_at' => now()->subDay(),
        ]);
        $previousLogin = $nick->last_login_at?->toDateTimeString();

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $nick))
            ->assertRedirect();

        $this->assertSame($previousLogin, $nick->fresh()->last_login_at?->toDateTimeString());
    }

    public function test_taken_over_account_uses_that_users_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $planner = User::factory()->create(['role' => UserRole::Planner]);

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $planner))
            ->assertRedirect();

        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('planning'))->assertOk();
    }

    public function test_admin_can_return_to_own_account(): void
    {
        $admin = User::factory()->admin()->create();
        $nick = User::factory()->create(['name' => 'Nick']);

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $nick))
            ->assertRedirect();

        $this->post(route('users.impersonate.stop'))
            ->assertRedirect(route('users.index'));

        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('impersonator_id'));
        $this->assertFalse(session()->has('impersonate_id'));

        $this->get(route('users.index'))
            ->assertOk()
            ->assertDontSee('Je bekijkt het account van Nick');
    }

    public function test_planner_cannot_take_over_an_account(): void
    {
        $planner = User::factory()->create(['role' => UserRole::Planner]);
        $target = User::factory()->create();

        $this->actingAs($planner)
            ->post(route('users.impersonate.start', $target))
            ->assertForbidden();

        $this->assertAuthenticatedAs($planner);
        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_admin_cannot_take_over_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $admin))
            ->assertForbidden();

        $this->assertFalse(session()->has('impersonator_id'));
    }

    public function test_admin_cannot_take_over_an_inactive_account(): void
    {
        $admin = User::factory()->admin()->create();
        $inactive = User::factory()->create(['active' => false]);

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $inactive))
            ->assertForbidden();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_nested_takeover_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $planner = User::factory()->create(['role' => UserRole::Planner]);

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $otherAdmin))
            ->assertRedirect();

        $this->post(route('users.impersonate.start', $planner))
            ->assertForbidden();

        $this->assertAuthenticatedAs($otherAdmin);
        $this->assertSame($admin->id, (int) session('impersonator_id'));
    }

    public function test_read_only_account_can_still_return_to_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $reader = User::factory()->alleenLezen()->create(['name' => 'Cuno']);

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $reader))
            ->assertRedirect();

        $this->assertAuthenticatedAs($reader);

        $this->post(route('users.impersonate.stop'))
            ->assertRedirect(route('users.index'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_taking_over_a_vakman_opens_vakman_planning(): void
    {
        $admin = User::factory()->admin()->create();
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'zzp',
            'company' => 'Nick Seine',
            'people_count' => 1,
            'specialty' => 'Linoleum',
            'active' => true,
        ]);
        $vakman = User::factory()->vakman($worker->id)->create(['name' => 'Nick']);

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $vakman))
            ->assertRedirect(route('vakman.planning'));

        $this->assertAuthenticatedAs($vakman);
        $this->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Je bekijkt het account van Nick')
            ->assertSee('Terug naar mijn account');
    }
}
