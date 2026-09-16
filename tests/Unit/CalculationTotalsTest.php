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
