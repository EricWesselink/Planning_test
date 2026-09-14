<?php

namespace Tests\Unit;

use App\Services\Meetstaat\Formats\NiconMeetbonParser;
use App\Services\Meetstaat\ImportClosureEvaluator;
use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\MaterialIdentity;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\TestCase;

class RovaMaterialIdentityImportTest extends TestCase
{
    public function test_multiline_ege_product_headers_keep_separate_canonical_works(): void
    {
        $text = <<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P260712 NB Rova Amersfoort
Werknr        : 260800124
Datum         : 06/09/2026

Ege Reform Heritage RF 7133080 (96 x 96 cm),
Tapijttegels
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek
onbenioemd 20.61 m² 10.00 m
Totaal 20.61 m² 10.00 m
Totaal
20.61 m² 10.00 m

Ege Reform Heritage RF 7133290 (96 x 96 cm) ,
Tapijttegels
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek
onbenioemd 10.00 m² 8.00 m
Totaal 10.00 m² 8.00 m
Totaal
10.00 m² 8.00 m

Ege Refor Heritage kamerbreed RF 7133070,
Tapijt (0.01 cm x 0.01 cm)
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek
onbenoemd 5.00 m² 4.00 m
Totaal 5.00 m² 4.00 m
Totaal
5.00 m² 4.00 m
TXT;

        $parsed = (new NiconMeetbonParser)->parse($text);
        $names = collect($parsed['works'])->pluck('name');

        $this->assertTrue($names->contains(fn ($name) => str_contains((string) $name, '7133080')));
        $this->assertTrue($names->contains(fn ($name) => str_contains((string) $name, '7133290')));
        $this->assertTrue($names->contains(fn ($name) => str_contains((string) $name, '7133070')));
        $this->assertFalse($names->contains('Tapijttegels'));
        $this->assertSame([], $parsed['uncertain']);

        $tilesA = collect($parsed['works'])->first(fn ($work) => str_contains((string) $work['name'], '7133080'));
        $tilesB = collect($parsed['works'])->first(fn ($work) => str_contains((string) $work['name'], '7133290'));
        $this->assertEqualsWithDelta(20.61, (float) $tilesA['declared_total'], 0.01);
        $this->assertEqualsWithDelta(10.00, (float) $tilesB['declared_total'], 0.01);
    }

    public function test_meetstaat_task_source_wins_over_inflated_material_list_netto(): void
    {
        $meetstaat = (new NiconMeetbonParser)->parse(<<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P260712 NB Rova Amersfoort
Werknr        : 260800124
Datum         : 06/09/2026

PU gietvloer Sikkens F2.10.60, Coating
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek
onbenioemd 448.51 m² 100.00 m
Totaal 448.51 m² 100.00 m
Totaal
448.51 m² 100.00 m

Ege Reform Heritage RF 7133080 (96 x 96 cm),
Tapijttegels
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek
onbenioemd 153.21 m² 50.00 m
Totaal 153.21 m² 50.00 m
Totaal
153.21 m² 50.00 m
TXT);

        $materials = (new MaterialenstaatParser(new PdfTextExtractor))->parseText(<<<'TXT'
Materialenstaat
Artikel Netto hoeveelheid Bruto hoeveelheid
PU gietvloer Sikkens F2.10.60, Coating 897.01 m² 520.44 m²
Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels 306.42 m² 261.37 m²
TXT);

        $preview = (new RoomImportAssembler)->assemble($meetstaat, null, $materials);
        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $expected = (float) ($preview['expected_task_totals']['project_total'] ?? 0);
        $this->assertEqualsWithDelta(601.72, $expected, 0.05);
        $this->assertEqualsWithDelta(601.72, (float) ($preview['import_report']['task_meters'] ?? 0), 0.05);
        $this->assertSame(0.0, (float) ($preview['import_report']['task_source_meters_lost'] ?? 99));

        $pu = collect($preview['material_check'])->first(
            fn (array $row) => str_contains((string) ($row['material'] ?? ''), 'F2.10.60')
        );
        $this->assertNotNull($pu);
        $this->assertEqualsWithDelta(448.51, (float) $pu['declared_total'], 0.01);
        $this->assertEqualsWithDelta(897.01, (float) $pu['material_list_netto'], 0.01);
        $this->assertNotSame('controleren', $pu['status']);

        $ege = collect($preview['material_check'])->first(
            fn (array $row) => str_contains((string) ($row['material'] ?? ''), '7133080')
        );
        $this->assertNotNull($ege);
        $this->assertEqualsWithDelta(153.21, (float) $ege['calculated_total'], 0.01);
        $this->assertNotSame('controleren', $ege['status']);

        $this->assertSame('READY', $closure['decision']);
    }

