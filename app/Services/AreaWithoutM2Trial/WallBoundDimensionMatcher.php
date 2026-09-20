<?php

namespace App\Services\AreaWithoutM2Trial;

/**
 * Bind millimetre labels to a room's own walls. Never pick the two largest nearby numbers.
 */
class WallBoundDimensionMatcher
{
    private const OVERALL_RATIO = 1.35;

    private const WALL_MATCH_RATIO = 0.25;

    private const SCALE_AGREE = 0.15;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $boundaryCache = [];

    public function __construct(
        private RoomBoundaryReconstructor $reconstructor = new RoomBoundaryReconstructor,
        private DimensionChainBinder $chains = new DimensionChainBinder,
    ) {}

    /**
     * @param  array{x: float, y: float, page: int, room_number?: string}  $anchor
     * @param  list<array<string, mixed>>  $dimensions
     * @param  array<string, mixed>  $page
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     * @return array{
     *     box: ?array{left: float, right: float, bottom: float, top: float, width: float, height: float},
     *     horizontal: ?array<string, mixed>,
     *     vertical: ?array<string, mixed>,
     *     rejected: list<array{mm: int, reason: string, endpoints?: string, expected?: string, delta?: string}>,
     *     dimension_debug: list<array{mm: int, decision: string, reason: string, endpoints: string, expected: string, delta: string}>,
     *     confidence: float,
     *     boundary: array<string, mixed>,
     *     scale_mm_per_px: ?float
     * }
     */
    public function match(array $anchor, array $dimensions, array $page, array $anchors): array
    {
        $boundary = $this->boundaryFor($anchor, $page, $anchors);
        $box = is_array($boundary['box'] ?? null) ? $boundary['box'] : null;
        $sides = [
            'left' => isset($boundary['left_pos']) ? (float) $boundary['left_pos'] : null,
            'right' => isset($boundary['right_pos']) ? (float) $boundary['right_pos'] : null,
            'top' => isset($boundary['top_pos']) ? (float) $boundary['top_pos'] : null,
            'bottom' => isset($boundary['bottom_pos']) ? (float) $boundary['bottom_pos'] : null,
        ];
        $pageMin = $this->pageMin($page);
        $printedScale = isset($page['drawing_scale']) ? (int) $page['drawing_scale'] : null;
        $lines = $this->dimensionLines($page, $boundary);
        $rejected = [];
        $matchBox = $box ?? $this->boxFromSides($sides);
        $boxEdges = is_array($matchBox) ? $this->boxEdgeWalls($matchBox) : [];
        $overallWalls = ($boundary['main_walls'] ?? []) !== []
            ? $boundary['main_walls']
            : ($page['walls'] ?? []);
        $otherBoxes = $this->otherBoxes($anchor, $anchors, $page);
        $horizontal = null;
        $vertical = null;
        $pairKnownH = $sides['left'] !== null && $sides['right'] !== null;
        $pairKnownV = $sides['top'] !== null && $sides['bottom'] !== null;
        $dimensionDebug = [];

        foreach ($dimensions as $dimension) {
            $mm = (int) ($dimension['mm'] ?? 0);
            if ($mm < 400) {
                continue;
            }
            $hChain = $this->chains->pickHorizontalChain($dimension, $lines, $sides, $pageMin);
            $vChain = $this->chains->pickVerticalChain($dimension, $lines, $sides, $pageMin);
            $hDebug = $this->chains->endpointDebug($hChain, $sides['left'], $sides['right'], true);
            $vDebug = $this->chains->endpointDebug($vChain, $sides['bottom'], $sides['top'], false);
            if (is_array($matchBox) && $this->closerToOtherBox($dimension, $matchBox, $otherBoxes)) {
                $rejected[] = $this->rejectRow(
                    $mm,
                    'dichter bij de contour van een andere ruimte',
                    $hDebug,
                    $vDebug,
                );
                $dimensionDebug[] = $this->debugRow($mm, 'afgewezen', 'dichter bij de contour van een andere ruimte', $hDebug, $vDebug);

                continue;
            }

            $chainWidth = $this->chains->asWidth($dimension, $lines, $sides, $anchor, $anchors, $pageMin, $printedScale);
            $chainHeight = $this->chains->asHeight($dimension, $lines, $sides, $anchor, $anchors, $pageMin, $printedScale);
            $asWidth = $chainWidth;
            $asHeight = $chainHeight;
            if ($asWidth === null && ! $pairKnownH && is_array($matchBox)) {
                $asWidth = $this->asRoomWidth($dimension, $matchBox, $boxEdges, $overallWalls);
            }
            if ($asHeight === null && ! $pairKnownV && is_array($matchBox)) {
                $asHeight = $this->asRoomHeight($dimension, $matchBox, $boxEdges, $overallWalls);
            }

            if ($asWidth === null && $asHeight === null) {
                $reason = $this->endpointRejectReason($hChain, $vChain, $pairKnownH, $pairKnownV, $hDebug, $vDebug)
                    ?? $this->chains->rejectReason($dimension, $lines, $anchor, $anchors, $pageMin, $sides)
                    ?? (is_array($matchBox) ? $this->rejectReason($dimension, $matchBox, $overallWalls) : 'maatketting sluit niet aan op de wanden van deze ruimte');
                $rejected[] = $this->rejectRow($mm, $reason, $hDebug, $vDebug);
                $dimensionDebug[] = $this->debugRow($mm, 'afgewezen', $reason, $hDebug, $vDebug);

                continue;
            }

            if ($asWidth !== null && ($horizontal === null || $asWidth['score'] > $horizontal['score'])) {
                $horizontal = $asWidth + $hDebug;
            }
            if ($asHeight !== null && ($vertical === null || $asHeight['score'] > $vertical['score'])) {
                $vertical = $asHeight + $vDebug;
            }
            $dimensionDebug[] = $this->debugRow(
                $mm,
                'geaccepteerd',
                $asWidth !== null && $asHeight !== null
                    ? 'gekoppeld als breedte en hoogte'
                    : ($asWidth !== null ? 'gekoppeld als breedte' : 'gekoppeld als hoogte'),
                $hDebug,
                $vDebug,
            );
        }

        $confidence = 0.0;
        $sides = $this->applyChainEnds($sides, $horizontal, $vertical);
        if ($box === null) {
            $matchBox = $this->boxFromSides($sides);
            if (is_array($matchBox)) {
                $box = $matchBox;
                $boundary['box'] = $box;
                $boundary['left_pos'] = $sides['left'];
                $boundary['right_pos'] = $sides['right'];
                $boundary['top_pos'] = $sides['top'];
                $boundary['bottom_pos'] = $sides['bottom'];
            }
        }
        if ($horizontal !== null && $vertical !== null && $this->scalesAgree($horizontal, $vertical, $box)) {
            $confidence = 0.9;
        } elseif ($horizontal !== null && $vertical !== null) {
            $keepHorizontal = ((float) $horizontal['score']) >= ((float) $vertical['score']);
            $dropped = $keepHorizontal ? $vertical : $horizontal;
            $rejected[] = $this->rejectRow(
                (int) $dropped['mm'],
                'horizontale en verticale schaal komen niet overeen; combinatie niet gebruikt',
                [
                    'endpoints' => $dropped['endpoints'] ?? '—',
                    'expected' => $dropped['expected'] ?? '—',
                    'delta' => $dropped['delta'] ?? '—',
                ],
                [
                    'endpoints' => '—',
                    'expected' => '—',
                    'delta' => '—',
                ],
            );
            $dimensionDebug[] = $this->debugRow(
                (int) $dropped['mm'],
                'afgewezen',
                'horizontale en verticale schaal komen niet overeen; combinatie niet gebruikt',
                [
                    'endpoints' => $dropped['endpoints'] ?? '—',
                    'expected' => $dropped['expected'] ?? '—',
                    'delta' => $dropped['delta'] ?? '—',
                ],
                [
                    'endpoints' => '—',
                    'expected' => '—',
                    'delta' => '—',
                ],
            );
            if ($keepHorizontal) {
                $vertical = null;
            } else {
                $horizontal = null;
            }
            $confidence = 0.62;
        } elseif ($horizontal !== null || $vertical !== null) {
            $confidence = 0.62;
        }

        $scale = $this->scaleFrom($horizontal, $vertical, $box ?? $matchBox);

        return [
            'box' => $box,
            'horizontal' => $horizontal,
            'vertical' => $vertical,
            'rejected' => $rejected,
            'dimension_debug' => $dimensionDebug,
            'confidence' => $confidence,
            'boundary' => $boundary,
            'scale_mm_per_px' => $scale,
        ];
    }

