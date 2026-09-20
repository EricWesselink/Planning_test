<?php

namespace Tests\Unit;

use App\Services\QuoteCalculation\SpatialFinishLinker;
use Tests\TestCase;

class SpatialFinishLinkerTest extends TestCase
{
    public function test_links_a_uniquely_nearest_code_beyond_the_default_window(): void
    {
        $rooms = [[
            'room_number' => 'A-00-12',
            'room_name' => 'SPEELLOKAAL',
            'square_meters' => 86.9,
            'floor_code' => null,
            'plinth_code' => null,
            'needs_review' => true,
        ]];
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('A-00-12', 100, 100),
                $this->item('v02', 100, 500),
            ],
        ]];

        $linked = (new SpatialFinishLinker)->link($rooms, '', $pages);

        $this->assertSame('v02', $linked[0]['floor_code']);
        $this->assertFalse($linked[0]['needs_review']);
    }

    public function test_keeps_a_second_floor_code_as_a_local_patch_with_its_own_area(): void
    {
        $rooms = [[
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'square_meters' => 78.9,
            'floor_code' => null,
            'plinth_code' => null,
            'needs_review' => true,
        ]];
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('A-00-01', 100, 100),
                $this->item('RECREATIE', 100, 80),
                $this->item('78.9', 100, 130),
                $this->item('m²', 130, 130),
                $this->item('v01.d', 140, 100),
                $this->item('v09', 220, 180),
                $this->item('4.2', 220, 200),
                $this->item('m²', 250, 200),
            ],
        ]];

        $linked = (new SpatialFinishLinker)->link($rooms, '', $pages);

        $this->assertSame('v01.d', $linked[0]['floor_code']);
        $floors = collect($linked[0]['floors'] ?? []);
        $this->assertCount(2, $floors);
        $main = $floors->firstWhere('role', 'main');
        $local = $floors->firstWhere('role', 'local');
        $this->assertSame('v01.d', $main['code']);
        $this->assertSame('v09', $local['code']);
        $this->assertEqualsWithDelta(4.2, (float) $local['quantity'], 0.001);
        $this->assertEqualsWithDelta(74.7, (float) $main['quantity'], 0.001);
        $this->assertLessThan(78.9, (float) $local['quantity']);
    }

    public function test_does_not_guess_when_two_rooms_are_equally_close_to_a_code(): void
    {
        $rooms = [
            [
                'room_number' => 'A-00-01',
                'room_name' => 'RECREATIE',
                'square_meters' => 78.9,
                'floor_code' => null,
                'plinth_code' => null,
                'needs_review' => true,
            ],
            [
                'room_number' => 'A-00-02',
                'room_name' => 'HAL',
                'square_meters' => 12.0,
                'floor_code' => null,
                'plinth_code' => null,
                'needs_review' => true,
            ],
        ];
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('A-00-01', 0, 0),
                $this->item('A-00-02', 200, 0),
                $this->item('v01.d', 100, 0),
            ],
        ]];

        $linked = (new SpatialFinishLinker)->link($rooms, '', $pages);

        $this->assertNull($linked[0]['floor_code']);
        $this->assertNull($linked[1]['floor_code']);
    }

    public function test_prefers_the_room_finish_symbol_over_a_closer_loose_floor_code(): void
    {
        $rooms = [[
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'square_meters' => 78.9,
            'floor_code' => null,
            'plinth_code' => null,
            'needs_review' => true,
        ]];
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('RECREATIE', 520, 776),
                $this->item('A-00-01', 503, 787),
                $this->item('78.9', 497, 797),
                $this->item('m²', 512, 797),
                $this->item('w01', 516, 842),
                $this->item('p01', 516, 851),
                $this->item('v01.d', 516, 858),
                $this->item('pl01', 516, 866),
                $this->item('v09', 448, 829),
            ],
        ]];

        $linked = (new SpatialFinishLinker)->link($rooms, '', $pages);

        $this->assertSame('v01.d', $linked[0]['floor_code']);
        $this->assertSame('pl01', $linked[0]['plinth_code']);
        $this->assertFalse($linked[0]['needs_review']);
        $this->assertSame('main', $linked[0]['floors'][0]['role']);
        $this->assertSame('v01.d', $linked[0]['floors'][0]['code']);
        $this->assertSame('local', $linked[0]['floors'][1]['role']);
        $this->assertSame('v09', $linked[0]['floors'][1]['code']);
        $this->assertNull($linked[0]['floors'][1]['quantity']);
    }

    public function test_does_not_let_a_prefilled_loose_code_override_the_finish_symbol(): void
    {
        $rooms = [[
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'square_meters' => 78.9,
            'floor_code' => 'v09',
            'plinth_code' => null,
            'needs_review' => false,
        ]];
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('A-00-01', 503, 787),
                $this->item('w01', 516, 842),
                $this->item('p01', 516, 851),
                $this->item('v01.d', 516, 858),
                $this->item('pl01', 516, 866),
                $this->item('v09', 448, 829),
            ],
        ]];

        $linked = (new SpatialFinishLinker)->link($rooms, '', $pages);

        $this->assertSame('v01.d', $linked[0]['floor_code']);
        $this->assertSame('pl01', $linked[0]['plinth_code']);
        $this->assertSame('v09', $linked[0]['floors'][1]['code'] ?? null);
        $this->assertSame('local', $linked[0]['floors'][1]['role'] ?? null);
    }

    public function test_keeps_a_measured_local_floor_as_its_own_area(): void
    {
        $rooms = [[
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'square_meters' => 78.9,
            'floor_code' => null,
            'plinth_code' => null,
            'needs_review' => true,
        ]];
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('A-00-01', 503, 787),
                $this->item('w01', 516, 842),
                $this->item('p01', 516, 851),
                $this->item('v01.d', 516, 858),
                $this->item('pl01', 516, 866),
                $this->item('v09', 448, 829),
                $this->item('2.4', 440, 840),
                $this->item('m²', 455, 840),
            ],
        ]];

        $linked = (new SpatialFinishLinker)->link($rooms, '', $pages);

        $this->assertSame('v01.d', $linked[0]['floor_code']);
        $this->assertEqualsWithDelta(2.4, (float) $linked[0]['floors'][1]['quantity'], 0.001);
        $this->assertEqualsWithDelta(76.5, (float) $linked[0]['floors'][0]['quantity'], 0.001);
        $this->assertEqualsWithDelta(78.9, (float) $linked[0]['square_meters'], 0.001);
    }

    public function test_uses_nearby_millimetre_sides_when_a_local_floor_has_no_square_metres(): void
    {
        $rooms = [[
            'room_number' => 'A-00-01',
            'room_name' => 'RECREATIE',
            'square_meters' => 78.9,
            'floor_code' => null,
            'plinth_code' => null,
            'needs_review' => true,
        ]];
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('RECREATIE', 520, 776),
                $this->item('A-00-01', 503, 787),
                $this->item('78.9', 497, 797),
                $this->item('m²', 512, 797),
                $this->item('w01', 516, 842),
                $this->item('p01', 516, 851),
                $this->item('v01.d', 516, 858),
                $this->item('pl01', 516, 866),
                $this->item('v09', 448, 829),
                $this->item('3020', 470, 754),
                $this->item('3240', 414, 754),
                $this->item('2000', 406, 675),
                $this->item('240', 430, 820),
                $this->item('260', 460, 820),
            ],
        ]];

        $linked = (new SpatialFinishLinker)->link($rooms, '', $pages);

        $this->assertSame('v09', $linked[0]['floors'][1]['code']);
        $this->assertEqualsWithDelta(6.04, (float) $linked[0]['floors'][1]['quantity'], 0.001);
        $this->assertEqualsWithDelta(72.86, (float) $linked[0]['floors'][0]['quantity'], 0.001);
        $this->assertEqualsWithDelta(78.9, (float) $linked[0]['square_meters'], 0.001);
    }

    public function test_ignores_a_door_mark_when_pairing_millimetre_sides_for_a_local_floor(): void
    {
        $rooms = [[
            'room_number' => 'A-00-18',
            'room_name' => 'ENTREE',
            'square_meters' => 31.9,
            'floor_code' => null,
            'plinth_code' => null,
            'needs_review' => true,
        ]];
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('ENTREE', 1699, 751),
                $this->item('A-00-18', 1692, 763),
                $this->item('31.9', 1686, 773),
                $this->item('m²', 1701, 773),
                $this->item('v01.g', 1695, 619),
                $this->item('pl01', 1695, 626),
                $this->item('v09', 1762, 674),
                $this->item('3190', 1686, 672),
                $this->item('3240', 1762, 820),
                $this->item('1900', 1791, 747),
                $this->item('dm', 1791, 764),
            ],
        ]];

        $linked = (new SpatialFinishLinker)->link($rooms, '', $pages);

        $this->assertSame('v09', $linked[0]['floors'][1]['code']);
        $this->assertEqualsWithDelta(10.34, (float) $linked[0]['floors'][1]['quantity'], 0.001);
        $this->assertEqualsWithDelta(21.56, (float) $linked[0]['floors'][0]['quantity'], 0.001);
    }

    public function test_skips_finish_codes_that_sit_in_a_legend_column(): void
    {
        $rooms = [[
            'room_number' => 'A-02-01',
            'room_name' => 'DAK',
            'square_meters' => 12.0,
            'floor_code' => null,
            'plinth_code' => null,
            'needs_review' => true,
        ]];
        $texts = [
            $this->item('A-02-01', 2000, 800),
        ];
        $y = 600.0;
        foreach (['v01.a', 'v01.b', 'v01.c', 'v01.d', 'v01.e', 'v01.f', 'v02', 'v03'] as $code) {
            $texts[] = $this->item($code, 2470, $y);
            $texts[] = $this->item('=', 2505, $y);
            $texts[] = $this->item('Marmoleum', 2560, $y);
            $y += 12;
        }

        $linked = (new SpatialFinishLinker)->link($rooms, '', [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => $texts,
        ]]);

        $this->assertNull($linked[0]['floor_code']);
    }

    public function test_uses_the_family_floor_from_the_finish_symbol_when_a_variant_sits_nearby(): void
    {
        $rooms = [[
            'room_number' => 'A-00-04',
            'room_name' => 'WK',
            'square_meters' => 12.4,
            'floor_code' => null,
            'plinth_code' => null,
            'needs_review' => true,
        ]];
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('WK', 500, 780),
                $this->item('A-00-04', 500, 790),
                $this->item('12.4', 500, 800),
                $this->item('m²', 520, 800),
                $this->item('w01', 516, 842),
                $this->item('p01', 516, 851),
                $this->item('v01', 516, 858),
                $this->item('pl01', 516, 866),
                $this->item('v01.d', 516, 872),
            ],
        ]];

        $linked = (new SpatialFinishLinker)->link($rooms, '', $pages);

        $this->assertSame('v01', $linked[0]['floor_code']);
        $this->assertSame('pl01', $linked[0]['plinth_code']);
        $this->assertFalse($linked[0]['needs_review']);
    }

    /**
     * @return array{text: string, x: float, y: float, page: int}
     */
    private function item(string $text, float $x, float $y): array
    {
        return ['text' => $text, 'x' => $x, 'y' => $y, 'page' => 1];
    }
}
