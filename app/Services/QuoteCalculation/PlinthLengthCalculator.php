<?php

namespace App\Services\QuoteCalculation;

use App\Enums\QuantitySource;
use App\Support\DutchNumber;
use App\Support\Format;

class PlinthLengthCalculator
{
    public const STATUS_NET = 'net';

    public const STATUS_GENEROUS = 'generous';

    public const STATUS_ESTIMATED = 'estimated';

    public const STATUS_MISSING = 'missing';

    /** Aspect ratio used when only m² is known: longer than square, so more plinth. */
    private const ESTIMATE_ASPECT = 2.5;

    /**
     * @param  array<string, mixed>  $room
     * @param  list<array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>, fills?: list<array{x: float, y: float, width: float, height: float, area: float}>}>  $pages
     * @return array{
     *     meters: ?float,
     *     source: string,
     *     trace: ?string,
     *     status: string,
     *     gross: ?float,
     *     doors: list<float>,
     *     net: ?float
     * }
     */
    public function forRoom(array $room, array $pages): array
    {
        $anchor = $this->roomAnchor($room, $pages);
        if ($anchor !== null) {
            $perimeter = $this->perimeterFromFill($room, $anchor, $pages)
                ?? $this->labelledPerimeter($anchor, $pages)
                ?? $this->perimeterFromWalls($room, $this->areaAnchor($room, $anchor, $pages) ?? $anchor, $pages)
                ?? $this->perimeterFromWalls($room, $anchor, $pages);

            if ($perimeter !== null) {
                $candidates = $this->doorCandidates($anchor, $pages);
                $doors = $candidates['reliable'];
                $doorTotal = array_sum($doors);
                if ($doorTotal >= $perimeter) {
                    $doors = [];
                    $doorTotal = 0.0;
                    $candidates['skipped'] = array_merge($candidates['skipped'], $candidates['reliable']);
                }

                $generous = $doorTotal < 0.001 || $candidates['skipped'] !== [];
                if ($generous && $doorTotal < 0.001) {
                    $doors = [];
                }

                $net = $this->ceilMeters($perimeter - array_sum($doors));
                if ($net >= 0.4 && $net <= 200) {
                    return $this->result($perimeter, $doors, $net, $generous);
                }
            }
        }

        return $this->estimated($room, $anchor, $pages);
    }

    /**
     * @param  array<string, mixed>|string|null  $raw
     * @return array{label: ?string, status: string, gross: ?float, doors: list<float>, net: ?float}
     */
    public static function breakdownFrom(array|string|null $raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
        }
        if (! is_array($decoded)) {
            return [
                'label' => is_string($raw) && $raw !== '' ? $raw : null,
                'status' => self::STATUS_MISSING,
                'gross' => null,
                'doors' => [],
                'net' => null,
            ];
        }

        $doors = [];
        foreach ($decoded['doors'] ?? [] as $door) {
            if (is_numeric($door)) {
                $doors[] = (float) $door;
            }
        }

