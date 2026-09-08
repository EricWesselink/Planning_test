<?php

namespace App\Services\Meetstaat;

use App\Support\PdfTextNormalizer;

/**
 * Deterministische room-identity matching op gecombineerd bewijs.
 * Geen project-/ruimte-hardcodes: alleen bouwlaag, nummer, naam, m², geometrie.
 */
class RoomIdentityResolver
{
    private const MIN_AUTO_SCORE = 70.0;

    private const MIN_SCORE_MARGIN = 25.0;

    /**
     * @param  array<string, mixed>  $meetstaatRoom
     * @param  list<array<string, mixed>>  $drawingRooms
     * @param  array<int, true>  $usedIndexes
     * @return array{index: int, area: array<string, mixed>, evidence: array<string, mixed>}|null
     */
    public function match(array $meetstaatRoom, array $drawingRooms, array $usedIndexes = []): ?array
    {
        $ranked = [];
        foreach ($drawingRooms as $index => $drawingRoom) {
            if (isset($usedIndexes[$index])) {
                continue;
            }
            $score = $this->scoreCandidate($meetstaatRoom, $drawingRoom);
            if ($score['rejected'] || $score['score'] < self::MIN_AUTO_SCORE) {
                continue;
            }
            $ranked[] = [
                'index' => $index,
                'area' => $drawingRoom,
                'score' => $score['score'],
                'evidence' => $score,
            ];
        }

        if ($ranked === []) {
            return null;
        }

        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $best = $ranked[0];
        $runnerUp = $ranked[1]['score'] ?? 0.0;

        if (! $this->isUniquelyBest($best, $runnerUp)) {
            return null;
        }

        $evidence = $best['evidence'];
        $evidence['runner_up_score'] = round($runnerUp, 2);
        $evidence['reason'] = $this->reasonFromEvidence($evidence);

        return [
            'index' => $best['index'],
            'area' => $best['area'],
            'evidence' => $evidence,
        ];
    }

