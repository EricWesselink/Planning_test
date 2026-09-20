<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\WallBoundDimensionMatcher;
use Tests\TestCase;

class WallBoundDimensionMatcherTest extends TestCase
{
    public function test_binds_local_wall_dimensions_and_rejects_an_overall_length(): void
    {
        $match = (new WallBoundDimensionMatcher)->match(
            ['x' => 275.0, 'y' => 590.0, 'page' => 1, 'room_number' => 'A-00-03'],
            [
                ['text' => '3500', 'x' => 250.0, 'y' => 485.0, 'page' => 1, 'mm' => 3500],
                ['text' => '2000', 'x' => 70.0, 'y' => 590.0, 'page' => 1, 'mm' => 2000],
                ['text' => '6975', 'x' => 250.0, 'y' => 455.0, 'page' => 1, 'mm' => 6975],
            ],
            [
                'page' => 1,
                'fills' => [[
                    'x' => 100.0,
                    'y' => 500.0,
                    'width' => 350.0,
                    'height' => 200.0,
                ]],
                'walls' => [
                    ['x1' => 100.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 500.0, 'axis' => 'h'],
                    ['x1' => 450.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'v'],
                    ['x1' => 100.0, 'y1' => 700.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'h'],
                    ['x1' => 100.0, 'y1' => 500.0, 'x2' => 100.0, 'y2' => 700.0, 'axis' => 'v'],
                    ['x1' => 70.0, 'y1' => 500.0, 'x2' => 70.0, 'y2' => 700.0, 'axis' => 'v'],
                    ['x1' => 50.0, 'y1' => 450.0, 'x2' => 545.0, 'y2' => 450.0, 'axis' => 'h'],
                ],
            ],
            [],
        );

        $this->assertSame(3500, $match['horizontal']['mm'] ?? null);
        $this->assertSame(2000, $match['vertical']['mm'] ?? null);
        $this->assertSame(0.9, $match['confidence']);
        $this->assertContains(6975, array_column($match['rejected'], 'mm'));
    }

    public function test_closes_a_door_opening_and_binds_local_wall_dimensions(): void
    {
        $match = (new WallBoundDimensionMatcher)->match(
            ['x' => 215.0, 'y' => 590.0, 'page' => 1, 'room_key' => 'slaapkamer-1', 'text' => 'SLAAPKAMER 1'],
            [
                ['text' => '3500', 'x' => 275.0, 'y' => 488.0, 'page' => 1, 'mm' => 3500],
                ['text' => '2000', 'x' => 88.0, 'y' => 590.0, 'page' => 1, 'mm' => 2000],
                ['text' => '10600', 'x' => 275.0, 'y' => 455.0, 'page' => 1, 'mm' => 10600],
            ],
            [
                'page' => 1,
                'width' => 900.0,
                'height' => 900.0,
                'fills' => [],
                'walls' => [
                    ['x1' => 100.0, 'y1' => 500.0, 'x2' => 180.0, 'y2' => 500.0, 'axis' => 'h'],
                    ['x1' => 250.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 500.0, 'axis' => 'h'],
                    ['x1' => 100.0, 'y1' => 700.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'h'],
                    ['x1' => 100.0, 'y1' => 500.0, 'x2' => 100.0, 'y2' => 700.0, 'axis' => 'v'],
                    ['x1' => 450.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'v'],
                    ['x1' => 50.0, 'y1' => 430.0, 'x2' => 850.0, 'y2' => 430.0, 'axis' => 'h'],
                ],
            ],
            [],
        );

        $this->assertNotNull($match['box']);
        $this->assertSame(3500, $match['horizontal']['mm'] ?? null);
        $this->assertSame(2000, $match['vertical']['mm'] ?? null);
        $this->assertSame(0.9, $match['confidence']);
        $this->assertContains(10600, array_column($match['rejected'], 'mm'));
        $this->assertTrue(
            str_contains((string) (collect($match['rejected'])->firstWhere('mm', 10600)['reason'] ?? ''), 'totale/stramienmaat')
            || str_contains((string) (collect($match['rejected'])->firstWhere('mm', 10600)['reason'] ?? ''), 'endpoints'),
        );
        $this->assertNotEmpty($match['boundary']['closed_gaps'] ?? []);
        $this->assertNotNull($match['scale_mm_per_px']);
    }