    /**
     * @param  array{endpoints: string, expected: string, delta: string}  $horizontal
     * @param  array{endpoints: string, expected: string, delta: string}  $vertical
     * @return array{mm: int, reason: string, endpoints: string, expected: string, delta: string}
     */
    private function rejectRow(int $mm, string $reason, array $horizontal, array $vertical): array
    {
        $debug = $this->preferAxisDebug($horizontal, $vertical);

        return [
            'mm' => $mm,
            'reason' => $reason,
            'endpoints' => $debug['endpoints'],
            'expected' => $debug['expected'],
            'delta' => $debug['delta'],
        ];
    }

    /**
     * @param  array{endpoints: string, expected: string, delta: string}  $horizontal
     * @param  array{endpoints: string, expected: string, delta: string}  $vertical
     * @return array{mm: int, decision: string, reason: string, endpoints: string, expected: string, delta: string}
     */
    private function debugRow(int $mm, string $decision, string $reason, array $horizontal, array $vertical): array
    {
        $debug = $this->preferAxisDebug($horizontal, $vertical);

        return [
            'mm' => $mm,
            'decision' => $decision,
            'reason' => $reason,
            'endpoints' => $debug['endpoints'],
            'expected' => $debug['expected'],
            'delta' => $debug['delta'],
        ];
    }

