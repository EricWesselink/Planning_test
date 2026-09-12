<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureUserIsActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_user_is_logged_out_on_the_next_page_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $user->forceFill(['active' => false])->save();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Dit account is niet actief.']);

        $this->assertGuest();
    }

    public function test_deactivated_user_receives_403_on_the_next_json_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $user->forceFill(['active' => false])->save();

        $this->getJson(route('dashboard'))
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'Dit account is niet actief.']);

        $this->assertGuest();
    }

    public function test_active_user_keeps_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }
}
