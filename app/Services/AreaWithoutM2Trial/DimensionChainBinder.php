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
        $chain = $this->pickHorizontalChain($dimension, $lines, $sides, $pageMin);
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
            'bind_reason' => (string) ($chain['bind_reason'] ?? 'gekoppeld kettingsegment'),
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
        ] + $this->endpointDebug($chain, $sides['left'] ?? null, $sides['right'] ?? null, true);
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
        $chain = $this->pickVerticalChain($dimension, $lines, $sides, $pageMin);
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
            'bind_reason' => (string) ($chain['bind_reason'] ?? 'gekoppeld kettingsegment'),
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
        ] + $this->endpointDebug($chain, $sides['bottom'] ?? null, $sides['top'] ?? null, false);
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
     * When both room walls are known, only a chain whose endpoints sit on that pair is usable.
     *
     * @param  array{x?: float, y?: float}  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @param  array{left?: ?float, right?: ?float, top?: ?float, bottom?: ?float}  $sides
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    public function pickHorizontalChain(array $dimension, array $lines, array $sides, float $pageMin): ?array
    {
        return $this->pickChain($dimension, $lines, $sides['left'] ?? null, $sides['right'] ?? null, true, $pageMin);
    }

    /**
     * @param  array{x?: float, y?: float}  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @param  array{left?: ?float, right?: ?float, top?: ?float, bottom?: ?float}  $sides
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    public function pickVerticalChain(array $dimension, array $lines, array $sides, float $pageMin): ?array
    {
        return $this->pickChain($dimension, $lines, $sides['bottom'] ?? null, $sides['top'] ?? null, false, $pageMin);
    }

    /**
     * @param  array{start?: float, end?: float}|null  $chain
     * @return array{endpoints: string, expected: string, delta: string}
     */
    public function endpointDebug(?array $chain, ?float $lo, ?float $hi, bool $horizontal): array
    {
        $axis = $horizontal ? 'x' : 'y';
        $loName = $horizontal ? 'links' : 'onder';
        $hiName = $horizontal ? 'rechts' : 'boven';
        $expected = ($lo === null || $hi === null)
            ? 'geen wandpaar'
            : $axis.'='.round(min($lo, $hi)).'–'.round(max($lo, $hi));
        if ($chain === null) {
            return [
                'endpoints' => 'geen maatsegment gevonden',
                'expected' => $expected,
                'delta' => '—',
            ];
        }
        $start = min((float) $chain['start'], (float) $chain['end']);
        $end = max((float) $chain['start'], (float) $chain['end']);
        $delta = '—';
        if ($lo !== null && $hi !== null) {
            $delta = $loName.' '.round(abs($start - min($lo, $hi)))
                .' px, '.$hiName.' '.round(abs($end - max($lo, $hi))).' px';
        }

        return [
            'endpoints' => $axis.'='.round($start).'–'.round($end),
            'expected' => $expected,
            'delta' => $delta,
        ];
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

    /**
     * @param  array{x?: float, y?: float}  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    private function pickChain(array $dimension, array $lines, ?float $first, ?float $second, bool $horizontal, float $pageMin): ?array
    {
        $fromObject = $this->chainFromObject($dimension, $horizontal);
        $mapped = $this->mapObjectToWallPair($fromObject, $dimension, $first, $second, $horizontal, $pageMin);
        if ($mapped !== null) {
            return $mapped;
        }

        $near = $horizontal
            ? $this->horizontalChain($dimension, $lines, $pageMin)
            : $this->verticalChain($dimension, $lines, $pageMin);
        if ($first === null || $second === null) {
            return $near ?? $fromObject;
        }
        if ($near !== null && $this->matchesPair($near['start'], $near['end'], $first, $second, $pageMin)) {
            return $near;
        }
        if ($near !== null && $this->numberInteriorToChain($dimension, $near, $horizontal)) {
            if (! $this->isSubsetOfPair($near, $first, $second, $pageMin)) {
                return null;
            }
            $ticks = $horizontal
                ? $this->fromPerpendicularTicks($dimension, $lines, 'v', max(36.0, $pageMin * 0.04))
                : $this->fromPerpendicularTicks($dimension, $lines, 'h', max(36.0, $pageMin * 0.04));
            if ($ticks !== null && $this->matchesPair($ticks['start'], $ticks['end'], $first, $second, $pageMin)) {
                return $ticks;
            }

            return null;
        }

        return $this->chainAlignedToPair($dimension, $lines, $horizontal, $first, $second, $pageMin);
    }

    /**
     * @param  array{start?: float, end?: float, span?: float}|null  $chain
     */
    private function isSubsetOfPair(?array $chain, float $first, float $second, float $pageMin): bool
    {
        if ($chain === null) {
            return false;
        }
        $roomStart = min($first, $second);
        $roomEnd = max($first, $second);
        $roomSpan = $roomEnd - $roomStart;
        $start = min((float) $chain['start'], (float) $chain['end']);
        $end = max((float) $chain['start'], (float) $chain['end']);
        $tol = max(14.0, $pageMin * 0.03);
        if ($start < $roomStart - $tol || $end > $roomEnd + $tol) {
            return false;
        }

        return $roomSpan >= 12 && ($end - $start) < $roomSpan * 0.85;
    }

    /**
     * @param  array{x?: float, y?: float}  $dimension
     * @param  array{start: float, end: float}  $chain
     */
    private function numberInteriorToChain(array $dimension, array $chain, bool $horizontal): bool
    {
        $along = $horizontal ? (float) ($dimension['x'] ?? 0) : (float) ($dimension['y'] ?? 0);
        $start = min((float) $chain['start'], (float) $chain['end']);
        $end = max((float) $chain['start'], (float) $chain['end']);

        return $along > $start + 8 && $along < $end - 8;
    }

    /**
     * @param  array{x?: float, y?: float}  $dimension
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $lines
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    private function chainAlignedToPair(
        array $dimension,
        array $lines,
        bool $horizontal,
        float $first,
        float $second,
        float $pageMin,
    ): ?array {
        $roomStart = min($first, $second);
        $roomEnd = max($first, $second);
        $x = (float) ($dimension['x'] ?? 0);
        $y = (float) ($dimension['y'] ?? 0);
        $along = $horizontal ? $x : $y;
        $perp = $horizontal ? $y : $x;
        $slack = max(18.0, $pageMin * 0.02);
        if ($along < $roomStart - $slack || $along > $roomEnd + $slack) {
            return null;
        }
        $axis = $horizontal ? 'h' : 'v';
        $maxPerp = max(36.0, $pageMin * 0.04);
        $best = null;
        foreach ($lines as $line) {
            if (($line['axis'] ?? '') !== $axis) {
                continue;
            }
            if ($horizontal) {
                $start = min((float) $line['x1'], (float) $line['x2']);
                $end = max((float) $line['x1'], (float) $line['x2']);
                $lineAlong = ((float) $line['y1'] + (float) $line['y2']) / 2;
            } else {
                $start = min((float) $line['y1'], (float) $line['y2']);
                $end = max((float) $line['y1'], (float) $line['y2']);
                $lineAlong = ((float) $line['x1'] + (float) $line['x2']) / 2;
            }
            if (! $this->matchesPair($start, $end, $roomStart, $roomEnd, $pageMin)) {
                continue;
            }
            $distance = abs($lineAlong - $perp);
            if ($distance > $maxPerp) {
                continue;
            }
            $span = $end - $start;
            $rank = $distance * 4 + $span * 0.01;
            if ($best === null || $rank < (float) $best['rank']) {
                $best = [
                    'start' => $start,
                    'end' => $end,
                    'along' => $lineAlong,
                    'span' => $span,
                    'distance' => $distance,
                    'rank' => $rank,
                ];
            }
        }
        if ($best !== null) {
            unset($best['rank']);

            return $best;
        }

        return null;
    }

    /**
     * Map a reconstructed chain segment onto the room's consecutive wall axes.
     * Architect dimension lines often sit outside the inner face, so endpoints
     * need not equal the detected inner walls.
     *
     * @param  array{start: float, end: float, along: float, span: float, distance: float}|null  $fromObject
     * @param  array{mm?: int, evidence?: string, overall?: bool, x?: float, y?: float}  $dimension
     * @return array{start: float, end: float, along: float, span: float, distance: float, bind_reason?: string}|null
     */
    private function mapObjectToWallPair(
        ?array $fromObject,
        array $dimension,
        ?float $first,
        ?float $second,
        bool $horizontal,
        float $pageMin,
    ): ?array {
        if ($fromObject === null) {
            return null;
        }
        if ($first === null || $second === null) {
            return $fromObject;
        }
        $overall = ($dimension['overall'] ?? false) === true
            || ($dimension['evidence'] ?? '') === 'gebouwmaat';
        $roomStart = min($first, $second);
        $roomEnd = max($first, $second);
        $roomSpan = $roomEnd - $roomStart;
        if ($roomSpan < 12) {
            return null;
        }
        if ($overall || $fromObject['span'] > $roomSpan * 1.2) {
            return $this->matchesPair($fromObject['start'], $fromObject['end'], $first, $second, $pageMin)
                ? $fromObject
                : null;
        }
        if ($this->matchesPair($fromObject['start'], $fromObject['end'], $first, $second, $pageMin)) {
            return [
                'start' => $roomStart,
                'end' => $roomEnd,
                'along' => $fromObject['along'],
                'span' => $roomSpan,
                'distance' => 0.0,
                'bind_reason' => 'extension-lines op opeenvolgende bouwassen',
            ];
        }
        if (! $this->numberInteriorToChain($dimension, ['start' => $roomStart, 'end' => $roomEnd], $horizontal)) {
            return null;
        }
        $spanOk = abs($fromObject['span'] - $roomSpan) / $roomSpan <= 0.25;
        $shiftOk = abs(($fromObject['start'] - $roomStart) - ($fromObject['end'] - $roomEnd)) <= max(28.0, $pageMin * 0.04);
        if ($spanOk && $shiftOk) {
            return [
                'start' => $roomStart,
                'end' => $roomEnd,
                'along' => $fromObject['along'],
                'span' => $roomSpan,
                'distance' => 0.0,
                'bind_reason' => 'kettingsegment topologisch op wandpaar',
            ];
        }

        return null;
    }

    /**
     * Use a already-built dimension object's endpoints when binding to a wall pair.
     *
     * @param  array{orientation?: string, endpoint1?: array{x?: float, y?: float}, endpoint2?: array{x?: float, y?: float}}  $dimension
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    public function chainFromObject(array $dimension, bool $horizontal): ?array
    {
        $orientation = (string) ($dimension['orientation'] ?? '');
        $expected = $horizontal ? 'horizontal' : 'vertical';
        if ($orientation !== $expected) {
            return null;
        }
        $first = is_array($dimension['endpoint1'] ?? null) ? $dimension['endpoint1'] : null;
        $second = is_array($dimension['endpoint2'] ?? null) ? $dimension['endpoint2'] : null;
        if ($first === null || $second === null) {
            return null;
        }
        if ($horizontal) {
            $start = min((float) ($first['x'] ?? 0), (float) ($second['x'] ?? 0));
            $end = max((float) ($first['x'] ?? 0), (float) ($second['x'] ?? 0));
            $along = ((float) ($first['y'] ?? 0) + (float) ($second['y'] ?? 0)) / 2;
        } else {
            $start = min((float) ($first['y'] ?? 0), (float) ($second['y'] ?? 0));
            $end = max((float) ($first['y'] ?? 0), (float) ($second['y'] ?? 0));
            $along = ((float) ($first['x'] ?? 0) + (float) ($second['x'] ?? 0)) / 2;
        }
        if (($end - $start) < 16) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'along' => $along,
            'span' => $end - $start,
            'distance' => 0.0,
        ];
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