    /**
     * @param  array{endpoints: string, expected: string, delta: string}  $horizontal
     * @param  array{endpoints: string, expected: string, delta: string}  $vertical
     * @return array{endpoints: string, expected: string, delta: string}
     */
    private function preferAxisDebug(array $horizontal, array $vertical): array
    {
        if (($horizontal['endpoints'] ?? '') !== 'geen maatsegment gevonden') {
            return $horizontal;
        }

        return $vertical;
    }

    /**
     * @param  array{start?: float, end?: float}|null  $horizontal
     * @param  array{start?: float, end?: float}|null  $vertical
     * @param  array{endpoints: string, expected: string, delta: string}  $hDebug
     * @param  array{endpoints: string, expected: string, delta: string}  $vDebug
     */
    private function endpointRejectReason(?array $horizontal, ?array $vertical, bool $pairKnownH, bool $pairKnownV, array $hDebug, array $vDebug): ?string
    {
        if ($pairKnownH && $horizontal !== null) {
            return 'maatlijn-endpoints sluiten niet aan op linker- en rechterwand ('.$hDebug['endpoints'].' vs '.$hDebug['expected'].', Δ '.$hDebug['delta'].')';
        }
        if ($pairKnownV && $vertical !== null) {
            return 'maatlijn-endpoints sluiten niet aan op boven- en onderwand ('.$vDebug['endpoints'].' vs '.$vDebug['expected'].', Δ '.$vDebug['delta'].')';
        }
        if ($pairKnownH || $pairKnownV) {
            return 'geen maatsegment gevonden waarvan de endpoints op dezelfde wandassen liggen';
        }

        return null;
    }

    /**
     * @param  array{left: float, right: float, bottom: float, top: float, width: float, height: float}  $box
     * @param  array<string, mixed>  $horizontal
     * @param  array<string, mixed>  $vertical
     */
    public function scalesAgree(array $horizontal, array $vertical, ?array $box): bool
    {
        $width = (float) ($horizontal['span_px'] ?? 0);
        $height = (float) ($vertical['span_px'] ?? 0);
        if ($width < 8 && is_array($box)) {
            $width = max(1.0, (float) $box['width']);
        }
        if ($height < 8 && is_array($box)) {
            $height = max(1.0, (float) $box['height']);
        }
        if ($width < 8 || $height < 8) {
            return false;
        }
        $scaleH = ((int) $horizontal['mm']) / $width;
        $scaleV = ((int) $vertical['mm']) / $height;
        $mean = ($scaleH + $scaleV) / 2;
        if ($mean <= 0) {
            return false;
        }

        return abs($scaleH - $scaleV) / $mean <= self::SCALE_AGREE;
    }

