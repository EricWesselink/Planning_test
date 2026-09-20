<?php

namespace App\Services\AreaWithoutM2Trial;

/**
 * Collapse parallel wall edges to a midline and label outer walls vs dimension lines.
 */
class WallAxisAssembler
{
    public const ROLE_INTERNAL = 'interne wand';

    public const ROLE_OUTER = 'buitenwand';

    public const ROLE_DIMENSION = 'maatlijn';

    public const KIND_LINE = 'lijn';

    public const KIND_BAND = 'band';

    public const KIND_PAIR = 'paar';

    private const MIN_PAIR_GAP = 5.0;

    private const MAX_PAIR_GAP = 36.0;

    private const MIN_OVERLAP_RATIO = 0.35;

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return array{
     *     walls: list<array<string, mixed>>,
     *     bands_h: list<array<string, mixed>>,
     *     bands_v: list<array<string, mixed>>,
     *     axes: list<array<string, mixed>>
     * }
     */
    public function assemble(array $segments, float $pageWidth, float $pageHeight): array
    {
        $pageWidth = max(1.0, $pageWidth);
        $pageHeight = max(1.0, $pageHeight);
        $normalized = $this->normalize($segments);
        $bandsH = array_values(array_filter(
            $normalized,
            fn (array $wall): bool => ($wall['axis'] ?? '') === 'h' && ($wall['kind'] ?? '') === self::KIND_BAND,
        ));
        $bandsV = array_values(array_filter(
            $normalized,
            fn (array $wall): bool => ($wall['axis'] ?? '') === 'v' && ($wall['kind'] ?? '') === self::KIND_BAND,
        ));
        $collapsed = $this->collapsePairs($normalized);
        $minMain = max(32.0, min($pageWidth, $pageHeight) * 0.05);
        $classified = $this->classify($collapsed, $pageWidth, $pageHeight, $minMain);
        $walls = array_values(array_filter(
            $classified,
            fn (array $wall): bool => $this->usableAsBound($wall),
        ));

        return [
            'walls' => $walls,
            'bands_h' => $bandsH,
            'bands_v' => $bandsV,
            'axes' => $classified,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    private function normalize(array $segments): array
    {
        $out = [];
        foreach ($segments as $segment) {
            $axis = (string) ($segment['axis'] ?? '');
            if ($axis !== 'h' && $axis !== 'v') {
                continue;
            }
            $x1 = (float) ($segment['x1'] ?? 0);
            $y1 = (float) ($segment['y1'] ?? 0);
            $x2 = (float) ($segment['x2'] ?? 0);
            $y2 = (float) ($segment['y2'] ?? 0);
            $length = hypot($x2 - $x1, $y2 - $y1);
            if ($length < 16) {
                continue;
            }
            $out[] = [
                'x1' => $axis === 'v' ? (($x1 + $x2) / 2) : min($x1, $x2),
                'y1' => $axis === 'h' ? (($y1 + $y2) / 2) : min($y1, $y2),
                'x2' => $axis === 'v' ? (($x1 + $x2) / 2) : max($x1, $x2),
                'y2' => $axis === 'h' ? (($y1 + $y2) / 2) : max($y1, $y2),
                'axis' => $axis,
                'kind' => (string) ($segment['kind'] ?? self::KIND_LINE),
                'thickness' => (float) ($segment['thickness'] ?? 1),
                'role' => $segment['role'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @return list<array<string, mixed>>
     */
    private function collapsePairs(array $walls): array
    {
        $horizontal = [];
        $vertical = [];
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') === 'h') {
                $horizontal[] = $wall;
            } elseif (($wall['axis'] ?? '') === 'v') {
                $vertical[] = $wall;
            }
        }

        return array_merge(
            $this->collapseAxis($vertical, true),
            $this->collapseAxis($horizontal, false),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @return list<array<string, mixed>>
     */
    private function collapseAxis(array $walls, bool $vertical): array
    {
        if ($walls === []) {
            return [];
        }

        usort($walls, function (array $a, array $b) use ($vertical): int {
            $pos = $this->axisPos($a, $vertical) <=> $this->axisPos($b, $vertical);
            if ($pos !== 0) {
                return $pos;
            }

            return $this->spanStart($a, $vertical) <=> $this->spanStart($b, $vertical);
        });

        $used = [];
        $out = [];
        $count = count($walls);
        for ($i = 0; $i < $count; $i++) {
            if (isset($used[$i])) {
                continue;
            }
            $partner = null;
            $partnerGap = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (isset($used[$j])) {
                    continue;
                }
                $gap = $this->axisPos($walls[$j], $vertical) - $this->axisPos($walls[$i], $vertical);
                if ($gap < self::MIN_PAIR_GAP) {
                    continue;
                }
                if ($gap > self::MAX_PAIR_GAP) {
                    break;
                }
                if ($this->overlapRatio($walls[$i], $walls[$j], $vertical) < self::MIN_OVERLAP_RATIO) {
                    continue;
                }
                if (! $this->pairableLengths($walls[$i], $walls[$j], $vertical)) {
                    continue;
                }
                if ($partner === null || $gap < $partnerGap) {
                    $partner = $j;
                    $partnerGap = $gap;
                }
            }
            if ($partner === null) {
                $out[] = $walls[$i];

                continue;
            }
            $used[$i] = true;
            $used[$partner] = true;
            $out[] = $this->midline($walls[$i], $walls[$partner], $vertical, (float) $partnerGap);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array<string, mixed>
     */
    private function midline(array $left, array $right, bool $vertical, float $gap): array
    {
        $mid = ($this->axisPos($left, $vertical) + $this->axisPos($right, $vertical)) / 2;
        $start = min($this->spanStart($left, $vertical), $this->spanStart($right, $vertical));
        $end = max($this->spanEnd($left, $vertical), $this->spanEnd($right, $vertical));
        $kind = self::KIND_PAIR;
        if (($left['kind'] ?? '') === self::KIND_BAND || ($right['kind'] ?? '') === self::KIND_BAND) {
            $kind = self::KIND_BAND;
        }

        if ($vertical) {
            return [
                'x1' => $mid,
                'y1' => $start,
                'x2' => $mid,
                'y2' => $end,
                'axis' => 'v',
                'kind' => $kind,
                'thickness' => max($gap, (float) ($left['thickness'] ?? 1), (float) ($right['thickness'] ?? 1)),
                'role' => null,
            ];
        }

        return [
            'x1' => $start,
            'y1' => $mid,
            'x2' => $end,
            'y2' => $mid,
            'axis' => 'h',
            'kind' => $kind,
            'thickness' => max($gap, (float) ($left['thickness'] ?? 1), (float) ($right['thickness'] ?? 1)),
            'role' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @return list<array<string, mixed>>
     */
    private function classify(array $walls, float $pageWidth, float $pageHeight, float $minMain): array
    {
        $count = count($walls);
        $meetTol = max(8.0, min($pageWidth, $pageHeight) * 0.01);
        $neighbours = array_fill(0, $count, []);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (! $this->meets($walls[$i], $walls[$j], $meetTol)) {
                    continue;
                }
                $neighbours[$i][] = $j;
                $neighbours[$j][] = $i;
            }
        }

        $structural = [];
        foreach ($walls as $index => $wall) {
            if ($this->length($wall) < $minMain) {
                continue;
            }
            $junctions = $this->structuralJunctions($index, $walls, $neighbours, $minMain);
            $kind = (string) ($wall['kind'] ?? self::KIND_LINE);
            $thick = $kind === self::KIND_BAND || $kind === self::KIND_PAIR;
            if ($junctions >= 2 || ($thick && $junctions >= 1)) {
                $structural[] = $index;
            }
        }

        $bbox = $this->bbox(array_map(fn (int $index): array => $walls[$index], $structural));
        $edgeTol = max(12.0, min($pageWidth, $pageHeight) * 0.012);
        $outsideMargin = max(16.0, min($pageWidth, $pageHeight) * 0.015);

        foreach ($walls as $index => &$wall) {
            $junctions = $this->structuralJunctions($index, $walls, $neighbours, $minMain);
            $inCore = in_array($index, $structural, true);
            if ($bbox === null) {
                $wall['role'] = self::ROLE_INTERNAL;
                $wall['junctions'] = $junctions;

                continue;
            }
            if ($this->isIsolatedDimension($wall, $bbox, $outsideMargin, $junctions, $pageWidth, $pageHeight)) {
                $wall['role'] = self::ROLE_DIMENSION;
                $wall['junctions'] = $junctions;

                continue;
            }
            if (($inCore || $junctions >= 1) && $this->onEnvelope($wall, $bbox, $edgeTol)) {
                $wall['role'] = self::ROLE_OUTER;
                $wall['junctions'] = $junctions;

                continue;
            }
            $wall['role'] = self::ROLE_INTERNAL;
            $wall['junctions'] = $junctions;
        }
        unset($wall);

        return $walls;
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @param  list<list<int>>  $neighbours
     */
    private function structuralJunctions(int $index, array $walls, array $neighbours, float $minMain): int
    {
        $count = 0;
        foreach ($neighbours[$index] ?? [] as $other) {
            if ($this->length($walls[$other]) >= $minMain) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $wall
     * @param  array{left: float, right: float, bottom: float, top: float}  $bbox
     */
    private function isIsolatedDimension(
        array $wall,
        array $bbox,
        float $margin,
        int $junctions,
        float $pageWidth,
        float $pageHeight,
    ): bool {
        if ($junctions > 0) {
            return false;
        }
        if (($wall['kind'] ?? self::KIND_LINE) !== self::KIND_LINE) {
            return false;
        }
        if (! $this->outsideCore($wall, $bbox, $margin)) {
            return false;
        }
        $length = $this->length($wall);
        $pageSpan = ($wall['axis'] ?? '') === 'h' ? $pageWidth : $pageHeight;

        return $length >= $pageSpan * 0.55;
    }

    /**
     * Internal dark blobs (text, hatching) stay in debug axes; only lines, pairs and outer bands bound rooms.
     *
     * @param  array<string, mixed>  $wall
     */
    private function usableAsBound(array $wall): bool
    {
        $kind = (string) ($wall['kind'] ?? self::KIND_LINE);
        $role = (string) ($wall['role'] ?? '');
        if ($kind === self::KIND_BAND && $role !== self::ROLE_OUTER) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function pairableLengths(array $a, array $b, bool $vertical): bool
    {
        $lenA = $this->spanEnd($a, $vertical) - $this->spanStart($a, $vertical);
        $lenB = $this->spanEnd($b, $vertical) - $this->spanStart($b, $vertical);
        $longer = max($lenA, $lenB);
        $shorter = min($lenA, $lenB);
        if ($shorter < 40) {
            return false;
        }

        return $longer < 1 ? false : ($shorter / $longer) >= 0.25;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function meets(array $a, array $b, float $tol): bool
    {
        if (($a['axis'] ?? '') === ($b['axis'] ?? '')) {
            return false;
        }
        $vertical = ($a['axis'] ?? '') === 'v' ? $a : $b;
        $horizontal = ($a['axis'] ?? '') === 'h' ? $a : $b;
        $x = (float) $vertical['x1'];
        $y = (float) $horizontal['y1'];

        return $x >= ((float) $horizontal['x1'] - $tol)
            && $x <= ((float) $horizontal['x2'] + $tol)
            && $y >= ((float) $vertical['y1'] - $tol)
            && $y <= ((float) $vertical['y2'] + $tol);
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @return array{left: float, right: float, bottom: float, top: float}|null
     */
    private function bbox(array $walls): ?array
    {
        if ($walls === []) {
            return null;
        }
        $left = $bottom = INF;
        $right = $top = -INF;
        foreach ($walls as $wall) {
            $left = min($left, (float) $wall['x1'], (float) $wall['x2']);
            $right = max($right, (float) $wall['x1'], (float) $wall['x2']);
            $bottom = min($bottom, (float) $wall['y1'], (float) $wall['y2']);
            $top = max($top, (float) $wall['y1'], (float) $wall['y2']);
        }

        return [
            'left' => $left,
            'right' => $right,
            'bottom' => $bottom,
            'top' => $top,
        ];
    }

    /**
     * @param  array<string, mixed>  $wall
     * @param  array{left: float, right: float, bottom: float, top: float}  $bbox
     */
    private function onEnvelope(array $wall, array $bbox, float $tol): bool
    {
        if (($wall['axis'] ?? '') === 'v') {
            $x = (float) $wall['x1'];
            $overlap = min((float) $wall['y2'], $bbox['top']) - max((float) $wall['y1'], $bbox['bottom']);
            $edge = min(abs($x - $bbox['left']), abs($x - $bbox['right']));

            return $edge <= $tol && $overlap >= 8;
        }
        $y = (float) $wall['y1'];
        $overlap = min((float) $wall['x2'], $bbox['right']) - max((float) $wall['x1'], $bbox['left']);
        $edge = min(abs($y - $bbox['bottom']), abs($y - $bbox['top']));

        return $edge <= $tol && $overlap >= 8;
    }

    /**
     * @param  array<string, mixed>  $wall
     * @param  array{left: float, right: float, bottom: float, top: float}  $bbox
     */
    private function outsideCore(array $wall, array $bbox, float $margin): bool
    {
        if (($wall['axis'] ?? '') === 'v') {
            $x = (float) $wall['x1'];

            return $x < $bbox['left'] - $margin || $x > $bbox['right'] + $margin;
        }
        $y = (float) $wall['y1'];

        return $y < $bbox['bottom'] - $margin || $y > $bbox['top'] + $margin;
    }

    /**
     * @param  array<string, mixed>  $wall
     */
    private function axisPos(array $wall, bool $vertical): float
    {
        return $vertical ? (float) $wall['x1'] : (float) $wall['y1'];
    }

    /**
     * @param  array<string, mixed>  $wall
     */
    private function spanStart(array $wall, bool $vertical): float
    {
        return $vertical ? (float) $wall['y1'] : (float) $wall['x1'];
    }

    /**
     * @param  array<string, mixed>  $wall
     */
    private function spanEnd(array $wall, bool $vertical): float
    {
        return $vertical ? (float) $wall['y2'] : (float) $wall['x2'];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function overlapRatio(array $a, array $b, bool $vertical): float
    {
        $start = max($this->spanStart($a, $vertical), $this->spanStart($b, $vertical));
        $end = min($this->spanEnd($a, $vertical), $this->spanEnd($b, $vertical));
        $overlap = $end - $start;
        if ($overlap <= 0) {
            return 0.0;
        }
        $shorter = min(
            $this->spanEnd($a, $vertical) - $this->spanStart($a, $vertical),
            $this->spanEnd($b, $vertical) - $this->spanStart($b, $vertical),
        );

        return $shorter < 1 ? 0.0 : $overlap / $shorter;
    }

    /**
     * @param  array<string, mixed>  $wall
     */
    private function length(array $wall): float
    {
        return hypot((float) $wall['x2'] - (float) $wall['x1'], (float) $wall['y2'] - (float) $wall['y1']);
    }
}
