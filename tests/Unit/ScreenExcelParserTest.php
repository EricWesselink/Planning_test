<?php

namespace Tests\Unit;

use App\Services\ScreenExcelParser;
use Tests\TestCase;

class ScreenExcelParserTest extends TestCase
{
    public function test_keeps_piece_rows_and_skips_matching_labor_hours(): void
    {
        $parsed = (new ScreenExcelParser)->parse([
            ['Groep', 'Productie Eenheid Omschrijving', 'Aantal', 'EH'],
            ['Screens', 'Screen H: 1700 mm B: 960 mm', '12', 'st'],
            ['Screens', 'Screen H: 1700 mm B: 960 mm', '4', 'uur'],
            ['BNR 11', 'BNR 11 Screen H: 1574 mm B: 770 mm', '2', 'st'],
            ['BNR 11', 'BNR 11 Screen H: 1574 mm B: 770 mm', '1', 'uur'],
        ]);

        $this->assertTrue($parsed['matched']);
        $this->assertTrue($parsed['ready']);
        $this->assertSame(2, $parsed['skipped_labor']);
        $this->assertSame(2, $parsed['processed_rows']);
        $this->assertSame(14.0, $parsed['total_pieces']);
        $this->assertCount(2, $parsed['lines']);
        $this->assertSame('Screen H: 1700 mm B: 960 mm', $parsed['lines'][0]['description']);
        $this->assertSame(12.0, $parsed['lines'][0]['quantity']);
        $this->assertSame('stuks', $parsed['lines'][0]['unit']);
        $this->assertSame('11', $parsed['lines'][1]['bnr']);
        $this->assertSame(2.0, $parsed['lines'][1]['quantity']);
        $this->assertSame('2 Excelregels verwerkt · 14 stuks · 0 niet herkend', $parsed['summary']);
    }

    public function test_merges_only_when_type_size_bnr_and_unit_match(): void
    {
        $parsed = (new ScreenExcelParser)->parse([
            ['Productie Eenheid Omschrijving', 'Aantal', 'EH'],
            ['Screen H: 1700 mm B: 960 mm', '6', 'st'],
            ['Screen H: 1700 mm B: 960 mm', '6', 'st'],
            ['BNR 11 Screen H: 1574 mm B: 770 mm', '2', 'st'],
            ['BNR 12 Screen H: 1574 mm B: 770 mm', '1', 'st'],
        ]);

        $this->assertCount(3, $parsed['lines']);
        $this->assertSame(12.0, $parsed['lines'][0]['quantity']);
        $this->assertSame(2.0, $parsed['lines'][1]['quantity']);
        $this->assertSame(1.0, $parsed['lines'][2]['quantity']);
        $this->assertSame('11', $parsed['lines'][1]['bnr']);
        $this->assertSame('12', $parsed['lines'][2]['bnr']);
    }

    public function test_does_not_match_a_floor_meetstaat(): void
    {
        $parser = new ScreenExcelParser;
        $rows = [
            ['nummer', 'naam', 'verdieping', 'm2', 'pvc'],
            ['01', 'Showroom', 'Begane grond', '860', '860'],
        ];

        $this->assertFalse($parser->looksLike($rows));
        $this->assertFalse($parser->parse($rows)['matched']);
    }

    public function test_does_not_match_a_floor_calculation_spreadsheet(): void
    {
        $parser = new ScreenExcelParser;
        $rows = [
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Aantal', 'EH', 'Kostprijs'],
            ['L', '4843-1', 'U', 'Schuren, primeren en egaliseren', '30', 'uur', '48'],
        ];

        $this->assertFalse($parser->looksLike($rows));
    }

    public function test_marks_unknown_units_and_missing_quantities_as_unrecognized(): void
    {
        $parsed = (new ScreenExcelParser)->parse([
            ['Productie Eenheid Omschrijving', 'Aantal', 'EH'],
            ['Screen H: 1700 mm B: 960 mm', '12', 'st'],
            ['Losse onderdelen', '3', 'm2'],
            ['Kapotte regel', '', 'st'],
        ]);

        $this->assertFalse($parsed['ready']);
        $this->assertCount(1, $parsed['lines']);
        $this->assertCount(2, $parsed['unrecognized']);
        $this->assertSame('Eenheid "m2" wordt niet als stuks ingelezen', $parsed['unrecognized'][0]['reason']);
        $this->assertSame('Geen geldig aantal', $parsed['unrecognized'][1]['reason']);
        $this->assertSame('1 Excelregels verwerkt · 12 stuks · 2 niet herkend', $parsed['summary']);
    }
}
