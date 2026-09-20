<?php

namespace App\Services\AreaWithoutM2Trial;

/**
 * Rebuild a full measure chain from collinear millimetre labels, then place
 * each segment on extension-line / wall-axis positions by millimetre ratios.
 */
class DimensionChainReconstructor
{
    /**
     * @param  list<array{mm: int, text: string, x?: float, y?: float, page?: int}>  $numbers
     * @param  list<array<string, mixed>>  $lines
     * @return array{
     *     objects: list<array<string, mixed>>,
     *     chains: list<array<string, mixed>>,
     *     misses: list<array{mm: int, text: string, x: float, y: float, page: int, reason: string}>
     * }
     */
    public function reconstruct(array $numbers, array $lines, float $width, float $height): array
    {
        $pageMin = min(max($width, 1.0), max($height, 1.0));
        $vAxes = $this->axisPositions($lines, 'v');
        $hAxes = $this->axisPositions($lines, 'h');
        $objects = [];
        $chains = [];
        $used = [];
        $missReasons = [];

        foreach (['horizontal' => true, 'vertical' => false] as $orientation => $horizontal) {
            $axes = $horizontal ? $vAxes : $hAxes;
            $groups = $this->groups($numbers, $horizontal, $vAxes, $hAxes, $pageMin);
            $index = 1;
            foreach ($groups as $group) {
                $built = $this->rebuildGroup($group, $axes, $orientation, $horizontal, $index, $pageMin);
                if ($built === null) {
                    if (count($group) >= 2) {
                        foreach ($group as $row) {
                            $missReasons[$this->candidateKey($row)] = [
                                'mm' => (int) $row['mm'],
                                'text' => (string) ($row['text'] ?? $row['mm']),
                                'x' => (float) ($row['x'] ?? 0),
                                'y' => (float) ($row['y'] ?? 0),
                                'page' => (int) ($row['page'] ?? 1),
                                'reason' => 'extension-lines niet gevonden',
                            ];
                        }
                    }

                    continue;
                }
                $chains[] = $built['chain'];
                $index++;
                foreach ($built['objects'] as $object) {
                    $key = $this->candidateKey($object);
                    $objects[$key] = $object;
                    $used[$key] = true;
                }
            }
        }

        foreach ($this->copyOntoAlignedDuplicates($numbers, array_values($objects), $used) as $object) {
            $key = $this->candidateKey($object);
            $objects[$key] = $object;
            $used[$key] = true;
        }

        $misses = [];
        foreach ($numbers as $row) {
            $key = $this->candidateKey($row);
            if (isset($used[$key])) {
                continue;
            }
            if (isset($missReasons[$key])) {
                $misses[] = $missReasons[$key];

                continue;
            }
            $reason = $this->stripMissReason($row, $vAxes, $hAxes, $pageMin);
            if ($reason !== null) {
                $misses[] = [
                    'mm' => (int) $row['mm'],
                    'text' => (string) ($row['text'] ?? $row['mm']),
                    'x' => (float) ($row['x'] ?? 0),
                    'y' => (float) ($row['y'] ?? 0),
                    'page' => (int) ($row['page'] ?? 1),
                    'reason' => $reason,
                ];
            }
        }

        return [
            'objects' => array_values($objects),
            'chains' => $chains,
            'misses' => $misses,
        ];
    }

