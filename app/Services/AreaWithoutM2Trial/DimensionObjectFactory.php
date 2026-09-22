<?php

namespace App\Services\AreaWithoutM2Trial;

/**
 * Promote a millimetre token to a dimension object from a measure line, ticks, or a collinear chain.
 */
class DimensionObjectFactory
{
    private const MIN_MM = 400;

    private const MAX_MM = 30000;

    public function __construct(
        private PageAdminZoneDetector $adminZones = new PageAdminZoneDetector,
        private DimensionChainReconstructor $chains = new DimensionChainReconstructor,
    ) {}

    /**
     * @param  array<string, mixed>  $page
     * @param  list<array{text: string, x: float, y: float, page: int, square_meters?: float}>  $printed
     * @param  list<array{left: float, right: float, bottom: float, top: float, kind?: string, reason?: string}>  $zones
     * @return array{
     *     accepted: list<array<string, mixed>>,
     *     excluded: list<array{mm: int, text: string, x: float, y: float, page: int, reason: string}>,
     *     chains: list<array<string, mixed>>,
     *     misses: list<array{mm: int, text: string, x: float, y: float, page: int, reason: string}>
     * }
     */
    public function fromPage(array $page, array $printed, array $zones): array
    {
        $printedKeys = [];
        foreach ($printed as $item) {
            $printedKeys[$this->itemKey($item)] = true;
        }
        $lines = array_values(array_filter(
            array_merge($page['walls'] ?? [], $page['ticks'] ?? []),
            fn (mixed $line): bool => is_array($line),
        ));
        $width = (float) ($page['width'] ?? 0);
        $height = (float) ($page['height'] ?? 0);
        $pageMin = min(max($width, 1.0), max($height, 1.0));
        $accepted = [];
        $excluded = [];
        $open = [];

        foreach ($page['texts'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '' || isset($printedKeys[$this->itemKey($item)]) || $this->isPrintedAreaToken($text)) {
                continue;
            }
            foreach ($this->candidateNumbers($text) as $candidate) {
                $row = $item + [
                    'mm' => $candidate['mm'],
                    'text' => $text,
                    'standalone' => $candidate['standalone'],
                ];
                $decision = $this->decide($row, $zones);
                if ($decision['excluded'] !== null) {
                    $excluded[] = $decision['excluded'];
                } else {
                    $open[] = $row;
                }
            }
        }

        $reconstruction = $this->chains->reconstruct($open, $lines, $width, $height);
        foreach ($reconstruction['objects'] as $object) {
            $accepted[] = $object;
            $open = array_values(array_filter(
                $open,
                fn (array $row): bool => ! $this->sameCandidate($row, $object),
            ));
        }
        $missByKey = [];
        foreach ($reconstruction['misses'] as $miss) {
            $missByKey[$this->candidateKey($miss)] = $miss;
        }

        foreach ($open as $index => $row) {
            $fromLine = $this->fromLineOrFragment($row, $lines, $pageMin, $width, $height);
            if ($fromLine !== null) {
                $accepted[] = $fromLine;
                unset($open[$index]);

                continue;
            }
            $fromTicks = $this->fromTickPair($row, $lines, $pageMin);
            if ($fromTicks !== null) {
                $accepted[] = $fromTicks;
                unset($open[$index]);
            }
        }
        $open = array_values($open);

        $claimed = [];
        foreach ($this->outerWallLabels($open, $lines, $pageMin) as $object) {
            $accepted[] = $object;
            $claimed[$this->candidateKey($object)] = true;
        }
        if ($claimed !== []) {
            $open = array_values(array_filter(
                $open,
                fn (array $row): bool => ! isset($claimed[$this->candidateKey($row)]),
            ));
        }

        foreach ($open as $row) {
            $miss = $missByKey[$this->candidateKey($row)] ?? null;
            $excluded[] = [
                'mm' => (int) $row['mm'],
                'text' => (string) $row['text'],
                'x' => (float) ($row['x'] ?? 0),
                'y' => (float) ($row['y'] ?? 0),
                'page' => (int) ($row['page'] ?? 1),
                'reason' => is_array($miss) ? (string) $miss['reason'] : 'geen maatlijn of endpoints',
            ];
        }

        $accepted = $this->unique($accepted);
        $acceptedKeys = [];
        foreach ($accepted as $object) {
            $acceptedKeys[$this->candidateKey($object)] = true;
        }
        $misses = array_values(array_filter(
            $reconstruction['misses'],
            fn (array $miss): bool => ! isset($acceptedKeys[$this->candidateKey($miss)]),
        ));

        return [
            'accepted' => $accepted,
            'excluded' => $this->uniqueExcluded($excluded),
            'chains' => $reconstruction['chains'],
            'misses' => $misses,
        ];
    }

