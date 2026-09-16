<?php

namespace Tests\Unit;

use App\Services\QuoteCalculation\SpatialRoomAssembler;
use Tests\TestCase;

class SpatialRoomAssemblerTest extends TestCase
{
    public function test_links_the_nearest_floor_code_to_the_room_and_skips_the_legend(): void
    {
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('SPEELLOKAAL', 1280, 1400),
                $this->item('A-00-12', 1299, 1430),
                $this->item('86.9', 1270, 1460),
                $this->item('m²', 1305, 1460),
                $this->item('v02', 1538, 1440),
                $this->item('pl01', 1548, 1475),
                $this->item('v01.g', 120, 2780),
                $this->item('=', 160, 2780),
                $this->item('Marmoleum', 220, 2780),
            ],
        ]];

        $rooms = (new SpatialRoomAssembler)->assemble($pages);

        $this->assertCount(1, $rooms);
        $this->assertSame('A-00-12', $rooms[0]['room_number']);
        $this->assertSame('SPEELLOKAAL', $rooms[0]['room_name']);
        $this->assertEqualsWithDelta(86.9, (float) $rooms[0]['square_meters'], 0.001);
        $this->assertSame('v02', $rooms[0]['floor_code']);
        $this->assertSame('pl01', $rooms[0]['plinth_code']);
        $this->assertFalse($rooms[0]['needs_review']);
    }

    public function test_ignores_scale_numbers_when_the_drawing_uses_building_room_codes(): void
    {
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('SPEELLOKAAL', 1280, 1400),
                $this->item('A-00-12', 1299, 1430),
                $this->item('86.9', 1270, 1460),
                $this->item('m²', 1305, 1460),
                $this->item('v02', 1538, 1440),
                $this->item('0.082', 400, 200),
                $this->item('v04', 420, 220),
            ],
        ]];

        $rooms = (new SpatialRoomAssembler)->assemble($pages);

        $this->assertCount(1, $rooms);
        $this->assertSame('A-00-12', $rooms[0]['room_number']);
        $this->assertSame('v02', $rooms[0]['floor_code']);
    }

    public function test_does_not_use_a_finish_code_as_the_room_name(): void
    {
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('v02', 1310, 1432),
                $this->item('A-00-12', 1299, 1430),
                $this->item('SPEELLOKAAL', 1280, 1400),
                $this->item('86.9', 1270, 1460),
                $this->item('m²', 1305, 1460),
            ],
        ]];

        $rooms = (new SpatialRoomAssembler)->assemble($pages);

        $this->assertSame('SPEELLOKAAL', $rooms[0]['room_name']);
        $this->assertSame('v02', $rooms[0]['floor_code']);
    }

    public function test_joins_stacked_room_name_fragments_by_position(): void
    {
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('VERKEER,', 1080, 760),
                $this->item('SPEEL,', 1090, 775),
                $this->item('BEWEGING', 1100, 790),
                $this->item('A-00-06', 1104, 788),
                $this->item('97.2', 1100, 800),
                $this->item('m²', 1120, 800),
                $this->item('v01.g', 1170, 790),
                $this->item('pl01', 1170, 805),
            ],
        ]];

        $rooms = (new SpatialRoomAssembler)->assemble($pages);

        $this->assertSame('A-00-06', $rooms[0]['room_number']);
        $this->assertSame('VERKEER, SPEEL, BEWEGING', $rooms[0]['room_name']);
        $this->assertSame('v01.g', $rooms[0]['floor_code']);
        $this->assertSame('pl01', $rooms[0]['plinth_code']);
    }

    /**
     * @return array{text: string, x: float, y: float, page: int}
     */
    private function item(string $text, float $x, float $y): array
    {
        return ['text' => $text, 'x' => $x, 'y' => $y, 'page' => 1];
    }
}
