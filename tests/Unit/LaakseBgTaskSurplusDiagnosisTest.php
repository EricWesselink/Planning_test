<?php

namespace Tests\Unit;

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use App\Support\WorkType;
use Tests\Support\RealDrawingFixtures;
use Tests\TestCase;

/**
 * Diagnoseert het BG taak-overschot.
 * Bewaakt dat extras − ontbrekend = rapportverschil, en dat begane grond op 0,0 reconcilieert.
 */
class LaakseBgTaskSurplusDiagnosisTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function tearDown(): void
    {
        if ($this->lines !== []) {
            fwrite(STDOUT, "\n=== Laakse BG taak-overschot diagnose ===\n");
            foreach ($this->lines as $line) {
                fwrite(STDOUT, $line."\n");
            }
            fwrite(STDOUT, "========================================\n");
        }

        parent::tearDown();
    }

    public function test_begane_grond_task_surplus_reconciles_to_drawing_only_extras(): void
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

        $bg = collect($preview['import_report']['floors'] ?? [])->first(
            fn (array $row) => mb_strtolower((string) ($row['floor'] ?? '')) === 'begane grond'
        );
        $this->assertNotNull($bg);
        $reportedDiff = round((float) ($bg['task_meters_difference'] ?? 0), 2);

        $previewMap = $this->materialMap($preview['areas'] ?? []);
        $meetstaatMap = $this->materialMap($meetstaat['areas'] ?? []);

        $extras = 0.0;
        $missing = 0.0;
        $rows = [];

        $keys = array_unique(array_merge(array_keys($previewMap), array_keys($meetstaatMap)));
        foreach ($keys as $key) {
            $left = $previewMap[$key] ?? null;
            $right = $meetstaatMap[$key] ?? null;
            $tek = round((float) ($left['qty'] ?? 0), 2);
            $ms = round((float) ($right['qty'] ?? 0), 2);
            $diff = round($tek - $ms, 2);
            if (abs($diff) <= 0.05) {
                continue;
            }
            if ($diff > 0) {
                $extras += $diff;
            } else {
                $missing += abs($diff);
            }
            $rows[] = [
                'room_number' => $left['room_number'] ?? $right['room_number'] ?? '',
                'room_name' => $left['room_name'] ?? $right['room_name'] ?? '',
                'material' => $left['material'] ?? $right['material'] ?? '',
                'tekening' => $tek,
                'meetstaat' => $ms,
                'difference' => $diff,
                'cause' => $ms <= 0.0001 ? 'alleen op tekening' : ($tek <= 0.0001 ? 'alleen in meetstaat' : 'andere hoeveelheid'),
            ];
        }

        usort($rows, fn (array $a, array $b) => abs($b['difference']) <=> abs($a['difference']));

        $net = round($extras - $missing, 2);
        $this->assertEqualsWithDelta(
            $reportedDiff,
            $net,
            0.05,
            'som extra taken − som ontbrekende taken moet gelijk zijn aan BG task_meters_difference'
        );
        $this->assertEqualsWithDelta(0.0, $missing, 0.05, 'BG-overschot zit nu volledig in extra tekening-taken, niet in ontbrekende meetstaat.');

        $stats = $preview['import_report']['safe_fix_stats'] ?? [];
        $this->assertGreaterThan(
            0,
            (int) ($stats['duplicate_rooms_removed'] ?? 0),
            'Ghost-/duplicate-ruimtes moeten veilig worden verwijderd.'
        );
        $this->assertGreaterThanOrEqual(
            1,
            (int) ($stats['ocr_meter_matches'] ?? 0),
            '0.21 seloal moet via nummer+m² terugkoppelen op speellokaal.'
        );
        $this->assertLessThanOrEqual(
            294.01,
            $reportedDiff,
            'OCR-match + implausible numbers mogen het BG-overschot niet laten stijgen.'
        );
        $this->assertLessThan(
            235.13,
            $reportedDiff + 0.01,
            'Na OCR-koppeling van 0.21 speellokaal moet het BG-overschot onder de eerdere +235 blijven of sterk dalen t.o.v. +294.'
        );
        $this->assertGreaterThanOrEqual(
            4,
            (int) ($stats['floor_reassignments'] ?? 0),
            'Sporthal-ruimtes moeten van begane grond naar begane grond sporthal worden hersteld.'
        );
        $this->assertLessThan(
            209.42,
            $reportedDiff + 0.01,
            'Na sporthal-bouwlaagherstel moet BG-extra onder +209,42 dalen.'
        );
        $this->assertEqualsWithDelta(
            0.0,
            $reportedDiff,
            0.05,
            'Begane grond reconcilieert naar 0,0 m² extra.',
        );

        $sporthal = collect($preview['import_report']['floors'] ?? [])->first(
            fn (array $row) => mb_strtolower((string) ($row['floor'] ?? '')) === 'begane grond sporthal'
        );
        $moved = round((float) ($stats['floor_reassignment_meters'] ?? 0), 2);

        $this->lines[] = sprintf(
            'BG | fysiek %.2f | taak %.2f | meetstaat-taak %.2f | verschil %+.2f',
            (float) $bg['physical_meters'],
            (float) $bg['task_meters'],
            (float) $bg['meetstaat_task_meters'],
            $reportedDiff
        );
        $this->lines[] = sprintf(
            'SAFE FIX | ocr_matches %d | floor_reassign %d (%.2f m²) | duplicate_m² -%.2f | kept_separate %d',
            (int) ($stats['ocr_meter_matches'] ?? 0),
            (int) ($stats['floor_reassignments'] ?? 0),
            $moved,
            (float) ($stats['duplicate_task_meters_removed'] ?? 0),
            (int) ($stats['wrong_merge_rooms_kept_separate'] ?? 0)
        );
        $this->lines[] = sprintf(
            'Reconciliatie | BG-extra vóór 209.42 | naar sporthal %.2f | OCR/fragment 84.59/0 | resterend BG-extra %.2f | sporthal verschil %s',
            $moved,
            $reportedDiff,
            $sporthal === null
                ? '—'
                : sprintf('%+.2f', (float) ($sporthal['task_meters_difference'] ?? 0))
        );
        $this->lines[] = sprintf('Extras − missing | %.2f − %.2f = %.2f', $extras, $missing, $net);
        foreach ($stats['floor_reassignment_log'] ?? [] as $row) {
            $this->lines[] = sprintf(
                'VERPLAATST | p%s | %s/%s → %s/%s | %s | %.2f | %s',
                $row['page'] ?? '—',
                $row['from_floor'] ?? '',
                $row['from_number'] !== '' ? $row['from_number'] : '—',
                $row['to_floor'] ?? '',
                $row['to_number'] !== '' ? $row['to_number'] : '—',
                $row['room_name'] ?? '',
                (float) ($row['meters'] ?? 0),
                $row['reason'] ?? ''
            );
        }
        foreach (array_slice($rows, 0, 15) as $row) {
            $this->lines[] = sprintf(
                '%s | %s | %s | tek %.2f | ms %.2f | %+.2f | %s',
                $row['room_number'] !== '' ? $row['room_number'] : '—',
                $row['room_name'],
                mb_substr((string) $row['material'], 0, 36),
                $row['tekening'],
                $row['meetstaat'],
                $row['difference'],
                $row['cause']
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, array{room_number: string, room_name: string, material: string, qty: float}>
     */
    private function materialMap(array $areas): array
    {
        $out = [];
        foreach ($areas as $area) {
            if (mb_strtolower(trim((string) ($area['floor'] ?? ''))) !== 'begane grond') {
                continue;
            }
            $number = (string) ($area['room_number'] ?? '');
            $name = (string) ($area['room_name'] ?? '');
            $roomKey = mb_strtolower($number !== '' ? 'n:'.$number : 'name:'.$name);
            foreach ($area['tasks'] ?? [] as $task) {
                if (! $this->isFlooring($task)) {
                    continue;
                }
                $material = trim((string) ($task['work_name'] ?? ''));
                $matKey = $this->normalizeMaterial($material);
                if ($matKey === '') {
                    continue;
                }
                $key = $roomKey.'|'.$matKey;
                $out[$key] ??= [
                    'room_number' => $number,
                    'room_name' => $name,
                    'material' => $material,
                    'qty' => 0.0,
                ];
                if (mb_strlen($material) > mb_strlen($out[$key]['material'])) {
                    $out[$key]['material'] = $material;
                }
                if ($out[$key]['room_name'] === '' && $name !== '') {
                    $out[$key]['room_name'] = $name;
                }
                $out[$key]['qty'] += (float) ($task['quantity'] ?? 0);
            }
        }
        foreach ($out as $key => $row) {
            $out[$key]['qty'] = round($row['qty'], 2);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function isFlooring(array $task): bool
    {
        $unit = (string) ($task['unit'] ?? 'm2');
        if ($unit !== 'm2' && $unit !== 'm²') {
            return false;
        }
        $name = mb_strtolower((string) ($task['work_name'] ?? ''));
        if ($name === '' || str_contains($name, 'plint') || WorkType::looksLikeRoom($name)) {
            return false;
        }

        return (float) ($task['quantity'] ?? 0) > 0;
    }

    private function normalizeMaterial(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\b(banen|vinyl|pvc|tapijttegels?|coating|lvt|linoleum|entreemat)\b/u', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }
}
