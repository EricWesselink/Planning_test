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
     * @var array<string, array{h: list<array<string, mixed>>, v: list<array<string, mixed>>, gaps: list<string>}>
     */
    private array $clusterCache = [];

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
            'wall_debug' => ['vertical' => [], 'horizontal' => [], 'tested_pair' => ['vertical' => 'geen', 'horizontal' => 'geen']],
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
            'wall_debug' => [
                'vertical' => $this->debugCandidates(
                    $clustered['v'],
                    $x,
                    $y,
                    true,
                    $anchor,
                    $otherAnchors,
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
                    $otherAnchors,
                    $minPick,
                    $pageHeight * 0.55,
                    $thickness,
                    $nameBand,
                    $pageWidth,
                    $bottom,
                    $top,
                ),
                'tested_pair' => [
                    'vertical' => $left === null || $right === null
                        ? 'geen compleet linker-/rechterpaar'
                        : 'x='.round((float) $left['pos']).'–'.round((float) $right['pos']),
                    'horizontal' => $bottom === null || $top === null
                        ? 'geen compleet onder-/bovenpaar'
                        : 'y='.round((float) $bottom['pos']).'–'.round((float) $top['pos']),
                ],
            ],
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
     * @return array{h: list<array<string, mixed>>, v: list<array<string, mixed>>, gaps: list<string>}
     */
    private function clusteredFor(array $page, float $doorGap, float $axisTol, float $minMain): array
    {
        $walls = array_merge($page['walls'] ?? [], $page['ticks'] ?? []);
        $key = ((int) ($page['page'] ?? 1)).'|'.count($walls).'|'
            .round((float) ($page['width'] ?? 0), 1).'|'.round((float) ($page['height'] ?? 0), 1);
        if (! isset($this->clusterCache[$key])) {
            $this->clusterCache[$key] = $this->clusterWalls($walls, $doorGap, $axisTol, $minMain);
        }

        return $this->clusterCache[$key];
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return array{h: list<array<string, mixed>>, v: list<array<string, mixed>>, gaps: list<string>}
     */
    public function clusterWalls(array $walls, float $doorGap, float $axisTol, float $minMain): array
    {
        $horizontal = [];
        $vertical = [];
        foreach ($walls as $wall) {
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
                ];
            }
            if (($wall['axis'] ?? '') === 'v') {
                $vertical[] = [
                    'y1' => min((float) $wall['y1'], (float) $wall['y2']),
                    'y2' => max((float) $wall['y1'], (float) $wall['y2']),
                    'x' => ((float) $wall['x1'] + (float) $wall['x2']) / 2,
                    'gaps' => [],
                ];
            }
        }

        $gaps = [];
        $h = $this->mergeCollinear($horizontal, 'h', $doorGap, $axisTol, $minMain, $gaps);
        $v = $this->mergeCollinear($vertical, 'v', $doorGap, $axisTol, $minMain, $gaps);

        return ['h' => $h, 'v' => $v, 'gaps' => $gaps];
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
    ): array {
        $lowSide = $vertical ? 'left' : 'bottom';
        $highSide = $vertical ? 'right' : 'top';
        $low = $this->firstWall($walls, $origin, $cover, $lowSide, $vertical, $anchor, $others, $minLength, $coverSlack, $maxDist, [], $thickness, $nameBand, $pageSpan);
        $high = $this->firstWall($walls, $origin, $cover, $highSide, $vertical, $anchor, $others, $minLength, $coverSlack, $maxDist, [], $thickness, $nameBand, $pageSpan);
        if ($low === null || $high === null || ((float) $high['pos'] - (float) $low['pos']) >= $minRoom) {
            return [$low, $high];
        }

        $clusterLo = (float) $low['pos'];
        $clusterHi = (float) $high['pos'];
        $excluded = [$clusterLo, $clusterHi];
        $nextLow = $this->firstWall($walls, $origin, $cover, $lowSide, $vertical, $anchor, $others, $minLength, $coverSlack, $maxDist, $excluded, $thickness, $nameBand, $pageSpan);
        $nextHigh = $this->firstWall($walls, $origin, $cover, $highSide, $vertical, $anchor, $others, $minLength, $coverSlack, $maxDist, $excluded, $thickness, $nameBand, $pageSpan);

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
    ): ?array {
        $candidates = [];
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
            $window = $this->coverWindow($cover, $vertical, $anchor, $others, $nameBand, $pageSpan);
            $overlap = min($spanEnd, $window['hi']) - max($spanStart, $window['lo']);
            if ($overlap < 4) {
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
            $candidates[] = [
                'pos' => $pos,
                'dist' => $dist,
                'length' => $length,
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
            if ($hasLocal && $candidate['length'] > $pageSpan * 0.65) {
                continue;
            }
            $usable[] = $candidate;
            $maxLength = max($maxLength, $candidate['length']);
        }

        $filtered = [];
        foreach ($usable as $candidate) {
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
     * @param  list<array<string, mixed>>  $walls
     * @param  list<array{x: float, y: float, page?: int, room_key?: string, room_number?: string}>  $others
     * @param  array{pos: float, wall: array<string, mixed>}|null  $chosenLow
     * @param  array{pos: float, wall: array<string, mixed>}|null  $chosenHigh
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
    ): array {
        $lowSide = $vertical ? 'links' : 'onder';
        $highSide = $vertical ? 'rechts' : 'boven';
        $window = $this->coverWindow($cover, $vertical, $anchor, $others, $nameBand, $pageSpan);
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
            $decision = 'afgewezen';
            if ($length < $minLength) {
                $decision = 'afgewezen: te kort ('.round($length).' px)';
            } elseif ($dist < 0.5 || $dist > $maxDist) {
                $decision = 'afgewezen: afstand tot OCR '.round($dist).' px';
            } elseif (min($spanEnd, $window['hi']) - max($spanStart, $window['lo']) < 4) {
                $decision = 'afgewezen: geen overlap met OCR-venster';
            } elseif ($this->otherNameBetween($origin, $pos, $vertical, $cover, $anchor, $others, $nameBand)) {
                $decision = 'afgewezen: andere ruimtenaam ertussen';
            } elseif ($chosenLow !== null && abs($pos - (float) $chosenLow['pos']) <= $excludeTol) {
                $decision = 'geaccepteerd als '.$lowSide.'wand';
            } elseif ($chosenHigh !== null && abs($pos - (float) $chosenHigh['pos']) <= $excludeTol) {
                $decision = 'geaccepteerd als '.$highSide.'wand';
            } else {
                $decision = 'kandidaat, niet het dichtstbijzijnde paar';
            }
            $rows[] = [
                'axis' => $vertical ? 'v' : 'h',
                'pos' => round($pos, 1),
                'span' => $span,
                'dist' => round($dist, 1),
                'decision' => $decision,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $a['dist'] <=> $b['dist']);

        return array_slice($rows, 0, 12);
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
            return $role.' x='.round((float) $wall['x']).' (y '.round((float) $wall['y1']).'–'.round((float) $wall['y2']).')';
        }

        return $role.' y='.round((float) ($wall['y'] ?? 0)).' (x '.round((float) ($wall['x1'] ?? 0)).'–'.round((float) ($wall['x2'] ?? 0)).')';
    }

    /**
     * @param  array{h: list<array<string, mixed>>, v: list<array<string, mixed>>}  $clustered
     * @return list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>
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
            ];
        }
        foreach ($clustered['v'] as $wall) {
            $walls[] = [
                'x1' => (float) $wall['x'],
                'y1' => (float) $wall['y1'],
                'x2' => (float) $wall['x'],
                'y2' => (float) $wall['y2'],
                'axis' => 'v',
            ];
        }

        return $walls;
    }

    /**
     * @param  array{x1: float, y1: float, x2: float, y2: float}  $wall
     */
    private function length(array $wall): float
    {
        return hypot((float) $wall['x2'] - (float) $wall['x1'], (float) $wall['y2'] - (float) $wall['y1']);
    }
}
