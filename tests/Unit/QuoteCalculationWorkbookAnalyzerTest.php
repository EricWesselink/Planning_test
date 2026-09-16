<?php

namespace Tests\Unit;

use App\Services\QuoteCalculation\WorkbookAnalyzer;
use App\Services\QuoteCalculation\WorkbookColumnGuesser;
use App\Services\QuoteCalculation\WorkbookRowParser;
use App\Services\SpreadsheetReader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SimpleXlsx;
use Tests\TestCase;

class QuoteCalculationWorkbookAnalyzerTest extends TestCase
{
    #[DataProvider('headerLayouts')]
    public function test_maps_room_and_floor_columns_without_fixed_indexes(array $rows, string $roomHeader, string $floorHeader): void
    {
        $guess = (new WorkbookColumnGuesser)->guess($rows);

        $this->assertArrayHasKey('room_number', $guess['groups'][0]['columns']);
        $this->assertArrayHasKey('floor_finish', $guess['groups'][0]['columns']);
        $this->assertFalse($guess['skippable']);

        $roles = [];
        foreach ($guess['labels'] as $label) {
            $roles[$label['role']] = $label['header'];
        }
        $this->assertSame($roomHeader, $roles['room_number']);
        $this->assertSame($floorHeader, $roles['floor_finish']);
    }

    /** @return array<string, array{0: list<list<string>>, 1: string, 2: string}> */
    public static function headerLayouts(): array
    {
        return [
            'ruimte nr then vloer' => [[
                ['Ruimte nr.', 'Vloer', 'Hoeveelheid', 'Eenheid'],
                ['A-00-13', 'v04 Gietvloer', '3,70', 'm2'],
                ['A-00-14', 'v01 Marmoleum', '12,00', 'm2'],
            ], 'Ruimte nr.', 'Vloer'],
            'vloer first then english room number' => [[
                ['Vloer', 'Product', 'Room number', 'Qty'],
                ['v01', 'Marmoleum', '01.12', '24.5'],
                ['v01', 'Marmoleum', '01.13', '12'],
            ], 'Room number', 'Vloer'],
        ];
    }

    public function test_finds_headers_that_are_not_on_the_first_row(): void
    {
        $guess = (new WorkbookColumnGuesser)->guess([
            ['Kleur- en materiaalstaat gebouw A'],
            ['', ''],
            ['Ruimtenummer', 'Vloerafwerking', 'Oppervlakte'],
            ['K-00-06', 'v04', '8,50'],
            ['K-00-07', 'v01', '11,20'],
        ]);

        $this->assertSame(2, $guess['header_row']);
        $this->assertSame(0, $guess['groups'][0]['columns']['room_number']);
        $this->assertSame(1, $guess['groups'][0]['columns']['floor_finish']);
        $this->assertSame(2, $guess['groups'][0]['columns']['quantity']);
    }

    public function test_marks_a_wall_only_sheet_as_skippable(): void
    {
        $guess = (new WorkbookColumnGuesser)->guess([
            ['Wandafwerking'],
            ['Ruimte nr.', 'Afwerking', 'm2'],
            ['A-00-01', 'Sauswerk', '12,00'],
            ['A-00-02', 'Behang', '8,40'],
        ]);

        $this->assertTrue($guess['skippable']);
        $this->assertNotNull($guess['skip_reason']);
    }

    public function test_parses_mapped_columns_by_role_instead_of_position(): void
    {
        $rows = [
            ['Vloer', 'Room number', 'Qty'],
            ['v04 Gietvloer', '01.12', '30,00'],
        ];

        $lines = (new WorkbookRowParser)->parse($rows, [
            'groups' => [[
                'columns' => ['floor_finish' => 0, 'room_number' => 1, 'quantity' => 2],
                'header_row' => 0,
            ]],
        ]);

        $this->assertCount(1, $lines);
        $this->assertSame('01.12', $lines[0]['room_number']);
        $this->assertSame('v04', $lines[0]['floor_code']);
        $this->assertEqualsWithDelta(30.0, $lines[0]['quantity'], 0.001);
        $this->assertSame('kolom Qty, cel C2', $lines[0]['quantity_source']);
    }

    public function test_uses_a_computed_takeoff_area_instead_of_a_width_column(): void
    {
        $guess = (new WorkbookColumnGuesser)->guess([
            ['Gietvloer v04', '', '', '', '', ''],
            ['', '', 'L', 'B', 'n', ''],
            ['A-00-13', 'MIVA T', '1.7', '2.175', '1', '3.6975'],
            ['K-00-21', 'Toilet', '1.105', '1.5', '1', '1.6575'],
            ['K-00-31', 'Toilet', '1.1', '1.595', '1', '1.7545'],
        ]);

        $this->assertSame(5, $guess['groups'][0]['columns']['quantity'] ?? null);
        $this->assertSame('certain', $guess['groups'][0]['confidence']['quantity'] ?? null);
        $this->assertSame('Gietvloer v04', $guess['groups'][0]['product_hint'] ?? null);
        $this->assertArrayNotHasKey('floor_finish', $guess['groups'][0]['columns'] ?? []);
    }

