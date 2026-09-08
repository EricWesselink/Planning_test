<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_redirect_to_the_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'planner@example.test',
        ]);

        $this->from(route('login'))->post('/login', [
            'email' => 'planner@example.test',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        User::factory()->create([
            'email' => 'planner@example.test',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from(route('login'))->post('/login', [
                'email' => 'planner@example.test',
                'password' => 'wrong-password',
            ])->assertRedirect(route('login'));
        }

        $this->from(route('login'))->post('/login', [
            'email' => 'planner@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }
}