    public function test_does_not_bind_a_dimension_that_only_sits_near_the_room_number(): void
    {
        $match = (new WallBoundDimensionMatcher)->match(
            ['x' => 50.0, 'y' => 70.0, 'page' => 1, 'room_number' => 'A-00-03'],
            [
                ['text' => '6975', 'x' => 48.0, 'y' => 65.0, 'page' => 1, 'mm' => 6975],
                ['text' => '3500', 'x' => 52.0, 'y' => 60.0, 'page' => 1, 'mm' => 3500],
            ],
            [
                'page' => 1,
                'fills' => [],
                'walls' => [],
            ],
            [],
        );

        $this->assertNull($match['horizontal']);
        $this->assertNull($match['vertical']);
        $this->assertSame(0.0, $match['confidence']);
        $this->assertStringContainsString('maatketting', (string) ($match['rejected'][0]['reason'] ?? ''));
    }

    public function test_binds_dimension_labels_that_sit_further_from_large_raster_walls(): void
    {
        $match = (new WallBoundDimensionMatcher)->match(
            ['x' => 835.5, 'y' => 877.5, 'page' => 1, 'room_number' => 'A-00-03'],
            [
                ['text' => '3500', 'x' => 866.5, 'y' => 544.0, 'page' => 1, 'mm' => 3500],
                ['text' => '2000', 'x' => 199.0, 'y' => 891.5, 'page' => 1, 'mm' => 2000],
            ],
            [
                'page' => 1,
                'fills' => [],
                'ticks' => [
                    ['x1' => 413.0, 'y1' => 544.0, 'x2' => 1393.0, 'y2' => 544.0, 'axis' => 'h'],
                    ['x1' => 199.0, 'y1' => 613.0, 'x2' => 199.0, 'y2' => 1170.0, 'axis' => 'v'],
                ],
                'walls' => [
                    ['x1' => 413.0, 'y1' => 1168.0, 'x2' => 1393.0, 'y2' => 1168.0, 'axis' => 'h'],
                    ['x1' => 413.0, 'y1' => 613.0, 'x2' => 1393.0, 'y2' => 613.0, 'axis' => 'h'],
                    ['x1' => 413.0, 'y1' => 608.0, 'x2' => 413.0, 'y2' => 1170.0, 'axis' => 'v'],
                    ['x1' => 1391.0, 'y1' => 608.0, 'x2' => 1391.0, 'y2' => 1170.0, 'axis' => 'v'],
                ],
            ],
            [],
        );

        $this->assertSame(3500, $match['horizontal']['mm'] ?? null);
        $this->assertSame(2000, $match['vertical']['mm'] ?? null);
        $this->assertSame(0.9, $match['confidence']);
    }

