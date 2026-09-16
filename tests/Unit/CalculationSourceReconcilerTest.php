<?php

namespace Tests\Unit;

use App\Services\CalculationExcelParser;
use App\Services\CalculationImportService;
use App\Services\CalculationSourceReconciler;
use App\Services\Meetstaat\Formats\NiconMeetbonParser;
use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\MaterialIdentity;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CalculationSourceReconcilerTest extends TestCase
{
    public function test_confirms_matching_quantities_per_product_and_color_across_sources(): void
    {
        $excel = app(CalculationExcelParser::class)->parse([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['L', '100', 'U', 'Elastische vloerbedekking', 'Elastische vloerbedekking', '20', 'uur', '48', '960'],
            ['M', '100', 'M', 'Elastische vloerbedekking', 'NovaFloor Real 1100 coral, Linoleum', '120.5', 'm2', '9', '1084.5'],
            ['L', '100', 'U', 'Elastische vloerbedekking', 'Elastische vloerbedekking', '8', 'uur', '48', '384'],
            ['M', '100', 'M', 'Elastische vloerbedekking', 'NovaFloor Walton 2200 pine, Linoleum', '40.25', 'm2', '9', '362.25'],
        ], 'calc.xlsx');

        $preview = [
            'header' => ['project_number' => '260200099'],
            'works' => [
                ['name' => 'NovaFloor Real 1100 coral, Linoleum', 'unit' => 'm2', 'declared_total' => 120.5],
                ['name' => 'NovaFloor Walton 2200 pine, Linoleum', 'unit' => 'm2', 'declared_total' => 40.25],
            ],
            'closure_baselines' => [
                'meetstaat_works' => [
                    ['name' => 'NovaFloor Real 1100 coral, Linoleum', 'unit' => 'm2', 'declared_total' => 120.5],
                    ['name' => 'NovaFloor Walton 2200 pine, Linoleum', 'unit' => 'm2', 'declared_total' => 40.25],
                ],
                'material_works' => [
                    ['name' => 'NovaFloor Real 1100 coral, Linoleum', 'unit' => 'm2', 'declared_total' => 120.5],
                    ['name' => 'NovaFloor Walton 2200 pine, Linoleum', 'unit' => 'm2', 'declared_total' => 40.25],
                ],
            ],
        ];

        $result = app(CalculationSourceReconciler::class)->reconcile($excel['lines'], $preview);

        $this->assertSame(0, $result['open_conflicts']);
        $this->assertCount(2, $result['products']);
        $this->assertSame('confirmed', $result['products'][0]['status']);
        $this->assertSame('confirmed', $result['products'][1]['status']);
        $this->assertEqualsWithDelta(120.5, (float) $result['products'][0]['excel_quantity'], 0.01);
        $this->assertEqualsWithDelta(40.25, (float) $result['products'][1]['excel_quantity'], 0.01);
        $this->assertNotEquals($result['products'][0]['name'], $result['products'][1]['name']);
    }

    public function test_quantity_deviation_keeps_meetstaat_leading_without_blocking(): void
    {
        $excel = app(CalculationExcelParser::class)->parse([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['M', '100', 'M', 'Elastische vloerbedekking', 'NovaFloor Real 1100 coral, Linoleum', '100', 'm2', '9', '900'],
        ], 'calc.xlsx');

        $result = app(CalculationSourceReconciler::class)->reconcile($excel['lines'], [
            'header' => ['project_number' => '260200099'],
            'works' => [],
            'closure_baselines' => [
                'meetstaat_works' => [
                    ['name' => 'NovaFloor Real 1100 coral, Linoleum', 'unit' => 'm2', 'declared_total' => 102.4],
                ],
                'material_works' => [
                    ['name' => 'NovaFloor Real 1100 coral, Linoleum', 'unit' => 'm2', 'declared_total' => 102.4],
                ],
            ],
        ]);

        $this->assertSame(0, $result['open_conflicts']);
        $this->assertSame('confirmed', $result['products'][0]['status']);
        $this->assertTrue($result['products'][0]['meetstaat_is_leading']);
        $this->assertEqualsWithDelta(102.4, (float) $result['products'][0]['leading_quantity'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $result['products'][0]['excel_quantity'], 0.01);
    }

    public function test_meetstaat_quantity_stays_leading_when_excel_differs(): void
    {
        $excel = app(CalculationExcelParser::class)->parse([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['M', '100', 'M', 'Elastische vloerbedekking', 'NovaFloor Real 1100 coral, Linoleum', '1677.90', 'm2', '9', '15101.1'],
            ['M', '100', 'M', 'Elastische vloerbedekking', 'NovaFloor Walton 2200 pine, Linoleum', '3098.54', 'm2', '9', '27886.86'],
        ], 'calc.xlsx');

        $result = app(CalculationSourceReconciler::class)->reconcile($excel['lines'], [
            'header' => ['project_number' => '260200099'],
            'works' => [],
            'closure_baselines' => [
                'meetstaat_works' => [
                    ['name' => 'NovaFloor Real 1100 coral, Linoleum', 'unit' => 'm2', 'declared_total' => 1596.84],
                    ['name' => 'NovaFloor Walton 2200 pine, Linoleum', 'unit' => 'm2', 'declared_total' => 2693.64],
                ],
                'material_works' => [
                    ['name' => 'NovaFloor Real 1100 coral, Linoleum', 'unit' => 'm2', 'declared_total' => 1677.90],
                    ['name' => 'NovaFloor Walton 2200 pine, Linoleum', 'unit' => 'm2', 'declared_total' => 3098.54],
                ],
            ],
        ]);

        $this->assertSame(0, $result['open_conflicts']);
        $this->assertCount(2, $result['products']);
        $this->assertSame('confirmed', $result['products'][0]['status']);
        $this->assertTrue($result['products'][0]['meetstaat_is_leading']);
        $this->assertTrue($result['products'][0]['quantities_differ']);
        $this->assertEqualsWithDelta(1677.90, (float) $result['products'][0]['excel_quantity'], 0.01);
        $this->assertEqualsWithDelta(1596.84, (float) $result['products'][0]['leading_quantity'], 0.01);
        $this->assertEqualsWithDelta(1596.84, (float) $result['products'][0]['meetstaat_quantity'], 0.01);
        $this->assertEqualsWithDelta(2693.64, (float) $result['products'][1]['leading_quantity'], 0.01);
        $this->assertSame('meetstaat', $result['products'][0]['leading_source']);
        $this->assertStringContainsString('leidend', mb_strtolower((string) $result['products'][0]['message']));
    }

    public function test_excel_only_floor_product_stays_a_review_calculation_row(): void
    {
        $excel = app(CalculationExcelParser::class)->parse([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['M', '100', 'M', 'Elastische vloerbedekking', 'NovaFloor Extra 3300 sage, Linoleum', '80', 'm2', '9', '720'],
        ], 'calc.xlsx');

        $result = app(CalculationSourceReconciler::class)->reconcile($excel['lines'], [
            'header' => ['project_number' => '260200099'],
            'works' => [],
            'closure_baselines' => [
                'meetstaat_works' => [],
                'material_works' => [],
            ],
        ]);

        $this->assertSame(1, $result['open_conflicts']);
        $this->assertSame('review', $result['products'][0]['status']);
        $this->assertNull($result['products'][0]['leading_quantity']);
        $this->assertFalse($result['products'][0]['meetstaat_is_leading']);
        $this->assertStringContainsString('Excel', (string) $result['products'][0]['message']);
    }

    public function test_meetstaat_quantity_stays_valid_without_excel_row(): void
    {
        $result = app(CalculationSourceReconciler::class)->reconcile([], [
            'header' => ['project_number' => '260200099'],
            'works' => [],
            'closure_baselines' => [
                'meetstaat_works' => [
                    ['name' => 'NovaFloor Real 1100 coral, Linoleum', 'unit' => 'm2', 'declared_total' => 1596.84],
                ],
                'material_works' => [],
            ],
        ]);

        $this->assertSame(0, $result['open_conflicts']);
        $this->assertSame('confirmed', $result['products'][0]['status']);
        $this->assertNull($result['products'][0]['excel_quantity']);
        $this->assertEqualsWithDelta(1596.84, (float) $result['products'][0]['leading_quantity'], 0.01);
        $this->assertTrue($result['products'][0]['meetstaat_is_leading']);
        $this->assertFalse($result['products'][0]['quantities_differ']);
    }

    public function test_attaches_laakse_style_labor_to_specific_colors_without_review(): void
    {
        $text = file_get_contents(base_path('tests/fixtures/nicon-meetbon-laakse-tuinen.txt'));
        $this->assertNotFalse($text);
        $meetstaat = (new NiconMeetbonParser)->parse($text);
        $materials = (new MaterialenstaatParser(new PdfTextExtractor))->parseText(<<<'TXT'
Materialenstaat
Marmoleum Real, 3120 rosato, Linoleum
Netto : 2226.69 m²
Marmoleum Sport 83020 move, Linoleum
Netto : 380.12 m²
Marmoleum Walton, 3355 rosemary green, Linoleum
Netto : 96.4 m²
Coral Welcome, 3202 desperado, Entreemat
Netto : 12.5 m²
PU gietvloer kleur n.t.b., Coating
Netto : 48.0 m²
TXT);
        $preview = (new RoomImportAssembler)->assemble($meetstaat, null, $materials);

        $excel = app(CalculationExcelParser::class)->parse([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['L', '4843-1', 'U', 'Schuren, primeren en egaliseren max. 2 mm', 'Schuren, primeren en egaliseren', '130', 'uur', '48', '6240'],
            ['L', '4843-1', 'U', 'Elastische vloerbedekking', 'Elastische vloerbedekking', '441.78', 'uur', '48', '21205.44'],
            ['M', '4843-1', 'M', 'Elastische vloerbedekking', 'Marmoleum Real 3120 rosato', '2226.69', 'm2', '9', '20040.21'],
            ['L', '4843-1', 'U', 'Elastische vloerbedekking', 'Elastische vloerbedekking', '75.45', 'uur', '48', '3621.6'],
            ['M', '4843-1', 'M', 'Elastische vloerbedekking', 'Marmoleum Sport 83020 move', '380.12', 'm2', '9', '3421.08'],
            ['L', '4843-1', 'U', 'Elastische vloerbedekking', 'Elastische vloerbedekking', '20.63', 'uur', '48', '990.24'],
            ['M', '4843-1', 'M', 'Elastische vloerbedekking', 'Marmoleum Walton 3355 rosemary green', '96.4', 'm2', '9', '867.6'],
            ['L', '4843-1', 'U', 'Zachte vloerbedekking', 'Zachte vloerbedekking', '8', 'uur', '48', '384'],
            ['M', '4843-1', 'M', 'Zachte vloerbedekking', 'Coral Welcome 3202 desperado', '12.5', 'm2', '9', '112.5'],
            ['L', '4843-1', 'U', 'Elastische vloerbedekking', 'Elastische vloerbedekking', '18.34', 'uur', '48', '880.32'],
            ['M', '4843-1', 'M', 'Elastische vloerbedekking', 'PU gietvloer kleur n.t.b.', '48', 'm2', '9', '432'],
        ], '11-ericwesselink1.xlsx');

        $preview = app(CalculationImportService::class)->attach($preview, [[
            'file' => new UploadedFile(
                __FILE__,
                '11-ericwesselink1.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
            'parsed' => $excel,
        ]]);

        $this->assertSame(0, (int) $preview['calculation']['open_matches']);
        $labor = collect($preview['calculation']['labor']);
        $this->assertFalse($labor->contains(fn (array $line): bool => ($line['status'] ?? '') === 'review'));
        $this->assertTrue($labor->contains(
            fn (array $line): bool => str_contains((string) $line['work_name'], 'Marmoleum Real')
        ));
        $this->assertTrue($labor->contains(
            fn (array $line): bool => str_contains((string) $line['work_name'], 'Marmoleum Sport')
        ));
        $this->assertTrue($labor->contains(
            fn (array $line): bool => str_contains((string) $line['work_name'], 'Walton') && str_contains((string) $line['work_name'], 'rosemary')
        ));
        $this->assertTrue($labor->contains(
            fn (array $line): bool => str_contains((string) $line['work_name'], 'Coral Welcome')
        ));
        $this->assertTrue($labor->contains(
            fn (array $line): bool => str_contains((string) $line['work_name'], 'gietvloer')
        ));

        $real = $labor->first(fn (array $line): bool => str_contains((string) $line['work_name'], 'Marmoleum Real'));
        $this->assertEqualsWithDelta(2226.69, (float) $real['quantity'], 0.01);
        $this->assertSame('m2', $real['quantity_unit']);
        $this->assertEqualsWithDelta(441.78, (float) $real['hours'], 0.01);
        $this->assertNotEquals($real['quantity'], $real['hours']);
    }

    public function test_generic_covering_label_is_not_itself_a_product_identity(): void
    {
        $identity = new MaterialIdentity;

        $this->assertTrue($identity->isGenericCoveringLabel('Elastische vloerbedekking'));
        $this->assertTrue($identity->isGenericCoveringLabel('Zachte vloerbedekking'));
        $this->assertFalse($identity->isGenericCoveringLabel('Marmoleum Real 3120 rosato'));
        $this->assertFalse($identity->isGenericCoveringLabel('PVC'));
        $this->assertTrue($identity->isPurchasePlaceholder('inkoop Amipox'));
        $this->assertFalse($identity->isPurchasePlaceholder('Marmoleum Real 3120 rosato'));
    }

    public function test_counts_unitless_amipox_inkoop_as_excel_floor_m2_without_extra_laag(): void
    {
        $excel = app(CalculationExcelParser::class)->parse([
            ['KM', 'Groep', 'M/U', 'Productie Eenheid Omschrijving', 'Artikel Omschrijving', 'Aantal', 'EH', 'Kostprijs', 'Kostprijs Tot.'],
            ['O', '100', 'O', '43.20.03a aanbrengen epoxy gietvloer in S3500-N', 'inkoop Amipox', '1513', '', '9', '13617'],
            ['O', '100', 'O', '43.20.05a aanbrengen vloercoating vloeistofdicht (transparant)', 'inkoop Amipox', '1245', '', '9', '11205'],
            ['O', '100', 'O', '43.20.05a Toeslag extra laag antislip EP coating t.p.v. de parkeergarage', 'inkoop Amipox', '645', '', '9', '5805'],
        ], '11-ericwesselink1.xlsx');

        $result = app(CalculationSourceReconciler::class)->reconcile($excel['lines'], [
            'header' => ['project_number' => '2505000'],
            'works' => [],
            'closure_baselines' => [
                'meetstaat_works' => [
                    ['name' => '43.20.03a Epoxy gietvloer (sp) S 3500-N, donkergrijs, Coating', 'unit' => 'm2', 'declared_total' => 2371.0],
                    ['name' => '43.20.05a Vloercoating vloeistofdicht, (sp), Coating', 'unit' => 'm2', 'declared_total' => 1145.16],
                ],
                'material_works' => [],
            ],
        ]);

        $gietvloer = collect($result['products'])->first(
            fn (array $row): bool => str_contains((string) $row['name'], '43.20.03a aanbrengen epoxy gietvloer')
        );
        $coating = collect($result['products'])->first(
            fn (array $row): bool => str_contains((string) $row['name'], 'Vloercoating vloeistofdicht')
        );
        $meetstaatGietvloer = collect($result['products'])->first(
            fn (array $row): bool => str_contains((string) $row['name'], 'Epoxy gietvloer (sp)')
        );

        $this->assertNotNull($gietvloer);
        $this->assertEqualsWithDelta(1513.0, (float) $gietvloer['excel_quantity'], 0.01);
        $this->assertNotNull($coating);
        $this->assertEqualsWithDelta(1245.0, (float) $coating['excel_quantity'], 0.01);
        $this->assertNotNull($meetstaatGietvloer);
        $this->assertTrue($meetstaatGietvloer['meetstaat_is_leading']);
        $this->assertEqualsWithDelta(2371.0, (float) $meetstaatGietvloer['leading_quantity'], 0.01);
        $this->assertFalse(collect($result['products'])->contains(
            fn (array $row): bool => str_contains(mb_strtolower((string) $row['name']), 'toeslag')
        ));
        $this->assertFalse(collect($result['products'])->contains(
            fn (array $row): bool => ($row['name'] ?? '') === 'inkoop Amipox'
        ));
    }
}
