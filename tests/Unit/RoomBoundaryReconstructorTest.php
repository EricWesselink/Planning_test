<?php

namespace Tests\Unit;

use App\Services\AreaWithoutM2Trial\RoomBoundaryReconstructor;
use Tests\TestCase;

class RoomBoundaryReconstructorTest extends TestCase
{
    public function test_closes_a_door_sized_gap_and_returns_a_rectangular_room(): void
    {
        $result = (new RoomBoundaryReconstructor)->reconstruct(
            ['x' => 215.0, 'y' => 590.0, 'page' => 1, 'room_key' => 'name:1:215:590:SLAAPKAMER 1', 'text' => 'SLAAPKAMER 1'],
            $this->doorGapPage(),
        );

        $this->assertNotNull($result['box']);
        $this->assertSame(100.0, $result['box']['left']);
        $this->assertSame(450.0, $result['box']['right']);
        $this->assertSame(500.0, $result['box']['bottom']);
        $this->assertSame(700.0, $result['box']['top']);
        $this->assertNotEmpty($result['closed_gaps']);
        $this->assertStringContainsString('deuropening', $result['closed_gaps'][0]);
        $this->assertStringContainsString('linkerwand', (string) $result['left']);
        $this->assertStringContainsString('rechterwand', (string) $result['right']);
        $this->assertNull($result['reason']);
    }

    public function test_ignores_a_short_interior_line_closer_than_the_main_wall(): void
    {
        $page = $this->doorGapPage();
        $page['walls'][] = ['x1' => 190.0, 'y1' => 560.0, 'x2' => 190.0, 'y2' => 620.0, 'axis' => 'v'];

        $result = (new RoomBoundaryReconstructor)->reconstruct(
            ['x' => 215.0, 'y' => 590.0, 'page' => 1, 'room_key' => 'slaapkamer', 'text' => 'SLAAPKAMER 1'],
            $page,
        );

        $this->assertSame(100.0, $result['left_pos']);
        $this->assertSame(450.0, $result['right_pos']);
    }

    public function test_does_not_close_a_large_unknown_hole(): void
    {
        $page = $this->doorGapPage();
        $page['walls'] = [
            ['x1' => 100.0, 'y1' => 500.0, 'x2' => 160.0, 'y2' => 500.0, 'axis' => 'h'],
            ['x1' => 420.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 500.0, 'axis' => 'h'],
            ['x1' => 100.0, 'y1' => 700.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'h'],
            ['x1' => 100.0, 'y1' => 500.0, 'x2' => 100.0, 'y2' => 700.0, 'axis' => 'v'],
            ['x1' => 450.0, 'y1' => 500.0, 'x2' => 450.0, 'y2' => 700.0, 'axis' => 'v'],
        ];

        $result = (new RoomBoundaryReconstructor)->reconstruct(
            ['x' => 290.0, 'y' => 590.0, 'page' => 1, 'room_key' => 'name:1:290:590:HAL'],
            $page,
        );

        $this->assertNull($result['box']);
        $this->assertStringContainsString('geen gesloten ruimtecontour', (string) $result['reason']);
    }

    public function test_does_not_merge_adjacent_rooms_when_another_name_is_inside_the_box(): void
    {
        $page = $this->doorGapPage();
        $page['walls'][] = ['x1' => 450.0, 'y1' => 500.0, 'x2' => 800.0, 'y2' => 500.0, 'axis' => 'h'];
        $page['walls'][] = ['x1' => 450.0, 'y1' => 700.0, 'x2' => 800.0, 'y2' => 700.0, 'axis' => 'h'];
        $page['walls'][] = ['x1' => 800.0, 'y1' => 500.0, 'x2' => 800.0, 'y2' => 700.0, 'axis' => 'v'];

        $result = (new RoomBoundaryReconstructor)->reconstruct(
            ['x' => 215.0, 'y' => 590.0, 'page' => 1, 'room_key' => 'slaapkamer'],
            $page,
            [
                ['x' => 215.0, 'y' => 590.0, 'page' => 1, 'room_key' => 'slaapkamer'],
                ['x' => 620.0, 'y' => 590.0, 'page' => 1, 'room_key' => 'woonkamer'],
            ],
        );

        $this->assertNotNull($result['box']);
        $this->assertSame(450.0, $result['box']['right']);
    }