    public function test_uses_dimension_chains_when_a_closed_contour_is_rejected(): void
    {
        $match = (new WallBoundDimensionMatcher)->match(
            ['x' => 320.0, 'y' => 440.0, 'page' => 1, 'room_key' => 's1', 'text' => 'SLAAPKAMER 1'],
            [
                ['text' => '3000', 'x' => 320.0, 'y' => 660.0, 'page' => 1, 'mm' => 3000],
                ['text' => '3500', 'x' => 120.0, 'y' => 440.0, 'page' => 1, 'mm' => 3500],
                ['text' => '10600', 'x' => 450.0, 'y' => 700.0, 'page' => 1, 'mm' => 10600],
                ['text' => '8000', 'x' => 30.0, 'y' => 440.0, 'page' => 1, 'mm' => 8000],
            ],
            $this->chainPage(),
            [
                ['x' => 320.0, 'y' => 440.0, 'page' => 1, 'room_key' => 's1'],
                ['x' => 560.0, 'y' => 430.0, 'page' => 1, 'room_key' => 's2'],
            ],
        );

        $this->assertSame(3000, $match['horizontal']['mm'] ?? null);
        $this->assertSame(3500, $match['vertical']['mm'] ?? null);
        $this->assertSame('chain', $match['horizontal']['source'] ?? null);
        $this->assertSame('chain', $match['vertical']['source'] ?? null);
        $this->assertSame(0.9, $match['confidence']);
        $this->assertContains(10600, array_column($match['rejected'], 'mm'));
        $this->assertContains(8000, array_column($match['rejected'], 'mm'));
        $this->assertTrue(
            str_contains((string) (collect($match['rejected'])->firstWhere('mm', 10600)['reason'] ?? ''), 'overspant')
            || str_contains((string) (collect($match['rejected'])->firstWhere('mm', 10600)['reason'] ?? ''), 'endpoints')
            || str_contains((string) (collect($match['rejected'])->firstWhere('mm', 10600)['reason'] ?? ''), 'wandassen'),
        );
    }

    public function test_rejects_a_short_dimension_segment_that_does_not_meet_both_room_walls(): void
    {
        $match = (new WallBoundDimensionMatcher)->match(
            ['x' => 850.0, 'y' => 330.0, 'page' => 1, 'room_key' => 's2', 'text' => 'SLAAPKAMER 2'],
            [
                ['text' => '2400', 'x' => 860.0, 'y' => 100.0, 'page' => 1, 'mm' => 2400],
                ['text' => '3000', 'x' => 866.0, 'y' => 80.0, 'page' => 1, 'mm' => 3000],
                ['text' => '3300', 'x' => 1140.0, 'y' => 330.0, 'page' => 1, 'mm' => 3300],
            ],
            [
                'page' => 1,
                'width' => 1200.0,
                'height' => 900.0,
                'drawing_scale' => 50,
                'fills' => [],
                'walls' => [
                    ['x1' => 678.0, 'y1' => 198.0, 'x2' => 678.0, 'y2' => 470.0, 'axis' => 'v'],
                    ['x1' => 1055.0, 'y1' => 198.0, 'x2' => 1055.0, 'y2' => 470.0, 'axis' => 'v'],
                    ['x1' => 678.0, 'y1' => 198.0, 'x2' => 1055.0, 'y2' => 198.0, 'axis' => 'h'],
                    ['x1' => 678.0, 'y1' => 470.0, 'x2' => 1055.0, 'y2' => 470.0, 'axis' => 'h'],
                    ['x1' => 800.0, 'y1' => 100.0, 'x2' => 920.0, 'y2' => 100.0, 'axis' => 'h'],
                    ['x1' => 678.0, 'y1' => 80.0, 'x2' => 1055.0, 'y2' => 80.0, 'axis' => 'h'],
                    ['x1' => 1140.0, 'y1' => 198.0, 'x2' => 1140.0, 'y2' => 470.0, 'axis' => 'v'],
                ],
            ],
            [],
        );

        $this->assertSame(3000, $match['horizontal']['mm'] ?? null);
        $this->assertSame(3300, $match['vertical']['mm'] ?? null);
        $this->assertSame('chain', $match['horizontal']['source'] ?? null);
        $this->assertSame('chain', $match['vertical']['source'] ?? null);
        $this->assertContains(2400, array_column($match['rejected'], 'mm'));
        $rejected = collect($match['rejected'])->firstWhere('mm', 2400);
        $this->assertNotNull($rejected);
        $this->assertTrue(
            ($rejected['endpoints'] ?? '') === 'x=800–920'
            || str_contains((string) ($rejected['reason'] ?? ''), 'wandassen')
            || str_contains((string) ($rejected['reason'] ?? ''), 'endpoints'),
        );
        $this->assertSame('x=678–1055', $rejected['expected'] ?? null);
        $this->assertNotEmpty($match['dimension_debug']);
        $debug2400 = collect($match['dimension_debug'])->firstWhere('mm', 2400);
        $this->assertSame('afgewezen', $debug2400['decision'] ?? null);
    }

