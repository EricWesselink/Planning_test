<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

class GriftlandAutoImportReadyTest extends TestCase
{
    public function test_three_source_bundle_is_ready_automatic_without_manual_review(): void
    {
        $bundle = RealDrawingFixtures::griftlandBundlePaths();
        if ($bundle === null) {
            $this->markTestSkipped('Echte Griftland-bundel ontbreekt.');
        }

        $extractor = new PdfTextExtractor;
        $preview = (new RoomImportAssembler)->assemble(
            (new MeetstaatReader($extractor))->parseFile($bundle['meetstaat'], 'meetstaat.pdf'),
            (new FloorPlanParser($extractor))->parseFile($bundle['drawing'], 'plattegrond.pdf'),
            (new MaterialenstaatParser($extractor))->parseFile($bundle['materials'], 'materialenstaat.pdf'),
        );

        $report = $preview['import_report'];
        $closure = $preview['import_closure'];
        $parsed = (float) ($report['meetstaat_task_meters'] ?? 0);
        $kept = (float) ($report['task_meters'] ?? 0);
        $lost = (float) ($report['task_source_meters_lost'] ?? abs($parsed - $kept));

        $this->assertEqualsWithDelta($parsed, $kept, 0.005, 'parsed TASK_SOURCE moet gelijk zijn aan final area_tasks.');
        $this->assertEqualsWithDelta(0.0, $lost, 0.005);
        $this->assertSame(0, (int) $report['controleren']);
        $this->assertSame(0, (int) $closure['open_points']);
        $this->assertTrue($closure['ready']);
        $this->assertContains($closure['decision'], ['READY', 'READY_WITH_WARNINGS']);
        $this->assertSame('Project definitief importeren', $closure['button_label']);
    }
}
