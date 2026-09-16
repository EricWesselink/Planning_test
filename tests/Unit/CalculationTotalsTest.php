<?php

namespace Tests\Unit;

use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\CalculationLine;
use App\Services\QuoteCalculation\CalculationTotals;
use Tests\TestCase;

class CalculationTotalsTest extends TestCase
{
    public function test_adds_the_same_product_code_across_rooms(): void
    {
        $totals = (new CalculationTotals)->grouped(collect([
            $this->line('v01.g', 'Marmoleum - Forbo 3752', 24.5, WorkUnit::SquareMeter),
            $this->line('v01.g', 'Marmoleum - Forbo 3752', 12.0, WorkUnit::SquareMeter),
            $this->line('pl01', 'Aluminium plakplint', 18.0, WorkUnit::LinearMeter),
            $this->line('v01.g', 'Marmoleum - Forbo 3752', null, WorkUnit::SquareMeter),
        ]));

        $floor = collect($totals)->first(fn (array $row) => $row['product_code'] === 'v01.g');
        $plinth = collect($totals)->first(fn (array $row) => $row['product_code'] === 'pl01');

        $this->assertEqualsWithDelta(36.5, $floor['quantity'], 0.001);
        $this->assertSame('m²', $floor['unit_label']);
        $this->assertEqualsWithDelta(18.0, $plinth['quantity'], 0.001);
        $this->assertSame('m¹', $plinth['unit_label']);
    }

    public function test_does_not_add_plinth_meters_when_no_plinth_applies(): void
    {
        $floor = $this->line('v04', 'Gietvloer', 3.7, WorkUnit::SquareMeter);
        $floor->room_number = 'A-00-13';
        $floor->plinth_not_applicable = true;
        $plinth = $this->line('pl02', 'Holplint', 7.72, WorkUnit::LinearMeter);
        $plinth->room_number = 'A-00-13';

        $totals = (new CalculationTotals)->grouped(collect([$floor, $plinth]));

        $this->assertCount(1, $totals);
        $this->assertSame('v04', $totals[0]['product_code']);
        $this->assertEqualsWithDelta(3.7, $totals[0]['quantity'], 0.001);
        $this->assertSame('m²', $totals[0]['unit_label']);
    }

    private function line(?string $code, ?string $product, ?float $quantity, WorkUnit $unit): CalculationLine
    {
        $line = new CalculationLine;
        $line->product_code = $code;
        $line->product = $product;
        $line->quantity = $quantity;
        $line->unit = $unit;
        $line->source = QuantitySource::FromDrawing;

        return $line;
    }
}
