<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VakmanLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_one_identifier_field_and_password(): void
    {
        $html = $this->get(route('vakman.login'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('06-nummer of e-mailadres', $html);
        $this->assertStringContainsString('Wachtwoord', $html);
        $this->assertStringContainsString('Inloggen', $html);
        $this->assertStringContainsString(route('login'), $html);
        $this->assertStringNotContainsString('<script type="module"', $html);
    }

    public function test_vakman_logs_in_with_email_and_lands_on_mijn_planning(): void
    {
        $user = $this->makeVakmanUser([
            'email' => 'nick@niconvloeren.nl',
        ]);

        $this->from(route('vakman.login'))
            ->post(route('vakman.login.store'), [
                'login' => 'nick@niconvloeren.nl',
                'password' => 'password',
            ])
            ->assertRedirect(route('vakman.planning'));

        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('mobileLogins')]
    public function test_vakman_logs_in_with_a_normalized_06_number(string $login): void
    {
        $user = $this->makeVakmanUser([
            'email' => 'nick@niconvloeren.nl',
        ], '06 12345678');

        $this->from(route('vakman.login'))
            ->post(route('vakman.login.store'), [
                'login' => $login,
                'password' => 'password',
            ])
            ->assertRedirect(route('vakman.planning'));

        $this->assertAuthenticatedAs($user);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function mobileLogins(): array
    {
        return [
            'digits' => ['0612345678'],
            'spaces' => ['06 12345678'],
            'plus' => ['+31612345678'],
        ];
    }

    public function test_crew_member_06_number_opens_that_member_account(): void
    {
        $worker = Worker::query()->create([
            'name' => 'Team Wespro',
            'employment_type' => 'zzp',
            'active' => true,
            'people_count' => 2,
            'crew_members' => [
                ['name' => 'Peter', 'phone' => '0611111111'],
                ['name' => 'Kees Jansen', 'phone' => '0622222222'],
            ],
        ]);
        User::factory()->vakman($worker->id)->create([
            'name' => 'Team Wespro',
            'email' => 'wespro@niconvloeren.nl',
        ]);
        $keesMember = $worker->crewPeople()->where('name', 'Kees Jansen')->first();
        $this->assertNotNull($keesMember);
        $kees = User::factory()->vakman($worker->id)->create([
            'name' => 'Kees Jansen',
            'email' => 'kees@niconvloeren.nl',
            'crew_member_id' => $keesMember->id,
        ]);

        $this->from(route('vakman.login'))
            ->post(route('vakman.login.store'), [
                'login' => '+31622222222',
                'password' => 'password',
            ])
            ->assertRedirect(route('vakman.planning'));

        $this->assertAuthenticatedAs($kees);
    }

    public function test_wrong_password_stays_on_the_vakman_login(): void
    {
        $this->makeVakmanUser(['email' => 'nick@niconvloeren.nl']);

        $this->from(route('vakman.login'))
            ->post(route('vakman.login.store'), [
                'login' => 'nick@niconvloeren.nl',
                'password' => 'verkeerd',
            ])
            ->assertRedirect(route('vakman.login'))
            ->assertSessionHasErrors([
                'login' => 'Deze combinatie van 06-nummer of e-mailadres en wachtwoord is onjuist.',
            ]);

        $this->assertGuest();
    }

    public function test_planner_credentials_are_rejected_on_the_vakman_login(): void
    {
        User::factory()->create([
            'email' => 'planner@niconvloeren.nl',
        ]);

        $this->from(route('vakman.login'))
            ->post(route('vakman.login.store'), [
                'login' => 'planner@niconvloeren.nl',
                'password' => 'password',
            ])
            ->assertRedirect(route('vakman.login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_inactive_vakman_cannot_log_in(): void
    {
        $this->makeVakmanUser([
            'email' => 'nick@niconvloeren.nl',
            'active' => false,
        ]);

        $this->from(route('vakman.login'))
            ->post(route('vakman.login.store'), [
                'login' => 'nick@niconvloeren.nl',
                'password' => 'password',
            ])
            ->assertRedirect(route('vakman.login'))
            ->assertSessionHasErrors([
                'login' => 'Dit account is niet actief.',
            ]);

        $this->assertGuest();
    }

    public function test_office_login_with_vakman_email_still_lands_on_mijn_planning(): void
    {
        $user = $this->makeVakmanUser(['email' => 'nick@niconvloeren.nl']);

        $this->from(route('login'))
            ->post('/login', [
                'email' => 'nick@niconvloeren.nl',
                'password' => 'password',
            ])
            ->assertRedirect(route('vakman.planning'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_vakman_login_is_throttled_after_five_failures(): void
    {
        $this->makeVakmanUser(['email' => 'nick@niconvloeren.nl']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from(route('vakman.login'))->post(route('vakman.login.store'), [
                'login' => 'nick@niconvloeren.nl',
                'password' => 'wrong-password',
            ])->assertRedirect(route('vakman.login'));
        }

        $this->from(route('vakman.login'))->post(route('vakman.login.store'), [
            'login' => 'nick@niconvloeren.nl',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_missing_fields_show_the_validation_messages(): void
    {
        $this->from(route('vakman.login'))
            ->post(route('vakman.login.store'), [])
            ->assertRedirect(route('vakman.login'))
            ->assertSessionHasErrors([
                'login' => 'Vul een 06-nummer of e-mailadres in.',
                'password' => 'Vul een wachtwoord in.',
            ]);
    }

    public function test_vakman_home_and_logout_stay_on_the_vakman_login(): void
    {
        $user = $this->makeVakmanUser();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('vakman.planning'));

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('vakman.login'));

        $this->assertGuest();
    }

    public function test_guest_is_sent_to_the_vakman_login_from_mijn_planning(): void
    {
        $this->get(route('vakman.planning'))
            ->assertRedirect(route('vakman.login'));
    }

    /**
     * @param  array<string, mixed>  $userAttributes
     */
    private function makeVakmanUser(array $userAttributes = [], string $phone = '0612345678'): User
    {
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'eigen',
            'active' => true,
            'phone' => $phone,
        ]);

        return User::factory()->vakman($worker->id)->create($userAttributes);
    }
}
