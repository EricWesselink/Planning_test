<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureFirstRunSetup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_is_available_when_no_users_exist(): void
    {
        $this->get(route('setup.create'))
            ->assertOk()
            ->assertSee('Eerste beheerder')
            ->assertSee('Naam')
            ->assertSee('Beheerdersaccount aanmaken');
    }

    public function test_first_admin_can_be_created_when_no_users_exist(): void
    {
        $this->from(route('setup.create'))
            ->post(route('setup.store'), [
                'name' => 'Eric Beheerder',
                'email' => 'eric@niconvloeren.nl',
                'password' => 'wachtwoord123',
                'password_confirmation' => 'wachtwoord123',
                'role' => UserRole::Vakman->value,
            ])
            ->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'eric@niconvloeren.nl')->first();
        $this->assertNotNull($user);
        $this->assertSame('Eric Beheerder', $user->name);
        $this->assertTrue(Hash::check('wachtwoord123', $user->password));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->last_login_at);
        $this->assertSame(1, User::query()->count());
    }

    public function test_first_created_user_receives_admin_rights(): void
    {
        $this->post(route('setup.store'), $this->payload());

        $user = User::query()->sole();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->active);
        $this->assertTrue($user->can_access_all_projects);
        $this->assertTrue($user->canManageUsers());

        $this->actingAs($user)
            ->get(route('users.index'))
            ->assertOk();
    }

    public function test_setup_get_is_unavailable_when_a_user_exists(): void
    {
        User::factory()->create();

        $this->get(route('setup.create'))
            ->assertRedirect(route('login'));
    }

    public function test_setup_post_cannot_create_a_second_user_when_one_exists(): void
    {
        User::factory()->create([
            'email' => 'bestaand@niconvloeren.nl',
        ]);

        $this->from(route('login'))
            ->post(route('setup.store'), $this->payload([
                'email' => 'tweede@niconvloeren.nl',
            ]))
            ->assertRedirect(route('login'));

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(User::query()->where('email', 'bestaand@niconvloeren.nl')->exists());
        $this->assertFalse(User::query()->where('email', 'tweede@niconvloeren.nl')->exists());
        $this->assertGuest();
    }

    public function test_setup_store_rechecks_existing_users_and_refuses_a_second_admin(): void
    {
        User::factory()->create([
            'email' => 'bestaand@niconvloeren.nl',
        ]);

        $this->withoutMiddleware(EnsureFirstRunSetup::class)
            ->from(route('login'))
            ->post(route('setup.store'), $this->payload([
                'email' => 'tweede@niconvloeren.nl',
            ]))
            ->assertRedirect(route('login'));

        $this->assertSame(1, User::query()->count());
        $this->assertFalse(User::query()->where('email', 'tweede@niconvloeren.nl')->exists());
        $this->assertGuest();
    }

    public function test_invalid_setup_payload_does_not_create_a_user(): void
    {
        $this->from(route('setup.create'))
            ->post(route('setup.store'), [])
            ->assertRedirect(route('setup.create'))
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertSame(0, User::query()->count());
        $this->assertGuest();
    }

    public function test_login_page_shows_setup_prompt_only_when_no_users_exist(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Er is nog geen beheerdersaccount ingesteld.')
            ->assertSee('Beheerdersaccount aanmaken')
            ->assertSee('href="'.route('setup.create').'"', false);

        User::factory()->create();

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Er is nog geen beheerdersaccount ingesteld.')
            ->assertDontSee('Beheerdersaccount aanmaken')
            ->assertDontSee('href="'.route('setup.create').'"', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Eerste Beheerder',
            'email' => 'beheer@niconvloeren.nl',
            'password' => 'wachtwoord123',
            'password_confirmation' => 'wachtwoord123',
        ], $overrides);
    }
}
