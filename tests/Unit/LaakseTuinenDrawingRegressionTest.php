<?php

namespace Tests\Unit;

use App\Services\Meetstaat\DrawingStyleAssessor;
use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

class LaakseTuinenDrawingRegressionTest extends TestCase
{
    /** @var list<string> */
    private array $reportLines = [];

    protected function tearDown(): void
    {
        if ($this->reportLines !== []) {
            fwrite(STDOUT, "\n=== Laakse Tuinen regressierapport ===\n");
            foreach ($this->reportLines as $line) {
                fwrite(STDOUT, $line."\n");
            }
            fwrite(STDOUT, "======================================\n");
        }

        parent::tearDown();
    }

    public function test_real_laakse_drawing_is_type_b_and_matches_known_rooms(): void
    {
        $drawingPath = RealDrawingFixtures::laakseTuinenDrawingPath();
        if ($drawingPath === null) {
            $this->markTestSkipped('Echte Laakse Tuinen-tekening ontbreekt (tests/fixtures/laakse-tuinen-tekening.pdf).');
        }

        $meetstaatPath = RealDrawingFixtures::laakseTuinenMeetstaatPath();
        $this->assertFileExists($meetstaatPath);

        $extractor = new PdfTextExtractor;
        $drawing = (new FloorPlanParser($extractor))->parseFile($drawingPath, 'tekening.pdf');
        $meetstaat = (new MeetstaatReader($extractor))->parseFile($meetstaatPath, 'Meetbon_Laakse_Tuinen.pdf');
        $preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);

        $strategy = $preview['drawing_style']['strategy']
            ?? $drawing['drawing_style']['strategy']
            ?? '';
        $this->assertContains($strategy, [
            DrawingStyleAssessor::StrategyNumberNameMeters,
            DrawingStyleAssessor::StrategyMixed,
        ], 'Laakse tekening moet Type B of mixed zijn, geen project-specifieke uitzondering.');

        $this->assertGreaterThanOrEqual(100, count($drawing['areas']));
        $this->assertGreaterThanOrEqual(100, count($preview['areas']));

        foreach ([
            ['0.07', 'groepsruimte', 50.97],
            ['0.08', 'berging', 24.01],
            ['0.19a', 'administratie', 12.01],
            ['1.24', 'logopedie', 20.22],
            ['2.16', "piranha's", 35.61],
        ] as [$number, $name, $meters]) {
            $this->assertHasRoom($preview['areas'], $number, $name, $meters);
        }

        $piranhas = $this->findRoom($preview['areas'], '2.16', "piranha's");
        $this->assertNotNull($piranhas);
        $this->assertGreaterThanOrEqual(
            2,
            count($piranhas['tasks'] ?? []),
            'Meerdere materiaalvlakken in één fysieke ruimte moeten als aparte area_tasks blijven.'
        );

        $multiTaskRooms = collect($preview['areas'])->filter(fn (array $area) => count($area['tasks'] ?? []) > 1);
        $this->assertGreaterThanOrEqual(20, $multiTaskRooms->count());

        $floors = collect(($preview['import_report']['floors'] ?? []))
            ->keyBy('floor');

        foreach ($floors as $floor => $row) {
            $this->reportLines[] = sprintf(
                '%s | fysieke ruimtes %d | fysieke m² %s | taak-m² %s | meetstaat taak-m² %s | verschil taak-m² %s | Hoog %d | Midden %d | Controleren %d',
                $floor,
                $row['rooms'] ?? 0,
                number_format((float) ($row['physical_meters'] ?? 0), 2, '.', ''),
                number_format((float) ($row['task_meters'] ?? 0), 2, '.', ''),
                ($row['meetstaat_task_meters'] ?? null) === null
                    ? '—'
                    : number_format((float) $row['meetstaat_task_meters'], 2, '.', ''),
                ($row['task_meters_difference'] ?? null) === null
                    ? '—'
                    : number_format((float) $row['task_meters_difference'], 2, '.', ''),
                $row['hoog'] ?? 0,
                $row['midden'] ?? 0,
                $row['controleren'] ?? 0,
            );
        }

