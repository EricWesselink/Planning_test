<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

class LarenAutoImportReadyTest extends TestCase
{
    public function test_three_source_bundle_is_ready_automatic_without_manual_review(): void
    {
        $bundle = RealDrawingFixtures::larenBundlePaths();
        if ($bundle === null) {
            $this->markTestSkipped('Echte Gezondheidscentrum Laren-bundel ontbreekt.');
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

        $this->assertEqualsWithDelta(807.65, $parsed, 0.005);
        $this->assertEqualsWithDelta($parsed, $kept, 0.005, 'parsed TASK_SOURCE moet gelijk zijn aan final area_tasks.');
        $this->assertEqualsWithDelta(0.0, $lost, 0.005);

        $unknownFloors = collect($preview['areas'])->filter(
            fn (array $area) => mb_strtolower(trim((string) ($area['floor'] ?? ''))) === 'onbekend'
                || trim((string) ($area['floor'] ?? '')) === ''
        );
        $this->assertTrue($unknownFloors->isEmpty(), $unknownFloors->pluck('room_name')->implode(', '));

        $controleren = collect($preview['areas'])->filter(
            fn (array $area) => ($area['confidence'] ?? '') === 'controleren'
        );
        $this->assertTrue($controleren->isEmpty(), $controleren->map(
            fn (array $area) => trim(($area['room_number'] ?? '').' '.($area['room_name'] ?? ''))
        )->implode(', '));

        $legendDesso = collect($preview['legend'])->first(
            fn (array $row) => str_contains(mb_strtolower((string) (($row['canonical_material'] ?? '').' '.($row['material'] ?? ''))), 'desso')
        );
        $this->assertNotNull($legendDesso);
        $this->assertNotSame('controleren', $legendDesso['status'] ?? '');
        $this->assertLessThanOrEqual(0.05, abs((float) ($legendDesso['difference'] ?? 99)));

        $plintM2 = collect($preview['works'])->first(
            fn (array $work) => str_contains(mb_strtolower((string) ($work['name'] ?? '')), 'plint')
                && in_array((string) ($work['unit'] ?? ''), ['m2', 'm²'], true)
                && (float) ($work['calculated_total'] ?? 0) > 1
        );
        $this->assertNull($plintM2, 'Plinten mogen niet als vloer-m² in de werken landen.');

        $this->assertSame(0, (int) $report['controleren']);
        $this->assertSame(0, (int) $closure['open_points']);
        $this->assertTrue($closure['ready']);
        $this->assertSame('READY_AUTOMATIC', $closure['decision']);
        $this->assertSame('Project definitief importeren', $closure['button_label']);
    }
}
