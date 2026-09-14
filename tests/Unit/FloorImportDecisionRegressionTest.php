<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MaterialenstaatParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

/**
 * Generieke regressiesuite: vaste decision snapshot per vloerproject.
 * Geen project-hardcodes in de importcode; fixtures mogen wel projectnamen hebben.
 */
class FloorImportDecisionRegressionTest extends TestCase
{
    /**
     * @return array<string, list<array{name: string, expected_decision: string, max_task_loss: float, expected_task?: float}>>
     */
    public static function projectCases(): array
    {
        $cases = [
            ['name' => 'Laakse', 'expected_decision' => 'READY', 'max_task_loss' => 0.05, 'expected_task' => 3440.46],
            ['name' => 'Rova', 'expected_decision' => 'READY', 'max_task_loss' => 0.05],
            ['name' => 'Griftland', 'expected_decision' => 'READY_WITH_WARNINGS', 'max_task_loss' => 0.05],
            ['name' => 'IKC Sluisbuurt', 'expected_decision' => 'READY', 'max_task_loss' => 0.05, 'expected_task' => 2529.54],
            ['name' => 'Gezondheidscentrum Laren', 'expected_decision' => 'READY', 'max_task_loss' => 0.05, 'expected_task' => 807.65],
        ];

        $out = [];
        foreach ($cases as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    #[DataProvider('projectCases')]
    /**
     * @param  array{name: string, expected_decision: string, max_task_loss: float, expected_task?: float}  $case
     */
    public function test_project_decision_snapshot(array $case): void
    {
        $preview = $this->assembleProject($case['name']);
        if ($preview === null) {
            $this->markTestSkipped('Fixturebundel ontbreekt voor '.$case['name']);
        }

        $report = $preview['import_report'] ?? [];
        $closure = $preview['import_closure'] ?? [];
        $parsed = (float) ($report['meetstaat_task_meters'] ?? $report['task_source_meters_parsed'] ?? 0);
        $final = (float) ($report['task_meters'] ?? 0);
        $loss = (float) ($report['task_source_meters_lost'] ?? max(0, $parsed - $final));
        $floors = collect($preview['areas'] ?? [])
            ->map(fn (array $area) => mb_strtolower(trim((string) ($area['floor'] ?? ''))))
            ->filter(fn (string $floor) => $floor !== '' && $floor !== 'onbekend')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $openConflicts = (int) ($closure['open_points'] ?? -1);
        $decision = (string) ($closure['decision'] ?? '');

        $snapshot = sprintf(
            '%s | parsed=%.2f | final=%.2f | loss=%.2f | floors=%d | open=%d | decision=%s',
            $case['name'],
            $parsed,
            $final,
            $loss,
            count($floors),
            $openConflicts,
            $decision,
        );
        fwrite(STDOUT, "\n".$snapshot."\n");

        $this->assertSame($case['expected_decision'], $decision, $snapshot);
        $this->assertSame(0, $openConflicts, $snapshot);
        $this->assertLessThanOrEqual($case['max_task_loss'], $loss, $snapshot);
        $this->assertSame(0, (int) ($report['controleren'] ?? -1), $snapshot);

        if (isset($case['expected_task'])) {
            $this->assertEqualsWithDelta($case['expected_task'], $final, 0.05, $snapshot);
        }

        foreach ($preview['works'] ?? [] as $work) {
            $name = mb_strtolower((string) ($work['name'] ?? ''));
            $unit = (string) ($work['unit'] ?? '');
            if (str_contains($name, 'plint')) {
                $this->assertSame('m1', $unit, 'Plinten mogen nooit als m²-werk eindigen: '.$name);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assembleProject(string $name): ?array
    {
        $extractor = new PdfTextExtractor;
        $assembler = new RoomImportAssembler;

        return match ($name) {
            'Laakse' => $this->assembleLaakse($extractor, $assembler),
            'Rova' => $this->assembleRova($extractor, $assembler),
            'Griftland' => $this->assembleBundle(RealDrawingFixtures::griftlandBundlePaths(), $extractor, $assembler),
            'IKC Sluisbuurt' => $this->assembleBundle(RealDrawingFixtures::sluisbuurtBundlePaths(), $extractor, $assembler),
            'Gezondheidscentrum Laren' => $this->assembleBundle(RealDrawingFixtures::larenBundlePaths(), $extractor, $assembler),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assembleLaakse(PdfTextExtractor $extractor, RoomImportAssembler $assembler): ?array
    {
        $drawing = RealDrawingFixtures::laakseTuinenDrawingPath();
        $meetstaat = RealDrawingFixtures::laakseTuinenMeetstaatPath();
        if ($drawing === null || ! is_file($meetstaat)) {
            return null;
        }

        return $assembler->assemble(
            (new MeetstaatReader($extractor))->parseFile($meetstaat, 'meetstaat.pdf'),
            (new FloorPlanParser($extractor))->parseFile($drawing, 'tekening.pdf'),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assembleRova(PdfTextExtractor $extractor, RoomImportAssembler $assembler): ?array
    {
        $bundle = RealDrawingFixtures::rovaBundlePaths();
        if ($bundle === null) {
            // Tekstfixture: Rova-identiteit blijft via bestaande unit tests; skip hier zonder PDF.
            return null;
        }

        $materials = null;
        if (! empty($bundle['materials']) && is_file((string) $bundle['materials'])) {
            $materials = (new MaterialenstaatParser($extractor))->parseFile($bundle['materials'], 'materialenstaat.pdf');
        }
        $drawing = null;
        if (! empty($bundle['drawing']) && is_file((string) $bundle['drawing'])) {
            $drawing = (new FloorPlanParser($extractor))->parseFile($bundle['drawing'], 'plattegrond.pdf');
        }

        return $assembler->assemble(
            (new MeetstaatReader($extractor))->parseFile($bundle['meetstaat'], 'meetstaat.pdf'),
            $drawing,
            $materials,
        );
    }

    /**
     * @param  array{meetstaat: string, materials: ?string, drawing: ?string}|null  $bundle
     * @return array<string, mixed>|null
     */
    private function assembleBundle(?array $bundle, PdfTextExtractor $extractor, RoomImportAssembler $assembler): ?array
    {
        if ($bundle === null) {
            return null;
        }

        $materials = null;
        if (! empty($bundle['materials']) && is_file((string) $bundle['materials'])) {
            $materials = (new MaterialenstaatParser($extractor))->parseFile($bundle['materials'], 'materialenstaat.pdf');
        }
        $drawing = null;
        if (! empty($bundle['drawing']) && is_file((string) $bundle['drawing'])) {
            $drawing = (new FloorPlanParser($extractor))->parseFile($bundle['drawing'], 'plattegrond.pdf');
        }

        return $assembler->assemble(
            (new MeetstaatReader($extractor))->parseFile($bundle['meetstaat'], 'meetstaat.pdf'),
            $drawing,
            $materials,
        );
    }
}
