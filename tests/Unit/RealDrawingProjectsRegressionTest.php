<?php

namespace Tests\Unit;

use App\Services\Meetstaat\DrawingStyleAssessor;
use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

/**
 * Bewaakt beide echte tekenstijlen tegelijk.
 * Verslechtert één project, dan moet die wijziging terug.
 */
class RealDrawingProjectsRegressionTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function tearDown(): void
    {
        if ($this->lines !== []) {
            fwrite(STDOUT, "\n=== Echte projecten (Griftland + Laakse) ===\n");
            foreach ($this->lines as $line) {
                fwrite(STDOUT, $line."\n");
            }
            fwrite(STDOUT, "==========================================\n");
        }

        parent::tearDown();
    }

    public function test_griftland_named_style_anchors_remain_green(): void
    {
        $path = RealDrawingFixtures::griftlandDrawingPath();
        if ($path === null) {
            $this->markTestSkipped('Echte Griftland-plattegrond ontbreekt.');
        }

        $drawing = (new FloorPlanParser(new PdfTextExtractor))->parseFile($path, 'Plattegrond.pdf');
        $preview = (new RoomImportAssembler)->assemble(null, $drawing);
        $strategy = $preview['drawing_style']['strategy'] ?? $drawing['drawing_style']['strategy'] ?? '';

        $this->assertSame(DrawingStyleAssessor::StrategyNameMetersColor, $strategy);
        $this->assertGreaterThanOrEqual(100, count($preview['areas']));
        $this->assertHasNamedMeters($preview['areas'], 'begane grond', 'tekenlokaal', 90.20);
        $this->assertHasNamedMeters($preview['areas'], 'begane grond', 'magazijn tekenen', 38.19);
        $kunst = collect($preview['areas'])->filter(
            fn (array $area) => mb_strtolower((string) ($area['floor'] ?? '')) === 'begane grond'
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'kunstplein')
        );
        $this->assertGreaterThanOrEqual(2, $kunst->count());

        $report = $preview['import_report'];
        $this->lines[] = sprintf(
            'Griftland | fysieke ruimtes %d | fysieke m² %s | taak-m² %s | verwacht taak-m² %s | verschil taak-m² %s | Hoog %d | Midden %d | Controleren %d | %s',
            $report['rooms'],
            number_format((float) ($report['physical_meters'] ?? $report['meters_found'] ?? 0), 2, '.', ''),
            number_format((float) ($report['task_meters'] ?? 0), 2, '.', ''),
            ($report['task_meters_expected'] ?? $report['meters_expected'] ?? null) === null
                ? '—'
                : number_format((float) ($report['task_meters_expected'] ?? $report['meters_expected']), 2, '.', ''),
            ($report['task_meters_difference'] ?? $report['meters_difference'] ?? null) === null
                ? '—'
                : number_format((float) ($report['task_meters_difference'] ?? $report['meters_difference']), 2, '.', ''),
            $report['hoog'],
            $report['midden'],
            $report['controleren'],
            $strategy,
        );
        foreach ($report['floors'] ?? [] as $floor) {
            $this->lines[] = sprintf(
                '  %s | fysieke ruimtes %d | fysieke m² %s | taak-m² %s | meetstaat taak-m² %s | verschil taak-m² %s | Hoog %d | Midden %d | Controleren %d',
                $floor['floor'],
                $floor['rooms'],
                number_format((float) ($floor['physical_meters'] ?? $floor['meters_found'] ?? 0), 2, '.', ''),
                number_format((float) ($floor['task_meters'] ?? 0), 2, '.', ''),
                ($floor['meetstaat_task_meters'] ?? $floor['meters_expected'] ?? null) === null
                    ? '—'
                    : number_format((float) ($floor['meetstaat_task_meters'] ?? $floor['meters_expected']), 2, '.', ''),
                ($floor['task_meters_difference'] ?? $floor['meters_difference'] ?? null) === null
                    ? '—'
                    : number_format((float) ($floor['task_meters_difference'] ?? $floor['meters_difference']), 2, '.', ''),
                $floor['hoog'],
                $floor['midden'],
                $floor['controleren'],
            );
        }
    }

    public function test_laakse_numbered_style_anchors_remain_green(): void
    {
        $drawingPath = RealDrawingFixtures::laakseTuinenDrawingPath();
        if ($drawingPath === null) {
            $this->markTestSkipped('Echte Laakse Tuinen-tekening ontbreekt.');
        }

        $extractor = new PdfTextExtractor;
        $drawing = (new FloorPlanParser($extractor))->parseFile($drawingPath, 'tekening.pdf');
        $meetstaat = (new MeetstaatReader($extractor))->parseFile(
            RealDrawingFixtures::laakseTuinenMeetstaatPath(),
            'Meetbon_Laakse_Tuinen.pdf'
        );
        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);
        $strategy = $preview['drawing_style']['strategy'] ?? $drawing['drawing_style']['strategy'] ?? '';

        $this->assertContains($strategy, [
            DrawingStyleAssessor::StrategyNumberNameMeters,
            DrawingStyleAssessor::StrategyMixed,
        ]);
        $this->assertHasNumberedMeters($preview['areas'], '0.07', 'groepsruimte', 50.97);
        $this->assertHasNumberedMeters($preview['areas'], '0.08', 'berging', 24.01);
        $this->assertHasNumberedMeters($preview['areas'], '0.19a', 'administratie', 12.01);
        $this->assertHasNumberedMeters($preview['areas'], '1.24', 'logopedie', 20.22);
        $this->assertHasNumberedMeters($preview['areas'], '2.16', "piranha's", 35.61);

        $scheids = collect($preview['areas'])->first(
            fn (array $area) => ($area['room_number'] ?? '') === '0.12'
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'scheidsrechter')
        );
        $this->assertNotNull($scheids);
        $this->assertSame('begane grond sporthal', mb_strtolower((string) $scheids['floor']));
        $this->assertContains($scheids['source'], ['beide', 'meetstaat', 'gecombineerd']);

        $entree = collect($preview['areas'])->first(
            fn (array $area) => ($area['room_number'] ?? '') === '0.01'
                && str_contains(mb_strtolower((string) ($area['floor'] ?? '')), 'sporthal')
                && abs((float) ($area['square_meters'] ?? $area['derived_square_meters'] ?? 0) - 5.86) < 0.15
        );
        $this->assertNotNull($entree, '0.01 entree 5,86 m² moet op begane grond sporthal landen, niet op gewone BG.');

        $bgPlain = collect($preview['areas'])->first(
            fn (array $area) => ($area['room_number'] ?? '') === '0.12'
                && mb_strtolower((string) ($area['floor'] ?? '')) === 'begane grond'
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'scheidsrechter')
        );
        $this->assertNull($bgPlain, '0.12 scheidsrechter mag niet op gewone begane grond blijven.');

        $report = $preview['import_report'];
        $this->lines[] = sprintf(
            'Laakse Tuinen | fysieke ruimtes %d | fysieke m² %s | taak-m² %s | meetstaat taak-m² %s | verwacht taak-m² %s | verschil taak-m² %s | Hoog %d | Midden %d | Controleren %d | %s',
            $report['rooms'],
            number_format((float) ($report['physical_meters'] ?? $report['meters_found'] ?? 0), 2, '.', ''),
            number_format((float) ($report['task_meters'] ?? 0), 2, '.', ''),
            ($report['meetstaat_task_meters'] ?? null) === null
                ? '—'
                : number_format((float) $report['meetstaat_task_meters'], 2, '.', ''),
            ($report['task_meters_expected'] ?? $report['meters_expected'] ?? null) === null
                ? '—'
                : number_format((float) ($report['task_meters_expected'] ?? $report['meters_expected']), 2, '.', ''),
            ($report['task_meters_difference'] ?? $report['meters_difference'] ?? null) === null
                ? '—'
                : number_format((float) ($report['task_meters_difference'] ?? $report['meters_difference']), 2, '.', ''),
            $report['hoog'],
            $report['midden'],
            $report['controleren'],
            $strategy,
        );

        $bg = collect($report['floors'] ?? [])->first(
            fn (array $row) => str_contains(mb_strtolower((string) ($row['floor'] ?? '')), 'begane')
        );
        if ($bg !== null) {
            $this->lines[] = sprintf(
                '  BG | fysieke ruimtes %d | fysieke m² %s | taak-m² %s | meetstaat taak-m² %s | verschil taak-m² %s',
                $bg['rooms'] ?? 0,
                number_format((float) ($bg['physical_meters'] ?? 0), 2, '.', ''),
                number_format((float) ($bg['task_meters'] ?? 0), 2, '.', ''),
                ($bg['meetstaat_task_meters'] ?? null) === null
                    ? '—'
                    : number_format((float) $bg['meetstaat_task_meters'], 2, '.', ''),
                ($bg['task_meters_difference'] ?? null) === null
                    ? '—'
                    : number_format((float) $bg['task_meters_difference'], 2, '.', ''),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function assertHasNamedMeters(array $areas, string $floor, string $nameNeedle, float $meters): void
    {
        $hit = collect($areas)->first(function (array $area) use ($floor, $nameNeedle, $meters) {
            return mb_strtolower((string) ($area['floor'] ?? '')) === mb_strtolower($floor)
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), mb_strtolower($nameNeedle))
                && abs((float) ($area['square_meters'] ?? 0) - $meters) < 0.15;
        });
        $this->assertNotNull($hit, "{$nameNeedle} {$meters} m² op {$floor} ontbreekt (Griftland-regressie).");
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function assertHasNumberedMeters(array $areas, string $number, string $nameNeedle, float $meters): void
    {
        $hit = collect($areas)->first(function (array $area) use ($number, $nameNeedle, $meters) {
            if ((string) ($area['room_number'] ?? '') !== $number) {
                return false;
            }
            if (! str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), mb_strtolower($nameNeedle))) {
                return false;
            }
            $physical = $area['square_meters'] ?? $area['derived_square_meters'] ?? null;
            if ($physical !== null && abs((float) $physical - $meters) < 0.15) {
                return true;
            }
            $taskSum = collect($area['tasks'] ?? [])
                ->filter(fn (array $task) => in_array((string) ($task['unit'] ?? ''), ['m2', 'm²'], true))
                ->sum(fn (array $task) => (float) ($task['quantity'] ?? 0));

            return abs((float) $taskSum - $meters) < 0.15;
        });
        $this->assertNotNull($hit, "{$number} {$nameNeedle} {$meters} m² ontbreekt (Laakse-regressie).");
    }
}