        return [
            'label' => isset($decoded['trace']) && is_string($decoded['trace']) ? $decoded['trace'] : null,
            'status' => is_string($decoded['status'] ?? null) ? $decoded['status'] : self::STATUS_MISSING,
            'gross' => is_numeric($decoded['gross'] ?? null) ? (float) $decoded['gross'] : null,
            'doors' => $doors,
            'net' => is_numeric($decoded['net'] ?? $decoded['meters'] ?? null)
                ? (float) ($decoded['net'] ?? $decoded['meters'])
                : null,
        ];
    }

    /**
     * @param  list<float>  $doors
     * @return array{meters: float, source: string, trace: string, status: string, gross: float, doors: list<float>, net: float}
     */
    public function result(float $gross, array $doors, float $net, bool $generous): array
    {
        $status = $generous ? self::STATUS_GENEROUS : self::STATUS_NET;
        $net = $this->ceilMeters($net);
        $gross = $this->ceilMeters($gross);
        $doorTotal = array_sum($doors);
        if ($generous && $doorTotal < 0.001) {
            $trace = Format::qty($net, 2).' m¹ – volledige omtrek; deuropening niet afgetrokken.';
        } elseif ($generous) {
            $trace = Format::qty($net, 2).' m¹ – omtrek '.Format::qty($gross, 2)
                .' − deur '.Format::qty($doorTotal, 2).' m (alleen betrouwbaar herkende deuropeningen).';
        } else {
            $trace = Format::qty($net, 2).' m¹ – omtrek '.Format::qty($gross, 2)
                .' − deur '.Format::qty($doorTotal, 2).' m.';
        }

        return [
            'meters' => $net,
            'source' => QuantitySource::Calculated->value,
            'trace' => $trace,
            'status' => $status,
            'gross' => $gross,
            'doors' => array_values($doors),
            'net' => $net,
        ];
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  list<array{page: int, texts: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @return array{x: float, y: float, page: int, threshold: float}|null
     */
    private function roomAnchor(array $room, array $pages): ?array
    {
        $number = mb_strtolower(trim((string) ($room['room_number'] ?? '')));
        if ($number === '') {
            return null;
        }

        $matches = [];
        foreach ($pages as $page) {
            $width = max(1.0, (float) ($page['width'] ?? 1));
            $height = max(1.0, (float) ($page['height'] ?? 1));
            $threshold = hypot($width, $height) * 0.08;
            foreach ($page['texts'] ?? [] as $item) {
                if (mb_strtolower(trim((string) $item['text'])) !== $number) {
                    continue;
                }
                $matches[] = [
                    'x' => (float) $item['x'],
                    'y' => (float) $item['y'],
                    'page' => (int) ($item['page'] ?? $page['page'] ?? 1),
                    'threshold' => $threshold,
                    'legend' => $this->nearLegendCue($item, $page['texts'] ?? []),
                ];
            }
        }
        if ($matches === []) {
            return null;
        }

        $area = is_numeric($room['square_meters'] ?? null) ? (float) $room['square_meters'] : null;
        $best = null;
        $bestScore = null;
        foreach ($matches as $match) {
            $score = $match['legend'] ? 80 : 0;
            if ($area !== null) {
                $nearArea = $this->areaAnchor($room, $match, $pages);
                $score += $nearArea === null ? 40 : (hypot($nearArea['x'] - $match['x'], $nearArea['y'] - $match['y']) / max(1.0, $match['threshold']));
            }
            if ($bestScore !== null && $score >= $bestScore) {
                continue;
            }
            $bestScore = $score;
            $best = $match;
        }

        unset($best['legend']);

        return $best;
    }

    /**
     * @param  array{text?: string, x: float, y: float, page: int}  $item
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     */
    private function nearLegendCue(array $item, array $items): bool
    {
        $text = (string) ($item['text'] ?? '');
        if (str_contains($text, '=')) {
            return true;
        }
        foreach ($items as $other) {
            if ((int) ($other['page'] ?? 0) !== (int) ($item['page'] ?? 0)) {
                continue;
            }
            if (hypot((float) $other['x'] - (float) $item['x'], (float) $other['y'] - (float) $item['y']) > 90) {
                continue;
            }
            if (str_contains((string) $other['text'], '=')
                || preg_match('/renvooi|legenda/iu', (string) $other['text'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{x: float, y: float, page: int, threshold: float}  $numberAnchor
     * @param  list<array{page?: int, texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @param  array<string, mixed>  $room
     * @return array{x: float, y: float, page: int, threshold: float}|null
     */
    private function areaAnchor(array $room, array $numberAnchor, array $pages): ?array
    {
        $target = is_numeric($room['square_meters'] ?? null) ? (float) $room['square_meters'] : null;
        if ($target === null || $target <= 0) {
            return null;
        }

        $best = null;
        $bestDistance = $numberAnchor['threshold'];
        foreach ($pages as $page) {
            $items = $page['texts'] ?? [];
            $units = [];
            foreach ($items as $item) {
                if ((int) ($item['page'] ?? 0) !== $numberAnchor['page']) {
                    continue;
                }
                if (preg_match('/^m(?:²|2)$/iu', trim((string) $item['text']))) {
                    $units[] = $item;
                }
            }
            foreach ($items as $item) {
                if ((int) ($item['page'] ?? 0) !== $numberAnchor['page']) {
                    continue;
                }
                if (! preg_match('/^\d{1,4}(?:[.,]\d+)?$/u', trim((string) $item['text']))) {
                    continue;
                }
                $value = DutchNumber::parse(trim((string) $item['text']));
                if ($value === null || abs($value - $target) > 0.16) {
                    continue;
                }
                $nearUnit = false;
                foreach ($units as $unit) {
                    if (hypot((float) $item['x'] - (float) $unit['x'], (float) $item['y'] - (float) $unit['y']) <= 36) {
                        $nearUnit = true;
                        break;
                    }
                }
                if (! $nearUnit) {
                    continue;
                }
                $distance = hypot((float) $item['x'] - $numberAnchor['x'], (float) $item['y'] - $numberAnchor['y']);
                if ($distance > $bestDistance) {
                    continue;
                }
                $bestDistance = $distance;
                $best = [
                    'x' => (float) $item['x'],
                    'y' => (float) $item['y'],
                    'page' => $numberAnchor['page'],
                    'threshold' => $numberAnchor['threshold'],
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array{x: float, y: float, page: int, threshold: float}  $anchor
     * @param  list<array{page: int, fills?: list<array{x: float, y: float, width: float, height: float, area?: float}>, texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @param  array<string, mixed>  $room
     */
    private function perimeterFromFill(array $room, array $anchor, array $pages): ?float
    {
        $area = is_numeric($room['square_meters'] ?? null) ? (float) $room['square_meters'] : null;
        if ($area === null || $area <= 0) {
            return null;
        }

        $best = null;
        $bestDistance = null;
        foreach ($pages as $page) {
            if ((int) ($page['page'] ?? $anchor['page']) !== $anchor['page']) {
                continue;
            }
            $pageWidth = max(1.0, (float) ($page['width'] ?? 1));
            $pageHeight = max(1.0, (float) ($page['height'] ?? 1));
            $pageArea = $pageWidth * $pageHeight;
            foreach ($page['fills'] ?? [] as $fill) {
                $x = (float) $fill['x'];
                $y = (float) $fill['y'];
                $width = (float) $fill['width'];
                $height = (float) $fill['height'];
                if ($width < 4 || $height < 4) {
                    continue;
                }
                $pdfArea = $width * $height;
                if ($pdfArea < 1 || $pdfArea > $pageArea * 0.45) {
                    continue;
                }
                $distance = $this->distanceToFill($anchor['x'], $anchor['y'], $x, $y, $width, $height);
                if ($distance > $anchor['threshold']) {
                    continue;
                }
                $scale = sqrt($area / $pdfArea);
                $perimeter = 2 * ($width + $height) * $scale;
                if ($perimeter < 1 || $perimeter > 200) {
                    continue;
                }
                if ($bestDistance !== null && $distance >= $bestDistance) {
                    continue;
                }
                $bestDistance = $distance;
                $best = $perimeter;
            }
        }

        return $best === null ? null : $this->ceilMeters($best);
    }

    private function distanceToFill(float $x, float $y, float $fillX, float $fillY, float $width, float $height): float
    {
        $nearestX = min(max($x, $fillX), $fillX + $width);
        $nearestY = min(max($y, $fillY), $fillY + $height);

        return hypot($x - $nearestX, $y - $nearestY);
    }

    /**
     * @param  array{x: float, y: float, page: int, threshold: float}  $anchor
     * @param  list<array{page?: int, width?: float, height?: float, geometry_width?: float, geometry_height?: float, walls?: list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>, texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @param  array<string, mixed>  $room
     */
    private function perimeterFromWalls(array $room, array $anchor, array $pages): ?float
    {
        $area = is_numeric($room['square_meters'] ?? null) ? (float) $room['square_meters'] : null;
        if ($area === null || $area <= 0) {
            return null;
        }

        foreach ($pages as $page) {
            if ((int) ($page['page'] ?? $anchor['page']) !== $anchor['page']) {
                continue;
            }
            $walls = $page['walls'] ?? [];
            if ($walls === []) {
                continue;
            }
            foreach ($this->wallFrames($anchor, $page) as $frame) {
                $meters = $this->metersFromWallContour($room, $area, $frame, $walls);
                if ($meters !== null) {
                    return $meters;
                }
            }
        }

        return null;
    }

    /**
     * @param  array{x: float, y: float, page: int, threshold: float}  $anchor
     * @param  array{page?: int, width?: float, height?: float, geometry_width?: float, geometry_height?: float, texts?: list<array{text: string, x: float, y: float, page: int}>}  $page
     * @return list<array{x: float, y: float, width: float, height: float, labels: list<array{x: float, y: float, text: string}>}>
     */
    private function wallFrames(array $anchor, array $page): array
    {
        $bboxWidth = max(1.0, (float) ($page['width'] ?? 1));
        $bboxHeight = max(1.0, (float) ($page['height'] ?? 1));
        $geoWidth = max(1.0, (float) ($page['geometry_width'] ?? $bboxWidth));
        $geoHeight = max(1.0, (float) ($page['geometry_height'] ?? $bboxHeight));
        $candidates = [
            [
                'x' => $anchor['x'] * ($geoWidth / $bboxWidth),
                'y' => $geoHeight - ($anchor['y'] * ($geoHeight / $bboxHeight)),
                'width' => $geoWidth,
                'height' => $geoHeight,
                'sx' => $geoWidth / $bboxWidth,
                'sy' => -($geoHeight / $bboxHeight),
                'oy' => $geoHeight,
            ],
            [
                'x' => $anchor['x'],
                'y' => $bboxHeight - $anchor['y'],
                'width' => $bboxWidth,
                'height' => $bboxHeight,
                'sx' => 1.0,
                'sy' => -1.0,
                'oy' => $bboxHeight,
            ],
            [
                'x' => $anchor['x'] * ($geoWidth / $bboxWidth),
                'y' => $anchor['y'] * ($geoHeight / $bboxHeight),
                'width' => $geoWidth,
                'height' => $geoHeight,
                'sx' => $geoWidth / $bboxWidth,
                'sy' => $geoHeight / $bboxHeight,
                'oy' => 0.0,
            ],
        ];

        $frames = [];
        foreach ($candidates as $candidate) {
            $labels = [];
            foreach ($page['texts'] ?? [] as $item) {
                if ((int) ($item['page'] ?? 0) !== $anchor['page']) {
                    continue;
                }
                $labels[] = [
                    'x' => (float) $item['x'] * $candidate['sx'],
                    'y' => ((float) $item['y'] * $candidate['sy']) + $candidate['oy'],
                    'text' => (string) $item['text'],
                ];
            }
            $frames[] = [
                'x' => $candidate['x'],
                'y' => $candidate['y'],
                'width' => $candidate['width'],
                'height' => $candidate['height'],
                'labels' => $labels,
            ];
        }

        return $frames;
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  array{x: float, y: float, width: float, height: float, labels: list<array{x: float, y: float, text: string}>}  $frame
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     */
    private function metersFromWallContour(array $room, float $area, array $frame, array $walls): ?float
    {
        $closed = $this->closeDoorGaps($walls);
        $others = $this->foreignRoomPoints($room, $frame);
        $region = $this->rectilinearRegion(
            $frame['x'],
            $frame['y'],
            $closed,
            $frame['width'],
            $frame['height'],
            $others,
            $area,
        );
        $floodMeters = null;
        if ($region !== null && $this->contourMatchesRoom($room, $area, $region, $frame)) {
            $scale = sqrt($area / $region['area']);
            $meters = $region['perimeter'] * $scale;
            if ($meters >= 1 && $meters <= 200 && $scale >= 0.0005 && $scale <= 0.5) {
                $floodMeters = $this->ceilMeters($meters);
            }
        }

        $box = $this->walledBox($frame['x'], $frame['y'], $walls, $frame['width'], $frame['height']);
        $boxMeters = null;
        if ($box !== null) {
            $pdfArea = $box['width'] * $box['height'];
            if ($pdfArea >= 1) {
                $scale = sqrt($area / $pdfArea);
                $perimeter = 2 * ($box['width'] + $box['height']) * $scale;
                if ($perimeter >= 1 && $perimeter <= 200) {
                    $boxMeters = $this->ceilMeters($perimeter);
                }
            }
        }

        if ($floodMeters !== null) {
            return $floodMeters;
        }

        return $boxMeters;
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>
     */
    private function closeDoorGaps(array $walls): array
    {
        $vertical = [];
        $horizontal = [];
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') === 'v') {
                $vertical[sprintf('%.1f', round((float) $wall['x1'], 1))][] = $wall;
            } elseif (($wall['axis'] ?? '') === 'h') {
                $horizontal[sprintf('%.1f', round((float) $wall['y1'], 1))][] = $wall;
            }
        }

        $closed = $walls;
        foreach ($vertical as $group) {
            usort($group, fn (array $left, array $right) => $left['y1'] <=> $right['y1']);
            for ($index = 0, $last = count($group) - 1; $index < $last; $index++) {
                $gap = (float) $group[$index + 1]['y1'] - (float) $group[$index]['y2'];
                if ($gap <= 2 || $gap > 64) {
                    continue;
                }
                $closed[] = [
                    'x1' => (float) $group[$index]['x1'],
                    'y1' => (float) $group[$index]['y2'],
                    'x2' => (float) $group[$index]['x1'],
                    'y2' => (float) $group[$index + 1]['y1'],
                    'axis' => 'v',
                ];
            }
        }
        foreach ($horizontal as $group) {
            usort($group, fn (array $left, array $right) => $left['x1'] <=> $right['x1']);
            for ($index = 0, $last = count($group) - 1; $index < $last; $index++) {
                $gap = (float) $group[$index + 1]['x1'] - (float) $group[$index]['x2'];
                if ($gap <= 2 || $gap > 64) {
                    continue;
                }
                $closed[] = [
                    'x1' => (float) $group[$index]['x1'],
                    'y1' => (float) $group[$index]['y1'],
                    'x2' => (float) $group[$index + 1]['x1'],
                    'y2' => (float) $group[$index]['y1'],
                    'axis' => 'h',
                ];
            }
        }

        return $closed;
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @param  list<array{x: float, y: float}>  $others
     * @return array{area: float, perimeter: float, cells: list<array{x0: float, y0: float, x1: float, y1: float}>}|null
     */
    private function rectilinearRegion(
        float $x,
        float $y,
        array $walls,
        float $pageWidth,
        float $pageHeight,
        array $others = [],
        float $knownArea = 0.0,
    ): ?array {
        $span = max(
            180.0,
            min($pageWidth, $pageHeight) * 0.42,
            $knownArea > 0 ? sqrt($knownArea) * 90.0 : 0.0,
        );
        $xMin = $x - $span;
        $xMax = $x + $span;
        $yMin = $y - $span;
        $yMax = $y + $span;
        $xs = [];
        $ys = [];
        $local = [];
        foreach ($walls as $wall) {
            $x1 = (float) $wall['x1'];
            $y1 = (float) $wall['y1'];
            $x2 = (float) $wall['x2'];
            $y2 = (float) $wall['y2'];
            if ($x2 < $xMin || $x1 > $xMax || $y2 < $yMin || $y1 > $yMax) {
                continue;
            }
            $local[] = $wall;
            if (($wall['axis'] ?? '') === 'v') {
                $xs[] = $x1;
            } elseif (($wall['axis'] ?? '') === 'h') {
                $ys[] = $y1;
            }
        }
        $walls = $local;
        $xs = $this->uniqueCoords($xs);
        $ys = $this->uniqueCoords($ys);
        if (count($xs) < 2 || count($ys) < 2 || count($xs) > 400 || count($ys) > 400) {
            return null;
        }

        $start = $this->startingCell($x, $y, $xs, $ys);
        if ($start === null) {
            return null;
        }

        $visited = $this->floodCells($start, $xs, $ys, $walls, $x, $y, $others);
        if ($visited === null || $visited === []) {
            return null;
        }

        $area = 0.0;
        $perimeter = 0.0;
        $onWall = 0.0;
        $cells = [];
        $indexed = $this->indexWalls($walls);
        foreach (array_keys($visited) as $key) {
            [$i, $j] = array_map('intval', explode(':', (string) $key));
            $x0 = $xs[$i];
            $x1 = $xs[$i + 1];
            $y0 = $ys[$j];
            $y1 = $ys[$j + 1];
            $area += ($x1 - $x0) * ($y1 - $y0);
            $cells[] = ['x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1];
            $sides = [
                [! isset($visited[($i - 1).':'.$j]), 'v', $x0, $y0, $y1, $y1 - $y0],
                [! isset($visited[($i + 1).':'.$j]), 'v', $x1, $y0, $y1, $y1 - $y0],
                [! isset($visited[$i.':'.($j - 1)]), 'h', $y0, $x0, $x1, $x1 - $x0],
                [! isset($visited[$i.':'.($j + 1)]), 'h', $y1, $x0, $x1, $x1 - $x0],
            ];
            foreach ($sides as [$exposed, $axis, $at, $from, $to, $length]) {
                if (! $exposed) {
                    continue;
                }
                $perimeter += $length;
                if ($this->indexedEdgeBlocked($indexed, $axis, $at, $from, $to)) {
                    $onWall += $length;
                }
            }
        }
        if ($area < 40 || $area > $pageWidth * $pageHeight * 0.45 || $perimeter < 8) {
            return null;
        }
        if ($onWall / $perimeter < 0.45) {
            return null;
        }

        return ['area' => $area, 'perimeter' => $perimeter, 'cells' => $cells];
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private function uniqueCoords(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $unique[sprintf('%.0f', round($value))] = (float) round($value);
        }
        $coords = array_values($unique);
        sort($coords);

        return $coords;
    }

    /**
     * @param  list<float>  $xs
     * @param  list<float>  $ys
     * @return array{0: int, 1: int}|null
     */
    private function startingCell(float $x, float $y, array $xs, array $ys): ?array
    {
        foreach ([$x, $x + 1.2, $x - 1.2] as $tx) {
            foreach ([$y, $y + 1.2, $y - 1.2] as $ty) {
                $i = $this->intervalIndex($tx, $xs);
                $j = $this->intervalIndex($ty, $ys);
                if ($i !== null && $j !== null) {
                    return [$i, $j];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<float>  $coords
     */
    private function intervalIndex(float $value, array $coords): ?int
    {
        for ($index = 0, $last = count($coords) - 1; $index < $last; $index++) {
            if ($value > $coords[$index] + 0.15 && $value < $coords[$index + 1] - 0.15) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array{0: int, 1: int}  $start
     * @param  list<float>  $xs
     * @param  list<float>  $ys
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @param  list<array{x: float, y: float}>  $others
     * @return array<string, true>|null
     */
    private function floodCells(
        array $start,
        array $xs,
        array $ys,
        array $walls,
        float $seedX,
        float $seedY,
        array $others,
    ): ?array {
        $indexed = $this->indexWalls($walls);
        $maxI = count($xs) - 2;
        $maxJ = count($ys) - 2;
        $queue = [$start];
        $visited = [];
        while ($queue !== []) {
            [$i, $j] = array_pop($queue);
            $key = $i.':'.$j;
            if (isset($visited[$key])) {
                continue;
            }
            if (count($visited) > 2500) {
                return null;
            }
            $visited[$key] = true;
            $edges = [
                [$i - 1, $j, 'v', $xs[$i], $ys[$j], $ys[$j + 1]],
                [$i + 1, $j, 'v', $xs[$i + 1], $ys[$j], $ys[$j + 1]],
                [$i, $j - 1, 'h', $ys[$j], $xs[$i], $xs[$i + 1]],
                [$i, $j + 1, 'h', $ys[$j + 1], $xs[$i], $xs[$i + 1]],
            ];
            foreach ($edges as [$ni, $nj, $axis, $at, $from, $to]) {
                $blocked = $this->indexedEdgeBlocked($indexed, $axis, $at, $from, $to);
                if ($ni < 0 || $nj < 0 || $ni > $maxI || $nj > $maxJ) {
                    continue;
                }
                if ($blocked) {
                    continue;
                }
                $cx = ($xs[$ni] + $xs[$ni + 1]) / 2;
                $cy = ($ys[$nj] + $ys[$nj + 1]) / 2;
                if ($this->closerToOtherRoom($cx, $cy, $seedX, $seedY, $others)) {
                    continue;
                }
                $queue[] = [$ni, $nj];
            }
        }

        return $visited;
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return array{v: array<string, list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>>, h: array<string, list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>>}
     */
    private function indexWalls(array $walls): array
    {
        $vertical = [];
        $horizontal = [];
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') === 'v') {
                $vertical[(string) (int) round((float) $wall['x1'])][] = $wall;
            } elseif (($wall['axis'] ?? '') === 'h') {
                $horizontal[(string) (int) round((float) $wall['y1'])][] = $wall;
            }
        }

        return ['v' => $vertical, 'h' => $horizontal];
    }

    /**
     * @param  array{v: array<string, list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>>, h: array<string, list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>>}  $indexed
     */
    private function indexedEdgeBlocked(array $indexed, string $axis, float $at, float $from, float $to): bool
    {
        $need = min(4.0, abs($to - $from) * 0.45);
        $bucket = (int) round($at);
        $group = $axis === 'v' ? $indexed['v'] : $indexed['h'];
        foreach ([$bucket - 2, $bucket - 1, $bucket, $bucket + 1, $bucket + 2] as $key) {
            foreach ($group[(string) $key] ?? [] as $wall) {
                if ($axis === 'v') {
                    if (abs((float) $wall['x1'] - $at) > 2.0) {
                        continue;
                    }
                    $overlap = min((float) $wall['y2'], $to) - max((float) $wall['y1'], $from);
                } else {
                    if (abs((float) $wall['y1'] - $at) > 2.0) {
                        continue;
                    }
                    $overlap = min((float) $wall['x2'], $to) - max((float) $wall['x1'], $from);
                }
                if ($overlap >= $need) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{x: float, y: float}>  $others
     */
    private function closerToOtherRoom(float $x, float $y, float $seedX, float $seedY, array $others): bool
    {
        $toSeed = hypot($x - $seedX, $y - $seedY);
        foreach ($others as $other) {
            if (hypot($x - (float) $other['x'], $y - (float) $other['y']) + 8 < $toSeed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  array{labels: list<array{x: float, y: float, text: string}>}  $frame
     * @return list<array{x: float, y: float}>
     */
    private function foreignRoomPoints(array $room, array $frame): array
    {
        $current = mb_strtolower(trim((string) ($room['room_number'] ?? '')));
        $points = [];
        foreach ($frame['labels'] as $label) {
            if (! $this->isOtherRoomLabel((string) $label['text'], $current)) {
                continue;
            }
            $points[] = ['x' => (float) $label['x'], 'y' => (float) $label['y']];
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  array{area: float, perimeter: float, cells: list<array{x0: float, y0: float, x1: float, y1: float}>}  $region
     * @param  array{x: float, y: float, width: float, height: float, labels: list<array{x: float, y: float, text: string}>}  $frame
     */
    private function contourMatchesRoom(array $room, float $area, array $region, array $frame): bool
    {
        $current = mb_strtolower(trim((string) ($room['room_number'] ?? '')));
        foreach ($frame['labels'] as $label) {
            if (! $this->isOtherRoomLabel((string) $label['text'], $current)) {
                continue;
            }
            if ($this->pointInCells((float) $label['x'], (float) $label['y'], $region['cells'])) {
                return false;
            }
        }

        $areaPoint = $this->matchingAreaLabel($area, $frame);
        if ($areaPoint !== null && ! $this->pointInCells($areaPoint['x'], $areaPoint['y'], $region['cells'])) {
            return false;
        }

        return ($region['perimeter'] ** 2) / max(1.0, $region['area']) >= 14.0;
    }

    /**
     * @param  array{x: float, y: float, labels: list<array{x: float, y: float, text: string}>}  $frame
     * @return array{x: float, y: float}|null
     */
    private function matchingAreaLabel(float $area, array $frame): ?array
    {
        $units = [];
        foreach ($frame['labels'] as $label) {
            if (preg_match('/^m(?:²|2)$/iu', trim((string) $label['text']))) {
                $units[] = $label;
            }
        }
        foreach ($frame['labels'] as $label) {
            if (! preg_match('/^\d{1,4}(?:[.,]\d+)?$/u', trim((string) $label['text']))) {
                continue;
            }
            $value = DutchNumber::parse(trim((string) $label['text']));
            if ($value === null || abs($value - $area) > 0.16) {
                continue;
            }
            foreach ($units as $unit) {
                if (hypot((float) $label['x'] - (float) $unit['x'], (float) $label['y'] - (float) $unit['y']) <= 36) {
                    return ['x' => (float) $label['x'], 'y' => (float) $label['y']];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{x0: float, y0: float, x1: float, y1: float}>  $cells
     */
    private function pointInCells(float $x, float $y, array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($x >= $cell['x0'] - 1 && $x <= $cell['x1'] + 1 && $y >= $cell['y0'] - 1 && $y <= $cell['y1'] + 1) {
                return true;
            }
        }

        return false;
    }

    private function isOtherRoomLabel(string $text, string $current): bool
    {
        $text = trim($text);
        if (preg_match('/\b([A-Z]-\d{2}-\d{2})\b/u', $text, $match)) {
            return mb_strtolower($match[1]) !== $current;
        }
        if ($current !== '' && preg_match('/(?<![0-9])(\d{1,2}[.\-]\d{2}[a-zA-Z]?)(?![0-9.\-])/u', $text, $match)) {
            return mb_strtolower($match[1]) !== $current;
        }

        return false;
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return array{width: float, height: float}|null
     */
    private function walledBox(float $x, float $y, array $walls, float $pageWidth, float $pageHeight): ?array
    {
        $left = $this->nearestVerticalWall($walls, $x, $y, 'left');
        $right = $this->nearestVerticalWall($walls, $x, $y, 'right');
        $bottom = $this->nearestHorizontalWall($walls, $x, $y, 'bottom');
        $top = $this->nearestHorizontalWall($walls, $x, $y, 'top');
        if ($left === null || $right === null || $bottom === null || $top === null) {
            return null;
        }
        $width = $right - $left;
        $height = $top - $bottom;
        $area = $width * $height;
        if ($width < 8 || $height < 8 || $area < 40 || $area > $pageWidth * $pageHeight * 0.45) {
            return null;
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     */
    private function nearestVerticalWall(array $walls, float $x, float $y, string $side): ?float
    {
        $best = null;
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') !== 'v') {
                continue;
            }
            $wx = (float) $wall['x1'];
            $y1 = (float) $wall['y1'];
            $y2 = (float) $wall['y2'];
            if (($y2 - $y1) < 8) {
                continue;
            }
            if ($y < $y1 - 40 || $y > $y2 + 40) {
                continue;
            }
            if ($side === 'left' && $wx < $x - 1.5 && ($best === null || $wx > $best)) {
                $best = $wx;
            }
            if ($side === 'right' && $wx > $x + 1.5 && ($best === null || $wx < $best)) {
                $best = $wx;
            }
        }

        return $best;
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     */
    private function nearestHorizontalWall(array $walls, float $x, float $y, string $side): ?float
    {
        $best = null;
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') !== 'h') {
                continue;
            }
            $wy = (float) $wall['y1'];
            $x1 = (float) $wall['x1'];
            $x2 = (float) $wall['x2'];
            if (($x2 - $x1) < 8) {
                continue;
            }
            if ($x < $x1 - 40 || $x > $x2 + 40) {
                continue;
            }
            if ($side === 'bottom' && $wy < $y - 1.5 && ($best === null || $wy > $best)) {
                $best = $wy;
            }
            if ($side === 'top' && $wy > $y + 1.5 && ($best === null || $wy < $best)) {
                $best = $wy;
            }
        }

        return $best;
    }

    /**
     * @param  array{x: float, y: float, page: int, threshold: float}  $anchor
     * @param  list<array{texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     */
    private function labelledPerimeter(array $anchor, array $pages): ?float
    {
        foreach ($pages as $page) {
            $items = $page['texts'] ?? [];
            foreach ($items as $index => $item) {
                if ((int) ($item['page'] ?? 0) !== $anchor['page']) {
                    continue;
                }
                if (! preg_match('/omtrek/iu', (string) $item['text'])) {
                    continue;
                }
                if (hypot((float) $item['x'] - $anchor['x'], (float) $item['y'] - $anchor['y']) > $anchor['threshold'] * 1.4) {
                    continue;
                }
                $window = (string) $item['text'];
                foreach (array_slice($items, max(0, $index - 1), 4) as $near) {
                    $window .= ' '.$near['text'];
                }
                if (preg_match('/omtrek[^0-9]{0,12}(\d+(?:[.,]\d+)?)/iu', $window, $match)) {
                    $value = DutchNumber::parse($match[1]);
                    if ($value !== null && $value >= 1 && $value <= 200) {
                        return $this->ceilMeters($value);
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array{x: float, y: float, page: int, threshold: float}  $anchor
     * @param  list<array{texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @return array{reliable: list<float>, skipped: list<float>}
     */
    private function doorCandidates(array $anchor, array $pages): array
    {
        $reliable = [];
        $skipped = [];
        $seen = [];
        foreach ($pages as $page) {
            foreach ($page['texts'] ?? [] as $item) {
                if ((int) ($item['page'] ?? 0) !== $anchor['page']) {
                    continue;
                }
                if (hypot((float) $item['x'] - $anchor['x'], (float) $item['y'] - $anchor['y']) > $anchor['threshold']) {
                    continue;
                }
                $parsed = $this->doorWidthFromText(trim((string) $item['text']));
                if ($parsed === null) {
                    continue;
                }
                $key = round((float) $item['x'], 1).'|'.round((float) $item['y'], 1).'|'.round($parsed['meters'], 3);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if ($parsed['reliable']) {
                    $reliable[] = $parsed['meters'];
                } else {
                    $skipped[] = $parsed['meters'];
                }
            }
        }

        return ['reliable' => $reliable, 'skipped' => $skipped];
    }

    /**
     * @return array{meters: float, reliable: bool}|null
     */
    private function doorWidthFromText(string $text): ?array
    {
        $meters = null;
        if (preg_match('/^(0[.,]\d{2}|1[.,]\d{2})$/', $text)) {
            $meters = DutchNumber::parse($text);
        } elseif (preg_match('/^([4-9]\d{2}|1[0-9]\d{2})$/', $text)) {
            $meters = ((float) $text) / 1000;
        }
        if ($meters === null || $meters < 0.4 || $meters > 2.0) {
            return null;
        }

        return [
            'meters' => $meters,
            'reliable' => $meters >= 0.6 && $meters <= 1.25,
        ];
    }

    private function ceilMeters(float $meters): float
    {
        return ceil($meters * 1000 - 1e-9) / 1000;
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  array{x: float, y: float, page: int, threshold: float}|null  $anchor
     * @param  list<array{page?: int, texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @return array{meters: ?float, source: string, trace: string, status: string, gross: ?float, doors: list<float>, net: ?float}
     */
    private function estimated(array $room, ?array $anchor, array $pages): array
    {
        $fromDimensions = $anchor === null ? null : $this->perimeterFromNearbyDimensions($anchor, $pages);
        if ($fromDimensions !== null) {
            return $this->estimatedResult($fromDimensions);
        }

        $area = is_numeric($room['square_meters'] ?? null) ? (float) $room['square_meters'] : null;
        if ($area === null || $area <= 0) {
            return $this->unknown();
        }

        $meters = $this->generousPerimeterFromArea($area);
        if ($meters < 0.4 || $meters > 200) {
            return $this->unknown();
        }

        return $this->estimatedResult($meters);
    }

    /**
     * @param  array{x: float, y: float, page: int, threshold: float}  $anchor
     * @param  list<array{page?: int, texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     */
    private function perimeterFromNearbyDimensions(array $anchor, array $pages): ?float
    {
        foreach ($pages as $page) {
            foreach ($page['texts'] ?? [] as $item) {
                if ((int) ($item['page'] ?? 0) !== $anchor['page']) {
                    continue;
                }
                if (hypot((float) $item['x'] - $anchor['x'], (float) $item['y'] - $anchor['y']) > $anchor['threshold'] * 1.4) {
                    continue;
                }
                if (! preg_match('/(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)(?:\s*m)?/iu', (string) $item['text'], $match)) {
                    continue;
                }
                $width = DutchNumber::parse($match[1]);
                $length = DutchNumber::parse($match[2]);
                if ($width === null || $length === null || $width < 0.4 || $length < 0.4 || $width > 80 || $length > 80) {
                    continue;
                }

                return $this->ceilMeters(2 * ($width + $length));
            }
        }

        return null;
    }

    private function generousPerimeterFromArea(float $area): float
    {
        $ratio = self::ESTIMATE_ASPECT;
        $sideA = sqrt($area * $ratio);
        $sideB = sqrt($area / $ratio);

        return $this->ceilMeters(2 * ($sideA + $sideB));
    }

    /**
     * @return array{meters: float, source: string, trace: string, status: string, gross: float, doors: list<float>, net: float}
     */
    private function estimatedResult(float $meters): array
    {
        $meters = $this->ceilMeters($meters);

        return [
            'meters' => $meters,
            'source' => QuantitySource::Calculated->value,
            'trace' => Format::qty($meters, 2).' m¹ – contour niet volledig herkenbaar; ruime calculatieschatting.',
            'status' => self::STATUS_ESTIMATED,
            'gross' => $meters,
            'doors' => [],
            'net' => $meters,
        ];
    }

    /**
     * @return array{meters: null, source: string, trace: string, status: string, gross: null, doors: list<float>, net: null}
     */
    private function unknown(): array
    {
        return [
            'meters' => null,
            'source' => QuantitySource::Review->value,
            'trace' => 'Ruimtecontour/omtrek niet betrouwbaar uit de PDF; m¹ niet verzonnen.',
            'status' => self::STATUS_MISSING,
            'gross' => null,
            'doors' => [],
            'net' => null,
        ];
    }
}