    public function test_does_not_treat_a_nearby_unproven_measure_as_the_room_width(): void
    {
        $match = (new WallBoundDimensionMatcher)->match(
            ['x' => 300.0, 'y' => 250.0, 'page' => 1, 'room_key' => 'woonkamer', 'text' => 'WOONKAMER'],
            [
                ['text' => '3600', 'x' => 300.0, 'y' => 50.0, 'page' => 1, 'mm' => 3600],
            ],
            [
                'page' => 1,
                'width' => 900.0,
                'height' => 700.0,
                'fills' => [],
                'walls' => [
                    ['x1' => 100.0, 'y1' => 100.0, 'x2' => 500.0, 'y2' => 100.0, 'axis' => 'h'],
                    ['x1' => 100.0, 'y1' => 400.0, 'x2' => 500.0, 'y2' => 400.0, 'axis' => 'h'],
                    ['x1' => 100.0, 'y1' => 100.0, 'x2' => 100.0, 'y2' => 400.0, 'axis' => 'v'],
                    ['x1' => 500.0, 'y1' => 100.0, 'x2' => 500.0, 'y2' => 400.0, 'axis' => 'v'],
                ],
            ],
            [],
        );

        $this->assertNull($match['horizontal']);
        $this->assertNull($match['vertical']);
        $this->assertContains(3600, array_column($match['rejected'], 'mm'));
        $this->assertSame(0.0, $match['confidence']);
    }

    /**
     * @return array<string, mixed>
     */
    private function chainPage(): array
    {
        return [
            'page' => 1,
            'width' => 1200.0,
            'height' => 900.0,
            'drawing_scale' => 50,
            'fills' => [],
            'ticks' => [],
            'walls' => [
                ['x1' => 200.0, 'y1' => 300.0, 'x2' => 270.0, 'y2' => 300.0, 'axis' => 'h'],
                ['x1' => 370.0, 'y1' => 300.0, 'x2' => 440.0, 'y2' => 300.0, 'axis' => 'h'],
                ['x1' => 200.0, 'y1' => 580.0, 'x2' => 440.0, 'y2' => 580.0, 'axis' => 'h'],
                ['x1' => 200.0, 'y1' => 300.0, 'x2' => 200.0, 'y2' => 580.0, 'axis' => 'v'],
                ['x1' => 440.0, 'y1' => 300.0, 'x2' => 440.0, 'y2' => 580.0, 'axis' => 'v'],
                ['x1' => 440.0, 'y1' => 300.0, 'x2' => 680.0, 'y2' => 300.0, 'axis' => 'h'],
                ['x1' => 440.0, 'y1' => 564.0, 'x2' => 680.0, 'y2' => 564.0, 'axis' => 'h'],
                ['x1' => 680.0, 'y1' => 300.0, 'x2' => 680.0, 'y2' => 564.0, 'axis' => 'v'],
                ['x1' => 200.0, 'y1' => 660.0, 'x2' => 440.0, 'y2' => 660.0, 'axis' => 'h'],
                ['x1' => 440.0, 'y1' => 660.0, 'x2' => 680.0, 'y2' => 660.0, 'axis' => 'h'],
                ['x1' => 40.0, 'y1' => 700.0, 'x2' => 888.0, 'y2' => 700.0, 'axis' => 'h'],
                ['x1' => 120.0, 'y1' => 300.0, 'x2' => 120.0, 'y2' => 580.0, 'axis' => 'v'],
                ['x1' => 760.0, 'y1' => 300.0, 'x2' => 760.0, 'y2' => 564.0, 'axis' => 'v'],
                ['x1' => 30.0, 'y1' => 100.0, 'x2' => 30.0, 'y2' => 740.0, 'axis' => 'v'],
            ],
        ];
    }
}
