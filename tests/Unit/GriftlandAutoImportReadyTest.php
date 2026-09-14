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
        $this->assertEqualsWithDelta(8456.65, $parsed, 0.005);
        $this->assertEqualsWithDelta(8456.65, $kept, 0.005);

        $variantLabels = [
            'Coral Brush',
            'Natural-black',
            'PU gietvloer antislip',
            'vloercoating',
            'Granit light',
            'iQ light green',
            'Natural-light blue',
            'Dusty Brick',
            'dusty green',
            'Natural blue',
            'directie levering',
        ];
        $names = collect($preview['works'])->pluck('name');
        foreach ($preview['works'] as $work) {
            $name = (string) ($work['name'] ?? '');
            $this->assertLessThanOrEqual(255, mb_strlen($name), $name);
            $this->assertFalse(
                str_contains($name, 'Coral Brush') && str_contains($name, 'Tarkett'),
                $name
            );
        }
        foreach ($variantLabels as $label) {
            $this->assertTrue(
                $names->contains(fn ($name) => str_contains((string) $name, $label)),
                $label.' ontbreekt als aparte werkzaamheid.'
            );
        }
        $gietvloer = $names->filter(fn ($name) => str_contains((string) $name, 'PU gietvloer'));
        $this->assertGreaterThanOrEqual(2, $gietvloer->count());
        $this->assertTrue($gietvloer->contains(fn ($name) => ! str_contains(mb_strtolower((string) $name), 'antislip')));
        $this->assertTrue($gietvloer->contains(fn ($name) => str_contains(mb_strtolower((string) $name), 'antislip')));
    }
}