    /**
     * @param  array{x: float, y: float, page?: int, room_key?: string, room_number?: string}  $anchor
     * @param  array<string, mixed>  $page
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $anchors
     * @return array<string, mixed>
     */
    public function roomBox(array $anchor, array $page, array $anchors = []): ?array
    {
        $boundary = $this->boundaryFor($anchor, $page, $anchors);

        return is_array($boundary['box'] ?? null) ? $boundary['box'] : null;
    }

    /**
     * @param  array{x: float, y: float, page?: int, room_key?: string, room_number?: string}  $anchor
     * @param  array<string, mixed>  $page
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $anchors
     * @return array<string, mixed>
     */
    private function boundaryFor(array $anchor, array $page, array $anchors): array
    {
        $key = (string) ($anchor['room_key'] ?? ($anchor['page'] ?? 1).':'.round((float) $anchor['x']).':'.round((float) $anchor['y']));
        if (! isset($this->boundaryCache[$key])) {
            $this->boundaryCache[$key] = $this->reconstructor->reconstruct($anchor, $page, $anchors);
        }

        return $this->boundaryCache[$key];
    }

    /**
     * @param  array<string, mixed>|null  $horizontal
     * @param  array<string, mixed>|null  $vertical
     * @param  array{width?: float, height?: float}|null  $box
     */
    private function scaleFrom(?array $horizontal, ?array $vertical, ?array $box): ?float
    {
        $scales = [];
        $width = (float) ($horizontal['span_px'] ?? ($box['width'] ?? 0));
        $height = (float) ($vertical['span_px'] ?? ($box['height'] ?? 0));
        if (is_array($horizontal) && $width > 0) {
            $scales[] = ((int) $horizontal['mm']) / $width;
        }
        if (is_array($vertical) && $height > 0) {
            $scales[] = ((int) $vertical['mm']) / $height;
        }
        if ($scales === []) {
            return null;
        }
        $mean = array_sum($scales) / count($scales);
        if ($mean <= 0) {
            return null;
        }
        foreach ($scales as $scale) {
            if (abs($scale - $mean) / $mean > self::SCALE_AGREE) {
                return round($mean, 3);
            }
        }

        return round($mean, 3);
    }

