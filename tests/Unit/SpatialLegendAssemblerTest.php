<?php

namespace Tests\Unit;

use App\Services\QuoteCalculation\SpatialLegendAssembler;
use Tests\TestCase;

class SpatialLegendAssemblerTest extends TestCase
{
    public function test_reads_products_to_the_right_of_legend_codes(): void
    {
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('v01.g', 2471, 701),
                $this->item('=', 2502, 701),
                $this->item('Marmoleum', 2541, 701),
                $this->item('Forbo', 2597, 701),
                $this->item('3752', 2630, 701),
                $this->item('v02', 2466, 750),
                $this->item('=', 2502, 750),
                $this->item('Marmoleum', 2541, 750),
                $this->item('sportvloer', 2608, 750),
                $this->item('v02', 1534, 961),
            ],
        ]];

        $legend = collect((new SpatialLegendAssembler)->assemble($pages))->keyBy('code');

        $this->assertSame('Marmoleum Forbo 3752', $legend['v01.g']['product']);
        $this->assertSame('Marmoleum sportvloer', $legend['v02']['product']);
        $this->assertSame('floor', $legend['v02']['kind']);
    }

    public function test_stops_the_product_at_the_next_legend_code_on_the_same_row(): void
    {
        $pages = [[
            'page' => 1,
            'width' => 1684.0,
            'height' => 2979.0,
            'texts' => [
                $this->item('pl01', 2470, 820),
                $this->item('=', 2502, 820),
                $this->item('Aluminium', 2541, 820),
                $this->item('plakplint', 2610, 820),
                $this->item('pl02', 2700, 820),
                $this->item('=', 2732, 820),
                $this->item('Holplint', 2770, 820),
            ],
        ]];

        $legend = collect((new SpatialLegendAssembler)->assemble($pages))->keyBy('code');

        $this->assertSame('Aluminium plakplint', $legend['pl01']['product']);
        $this->assertSame('Holplint', $legend['pl02']['product']);
    }

    /**
     * @return array{text: string, x: float, y: float, page: int}
     */
    private function item(string $text, float $x, float $y): array
    {
        return ['text' => $text, 'x' => $x, 'y' => $y, 'page' => 1];
    }
}
