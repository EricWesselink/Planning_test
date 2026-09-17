<?php

namespace Tests\Unit;

use App\Enums\ImportDecision;
use App\Services\Meetstaat\DrawingStyleAssessor;
use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use Tests\Support\ColoredFloorPlanPdf;
use Tests\Support\NumberedFloorPlanPdf;
use Tests\TestCase;

class DrawingStyleRegressionTest extends TestCase
{
    /**
     * @return list<array{label: string, rooms: int, meters_found: float, meters_expected: ?float, difference: ?float, hoog: int, midden: int, controleren: int, strategy: string}>
     */
    private array $reports = [];

    protected function tearDown(): void
    {
        if ($this->reports !== []) {
            fwrite(STDOUT, "\n=== Tekenstijl regressierapport ===\n");
            foreach ($this->reports as $row) {
                fwrite(STDOUT, sprintf(
                    "%s | ruimtes %d | m² gevonden %s | m² verwacht %s | verschil %s | Hoog %d | Midden %d | Controleren %d | %s\n",
                    $row['label'],
                    $row['rooms'],
                    number_format($row['meters_found'], 2, '.', ''),
                    $row['meters_expected'] === null ? '—' : number_format($row['meters_expected'], 2, '.', ''),
                    $row['difference'] === null ? '—' : number_format($row['difference'], 2, '.', ''),
                    $row['hoog'],
                    $row['midden'],
                    $row['controleren'],
                    $row['strategy'],
                ));
            }
            fwrite(STDOUT, "===================================\n");
        }

        parent::tearDown();
    }

    public function test_type_a_named_rooms_with_color_and_legend_stay_stable(): void
    {
        $path = ColoredFloorPlanPdf::path();
        $drawing = (new FloorPlanParser(new PdfTextExtractor))->parseFile($path, 'Plattegrond.pdf');
        $preview = (new RoomImportAssembler)->assemble(null, $drawing);

        $this->assertSame(
            DrawingStyleAssessor::StrategyNameMetersColor,
            $preview['drawing_style']['strategy'] ?? $drawing['drawing_style']['strategy']
        );
        $this->assertGreaterThanOrEqual(20, count($preview['areas']));
        $this->assertNotEmpty($preview['legend']);

        $kunstpleinen = collect($preview['areas'])->where('room_name', 'kunstplein');
        $this->assertCount(2, $kunstpleinen);

        $report = $preview['import_report'];
        $this->record('Type A (naam+m²+kleur)', $report, $preview['drawing_style']['strategy'] ?? '');

        $this->assertArrayHasKey('meters_found', $report);
        $this->assertArrayHasKey('incomplete_recognition', $report);
        $this->assertArrayHasKey('quality_label', $report);
    }

    public function test_type_b_numbered_rooms_do_not_break_type_a_strategy_detection(): void
    {
        $path = NumberedFloorPlanPdf::path();
        $drawing = (new FloorPlanParser(new PdfTextExtractor))->parseFile($path, 'Plattegrond.pdf');
        $preview = (new RoomImportAssembler)->assemble(null, $drawing);

        $this->assertGreaterThanOrEqual(5, count($preview['areas']));
        $this->assertTrue(collect($preview['areas'])->contains(
            fn (array $area) => str_contains(mb_strtolower((string) ($area['room_name'] ?? '')), 'groepsruimte')
                || ($area['room_number'] ?? null) === '0.07'
        ));

        // Type A fixture remains name/color-led when run in the same suite.
        $typeA = (new FloorPlanParser(new PdfTextExtractor))->parseFile(ColoredFloorPlanPdf::path(), 'Plattegrond.pdf');
        $this->assertSame(
            DrawingStyleAssessor::StrategyNameMetersColor,
            $typeA['drawing_style']['strategy']
        );

        $report = $preview['import_report'];
        $this->record('Type B (nummer+naam+m²)', $report, $preview['drawing_style']['strategy'] ?? '');
        $this->assertGreaterThanOrEqual(1, $report['rooms']);
    }

    public function test_assessor_picks_strategy_from_signals_not_project_names(): void
    {
        $assessor = new DrawingStyleAssessor;

        $named = $assessor->assess([
            ['room_number' => null, 'room_name' => 'tekenlokaal', 'square_meters' => 90.2, 'fill_color' => '#8d8676', 'page' => 1],
            ['room_number' => null, 'room_name' => 'kunstplein', 'square_meters' => 29.2, 'fill_color' => '#eea8f5', 'page' => 1],
        ], [['material' => 'Dark Sand', 'declared_total' => 100]]);
        $this->assertSame(DrawingStyleAssessor::StrategyNameMetersColor, $named['strategy']);

        $numbered = $assessor->assess([
            ['room_number' => '0.07', 'room_name' => 'groepsruimte', 'square_meters' => 50.97, 'fill_color' => null, 'page' => 1],
            ['room_number' => '0.09', 'room_name' => 'groepsruimte', 'square_meters' => 59.0, 'fill_color' => null, 'page' => 1],
        ]);
        $this->assertSame(DrawingStyleAssessor::StrategyNumberNameMeters, $numbered['strategy']);

        $sparse = $assessor->assess([
            ['room_number' => '0.15', 'room_name' => 'instructieruimte', 'square_meters' => null, 'fill_color' => null, 'page' => 1],
            ['room_number' => '0.16', 'room_name' => 'instructieruimte', 'square_meters' => null, 'fill_color' => null, 'page' => 1],
        ]);
        $this->assertSame(DrawingStyleAssessor::StrategyNumberNameOnly, $sparse['strategy']);
    }