    /**
     * @param  list<array{mm: int, text?: string, x?: float, y?: float, page?: int}>  $group
     * @param  list<float>  $axes
     * @return array{objects: list<array<string, mixed>>, chain: array<string, mixed>}|null
     */
    private function rebuildGroup(
        array $group,
        array $axes,
        string $orientation,
        bool $horizontal,
        int $index,
        float $pageMin,
    ): ?array {
        if (count($group) < 2 || count($axes) < 2) {
            return null;
        }
        $overallKeys = $this->overallKeys($group);
        $segments = [];
        $overall = null;
        foreach ($this->sortGroup($group, $horizontal) as $row) {
            if (isset($overallKeys[$this->candidateKey($row)])) {
                $overall = $row;

                continue;
            }
            $segments[] = $row;
        }
        $windows = $this->segmentWindows($segments, $horizontal);
        $best = null;
        foreach ($windows as $window) {
            $fit = $this->fitWindow($window, $axes, $horizontal, $pageMin);
            if ($fit === null) {
                continue;
            }
            $sum = 0;
            foreach ($window as $row) {
                $sum += (int) $row['mm'];
            }
            $overallMm = is_array($overall) ? (int) $overall['mm'] : null;
            $sumMatches = $overallMm !== null && $sum >= 800 && abs($overallMm - $sum) / max($overallMm, 1) <= 0.08;
            $score = $fit['error']
                + ($fit['center'] ?? 0) * 0.02
                - (count($window) * 0.01)
                - ($sumMatches ? 0.16 : 0.0);
            if ($best !== null && $score >= $best['score']) {
                continue;
            }
            $best = [
                'score' => $score,
                'window' => $window,
                'bounds' => $fit['bounds'],
                'error' => $fit['error'],
                'sum' => $sum,
                'valid' => $sumMatches || $fit['error'] <= 0.12,
                'overall_mm' => $sumMatches ? $overallMm : null,
                'overall_row' => $sumMatches ? $overall : null,
            ];
        }
        if ($best === null) {
            return null;
        }

        $label = ($horizontal ? 'H' : 'V').$index;
        $along = $this->groupAlong($best['window'], $horizontal);
        $objects = [];
        $values = [];
        foreach ($best['window'] as $i => $row) {
            $values[] = (int) $row['mm'];
            $objects[] = $this->objectFromBounds(
                $row,
                (float) $best['bounds'][$i],
                (float) $best['bounds'][$i + 1],
                $along,
                $orientation,
                'maatketting + extension-lines',
                $best['valid'] ? 0.92 : 0.8,
                $label,
                $best['bounds'],
                false,
            );
        }
        if (is_array($best['overall_row'])) {
            $objects[] = $this->objectFromBounds(
                $best['overall_row'],
                (float) $best['bounds'][0],
                (float) $best['bounds'][count($best['bounds']) - 1],
                $along,
                $orientation,
                'gebouwmaat',
                0.78,
                $label,
                $best['bounds'],
                true,
            );
        }

        return [
            'objects' => $objects,
            'chain' => [
                'id' => $label,
                'orientation' => $orientation,
                'segments' => $values,
                'extensions' => array_map(fn (float $pos): float => round($pos, 1), $best['bounds']),
                'total' => $best['overall_mm'],
                'sum' => $best['sum'],
                'valid' => $best['valid'],
            ],
        ];
    }

    /**
     * @param  list<array{mm: int, x?: float, y?: float}>  $segments
     * @return list<list<array{mm: int, x?: float, y?: float}>>
     */
    private function segmentWindows(array $segments, bool $horizontal): array
    {
        $n = count($segments);
        if ($n < 2) {
            return [];
        }
        $windows = [];
        for ($length = $n; $length >= 2; $length--) {
            for ($start = 0; $start <= $n - $length; $start++) {
                $window = array_slice($segments, $start, $length);
                $separated = true;
                for ($i = 1; $i < count($window); $i++) {
                    $gap = $horizontal
                        ? abs((float) ($window[$i - 1]['x'] ?? 0) - (float) ($window[$i]['x'] ?? 0))
                        : abs((float) ($window[$i - 1]['y'] ?? 0) - (float) ($window[$i]['y'] ?? 0));
                    if ($gap < 12) {
                        $separated = false;
                        break;
                    }
                }
                if ($separated) {
                    $windows[] = $window;
                }
            }
        }

        return $windows;
    }