    public function test_meetstaat_and_drawing_legend_consensus_keeps_material_list_factor_two_informative(): void
    {
        $meetstaat = (new NiconMeetbonParser)->parse(<<<'TXT'
Meetstaat
Opdrachtgever : Nicon vloeren
Referentie    : 11P260712 NB Rova Amersfoort
Werknr        : 260800124
Datum         : 06/09/2026

PU gietvloer Sikkens F2.10.60, Coating
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek
onbenioemd 358.74 m² 100.00 m
Totaal 358.74 m² 100.00 m
Bouwlaag: verdieping 1
Ruimte Oppervlakte Omtrek
onbenioemd 89.77 m² 40.00 m
Totaal 89.77 m² 40.00 m
Totaal
448.51 m² 140.00 m

Ege Reform Heritage RF 7133080 (96 x 96 cm),
Tapijttegels
Bouwlaag: begane grond
Ruimte Oppervlakte Omtrek
onbenioemd 20.61 m² 10.00 m
Totaal 20.61 m² 10.00 m
Bouwlaag: verdieping 1
Ruimte Oppervlakte Omtrek
onbenioemd 132.60 m² 50.00 m
Totaal 132.60 m² 50.00 m
Totaal
153.21 m² 60.00 m

Ege Reform Heritage RF 7133290 (96 x 96 cm),
Tapijttegels
Bouwlaag: verdieping 1
Ruimte Oppervlakte Omtrek
onbenioemd 88.79 m² 30.00 m
Totaal 88.79 m² 30.00 m
Totaal
88.79 m² 30.00 m
TXT);

        $materials = (new MaterialenstaatParser(new PdfTextExtractor))->parseText(<<<'TXT'
Materialenstaat
Artikel Netto hoeveelheid Bruto hoeveelheid
PU gietvloer Sikkens F2.10.60, Coating 897.01 m² 520.44 m²
Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels 306.42 m² 261.37 m²
TXT);

        $drawing = [
            'format' => 'plattegrond',
            'areas' => [],
            'works' => [],
            'legend' => [
                [
                    'material' => 'PU gietvloer Sikkens F2.10.60, Coating',
                    'declared_total' => 358.74,
                    'unit' => 'm2',
                    'floor' => 'begane grond',
                    'page' => 1,
                    'color' => '#aabbcc',
                ],
                [
                    'material' => 'PU gietvloer Sikkens F2.10.60, Coating',
                    'declared_total' => 89.77,
                    'unit' => 'm2',
                    'floor' => 'verdieping 1',
                    'page' => 2,
                    'color' => '#aabbcc',
                ],
                [
                    'material' => 'Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels',
                    'declared_total' => 20.61,
                    'unit' => 'm2',
                    'floor' => 'begane grond',
                    'page' => 1,
                    'color' => '#112233',
                ],
                [
                    'material' => 'Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels',
                    'declared_total' => 132.60,
                    'unit' => 'm2',
                    'floor' => 'verdieping 1',
                    'page' => 2,
                    'color' => '#112233',
                ],
                [
                    'material' => 'Ege Reform Heritage RF 7133290 (96 x 96 cm), Tapijttegels',
                    'declared_total' => 88.79,
                    'unit' => 'm2',
                    'floor' => 'verdieping 1',
                    'page' => 2,
                    'color' => '#445566',
                ],
            ],
            'warnings' => [],
            'uncertain' => [],
            'duplicates_removed' => 0,
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing, $materials);
        $closure = (new ImportClosureEvaluator)->evaluate($preview);

        $this->assertEqualsWithDelta(690.51, (float) ($preview['expected_task_totals']['project_total'] ?? 0), 0.05);
        $this->assertEqualsWithDelta(690.51, (float) ($preview['import_report']['task_meters'] ?? 0), 0.05);

        $pu = collect($preview['material_check'])->first(
            fn (array $row) => str_contains((string) ($row['material'] ?? ''), 'F2.10.60')
        );
        $this->assertNotNull($pu);
        $this->assertTrue((bool) ($pu['source_consensus'] ?? false));
        $this->assertEqualsWithDelta(448.51, (float) $pu['drawing_legend_total'], 0.01);
        $this->assertEqualsWithDelta(897.01, (float) $pu['material_list_netto'], 0.01);
        $this->assertSame('informatief', $pu['status']);
        $this->assertTrue((bool) ($pu['audit']['approx_factor_two'] ?? false));
        $this->assertStringContainsString('bronconsensus', (string) ($pu['expected_source'] ?? ''));

        $ege = collect($preview['material_check'])->first(
            fn (array $row) => str_contains((string) ($row['material'] ?? ''), '7133080')
        );
        $this->assertNotNull($ege);
        $this->assertTrue((bool) ($ege['source_consensus'] ?? false));
        $this->assertSame('informatief', $ege['status']);
        $this->assertTrue((bool) ($ege['audit']['approx_factor_two'] ?? false));

        $tasks7133290 = collect($preview['areas'])
            ->flatMap(fn (array $area) => $area['tasks'] ?? [])
            ->filter(fn (array $task) => str_contains((string) ($task['work_name'] ?? ''), '7133290'))
            ->sum(fn (array $task) => (float) ($task['quantity'] ?? 0));
        $this->assertEqualsWithDelta(88.79, $tasks7133290, 0.01);

        $this->assertSame('READY', $closure['decision']);
        $this->assertSame(0, collect($preview['material_check'])->where('status', 'controleren')->count());
    }

    public function test_ambiguous_generic_tapijttegels_is_not_guessed_onto_one_variant(): void
    {
        $identity = new MaterialIdentity;
        $resolved = $identity->resolveUniqueCanonical('Tapijttegels', [
            'Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels',
            'Ege Reform Heritage RF 7133290 (96 x 96 cm), Tapijttegels',
        ]);

        $this->assertNull($resolved);
    }
}