    public function test_type_c_number_and_name_only_creates_room_for_manual_meters(): void
    {
        $text = "begane grond\n0.15 instructieruimte\n0.16 instructieruimte\n";
        $drawing = (new FloorPlanParser(new PdfTextExtractor))->parseText($text);
        $preview = (new RoomImportAssembler)->assemble(null, $drawing);

        $this->assertSame(DrawingStyleAssessor::StrategyNumberNameOnly, $drawing['drawing_style']['strategy']);
        $this->assertCount(2, $preview['areas']);
        foreach ($preview['areas'] as $area) {
            $this->assertNull($area['square_meters']);
            $this->assertTrue($area['needs_review']);
            $this->assertSame('instructieruimte', mb_strtolower((string) $area['room_name']));
        }

        $report = $preview['import_report'];
        $this->record('Type C (nummer+naam)', $report, $drawing['drawing_style']['strategy']);
        $this->assertSame(ImportDecision::ReadyWithWarnings->value, $preview['import_closure']['decision']);
        $this->assertFalse($report['incomplete_recognition']);
        $this->assertSame(ImportDecision::ReadyWithWarnings->label(), $report['quality_label']);
    }

    public function test_one_physical_room_keeps_multiple_material_tasks(): void
    {
        $preview = (new RoomImportAssembler)->assemble([
            'areas' => [[
                'key' => 'begane grond|0.07',
                'floor' => 'begane grond',
                'room_number' => '0.07',
                'room_name' => 'groepsruimte',
                'square_meters' => null,
                'tasks' => [
                    [
                        'work_name' => 'Dark Sand, PVC',
                        'unit' => 'm2',
                        'quantity' => 30.0,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ],
                    [
                        'work_name' => 'Entreemat Coral',
                        'unit' => 'm2',
                        'quantity' => 4.5,
                        'perimeter' => 0,
                        'seams' => 0,
                        'parts' => 1,
                    ],
                ],
                'source' => 'meetstaat',
                'needs_review' => true,
            ]],
            'works' => [],
            'warnings' => [],
            'uncertain' => [],
            'header' => [],
        ], null);

        $this->assertCount(1, $preview['areas']);
        $tasks = collect($preview['areas'][0]['tasks'])->pluck('work_name');
        $this->assertTrue($tasks->contains('Dark Sand, PVC'));
        $this->assertTrue($tasks->contains('Entreemat Coral'));
        $this->assertCount(2, $preview['areas'][0]['tasks']);
    }

    public function test_incomplete_legend_gap_is_reported_clearly(): void
    {
        $preview = (new RoomImportAssembler)->assemble(null, [
            'areas' => [[
                'key' => 'begane grond|tekenlokaal',
                'floor' => 'begane grond',
                'room_number' => null,
                'room_name' => 'tekenlokaal',
                'square_meters' => 90.2,
                'tasks' => [[
                    'work_name' => 'Dark Sand',
                    'unit' => 'm2',
                    'quantity' => 90.2,
                    'perimeter' => 0,
                    'seams' => 0,
                    'parts' => 1,
                ]],
                'fill_color' => '#8d8676',
                'legend_material' => 'Dark Sand',
                'recognized_via' => ['kleur', 'legenda'],
                'confidence' => 'hoog',
                'source' => 'plattegrond',
                'needs_review' => false,
                'keep_separate' => true,
            ]],
            'legend' => [[
                'material' => 'Dark Sand',
                'color' => '#8d8676',
                'unit' => 'm2',
                'declared_total' => 500.0,
                'page' => 1,
                'floor' => 'begane grond',
            ]],
            'works' => [],
            'warnings' => [],
            'uncertain' => [],
            'duplicates_removed' => 0,
        ]);

        $report = $preview['import_report'];
        $this->assertSame(ImportDecision::ReadyWithWarnings->value, $preview['import_closure']['decision']);
        $this->assertFalse($report['incomplete_recognition']);
        $this->assertSame(ImportDecision::ReadyWithWarnings->label(), $report['quality_label']);
        $this->assertEqualsWithDelta(90.2, $report['physical_meters'], 0.01);
        $this->assertEqualsWithDelta(90.2, $report['task_meters'], 0.01);
        $legend = collect($preview['legend'])->first(fn (array $row) => ($row['material'] ?? '') === 'Dark Sand');
        $this->assertNotNull($legend);
        $this->assertSame('controleren', $legend['status']);
        $this->assertEqualsWithDelta(90.2, (float) $legend['calculated_total'], 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $legend['declared_total'], 0.01);
        $this->assertEqualsWithDelta(-409.8, (float) $legend['difference'], 0.1);
        // Legacy aliases blijven fysieke m² tonen; project-expected komt uit materiaalblok wanneer aanwezig.
        $this->assertEqualsWithDelta(90.2, $report['meters_found'], 0.01);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function record(string $label, array $report, string $strategy): void
    {
        $this->reports[] = [
            'label' => $label,
            'rooms' => (int) ($report['rooms'] ?? 0),
            'meters_found' => (float) ($report['meters_found'] ?? 0),
            'meters_expected' => array_key_exists('meters_expected', $report) ? $report['meters_expected'] : null,
            'difference' => array_key_exists('meters_difference', $report) ? $report['meters_difference'] : null,
            'hoog' => (int) ($report['hoog'] ?? 0),
            'midden' => (int) ($report['midden'] ?? 0),
            'controleren' => (int) ($report['controleren'] ?? 0),
            'strategy' => $strategy,
        ];
    }
}
