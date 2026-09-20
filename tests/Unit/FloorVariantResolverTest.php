<?php

namespace Tests\Unit;

use App\Services\QuoteCalculation\FloorVariantResolver;
use Tests\TestCase;

class FloorVariantResolverTest extends TestCase
{
    public function test_upgrades_a_family_code_when_one_variant_sits_at_the_room(): void
    {
        $rooms = [[
            'room_number' => 'A-00-04',
            'room_name' => 'WK',
            'square_meters' => 4.0,
            'floor_code' => 'v01',
            'floors' => [['code' => 'v01', 'quantity' => 4.0, 'role' => 'main']],
        ]];
        $legend = [
            ['code' => 'v01.a', 'product' => 'Marmoleum a', 'kind' => 'floor'],
            ['code' => 'v01.d', 'product' => 'Marmoleum d', 'kind' => 'floor'],
        ];
        $pages = [[
            'page' => 1,
            'texts' => [
                $this->item('A-00-04', 100, 100),
                $this->item('v01.d', 110, 108),
            ],
        ]];

        $resolved = (new FloorVariantResolver)->resolve($rooms, $legend, $pages);

        $this->assertSame('v01.d', $resolved[0]['floor_code']);
        $this->assertSame('v01.d', $resolved[0]['floors'][0]['code']);
    }

    public function test_does_not_guess_when_two_variants_are_equally_near(): void
    {
        $rooms = [[
            'room_number' => 'A-00-04',
            'floor_code' => 'v01',
            'floors' => [['code' => 'v01', 'quantity' => 4.0, 'role' => 'main']],
        ]];
        $legend = [
            ['code' => 'v01.a', 'product' => 'A', 'kind' => 'floor'],
            ['code' => 'v01.b', 'product' => 'B', 'kind' => 'floor'],
        ];
        $pages = [[
            'page' => 1,
            'texts' => [
                $this->item('A-00-04', 100, 100),
                $this->item('v01.a', 90, 100),
                $this->item('v01.b', 110, 100),
            ],
        ]];

        $resolved = (new FloorVariantResolver)->resolve($rooms, $legend, $pages);

        $this->assertSame('v01', $resolved[0]['floor_code']);
    }

    public function test_ignores_legend_column_variants(): void
    {
        $rooms = [[
            'room_number' => 'A-00-04',
            'floor_code' => 'v01',
            'floors' => [['code' => 'v01', 'quantity' => 4.0, 'role' => 'main']],
        ]];
        $legend = [
            ['code' => 'v01.d', 'product' => 'Marmoleum', 'kind' => 'floor'],
        ];
        $pages = [[
            'page' => 1,
            'texts' => [
                $this->item('A-00-04', 100, 100),
                $this->item('v01.d', 400, 100),
                $this->item('=', 430, 100),
                $this->item('Marmoleum', 460, 100),
            ],
        ]];

        $resolved = (new FloorVariantResolver)->resolve($rooms, $legend, $pages);

        $this->assertSame('v01', $resolved[0]['floor_code']);
    }

    public function test_keeps_an_exact_legend_code_even_when_a_variant_is_nearby(): void
    {
        $rooms = [[
            'room_number' => 'A-00-04',
            'room_name' => 'WK',
            'square_meters' => 12.4,
            'floor_code' => 'v01',
            'floors' => [['code' => 'v01', 'quantity' => 12.4, 'role' => 'main']],
        ]];
        $legend = [
            ['code' => 'v01', 'product' => 'Marmoleum - Forbo 3733', 'kind' => 'floor'],
            ['code' => 'v01.d', 'product' => 'Marmoleum d', 'kind' => 'floor'],
        ];
        $pages = [[
            'page' => 1,
            'texts' => [
                $this->item('A-00-04', 100, 100),
                $this->item('w01 / p01 / v01 / pl01', 100, 120),
                $this->item('v01.d', 110, 108),
            ],
        ]];

        $resolved = (new FloorVariantResolver)->resolve($rooms, $legend, $pages);

        $this->assertSame('v01', $resolved[0]['floor_code']);
        $this->assertSame('v01', $resolved[0]['floors'][0]['code']);
    }

    /**
     * @return array{text: string, x: float, y: float, page: int}
     */
    private function item(string $text, float $x, float $y): array
    {
        return ['text' => $text, 'x' => $x, 'y' => $y, 'page' => 1];
    }
}
