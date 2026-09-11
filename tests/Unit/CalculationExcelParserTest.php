<?php

namespace Tests\Unit;

use App\Services\CalculationExcelParser;
use App\Services\SpreadsheetReader;
use Tests\TestCase;

class CalculationExcelParserTest extends TestCase
{
    public function test_reads_labor_rows_from_the_ericwesselink_calculation(): void
    {
        $rows = (new SpreadsheetReader)->rows(
            base_path('tests/fixtures/11-ericwesselink.xlsx'),
            '11-ericwesselink.xlsx',
        );

        $this->assertTrue($this->parser()->looksLike($rows));

        $parsed = $this->parser()->parse($rows, '11-ericwesselink.xlsx');

        $this->assertTrue($parsed['matched']);
        $this->assertCount(6, $parsed['labor']);
        $this->assertSame('4843-1', $parsed['labor'][0]['group_code']);

        $first = $parsed['labor'][0];
        $this->assertSame('U', $first['mu']);
        $this->assertSame('uur', $first['unit']);
        $this->assertTrue($first['is_labor']);
        $this->assertSame('Schuren, primeren en egaliseren max. 2 mm (meerverbruik wordt doorberekend)', $first['production_description']);
        $this->assertEqualsWithDelta(30.064, $first['hours'], 0.001);
        $this->assertSame(48.0, $first['hourly_rate']);
        $this->assertEqualsWithDelta(1443.07, $first['labor_cost'], 0.02);
        $this->assertSame('linked', $first['quantity_status']);
        $this->assertSame('m2', $first['quantity_unit']);
        $this->assertEqualsWithDelta(392.04, $first['quantity'], 0.02);

        $pvc = collect($parsed['labor'])->first(
            fn (array $line): bool => str_contains((string) $line['production_description'], 'IVC Ultimo Trasimeno')
        );
        $this->assertNotNull($pvc);
        $this->assertSame('m2', $pvc['quantity_unit']);
        $this->assertSame('linked', $pvc['quantity_status']);
        $this->assertEqualsWithDelta(178.2, $pvc['quantity'], 0.01);
        $this->assertSame(48.0, $pvc['hourly_rate']);

        $chapman = collect($parsed['labor'])->first(
            fn (array $line): bool => str_contains((string) $line['production_description'], 'Chapman Oak')
        );
        $this->assertNotNull($chapman);
        $this->assertSame('linked', $chapman['quantity_status']);
        $this->assertEqualsWithDelta(213.84, $chapman['quantity'], 0.01);
        $this->assertNotEquals($pvc['quantity'], $chapman['quantity']);

        $material = collect($parsed['lines'])->first(
            fn (array $line): bool => ($line['article_description'] ?? '') === 'IVC Ultimo Trasimeno 46906 PVC tegels 33 x 66 cm'
        );
        $this->assertNotNull($material);
        $this->assertFalse($material['is_labor']);
        $this->assertSame('M', $material['mu']);
        $this->assertSame('m2', $material['unit']);
    }

    public function test_does_not_treat_material_or_subcontract_rows_as_labor(): void
    {
        $parsed = $this->parser()->parse([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['L', '100', 'U', 'Leveren en leggen PVC', 'Elastische vloerbedekking', '10', 'uur', '48', '480'],
            ['M', '100', 'M', 'Leveren en leggen PVC', 'PVC tegels', '80', 'm2', '9', '720'],
            ['O', '100', 'O', 'Leveren en aanbrengen hardschuimplinten', 'inkoop', '40', 'm1', '6.45', '258'],
            ['O', '100', 'O', 'kilometers', 'kilometers', '10', 'km', '0.21', '2.1'],
        ], 'calc.xlsx');

        $this->assertCount(1, $parsed['labor']);
        $this->assertSame(10.0, $parsed['labor'][0]['hours']);
        $this->assertSame(80.0, $parsed['labor'][0]['quantity']);
        $this->assertSame('m2', $parsed['labor'][0]['quantity_unit']);
        $this->assertSame('linked', $parsed['labor'][0]['quantity_status']);
        $this->assertCount(4, $parsed['lines']);
    }