    /**
     * Zoek unieke meetstaat-host voor een tekeningfragment (omgekeerde richting).
     *
     * @param  array<string, mixed>  $drawingRoom
     * @param  list<array<string, mixed>>  $hosts
     * @return array{index: int, area: array<string, mixed>, evidence: array<string, mixed>}|null
     */
    public function matchHost(array $drawingRoom, array $hosts): ?array
    {
        $ranked = [];
        foreach ($hosts as $index => $host) {
            $score = $this->scoreCandidate($host, $drawingRoom);
            if ($score['rejected'] || $score['score'] < self::MIN_AUTO_SCORE) {
                continue;
            }
            $ranked[] = [
                'index' => $index,
                'area' => $host,
                'score' => $score['score'],
                'evidence' => $score,
            ];
        }
        if ($ranked === []) {
            return null;
        }
        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $best = $ranked[0];
        $runnerUp = $ranked[1]['score'] ?? 0.0;
        if (! $this->isUniquelyBest($best, $runnerUp)) {
            return null;
        }
        $evidence = $best['evidence'];
        $evidence['runner_up_score'] = round($runnerUp, 2);
        $evidence['reason'] = $this->reasonFromEvidence($evidence);

        return [
            'index' => $best['index'],
            'area' => $best['area'],
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $left  meetstaat/host
     * @param  array<string, mixed>  $right  drawing
     * @return array<string, mixed>
     */
    public function scoreCandidate(array $left, array $right): array
    {
        $leftFloor = $this->normalizeFloor((string) ($left['floor'] ?? ''));
        $rightFloor = $this->normalizeFloor((string) ($right['floor'] ?? ''));
        $floorOk = $leftFloor === '' || $rightFloor === ''
            || $leftFloor === 'onbekend' || $rightFloor === 'onbekend'
            || $leftFloor === $rightFloor;

        $leftNumber = $this->normalizeNumber((string) ($left['room_number'] ?? ''));
        $rightNumber = $this->normalizeNumber((string) ($right['room_number'] ?? ''));
        $exactNumber = $leftNumber !== '' && $rightNumber !== '' && $leftNumber === $rightNumber;

        $leftName = trim((string) ($left['room_name'] ?? ''));
        $rightName = trim((string) ($right['room_name'] ?? ''));
        $nameCompatible = $this->namesCompatible($leftName, $rightName);
        $nameConflict = $this->namesConflict($leftName, $rightName);

        $leftMeters = $this->compareMeters($left);
        $rightMeters = $this->compareMeters($right);
        $metersDelta = ($leftMeters !== null && $rightMeters !== null)
            ? abs($leftMeters - $rightMeters)
            : null;
        $metersExact = $metersDelta !== null && $metersDelta <= 0.05;
        $metersNear = $metersDelta !== null && $metersDelta <= 0.15;
        $metersSoft = $metersDelta !== null && $metersDelta <= 1.0;

        $geometry = $this->geometricallyNear($left, $right);
        $hasBothPositions = ($left['meter_x'] ?? $left['x'] ?? null) !== null
            && ($left['meter_y'] ?? $left['y'] ?? null) !== null
            && ($right['meter_x'] ?? $right['x'] ?? null) !== null
            && ($right['meter_y'] ?? $right['y'] ?? null) !== null;

        $pageLeft = (int) ($left['page'] ?? 0);
        $pageRight = (int) ($right['page'] ?? 0);
        $pageOk = $pageLeft < 1 || $pageRight < 1 || $pageLeft === $pageRight;

        $rejected = false;
        $rejectReason = null;
        if (! $floorOk) {
            $rejected = true;
            $rejectReason = 'floor_mismatch';
        } elseif (! $pageOk) {
            $rejected = true;
            $rejectReason = 'page_mismatch';
        } elseif ($exactNumber && $nameConflict && $metersDelta !== null && $metersDelta > 2.0) {
            $rejected = true;
            $rejectReason = 'hard_identity_conflict';
        } elseif (! $exactNumber && $metersDelta !== null && $metersDelta > 2.0) {
            // Zonder exact nummer mag een groot m²-verschil geen naam/geometrie-match forceren.
            $rejected = true;
            $rejectReason = 'meters_mismatch_without_number';
        } elseif (! $exactNumber && $leftNumber !== '' && $rightNumber !== '' && $nameConflict) {
            $rejected = true;
            $rejectReason = 'different_number_name_conflict';
        } elseif (! $exactNumber && $leftNumber !== '' && $rightNumber !== ''
            && ! $nameCompatible && ! ($metersExact || $metersNear)) {
            $rejected = true;
            $rejectReason = 'different_number_weak_evidence';
        }

        $score = 0.0;
        $signals = [
            'floor' => false,
            'exact_number' => false,
            'normalized_number' => false,
            'name' => false,
            'meters' => false,
            'geometry' => false,
            'meetstaat_task' => ($this->taskMeters($left) > 0.0001),
            'drawing_contour' => ($right['square_meters'] ?? null) !== null
                || ($right['contour_id'] ?? null) !== null
                || ($right['x'] ?? null) !== null,
        ];

        if ($floorOk && $leftFloor !== '' && $rightFloor !== '' && $leftFloor !== 'onbekend' && $rightFloor !== 'onbekend') {
            $score += 20.0;
            $signals['floor'] = true;
        } elseif ($floorOk) {
            $score += 8.0;
            $signals['floor'] = true;
        }

        if ($exactNumber) {
            $score += 100.0;
            $signals['exact_number'] = true;
            $signals['normalized_number'] = true;
        }

        $nameExact = $this->namesEqual($leftName, $rightName);
        $nameLoose = $nameCompatible && ! $nameExact;
        if ($nameExact) {
            $score += 50.0;
            $signals['name'] = true;
        } elseif ($nameCompatible) {
            // Substring/alias: zwakker dan exacte naam.
            $score += $nameLoose ? 18.0 : 35.0;
            $signals['name'] = true;
        } elseif ($nameConflict) {
            $score -= 30.0;
        }

        if ($metersExact) {
            $score += 40.0;
            $signals['meters'] = true;
        } elseif ($metersNear) {
            $score += 30.0;
            $signals['meters'] = true;
        } elseif ($metersSoft && ($exactNumber || $nameExact)) {
            $score += 12.0;
            $signals['meters'] = true;
        }

        if ($geometry && $hasBothPositions) {
            $score += 15.0;
            $signals['geometry'] = true;
        }

        // Route B: nummer ontbreekt → exacte/compatibele naam + passende m² vereist.
        if (! $exactNumber && ($leftNumber === '' || $rightNumber === '')) {
            if ($nameCompatible && ($metersExact || $metersNear)) {
                $score += $nameExact ? 20.0 : 10.0;
            } elseif ($nameExact && $signals['geometry']) {
                $score += 10.0;
            } else {
                $score = min($score, 60.0);
            }
        }

        // Route C: verschillende OCR-nummers maar naam+m² bevestigen één ruimte.
        if (! $exactNumber && $leftNumber !== '' && $rightNumber !== '' && $nameCompatible && ($metersExact || $metersNear)) {
            $score += $nameExact ? 20.0 : 8.0;
            $signals['normalized_number'] = true;
        }

        return [
            'score' => round($score, 2),
            'rejected' => $rejected,
            'reject_reason' => $rejectReason,
            'signals' => $signals,
            'meters_delta' => $metersDelta,
            'left_number' => $leftNumber,
            'right_number' => $rightNumber,
            'left_name' => $leftName,
            'right_name' => $rightName,
            'left_meters' => $leftMeters,
            'right_meters' => $rightMeters,
        ];
    }

    public function normalizeNumber(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/\s+/', '', $value) ?? $value;

        return $this->repairOcrRoomNumber($value);
    }

    /**
     * 00.0.077 → 0.07, 11.1.100 → 1.10, 22.0.055 → 2.05
     */
    private function repairOcrRoomNumber(string $value): string
    {
        if (preg_match('/^(\d{1,2})[.\-](\d{1,2})[.\-](\d{2,3})([a-z])?$/u', $value, $match)) {
            $floor = (int) substr($match[1], 0, 1);
            $mid = $match[2];
            $last = substr($match[3], -1);
            $suffix = $match[4] ?? '';

            return $floor.'.'.$mid.$last.$suffix;
        }

        return $value;
    }

    /**
     * @param  array{score: float, evidence?: array<string, mixed>}  $best
     */
    private function isUniquelyBest(array $best, float $runnerUp): bool
    {
        $score = (float) $best['score'];
        $signals = $best['evidence']['signals'] ?? [];

        // Uniek nummer op dezelfde bouwlaag: marge mag kleiner.
        if (! empty($signals['exact_number']) && $score >= 100) {
            return $score - $runnerUp >= 10.0 || $runnerUp < self::MIN_AUTO_SCORE;
        }

        // Naam + m² zonder nummer: strengere uniekheid.
        if (empty($signals['exact_number']) && ! empty($signals['name']) && ! empty($signals['meters'])) {
            return $score - $runnerUp >= self::MIN_SCORE_MARGIN;
        }

        return $score >= self::MIN_AUTO_SCORE && ($score - $runnerUp) >= self::MIN_SCORE_MARGIN;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function reasonFromEvidence(array $evidence): string
    {
        $signals = $evidence['signals'] ?? [];
        if (! empty($signals['exact_number']) && ! empty($signals['name'])) {
            return 'exact_floor_number_name';
        }
        if (! empty($signals['exact_number'])) {
            return 'exact_floor_number';
        }
        if (! empty($signals['normalized_number']) && ! empty($signals['name']) && ! empty($signals['meters'])) {
            return 'ocr_number_name_meters';
        }
        if (! empty($signals['name']) && ! empty($signals['meters']) && ! empty($signals['geometry'])) {
            return 'name_meters_geometry';
        }
        if (! empty($signals['name']) && ! empty($signals['meters'])) {
            return 'name_meters';
        }
        if (! empty($signals['name']) && ! empty($signals['geometry'])) {
            return 'name_geometry';
        }

        return 'combined_evidence';
    }

    private function normalizeFloor(string $floor): string
    {
        return mb_strtolower(trim($floor));
    }

    private function normalizeName(string $name): string
    {
        return PdfTextNormalizer::stripTrailingIndex(PdfTextNormalizer::name($name));
    }

    private function namesEqual(string $left, string $right): bool
    {
        return $this->normalizeName($left) !== ''
            && $this->normalizeName($left) === $this->normalizeName($right);
    }

    private function namesCompatible(string $left, string $right): bool
    {
        $a = $this->normalizeName($left);
        $b = $this->normalizeName($right);
        if ($a === '' || $b === '' || $this->isGenericLabel($a) || $this->isGenericLabel($b)) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;

            return mb_strlen($shorter) >= 5;
        }

        $aLetters = PdfTextNormalizer::collapseRuns(preg_replace('/[^a-z]/u', '', $a) ?? $a);
        $bLetters = PdfTextNormalizer::collapseRuns(preg_replace('/[^a-z]/u', '', $b) ?? $b);
        if ($aLetters !== '' && $aLetters === $bLetters) {
            return true;
        }

        // OCR: één teken verschil toegestaan bij korte unieke namen (≥5).
        if (mb_strlen($a) >= 5 && mb_strlen($b) >= 5 && levenshtein($a, $b) <= 1) {
            return true;
        }

        // Afgekapt tekeninglabel: "team-" hoort bij "teamruimte".
        $aPrefix = rtrim($a, '-–—');
        $bPrefix = rtrim($b, '-–—');
        if ($aPrefix !== $a || $bPrefix !== $b) {
            $shorter = mb_strlen($aPrefix) <= mb_strlen($bPrefix) ? $aPrefix : $bPrefix;
            $longer = $shorter === $aPrefix ? $bPrefix : $aPrefix;
            if (mb_strlen($shorter) >= 4 && str_starts_with($longer, $shorter)) {
                return true;
            }
        }

        return false;
    }

    private function namesConflict(string $left, string $right): bool
    {
        $a = $this->normalizeName($left);
        $b = $this->normalizeName($right);
        if ($a === '' || $b === '' || $this->isGenericLabel($a) || $this->isGenericLabel($b)) {
            return false;
        }

        return ! $this->namesCompatible($left, $right);
    }

    private function isGenericLabel(string $normalized): bool
    {
        if ($normalized === '' || $normalized === 'ruimte') {
            return true;
        }

        return (bool) preg_match('/^[0-3][.\-]\d{1,3}[a-z]?$/u', $normalized);
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function compareMeters(array $area): ?float
    {
        if (($area['square_meters'] ?? null) !== null) {
            return round((float) $area['square_meters'], 2);
        }
        $task = $this->taskMeters($area);
        if ($task > 0.0001) {
            // Voor matching: som taak-m² als zachte fingerprint, nooit als bewezen fysieke m².
            return round($task, 2);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function taskMeters(array $area): float
    {
        $sum = 0.0;
        foreach ($area['tasks'] ?? [] as $task) {
            if (! is_array($task)) {
                continue;
            }
            $unit = (string) ($task['unit'] ?? 'm2');
            if (! in_array($unit, ['m2', 'm²'], true)) {
                continue;
            }
            $name = mb_strtolower((string) ($task['work_name'] ?? ''));
            if (str_contains($name, 'plint')) {
                continue;
            }
            $sum += (float) ($task['quantity'] ?? 0);
        }

        return $sum;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function geometricallyNear(array $left, array $right): bool
    {
        $pageLeft = (int) ($left['page'] ?? 0);
        $pageRight = (int) ($right['page'] ?? 0);
        if ($pageLeft > 0 && $pageRight > 0 && $pageLeft !== $pageRight) {
            return false;
        }

        $lx = $left['meter_x'] ?? $left['x'] ?? null;
        $ly = $left['meter_y'] ?? $left['y'] ?? null;
        $rx = $right['meter_x'] ?? $right['x'] ?? null;
        $ry = $right['meter_y'] ?? $right['y'] ?? null;
        if ($lx === null || $ly === null || $rx === null || $ry === null) {
            return true;
        }

        $dx = (float) $lx - (float) $rx;
        $dy = (float) $ly - (float) $ry;

        return sqrt(($dx * $dx) + ($dy * $dy)) <= 120.0;
    }
}
