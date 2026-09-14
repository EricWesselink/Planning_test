<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VakmanPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_the_vakman_login(): void
    {
        $this->get(route('vakman.password.edit'))
            ->assertRedirect(route('vakman.login'));
    }

    public function test_planner_cannot_open_the_vakman_password_page(): void
    {
        $planner = User::factory()->create();

        $this->actingAs($planner)
            ->get(route('vakman.password.edit'))
            ->assertForbidden();
    }

    public function test_vakman_can_change_password_with_the_current_one(): void
    {
        $vakman = $this->makeVakman();

        $this->actingAs($vakman)
            ->from(route('vakman.password.edit'))
            ->patch(route('vakman.password.update'), [
                'current_password' => 'password',
                'password' => 'nieuwepass1',
                'password_confirmation' => 'nieuwepass1',
            ])
            ->assertRedirect(route('vakman.password.edit'));

        $this->assertTrue(Hash::check('nieuwepass1', $vakman->fresh()->password));
        $this->assertAuthenticatedAs($vakman);

        $this->get(route('vakman.password.edit'))
            ->assertOk()
            ->assertSee('Wachtwoord is gewijzigd.');
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $vakman = $this->makeVakman();

        $this->actingAs($vakman)
            ->from(route('vakman.password.edit'))
            ->patch(route('vakman.password.update'), [
                'current_password' => 'verkeerd',
                'password' => 'nieuwepass1',
                'password_confirmation' => 'nieuwepass1',
            ])
            ->assertRedirect(route('vakman.password.edit'))
            ->assertSessionHasErrors([
                'current_password' => 'Het huidige wachtwoord is onjuist.',
            ]);

        $this->assertTrue(Hash::check('password', $vakman->fresh()->password));
    }

    public function test_confirmation_mismatch_is_rejected(): void
    {
        $vakman = $this->makeVakman();

        $this->actingAs($vakman)
            ->from(route('vakman.password.edit'))
            ->patch(route('vakman.password.update'), [
                'current_password' => 'password',
                'password' => 'nieuwepass1',
                'password_confirmation' => 'anderswacht',
            ])
            ->assertRedirect(route('vakman.password.edit'))
            ->assertSessionHasErrors([
                'password' => 'De wachtwoorden komen niet overeen.',
            ]);
    }

    public function test_vakman_nav_links_to_password_change(): void
    {
        $vakman = $this->makeVakman();

        $this->actingAs($vakman)
            ->get(route('vakman.planning'))
            ->assertOk()
            ->assertSee('Wachtwoord wijzigen')
            ->assertSee(route('vakman.password.edit'), false);
    }

    public function test_login_page_mentions_password_change(): void
    {
        $this->get(route('vakman.login'))
            ->assertOk()
            ->assertSee('Na het inloggen kun je zelf je wachtwoord wijzigen.');
    }

    private function makeVakman(): User
    {
        $worker = Worker::query()->create([
            'name' => 'Nick Seine',
            'employment_type' => 'eigen',
            'active' => true,
        ]);

        return User::factory()->vakman($worker->id)->create();
    }
}
