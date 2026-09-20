<?php

namespace App\Services\AreaWithoutM2Trial;

/**
 * Rebuild a rectangular room from H/V wall fragments. Door-sized gaps may be closed.
 *
 * All geometry is PDF-up: origin bottom-left, larger Y is visually higher (same as OCR).
 */
class RoomBoundaryReconstructor
{
    /**
     * @var array<string, array{h: list<array<string, mixed>>, v: list<array<string, mixed>>, gaps: list<string>, extract: array<string, mixed>}>
     */
    private array $clusterCache = [];

    public function __construct(private WallAxisAssembler $assembler = new WallAxisAssembler) {}

    /**
     * @param  array{x: float, y: float, page?: int, room_key?: string, room_number?: string}  $anchor
     * @param  array<string, mixed>  $page
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string, text?: string}>  $otherAnchors
     * @return array{
     *     box: ?array{left: float, right: float, bottom: float, top: float, width: float, height: float},
     *     left: ?string,
     *     right: ?string,
     *     top: ?string,
     *     bottom: ?string,
     *     closed_gaps: list<string>,
     *     reason: ?string,
     *     main_walls: list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>,
     *     left_pos: ?float,
     *     right_pos: ?float,
     *     top_pos: ?float,
     *     bottom_pos: ?float
     * }
     */
    public function reconstruct(array $anchor, array $page, array $otherAnchors = []): array
    {
        $empty = [
            'box' => null,
            'left' => null,
            'right' => null,
            'top' => null,
            'bottom' => null,
            'closed_gaps' => [],
            'reason' => null,
            'main_walls' => [],
            'left_pos' => null,
            'right_pos' => null,
            'top_pos' => null,
            'bottom_pos' => null,
            'wall_debug' => [
                'vertical' => [],
                'horizontal' => [],
                'tested_pair' => ['vertical' => 'geen', 'horizontal' => 'geen'],
                'bands_h' => [],
                'bands_v' => [],
                'axes' => [],
            ],
        ];

        $fill = $this->containingFill($anchor, $page['fills'] ?? []);
        if ($fill !== null) {
            $box = $this->toBox(
                (float) $fill['x'],
                (float) $fill['y'],
                (float) $fill['x'] + (float) $fill['width'],
                (float) $fill['y'] + (float) $fill['height'],
            );
            if ($box === null) {
                $empty['reason'] = 'geen gesloten ruimtecontour; maat niet geometrisch te koppelen';

                return $empty;
            }

            return [
                'box' => $box,
                'left' => 'x='.round($box['left']).' (vulcontour)',
                'right' => 'x='.round($box['right']).' (vulcontour)',
                'top' => 'y='.round($box['top']).' (vulcontour)',
                'bottom' => 'y='.round($box['bottom']).' (vulcontour)',
                'closed_gaps' => [],
                'reason' => null,
                'main_walls' => [],
                'left_pos' => $box['left'],
                'right_pos' => $box['right'],
                'top_pos' => $box['top'],
                'bottom_pos' => $box['bottom'],
            ];
        }

        $pageWidth = (float) ($page['width'] ?? 0);
        $pageHeight = (float) ($page['height'] ?? 0);
        if ($pageWidth < 8 || $pageHeight < 8) {
            foreach ($page['walls'] ?? [] as $wall) {
                $pageWidth = max($pageWidth, (float) ($wall['x1'] ?? 0), (float) ($wall['x2'] ?? 0));
                $pageHeight = max($pageHeight, (float) ($wall['y1'] ?? 0), (float) ($wall['y2'] ?? 0));
            }
        }
        $pageWidth = max(1.0, $pageWidth);
        $pageHeight = max(1.0, $pageHeight);
        $pageMin = min($pageWidth, $pageHeight);
        $doorGap = max(56.0, min(200.0, $pageMin * 0.16));
        $minMain = max(32.0, $pageMin * 0.05);
        $minPick = max($minMain, $pageMin * 0.07);
        $axisTol = max(6.0, $pageMin * 0.006);
        $coverSlack = max(8.0, $pageMin * 0.008);
        $minRoom = max(36.0, $pageMin * 0.04);
        $thickness = max(10.0, $pageMin * 0.012);
        $nameBand = max(48.0, $pageMin * 0.08);
        $clustered = $this->clusteredFor($page, $doorGap, $axisTol, $minMain);

        $x = (float) $anchor['x'];
        $y = (float) $anchor['y'];
        [$left, $right] = $this->resolvePair(
            $clustered['v'],
            $x,
            $y,
            true,
            $anchor,
            $otherAnchors,
            $minPick,
            $coverSlack,
            $pageWidth * 0.55,
            $minRoom,
            $thickness,
            $nameBand,
            $pageHeight,
        );
        $roomWidthWindow = ($left !== null && $right !== null)
            ? ['lo' => (float) $left['pos'], 'hi' => (float) $right['pos']]
            : null;
        [$bottom, $top] = $this->resolvePair(
            $clustered['h'],
            $y,
            $x,
            false,
            $anchor,
            $otherAnchors,
            $minPick,
            $coverSlack,
            $pageHeight * 0.55,
            $minRoom,
            $thickness,
            $nameBand,
            $pageWidth,
            $roomWidthWindow,
        );

        $gaps = [];
        foreach ([$left, $right, $bottom, $top] as $side) {
            if (! is_array($side)) {
                continue;
            }
            foreach ($side['gaps'] ?? [] as $gap) {
                $gaps[] = $gap;
            }
        }

        $found = [
            'box' => null,
            'left' => $left === null ? null : $this->sideLabel('linkerwand', $left['wall']),
            'right' => $right === null ? null : $this->sideLabel('rechterwand', $right['wall']),
            'top' => $top === null ? null : $this->sideLabel('bovenwand', $top['wall']),
            'bottom' => $bottom === null ? null : $this->sideLabel('onderwand', $bottom['wall']),
            'closed_gaps' => array_values(array_unique(array_merge($clustered['gaps'], $gaps))),
            'reason' => null,
            'main_walls' => $this->toAxisWalls($clustered),
            'left_pos' => $left['pos'] ?? null,
            'right_pos' => $right['pos'] ?? null,
            'top_pos' => $top['pos'] ?? null,
            'bottom_pos' => $bottom['pos'] ?? null,
            'wall_debug' => $this->wallDebug(
                $clustered,
                $page,
                $x,
                $y,
                $anchor,
                $otherAnchors,
                $minPick,
                $thickness,
                $nameBand,
                $pageWidth,
                $pageHeight,
                $left,
                $right,
                $bottom,
                $top,
            ),
        ];

        if ($left === null || $right === null || $bottom === null || $top === null) {
            $found['reason'] = 'geen gesloten ruimtecontour; maatketting kan nog wel gekoppeld worden';

            return $found;
        }

        $box = $this->toBox($left['pos'], $bottom['pos'], $right['pos'], $top['pos']);
        if ($box === null) {
            $found['reason'] = 'geen gesloten ruimtecontour; maatketting kan nog wel gekoppeld worden';

            return $found;
        }

        if ($box['width'] > $pageWidth * 0.72 && $box['height'] > $pageHeight * 0.72) {
            $found['reason'] = 'contour is te groot (waarschijnlijk het hele gebouw); niet betrouwbaar als kamer';

            return $found;
        }

        if ($this->containsOtherAnchor($box, $anchor, $otherAnchors)) {
            $found['reason'] = 'contour bevat een andere ruimtenaam; kamers niet samengevoegd';

            return $found;
        }

        $found['box'] = $box;
        $found['reason'] = null;

        return $found;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{h: list<array<string, mixed>>, v: list<array<string, mixed>>, gaps: list<string>, extract: array<string, mixed>}
     */
    private function clusteredFor(array $page, float $doorGap, float $axisTol, float $minMain): array
    {
        $walls = array_merge($page['walls'] ?? [], $page['ticks'] ?? []);
        $pageWidth = (float) ($page['width'] ?? 0);
        $pageHeight = (float) ($page['height'] ?? 0);
        $key = ((int) ($page['page'] ?? 1)).'|'.count($walls).'|'
            .round($pageWidth, 1).'|'.round($pageHeight, 1);
        if (! isset($this->clusterCache[$key])) {
            $clustered = $this->clusterWalls($walls, $doorGap, $axisTol, $minMain, $pageWidth, $pageHeight);
            if (is_array($page['wall_extract'] ?? null)) {
                $clustered['extract'] = [
                    'bands_h' => $page['wall_extract']['bands_h'] ?? $clustered['extract']['bands_h'],
                    'bands_v' => $page['wall_extract']['bands_v'] ?? $clustered['extract']['bands_v'],
                    'axes' => $page['wall_extract']['axes'] ?? $clustered['extract']['axes'],
                ];
            }
            $this->clusterCache[$key] = $clustered;
        }

        return $this->clusterCache[$key];
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return array{h: list<array<string, mixed>>, v: list<array<string, mixed>>, gaps: list<string>, extract: array<string, mixed>}
     */
    public function clusterWalls(array $walls, float $doorGap, float $axisTol, float $minMain, float $pageWidth = 0.0, float $pageHeight = 0.0): array
    {
        if ($pageWidth < 8 || $pageHeight < 8) {
            foreach ($walls as $wall) {
                $pageWidth = max($pageWidth, (float) ($wall['x1'] ?? 0), (float) ($wall['x2'] ?? 0));
                $pageHeight = max($pageHeight, (float) ($wall['y1'] ?? 0), (float) ($wall['y2'] ?? 0));
            }
        }
        $assembled = $this->assembler->assemble($walls, max(1.0, $pageWidth), max(1.0, $pageHeight));
        $horizontal = [];
        $vertical = [];
        foreach ($assembled['walls'] as $wall) {
            $length = $this->length($wall);
            if ($length < 16) {
                continue;
            }
            if (($wall['axis'] ?? '') === 'h') {
                $horizontal[] = [
                    'x1' => min((float) $wall['x1'], (float) $wall['x2']),
                    'x2' => max((float) $wall['x1'], (float) $wall['x2']),
                    'y' => ((float) $wall['y1'] + (float) $wall['y2']) / 2,
                    'gaps' => [],
                    'kind' => $wall['kind'] ?? WallAxisAssembler::KIND_LINE,
                    'role' => $wall['role'] ?? null,
                    'thickness' => $wall['thickness'] ?? null,
                ];
            }
            if (($wall['axis'] ?? '') === 'v') {
                $vertical[] = [
                    'y1' => min((float) $wall['y1'], (float) $wall['y2']),
                    'y2' => max((float) $wall['y1'], (float) $wall['y2']),
                    'x' => ((float) $wall['x1'] + (float) $wall['x2']) / 2,
                    'gaps' => [],
                    'kind' => $wall['kind'] ?? WallAxisAssembler::KIND_LINE,
                    'role' => $wall['role'] ?? null,
                    'thickness' => $wall['thickness'] ?? null,
                ];
            }
        }

        $gaps = [];
        $h = $this->mergeCollinear($horizontal, 'h', $doorGap, $axisTol, $minMain, $gaps);
        $v = $this->mergeCollinear($vertical, 'v', $doorGap, $axisTol, $minMain, $gaps);

        return [
            'h' => $h,
            'v' => $v,
            'gaps' => $gaps,
            'extract' => [
                'bands_h' => $assembled['bands_h'],
                'bands_v' => $assembled['bands_v'],
                'axes' => $assembled['axes'],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @param  list<string>  $gaps
     * @return list<array<string, mixed>>
     */
    private function mergeCollinear(array $segments, string $axis, float $doorGap, float $axisTol, float $minMain, array &$gaps): array
    {
        if ($segments === []) {
            return [];
        }

        usort($segments, function (array $a, array $b) use ($axis): int {
            if ($axis === 'h') {
                $y = $a['y'] <=> $b['y'];

                return $y !== 0 ? $y : $a['x1'] <=> $b['x1'];
            }
            $x = $a['x'] <=> $b['x'];

            return $x !== 0 ? $x : $a['y1'] <=> $b['y1'];
        });

        $merged = [];
        foreach ($segments as $segment) {
            $attached = false;
            foreach ($merged as &$existing) {
                if ($axis === 'h') {
                    if (abs((float) $existing['y'] - (float) $segment['y']) > $axisTol) {
                        continue;
                    }
                    $gap = (float) $segment['x1'] - (float) $existing['x2'];
                    $overlap = (float) $segment['x1'] <= (float) $existing['x2'] + 2
                        && (float) $segment['x2'] >= (float) $existing['x1'] - 2;
                    if (! $overlap && ($gap < 0 || $gap > $doorGap)) {
                        continue;
                    }
                    if ($gap > 8 && $gap <= $doorGap) {
                        $label = 'deuropening '.round($gap).' px op y='.round((float) $existing['y']);
                        $existing['gaps'][] = $label;
                        $gaps[] = $label;
                    }
                    $existing['x1'] = min((float) $existing['x1'], (float) $segment['x1']);
                    $existing['x2'] = max((float) $existing['x2'], (float) $segment['x2']);
                    $this->mergeWallMeta($existing, $segment);
                    $attached = true;
                    break;
                }
                if (abs((float) $existing['x'] - (float) $segment['x']) > $axisTol) {
                    continue;
                }
                $gap = (float) $segment['y1'] - (float) $existing['y2'];
                $overlap = (float) $segment['y1'] <= (float) $existing['y2'] + 2
                    && (float) $segment['y2'] >= (float) $existing['y1'] - 2;
                if (! $overlap && ($gap < 0 || $gap > $doorGap)) {
                    continue;
                }
                if ($gap > 8 && $gap <= $doorGap) {
                    $label = 'deuropening '.round($gap).' px op x='.round((float) $existing['x']);
                    $existing['gaps'][] = $label;
                    $gaps[] = $label;
                }
                $existing['y1'] = min((float) $existing['y1'], (float) $segment['y1']);
                $existing['y2'] = max((float) $existing['y2'], (float) $segment['y2']);
                $this->mergeWallMeta($existing, $segment);
                $attached = true;
                break;
            }
            unset($existing);
            if (! $attached) {
                $merged[] = $segment;
            }
        }

        return array_values(array_filter(
            $merged,
            function (array $wall) use ($axis, $minMain): bool {
                $length = $axis === 'h'
                    ? (float) $wall['x2'] - (float) $wall['x1']
                    : (float) $wall['y2'] - (float) $wall['y1'];

                return $length >= $minMain;
            },
        ));
    }

    /**
     * Pick opposite main walls from the room-name origin. Skip wall-thickness pairs and walls beyond another room.
     *
     * @param  list<array<string, mixed>>  $walls
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $others
     * @param  array{lo: float, hi: float}|null  $spanWindow
     * @return array{0: ?array{pos: float, wall: array<string, mixed>, gaps: list<string>, dist: float}, 1: ?array{pos: float, wall: array<string, mixed>, gaps: list<string>, dist: float}}
     */
    private function resolvePair(
        array $walls,
        float $origin,
        float $cover,
        bool $vertical,
        array $anchor,
        array $others,
        float $minLength,
        float $coverSlack,
        float $maxDist,
        float $minRoom,
        float $thickness,
        float $nameBand,
        float $pageSpan,
        ?array $spanWindow = null,
    ): array {
        $lowSide = $vertical ? 'left' : 'bottom';
        $highSide = $vertical ? 'right' : 'top';
        $low = $this->firstWall($walls, $origin, $cover, $lowSide, $vertical, $anchor, $others, $minLength, $coverSlack, $maxDist, [], $thickness, $nameBand, $pageSpan, $spanWindow);
        $high = $this->firstWall($walls, $origin, $cover, $highSide, $vertical, $anchor, $others, $minLength, $coverSlack, $maxDist, [], $thickness, $nameBand, $pageSpan, $spanWindow);
        if ($low === null || $high === null || ((float) $high['pos'] - (float) $low['pos']) >= $minRoom) {
            return [$low, $high];
        }

        $clusterLo = (float) $low['pos'];
        $clusterHi = (float) $high['pos'];
        $excluded = [$clusterLo, $clusterHi];
        $nextLow = $this->firstWall($walls, $origin, $cover, $lowSide, $vertical, $anchor, $others, $minLength, $coverSlack, $maxDist, $excluded, $thickness, $nameBand, $pageSpan, $spanWindow);
        $nextHigh = $this->firstWall($walls, $origin, $cover, $highSide, $vertical, $anchor, $others, $minLength, $coverSlack, $maxDist, $excluded, $thickness, $nameBand, $pageSpan, $spanWindow);

        $namesLow = 0;
        $namesHigh = 0;
        foreach ($others as $other) {
            if ((int) ($other['page'] ?? 1) !== (int) ($anchor['page'] ?? 1)) {
                continue;
            }
            $otherKey = $other['room_key'] ?? (string) ($other['room_number'] ?? '');
            $anchorKey = $anchor['room_key'] ?? (string) ($anchor['room_number'] ?? '');
            if ($otherKey !== '' && $otherKey === $anchorKey) {
                continue;
            }
            $along = $vertical ? (float) $other['x'] : (float) $other['y'];
            if ($along < $clusterLo - 4) {
                $namesLow++;
            }
            if ($along > $clusterHi + 4) {
                $namesHigh++;
            }
        }

        if ($namesHigh > $namesLow && $nextLow !== null) {
            return [$nextLow, $high];
        }
        if ($namesLow > $namesHigh && $nextHigh !== null) {
            return [$low, $nextHigh];
        }

        $lowGap = $nextLow !== null ? $clusterLo - (float) $nextLow['pos'] : 0.0;
        $highGap = $nextHigh !== null ? (float) $nextHigh['pos'] - $clusterHi : 0.0;
        if ($lowGap >= $minRoom && $lowGap >= $highGap && $nextLow !== null) {
            return [$nextLow, $high];
        }
        if ($highGap >= $minRoom && $nextHigh !== null) {
            return [$low, $nextHigh];
        }

        return [$nextLow, $nextHigh];
    }

    /**
     * First sufficiently long wall outward from the label that actually covers the label.
     *
     * @param  list<array<string, mixed>>  $walls
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $others
     * @param  list<float>  $excluded
     * @param  array{lo: float, hi: float}|null  $spanWindow
     * @return array{pos: float, wall: array<string, mixed>, gaps: list<string>, dist: float}|null
     */
    private function firstWall(
        array $walls,
        float $origin,
        float $cover,
        string $side,
        bool $vertical,
        array $anchor,
        array $others,
        float $minLength,
        float $coverSlack,
        float $maxDist,
        array $excluded,
        float $excludeTol,
        float $nameBand,
        float $pageSpan,
        ?array $spanWindow = null,
    ): ?array {
        $candidates = [];
        $window = $this->boundWindow($cover, $vertical, $anchor, $others, $nameBand, $pageSpan, $spanWindow);
        $minOverlap = $this->minBoundOverlap($window, $spanWindow !== null);
        foreach ($walls as $wall) {
            if ($vertical) {
                $pos = (float) $wall['x'];
                $spanStart = (float) $wall['y1'];
                $spanEnd = (float) $wall['y2'];
            } else {
                $pos = (float) $wall['y'];
                $spanStart = (float) $wall['x1'];
                $spanEnd = (float) $wall['x2'];
            }
            $length = $spanEnd - $spanStart;
            if ($length < $minLength) {
                continue;
            }
            $overlap = min($spanEnd, $window['hi']) - max($spanStart, $window['lo']);
            if ($overlap < $minOverlap) {
                continue;
            }
            $dist = abs($origin - $pos);
            if ($dist < 0.5 || $dist > $maxDist) {
                continue;
            }
            if (($side === 'left' || $side === 'bottom') && $pos > $origin - 0.5) {
                continue;
            }
            if (($side === 'right' || $side === 'top') && $pos < $origin + 0.5) {
                continue;
            }
            foreach ($excluded as $exclude) {
                if (abs($pos - $exclude) <= $excludeTol) {
                    continue 2;
                }
            }
            if ($this->otherNameBetween($origin, $pos, $vertical, $cover, $anchor, $others, $nameBand)) {
                continue;
            }
            if (($wall['role'] ?? '') === WallAxisAssembler::ROLE_DIMENSION) {
                continue;
            }
            $windowWidth = max(1.0, $window['hi'] - $window['lo']);
            $candidates[] = [
                'pos' => $pos,
                'dist' => $dist,
                'length' => $length,
                'coverage' => $overlap / $windowWidth,
                'wall' => $wall,
                'gaps' => $wall['gaps'] ?? [],
            ];
        }

        $hasLocal = false;
        foreach ($candidates as $candidate) {
            if ($candidate['length'] <= $pageSpan * 0.65) {
                $hasLocal = true;
                break;
            }
        }
        $usable = [];
        $maxLength = 0.0;
        foreach ($candidates as $candidate) {
            $keepLongRoomBound = $spanWindow !== null
                && ($candidate['coverage'] >= (1 / 3) || $this->isEnvelopeWall($candidate['wall']));
            if ($hasLocal && $candidate['length'] > $pageSpan * 0.65 && ! $keepLongRoomBound) {
                continue;
            }
            $usable[] = $candidate;
            $maxLength = max($maxLength, $candidate['length']);
        }

        $filtered = [];
        foreach ($usable as $candidate) {
            if ($spanWindow !== null) {
                $filtered[] = $candidate;

                continue;
            }
            $besideLongest = false;
            foreach ($usable as $other) {
                if ($other['length'] >= $maxLength * 0.9 && abs($candidate['pos'] - $other['pos']) <= $excludeTol) {
                    $besideLongest = true;
                    break;
                }
            }
            if ($candidate['length'] < max($minLength, $maxLength * 0.5) && ! $besideLongest) {
                continue;
            }
            $filtered[] = $candidate;
        }

        $best = null;
        foreach ($filtered as $candidate) {
            if ($best === null || $candidate['dist'] < $best['dist']) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'pos' => $best['pos'],
            'dist' => $best['dist'],
            'wall' => $best['wall'],
            'gaps' => $best['gaps'],
        ];
    }

    /**
     * Window along the wall axis: OCR neighbourhood plus stacked/side-by-side rooms that share this wall.
     *
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $others
     * @return array{lo: float, hi: float}
     */
    private function coverWindow(float $cover, bool $vertical, array $anchor, array $others, float $nameBand, float $pageSpan): array
    {
        $reach = max(36.0, $pageSpan * 0.12);
        $lo = $cover - $reach;
        $hi = $cover + $reach;
        $anchorAlong = $vertical ? (float) $anchor['x'] : (float) $anchor['y'];
        $alignTol = max($nameBand * 1.6, $pageSpan * 0.12);
        foreach ($others as $other) {
            if ((int) ($other['page'] ?? 1) !== (int) ($anchor['page'] ?? 1)) {
                continue;
            }
            $otherKey = $other['room_key'] ?? (string) ($other['room_number'] ?? '');
            $anchorKey = $anchor['room_key'] ?? (string) ($anchor['room_number'] ?? '');
            if ($otherKey !== '' && $otherKey === $anchorKey) {
                continue;
            }
            $otherAlong = $vertical ? (float) $other['x'] : (float) $other['y'];
            if (abs($otherAlong - $anchorAlong) > $alignTol) {
                continue;
            }
            $otherCover = $vertical ? (float) $other['y'] : (float) $other['x'];
            $lo = min($lo, $otherCover);
            $hi = max($hi, $otherCover);
        }

        return ['lo' => $lo, 'hi' => $hi];
    }

    /**
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $others
     * @param  array{lo: float, hi: float}|null  $spanWindow
     * @return array{lo: float, hi: float}
     */
    private function boundWindow(
        float $cover,
        bool $vertical,
        array $anchor,
        array $others,
        float $nameBand,
        float $pageSpan,
        ?array $spanWindow,
    ): array {
        if ($spanWindow === null) {
            return $this->coverWindow($cover, $vertical, $anchor, $others, $nameBand, $pageSpan);
        }

        return [
            'lo' => min($spanWindow['lo'], $spanWindow['hi']),
            'hi' => max($spanWindow['lo'], $spanWindow['hi']),
        ];
    }

    /**
     * @param  array{lo: float, hi: float}  $window
     */
    private function minBoundOverlap(array $window, bool $spanKnown): float
    {
        $width = $window['hi'] - $window['lo'];
        if ($spanKnown && $width >= 8) {
            return max(4.0, $width / 3);
        }

        return 4.0;
    }

    /**
     * @param  list<array<string, mixed>>  $walls
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $others
     * @param  array{pos: float, wall: array<string, mixed>}|null  $chosenLow
     * @param  array{pos: float, wall: array<string, mixed>}|null  $chosenHigh
     * @param  array{lo: float, hi: float}|null  $spanWindow
     * @return list<array{axis: string, pos: float, span: string, dist: float, decision: string}>
     */
    private function debugCandidates(
        array $walls,
        float $origin,
        float $cover,
        bool $vertical,
        array $anchor,
        array $others,
        float $minLength,
        float $maxDist,
        float $excludeTol,
        float $nameBand,
        float $pageSpan,
        ?array $chosenLow,
        ?array $chosenHigh,
        ?array $spanWindow = null,
    ): array {
        $lowSide = $vertical ? 'links' : 'onder';
        $highSide = $vertical ? 'rechts' : 'boven';
        $window = $this->boundWindow($cover, $vertical, $anchor, $others, $nameBand, $pageSpan, $spanWindow);
        $minOverlap = $this->minBoundOverlap($window, $spanWindow !== null);
        $rows = [];
        foreach ($walls as $wall) {
            if ($vertical) {
                $pos = (float) $wall['x'];
                $spanStart = (float) $wall['y1'];
                $spanEnd = (float) $wall['y2'];
                $span = 'x='.round($pos).' y='.round($spanStart).'–'.round($spanEnd);
            } else {
                $pos = (float) $wall['y'];
                $spanStart = (float) $wall['x1'];
                $spanEnd = (float) $wall['x2'];
                $span = 'y='.round($pos).' x='.round($spanStart).'–'.round($spanEnd);
            }
            $length = $spanEnd - $spanStart;
            $dist = abs($origin - $pos);
            $kind = (string) ($wall['kind'] ?? WallAxisAssembler::KIND_LINE);
            $role = (string) ($wall['role'] ?? '—');
            $envelope = $this->isEnvelopeWall($wall);
            $overlap = min($spanEnd, $window['hi']) - max($spanStart, $window['lo']);
            if (($wall['role'] ?? '') === WallAxisAssembler::ROLE_DIMENSION) {
                $decision = 'afgewezen: maatlijn buiten de gevel';
            } elseif ($length < $minLength) {
                $decision = 'afgewezen: te kort ('.round($length).' px)';
            } elseif ($dist < 0.5 || $dist > $maxDist) {
                $decision = 'afgewezen: afstand tot OCR '.round($dist).' px';
            } elseif ($overlap < $minOverlap) {
                $decision = 'afgewezen: onvoldoende overlap met kamerbereik ('.round(max(0.0, $overlap)).' px)';
            } elseif ($this->otherNameBetween($origin, $pos, $vertical, $cover, $anchor, $others, $nameBand)) {
                $decision = 'afgewezen: andere ruimtenaam ertussen';
            } elseif ($chosenLow !== null && abs($pos - (float) $chosenLow['pos']) <= $excludeTol) {
                $decision = 'geaccepteerd als '.$lowSide.'wand'
                    .($role !== '—' ? ' ('.$role.')' : '');
            } elseif ($chosenHigh !== null && abs($pos - (float) $chosenHigh['pos']) <= $excludeTol) {
                $decision = 'geaccepteerd als '.$highSide.'wand'
                    .($role !== '—' ? ' ('.$role.')' : '');
            } elseif (! $envelope && $length > $pageSpan * 0.65) {
                $decision = 'afgewezen: te lang als interne lijn ('.round($length).' px); buitenwand zou wel mogen';
            } else {
                $decision = 'kandidaat, niet het dichtstbijzijnde paar';
            }
            $rows[] = [
                'axis' => $vertical ? 'v' : 'h',
                'pos' => round($pos, 1),
                'span' => $span,
                'dist' => round($dist, 1),
                'kind' => $kind,
                'role' => $role,
                'decision' => $decision,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $a['dist'] <=> $b['dist']);
        $nearest = array_slice($rows, 0, 12);
        $envelopeRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => ($row['role'] ?? '') === WallAxisAssembler::ROLE_OUTER
                || in_array($row['kind'] ?? '', [WallAxisAssembler::KIND_BAND, WallAxisAssembler::KIND_PAIR, WallAxisAssembler::KIND_CLUSTER], true),
        ));
        $merged = [];
        foreach (array_merge($envelopeRows, $nearest) as $row) {
            $merged[$row['axis'].':'.$row['pos'].':'.$row['span']] = $row;
        }
        $combined = array_values($merged);
        usort($combined, fn (array $a, array $b): int => $a['dist'] <=> $b['dist']);

        return array_slice($combined, 0, 20);
    }

    /**
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $others
     */
    private function otherNameBetween(
        float $from,
        float $to,
        bool $alongX,
        float $perp,
        array $anchor,
        array $others,
        float $nameBand,
    ): bool {
        $lo = min($from, $to);
        $hi = max($from, $to);
        $anchorKey = $anchor['room_key'] ?? (string) ($anchor['room_number'] ?? '');
        foreach ($others as $other) {
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
            $value = $alongX ? (float) $other['x'] : (float) $other['y'];
            if ($value <= $lo + 2 || $value >= $hi - 2) {
                continue;
            }
            $otherPerp = $alongX ? (float) $other['y'] : (float) $other['x'];
            if (abs($otherPerp - $perp) > $nameBand) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array{left: float, right: float, bottom: float, top: float}  $box
     * @param  array{x: float, y: float, page?: int, room_key?: string, room_number?: string}  $anchor
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $others
     */
    private function containsOtherAnchor(array $box, array $anchor, array $others): bool
    {
        $anchorKey = $anchor['room_key'] ?? (string) ($anchor['room_number'] ?? '');
        $inset = 10.0;
        foreach ($others as $other) {
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
            $x = (float) $other['x'];
            $y = (float) $other['y'];
            if ($x > $box['left'] + $inset && $x < $box['right'] - $inset && $y > $box['bottom'] + $inset && $y < $box['top'] - $inset) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{x: float, y: float}  $anchor
     * @param  list<array<string, mixed>>  $fills
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function containingFill(array $anchor, array $fills): ?array
    {
        $x = (float) $anchor['x'];
        $y = (float) $anchor['y'];
        $inside = null;
        foreach ($fills as $fill) {
            $fx = (float) ($fill['x'] ?? 0);
            $fy = (float) ($fill['y'] ?? 0);
            $width = (float) ($fill['width'] ?? 0);
            $height = (float) ($fill['height'] ?? 0);
            if ($width < 12 || $height < 12) {
                continue;
            }
            if ($x < $fx - 4 || $x > $fx + $width + 4 || $y < $fy - 4 || $y > $fy + $height + 4) {
                continue;
            }
            if ($inside === null || $width * $height < ($inside['width'] * $inside['height'])) {
                $inside = ['x' => $fx, 'y' => $fy, 'width' => $width, 'height' => $height];
            }
        }

        return $inside;
    }

    /**
     * @return array{left: float, right: float, bottom: float, top: float, width: float, height: float}|null
     */
    private function toBox(float $left, float $bottom, float $right, float $top): ?array
    {
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
     * @param  array<string, mixed>  $wall
     */
    private function sideLabel(string $role, array $wall): string
    {
        if (isset($wall['x'], $wall['y1'], $wall['y2'])) {
            return $role.' x='.round((float) $wall['x']).' (y '.round((float) $wall['y1']).'–'.round((float) $wall['y2']).')'.$this->wallMetaSuffix($wall);
        }

        return $role.' y='.round((float) ($wall['y'] ?? 0)).' (x '.round((float) ($wall['x1'] ?? 0)).'–'.round((float) ($wall['x2'] ?? 0)).')'.$this->wallMetaSuffix($wall);
    }

    /**
     * @param  array<string, mixed>  $wall
     */
    private function wallMetaSuffix(array $wall): string
    {
        $parts = [];
        if (is_string($wall['role'] ?? null) && $wall['role'] !== '') {
            $parts[] = (string) $wall['role'];
        }
        if (is_string($wall['kind'] ?? null) && $wall['kind'] !== '' && $wall['kind'] !== WallAxisAssembler::KIND_LINE) {
            $parts[] = (string) $wall['kind'];
        }

        return $parts === [] ? '' : ' · '.implode(', ', $parts);
    }

    /**
     * @param  array{h: list<array<string, mixed>>, v: list<array<string, mixed>>, extract?: array<string, mixed>}  $clustered
     * @return list<array<string, mixed>>
     */
    private function toAxisWalls(array $clustered): array
    {
        $walls = [];
        foreach ($clustered['h'] as $wall) {
            $walls[] = [
                'x1' => (float) $wall['x1'],
                'y1' => (float) $wall['y'],
                'x2' => (float) $wall['x2'],
                'y2' => (float) $wall['y'],
                'axis' => 'h',
                'kind' => $wall['kind'] ?? WallAxisAssembler::KIND_LINE,
                'role' => $wall['role'] ?? null,
            ];
        }
        foreach ($clustered['v'] as $wall) {
            $walls[] = [
                'x1' => (float) $wall['x'],
                'y1' => (float) $wall['y1'],
                'x2' => (float) $wall['x'],
                'y2' => (float) $wall['y2'],
                'axis' => 'v',
                'kind' => $wall['kind'] ?? WallAxisAssembler::KIND_LINE,
                'role' => $wall['role'] ?? null,
            ];
        }

        return $walls;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $segment
     */
    private function mergeWallMeta(array &$existing, array $segment): void
    {
        $existing['role'] = $this->strongerRole($existing['role'] ?? null, $segment['role'] ?? null);
        $existing['kind'] = $this->strongerKind(
            (string) ($existing['kind'] ?? WallAxisAssembler::KIND_LINE),
            (string) ($segment['kind'] ?? WallAxisAssembler::KIND_LINE),
        );
        $existing['thickness'] = max((float) ($existing['thickness'] ?? 0), (float) ($segment['thickness'] ?? 0));
    }

    private function strongerRole(mixed $left, mixed $right): ?string
    {
        foreach ([WallAxisAssembler::ROLE_OUTER, WallAxisAssembler::ROLE_INTERNAL] as $role) {
            if ($left === $role || $right === $role) {
                return $role;
            }
        }

        return is_string($left) ? $left : (is_string($right) ? $right : null);
    }

    private function strongerKind(string $left, string $right): string
    {
        foreach ([WallAxisAssembler::KIND_BAND, WallAxisAssembler::KIND_CLUSTER, WallAxisAssembler::KIND_PAIR, WallAxisAssembler::KIND_LINE] as $kind) {
            if ($left === $kind || $right === $kind) {
                return $kind;
            }
        }

        return WallAxisAssembler::KIND_LINE;
    }

    /**
     * @param  array<string, mixed>  $wall
     */
    private function isEnvelopeWall(array $wall): bool
    {
        $role = (string) ($wall['role'] ?? '');
        $kind = (string) ($wall['kind'] ?? '');

        return $role === WallAxisAssembler::ROLE_OUTER
            || $kind === WallAxisAssembler::KIND_BAND
            || $kind === WallAxisAssembler::KIND_PAIR
            || $kind === WallAxisAssembler::KIND_CLUSTER;
    }

    /**
     * @param  array{h: list<array<string, mixed>>, v: list<array<string, mixed>>, extract?: array<string, mixed>}  $clustered
     * @param  array<string, mixed>  $page
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $others
     * @param  array{pos: float, wall: array<string, mixed>}|null  $left
     * @param  array{pos: float, wall: array<string, mixed>}|null  $right
     * @param  array{pos: float, wall: array<string, mixed>}|null  $bottom
     * @param  array{pos: float, wall: array<string, mixed>}|null  $top
     * @return array<string, mixed>
     */
    private function wallDebug(
        array $clustered,
        array $page,
        float $x,
        float $y,
        array $anchor,
        array $others,
        float $minPick,
        float $thickness,
        float $nameBand,
        float $pageWidth,
        float $pageHeight,
        ?array $left,
        ?array $right,
        ?array $bottom,
        ?array $top,
    ): array {
        $extract = is_array($clustered['extract'] ?? null) ? $clustered['extract'] : ['bands_h' => [], 'bands_v' => [], 'axes' => []];
        if (is_array($page['wall_extract'] ?? null)) {
            $extract = [
                'bands_h' => $page['wall_extract']['bands_h'] ?? $extract['bands_h'],
                'bands_v' => $page['wall_extract']['bands_v'] ?? $extract['bands_v'],
                'axes' => $page['wall_extract']['axes'] ?? $extract['axes'],
            ];
        }

        return [
            'vertical' => $this->debugCandidates(
                $clustered['v'],
                $x,
                $y,
                true,
                $anchor,
                $others,
                $minPick,
                $pageWidth * 0.55,
                $thickness,
                $nameBand,
                $pageHeight,
                $left,
                $right,
            ),
            'horizontal' => $this->debugCandidates(
                $clustered['h'],
                $y,
                $x,
                false,
                $anchor,
                $others,
                $minPick,
                $pageHeight * 0.55,
                $thickness,
                $nameBand,
                $pageWidth,
                $bottom,
                $top,
                ($left !== null && $right !== null)
                    ? ['lo' => (float) $left['pos'], 'hi' => (float) $right['pos']]
                    : null,
            ),
            'tested_pair' => [
                'vertical' => $left === null || $right === null
                    ? 'geen compleet linker-/rechterpaar'
                    : 'x='.round((float) $left['pos']).'–'.round((float) $right['pos']),
                'horizontal' => $bottom === null || $top === null
                    ? 'geen compleet onder-/bovenpaar'
                    : 'y='.round((float) $bottom['pos']).'–'.round((float) $top['pos']),
            ],
            'bands_h' => $this->debugExtractList($extract['bands_h'] ?? []),
            'bands_v' => $this->debugExtractList($extract['bands_v'] ?? []),
            'axes' => $this->debugExtractList($extract['axes'] ?? []),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function debugExtractList(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $axis = (string) ($item['axis'] ?? '');
            $kind = (string) ($item['kind'] ?? WallAxisAssembler::KIND_LINE);
            $role = (string) ($item['role'] ?? '—');
            if ($axis === 'v') {
                $lines[] = 'x='.round((float) ($item['x1'] ?? $item['x'] ?? 0))
                    .' y='.round((float) ($item['y1'] ?? 0)).'–'.round((float) ($item['y2'] ?? 0))
                    .' · '.$kind.' · '.$role;
            } else {
                $lines[] = 'y='.round((float) ($item['y1'] ?? $item['y'] ?? 0))
                    .' x='.round((float) ($item['x1'] ?? 0)).'–'.round((float) ($item['x2'] ?? 0))
                    .' · '.$kind.' · '.$role;
            }
        }

        return array_slice($lines, 0, 24);
    }

    /**
     * @param  array{x1: float, y1: float, x2: float, y2: float}  $wall
     */
    private function length(array $wall): float
    {
        return hypot((float) $wall['x2'] - (float) $wall['x1'], (float) $wall['y2'] - (float) $wall['y1']);
    }
}