    /**
     * @param  list<array{mm: int, x?: float, y?: float}>  $window
     * @param  list<float>  $axes
     * @return array{bounds: list<float>, error: float}|null
     */
    private function fitWindow(array $window, array $axes, bool $horizontal, float $pageMin): ?array
    {
        $count = count($window);
        $sum = 0;
        foreach ($window as $row) {
            $sum += (int) $row['mm'];
        }
        if ($sum < 1) {
            return null;
        }
        $best = null;
        $slack = max(24.0, $pageMin * 0.03);
        foreach ($this->candidateBounds($axes, $count + 1) as $bounds) {
            $totalPx = $bounds[$count] - $bounds[0];
            if ($totalPx < 16) {
                continue;
            }
            $error = 0.0;
            $center = 0.0;
            $ok = true;
            foreach ($window as $i => $row) {
                $span = $bounds[$i + 1] - $bounds[$i];
                if ($span < 12) {
                    $ok = false;
                    break;
                }
                $ratioErr = abs(($span / $totalPx) - ((int) $row['mm'] / $sum));
                $error += $ratioErr * $ratioErr;
                $along = $horizontal ? (float) ($row['x'] ?? 0) : (float) ($row['y'] ?? 0);
                $center += abs($along - (($bounds[$i] + $bounds[$i + 1]) / 2)) / $span;
                if ($along < $bounds[$i] - $slack || $along > $bounds[$i + 1] + $slack) {
                    $ok = false;
                    break;
                }
            }
            $maxErr = $window === [] ? 1.0 : sqrt($error / count($window));
            $centerErr = $window === [] ? 1.0 : $center / count($window);
            if (! $ok || $maxErr > 0.22) {
                continue;
            }
            $better = $best === null
                || $maxErr < $best['error'] - 0.002
                || (abs($maxErr - $best['error']) <= 0.002 && $centerErr < ($best['center'] ?? 99));
            if ($better) {
                $best = ['bounds' => $bounds, 'error' => $maxErr, 'center' => $centerErr, 'total_px' => $totalPx];
            }
        }

        return $best;
    }

