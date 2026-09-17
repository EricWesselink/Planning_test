<?php

namespace Tests\Unit;

use App\Enums\CheckStatus;
use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\CalculationLine;
use App\Services\QuoteCalculation\CalculationRoomRows;
use App\Services\QuoteCalculation\PlinthLengthCalculator;
use Tests\TestCase;

class CalculationRoomRowsTest extends TestCase
{
    public function test_puts_floor_and_plinth_of_the_same_room_on_one_row(): void
    {
        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-01', 'RECREATIE', 'v01.d', 78.9, WorkUnit::SquareMeter, QuantitySource::FromDrawing),
            $this->line(2, 'A-00-01', 'RECREATIE', 'pl01', null, WorkUnit::LinearMeter, QuantitySource::Review),
        ]));

        $this->assertSame(1, $table['room_count']);
        $this->assertSame(CheckStatus::Certain, $table['rows'][0]['status']);
        $this->assertFalse($table['rows'][0]['needs_review']);
        $this->assertFalse($table['rows'][0]['can_confirm']);
        $this->assertTrue($table['ready_for_excel']);
        $this->assertSame(1, $table['floor_linked_count']);
        $this->assertSame(1, $table['plinth_linked_count']);
        $this->assertSame('v01.d', $table['rows'][0]['floor']?->product_code);
        $this->assertSame('pl01', $table['rows'][0]['plinth']?->product_code);
    }

    public function test_keeps_a_complete_room_ready_when_a_local_patch_has_no_area_yet(): void
    {
        $local = $this->line(3, 'A-00-01', 'RECREATIE', 'v09', null, WorkUnit::SquareMeter, QuantitySource::Review, 'Schoonloopmat');
        $local->finish_role = FinishRole::Local;

        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-01', 'RECREATIE', 'v01.d', 78.9, WorkUnit::SquareMeter, QuantitySource::FromDrawing),
            $this->line(2, 'A-00-01', 'RECREATIE', 'pl01', 26.4, WorkUnit::LinearMeter, QuantitySource::Calculated, 'Aluminium plakplint'),
            $local,
        ]));

        $this->assertSame(1, $table['room_count']);
        $this->assertSame(CheckStatus::Certain, $table['rows'][0]['status']);
        $this->assertSame('v01.d', $table['rows'][0]['floor']?->product_code);
        $this->assertCount(2, $table['rows'][0]['floors']);
        $this->assertTrue($table['ready_for_excel']);
    }

    public function test_keeps_two_floor_finishes_of_the_same_room_on_one_row(): void
    {
        $main = $this->line(1, 'A-00-01', 'RECREATIE', 'v01.d', 74.7, WorkUnit::SquareMeter, QuantitySource::FromDrawing);
        $main->finish_role = FinishRole::Main;
        $main->room_area = 78.9;
        $local = $this->line(3, 'A-00-01', 'RECREATIE', 'v09', 4.2, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Schoonloopmat');
        $local->finish_role = FinishRole::Local;
        $local->room_area = 78.9;
        $table = (new CalculationRoomRows)->table(collect([
            $main,
            $local,
            $this->line(2, 'A-00-01', 'RECREATIE', 'pl01', null, WorkUnit::LinearMeter, QuantitySource::Review),
        ]));

        $this->assertSame(1, $table['room_count']);
        $this->assertCount(2, $table['rows'][0]['floors']);
        $this->assertEqualsWithDelta(78.9, (float) $table['square_meters'], 0.001);
        $this->assertSame('v01.d', $table['rows'][0]['floor']?->product_code);
    }

    public function test_marks_a_room_without_floor_finish_as_not_applicable(): void
    {
        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-13', 'TOILET', null, 3.7, WorkUnit::SquareMeter, QuantitySource::Review),
        ]));

        $this->assertSame(CheckStatus::NotApplicable, $table['rows'][0]['status']);
        $this->assertSame('Niet van toepassing', $table['rows'][0]['status_label']);
        $this->assertFalse($table['rows'][0]['needs_review']);
        $this->assertSame(0, $table['blocking_count']);
        $this->assertSame(0, $table['certain_count']);
    }

    public function test_treats_a_complete_drawing_combo_as_certain_and_ready_for_excel(): void
    {
        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-13', 'MIVA T', 'v04', 3.7, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Gietvloer'),
            $this->line(2, 'A-00-13', 'MIVA T', 'pl02', 7.72, WorkUnit::LinearMeter, QuantitySource::Calculated, 'Holplint'),
        ]));

        $this->assertSame(CheckStatus::Certain, $table['rows'][0]['status']);
        $this->assertFalse($table['rows'][0]['can_confirm']);
        $this->assertFalse($table['rows'][0]['can_skip_plinth']);
        $this->assertTrue($table['ready_for_excel']);
        $this->assertSame(1, $table['certain_count']);
    }

    public function test_keeps_gietvloer_without_plinth_as_a_specific_review(): void
    {
        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-13', 'MIVA T', 'v04', 3.7, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Gietvloer'),
        ]));

        $this->assertSame(CheckStatus::Review, $table['rows'][0]['status']);
        $this->assertSame('Plintcode ontbreekt', $table['rows'][0]['status_label']);
        $this->assertNotSame('Ontbreekt', $table['rows'][0]['status_label']);
        $this->assertTrue($table['rows'][0]['can_skip_plinth']);
        $this->assertFalse($table['rows'][0]['plinth_not_applicable']);
        $this->assertSame(1, $table['floor_linked_count']);
        $this->assertSame(0, $table['plinth_linked_count']);
        $this->assertFalse($table['ready_for_excel']);
        $this->assertEqualsWithDelta(3.7, $table['square_meters'], 0.001);
    }

    public function test_offers_plinth_products_from_the_legend_when_gietvloer_has_no_plinth(): void
    {
        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-14', 'T', 'v04', 3.7, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Gietvloer v04'),
        ]), [
            ['code' => 'v04', 'product' => 'Gietvloer v04'],
            ['code' => 'pl01', 'product' => 'Aluminium plakplint'],
            ['code' => 'pl02', 'product' => 'Holplint'],
        ]);

        $this->assertSame(['pl01', 'pl02'], array_column($table['rows'][0]['plinth_options'], 'code'));
        $this->assertTrue($table['rows'][0]['can_skip_plinth']);
    }

    public function test_flags_a_found_plinth_code_without_product_as_review(): void
    {
        $plinth = $this->line(2, 'K-00-20', 'OPSLAG', 'pl01', 8.86, WorkUnit::LinearMeter, QuantitySource::FromDrawing, null);

        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'K-00-20', 'OPSLAG', 'v01.g', 12.0, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Marmoleum'),
            $plinth,
        ]), [
            ['code' => 'pl01', 'product' => 'Aluminium plakplint'],
        ]);

        $this->assertSame(CheckStatus::Review, $table['rows'][0]['status']);
        $this->assertSame('Plintproduct ontbreekt', $table['rows'][0]['status_label']);
        $this->assertFalse($table['rows'][0]['can_skip_plinth']);
        $this->assertSame(['pl01'], array_column($table['rows'][0]['plinth_options'], 'code'));
    }

    public function test_marks_gietvloer_as_certain_when_no_plinth_is_applicable(): void
    {
        $floor = $this->line(1, 'A-00-13', 'MIVA T', 'v04', 3.7, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Gietvloer');
        $floor->plinth_not_applicable = true;

        $table = (new CalculationRoomRows)->table(collect([$floor]));

        $this->assertSame(CheckStatus::Certain, $table['rows'][0]['status']);
        $this->assertSame('Zeker · Geen plint van toepassing', $table['rows'][0]['status_label']);
        $this->assertFalse($table['rows'][0]['needs_review']);
        $this->assertTrue($table['rows'][0]['plinth_not_applicable']);
        $this->assertTrue($table['rows'][0]['can_skip_plinth']);
        $this->assertSame(0, $table['blocking_count']);
        $this->assertSame(0, $table['plinth_linked_count']);
        $this->assertSame(0, $table['plinth_meters_count']);
        $this->assertTrue($table['ready_for_excel']);
        $this->assertEqualsWithDelta(3.7, $table['square_meters'], 0.001);
    }

    public function test_marks_a_generous_plinth_as_calculated_wide_and_ready(): void
    {
        $plinth = $this->line(2, 'A-00-13', 'MIVA T', 'pl02', 12.84, WorkUnit::LinearMeter, QuantitySource::Calculated, 'Holplint');
        $plinth->note = '12,84 m¹ – volledige omtrek; deuropening niet afgetrokken.';
        $plinth->calculation_trace = json_encode([
            'meters' => 12.84,
            'source' => QuantitySource::Calculated->value,
            'trace' => '12,84 m¹ – volledige omtrek; deuropening niet afgetrokken.',
            'status' => PlinthLengthCalculator::STATUS_GENEROUS,
            'gross' => 12.84,
            'doors' => [],
            'net' => 12.84,
        ], JSON_UNESCAPED_UNICODE);

        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-13', 'MIVA T', 'v04', 3.7, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Gietvloer'),
            $plinth,
        ]));

        $this->assertSame(CheckStatus::Generous, $table['rows'][0]['status']);
        $this->assertFalse($table['rows'][0]['needs_review']);
        $this->assertTrue($table['ready_for_excel']);
        $this->assertSame(0, $table['certain_count']);
        $this->assertSame(1, $table['generous_count']);
        $this->assertStringContainsString('volledige omtrek', (string) $table['rows'][0]['trace']);
        $this->assertSame(1, $table['plinth_meters_count']);
        $this->assertSame(1, $table['plinth_generous_count']);
        $this->assertSame(0, $table['plinth_estimated_count']);
        $this->assertSame(0, $table['plinth_missing_meters_count']);
    }

    public function test_marks_an_estimated_plinth_as_geschat_ruim_and_ready(): void
    {
        $plinth = $this->line(2, 'A-00-01', 'RECREATIE', 'pl01', 26.4, WorkUnit::LinearMeter, QuantitySource::Calculated, 'Aluminium plakplint');
        $plinth->calculation_trace = json_encode([
            'meters' => 26.4,
            'source' => QuantitySource::Calculated->value,
            'trace' => '26,40 m¹ – contour niet volledig herkenbaar; ruime calculatieschatting.',
            'status' => PlinthLengthCalculator::STATUS_ESTIMATED,
            'gross' => 26.4,
            'doors' => [],
            'net' => 26.4,
        ], JSON_UNESCAPED_UNICODE);

        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-01', 'RECREATIE', 'v01.d', 78.9, WorkUnit::SquareMeter, QuantitySource::FromDrawing),
            $plinth,
        ]));

        $this->assertSame(CheckStatus::Estimated, $table['rows'][0]['status']);
        $this->assertSame('Geschat ruim', $table['rows'][0]['status_label']);
        $this->assertFalse($table['rows'][0]['needs_review']);
        $this->assertTrue($table['ready_for_excel']);
        $this->assertSame(1, $table['estimated_count']);
        $this->assertSame(1, $table['plinth_estimated_count']);
        $this->assertSame(0, $table['plinth_missing_meters_count']);
    }

    public function test_counts_excel_confirmation_when_the_code_matches(): void
    {
        $floor = $this->line(1, 'A-00-01', 'RECREATIE', 'v01.d', 78.9, WorkUnit::SquareMeter, QuantitySource::FromDrawing);
        $floor->excel_product_code = 'v01.d';
        $floor->excel_quantity = 78.9;

        $table = (new CalculationRoomRows)->table(collect([$floor]));

        $this->assertSame(1, $table['excel_confirmed_count']);
        $this->assertFalse($table['rows'][0]['excel_conflict']);
        $this->assertFalse($table['rows'][0]['excel_area_mismatch']);
        $this->assertFalse($table['rows'][0]['excel_code_conflict']);
    }

    public function test_flags_an_excel_quantity_that_differs_from_the_drawing(): void
    {
        $floor = $this->line(1, 'A-00-13', 'MIVA T', 'v04', 3.7, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Gietvloer');
        $floor->excel_product_code = 'v04';
        $floor->excel_quantity = 5.0;

        $table = (new CalculationRoomRows)->table(collect([$floor]));

        $this->assertTrue($table['rows'][0]['excel_conflict']);
        $this->assertFalse($table['rows'][0]['excel_area_mismatch']);
        $this->assertEqualsWithDelta(5.0, (float) $table['rows'][0]['excel_quantity'], 0.001);
        $this->assertSame(1, $table['excel_confirmed_count']);
    }

    public function test_flags_a_tenfold_excel_area_as_a_possible_wrong_room(): void
    {
        $floor = $this->line(1, 'M-00-04', 'Magazijn', 'v07', 250.0, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Coating');
        $floor->excel_product_code = 'v07';
        $floor->excel_quantity = 25.0;

        $table = (new CalculationRoomRows)->table(collect([$floor]));

        $this->assertTrue($table['rows'][0]['excel_conflict']);
        $this->assertTrue($table['rows'][0]['excel_area_mismatch']);
        $this->assertSame(CheckStatus::Certain, $table['rows'][0]['status']);
        $this->assertSame(1, $table['excel_confirmed_count']);
    }

    public function test_allows_confirm_when_a_complete_combo_still_needs_review(): void
    {
        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-13', 'MIVA T', 'v04', 3.7, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Gietvloer'),
            $this->line(2, 'A-00-13', 'MIVA T', 'pl02', 7.72, WorkUnit::LinearMeter, QuantitySource::Manual, 'Holplint'),
        ]));

        $this->assertSame(CheckStatus::Review, $table['rows'][0]['status']);
        $this->assertTrue($table['rows'][0]['can_confirm']);
        $this->assertFalse($table['ready_for_excel']);
    }

    public function test_flags_only_the_missing_v01_variant_on_an_otherwise_complete_room(): void
    {
        $floor = $this->line(1, 'A-00-04', 'WK', 'v01', 4.0, WorkUnit::SquareMeter, QuantitySource::FromDrawing, null);
        $plinth = $this->line(2, 'A-00-04', 'WK', 'pl01', 8.86, WorkUnit::LinearMeter, QuantitySource::Calculated, 'Aluminium plakplint');

        $table = (new CalculationRoomRows)->table(collect([$floor, $plinth]));

        $this->assertSame(CheckStatus::Review, $table['rows'][0]['status']);
        $this->assertSame('Exacte v01-variant ontbreekt', $table['rows'][0]['status_label']);
        $this->assertNotSame('Ontbreekt', $table['rows'][0]['status_label']);
        $this->assertTrue($table['rows'][0]['needs_review']);
        $this->assertFalse($table['rows'][0]['can_confirm']);
        $this->assertFalse($table['rows'][0]['can_skip_plinth']);
        $this->assertSame('v01', $table['rows'][0]['floor']?->product_code);
        $this->assertSame('pl01', $table['rows'][0]['plinth']?->product_code);
        $this->assertSame([], $table['rows'][0]['floor_variants']);
        $this->assertEqualsWithDelta(4.0, $table['square_meters'], 0.001);
    }

    public function test_offers_sorted_v01_legend_options_when_the_exact_variant_is_missing(): void
    {
        $floor = $this->line(1, 'A-00-04', 'WK', 'v01', 4.0, WorkUnit::SquareMeter, QuantitySource::FromDrawing, null);
        $floor->note = 'Exacte v01-variant ontbreekt.';
        $plinth = $this->line(2, 'A-00-04', 'WK', 'pl01', 8.86, WorkUnit::LinearMeter, QuantitySource::Calculated, 'Aluminium plakplint');

        $table = (new CalculationRoomRows)->table(collect([$floor, $plinth]), [
            ['code' => 'v01.b', 'product' => 'Marmoleum - Forbo 3753'],
            ['code' => 'v01.a', 'product' => 'Marmoleum - Forbo 3732-3725'],
            ['code' => 'v04', 'product' => 'Gietvloer v.z.v. matte coating'],
            ['code' => 'pl01', 'product' => 'Aluminium plakplint'],
        ]);

        $this->assertSame(['v01.a', 'v01.b'], array_column($table['rows'][0]['floor_variants'], 'code'));
        $this->assertSame('Marmoleum - Forbo 3732-3725', $table['rows'][0]['floor_variants'][0]['product']);
        $this->assertSame('Exacte v01-variant ontbreekt', $table['rows'][0]['status_label']);
        $this->assertFalse($table['rows'][0]['can_confirm']);
    }

    public function test_does_not_offer_variant_options_for_a_resolved_v01_floor(): void
    {
        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-00-01', 'RECREATIE', 'v01.d', 78.9, WorkUnit::SquareMeter, QuantitySource::FromDrawing, 'Marmoleum - Forbo 3430'),
        ]), [
            ['code' => 'v01.a', 'product' => 'Marmoleum - Forbo 3732-3725'],
            ['code' => 'v01.d', 'product' => 'Marmoleum - Forbo 3430'],
        ]);

        $this->assertSame([], $table['rows'][0]['floor_variants']);
        $this->assertSame(CheckStatus::Certain, $table['rows'][0]['status']);
    }

    public function test_keeps_rooms_without_floor_work_out_of_review(): void
    {
        $table = (new CalculationRoomRows)->table(collect([
            $this->line(1, 'A-02-01', 'INST prefab', null, 281.2, WorkUnit::SquareMeter, QuantitySource::FromDrawing, null),
            $this->line(2, 'A-00-20', 'SCHACHT', null, 5.0, WorkUnit::SquareMeter, QuantitySource::FromDrawing, null),
            $this->line(3, 'K-00-08', 'MK', null, 1.2, WorkUnit::SquareMeter, QuantitySource::FromDrawing, null),
        ]));

        $this->assertSame(3, $table['room_count']);
        $this->assertSame(0, $table['blocking_count']);
        foreach ($table['rows'] as $row) {
            $this->assertSame(CheckStatus::NotApplicable, $row['status']);
            $this->assertFalse($row['needs_review']);
            $this->assertSame('Niet van toepassing', $row['status_label']);
        }
        $this->assertSame(0.0, $table['square_meters']);
        $this->assertFalse($table['ready_for_excel']);
    }

    private function line(
        int $id,
        string $number,
        string $name,
        ?string $code,
        ?float $quantity,
        WorkUnit $unit,
        QuantitySource $source,
        ?string $product = 'Marmoleum',
    ): CalculationLine {
        $line = new CalculationLine;
        $line->id = $id;
        $line->calculation_drawing_id = 1;
        $line->room_number = $number;
        $line->room_name = $name;
        $line->product_code = $code;
        $line->product = $code === null ? null : $product;
        $line->quantity = $quantity;
        $line->unit = $unit;
        $line->source = $source;

        return $line;
    }
}
