<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

class SluisbuurtAutoImportReadyTest extends TestCase
{
    public function test_three_source_bundle_keeps_cork_variant_apart_and_is_ready_automatic(): void
    {
        $bundle = RealDrawingFixtures::sluisbuurtBundlePaths();
        if ($bundle === null) {
            $this->markTestSkipped('Echte IKC Sluisbuurt-bundel ontbreekt.');
        }

        $extractor = new PdfTextExtractor;
        $preview = (new RoomImportAssembler)->assemble(
            (new MeetstaatReader($extractor))->parseFile($bundle['meetstaat'], 'meetstaat.pdf'),
            (new FloorPlanParser($extractor))->parseFile($bundle['drawing'], 'plattegrond.pdf'),
            (new MaterialenstaatParser($extractor))->parseFile($bundle['materials'], 'materialenstaat.pdf'),
        );

        $report = $preview['import_report'];
        $closure = $preview['import_closure'];
        $plain = 'Lino Art Urban R893-0555, flashy street grey, Linoleum';
        $cork = 'Lino Art Urban R893-0555 op kurk, flashy street grey, Linoleum';

        $unknownFloors = collect($preview['areas'])->filter(
            fn (array $area) => mb_strtolower(trim((string) ($area['floor'] ?? ''))) === 'onbekend'
                || trim((string) ($area['floor'] ?? '')) === ''
        );
        $this->assertTrue($unknownFloors->isEmpty());

        $oat = collect($preview['areas'])->first(
            fn (array $area) => str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'container')
        );
        $douches = collect($preview['areas'])->first(fn (array $area) => ($area['room_number'] ?? '') === '03.08');
        $verkeer = collect($preview['areas'])->first(fn (array $area) => ($area['room_number'] ?? '') === '03.13b');
        $this->assertNotNull($oat);
        $this->assertNotNull($douches);
        $this->assertNotNull($verkeer);
        $this->assertSame('kelder', mb_strtolower((string) $oat['floor']));
        $this->assertSame('verdieping 3', mb_strtolower((string) $douches['floor']));
        $this->assertSame('verdieping 3', mb_strtolower((string) $verkeer['floor']));

        $corkQty = 0.0;
        $greyOnlyQty = 0.0;
        foreach ($preview['areas'] as $area) {
            foreach ($area['tasks'] ?? [] as $task) {
                $name = (string) ($task['work_name'] ?? '');
                $qty = (float) ($task['quantity'] ?? 0);
                if (str_contains($name, 'op kurk')) {
                    $corkQty += $qty;
                }
                if ($name === 'grey, Linoleum') {
                    $greyOnlyQty += $qty;
                }
            }
        }
        $this->assertEqualsWithDelta(120.39, $corkQty, 0.01);
        $this->assertEqualsWithDelta(0.0, $greyOnlyQty, 0.001);

        $workNames = collect($preview['works'])->pluck('name');
        $this->assertTrue($workNames->contains($plain));
        $this->assertTrue($workNames->contains($cork));
        $this->assertFalse($workNames->contains('grey, Linoleum'));

        $this->assertEqualsWithDelta(2529.54, (float) ($report['task_meters'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(2529.56, (float) ($preview['expected_task_totals']['project_total'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($report['task_source_meters_lost'] ?? 99), 0.005);

        $legendCork = collect($preview['legend'])->first(
            fn (array $row) => str_contains((string) ($row['canonical_material'] ?? $row['material'] ?? ''), 'op kurk')
        );
        $this->assertNotNull($legendCork);
        $this->assertNotSame('controleren', $legendCork['status'] ?? '');
        $this->assertEqualsWithDelta(120.39, (float) ($legendCork['calculated_total'] ?? 0), 0.10);

        $this->assertSame(0, (int) $report['controleren']);
        $this->assertSame(0, (int) $closure['open_points']);
        $this->assertTrue($closure['ready']);
        $this->assertSame('READY_AUTOMATIC', $closure['decision']);
    }
}
