<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

/**
 * Meetstaat is TASK_SOURCE: tekening mag geen tweede vloer-taken toevoegen.
 * Expected totals komen uit materiaalblok-eindtotalen (één waarheid).
 */
class LaakseTaskSourceNoDrawingDuplicateTest extends TestCase
{
    public function test_drawing_does_not_inflate_meetstaat_task_meters_or_split_expected_totals(): void
    {
        $drawingPath = RealDrawingFixtures::laakseTuinenDrawingPath();
        $meetstaatPath = RealDrawingFixtures::laakseTuinenMeetstaatPath();
        if ($drawingPath === null || ! is_file($meetstaatPath)) {
            $this->markTestSkipped('Echte Laakse-fixtures ontbreken.');
        }

        $extractor = new PdfTextExtractor;
        $meetstaat = (new MeetstaatReader($extractor))->parseFile($meetstaatPath, 'meetstaat.pdf');
        $drawing = (new FloorPlanParser($extractor))->parseFile($drawingPath, 'tekening.pdf');
        $assembler = new RoomImportAssembler;

        $combined = $assembler->assemble($meetstaat, $drawing);
        $meetstaatOnly = $assembler->assemble($meetstaat, null);

        $report = $combined['import_report'];
        $expected = $combined['expected_task_totals'];
        $closure = $combined['import_closure'];

        $this->assertTrue($expected['known']);
        $this->assertEqualsWithDelta(3440.44, (float) $expected['project_total'], 0.001);
        $this->assertEqualsWithDelta(3440.44, (float) $report['task_meters_expected'], 0.001);
        $this->assertEqualsWithDelta(3440.44, (float) ($closure['totals']['expected'] ?? 0), 0.001);
        $this->assertSame(
            (float) $report['task_meters_expected'],
            (float) ($closure['totals']['expected'] ?? -1),
            'IMPORTCONTROLE en rapport moeten dezelfde expected delen.'
        );

        $this->assertEqualsWithDelta(
            (float) $meetstaatOnly['import_report']['task_meters'],
            (float) $report['task_meters'],
            0.01,
            'Gecombineerde import mag geen tekening-vloertaken bovenop meetstaat optellen.'
        );

        $materials = collect($report['materials'])->keyBy('material');
        $this->assertEqualsWithDelta(2226.69, (float) $materials['Marmoleum Real, 3120 rosato, Linoleum']['expected_task_meters'], 0.001);
        $this->assertEqualsWithDelta(394.51, (float) $materials['Marmoleum Walton, 3355 rosemary green, Linoleum']['expected_task_meters'], 0.001);
        $this->assertEqualsWithDelta(107.92, (float) $materials['Marmoleum Walton, 3352 berlin red, Linoleum']['expected_task_meters'], 0.001);
        $this->assertEqualsWithDelta(86.24, (float) $materials['Marmoleum Walton, 3370 terracotta, Linoleum']['expected_task_meters'], 0.001);
        $this->assertEqualsWithDelta(256.74, (float) $materials['Marmoleum Walton, 3360 vintage blue, Linoleum']['expected_task_meters'], 0.001);
        $this->assertEqualsWithDelta(251.96, (float) $materials['PU gietvloer kleur n.t.b., Coating']['expected_task_meters'], 0.001);

        foreach ($materials as $row) {
            $this->assertLessThan(
                0.11,
                abs((float) ($row['difference'] ?? 0)),
                'Materiaal '.$row['material'].' mag geen tekening-surplus tonen.'
            );
        }

        $suppressed = $report['safe_fix_stats']['suppressed_drawing_task_meters'] ?? 0;
        $this->assertGreaterThan(200, (float) $suppressed, 'Tekening-vloertaken moeten actief onderdrukt zijn.');

        $deltas = $expected['declared_vs_task_delta'] ?? [];
        $deltaSum = round(collect($deltas)->sum('difference'), 2);
        $this->assertEqualsWithDelta(0.02, $deltaSum, 0.001, '0,02 m² zit in meetstaat-taak vs declared, niet in tekening-dubbels.');

        $egels = collect($combined['areas'])->first(
            fn (array $area) => ($area['room_number'] ?? '') === '0.35'
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'egel')
        );
        $this->assertNotNull($egels);
        $flooring = collect($egels['tasks'] ?? [])->filter(function (array $task) {
            $unit = (string) ($task['unit'] ?? '');

            return in_array($unit, ['m2', 'm²'], true) && (float) ($task['quantity'] ?? 0) > 0;
        });
        $this->assertGreaterThanOrEqual(2, $flooring->count());
    }

    public function test_synthetic_meetstaat_rejects_extra_drawing_flooring_task(): void
    {
        $meetstaat = [
            'format' => 'test',
            'header' => [],
            'works' => [[
                'name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                'unit' => 'm2',
                'declared_total' => 50.97,
            ]],
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'groepsruimte',
                'tasks' => [[
                    'work_name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                    'unit' => 'm2',
                    'quantity' => 50.97,
                ]],
                'source' => 'meetstaat',
            ]],
            'warnings' => [],
            'uncertain' => [],
            'duplicates' => [],
            'needs_ocr' => false,
        ];
        $drawing = [
            'format' => 'test',
            'header' => [],
            'works' => [[
                'name' => 'Marmoleum Real, 3120 rosato, Linoleum',
                'unit' => 'm2',
                'declared_total' => 10.0,
            ]],
            'areas' => [[
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'groepsruimte',
                'square_meters' => 50.97,
                'fill_color' => '#aa0000',
                'legend_material' => 'Marmoleum Real',
                'tasks' => [[
                    'work_name' => 'Marmoleum Real',
                    'unit' => 'm2',
                    'quantity' => 50.97,
                ]],
                'source' => 'plattegrond',
                'recognized_via' => ['tekening', 'kleur'],
                'confidence' => 'hoog',
            ]],
            'legend' => [[
                'material' => 'Marmoleum Real, 3120 rosato, Linoleum',
                'declared_total' => 10.0,
                'declared_known' => true,
                'unit' => 'm2',
                'page' => 1,
                'floor' => 'begane grond',
            ]],
            'warnings' => [],
            'uncertain' => [],
            'needs_ocr' => false,
        ];

        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $room = $preview['areas'][0];
        $flooring = collect($room['tasks'])->filter(fn (array $task) => ($task['unit'] ?? '') === 'm2');

        $this->assertCount(1, $flooring);
        $this->assertEqualsWithDelta(50.97, (float) $flooring->first()['quantity'], 0.001);
        $this->assertEqualsWithDelta(50.97, (float) $preview['expected_task_totals']['project_total'], 0.001);
        $this->assertEqualsWithDelta(50.97, (float) $preview['import_report']['task_meters'], 0.001);
        $this->assertEqualsWithDelta(50.97, (float) $preview['import_report']['materials'][0]['expected_task_meters'], 0.001);
        $this->assertNotEquals(10.0, (float) $preview['import_report']['materials'][0]['expected_task_meters']);
    }
}