    /**
     * @param  array<string, mixed>  $dimension
     * @param  array{left: float, right: float, bottom: float, top: float, width: float, height: float}  $box
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $boxEdges
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $overallWalls
     * @return array<string, mixed>|null
     */
    private function asRoomWidth(array $dimension, array $box, array $boxEdges, array $overallWalls): ?array
    {
        $x = (float) $dimension['x'];
        $y = (float) $dimension['y'];
        $band = max(16.0, (float) $box['height'] * 0.25);
        $alongBottom = $y >= $box['bottom'] - $band && $y <= $box['bottom'] + 14;
        $alongTop = $y >= $box['top'] - 14 && $y <= $box['top'] + $band;
        $xInside = $x >= $box['left'] - 10 && $x <= $box['right'] + 10;
        if ((! $alongBottom && ! $alongTop) || ! $xInside) {
            return null;
        }

        $side = $alongBottom ? 'onder' : 'boven';
        $maxDistance = max(36.0, $band);
        $roomWall = $this->matchingRoomWall($dimension, $boxEdges, 'h', (float) $box['width'], $maxDistance);
        $overall = $this->nearbyOverallWall($dimension, $overallWalls, 'h', (float) $box['width'], $maxDistance);
        if ($overall !== null && ($roomWall === null || $roomWall['distance'] > $overall['distance'] + 2)) {
            return null;
        }
        if ($roomWall === null) {
            return null;
        }

        return [
            'mm' => (int) $dimension['mm'],
            'x' => $x,
            'y' => $y,
            'axis' => 'horizontal',
            'wall_label' => $this->wallLabel($roomWall['wall'], $side.'wand + linker-/rechterwand'),
            'score' => 1.0 / max(1.0, $roomWall['distance']),
            'side' => $side,
            'span_px' => (float) $box['width'],
            'source' => 'walls',
            'segment' => 'x='.round((float) $box['left']).'–'.round((float) $box['right']).', y='.round($y),
            'overlay' => [
                'x1' => (float) $box['left'],
                'y1' => $y,
                'x2' => (float) $box['right'],
                'y2' => $y,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dimension
     * @param  array{left: float, right: float, bottom: float, top: float, width: float, height: float}  $box
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $boxEdges
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $overallWalls
     * @return array<string, mixed>|null
     */
    private function asRoomHeight(array $dimension, array $box, array $boxEdges, array $overallWalls): ?array
    {
        $x = (float) $dimension['x'];
        $y = (float) $dimension['y'];
        $band = max(16.0, (float) $box['width'] * 0.25);
        $alongLeft = $x >= $box['left'] - $band && $x <= $box['left'] + 14;
        $alongRight = $x >= $box['right'] - 14 && $x <= $box['right'] + $band;
        $yInside = $y >= $box['bottom'] - 10 && $y <= $box['top'] + 10;
        if ((! $alongLeft && ! $alongRight) || ! $yInside) {
            return null;
        }

        $side = $alongLeft ? 'linker' : 'rechter';
        $maxDistance = max(36.0, $band);
        $roomWall = $this->matchingRoomWall($dimension, $boxEdges, 'v', (float) $box['height'], $maxDistance);
        $overall = $this->nearbyOverallWall($dimension, $overallWalls, 'v', (float) $box['height'], $maxDistance);
        if ($overall !== null && ($roomWall === null || $roomWall['distance'] > $overall['distance'] + 2)) {
            return null;
        }
        if ($roomWall === null) {
            return null;
        }

        return [
            'mm' => (int) $dimension['mm'],
            'x' => $x,
            'y' => $y,
            'axis' => 'vertical',
            'wall_label' => $this->wallLabel($roomWall['wall'], $side.'wand + boven-/onderwand'),
            'score' => 1.0 / max(1.0, $roomWall['distance']),
            'side' => $side,
            'span_px' => (float) $box['height'],
            'source' => 'walls',
            'segment' => 'y='.round((float) $box['bottom']).'–'.round((float) $box['top']).', x='.round($x),
            'overlay' => [
                'x1' => $x,
                'y1' => (float) $box['bottom'],
                'x2' => $x,
                'y2' => (float) $box['top'],
            ],
        ];
    }

    /**
     * @param  array{x: float, y: float}  $point
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return array{wall: array{x1: float, y1: float, x2: float, y2: float, axis: string}, distance: float}|null
     */
    private function matchingRoomWall(array $point, array $walls, string $axis, float $expectedLength, float $maxDistance = 36.0): ?array
    {
        $best = null;
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') !== $axis) {
                continue;
            }
            $length = $this->wallLength($wall);
            if ($expectedLength < 8 || abs($length - $expectedLength) / $expectedLength > self::WALL_MATCH_RATIO) {
                continue;
            }
            $distance = $this->distanceToWall($point, $wall);
            if ($distance > $maxDistance) {
                continue;
            }
            if ($best === null || $distance < $best['distance']) {
                $best = ['wall' => $wall, 'distance' => $distance];
            }
        }

        return $best;
    }

    /**
     * @param  array{x: float, y: float}  $point
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return array{wall: array{x1: float, y1: float, x2: float, y2: float, axis: string}, distance: float}|null
     */
    private function nearbyOverallWall(array $point, array $walls, string $axis, float $roomLength, float $maxDistance = 40.0): ?array
    {
        $best = null;
        foreach ($walls as $wall) {
            if (($wall['axis'] ?? '') !== $axis) {
                continue;
            }
            $length = $this->wallLength($wall);
            if ($roomLength >= 8 && $length < $roomLength * self::OVERALL_RATIO) {
                continue;
            }
            $distance = $this->distanceToWall($point, $wall);
            if ($distance > $maxDistance) {
                continue;
            }
            if ($best === null || $distance < $best['distance']) {
                $best = ['wall' => $wall, 'distance' => $distance];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $dimension
     * @param  array{left: float, right: float, bottom: float, top: float, width: float, height: float}  $box
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     */
    private function rejectReason(array $dimension, array $box, array $walls): string
    {
        $overallH = $this->nearbyOverallWall($dimension, $walls, 'h', (float) $box['width']);
        $overallV = $this->nearbyOverallWall($dimension, $walls, 'v', (float) $box['height']);
        if ($overallH !== null || $overallV !== null) {
            return 'totale/stramienmaat of maat van een langere wand, niet van deze ruimte';
        }

        return 'maatlijn sluit niet aan op de linker- en rechterwand of boven- en onderwand van deze ruimte';
    }

    /**
     * @param  array{x: float, y: float}  $dimension
     * @param  array{left: float, right: float, bottom: float, top: float}  $box
     * @param  list<array{left: float, right: float, bottom: float, top: float}>  $otherBoxes
     */
    private function closerToOtherBox(array $dimension, array $box, array $otherBoxes): bool
    {
        $own = $this->distanceToBox($dimension, $box);
        foreach ($otherBoxes as $other) {
            if ($this->distanceToBox($dimension, $other) + 4 < $own) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{x: float, y: float}  $point
     * @param  array{left: float, right: float, bottom: float, top: float}  $box
     */
    private function distanceToBox(array $point, array $box): float
    {
        $x = (float) $point['x'];
        $y = (float) $point['y'];
        $cx = ((float) $box['left'] + (float) $box['right']) / 2;
        $cy = ((float) $box['bottom'] + (float) $box['top']) / 2;

        return hypot($x - $cx, $y - $cy);
    }

    /**
     * @param  array{x: float, y: float, page: int, room_number?: string}  $anchor
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     * @param  array<string, mixed>  $page
     * @return list<array{left: float, right: float, bottom: float, top: float}>
     */
    private function otherBoxes(array $anchor, array $anchors, array $page): array
    {
        $boxes = [];
        foreach ($anchors as $other) {
            if ((int) $other['page'] !== (int) $anchor['page']) {
                continue;
            }
            $anchorKey = $anchor['room_key'] ?? (string) ($anchor['room_number'] ?? '');
            $otherKey = $other['room_key'] ?? (string) ($other['room_number'] ?? '');
            if ($otherKey === $anchorKey) {
                continue;
            }
            $box = $this->roomBox($other, $page, $anchors);
            if ($box !== null) {
                $boxes[] = $box;
            }
        }

        return $boxes;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $boundary
     * @return list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>
     */
    private function dimensionLines(array $page, array $boundary): array
    {
        $lines = array_merge($page['walls'] ?? [], $page['ticks'] ?? [], $boundary['main_walls'] ?? []);

        return array_values($lines);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function pageMin(array $page): float
    {
        $width = (float) ($page['width'] ?? 0);
        $height = (float) ($page['height'] ?? 0);
        if ($width < 8 || $height < 8) {
            foreach ($page['walls'] ?? [] as $wall) {
                $width = max($width, (float) ($wall['x1'] ?? 0), (float) ($wall['x2'] ?? 0));
                $height = max($height, (float) ($wall['y1'] ?? 0), (float) ($wall['y2'] ?? 0));
            }
        }

        return max(1.0, min(max($width, 1.0), max($height, 1.0)));
    }

    /**
     * @param  array{left: ?float, right: ?float, top: ?float, bottom: ?float}  $sides
     * @param  array<string, mixed>|null  $horizontal
     * @param  array<string, mixed>|null  $vertical
     * @return array{left: ?float, right: ?float, top: ?float, bottom: ?float}
     */
    private function applyChainEnds(array $sides, ?array $horizontal, ?array $vertical): array
    {
        if (is_array($horizontal['overlay'] ?? null)) {
            $start = min((float) $horizontal['overlay']['x1'], (float) $horizontal['overlay']['x2']);
            $end = max((float) $horizontal['overlay']['x1'], (float) $horizontal['overlay']['x2']);
            $sides['left'] = $sides['left'] ?? $start;
            $sides['right'] = $sides['right'] ?? $end;
        }
        if (is_array($vertical['overlay'] ?? null)) {
            $start = min((float) $vertical['overlay']['y1'], (float) $vertical['overlay']['y2']);
            $end = max((float) $vertical['overlay']['y1'], (float) $vertical['overlay']['y2']);
            $sides['bottom'] = $sides['bottom'] ?? $start;
            $sides['top'] = $sides['top'] ?? $end;
        }

        return $sides;
    }

    /**
     * @param  array{left: ?float, right: ?float, top: ?float, bottom: ?float}  $sides
     * @return array{left: float, right: float, bottom: float, top: float, width: float, height: float}|null
     */
    private function boxFromSides(array $sides): ?array
    {
        if ($sides['left'] === null || $sides['right'] === null || $sides['top'] === null || $sides['bottom'] === null) {
            return null;
        }
        $left = min($sides['left'], $sides['right']);
        $right = max($sides['left'], $sides['right']);
        $bottom = min($sides['bottom'], $sides['top']);
        $top = max($sides['bottom'], $sides['top']);
        $width = $right - $left;
        $height = $top - $bottom;
        if ($width < 12 || $height < 12) {
            return null;
        }

        return [
            'left' => $left,
            'right' => $right,
            'bottom' => $bottom,
            'top' => $top,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @param  array{left: float, right: float, bottom: float, top: float}  $box
     * @return list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>
     */
    private function boxEdgeWalls(array $box): array
    {
        return [
            [
                'x1' => (float) $box['left'],
                'y1' => (float) $box['bottom'],
                'x2' => (float) $box['right'],
                'y2' => (float) $box['bottom'],
                'axis' => 'h',
            ],
            [
                'x1' => (float) $box['left'],
                'y1' => (float) $box['top'],
                'x2' => (float) $box['right'],
                'y2' => (float) $box['top'],
                'axis' => 'h',
            ],
            [
                'x1' => (float) $box['left'],
                'y1' => (float) $box['bottom'],
                'x2' => (float) $box['left'],
                'y2' => (float) $box['top'],
                'axis' => 'v',
            ],
            [
                'x1' => (float) $box['right'],
                'y1' => (float) $box['bottom'],
                'x2' => (float) $box['right'],
                'y2' => (float) $box['top'],
                'axis' => 'v',
            ],
        ];
    }

    /**
     * @param  array{x1: float, y1: float, x2: float, y2: float, axis: string}  $wall
     */
    private function wallLabel(array $wall, string $role): string
    {
        return $role.' ('.round((float) $wall['x1']).','.round((float) $wall['y1'])
            .'–'.round((float) $wall['x2']).','.round((float) $wall['y2']).')';
    }

    /**
     * @param  array{x1: float, y1: float, x2: float, y2: float}  $wall
     */
    private function wallLength(array $wall): float
    {
        return hypot((float) $wall['x2'] - (float) $wall['x1'], (float) $wall['y2'] - (float) $wall['y1']);
    }

    /**
     * @param  array{x: float, y: float}  $point
     * @param  array{x1: float, y1: float, x2: float, y2: float, axis: string}  $wall
     */
    private function distanceToWall(array $point, array $wall): float
    {
        $x = (float) $point['x'];
        $y = (float) $point['y'];
        $x1 = (float) $wall['x1'];
        $y1 = (float) $wall['y1'];
        $x2 = (float) $wall['x2'];
        $y2 = (float) $wall['y2'];
        if (($wall['axis'] ?? '') === 'h') {
            if ($x < min($x1, $x2) - 14 || $x > max($x1, $x2) + 14) {
                return 999;
            }

            return abs($y - $y1);
        }
        if ($y < min($y1, $y2) - 14 || $y > max($y1, $y2) + 14) {
            return 999;
        }

        return abs($x - $x1);
    }
}