    /**
     * @param  array{mm: int, text: string, x?: float, y?: float, page?: int, standalone: bool}  $row
     * @param  list<array{left: float, right: float, bottom: float, top: float, reason?: string}>  $zones
     * @return array{excluded: ?array{mm: int, text: string, x: float, y: float, page: int, reason: string}}
     */
    private function decide(array $row, array $zones): array
    {
        $mm = (int) $row['mm'];
        $x = (float) ($row['x'] ?? 0);
        $y = (float) ($row['y'] ?? 0);
        $page = (int) ($row['page'] ?? 1);
        $text = (string) $row['text'];
        $excluded = [
            'mm' => $mm,
            'text' => $text,
            'x' => $x,
            'y' => $y,
            'page' => $page,
            'reason' => '',
        ];

        if (! ($row['standalone'] ?? false)) {
            $excluded['reason'] = 'getal in product-/legendatekst';

            return ['excluded' => $excluded];
        }

        $zoneReason = $this->adminZones->reasonAt($x, $y, $zones);
        if ($zoneReason !== null) {
            $excluded['reason'] = $zoneReason;

            return ['excluded' => $excluded];
        }

        return ['excluded' => null];
    }

    /**
     * @param  array{mm: int, text: string, x?: float, y?: float, page?: int}  $row
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>|null
     */
    private function fromLineOrFragment(array $row, array $lines, float $pageMin, float $pageWidth, float $pageHeight): ?array
    {
        $horizontal = $this->nearestFragment($row, $lines, 'h', $pageMin, $pageWidth, $pageHeight);
        $vertical = $this->nearestFragment($row, $lines, 'v', $pageMin, $pageWidth, $pageHeight);
        if ($horizontal === null && $vertical === null) {
            return null;
        }
        $useHorizontal = $horizontal !== null && ($vertical === null || (float) $horizontal['distance'] <= (float) $vertical['distance']);
        $chain = $useHorizontal ? $horizontal : $vertical;
        $overall = (float) $chain['span'] >= ($useHorizontal ? $pageWidth : $pageHeight) * 0.65;

        return $this->objectFromChain(
            $row,
            $chain,
            $useHorizontal ? 'horizontal' : 'vertical',
            $overall ? 'gebouwmaat' : 'maatlijn/ticks',
            $overall ? 0.7 : 0.9,
        );
    }

