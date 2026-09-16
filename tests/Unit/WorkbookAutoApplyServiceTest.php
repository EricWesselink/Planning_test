<?php

namespace Tests\Unit;

use App\Models\CalculationWorkbook;
use App\Services\QuoteCalculation\WorkbookAutoApplyService;
use Tests\TestCase;

class WorkbookAutoApplyServiceTest extends TestCase
{
    public function test_skips_wall_sheets_and_keeps_only_certain_floor_columns(): void
    {
        $workbook = new CalculationWorkbook;
        $workbook->analysis = [
            'skippable' => false,
            'sheets' => [
                ['name' => 'wand', 'skippable' => true, 'groups' => []],
                [
                    'name' => 'vloer',
                    'skippable' => false,
                    'header_row' => 0,
                    'groups' => [[
                        'columns' => ['room_number' => 0, 'floor_finish' => 1, 'room_name' => 2],
                        'confidence' => ['room_number' => 'certain', 'floor_finish' => 'certain', 'room_name' => 'review'],
                        'header_row' => 0,
                    ]],
                ],
            ],
        ];

        $mapping = (new WorkbookAutoApplyService)->mappingFromAnalysis($workbook);

        $this->assertFalse($mapping['skip'] ?? false);
        $this->assertTrue($mapping['sheets'][0]['skip']);
        $this->assertFalse($mapping['sheets'][1]['skip']);
        $this->assertSame(['room_number' => 0, 'floor_finish' => 1], $mapping['sheets'][1]['groups'][0]['columns']);
    }

    public function test_does_not_auto_apply_when_the_room_number_is_uncertain(): void
    {
        $workbook = new CalculationWorkbook;
        $workbook->analysis = [
            'skippable' => false,
            'sheets' => [[
                'name' => 'onbekend',
                'skippable' => false,
                'groups' => [[
                    'columns' => ['room_number' => 0, 'quantity' => 1],
                    'confidence' => ['room_number' => 'review', 'quantity' => 'certain'],
                ]],
            ]],
        ];

        $mapping = (new WorkbookAutoApplyService)->mappingFromAnalysis($workbook);

        $this->assertFalse($mapping['auto'] ?? true);
    }

    public function test_does_not_auto_apply_a_quantity_table_without_finish_codes(): void
    {
        $workbook = new CalculationWorkbook;
        $workbook->analysis = [
            'skippable' => false,
            'sheets' => [[
                'name' => 'oppervlaktes',
                'skippable' => false,
                'groups' => [[
                    'columns' => ['room_number' => 0, 'quantity' => 1],
                    'confidence' => ['room_number' => 'certain', 'quantity' => 'certain'],
                ]],
            ]],
        ];

        $mapping = (new WorkbookAutoApplyService)->mappingFromAnalysis($workbook);

        $this->assertFalse($mapping['auto'] ?? true);
    }

    public function test_auto_applies_quantity_when_a_section_title_has_a_finish_code(): void
    {
        $workbook = new CalculationWorkbook;
        $workbook->analysis = [
            'skippable' => false,
            'sheets' => [[
                'name' => 'vloer',
                'skippable' => false,
                'groups' => [[
                    'columns' => ['room_number' => 0, 'quantity' => 6],
                    'confidence' => ['room_number' => 'certain', 'quantity' => 'certain'],
                    'product_hint' => 'Gietvloer v04',
                ]],
            ]],
        ];

        $mapping = (new WorkbookAutoApplyService)->mappingFromAnalysis($workbook);

        $this->assertFalse($mapping['skip'] ?? false);
        $this->assertSame(['room_number' => 0, 'quantity' => 6], $mapping['sheets'][0]['groups'][0]['columns']);
        $this->assertSame('Gietvloer v04', $mapping['sheets'][0]['groups'][0]['product_hint']);
    }
}