    public function test_ignores_a_wall_thickness_pair_around_the_room_label(): void
    {
        $result = (new RoomBoundaryReconstructor)->reconstruct(
            ['x' => 615.5, 'y' => 466.5, 'page' => 1, 'room_key' => 'hal', 'text' => 'HAL'],
            $this->proefLikePage(),
            $this->proefLikeAnchors(),
        );

        $this->assertSame(400.0, $result['left_pos']);
        $this->assertSame(621.0, $result['right_pos']);
        $this->assertGreaterThan(80.0, (float) $result['right_pos'] - (float) $result['left_pos']);
    }

    public function test_finds_four_bedroom_walls_without_crossing_the_other_bedroom(): void
    {
        $page = $this->proefLikePage();
        $anchors = $this->proefLikeAnchors();
        $reconstructor = new RoomBoundaryReconstructor;

        $first = $reconstructor->reconstruct(
            ['x' => 945.3, 'y' => 620.5, 'page' => 1, 'room_key' => 's1', 'text' => 'SLAAPKAMER 1'],
            $page,
            $anchors,
        );
        $second = $reconstructor->reconstruct(
            ['x' => 945.0, 'y' => 333.5, 'page' => 1, 'room_key' => 's2', 'text' => 'SLAAPKAMER 2'],
            $page,
            $anchors,
        );

        $this->assertSame(621.0, $first['left_pos']);
        $this->assertSame(1100.0, $first['right_pos']);
        $this->assertSame(480.0, $first['bottom_pos']);
        $this->assertSame(746.0, $first['top_pos']);
        $this->assertNotNull($first['box']);
        $this->assertSame(621.0, $second['left_pos']);
        $this->assertSame(1100.0, $second['right_pos']);
        $this->assertSame(200.0, $second['bottom_pos']);
        $this->assertSame(480.0, $second['top_pos']);
        $this->assertNotNull($second['box']);
        $this->assertNotSame($first['top_pos'], $second['top_pos']);
        $this->assertSame($first['bottom_pos'], $second['top_pos']);
    }

    public function test_rebuilds_bedroom_walls_from_interrupted_collinear_segments(): void
    {
        $page = $this->fragmentedBedroomPage();
        $anchors = $this->proefLikeAnchors();
        $reconstructor = new RoomBoundaryReconstructor;

        $first = $reconstructor->reconstruct(
            ['x' => 945.3, 'y' => 620.5, 'page' => 1, 'room_key' => 's1', 'text' => 'SLAAPKAMER 1'],
            $page,
            $anchors,
        );
        $second = $reconstructor->reconstruct(
            ['x' => 945.0, 'y' => 333.5, 'page' => 1, 'room_key' => 's2', 'text' => 'SLAAPKAMER 2'],
            $page,
            $anchors,
        );

        $this->assertSame(621.0, $first['left_pos']);
        $this->assertSame(1100.0, $first['right_pos']);
        $this->assertSame(480.0, $first['bottom_pos']);
        $this->assertSame(746.0, $first['top_pos']);
        $this->assertSame(621.0, $second['left_pos']);
        $this->assertSame(1100.0, $second['right_pos']);
        $this->assertSame(200.0, $second['bottom_pos']);
        $this->assertSame(480.0, $second['top_pos']);
        $this->assertSame($first['bottom_pos'], $second['top_pos']);
        $this->assertNotEmpty($first['wall_debug']['vertical']);
        $this->assertNotEmpty($second['wall_debug']['horizontal']);
    }