    /**
     * @param  array{x?: float, y?: float}  $dimension
     * @param  list<array<string, mixed>>  $lines
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    private function nearestFragment(array $dimension, array $lines, string $axis, float $pageMin, float $pageWidth, float $pageHeight): ?array
    {
        $x = (float) ($dimension['x'] ?? 0);
        $y = (float) ($dimension['y'] ?? 0);
        $spanSlack = max(24.0, $pageMin * 0.03);
        $perpSlack = max(28.0, $pageMin * 0.035);
        $axisLength = $axis === 'h' ? max($pageWidth, 1.0) : max($pageHeight, 1.0);
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
                if ($x < $start - $spanSlack || $x > $end + $spanSlack) {
                    continue;
                }
            } else {
                $along = ((float) $line['x1'] + (float) $line['x2']) / 2;
                $distance = abs($along - $x);
                $start = min((float) $line['y1'], (float) $line['y2']);
                $end = max((float) $line['y1'], (float) $line['y2']);
                if ($y < $start - $spanSlack || $y > $end + $spanSlack) {
                    continue;
                }
            }
            $span = $end - $start;
            if ($span < 12 || $distance > $perpSlack) {
                continue;
            }
            $alongPos = $axis === 'h' ? $x : $y;
            $inset = max(24.0, $span * 0.18);
            $onOverall = $span >= $axisLength * 0.65 && $alongPos > $start + $inset && $alongPos < $end - $inset;
            if ($onOverall && $distance > max(28.0, $pageMin * 0.03)) {
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
     * Isolated numbers can still form a dimension object from two short extension ticks.
     *
     * @param  array{mm: int, text?: string, x?: float, y?: float, page?: int}  $row
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>|null
     */
    private function fromTickPair(array $row, array $lines, float $pageMin): ?array
    {
        $maxTick = max(48.0, $pageMin * 0.08);
        $horizontal = $this->shortTickBracket($row, $lines, 'v', $pageMin, $maxTick);
        $vertical = $this->shortTickBracket($row, $lines, 'h', $pageMin, $maxTick);
        if ($horizontal === null && $vertical === null) {
            return null;
        }
        $useHorizontal = $horizontal !== null && ($vertical === null || (float) $horizontal['distance'] <= (float) $vertical['distance']);

        return $this->objectFromChain(
            $row,
            $useHorizontal ? $horizontal : $vertical,
            $useHorizontal ? 'horizontal' : 'vertical',
            'extension-ticks',
            0.75,
        );
    }