        $bg = $floors->get('begane grond') ?? $floors->first(
            fn (array $row) => str_contains(mb_strtolower((string) ($row['floor'] ?? '')), 'begane')
        );
        $this->assertNotNull($bg, 'Begane grond moet in het import_report staan.');
        $this->assertArrayHasKey('physical_meters', $bg);
        $this->assertArrayHasKey('task_meters', $bg);
        $this->assertArrayHasKey('meetstaat_task_meters', $bg);
        // Fysieke m² ≠ taak-m²: multi-materiaal ruimtes (zoals 0.35) houden aparte taakregels.
        $this->assertNotEquals(
            round((float) $bg['physical_meters'], 2),
            round((float) $bg['task_meters'], 2),
            'Begane grond: fysieke m² en taak-m² moeten gescheiden blijven.'
        );

        $report = $preview['import_report'];
        $this->assertArrayHasKey('physical_meters', $report);
        $this->assertArrayHasKey('task_meters', $report);
        $this->assertArrayHasKey('materials', $report);
        $this->reportLines[] = sprintf(
            'TOTAAL | fysieke ruimtes %d | fysieke m² %s | taak-m² %s | meetstaat taak-m² %s | verwacht taak-m² %s | verschil taak-m² %s | Hoog %d | Midden %d | Controleren %d | %s',
            $report['rooms'],
            number_format((float) $report['physical_meters'], 2, '.', ''),
            number_format((float) $report['task_meters'], 2, '.', ''),
            $report['meetstaat_task_meters'] === null ? '—' : number_format((float) $report['meetstaat_task_meters'], 2, '.', ''),
            $report['task_meters_expected'] === null ? '—' : number_format((float) $report['task_meters_expected'], 2, '.', ''),
            $report['task_meters_difference'] === null ? '—' : number_format((float) $report['task_meters_difference'], 2, '.', ''),
            $report['hoog'],
            $report['midden'],
            $report['controleren'],
            $strategy,
        );

        foreach ($report['materials'] ?? [] as $material) {
            $this->reportLines[] = sprintf(
                'MATERIAAL | %s | gevonden taak-m² %s | verwacht taak-m² %s | verschil %s | %s',
                $material['material'] ?? '',
                number_format((float) ($material['found_task_meters'] ?? 0), 2, '.', ''),
                ($material['expected_task_meters'] ?? null) === null
                    ? '—'
                    : number_format((float) $material['expected_task_meters'], 2, '.', ''),
                ($material['difference'] ?? null) === null
                    ? '—'
                    : number_format((float) $material['difference'], 2, '.', ''),
                $material['status_label'] ?? '',
            );
        }

        // Sanity: totale “verwacht” is materiaal-totaal, niet fysieke ruimte-som.
        if ($report['task_meters_expected'] !== null) {
            $this->assertNotEquals(
                round((float) $report['physical_meters'], 2),
                round((float) $report['task_meters_expected'], 2)
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function assertHasRoom(array $areas, string $number, string $name, float $meters): void
    {
        $hit = $this->findRoom($areas, $number, $name);
        $this->assertNotNull($hit, "Ruimte {$number} {$name} ontbreekt.");
        $physical = $hit['square_meters'] ?? $hit['derived_square_meters'] ?? null;
        if ($physical !== null && abs((float) $physical - $meters) < 0.15) {
            $this->assertTrue(true);

            return;
        }
        $taskSum = collect($hit['tasks'] ?? [])
            ->filter(fn (array $task) => in_array((string) ($task['unit'] ?? ''), ['m2', 'm²'], true))
            ->sum(fn (array $task) => (float) ($task['quantity'] ?? 0));
        $this->assertEqualsWithDelta(
            $meters,
            (float) $taskSum,
            0.15,
            "{$number} {$name} heeft verkeerde m²."
        );
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, mixed>|null
     */
    private function findRoom(array $areas, string $number, string $name): ?array
    {
        return collect($areas)->first(function (array $area) use ($number, $name) {
            return (string) ($area['room_number'] ?? '') === $number
                && str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), mb_strtolower($name));
        });
    }
}