    public function test_does_not_treat_a_section_title_column_as_per_room_finish(): void
    {
        $guess = (new WorkbookColumnGuesser)->guess([
            ['', 'Gietvloer v04', '', '', '', '', '', '', '', '', '', 'PU gietvloer v05'],
            ['A-00-13', 'MIVA T', '1.7', '2.175', '1', '', '3.6975', '', '', '', '', ''],
            ['K-00-21', 'Toilet', '1.105', '1.5', '1', '', '1.6575', '', '', '', '', ''],
            ['K-00-31', 'Toilet', '1.1', '1.595', '1', '', '1.7545', '', '', '', '', ''],
        ]);

        $this->assertArrayNotHasKey('floor_finish', $guess['groups'][0]['columns'] ?? []);
        $this->assertSame('Gietvloer v04', $guess['groups'][0]['product_hint'] ?? null);
        $this->assertSame(6, $guess['groups'][0]['columns']['quantity'] ?? null);
    }

    public function test_parses_machine_decimals_and_records_the_quantity_cell(): void
    {
        $lines = (new WorkbookRowParser)->parse([
            ['Ruimte nr.', 'Vloer', 'Hoeveelheid'],
            ['K-00-21', 'v04', '1.105'],
        ], [
            'groups' => [[
                'columns' => ['room_number' => 0, 'floor_finish' => 1, 'quantity' => 2],
                'header_row' => 0,
            ]],
        ]);

        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(1.105, $lines[0]['quantity'], 0.0001);
        $this->assertSame('kolom Hoeveelheid, cel C2', $lines[0]['quantity_source']);
    }

    public function test_treats_a_known_floor_header_with_finish_codes_as_certain(): void
    {
        $guess = (new WorkbookColumnGuesser)->guess([
            ['Ruimte nr.', 'Vloer', 'Hoeveelheid'],
            ['A-00-13', 'v04 Gietvloer', '3,70'],
            ['A-00-14', 'v01 Marmoleum', '12,00'],
        ]);

        $this->assertSame('certain', $guess['groups'][0]['confidence']['room_number'] ?? null);
        $this->assertSame('certain', $guess['groups'][0]['confidence']['floor_finish'] ?? null);
        $this->assertSame('certain', $guess['groups'][0]['confidence']['quantity'] ?? null);
    }

    public function test_treats_strong_cell_content_as_certain_when_headers_are_weak(): void
    {
        $guess = (new WorkbookColumnGuesser)->guess([
            ['Code', 'Naam', 'Afwerking', 'Opp'],
            ['A-00-12', 'SPEELLOKAAL', 'v02', '86,9'],
            ['A-00-01', 'RECREATIE', 'v01.d', '78,9'],
            ['A-00-06', 'VERKEER', 'v01.g', '97,2'],
        ]);

        $this->assertSame('certain', $guess['groups'][0]['confidence']['room_number'] ?? null);
        $this->assertSame('certain', $guess['groups'][0]['confidence']['floor_finish'] ?? null);
    }

    public function test_does_not_treat_a_repeated_product_code_column_as_per_room_finish(): void
    {
        $guess = (new WorkbookColumnGuesser)->guess([
            ['Ruimte nr.', 'Naam', 'v05', 'm2'],
            ['A-00-12', 'SPEELLOKAAL', 'v05', '86,9'],
            ['A-00-01', 'RECREATIE', 'v05', '78,9'],
            ['A-00-06', 'VERKEER', 'v05', '97,2'],
            ['A-00-07', 'HAL', 'v05', '12,0'],
            ['A-00-08', 'KAST', 'v05', '3,1'],
        ]);

        $this->assertArrayNotHasKey('floor_finish', $guess['groups'][0]['columns'] ?? []);
    }

    public function test_uses_remembered_headers_only_when_the_cell_content_agrees(): void
    {
        $guesser = (new WorkbookColumnGuesser)->withMemories([
            'code intern' => 'room_number',
        ]);

        $agrees = $guesser->guess([
            ['Code intern', 'Vloer'],
            ['A-00-13', 'v04'],
            ['A-00-14', 'v01'],
        ]);
        $this->assertSame(0, $agrees['groups'][0]['columns']['room_number'] ?? null);
        $this->assertSame('certain', $agrees['groups'][0]['confidence']['room_number'] ?? null);

        $rejects = $guesser->guess([
            ['Code intern', 'Vloer'],
            ['Marmoleum', 'v04'],
            ['PVC', 'v01'],
        ]);
        $this->assertArrayNotHasKey('room_number', $rejects['groups'][0]['columns'] ?? []);
    }

    public function test_keeps_a_workbook_usable_when_only_one_sheet_has_floor_data(): void
    {
        $path = SimpleXlsx::path([
            'wandafwerking' => [
                ['Wandafwerking'],
                ['Ruimte nr.', 'Afwerking', 'm2'],
                ['A-00-01', 'Sauswerk', '12,00'],
            ],
            'vloerafwerking' => [
                ['Ruimte nr.', 'Vloer', 'Hoeveelheid'],
                ['A-00-13', 'v04 Gietvloer', '3,70'],
            ],
        ]);

        $analysis = (new WorkbookAnalyzer(new SpreadsheetReader, new WorkbookColumnGuesser))->analyze($path, 'staat.xlsx');

        $this->assertFalse($analysis['skippable']);
        $this->assertTrue($analysis['sheets'][0]['skippable']);
        $this->assertFalse($analysis['sheets'][1]['skippable']);
        $this->assertSame('Ruimte nr.', collect($analysis['labels'])->firstWhere('role', 'room_number')['header'] ?? null);
    }
}