    /**
     * One closed rectangle and no dimension line: a millimetre text just
     * outside one side is that side's length. Extra walls keep the normal chain.
     *
     * @param  list<array{mm: int, text: string, x?: float, y?: float, page?: int, standalone?: bool}>  $rows
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function outerWallLabels(array $rows, array $lines, float $pageMin): array
    {
        $gap = max(6.0, $pageMin * 0.01);
        $vertical = $this->clusteredAxes($lines, 'v', $gap);
        $horizontal = $this->clusteredAxes($lines, 'h', $gap);
        if (count($vertical) !== 2 || count($horizontal) !== 2) {
            return [];
        }

        $left = $vertical[0];
        $right = $vertical[1];
        $bottom = $horizontal[0];
        $top = $horizontal[1];
        if (($right - $left) < 24 || ($top - $bottom) < 24) {
            return [];
        }

        $margin = max(130.0, $pageMin * 0.15);
        $alongSlack = max(24.0, $pageMin * 0.04);
        $bySide = ['below' => [], 'above' => [], 'left' => [], 'right' => []];
        foreach ($rows as $row) {
            if (! ($row['standalone'] ?? false)) {
                continue;
            }
            $x = (float) ($row['x'] ?? 0);
            $y = (float) ($row['y'] ?? 0);
            $inX = $x >= $left - $alongSlack && $x <= $right + $alongSlack;
            $inY = $y >= $bottom - $alongSlack && $y <= $top + $alongSlack;
            $sides = [];
            if ($inX && $y < $bottom && ($bottom - $y) <= $margin) {
                $sides['below'] = $bottom - $y;
            }
            if ($inX && $y > $top && ($y - $top) <= $margin) {
                $sides['above'] = $y - $top;
            }
            if ($inY && $x < $left && ($left - $x) <= $margin) {
                $sides['left'] = $left - $x;
            }
            if ($inY && $x > $right && ($x - $right) <= $margin) {
                $sides['right'] = $x - $right;
            }
            if ($sides === []) {
                continue;
            }
            $side = array_key_first($sides);
            $nearest = $sides[$side];
            foreach ($sides as $name => $distance) {
                if ($distance < $nearest) {
                    $side = $name;
                    $nearest = $distance;
                }
            }
            $bySide[$side][] = $row;
        }

        $widthSides = array_values(array_filter(
            ['below', 'above'],
            fn (string $side): bool => count($bySide[$side]) === 1,
        ));
        $heightSides = array_values(array_filter(
            ['left', 'right'],
            fn (string $side): bool => count($bySide[$side]) === 1,
        ));
        if (count($widthSides) !== 1 || count($heightSides) !== 1) {
            return [];
        }

        $objects = [];
        foreach (['below' => $bottom, 'above' => $top] as $side => $along) {
            if (count($bySide[$side]) !== 1) {
                continue;
            }
            $objects[] = $this->objectFromChain($bySide[$side][0], [
                'start' => $left,
                'end' => $right,
                'along' => $along,
                'span' => $right - $left,
                'distance' => 0.0,
            ], 'horizontal', 'maattekst buiten wand', 0.9);
        }
        foreach (['left' => $left, 'right' => $right] as $side => $along) {
            if (count($bySide[$side]) !== 1) {
                continue;
            }
            $objects[] = $this->objectFromChain($bySide[$side][0], [
                'start' => $bottom,
                'end' => $top,
                'along' => $along,
                'span' => $top - $bottom,
                'distance' => 0.0,
            ], 'vertical', 'maattekst buiten wand', 0.9);
        }

        return $objects;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<float>
     */
    private function clusteredAxes(array $lines, string $axis, float $gap): array
    {
        $positions = [];
        foreach ($lines as $line) {
            if (($line['axis'] ?? '') !== $axis) {
                continue;
            }
            $positions[] = $axis === 'v'
                ? ((float) $line['x1'] + (float) $line['x2']) / 2
                : ((float) $line['y1'] + (float) $line['y2']) / 2;
        }
        sort($positions);
        $clustered = [];
        foreach ($positions as $position) {
            if ($clustered === [] || abs($position - $clustered[array_key_last($clustered)]) > $gap) {
                $clustered[] = $position;
            }
        }

        return $clustered;
    }

