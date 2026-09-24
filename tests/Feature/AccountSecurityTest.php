<?php

namespace Tests\Feature;

use App\Models\AccountActivation;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\AccountActivationNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\AccountActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_account_with_an_eight_character_password_can_still_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'planner@niconvloeren.nl',
            'password' => 'password',
        ]);

        $this->from(route('login'))->post('/login', [
            'email' => 'planner@niconvloeren.nl',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_vakman_can_still_log_in_with_a_phone_number(): void
    {
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'eigen',
            'active' => true,
            'people_count' => 1,
            'crew_members' => [
                ['name' => 'Nick Seine', 'phone' => '06-57925505'],
            ],
        ]);
        $member = $worker->crewPeople()->first();
        $user = User::factory()->vakman($worker->id)->create([
            'email' => '31657925505@telefoon.niconvloeren.nl',
            'crew_member_id' => $member->id,
            'password' => 'password',
        ]);

        $this->from(route('vakman.login'))->post(route('vakman.login.store'), [
            'login' => '06-57925505',
            'password' => 'password',
        ])->assertRedirect(route('vakman.planning'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_activation_link_sets_a_hashed_password_once(): void
    {
        $user = User::factory()->create([
            'email' => 'nieuw@niconvloeren.nl',
            'password' => 'placeholder-niet-verstuurd',
        ]);
        $other = User::factory()->create([
            'email' => 'ander@niconvloeren.nl',
            'password' => 'password',
        ]);
        $plain = app(AccountActivationService::class)->issue($user);

        $this->get(route('activation.show', ['token' => $plain]))
            ->assertOk()
            ->assertSee('Nieuw wachtwoord');

        $this->post(route('activation.store', ['token' => $plain]), [
            'password' => 'kort',
            'password_confirmation' => 'kort',
        ])->assertSessionHasErrors('password');

        $this->post(route('activation.store', ['token' => $plain]), [
            'password' => 'nieuwWachtwoord',
            'password_confirmation' => 'nieuwWachtwoord',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('nieuwWachtwoord', $user->password));
        $this->assertNotSame('nieuwWachtwoord', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('password', $other->fresh()->password));
        $this->assertNotNull(AccountActivation::query()->where('user_id', $user->id)->value('used_at'));

        $this->post('/login', [
            'email' => 'nieuw@niconvloeren.nl',
            'password' => 'nieuwWachtwoord',
        ])->assertRedirect(route('dashboard'));

        $this->post('/logout');
        $this->post(route('activation.store', ['token' => $plain]), [
            'password' => 'nogEenAnder1',
            'password_confirmation' => 'nogEenAnder1',
        ])->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('nieuwWachtwoord', $user->fresh()->password));
    }

    public function test_expired_activation_link_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $plain = app(AccountActivationService::class)->issue($user);

        $this->travel(25)->hours();

        $this->get(route('activation.show', ['token' => $plain]))
            ->assertOk()
            ->assertSee('ongeldig of verlopen');

        $this->post(route('activation.store', ['token' => $plain]), [
            'password' => 'nieuwWachtwoord',
            'password_confirmation' => 'nieuwWachtwoord',
        ])->assertSessionHasErrors('token');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_activation_mail_and_whatsapp_do_not_contain_a_password(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'jan@niconvloeren.nl']);

        $this->actingAs($admin)
            ->post(route('users.activation.store', $user))
            ->assertRedirect();

        Notification::assertSentTo($user, AccountActivationNotification::class, function (AccountActivationNotification $notification) use ($user): bool {
            $html = $notification->toMail($user)->render();

            return str_contains($notification->activationUrl, '/activeren/')
                && ! str_contains($html, 'Tijdelijk wachtwoord')
                && ! str_contains($html, 'password=');
        });
    }

    public function test_logged_in_user_must_confirm_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->from(route('account.password.edit'))
            ->patch(route('account.password.update'), [
                'current_password' => 'verkeerd',
                'password' => 'nieuwWachtwoord',
                'password_confirmation' => 'nieuwWachtwoord',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->actingAs($user)
            ->patch(route('account.password.update'), [
                'password' => 'nieuwWachtwoord',
                'password_confirmation' => 'nieuwWachtwoord',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->from(route('account.password.edit'))
            ->patch(route('account.password.update'), [
                'current_password' => 'password',
                'password' => 'kort',
                'password_confirmation' => 'kort',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->from(route('account.password.edit'))
            ->patch(route('account.password.update'), [
                'current_password' => 'password',
                'password' => 'nieuwWachtwoord',
                'password_confirmation' => 'nieuwWachtwoord',
            ])
            ->assertRedirect(route('account.password.edit'));

        $this->post('/logout');
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'nieuwWachtwoord',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_password_reset_link_works_once_and_hides_whether_the_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'jan@niconvloeren.nl',
            'password' => 'password',
        ]);

        $this->from(route('password.request'))->post(route('password.email'), [
            'email' => 'onbekend@niconvloeren.nl',
        ])->assertRedirect(route('password.request'))
            ->assertSessionHas('status', 'Als er een account met deze gegevens bestaat, ontvang je een e-mail.');

        User::factory()->create([
            'email' => '31657925505@telefoon.niconvloeren.nl',
            'password' => 'password',
        ]);

        $this->from(route('password.request'))->post(route('password.email'), [
            'email' => '31657925505@telefoon.niconvloeren.nl',
        ])->assertSessionHas('status', 'Als er een account met deze gegevens bestaat, ontvang je een e-mail.');

        Notification::assertNothingSent();

        $this->from(route('password.request'))->post(route('password.email'), [
            'email' => 'jan@niconvloeren.nl',
        ])->assertSessionHas('status', 'Als er een account met deze gegevens bestaat, ontvang je een e-mail.');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'jan@niconvloeren.nl',
            'password' => 'nieuwWachtwoord',
            'password_confirmation' => 'nieuwWachtwoord',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('nieuwWachtwoord', $user->fresh()->password));

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'jan@niconvloeren.nl',
            'password' => 'anderWachtwoord',
            'password_confirmation' => 'anderWachtwoord',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('nieuwWachtwoord', $user->fresh()->password));
    }

    public function test_expired_password_reset_link_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'jan@niconvloeren.nl',
            'password' => 'password',
        ]);
        $token = Password::broker()->createToken($user);

        $this->travel(61)->minutes();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'jan@niconvloeren.nl',
            'password' => 'nieuwWachtwoord',
            'password_confirmation' => 'nieuwWachtwoord',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_disabled_account_cannot_log_in_and_guests_cannot_change_a_password(): void
    {
        User::factory()->create([
            'email' => 'uit@niconvloeren.nl',
            'active' => false,
            'password' => 'password',
        ]);

        $this->from(route('login'))->post('/login', [
            'email' => 'uit@niconvloeren.nl',
            'password' => 'password',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('account.password.edit'))->assertRedirect(route('login'));
    }

    public function test_password_email_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('password.email'), [
                'email' => 'jan@niconvloeren.nl',
            ])->assertRedirect();
        }

        $this->post(route('password.email'), [
            'email' => 'jan@niconvloeren.nl',
        ])->assertTooManyRequests();
    }

    public function test_admin_impersonation_still_works(): void
    {
        $admin = User::factory()->admin()->create();
        $planner = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('users.impersonate.start', $planner))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($planner);
    }
}
