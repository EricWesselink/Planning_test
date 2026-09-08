<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

/**
 * Normale Decoloop-import (meetstaat + plattegrond) moet zonder handmatige Controleren klaar zijn.
 */
class LaakseAutoImportReadyTest extends TestCase
{
    public function test_laakse_meetstaat_plus_drawing_is_auto_ready_without_manual_review(): void
    {
        $drawingPath = RealDrawingFixtures::laakseTuinenDrawingPath();
        $meetstaatPath = RealDrawingFixtures::laakseTuinenMeetstaatPath();
        if ($drawingPath === null || ! is_file($meetstaatPath)) {
            $this->markTestSkipped('Echte Laakse-fixtures ontbreken.');
        }

        $extractor = new PdfTextExtractor;
        $preview = (new RoomImportAssembler)->assemble(
            (new MeetstaatReader($extractor))->parseFile($meetstaatPath, 'meetstaat.pdf'),
            (new FloorPlanParser($extractor))->parseFile($drawingPath, 'tekening.pdf'),
        );

        $report = $preview['import_report'];
        $closure = $preview['import_closure'];

        $this->assertSame(0, (int) $report['controleren'], 'Geen open Controleren-ruimtes bij betrouwbare bronnen.');
        $this->assertSame(0, (int) ($report['material_unknown'] ?? -1), 'Geen onbekend materiaal waar meetstaat materiaal geeft.');
        $this->assertEqualsWithDelta(3440.46, (float) $report['task_meters'], 0.01);
        $this->assertEqualsWithDelta(3440.44, (float) $report['task_meters_expected'], 0.01);
        $this->assertEqualsWithDelta(0.02, (float) $report['task_meters_difference'], 0.01);
        $this->assertTrue($closure['ready'], 'Import moet automatisch gereed zijn voor definitieve opslag.');
        $this->assertSame('READY_AUTOMATIC', $closure['decision']);
        $this->assertSame(0, (int) $closure['open_points']);
        $this->assertTrue((bool) ($closure['totals']['rounding_explained'] ?? false));
        $this->assertSame('+0,02 m² — verklaarde bronafronding ✓', $closure['totals']['difference_label']);
        $this->assertSame('Project definitief importeren', $closure['button_label']);
        $this->assertSame('Import gereed', $report['quality_label']);
        $this->assertFalse((bool) ($report['incomplete_recognition'] ?? true));

        $waltonLegend = collect($preview['legend'])->filter(function (array $row) {
            $blob = mb_strtolower((string) (($row['material'] ?? '').' '.($row['canonical_material'] ?? '')));

            return str_contains($blob, 'walton');
        });
        $this->assertTrue($waltonLegend->isNotEmpty());
        foreach ($waltonLegend as $row) {
            $this->assertNotSame('controleren', $row['status'] ?? '', (string) ($row['canonical_material'] ?? $row['material'] ?? ''));
            if (($row['status'] ?? '') === 'ok') {
                $this->assertStringContainsString('Walton,', (string) ($row['canonical_material'] ?? ''));
                $this->assertLessThanOrEqual(0.10, abs((float) ($row['difference'] ?? 99)));
            }
        }

        $controleren = collect($preview['areas'])->filter(
            fn (array $area) => ($area['confidence'] ?? '') === 'controleren'
        );
        $this->assertTrue($controleren->isEmpty());
    }

    public function test_griftland_physical_room_count_stays_at_115(): void
    {
        $path = RealDrawingFixtures::griftlandDrawingPath();
        if ($path === null) {
            $this->markTestSkipped('Echte Griftland-plattegrond ontbreekt.');
        }

        $preview = (new RoomImportAssembler)->assemble(
            null,
            (new FloorPlanParser(new PdfTextExtractor))->parseFile($path, 'Plattegrond.pdf'),
        );

        $this->assertSame(115, count($preview['areas']));
    }
}