    /**
     * @param  list<float>  $axes
     * @return list<list<float>>
     */
    private function candidateBounds(array $axes, int $count): array
    {
        $n = count($axes);
        if ($n < $count || $count < 2) {
            return [];
        }
        $out = [];
        $maxSkip = min(4, $n - $count);
        for ($skip = 0; $skip <= $maxSkip; $skip++) {
            $windowLen = $count + $skip;
            for ($start = 0; $start <= $n - $windowLen; $start++) {
                $end = $start + $windowLen - 1;
                $need = $count - 2;
                $interior = $end - $start > 1 ? range($start + 1, $end - 1) : [];
                foreach ($this->indexCombinations($interior, $need) as $picked) {
                    $idx = array_merge([$start], $picked, [$end]);
                    $out[] = array_map(fn (int $i): float => $axes[$i], $idx);
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $items
     * @return list<list<int>>
     */
    private function indexCombinations(array $items, int $k): array
    {
        $items = array_values($items);
        $n = count($items);
        if ($k === 0) {
            return [[]];
        }
        if ($k > $n || $k < 0) {
            return [];
        }
        $result = [];
        $combo = range(0, $k - 1);
        while (true) {
            $picked = [];
            foreach ($combo as $i) {
                $picked[] = $items[$i];
            }
            $result[] = $picked;
            $i = $k - 1;
            while ($i >= 0 && $combo[$i] === $n - $k + $i) {
                $i--;
            }
            if ($i < 0) {
                break;
            }
            $combo[$i]++;
            for ($j = $i + 1; $j < $k; $j++) {
                $combo[$j] = $combo[$j - 1] + 1;
            }
        }

        return $result;
    }

    /**
     * @param  list<array{mm: int, x?: float, y?: float, page?: int}>  $numbers
     * @param  list<float>  $vAxes
     * @param  list<float>  $hAxes
     * @return list<list<array{mm: int, text?: string, x?: float, y?: float, page?: int}>>
     */
    private function groups(array $numbers, bool $horizontal, array $vAxes, array $hAxes, float $pageMin): array
    {
        $groups = [];
        $seen = [];
        foreach ($this->collinearGroups($numbers, $horizontal, $pageMin) as $group) {
            if (count($group) < 2) {
                continue;
            }
            if (! $horizontal && ! $this->liesBesideBuilding($group, $vAxes, $pageMin)) {
                continue;
            }
            $groups[] = $group;
            $seen[implode('+', array_map($this->candidateKey(...), $group))] = true;
        }
        foreach ($this->stripGroups($numbers, $horizontal, $vAxes, $hAxes, $pageMin) as $group) {
            if (count($group) < 2) {
                continue;
            }
            $key = implode('+', array_map($this->candidateKey(...), $group));
            if (! isset($seen[$key])) {
                $groups[] = $group;
                $seen[$key] = true;
            }
        }

        return $groups;
    }

    /**
     * @param  list<array{mm: int, x?: float, y?: float}>  $open
     * @return list<list<array{mm: int, text?: string, x?: float, y?: float, page?: int}>>
     */
    private function collinearGroups(array $open, bool $horizontal, float $pageMin): array
    {
        $tolerance = $horizontal ? max(48.0, $pageMin * 0.05) : max(80.0, $pageMin * 0.07);
        $items = $open;
        usort($items, function (array $a, array $b) use ($horizontal): int {
            $aAlong = $horizontal ? (float) ($a['y'] ?? 0) : (float) ($a['x'] ?? 0);
            $bAlong = $horizontal ? (float) ($b['y'] ?? 0) : (float) ($b['x'] ?? 0);
            if ($aAlong === $bAlong) {
                return ($horizontal ? (float) ($a['x'] ?? 0) : (float) ($a['y'] ?? 0))
                    <=> ($horizontal ? (float) ($b['x'] ?? 0) : (float) ($b['y'] ?? 0));
            }

            return $aAlong <=> $bAlong;
        });

        $groups = [];
        $current = [];
        $currentAlong = null;
        foreach ($items as $item) {
            $along = $horizontal ? (float) ($item['y'] ?? 0) : (float) ($item['x'] ?? 0);
            if ($current === [] || abs($along - (float) $currentAlong) <= $tolerance) {
                $current[] = $item;
                $currentAlong = $currentAlong === null
                    ? $along
                    : (($currentAlong * (count($current) - 1)) + $along) / count($current);

                continue;
            }
            $groups[] = $this->sortGroup($current, $horizontal);
            $current = [$item];
            $currentAlong = $along;
        }
        if ($current !== []) {
            $groups[] = $this->sortGroup($current, $horizontal);
        }

        return $groups;
    }

    /**
     * @param  list<array{mm: int, x?: float, y?: float}>  $numbers
     * @param  list<float>  $vAxes
     * @param  list<float>  $hAxes
     * @return list<list<array{mm: int, x?: float, y?: float}>>
     */
    private function stripGroups(array $numbers, bool $horizontal, array $vAxes, array $hAxes, float $pageMin): array
    {
        if ($vAxes === [] || $hAxes === []) {
            return [];
        }
        $vMin = min($vAxes);
        $vMax = max($vAxes);
        $hMin = min($hAxes);
        $hMax = max($hAxes);
        $margin = max(24.0, $pageMin * 0.03);
        $groups = [];
        if ($horizontal) {
            $groups[] = $this->sortGroup(array_values(array_filter(
                $numbers,
                fn (array $row): bool => (float) ($row['y'] ?? 0) <= $hMin + $margin,
            )), true);
            $groups[] = $this->sortGroup(array_values(array_filter(
                $numbers,
                fn (array $row): bool => (float) ($row['y'] ?? 0) >= $hMax - $margin,
            )), true);
        } else {
            $groups[] = $this->sortGroup(array_values(array_filter(
                $numbers,
                fn (array $row): bool => (float) ($row['x'] ?? 0) >= $vMax - $margin,
            )), false);
            $groups[] = $this->sortGroup(array_values(array_filter(
                $numbers,
                fn (array $row): bool => (float) ($row['x'] ?? 0) <= $vMin + $margin,
            )), false);
        }

        return array_values(array_filter($groups, fn (array $group): bool => count($group) >= 2));
    }

    /**
     * Vertical chains sit beside the building. Interior labels with the same X are stacked widths, not a height chain.
     *
     * @param  list<array{x?: float, y?: float}>  $group
     * @param  list<float>  $vAxes
     */
    private function liesBesideBuilding(array $group, array $vAxes, float $pageMin): bool
    {
        if ($vAxes === []) {
            return true;
        }
        $margin = max(36.0, $pageMin * 0.04);
        $x = $this->groupAlong($group, false);

        return $x <= min($vAxes) + $margin || $x >= max($vAxes) - $margin;
    }

    /**
     * @param  list<array{mm: int, x?: float, y?: float}>  $group
     * @return list<array{mm: int, x?: float, y?: float}>
     */
    private function sortGroup(array $group, bool $horizontal): array
    {
        usort($group, function (array $a, array $b) use ($horizontal): int {
            $aPos = $horizontal ? (float) ($a['x'] ?? 0) : (float) ($a['y'] ?? 0);
            $bPos = $horizontal ? (float) ($b['x'] ?? 0) : (float) ($b['y'] ?? 0);

            return $aPos <=> $bPos;
        });

        return $group;
    }

    /**
     * @param  list<array{mm: int, x?: float, y?: float}>  $group
     * @return array<string, true>
     */
    private function overallKeys(array $group): array
    {
        $keys = [];
        $total = 0;
        foreach ($group as $row) {
            $total += (int) $row['mm'];
        }
        foreach ($group as $row) {
            $others = $total - (int) $row['mm'];
            $largestOther = 0;
            foreach ($group as $other) {
                if ($this->candidateKey($other) === $this->candidateKey($row)) {
                    continue;
                }
                $largestOther = max($largestOther, (int) $other['mm']);
            }
            if (count($group) >= 3 && (int) $row['mm'] > $largestOther && $largestOther > 0 && (
                ($others >= 800 && abs((int) $row['mm'] - $others) / max($others, 1) <= 0.08)
                || (int) $row['mm'] >= (int) round($largestOther * 1.45)
            )) {
                $keys[$this->candidateKey($row)] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<float>
     */
    private function axisPositions(array $lines, string $axis): array
    {
        $positions = [];
        foreach ($lines as $line) {
            if (($line['axis'] ?? '') !== $axis) {
                continue;
            }
            $pos = $axis === 'v'
                ? ((float) $line['x1'] + (float) $line['x2']) / 2
                : ((float) $line['y1'] + (float) $line['y2']) / 2;
            $positions[(string) round($pos, 1)] = $pos;
        }
        $list = array_values($positions);
        sort($list);

        return $list;
    }

    /**
     * @param  list<array{x?: float, y?: float}>  $group
     */
    private function groupAlong(array $group, bool $horizontal): float
    {
        $sum = 0.0;
        foreach ($group as $row) {
            $sum += $horizontal ? (float) ($row['y'] ?? 0) : (float) ($row['x'] ?? 0);
        }

        return $group === [] ? 0.0 : $sum / count($group);
    }

    /**
     * @param  array{mm: int, text?: string, x?: float, y?: float, page?: int}  $row
     * @param  list<float>  $extensions
     * @return array<string, mixed>
     */
    private function objectFromBounds(
        array $row,
        float $start,
        float $end,
        float $along,
        string $orientation,
        string $evidence,
        float $confidence,
        string $chainId,
        array $extensions,
        bool $overall,
    ): array {
        $lo = min($start, $end);
        $hi = max($start, $end);
        if ($orientation === 'horizontal') {
            $endpoint1 = ['x' => $lo, 'y' => $along];
            $endpoint2 = ['x' => $hi, 'y' => $along];
        } else {
            $endpoint1 = ['x' => $along, 'y' => $lo];
            $endpoint2 = ['x' => $along, 'y' => $hi];
        }

        return [
            'mm' => (int) $row['mm'],
            'value' => (int) $row['mm'],
            'text' => (string) ($row['text'] ?? $row['mm']),
            'x' => (float) ($row['x'] ?? 0),
            'y' => (float) ($row['y'] ?? 0),
            'page' => (int) ($row['page'] ?? 1),
            'orientation' => $orientation,
            'dimensionLine' => [
                'x1' => (float) $endpoint1['x'],
                'y1' => (float) $endpoint1['y'],
                'x2' => (float) $endpoint2['x'],
                'y2' => (float) $endpoint2['y'],
            ],
            'endpoint1' => $endpoint1,
            'endpoint2' => $endpoint2,
            'source' => 'chain',
            'evidence' => $evidence,
            'confidence' => $confidence,
            'chain_id' => $chainId,
            'extensions' => $extensions,
            'overall' => $overall,
        ];
    }

    /**
     * @param  list<array{mm: int, x?: float, y?: float, page?: int}>  $numbers
     * @param  list<array<string, mixed>>  $objects
     * @param  array<string, true>  $used
     * @return list<array<string, mixed>>
     */
    private function copyOntoAlignedDuplicates(array $numbers, array $objects, array $used): array
    {
        $copies = [];
        foreach ($numbers as $row) {
            $key = $this->candidateKey($row);
            if (isset($used[$key])) {
                continue;
            }
            foreach ($objects as $object) {
                if ((int) $object['mm'] !== (int) $row['mm'] || ($object['overall'] ?? false)) {
                    continue;
                }
                $horizontal = ($object['orientation'] ?? '') === 'horizontal';
                $start = $horizontal
                    ? min((float) $object['endpoint1']['x'], (float) $object['endpoint2']['x'])
                    : min((float) $object['endpoint1']['y'], (float) $object['endpoint2']['y']);
                $end = $horizontal
                    ? max((float) $object['endpoint1']['x'], (float) $object['endpoint2']['x'])
                    : max((float) $object['endpoint1']['y'], (float) $object['endpoint2']['y']);
                $along = $horizontal ? (float) ($row['x'] ?? 0) : (float) ($row['y'] ?? 0);
                if ($along < $start + 8 || $along > $end - 8) {
                    continue;
                }
                $copy = $object;
                $copy['x'] = (float) ($row['x'] ?? 0);
                $copy['y'] = (float) ($row['y'] ?? 0);
                $copy['text'] = (string) ($row['text'] ?? $row['mm']);
                $copy['page'] = (int) ($row['page'] ?? 1);
                $copies[] = $copy;
                break;
            }
        }

        return $copies;
    }

    /**
     * @param  array{x?: float, y?: float}  $row
     * @param  list<float>  $vAxes
     * @param  list<float>  $hAxes
     */
    private function stripMissReason(array $row, array $vAxes, array $hAxes, float $pageMin): ?string
    {
        if ($vAxes === [] || $hAxes === []) {
            return null;
        }
        $margin = max(24.0, $pageMin * 0.03);
        $x = (float) ($row['x'] ?? 0);
        $y = (float) ($row['y'] ?? 0);
        if ($x >= max($vAxes) - $margin || $x <= min($vAxes) + $margin) {
            return 'geen verticale ketting';
        }
        if ($y <= min($hAxes) + $margin || $y >= max($hAxes) - $margin) {
            return 'geen horizontale ketting';
        }

        return null;
    }

    /**
     * @param  array{mm?: int, x?: float, y?: float}  $row
     */
    private function candidateKey(array $row): string
    {
        return round((float) ($row['x'] ?? 0), 1).'|'.round((float) ($row['y'] ?? 0), 1).'|'.(int) ($row['mm'] ?? 0);
    }
}
