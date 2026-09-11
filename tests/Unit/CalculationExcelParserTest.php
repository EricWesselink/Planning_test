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

        $this->assertTrue((new CalculationExcelParser)->looksLike($rows));

        $parsed = (new CalculationExcelParser)->parse($rows, '11-ericwesselink.xlsx');

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
        $parsed = (new CalculationExcelParser)->parse([
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

    public function test_does_not_guess_quantities_when_multiple_floor_articles_share_a_production_description(): void
    {
        $parsed = (new CalculationExcelParser)->parse([
            ['KM', 'Groep', 'M/U', 'Artikelnr.', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['L', '100', 'U', '4843', 'Leveren en leggen PVC', 'Elastische vloerbedekking', '10', 'uur', '48', '480'],
            ['M', '100', 'M', 'FOR5230', 'Leveren en leggen PVC', 'IVC Ultimo Trasimeno 46906 PVC tegels', '178.2', 'm2', '9', '1603.8'],
            ['L', '100', 'U', '4843', 'Leveren en leggen PVC', 'Elastische vloerbedekking', '12', 'uur', '48', '576'],
            ['M', '100', 'M', 'FOR5231', 'Leveren en leggen PVC', 'IVC Ultimo Chapman Oak 24245 pvc stroken', '213.84', 'm2', '9', '1924.56'],
        ], 'calc.xlsx');

        $this->assertCount(2, $parsed['labor']);
        $this->assertSame('review', $parsed['labor'][0]['quantity_status']);
        $this->assertSame('review', $parsed['labor'][1]['quantity_status']);
        $this->assertSame('uur', $parsed['labor'][0]['quantity_unit']);
        $this->assertSame(10.0, $parsed['labor'][0]['quantity']);
        $this->assertSame(12.0, $parsed['labor'][1]['quantity']);
    }

    public function test_links_preparation_labor_to_hard_floor_m2_in_the_same_group(): void
    {
        $parsed = (new CalculationExcelParser)->parse([
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

        $this->assertFalse((new CalculationExcelParser)->looksLike($rows));
    }
}
