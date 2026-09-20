<?php

namespace App\Services\AreaWithoutM2Trial;

/**
 * Bind a millimetre label to the two extension lines / ticks of its dimension chain.
 */
class DimensionChainBinder
{
    private const SPAN_INSET = 10.0;

    /**
     * @param  array<string, mixed>  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @param  array{left: ?float, right: ?float, top: ?float, bottom: ?float}  $sides
     * @param  array{x: float, y: float, page?: int, room_key?: string, room_number?: string}  $anchor
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $anchors
     * @return array<string, mixed>|null
     */
    public function asWidth(array $dimension, array $lines, array $sides, array $anchor, array $anchors, float $pageMin, ?int $printedScale): ?array
    {
        $chain = $this->horizontalChain($dimension, $lines, $pageMin);
        if ($chain === null) {
            return null;
        }
        if ($this->spansOtherRooms($chain['start'], $chain['end'], true, $anchor, $anchors, $sides)) {
            return null;
        }
        if (! $this->chainFitsRoom($chain, $sides['left'], $sides['right'], $anchor, true, $pageMin)) {
            return null;
        }
        if (! $this->scaleLooksPlausible($printedScale, (int) $dimension['mm'], $chain['span'])) {
            return null;
        }

        return [
            'mm' => (int) $dimension['mm'],
            'x' => (float) $dimension['x'],
            'y' => (float) $dimension['y'],
            'axis' => 'horizontal',
            'wall_label' => 'maatketting x='.round($chain['start']).'–'.round($chain['end']).' op y='.round($chain['along']),
            'segment' => 'x='.round($chain['start']).'–'.round($chain['end']).', y='.round($chain['along']),
            'score' => 1.2 / max(1.0, $chain['distance']),
            'side' => 'ketting',
            'span_px' => $chain['span'],
            'source' => 'chain',
            'overlay' => [
                'x1' => $chain['start'],
                'y1' => $chain['along'],
                'x2' => $chain['end'],
                'y2' => $chain['along'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @param  array{left: ?float, right: ?float, top: ?float, bottom: ?float}  $sides
     * @param  array{x: float, y: float, page?: int, room_key?: string, room_number?: string}  $anchor
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $anchors
     * @return array<string, mixed>|null
     */
    public function asHeight(array $dimension, array $lines, array $sides, array $anchor, array $anchors, float $pageMin, ?int $printedScale): ?array
    {
        $chain = $this->verticalChain($dimension, $lines, $pageMin);
        if ($chain === null) {
            return null;
        }
        if ($this->spansOtherRooms($chain['start'], $chain['end'], false, $anchor, $anchors, $sides)) {
            return null;
        }
        if (! $this->chainFitsRoom($chain, $sides['bottom'], $sides['top'], $anchor, false, $pageMin)) {
            return null;
        }
        if (! $this->scaleLooksPlausible($printedScale, (int) $dimension['mm'], $chain['span'])) {
            return null;
        }

        return [
            'mm' => (int) $dimension['mm'],
            'x' => (float) $dimension['x'],
            'y' => (float) $dimension['y'],
            'axis' => 'vertical',
            'wall_label' => 'maatketting y='.round($chain['start']).'–'.round($chain['end']).' op x='.round($chain['along']),
            'segment' => 'y='.round($chain['start']).'–'.round($chain['end']).', x='.round($chain['along']),
            'score' => 1.2 / max(1.0, $chain['distance']),
            'side' => 'ketting',
            'span_px' => $chain['span'],
            'source' => 'chain',
            'overlay' => [
                'x1' => $chain['along'],
                'y1' => $chain['start'],
                'x2' => $chain['along'],
                'y2' => $chain['end'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @param  array{x: float, y: float, page?: int, room_key?: string, room_number?: string}  $anchor
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $anchors
     * @param  array{left: ?float, right: ?float, top: ?float, bottom: ?float}  $sides
     */
    public function rejectReason(array $dimension, array $lines, array $anchor, array $anchors, float $pageMin, array $sides = []): ?string
    {
        $horizontal = $this->horizontalChain($dimension, $lines, $pageMin);
        if ($horizontal !== null && $this->spansOtherRooms($horizontal['start'], $horizontal['end'], true, $anchor, $anchors, $sides)) {
            return 'totale/stramienmaat; maatsegment overspant meerdere ruimtes';
        }
        $vertical = $this->verticalChain($dimension, $lines, $pageMin);
        if ($vertical !== null && $this->spansOtherRooms($vertical['start'], $vertical['end'], false, $anchor, $anchors, $sides)) {
            return 'totale/stramienmaat; maatsegment overspant meerdere ruimtes';
        }

        return null;
    }

    /**
     * @param  array{x: float, y: float}  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    public function horizontalChain(array $dimension, array $lines, float $pageMin): ?array
    {
        $slack = max(18.0, $pageMin * 0.018);
        $fromLine = $this->nearestAxisLine($dimension, $lines, 'h', $slack);
        if ($fromLine !== null) {
            return $fromLine;
        }

        return $this->fromPerpendicularTicks($dimension, $lines, 'v', $slack);
    }

    /**
     * @param  array{x: float, y: float}  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    public function verticalChain(array $dimension, array $lines, float $pageMin): ?array
    {
        $slack = max(18.0, $pageMin * 0.018);
        $fromLine = $this->nearestAxisLine($dimension, $lines, 'v', $slack);
        if ($fromLine !== null) {
            return $fromLine;
        }

        return $this->fromPerpendicularTicks($dimension, $lines, 'h', $slack);
    }

    /**
     * @param  array{x: float, y: float}  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    private function nearestAxisLine(array $dimension, array $lines, string $axis, float $slack): ?array
    {
        $x = (float) $dimension['x'];
        $y = (float) $dimension['y'];
        $best = null;
        foreach ($lines as $line) {
            if (($line['axis'] ?? '') !== $axis) {
                continue;
            }
            if ($axis === 'h') {
                $along = ((float) $line['y1'] + (float) $line['y2']) / 2;
                $distance = abs($along - $y);
                $start = min((float) $line['x1'], (float) $line['x2']);
                $end = max((float) $line['x1'], (float) $line['x2']);
                if ($x < $start - $slack || $x > $end + $slack) {
                    continue;
                }
            } else {
                $along = ((float) $line['x1'] + (float) $line['x2']) / 2;
                $distance = abs($along - $x);
                $start = min((float) $line['y1'], (float) $line['y2']);
                $end = max((float) $line['y1'], (float) $line['y2']);
                if ($y < $start - $slack || $y > $end + $slack) {
                    continue;
                }
            }
            $span = $end - $start;
            if ($span < 16 || $distance > $slack) {
                continue;
            }
            $rank = $distance * 4 + $span * 0.01;
            if ($best === null || $rank < $best['rank']) {
                $best = [
                    'start' => $start,
                    'end' => $end,
                    'along' => $along,
                    'span' => $span,
                    'distance' => $distance,
                    'rank' => $rank,
                ];
            }
        }

        if ($best === null) {
            return null;
        }
        unset($best['rank']);

        return $best;
    }

    /**
     * @param  array{x: float, y: float}  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    private function fromPerpendicularTicks(array $dimension, array $lines, string $tickAxis, float $slack): ?array
    {
        $x = (float) $dimension['x'];
        $y = (float) $dimension['y'];
        $left = null;
        $right = null;
        foreach ($lines as $line) {
            if (($line['axis'] ?? '') !== $tickAxis) {
                continue;
            }
            if ($tickAxis === 'v') {
                $pos = ((float) $line['x1'] + (float) $line['x2']) / 2;
                $spanStart = min((float) $line['y1'], (float) $line['y2']);
                $spanEnd = max((float) $line['y1'], (float) $line['y2']);
                if ($y < $spanStart - $slack || $y > $spanEnd + $slack) {
                    continue;
                }
                $distance = abs($pos - $x);
                $candidate = ['pos' => $pos, 'distance' => $distance];
                if ($pos < $x && ($left === null || $distance < $left['distance'])) {
                    $left = $candidate;
                }
                if ($pos > $x && ($right === null || $distance < $right['distance'])) {
                    $right = $candidate;
                }
            } else {
                $pos = ((float) $line['y1'] + (float) $line['y2']) / 2;
                $spanStart = min((float) $line['x1'], (float) $line['x2']);
                $spanEnd = max((float) $line['x1'], (float) $line['x2']);
                if ($x < $spanStart - $slack || $x > $spanEnd + $slack) {
                    continue;
                }
                $distance = abs($pos - $y);
                $candidate = ['pos' => $pos, 'distance' => $distance];
                if ($pos < $y && ($left === null || $distance < $left['distance'])) {
                    $left = $candidate;
                }
                if ($pos > $y && ($right === null || $distance < $right['distance'])) {
                    $right = $candidate;
                }
            }
        }
        if ($left === null || $right === null) {
            return null;
        }
        $span = (float) $right['pos'] - (float) $left['pos'];
        if ($span < 16) {
            return null;
        }

        return [
            'start' => (float) $left['pos'],
            'end' => (float) $right['pos'],
            'along' => $tickAxis === 'v' ? $y : $x,
            'span' => $span,
            'distance' => min((float) $left['distance'], (float) $right['distance']),
        ];
    }

    /**
     * @param  array{start: float, end: float}  $chain
     * @param  array{x: float, y: float}  $anchor
     */
    private function chainFitsRoom(array $chain, ?float $first, ?float $second, array $anchor, bool $horizontal, float $pageMin): bool
    {
        if ($first !== null && $second !== null) {
            return $this->matchesPair($chain['start'], $chain['end'], $first, $second, $pageMin);
        }

        $ocr = $horizontal ? (float) $anchor['x'] : (float) $anchor['y'];
        if ($ocr <= $chain['start'] + 4 || $ocr >= $chain['end'] - 4) {
            return false;
        }
        $tol = max(16.0, $pageMin * 0.03);
        foreach (array_filter([$first, $second], is_numeric(...)) as $side) {
            if (abs((float) $side - $chain['start']) <= $tol || abs((float) $side - $chain['end']) <= $tol) {
                return true;
            }
        }

        return $first === null && $second === null;
    }

    private function matchesPair(float $start, float $end, ?float $first, ?float $second, float $pageMin): bool
    {
        if ($first === null || $second === null) {
            return false;
        }
        $roomStart = min($first, $second);
        $roomEnd = max($first, $second);
        $roomSpan = $roomEnd - $roomStart;
        if ($roomSpan < 12) {
            return false;
        }
        $tolerance = max(14.0, min($pageMin * 0.03, $roomSpan * 0.12));
        if (abs($start - $roomStart) > $tolerance || abs($end - $roomEnd) > $tolerance) {
            return false;
        }

        return abs(($end - $start) - $roomSpan) / $roomSpan <= 0.2;
    }

    /**
     * @param  array{x: float, y: float, page?: int, room_key?: string, room_number?: string}  $anchor
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $anchors
     * @param  array{left?: ?float, right?: ?float, top?: ?float, bottom?: ?float}  $sides
     */
    private function spansOtherRooms(float $start, float $end, bool $horizontal, array $anchor, array $anchors, array $sides = []): bool
    {
        $lo = min($start, $end) + self::SPAN_INSET;
        $hi = max($start, $end) - self::SPAN_INSET;
        if ($hi <= $lo) {
            return false;
        }
        [$perpLo, $perpHi] = $this->perpendicularBand($horizontal, $anchor, $sides);
        $anchorKey = $anchor['room_key'] ?? (string) ($anchor['room_number'] ?? '');
        foreach ($anchors as $other) {
            if ((int) ($other['page'] ?? 1) !== (int) ($anchor['page'] ?? 1)) {
                continue;
            }
            $otherKey = $other['room_key'] ?? (string) ($other['room_number'] ?? '');
            if ($otherKey !== '' && $otherKey === $anchorKey) {
                continue;
            }
            if ($otherKey === '' && abs((float) $other['x'] - (float) $anchor['x']) < 1 && abs((float) $other['y'] - (float) $anchor['y']) < 1) {
                continue;
            }
            $value = $horizontal ? (float) $other['x'] : (float) $other['y'];
            if ($value <= $lo || $value >= $hi) {
                continue;
            }
            $perp = $horizontal ? (float) $other['y'] : (float) $other['x'];
            if ($perp <= $perpLo || $perp >= $perpHi) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array{x: float, y: float}  $anchor
     * @param  array{left?: ?float, right?: ?float, top?: ?float, bottom?: ?float}  $sides
     * @return array{0: float, 1: float}
     */
    private function perpendicularBand(bool $horizontal, array $anchor, array $sides): array
    {
        $first = $horizontal ? ($sides['bottom'] ?? null) : ($sides['left'] ?? null);
        $second = $horizontal ? ($sides['top'] ?? null) : ($sides['right'] ?? null);
        if (is_numeric($first) && is_numeric($second)) {
            return [min((float) $first, (float) $second), max((float) $first, (float) $second)];
        }
        $center = $horizontal ? (float) $anchor['y'] : (float) $anchor['x'];

        return [$center - 90.0, $center + 90.0];
    }

    private function scaleLooksPlausible(?int $printedScale, int $mm, float $spanPx): bool
    {
        if ($printedScale === null || $printedScale < 1 || $spanPx < 8 || $mm < 1) {
            return true;
        }
        $dpi = $spanPx * 25.4 * $printedScale / $mm;

        return $dpi >= 28.0 && $dpi <= 480.0;
    }
}
