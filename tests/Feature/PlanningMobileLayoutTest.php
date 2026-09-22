<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningMobileLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_page_keeps_a_compact_mobile_bar_and_the_desktop_controls(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07', 'status' => 'gepland']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<a class="planning-mobile-btn" href="[^"]*week=2026-08-31[^"]*" title="Vorige week" aria-label="Vorige week">‹<\/a>/',
            $html
        );
        $this->assertStringContainsString('planning-mobile-week">Week 37</span>', $html);
        $this->assertMatchesRegularExpression(
            '/<a class="planning-mobile-btn" href="[^"]*week=2026-09-14[^"]*" title="Volgende week" aria-label="Volgende week">›<\/a>/',
            $html
        );
        $this->assertStringContainsString('id="planning-mobile-filters" aria-expanded="false" aria-controls="planning-filters"', $html);
        $this->assertStringContainsString('>Filters</button>', $html);
        $this->assertStringContainsString('>Vandaag</a>', $html);
        $this->assertStringContainsString('id="planning-mobile-availability" aria-expanded="false" aria-controls="planning-available"', $html);
        $this->assertStringContainsString('id="planning-filters"', $html);
        $this->assertStringContainsString('id="planning-available"', $html);
        $this->assertStringContainsString('name="kind"', $html);
        $this->assertStringContainsString('planning-controls-row', $html);
        $this->assertStringContainsString('id="plan-dialog"', $html);
        $this->assertStringNotContainsString('person-bar-mobile', $html);
        $this->assertTrue(
            strpos($html, 'planning-mobile-bar') < strpos($html, 'planning-scroll-area'),
            'The compact bar must sit above the planning board.'
        );
        $this->assertTrue(
            strpos($html, 'planning-controls') < strpos($html, 'planning-scroll-area'),
            'Desktop controls must stay above the scrollable board.'
        );
    }

    public function test_planning_page_hides_availability_controls_without_planning_management(): void
    {
        $user = User::factory()->uitvoerder()->create();

        $html = $this->actingAs($user)
            ->get(route('planning', ['week' => '2026-09-07']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="planning-mobile-filters"', $html);
        $this->assertStringContainsString('>Vandaag</a>', $html);
        $this->assertStringNotContainsString('id="planning-mobile-availability"', $html);
        $this->assertStringNotContainsString('id="planning-available"', $html);
    }

    public function test_mobile_planning_css_collapses_chrome_and_keeps_a_scrollable_board(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $start = strpos($css, '@media (max-width: 768px), ((max-height: 520px) and (max-width: 1000px))');
        $end = strpos($css, '@media (max-height: 520px) and (max-width: 1000px)');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $this->assertGreaterThan($start, $end);
        $mobile = substr($css, $start, $end - $start);

        $this->assertStringContainsString('height: 100dvh', $mobile);
        $this->assertStringContainsString('overflow-x: hidden', $mobile);
        $this->assertStringContainsString('.planning-page:not(.is-filters-open) .planning-controls-row', $mobile);
        $this->assertStringContainsString('.planning-page:not(.is-filters-open) .planning-filters', $mobile);
        $this->assertStringContainsString('.planning-page:not(.is-availability-open) .planning-available', $mobile);
        $this->assertStringContainsString('display: none', $mobile);
        $this->assertStringContainsString('--planning-sidebar-width: 11rem', $mobile);
        $this->assertStringContainsString('--plan-day-min: 12rem !important', $mobile);
        $this->assertStringContainsString('overscroll-behavior-x: contain', $mobile);
        $this->assertStringContainsString('touch-action: pan-x pan-y', $mobile);
        $this->assertStringContainsString('.plan-line--head.sticky-head', $mobile);
        $this->assertStringContainsString('position: sticky', $mobile);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr)', $mobile);
        $this->assertStringContainsString('.plan-frozen > .plan-cell--num', $mobile);
        $this->assertStringContainsString('font-size: 12px', $mobile);
        $this->assertSame(0, substr_count(substr($css, 0, $start), '--planning-sidebar-width: 11rem'));
        $this->assertMatchesRegularExpression(
            '/\.planning-mobile-bar\s*\{[^}]*display:\s*none/s',
            substr($css, 0, $start),
        );
        $landscapeEnd = strpos($css, '@media', $end + 10);
        $landscape = $landscapeEnd === false
            ? substr($css, $end)
            : substr($css, $end, $landscapeEnd - $end);
        $this->assertStringContainsString('min-height: 0', $landscape);
        $this->assertStringNotContainsString('planning-controls-row', $landscape);
        $this->assertMatchesRegularExpression(
            '/\.plan-frozen\s*\{[^}]*position:\s*sticky;\s*left:\s*0/s',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.person-bar\s*\{[^}]*font-size:\s*11px/s',
            $css
        );
    }
}