    /**
     * Geometry inspired by OCR from proef.pdf: stacked bedrooms at x≈945, HAL on a thick wall.
     *
     * @return array<string, mixed>
     */
    private function proefLikePage(): array
    {
        return [
            'page' => 1,
            'width' => 1200.0,
            'height' => 900.0,
            'fills' => [],
            'ticks' => [
                ['x1' => 930.0, 'y1' => 500.0, 'x2' => 960.0, 'y2' => 500.0, 'axis' => 'h'],
            ],
            'walls' => [
                ['x1' => 400.0, 'y1' => 200.0, 'x2' => 400.0, 'y2' => 760.0, 'axis' => 'v'],
                ['x1' => 612.0, 'y1' => 200.0, 'x2' => 612.0, 'y2' => 760.0, 'axis' => 'v'],
                ['x1' => 621.0, 'y1' => 200.0, 'x2' => 621.0, 'y2' => 760.0, 'axis' => 'v'],
                ['x1' => 1100.0, 'y1' => 200.0, 'x2' => 1100.0, 'y2' => 760.0, 'axis' => 'v'],
                ['x1' => 621.0, 'y1' => 200.0, 'x2' => 1100.0, 'y2' => 200.0, 'axis' => 'h'],
                ['x1' => 621.0, 'y1' => 480.0, 'x2' => 1100.0, 'y2' => 480.0, 'axis' => 'h'],
                ['x1' => 621.0, 'y1' => 746.0, 'x2' => 1100.0, 'y2' => 746.0, 'axis' => 'h'],
                ['x1' => 40.0, 'y1' => 800.0, 'x2' => 1180.0, 'y2' => 800.0, 'axis' => 'h'],
                ['x1' => 400.0, 'y1' => 200.0, 'x2' => 621.0, 'y2' => 200.0, 'axis' => 'h'],
                ['x1' => 400.0, 'y1' => 700.0, 'x2' => 621.0, 'y2' => 700.0, 'axis' => 'h'],
            ],
        ];
    }

    /**
     * @return list<array{x: float, y: float, page: int, room_key: string}>
     */
    private function proefLikeAnchors(): array
    {
        return [
            ['x' => 615.5, 'y' => 466.5, 'page' => 1, 'room_key' => 'hal'],
            ['x' => 945.3, 'y' => 620.5, 'page' => 1, 'room_key' => 's1'],
            ['x' => 945.0, 'y' => 333.5, 'page' => 1, 'room_key' => 's2'],
        ];
    }

    /**
     * Same axes as the real proef.pdf bedrooms, but only broken ink segments like a scan.
     *
     * @return array<string, mixed>
     */
    private function fragmentedBedroomPage(): array
    {
        return [
            'page' => 1,
            'width' => 1200.0,
            'height' => 900.0,
            'fills' => [],
            'ticks' => [],
            'walls' => [
                ['x1' => 400.0, 'y1' => 200.0, 'x2' => 400.0, 'y2' => 760.0, 'axis' => 'v'],
                ['x1' => 612.0, 'y1' => 200.0, 'x2' => 612.0, 'y2' => 760.0, 'axis' => 'v'],
                ['x1' => 621.0, 'y1' => 560.0, 'x2' => 621.0, 'y2' => 740.0, 'axis' => 'v'],
                ['x1' => 1100.0, 'y1' => 688.0, 'x2' => 1100.0, 'y2' => 760.0, 'axis' => 'v'],
                ['x1' => 1100.0, 'y1' => 200.0, 'x2' => 1100.0, 'y2' => 292.0, 'axis' => 'v'],
                ['x1' => 621.0, 'y1' => 200.0, 'x2' => 900.0, 'y2' => 200.0, 'axis' => 'h'],
                ['x1' => 621.0, 'y1' => 480.0, 'x2' => 850.0, 'y2' => 480.0, 'axis' => 'h'],
                ['x1' => 621.0, 'y1' => 746.0, 'x2' => 880.0, 'y2' => 746.0, 'axis' => 'h'],
                ['x1' => 40.0, 'y1' => 800.0, 'x2' => 1180.0, 'y2' => 800.0, 'axis' => 'h'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function doorGapPage(): array
    {
        return [
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
                ['x1' => 280.0, 'y1' => 505.0, 'x2' => 310.0, 'y2' => 505.0, 'axis' => 'h'],
            ],
        ];
    }
}
