<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticatedLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_renders_in_the_top_bar_with_logout(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, '>Uitloggen</button>'));
        $this->assertSame(1, substr_count($html, 'action="'.route('logout').'"'));
        $this->assertStringContainsString('nicon-topbar', $html);
        $this->assertStringContainsString('nicon-main', $html);
        $this->assertStringNotContainsString('nicon-sidebar', $html);
        $this->assertStringNotContainsString('nicon-mobile-bar', $html);
        $this->assertMatchesRegularExpression(
            '/<header class="nicon-topbar[\s\S]*<nav[\s\S]*Dashboard[\s\S]*<\/nav>[\s\S]*action="'.preg_quote(route('logout'), '/').'"[\s\S]*Uitloggen[\s\S]*<\/header>\s*<div class="nicon-main">\s*<main/',
            $html
        );
    }

    public function test_project_board_renders_inside_the_main_content_region(): void
    {
        $user = User::factory()->create();
        $customer = Customer::query()->create(['name' => 'Nicon vloeren']);
        $project = Project::query()->create([
            'project_number' => 'P-100046',
            'customer_id' => $customer->id,
            'name' => 'Laakse Tuinen',
            'city' => 'Amersfoort',
            'status' => 'gepland',
        ]);

        $html = $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="nicon-main">\s*<main class="p-0 min-h-0 overflow-hidden">[\s\S]*id="project-board"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression('/nicon-sidebar|nicon-mobile-bar/', $html);
    }

    public function test_logout_clears_the_session_and_returns_to_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