    /**
     * @param  array{x?: float, y?: float}  $dimension
     * @param  list<array<string, mixed>>  $lines
     * @return array{start: float, end: float, along: float, span: float, distance: float}|null
     */
    private function shortTickBracket(array $dimension, array $lines, string $tickAxis, float $pageMin, float $maxTick): ?array
    {
        $x = (float) ($dimension['x'] ?? 0);
        $y = (float) ($dimension['y'] ?? 0);
        $slack = max(18.0, $pageMin * 0.018);
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
                $span = $spanEnd - $spanStart;
                if ($span > $maxTick || $y < $spanStart - $slack || $y > $spanEnd + $slack) {
                    continue;
                }
                $distance = abs($pos - $x);
            } else {
                $pos = ((float) $line['y1'] + (float) $line['y2']) / 2;
                $spanStart = min((float) $line['x1'], (float) $line['x2']);
                $spanEnd = max((float) $line['x1'], (float) $line['x2']);
                $span = $spanEnd - $spanStart;
                if ($span > $maxTick || $x < $spanStart - $slack || $x > $spanEnd + $slack) {
                    continue;
                }
                $distance = abs($pos - $y);
            }
            $candidate = ['pos' => $pos, 'distance' => $distance];
            $along = $tickAxis === 'v' ? $x : $y;
            if ($pos < $along && ($left === null || $distance < $left['distance'])) {
                $left = $candidate;
            }
            if ($pos > $along && ($right === null || $distance < $right['distance'])) {
                $right = $candidate;
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
     * @param  array{mm: int, text?: string, x?: float, y?: float, page?: int}  $row
     * @param  array{start: float, end: float, along: float, span: float, distance: float}  $chain
     * @return array<string, mixed>
     */
    private function objectFromChain(array $row, array $chain, string $orientation, string $evidence, float $confidence): array
    {
        $start = min((float) $chain['start'], (float) $chain['end']);
        $end = max((float) $chain['start'], (float) $chain['end']);
        $along = (float) $chain['along'];
        if ($orientation === 'horizontal') {
            $endpoint1 = ['x' => $start, 'y' => $along];
            $endpoint2 = ['x' => $end, 'y' => $along];
        } else {
            $endpoint1 = ['x' => $along, 'y' => $start];
            $endpoint2 = ['x' => $along, 'y' => $end];
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
            'overall' => $evidence === 'gebouwmaat',
        ];
    }

    /**
     * @return list<array{mm: int, standalone: bool}>
     */
    private function candidateNumbers(string $text): array
    {
        if (preg_match('/^(\d{3,5})(?:\s*mm)?$/iu', $text, $match) === 1) {
            $mm = (int) $match[1];
            if ($mm >= self::MIN_MM && $mm <= self::MAX_MM) {
                return [['mm' => $mm, 'standalone' => true]];
            }

            return [];
        }

        if (preg_match('/^(\d{3,5})\s*[x×]\s*(\d{3,5})(?:\s*mm)?$/iu', $text, $pair) === 1) {
            $found = [];
            foreach ([(int) $pair[1], (int) $pair[2]] as $mm) {
                if ($mm >= self::MIN_MM && $mm <= self::MAX_MM) {
                    $found[] = ['mm' => $mm, 'standalone' => true];
                }
            }

            return $found;
        }

        $found = [];
        if (preg_match_all('/(?<![0-9.,])(\d{3,5})(?!\s*m(?:²|2)\b)(?:\s*mm)?(?![0-9])/u', $text, $matches) !== false) {
            foreach ($matches[1] as $raw) {
                $mm = (int) $raw;
                if ($mm >= self::MIN_MM && $mm <= self::MAX_MM) {
                    $found[] = ['mm' => $mm, 'standalone' => false];
                }
            }
        }

        return $found;
    }

    private function isPrintedAreaToken(string $text): bool
    {
        return (bool) preg_match('/\d+(?:[.,]\d+)?\s*m(?:²|2)\b/u', $text)
            || (bool) preg_match('/^m(?:²|2)$/iu', $text);
    }

    /**
     * @param  array{text?: string, x?: float, y?: float, page?: int}  $item
     */
    private function itemKey(array $item): string
    {
        return ($item['page'] ?? 1).'|'.round((float) ($item['x'] ?? 0), 1).'|'.round((float) ($item['y'] ?? 0), 1).'|'.($item['text'] ?? '');
    }

    /**
     * @param  array{mm?: int, x?: float, y?: float, page?: int}  $row
     */
    private function candidateKey(array $row): string
    {
        return round((float) ($row['x'] ?? 0), 1).'|'.round((float) ($row['y'] ?? 0), 1).'|'.(int) ($row['mm'] ?? 0);
    }

    /**
     * @param  array{mm?: int, x?: float, y?: float}  $row
     * @param  array{mm?: int, x?: float, y?: float}  $object
     */
    private function sameCandidate(array $row, array $object): bool
    {
        return $this->candidateKey($row) === $this->candidateKey($object);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function unique(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $unique[$this->candidateKey($item)] = $item;
        }

        return array_values($unique);
    }

    /**
     * @param  list<array{mm: int, text: string, x: float, y: float, page: int, reason: string}>  $items
     * @return list<array{mm: int, text: string, x: float, y: float, page: int, reason: string}>
     */
    private function uniqueExcluded(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $key = $item['page'].'|'.round($item['x'], 1).'|'.round($item['y'], 1).'|'.$item['mm'].'|'.$item['reason'];
            $unique[$key] = $item;
        }

        return array_values($unique);
    }
}