    public function test_links_preparation_labor_to_hard_floor_m2_in_the_same_group(): void
    {
        $parsed = $this->parser()->parse([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['L', '100', 'U', 'Schuren, primeren en egaliseren max. 2 mm', 'Schuren, primeren en egaliseren', '30', 'uur', '48', '1440'],
            ['M', '100', 'M', 'Schuren, primeren en egaliseren max. 2 mm', 'ALM500 egalisatiemortel', '57', 'zak', '13.8', '786.6'],
            ['L', '100', 'U', 'Leveren en leggen PVC', 'Elastische vloerbedekking', '10', 'uur', '48', '480'],
            ['M', '100', 'M', 'Leveren en leggen PVC', 'PVC tegels', '200', 'm2', '9', '1800'],
            ['L', '100', 'U', 'Leveren en leggen tapijttegels', 'Zachte vloerbedekking', '5', 'uur', '48', '240'],
            ['M', '100', 'M', 'Leveren en leggen tapijttegels', 'Tapijttegels', '80', 'm2', '9', '720'],
            ['L', '100', 'U', 'Toeslag leggen proefkamer', '', '15', 'uur', '48', '720'],
        ], 'calc.xlsx');

        $prep = collect($parsed['labor'])->first(
            fn (array $line): bool => str_contains((string) $line['production_description'], 'egaliseren')
        );
        $toeslag = collect($parsed['labor'])->first(
            fn (array $line): bool => str_contains((string) $line['production_description'], 'Toeslag')
        );

        $this->assertNotNull($prep);
        $this->assertSame('linked', $prep['quantity_status']);
        $this->assertSame('m2', $prep['quantity_unit']);
        $this->assertEqualsWithDelta(200.0, $prep['quantity'], 0.01);
        $this->assertEqualsWithDelta(30.0, $prep['hours'], 0.01);

        $this->assertNotNull($toeslag);
        $this->assertSame('missing', $toeslag['quantity_status']);
        $this->assertSame('uur', $toeslag['quantity_unit']);
        $this->assertSame(15.0, $toeslag['quantity']);
    }

    public function test_does_not_match_a_screen_opdrachtlijst(): void
    {
        $rows = [
            ['Productie Eenheid Omschrijving', 'Aantal', 'EH'],
            ['Screen H: 1700 mm B: 960 mm', '12', 'st'],
        ];

        $this->assertFalse($this->parser()->looksLike($rows));
    }

    public function test_pairs_neighboring_floor_articles_when_labor_shares_a_generic_description(): void
    {
        $parsed = $this->parser()->parse([
            ['KM', 'Groep', 'M/U', 'Artikelnr.', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['L', '100', 'U', '4843', 'Elastische vloerbedekking', 'Elastische vloerbedekking', '10', 'uur', '48', '480'],
            ['M', '100', 'M', 'FOR5230', 'Elastische vloerbedekking', 'NovaFloor Real 1100 coral', '178.2', 'm2', '9', '1603.8'],
            ['L', '100', 'U', '4843', 'Elastische vloerbedekking', 'Elastische vloerbedekking', '12', 'uur', '48', '576'],
            ['M', '100', 'M', 'FOR5231', 'Elastische vloerbedekking', 'NovaFloor Sport 2200 move', '213.84', 'm2', '9', '1924.56'],
        ], 'calc.xlsx');

        $this->assertCount(2, $parsed['labor']);
        $this->assertSame('linked', $parsed['labor'][0]['quantity_status']);
        $this->assertSame('linked', $parsed['labor'][1]['quantity_status']);
        $this->assertSame('m2', $parsed['labor'][0]['quantity_unit']);
        $this->assertEqualsWithDelta(178.2, (float) $parsed['labor'][0]['quantity'], 0.01);
        $this->assertEqualsWithDelta(213.84, (float) $parsed['labor'][1]['quantity'], 0.01);
        $this->assertSame('NovaFloor Real 1100 coral', $parsed['labor'][0]['context_materials'][0]['label']);
        $this->assertSame('NovaFloor Sport 2200 move', $parsed['labor'][1]['context_materials'][0]['label']);
        $this->assertEqualsWithDelta(10.0, (float) $parsed['labor'][0]['hours'], 0.01);
    }

    public function test_does_not_turn_labor_hours_into_square_meters(): void
    {
        $parsed = $this->parser()->parse([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['L', '100', 'U', 'Onbekende extra werkzaamheid xyz', 'Onbekende extra werkzaamheid xyz', '80', 'uur', '48', '3840'],
        ], 'calc.xlsx');

        $this->assertSame('missing', $parsed['labor'][0]['quantity_status']);
        $this->assertSame('uur', $parsed['labor'][0]['quantity_unit']);
        $this->assertSame(80.0, $parsed['labor'][0]['quantity']);
        $this->assertSame(80.0, $parsed['labor'][0]['hours']);
    }

    private function parser(): CalculationExcelParser
    {
        return app(CalculationExcelParser::class);
    }
}
