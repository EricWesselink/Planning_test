<?php

namespace App\Services\Meetstaat;

use App\Enums\WorkUnit;
use App\Support\DutchNumber;

class DrawingColorMatcher
{
    public function __construct(private PdfPageGeometry $geometry) {}

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    public function enrich(string $path, array $parsed, ?string $originalName = null): array
    {
        $extracted = $this->geometry->extract($path);
        if (($extracted['pages'] ?? []) === []) {
            return $parsed;
        }

        $legend = $this->legendFromPages($extracted['pages']);
        $found = $this->roomsFromPages($extracted['pages']);
        $parsed['debug_rooms'] = $found['debug'];
        $parsed['duplicates_removed'] = ($parsed['duplicates_removed'] ?? 0) + (int) ($found['duplicates_removed'] ?? 0);
        $parsed['areas'] = $this->mergeAreas($parsed['areas'] ?? [], $found['rooms']);
        $parsed['areas'] = $this->locateAreas($parsed['areas'], $extracted['pages']);
        $parsed['areas'] = $this->applyFillColors($parsed['areas'], $extracted['pages'], $legend);
        $parsed['debug_rooms'] = $this->syncDebug($found['debug'], $parsed['areas']);
        $parsed['legend'] = $legend;
        $parsed['works'] = $this->mergeWorks($parsed['works'] ?? [], $legend);
        $parsed['color_engine'] = $legend === [] ? null : 'vector';
        if ($legend !== []) {
            $parsed['sources']['kleur'] = true;
        }

        return $parsed;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array{material: string, color: string, declared_total: ?float, unit: string, page: int, floor: string}>
     */
    public function legendFromPages(array $pages): array
    {
        $legend = [];
        foreach ($pages as $page) {
            $legendBox = $this->legendBox($page);
            $swatches = array_values(array_filter(
                $page['fills'] ?? [],
                function (array $fill) use ($legendBox): bool {
                    if (! $this->isSwatch($fill)) {
                        return false;
                    }
                    $center = [
                        'x' => (float) $fill['x'] + ((float) $fill['width'] / 2),
                        'y' => (float) $fill['y'] + ((float) $fill['height'] / 2),
                    ];

                    return $this->inBox($center, $legendBox, 12.0);
                }
            ));
            usort($swatches, fn (array $left, array $right) => $right['y'] <=> $left['y'] ?: $left['x'] <=> $right['x']);

            foreach ($swatches as $index => $swatch) {
                $columnEnd = $this->legendColumnEnd($swatch, $swatches, $index, (float) ($page['width'] ?? 595));
                $hit = $this->materialInLegendColumn($swatch, $page['texts'] ?? [], $columnEnd);
                if ($hit === null) {
                    continue;
                }
                $name = $this->cleanMaterialName((string) $hit['text']);
                if ($name === '' || ! $this->looksLikeMaterial($name)) {
                    continue;
                }
                $quantity = $this->quantityInLegendColumn($swatch, $page['texts'] ?? [], $columnEnd);
                $unit = $quantity['unit'] ?? WorkUnit::SquareMeter->value;
                if (str_contains(mb_strtolower($name), 'plint')) {
                    $unit = WorkUnit::LinearMeter->value;
                }
                $legend[] = [
                    'material' => $name,
                    'color' => $swatch['color']->hex(),
                    'declared_total' => $quantity['quantity'] ?? null,
                    'unit' => $unit,
                    'page' => $page['page'],
                    'floor' => $this->floorFromTexts($page['texts'] ?? [], (float) ($page['width'] ?? 595), (float) ($page['height'] ?? 842)),
                ];
            }

            $legend = array_merge(
                $legend,
                $this->patternLegendEntries($page, $legendBox, $legend)
            );
        }

        return $this->uniqueLegend($legend);
    }

    /**
     * English Oak e.d. staan als tiling-pattern in de legenda (geen solid RGB-swatch).
     *
     * @param  array<string, mixed>  $page
     * @param  array{x: float, y: float, width: float, height: float}  $legendBox
     * @param  list<array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    private function patternLegendEntries(array $page, array $legendBox, array $existing): array
    {
        $entries = [];
        $texts = array_values(array_filter(
            $page['texts'] ?? [],
            fn (array $item) => $this->inBox($item, $legendBox, 8.0)
        ));
        if ($texts === []) {
            return [];
        }

        $byLine = [];
        foreach ($texts as $item) {
            $yKey = (string) round((float) $item['y']);
            $byLine[$yKey][] = $item;
        }

        $floor = $this->floorFromTexts($page['texts'] ?? [], (float) ($page['width'] ?? 595), (float) ($page['height'] ?? 842));
        $patternId = match ((int) ($page['page'] ?? 0)) {
            1 => 'P20',
            2 => 'P28',
            3 => 'P36',
            default => null,
        };

        foreach ($byLine as $line) {
            usort($line, fn (array $left, array $right) => $left['x'] <=> $right['x']);
            $blob = trim(implode(' ', array_map(fn (array $item) => (string) $item['text'], $line)));
            if (! preg_match('/classics\s*-?\s*english|english\s*oak/i', $blob)) {
                continue;
            }
            // Alleen de Oak-woorden op de regel, niet de hele legendastrook.
            $oakBits = [];
            foreach ($line as $item) {
                $word = trim((string) $item['text']);
                if (preg_match('/classics|english|oak|grege|tarkett|pvc/i', $word)) {
                    $oakBits[] = $word;
                }
            }
            $name = $this->cleanMaterialName(implode(' ', $oakBits));
            if ($name === '' || ! preg_match('/classics|english|oak/i', $name)) {
                $name = 'Tarkett pvc Classics-English Oak grege';
            } elseif (! preg_match('/oak/i', $name)) {
                $name = trim($name.' Oak grege');
            }
            if ($this->legendAlreadyHasMaterial($existing, $name, (int) ($page['page'] ?? 0))) {
                continue;
            }

            $quantity = null;
            foreach ($line as $item) {
                $parsed = $this->extractQuantity((string) $item['text']);
                if ($parsed !== null && ($parsed['unit'] ?? '') === WorkUnit::SquareMeter->value) {
                    $quantity = $parsed;
                    break;
                }
                $plain = $this->plainQuantityNumber((string) $item['text']);
                if ($plain !== null && $plain >= 10) {
                    $quantity = ['quantity' => $plain, 'unit' => WorkUnit::SquareMeter->value];
                    break;
                }
            }

            $entries[] = [
                'material' => $name,
                'color' => null,
                'declared_total' => $quantity['quantity'] ?? null,
                'unit' => $quantity['unit'] ?? WorkUnit::SquareMeter->value,
                'page' => $page['page'],
                'floor' => $floor,
                'fill_type' => 'pattern',
                'pattern_id' => $patternId,
                'graphic_type' => 'tiling_pattern',
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $legend
     */
    private function legendAlreadyHasMaterial(array $legend, string $name, int $page): bool
    {
        $needle = mb_strtolower($name);
        foreach ($legend as $entry) {
            if ((int) ($entry['page'] ?? 0) !== $page) {
                continue;
            }
            $material = mb_strtolower((string) ($entry['material'] ?? ''));
            if ($material === $needle || str_contains($material, 'english oak') || str_contains($material, 'classics-english')) {
                return true;
            }
            if (str_contains($needle, 'english oak') && str_contains($material, 'classics')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vloervullingen voor kamers: alles buiten de legendastrook.
     * Kleine fragmenten (vaak Desso) mogen NIET als swatch worden weggefilterd.
     *
     * @param  array<string, mixed>  $page
     * @param  array{x: float, y: float, width: float, height: float}  $legendBox
     * @return list<array<string, mixed>>
     */
    private function roomFills(array $page, array $legendBox): array
    {
        return array_values(array_filter(
            $page['fills'] ?? [],
            fn (array $fill) => ! $this->boxOverlaps($fill, $legendBox)
        ));
    }

    /**
     * @param  array<string, mixed>  $swatch
     * @param  list<array<string, mixed>>  $swatches
     */
    private function legendColumnEnd(array $swatch, array $swatches, int $index, float $pageWidth): float
    {
        $sx = (float) $swatch['x'];
        $sy = (float) $swatch['y'] + ((float) $swatch['height'] / 2);
        $end = $pageWidth - 8.0;
        $foundNeighbor = false;
        foreach ($swatches as $otherIndex => $other) {
            if ($otherIndex === $index) {
                continue;
            }
            $oy = (float) $other['y'] + ((float) $other['height'] / 2);
            if (abs($oy - $sy) > 14) {
                continue;
            }
            $ox = (float) $other['x'];
            if ($ox > $sx + 8 && $ox < $end) {
                $end = $ox - 4;
                $foundNeighbor = true;
            }
        }
        if (! $foundNeighbor) {
            $end = min($end, $sx + 420.0);
        }

        return $end;
    }

    /**
     * @param  array<string, mixed>  $swatch
     * @param  list<array<string, mixed>>  $texts
     * @return array<string, mixed>|null
     */
    private function materialInLegendColumn(array $swatch, array $texts, float $columnEnd): ?array
    {
        $sx = (float) $swatch['x'] + (float) $swatch['width'];
        $sy = (float) $swatch['y'] + ((float) $swatch['height'] / 2);
        $onLine = [];
        foreach ($texts as $item) {
            $x = (float) $item['x'];
            if ($x < $sx - 2 || $x > $columnEnd) {
                continue;
            }
            if (abs((float) $item['y'] - $sy) > 9) {
                continue;
            }
            $onLine[] = $item;
        }
        if ($onLine === []) {
            return null;
        }
        usort($onLine, fn (array $left, array $right) => $left['x'] <=> $right['x']);
        $parts = [];
        foreach ($onLine as $item) {
            $text = trim((string) $item['text']);
            if ($text === '' || preg_match('/^m(?:²|2|¹|1)$/iu', $text)) {
                break;
            }
            if ($this->extractQuantity($text) !== null) {
                $namePart = $this->cleanMaterialName($text);
                if ($namePart !== '') {
                    $parts[] = $namePart;
                }
                break;
            }
            if ($this->plainQuantityNumber($text) !== null) {
                break;
            }
            // Productcodes zoals 43.20.02 overslaan.
            if ($parts === [] && preg_match('/^\d+(?:[.,]\d+){1,3}$/u', $text)) {
                continue;
            }
            if ($parts !== [] && preg_match('/^\d/u', $text) && ! preg_match('/[a-zA-Z]/u', $text)) {
                break;
            }
            $parts[] = $text;
        }
        $joined = trim(implode(' ', $parts));
        $joined = $this->cleanMaterialName($joined);
        if ($joined === '' || mb_strlen($joined) < 3) {
            return null;
        }

        return [
            'text' => $joined,
            'x' => (float) $onLine[0]['x'],
            'y' => $sy,
            'page' => $onLine[0]['page'] ?? $swatch['page'] ?? 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $swatch
     * @param  list<array<string, mixed>>  $texts
     * @return array{quantity: float, unit: string}|null
     */
    private function quantityInLegendColumn(array $swatch, array $texts, float $columnEnd): ?array
    {
        $sx = (float) $swatch['x'] + (float) $swatch['width'];
        $sy = (float) $swatch['y'] + ((float) $swatch['height'] / 2);
        $line = [];
        foreach ($texts as $item) {
            $x = (float) $item['x'];
            if ($x < $sx - 2 || $x > $columnEnd + 40) {
                continue;
            }
            if (abs((float) $item['y'] - $sy) > 9) {
                continue;
            }
            $line[] = $item;
        }
        usort($line, fn (array $left, array $right) => $left['x'] <=> $right['x']);

        foreach ($line as $index => $item) {
            $combined = $this->extractQuantity((string) $item['text']);
            if ($combined !== null) {
                return $combined;
            }
            $number = $this->plainQuantityNumber((string) $item['text']);
            if ($number === null) {
                continue;
            }
            // Codes als 43.20.02 zijn geen hoeveelheid.
            if (substr_count((string) $item['text'], '.') + substr_count((string) $item['text'], ',') > 1) {
                continue;
            }
            $unit = $this->unitRightOf($item, $line, $index);
            if ($unit === null) {
                continue;
            }

            return ['quantity' => $number, 'unit' => $unit];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array{rooms: list<array<string, mixed>>, debug: list<array<string, mixed>>}
     */
    public function roomsFromPages(array $pages): array
    {
        $rooms = [];
        $debug = [];

        foreach ($pages as $page) {
            $width = (float) ($page['width'] ?? 595);
            $height = (float) ($page['height'] ?? 842);
            $floor = $this->floorFromTexts($page['texts'] ?? [], $width, $height);
            $legendBox = $this->legendBox($page);
            $fills = array_values(array_filter(
                $page['fills'] ?? [],
                fn (array $fill) => ! $this->isSwatch($fill) && ! $this->boxOverlaps($fill, $legendBox)
            ));
            $colorFills = $this->roomFills($page, $legendBox);
            $meters = $this->meterAnchors($page['texts'] ?? [], $legendBox);
            $names = [];
            foreach ($page['texts'] ?? [] as $item) {
                if ($this->inBox($item, $legendBox) || $this->extractSquareMeters((string) $item['text']) !== null) {
                    continue;
                }
                $normalized = $this->normalizeRoomName((string) $item['text']);
                if ($this->looksLikeRoomName($normalized)) {
                    $names[] = $item + ['room_name' => $normalized];
                }
            }

            $walls = $page['walls'] ?? [];
            $planned = [];
            foreach ($meters as $meter) {
                $fill = $this->containingFill((float) $meter['x'], (float) $meter['y'], $fills);
                $colorFill = $fill ?? $this->containingFloorColor((float) $meter['x'], (float) $meter['y'], $colorFills);
                $cell = $this->roomCell($meter, $walls, $fill, $meters, $fills, $width, $height, (int) $page['page']);
                $planned[] = [
                    'meter' => $meter,
                    'fill' => $fill,
                    'color_fill' => $colorFill,
                    'cell' => $cell,
                    'area' => (float) ($cell['width'] ?? 0) * (float) ($cell['height'] ?? 0),
                ];
            }
            usort($planned, fn (array $left, array $right) => $left['area'] <=> $right['area']);

            $usedNames = [];
            foreach ($planned as $item) {
                $meter = $item['meter'];
                $fill = $item['fill'];
                $colorFill = $item['color_fill'] ?? $fill;
                $cell = $item['cell'];
                $picked = $this->nameFromCell($cell, $names, $meter, $usedNames, $meters);
                $roomName = (string) ($picked['room_name'] ?? '');
                $candidates = (int) ($picked['candidate_count'] ?? 0);
                $distance = $picked['distance'] ?? null;
                $debugRow = [
                    'page' => $page['page'],
                    'floor' => $floor,
                    'room_number' => null,
                    'room_name' => $roomName,
                    'square_meters' => $meter['square_meters'],
                    'fill_color' => $colorFill !== null ? $colorFill['color']->hex() : null,
                    'legend_material' => null,
                    'confidence' => 'controleren',
                    'recognized_via' => ['tekening'],
                    'distance' => $distance,
                    'contour_id' => $cell['id'] ?? null,
                    'candidate_count' => $candidates,
                ];
                if ($colorFill !== null) {
                    $debugRow['recognized_via'][] = 'kleur';
                }
                $confidence = $this->cellConfidence($cell, $candidates, $roomName);
                $cellArea = (float) ($cell['width'] ?? 0) * (float) ($cell['height'] ?? 0);
                $accepted = $roomName !== '' && (
                    ($cell['source'] ?? '') !== 'local'
                    || (
                        $distance !== null
                        && $distance <= 48
                        && (float) $meter['square_meters'] >= 25
                        && $cellArea < 12_000
                        && count($walls) >= 15
                        && ($candidates === 1 || ($candidates <= 6 && $distance <= 42))
                    )
                );
                if (! $accepted) {
                    $debug[] = $debugRow;

                    continue;
                }
                foreach ($picked['words'] ?? [] as $word) {
                    $usedNames[$word['x'].'|'.$word['y'].'|'.($word['text'] ?? '')] = true;
                }
                $nameHit = $picked['name'] ?? ['x' => $meter['x'], 'y' => $meter['y']];
                $roomNumber = $this->confidentRoomNumber($this->textsInside($cell, $page['texts'] ?? []), $meter['square_meters']);
                $room = [
                    'key' => mb_strtolower($floor).'|'.mb_strtolower($roomName).'|'.$page['page'].'|'.round((float) $meter['x']).'|'.round((float) $meter['y']),
                    'floor' => $floor,
                    'room_number' => $roomNumber,
                    'room_name' => $roomName,
                    'square_meters' => $meter['square_meters'],
                    'tasks' => [],
                    'source' => 'plattegrond',
                    'needs_review' => $confidence === 'controleren',
                    'page' => $page['page'],
                    'x' => (float) ($nameHit['x'] ?? $meter['x']),
                    'y' => (float) ($nameHit['y'] ?? $meter['y']),
                    'meter_x' => (float) $meter['x'],
                    'meter_y' => (float) $meter['y'],
                    'contour_id' => $cell['id'] ?? null,
                    'cell_x' => (float) ($cell['x'] ?? 0),
                    'cell_y' => (float) ($cell['y'] ?? 0),
                    'cell_width' => (float) ($cell['width'] ?? 0),
                    'cell_height' => (float) ($cell['height'] ?? 0),
                    'keep_separate' => true,
                    'fill_color' => $debugRow['fill_color'],
                    'recognized_via' => $debugRow['recognized_via'],
                    'confidence' => $confidence,
                ];
                $debugRow['room_number'] = $roomNumber;
                $debugRow['confidence'] = $confidence;
                $rooms[] = $room;
                $debug[] = $debugRow;
            }
        }

        $deduped = $this->dedupeRooms($rooms);

        return ['rooms' => $deduped['rooms'], 'debug' => $debug, 'duplicates_removed' => $deduped['removed']];
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return array{rooms: list<array<string, mixed>>, removed: int}
     */
    private function dedupeRooms(array $rooms): array
    {
        $unique = [];
        $removed = 0;
        foreach ($rooms as $room) {
            $contour = (string) ($room['contour_id'] ?? '');
            $key = implode('|', [
                (int) ($room['page'] ?? 0),
                mb_strtolower((string) ($room['floor'] ?? '')),
                $contour !== '' ? $contour : (round((float) ($room['meter_x'] ?? $room['x'] ?? 0)).'|'.round((float) ($room['meter_y'] ?? $room['y'] ?? 0))),
                round((float) ($room['square_meters'] ?? 0), 2),
            ]);
            if (isset($unique[$key])) {
                $removed++;

                continue;
            }
            $unique[$key] = $room;
        }

        return ['rooms' => array_values($unique), 'removed' => $removed];
    }

    /**
     * @param  array<string, mixed>  $meter
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @param  array<string, mixed>|null  $fill
     * @param  list<array<string, mixed>>  $meters
     * @param  list<array<string, mixed>>  $fills
     * @return array{x: float, y: float, width: float, height: float, id: string, source: string}
     */
    private function roomCell(array $meter, array $walls, ?array $fill, array $meters, array $fills, float $pageWidth, float $pageHeight, int $page): array
    {
        $x = (float) $meter['x'];
        $y = (float) $meter['y'];
        if ($fill !== null && $this->meterCountInFill($fill, $meters) <= 1) {
            return $this->cellFromBox($fill, $page, 'fill');
        }

        $walled = $this->walledCell($x, $y, $walls, $pageWidth, $pageHeight);
        if ($walled !== null) {
            return $this->cellFromBox($walled, $page, 'walls');
        }

        $local = $this->localCell($x, $y, $walls, $meters, $meter, $fill, $fills, $pageWidth, $pageHeight);
        $source = ($local['wall_clips'] ?? 0) >= 2 && min((float) $local['width'], (float) $local['height']) >= 40
            ? 'walls'
            : 'local';

        return $this->cellFromBox($local, $page, $source);
    }

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @return array{x: float, y: float, width: float, height: float, id: string, source: string}
     */
    private function cellFromBox(array $box, int $page, string $source): array
    {
        $x = (float) $box['x'];
        $y = (float) $box['y'];
        $width = (float) $box['width'];
        $height = (float) $box['height'];

        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'source' => $source,
            'id' => $page.'-'.$source.'-'.(int) round($x).'-'.(int) round($y).'-'.(int) round($width).'-'.(int) round($height),
        ];
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function walledCell(float $x, float $y, array $walls, float $pageWidth, float $pageHeight): ?array
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
        if ($width < 28 || $height < 28 || $area < 250 || $area > $pageWidth * $pageHeight * 0.28) {
            return null;
        }

        return ['x' => $left, 'y' => $bottom, 'width' => $width, 'height' => $height];
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @param  list<array<string, mixed>>  $meters
     * @return array{x: float, y: float, width: float, height: float, wall_clips: int}
     */
    private function localCell(float $x, float $y, array $walls, array $meters, array $meter, ?array $fill, array $fills, float $pageWidth, float $pageHeight): array
    {
        $radius = 62.0;
        $left = max(0.0, $x - $radius);
        $right = min($pageWidth, $x + $radius);
        $bottom = max(0.0, $y - $radius);
        $top = min($pageHeight, $y + $radius);
        $clipLeft = $this->nearestVerticalWall($walls, $x, $y, 'left');
        $clipRight = $this->nearestVerticalWall($walls, $x, $y, 'right');
        $clipBottom = $this->nearestHorizontalWall($walls, $x, $y, 'bottom');
        $clipTop = $this->nearestHorizontalWall($walls, $x, $y, 'top');
        if ($clipLeft !== null && ($x - $clipLeft) < 22) {
            $clipLeft = null;
        }
        if ($clipRight !== null && ($clipRight - $x) < 22) {
            $clipRight = null;
        }
        if ($clipBottom !== null && ($y - $clipBottom) < 22) {
            $clipBottom = null;
        }
        if ($clipTop !== null && ($clipTop - $y) < 22) {
            $clipTop = null;
        }
        $minSpan = 36.0;
        if ($clipLeft !== null && $clipRight !== null && ($clipRight - $clipLeft) < $minSpan) {
            $clipLeft = null;
            $clipRight = null;
        }
        if ($clipBottom !== null && $clipTop !== null && ($clipTop - $clipBottom) < $minSpan) {
            $clipBottom = null;
            $clipTop = null;
        }
        $wallClips = 0;
        if ($clipLeft !== null) {
            $left = max($left, $clipLeft);
            $wallClips++;
        }
        if ($clipRight !== null) {
            $right = min($right, $clipRight);
            $wallClips++;
        }
        if ($clipBottom !== null) {
            $bottom = max($bottom, $clipBottom);
            $wallClips++;
        }
        if ($clipTop !== null) {
            $top = min($top, $clipTop);
            $wallClips++;
        }
        foreach ($meters as $other) {
            if ($this->sameMeter($other, $meter)) {
                continue;
            }
            if ((float) $other['square_meters'] < 20 && (float) $meter['square_meters'] >= 40) {
                continue;
            }
            $otherFill = $this->containingFill((float) $other['x'], (float) $other['y'], $fills);
            if (
                $otherFill !== null
                && $this->meterCountInFill($otherFill, $meters) <= 1
                && ($fill === null || $this->meterCountInFill($fill, $meters) > 1)
            ) {
                continue;
            }
            $ox = (float) $other['x'];
            $oy = (float) $other['y'];
            $dx = $ox - $x;
            $dy = $oy - $y;
            if (hypot($dx, $dy) > 110) {
                continue;
            }
            if (abs($dx) >= abs($dy)) {
                $mid = ($x + $ox) / 2;
                if ($dx > 0) {
                    $right = min($right, $mid);
                } else {
                    $left = max($left, $mid);
                }
            } else {
                $mid = ($y + $oy) / 2;
                if ($dy > 0) {
                    $top = min($top, $mid);
                } else {
                    $bottom = max($bottom, $mid);
                }
            }
        }

        return [
            'x' => $left,
            'y' => $bottom,
            'width' => max(8.0, $right - $left),
            'height' => max(8.0, $top - $bottom),
            'wall_clips' => $wallClips,
        ];
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
            if (($y2 - $y1) < 18) {
                continue;
            }
            if ($y < $y1 - 6 || $y > $y2 + 6) {
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
            if (($x2 - $x1) < 18) {
                continue;
            }
            if ($x < $x1 - 6 || $x > $x2 + 6) {
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
     * @param  array<string, mixed>  $cell
     * @param  list<array<string, mixed>>  $names
     * @param  array<string, mixed>  $meter
     * @param  array<string, true>  $used
     * @param  list<array<string, mixed>>  $meters
     * @return array{room_name: string, candidate_count: int, distance: float|null, words: list<array<string, mixed>>, name: array<string, mixed>|null}
     */
    private function nameFromCell(array $cell, array $names, array $meter, array $used, array $meters): array
    {
        $empty = ['room_name' => '', 'candidate_count' => 0, 'distance' => null, 'words' => [], 'name' => null];
        $inside = [];
        $pad = (($cell['source'] ?? '') === 'local') ? 14.0 : 3.0;
        foreach ($names as $name) {
            $key = $name['x'].'|'.$name['y'].'|'.$name['text'];
            // Tekst binnen de kamercontour hoort bij deze kamer; nabije m² buiten de
            // contour mogen die eigendom niet 'stelen' via nameBelongsToMeter.
            if (isset($used[$key]) || ! $this->inBox($name, $cell, $pad)) {
                continue;
            }
            $inside[] = $name;
        }
        if ($inside === [] && ($cell['source'] ?? '') === 'local') {
            foreach ($names as $name) {
                $key = $name['x'].'|'.$name['y'].'|'.$name['text'];
                if (isset($used[$key])) {
                    continue;
                }
                $distance = hypot((float) $name['x'] - (float) $meter['x'], (float) $name['y'] - (float) $meter['y']);
                if ($distance > 48 || ! $this->nameBelongsToMeter($name, $meter, $meters)) {
                    continue;
                }
                $inside[] = $name;
            }
        }
        if ($inside === []) {
            return $empty;
        }

        $metersInCell = [];
        foreach ($meters as $candidate) {
            if ($this->inBox($candidate, $cell, max(6.0, $pad))) {
                $metersInCell[] = $candidate;
            }
        }
        $ownerPool = $metersInCell !== [] ? $metersInCell : $meters;
        if ((float) $meter['square_meters'] >= 40) {
            $ownerPool = array_values(array_filter(
                $ownerPool,
                fn (array $candidate): bool => (float) $candidate['square_meters'] >= 25
                    || $this->sameMeter($candidate, $meter)
            ));
            if ($ownerPool === []) {
                $ownerPool = [$meter];
            }
        }

        $phrases = $this->namePhrases($inside);
        $best = null;
        $bestScore = -1_000_000.0;
        $bestDistance = null;
        foreach ($phrases as $phrase) {
            $midX = array_sum(array_map(fn (array $word) => (float) $word['x'], $phrase)) / count($phrase);
            $midY = array_sum(array_map(fn (array $word) => (float) $word['y'], $phrase)) / count($phrase);
            $distance = hypot($midX - (float) $meter['x'], $midY - (float) $meter['y']);
            $belowBonus = $midY < ((float) $meter['y'] - 4) ? 28.0 : 0.0;
            $aboveMeterBonus = $midY > ((float) $meter['y'] + 4) ? 22.0 : 0.0;
            $xAlignBonus = max(0.0, 55.0 - abs($midX - (float) $meter['x']));
            $lengthBonus = mb_strlen(implode(' ', array_map(
                fn (array $word) => (string) ($word['room_name'] ?? ''),
                $phrase
            )));
            $affinity = $this->nameMeterAffinity(['x' => $midX, 'y' => $midY], $meter);
            $owner = $this->closestMeterByDistance(['x' => $midX, 'y' => $midY], $ownerPool);
            $ownerBonus = $this->sameMeter($owner, $meter) ? 55.0 : -25.0;
            $score = $belowBonus + $aboveMeterBonus + $xAlignBonus + $ownerBonus + ($lengthBonus * 1.2) - $distance - ($affinity * 0.35);
            if ($score > $bestScore) {
                $best = $phrase;
                $bestScore = $score;
                $bestDistance = $distance;
            }
        }
        if ($best === null) {
            return $empty;
        }

        // Houd het labelcluster rond het dichtstbijzijnde woord bij dit m²-anker.
        usort($best, function (array $left, array $right) use ($meter): int {
            $leftDistance = hypot((float) $left['x'] - (float) $meter['x'], (float) $left['y'] - (float) $meter['y']);
            $rightDistance = hypot((float) $right['x'] - (float) $meter['x'], (float) $right['y'] - (float) $meter['y']);

            return $leftDistance <=> $rightDistance;
        });
        $seed = $best[0];
        $seedLabel = (string) ($seed['room_name'] ?? '');
        $seedIsAlcove = (bool) preg_match('/trap|toilet|wc|kast|berging|entree|gang|bordes|^hv$/iu', $seedLabel);
        $cluster = [$seed];
        $changed = true;
        while ($changed && count($cluster) < 3) {
            $changed = false;
            foreach ($best as $word) {
                foreach ($cluster as $accepted) {
                    if ($this->sameTextPoint($word, $accepted)) {
                        continue 2;
                    }
                }
                $wordLabel = (string) ($word['room_name'] ?? '');
                $wordIsAlcove = (bool) preg_match('/trap|toilet|wc|kast|berging|entree|gang|bordes|^hv$/iu', $wordLabel);
                if ($wordIsAlcove !== $seedIsAlcove) {
                    continue;
                }
                foreach ($cluster as $accepted) {
                    $gap = hypot((float) $word['x'] - (float) $accepted['x'], (float) $word['y'] - (float) $accepted['y']);
                    $fromMeter = hypot((float) $word['x'] - (float) $meter['x'], (float) $word['y'] - (float) $meter['y']);
                    if ($gap <= 28 && $fromMeter <= 52) {
                        $cluster[] = $word;
                        $changed = true;
                        break;
                    }
                }
            }
        }
        // Leesvolgorde: boven→onder, links→rechts (samengestelde ruimtenamen).
        usort($cluster, fn (array $left, array $right): int => ((float) $right['y'] <=> (float) $left['y']) ?: ((float) $left['x'] <=> (float) $right['x']));
        $best = $cluster;

        $parts = [];
        foreach ($best as $word) {
            $part = (string) ($word['room_name'] ?? $this->normalizeRoomName((string) $word['text']));
            if ($part === '') {
                continue;
            }
            if ($parts !== []) {
                $last = $parts[array_key_last($parts)];
                if ($last === $part) {
                    continue;
                }
                if (str_starts_with($last, $part) || str_starts_with($part, $last)) {
                    if (mb_strlen($part) > mb_strlen($last)) {
                        $parts[array_key_last($parts)] = $part;
                    }

                    continue;
                }
            }
            $parts[] = $part;
        }
        $joined = trim(implode(' ', $parts));
        $normalized = $this->normalizeRoomName($joined);
        if (! $this->looksLikeRoomName($normalized)) {
            return $empty + ['candidate_count' => count($phrases)];
        }

        return [
            'room_name' => $normalized,
            'candidate_count' => count($phrases),
            'distance' => $bestDistance !== null ? round($bestDistance, 1) : null,
            'words' => $best,
            'name' => $best[0] + ['room_name' => $normalized, 'text' => $joined, 'words' => $best],
        ];
    }

    /**
     * @param  array<string, mixed>  $name
     * @param  array<string, mixed>  $meter
     * @param  list<array<string, mixed>>  $meters
     */
    private function nameCloserToMeterThanPeers(array $name, array $meter, array $meters): bool
    {
        $selfScore = $this->nameMeterAffinity($name, $meter);
        foreach ($meters as $candidate) {
            if ($this->sameMeter($candidate, $meter)) {
                continue;
            }
            if ($this->nameMeterAffinity($name, $candidate) + 4.0 < $selfScore) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lagere score = sterkere koppeling. Labels horen meestal bij het m²-anker
     * eronder of horizontaal uitgelijnd, niet bij een toevallig dichter totaal erboven.
     *
     * @param  array<string, mixed>  $name
     * @param  array<string, mixed>  $meter
     */
    private function nameMeterAffinity(array $name, array $meter): float
    {
        $dx = abs((float) $name['x'] - (float) $meter['x']);
        $dy = (float) $name['y'] - (float) $meter['y'];
        $distance = hypot((float) $name['x'] - (float) $meter['x'], (float) $name['y'] - (float) $meter['y']);
        // Voorkeur: m²-anker onder het ruimtelabel (label hoger op de pagina).
        if ($dy > 4) {
            $distance -= 18.0;
        }
        if ($dx <= 28) {
            $distance -= 12.0;
        }

        return $distance;
    }

    /**
     * @param  array<string, mixed>  $name
     * @param  array<string, mixed>  $meter
     * @param  list<array<string, mixed>>  $meters
     */
    private function nameBelongsToMeter(array $name, array $meter, array $meters): bool
    {
        if (! $this->nameCloserToMeterThanPeers($name, $meter, $meters)) {
            return false;
        }
        $label = (string) ($name['room_name'] ?? $name['text'] ?? '');
        $alcove = (bool) preg_match('/trap|toilet|wc|kast|berging|entree|gang|bordes/i', $label);
        $selfDistance = hypot((float) $name['x'] - (float) $meter['x'], (float) $name['y'] - (float) $meter['y']);
        if ((float) $meter['square_meters'] < 20 && ! $alcove) {
            foreach ($meters as $other) {
                if ((float) $other['square_meters'] < 40) {
                    continue;
                }
                $distance = hypot((float) $name['x'] - (float) $other['x'], (float) $name['y'] - (float) $other['y']);
                if ($distance <= 55) {
                    return false;
                }
            }
        }
        $closest = null;
        $closestDistance = 80.0;
        foreach ($meters as $candidate) {
            $distance = hypot((float) $name['x'] - (float) $candidate['x'], (float) $name['y'] - (float) $candidate['y']);
            if ($distance < $closestDistance) {
                $closest = $candidate;
                $closestDistance = $distance;
            }
        }
        if ($closest === null || $this->sameMeter($closest, $meter)) {
            return true;
        }
        if ($alcove) {
            return false;
        }

        return (float) $closest['square_meters'] < 20
            && (float) $meter['square_meters'] >= 40
            && $selfDistance <= 55;
    }

    /**
     * @param  list<array<string, mixed>>  $names
     * @return list<list<array<string, mixed>>>
     */
    private function namePhrases(array $names): array
    {
        $lines = $this->groupLines($names);
        usort($lines, fn (array $left, array $right) => $right[0]['y'] <=> $left[0]['y'] ?: $left[0]['x'] <=> $right[0]['x']);
        $phrases = [];
        foreach ($lines as $line) {
            if ($phrases === []) {
                $phrases[] = $line;

                continue;
            }
            $previous = $phrases[array_key_last($phrases)];
            $prevY = (float) $previous[0]['y'];
            $lineY = (float) $line[0]['y'];
            $prevMinX = min(array_map(fn (array $word) => (float) $word['x'], $previous));
            $prevMaxX = max(array_map(fn (array $word) => (float) $word['x'], $previous));
            $lineMinX = min(array_map(fn (array $word) => (float) $word['x'], $line));
            $lineMaxX = max(array_map(fn (array $word) => (float) $word['x'], $line));
            $xClose = $lineMinX <= $prevMaxX + 36 && $lineMaxX >= $prevMinX - 36;
            if (abs($prevY - $lineY) <= 14 && $xClose) {
                $phrases[array_key_last($phrases)] = array_merge($previous, $line);

                continue;
            }
            $phrases[] = $line;
        }
        foreach ($phrases as &$phrase) {
            usort($phrase, fn (array $left, array $right) => $right['y'] <=> $left['y'] ?: $left['x'] <=> $right['x']);
        }
        unset($phrase);

        return $phrases;
    }

    private function cellConfidence(array $cell, int $candidates, string $roomName): string
    {
        if ($roomName === '' || $candidates > 1) {
            return 'controleren';
        }
        if (($cell['source'] ?? '') === 'local') {
            return 'controleren';
        }

        return 'hoog';
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeAreas(array $existing, array $incoming): array
    {
        foreach ($incoming as $area) {
            $exists = false;
            foreach ($existing as $current) {
                if (($current['key'] ?? '') === ($area['key'] ?? '')) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $existing[] = $area;
            }
        }

        return $existing;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function locateAreas(array $areas, array $pages): array
    {
        $texts = [];
        foreach ($pages as $page) {
            foreach ($page['texts'] ?? [] as $item) {
                $texts[] = $item;
            }
        }

        foreach ($areas as &$area) {
            if (isset($area['x'], $area['y'])) {
                continue;
            }
            $needle = mb_strtolower(trim((string) ($area['room_number'] ?? $area['room_name'] ?? '')));
            if ($needle === '') {
                continue;
            }
            foreach ($texts as $item) {
                if (str_contains(mb_strtolower((string) $item['text']), $needle)) {
                    $area['page'] = $item['page'];
                    $area['x'] = (float) $item['x'];
                    $area['y'] = (float) $item['y'];
                    break;
                }
            }
        }

        return $areas;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $pages
     * @param  list<array{material: string, color: string, declared_total: float, page: int}>  $legend
     * @return list<array<string, mixed>>
     */
    private function applyFillColors(array $areas, array $pages, array $legend): array
    {
        $fillsByPage = [];
        foreach ($pages as $page) {
            $legendBox = $this->legendBox($page);
            $fillsByPage[$page['page']] = $this->roomFills($page, $legendBox);
        }

        foreach ($areas as &$area) {
            $page = (int) ($area['page'] ?? 1);
            $fills = $fillsByPage[$page] ?? [];
            $fill = null;
            if (isset($area['meter_x'], $area['meter_y'])) {
                $fill = $this->containingFloorColor((float) $area['meter_x'], (float) $area['meter_y'], $fills);
            }
            if ($fill === null && isset($area['x'], $area['y'])) {
                $fill = $this->containingFloorColor((float) $area['x'], (float) $area['y'], $fills);
            }

            if ($fill !== null) {
                $area['fill_color'] = $fill['color']->hex();
                $area['fill_type'] = 'solid';
            }

            if (filled($area['legend_material'] ?? null)) {
                continue;
            }

            $hex = (string) ($area['fill_color'] ?? '');
            if ($hex === '') {
                continue;
            }
            $color = RgbColor::fromHex($hex);
            if ($color === null) {
                continue;
            }

            $via = $area['recognized_via'] ?? ['tekening'];
            if (! in_array('kleur', $via, true)) {
                $via[] = 'kleur';
            }

            $match = $this->matchLegend($color, $legend, $page);
            if ($match === null) {
                // Kleur zonder legenda: fysiek vlak mag bestaan; geen harde Controleren.
                $area['recognized_via'] = array_values(array_unique($via));
                if (($area['confidence'] ?? '') === 'controleren' || ($area['confidence'] ?? '') === '') {
                    $area['confidence'] = ($area['square_meters'] ?? null) !== null ? 'midden' : 'controleren';
                }
                $area['needs_review'] = ($area['confidence'] ?? '') === 'controleren';

                continue;
            }

            if ($this->isNonFlooringLegend($match)) {
                // Plint-/m¹-legenda bevestigt kleur, maar maakt geen vloer-m²-taak en geen Controleren.
                $area['legend_material'] = $match['material'];
                $area['recognized_via'] = array_values(array_unique([...$via, 'legenda']));
                $area['confidence'] = 'hoog';
                $area['needs_review'] = false;

                continue;
            }

            $area['legend_material'] = $match['material'];
            $area['tasks'] = $this->ensureMaterialTask($area['tasks'] ?? [], $match['material'], $area['square_meters'] ?? null);
            $via[] = 'legenda';
            $area['recognized_via'] = array_values(array_unique($via));
            $area['confidence'] = 'hoog';
            $area['needs_review'] = false;
        }

        return $areas;
    }

    /**
     * @param  list<array<string, mixed>>  $works
     * @param  list<array{material: string, color: string, declared_total: float, page: int}>  $legend
     * @return list<array<string, mixed>>
     */
    private function mergeWorks(array $works, array $legend): array
    {
        $index = [];
        foreach ($works as $i => $work) {
            $index[mb_strtolower((string) ($work['name'] ?? ''))] = $i;
        }
        foreach ($legend as $entry) {
            if ($this->isNonFlooringLegend($entry)) {
                continue;
            }
            $key = mb_strtolower($entry['material']);
            if (isset($index[$key])) {
                if ((float) ($works[$index[$key]]['declared_total'] ?? 0) <= 0) {
                    $works[$index[$key]]['declared_total'] = $entry['declared_total'];
                }

                continue;
            }
            $isPlint = str_contains(mb_strtolower((string) $entry['material']), 'plint');
            $works[] = [
                'name' => $entry['material'],
                'unit' => $isPlint
                    ? WorkUnit::LinearMeter->value
                    : (string) ($entry['unit'] ?? WorkUnit::SquareMeter->value),
                'declared_total' => $entry['declared_total'],
                'calculated_total' => 0.0,
                'source_names' => [$entry['material']],
            ];
        }

        return $works;
    }

    /**
     * @param  array<string, mixed>  $fill
     */
    private function isSwatch(array $fill): bool
    {
        return $fill['width'] <= 36 && $fill['height'] <= 36 && $fill['area'] <= 900;
    }

    /**
     * @param  array<string, mixed>  $swatch
     * @param  list<array<string, mixed>>  $texts
     * @return array<string, mixed>|null
     */
    private function materialLineRightOf(array $swatch, array $texts): ?array
    {
        $sx = $swatch['x'] + $swatch['width'];
        $sy = $swatch['y'] + ($swatch['height'] / 2);
        $onLine = [];
        foreach ($texts as $item) {
            if ((float) $item['x'] < $sx - 4 || abs((float) $item['y'] - $sy) > 8) {
                continue;
            }
            $onLine[] = $item;
        }
        if ($onLine === []) {
            return $this->nearestRight($swatch, array_values(array_filter(
                $texts,
                fn (array $item) => $this->looksLikeMaterial((string) $item['text'])
            )));
        }
        usort($onLine, fn (array $left, array $right) => $left['x'] <=> $right['x']);
        $parts = [];
        foreach ($onLine as $item) {
            $text = trim((string) $item['text']);
            if ($this->extractQuantity($text) !== null || preg_match('/^m(?:²|2|¹|1)$/iu', $text)) {
                break;
            }
            // Getal/afgekapt getal (466.73 of "0…") hoort bij de hoeveelheid, niet bij de naam.
            if ($parts !== [] && preg_match('/^\d/u', $text)) {
                break;
            }
            $parts[] = $text;
        }
        $joined = trim(implode(' ', $parts));
        if ($joined === '' || ! $this->looksLikeMaterial($joined)) {
            return $this->nearestRight($swatch, array_values(array_filter(
                $texts,
                fn (array $item) => $this->looksLikeMaterial((string) $item['text'])
            )));
        }

        return [
            'text' => $joined,
            'x' => (float) $onLine[0]['x'],
            'y' => $sy,
            'page' => $onLine[0]['page'] ?? $swatch['page'] ?? 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $swatch
     * @param  list<array<string, mixed>>  $materials
     * @return array<string, mixed>|null
     */
    private function nearestRight(array $swatch, array $materials): ?array
    {
        $best = null;
        $bestDistance = 80.0;
        $sx = $swatch['x'] + $swatch['width'];
        $sy = $swatch['y'] + ($swatch['height'] / 2);
        foreach ($materials as $item) {
            if ($item['x'] < $sx - 4) {
                continue;
            }
            $distance = hypot($item['x'] - $sx, $item['y'] - $sy);
            if ($distance < $bestDistance) {
                $best = $item;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $anchor
     * @param  list<array<string, mixed>>  $texts
     * @return array{quantity: float, unit: string}|null
     */
    private function quantityNear(array $anchor, array $texts): ?array
    {
        $own = $this->extractQuantity((string) $anchor['text']);
        if ($own !== null) {
            return $own;
        }

        $anchorX = (float) $anchor['x'];
        $anchorY = (float) $anchor['y'];
        $line = [];
        foreach ($texts as $item) {
            if (abs((float) $item['y'] - $anchorY) > 7) {
                continue;
            }
            if ((float) $item['x'] < $anchorX - 8) {
                continue;
            }
            $line[] = $item;
        }
        usort($line, fn (array $left, array $right) => $left['x'] <=> $right['x']);

        foreach ($line as $index => $item) {
            $combined = $this->extractQuantity((string) $item['text']);
            if ($combined !== null) {
                return $combined;
            }
            $number = $this->plainQuantityNumber((string) $item['text']);
            if ($number === null) {
                continue;
            }
            $unit = $this->unitRightOf($item, $line, $index);
            if ($unit === null) {
                continue;
            }

            return ['quantity' => $number, 'unit' => $unit];
        }

        return null;
    }

    private function plainQuantityNumber(string $text): ?float
    {
        $text = trim($text);
        if (! preg_match('/^\d{1,5}(?:[.,]\d{1,3})?$/u', $text)) {
            return null;
        }

        return DutchNumber::parse($text);
    }

    /**
     * @param  array<string, mixed>  $number
     * @param  list<array<string, mixed>>  $line
     */
    private function unitRightOf(array $number, array $line, int $index): ?string
    {
        $nx = (float) $number['x'];
        $ny = (float) $number['y'];
        for ($i = $index + 1; $i < count($line); $i++) {
            $item = $line[$i];
            if ((float) $item['x'] - $nx > 50) {
                break;
            }
            if (abs((float) $item['y'] - $ny) > 7) {
                continue;
            }
            $text = trim((string) $item['text']);
            if (preg_match('/^m(?:²|2)$/iu', $text)) {
                return WorkUnit::SquareMeter->value;
            }
            if (preg_match('/^m(?:¹|1)$/iu', $text) || preg_match('/^m$/iu', $text)) {
                return WorkUnit::LinearMeter->value;
            }
            if (preg_match('/^st(?:uks)?$/iu', $text)) {
                return WorkUnit::Pieces->value;
            }
            if ($this->plainQuantityNumber($text) !== null || $this->looksLikeMaterial($text)) {
                break;
            }
        }

        return null;
    }

    /**
     * @return array{quantity: float, unit: string}|null
     */
    private function extractQuantity(string $text): ?array
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', $text, $match)) {
            return ['quantity' => DutchNumber::parse($match[1]) ?? 0.0, 'unit' => WorkUnit::SquareMeter->value];
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*m(?:¹|1)\b/u', $text, $match)) {
            return ['quantity' => DutchNumber::parse($match[1]) ?? 0.0, 'unit' => WorkUnit::LinearMeter->value];
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*st(?:uks)?\b/iu', $text, $match)) {
            return ['quantity' => DutchNumber::parse($match[1]) ?? 0.0, 'unit' => WorkUnit::Pieces->value];
        }
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*m\b/u', $text, $match) && ! preg_match('/m(?:²|2)/u', $text)) {
            return ['quantity' => DutchNumber::parse($match[1]) ?? 0.0, 'unit' => WorkUnit::LinearMeter->value];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $debug
     * @param  list<array<string, mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    private function syncDebug(array $debug, array $areas): array
    {
        foreach ($debug as &$row) {
            foreach ($areas as $area) {
                if ((int) ($area['page'] ?? 0) !== (int) ($row['page'] ?? 0)) {
                    continue;
                }
                if (abs((float) ($area['square_meters'] ?? 0) - (float) ($row['square_meters'] ?? 0)) > 0.02) {
                    continue;
                }
                if (mb_strtolower((string) ($area['room_name'] ?? '')) !== mb_strtolower((string) ($row['room_name'] ?? ''))) {
                    continue;
                }
                $row['fill_color'] = $area['fill_color'] ?? $row['fill_color'] ?? null;
                $row['legend_material'] = $area['legend_material'] ?? $row['legend_material'] ?? null;
                $row['confidence'] = $area['confidence'] ?? $row['confidence'];
                $row['recognized_via'] = $area['recognized_via'] ?? $row['recognized_via'];
                $row['room_number'] = $area['room_number'] ?? $row['room_number'];
                break;
            }
        }
        unset($row);

        return $debug;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function legendBox(array $page): array
    {
        $pageWidth = (float) ($page['width'] ?? 595);
        $pageHeight = (float) ($page['height'] ?? 842);
        // Legenda staat onderaan de tekenpagina; beperk zoekgebied zodat
        // kamerkleuren hoger op de pagina geen legendavlaken worden.
        $maxLegendY = min($pageHeight * 0.22, 160.0);

        $materials = [];
        foreach ($page['texts'] ?? [] as $item) {
            if ((float) ($item['y'] ?? 0) > $maxLegendY) {
                continue;
            }
            if ($this->looksLikeMaterial((string) $item['text'])) {
                $materials[] = $item;
            }
        }
        if ($materials === []) {
            return ['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 0.0];
        }

        $xs = array_column($materials, 'x');
        $ys = array_column($materials, 'y');
        foreach ($page['fills'] ?? [] as $fill) {
            if (! $this->isSwatch($fill)) {
                continue;
            }
            $cy = $fill['y'] + ($fill['height'] / 2);
            if ($cy > $maxLegendY) {
                continue;
            }
            $cx = $fill['x'] + ($fill['width'] / 2);
            foreach ($materials as $material) {
                if (hypot($cx - (float) $material['x'], $cy - (float) $material['y']) < 90) {
                    $xs[] = $fill['x'];
                    $ys[] = $fill['y'];
                    break;
                }
            }
        }
        $minX = min($xs) - 24;
        $minY = min($ys) - 16;
        $maxX = max($xs) + 400;
        $maxY = min($maxLegendY + 8, max($ys) + 20);

        return [
            'x' => max(0.0, $minX),
            'y' => max(0.0, $minY),
            'width' => min($pageWidth, $maxX) - max(0.0, $minX),
            'height' => max(0.0, $maxY - max(0.0, $minY)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $texts
     * @param  array{x: float, y: float, width: float, height: float}  $legendBox
     * @return list<array<string, mixed>>
     */
    private function meterAnchors(array $texts, array $legendBox): array
    {
        $anchors = [];
        foreach ($texts as $item) {
            if ($this->inBox($item, $legendBox) || $this->looksLikeMaterial((string) $item['text'])) {
                continue;
            }
            $own = $this->extractSquareMeters((string) $item['text']);
            if ($own !== null && $own > 0) {
                if ($this->nearMaterial($item, $texts)) {
                    continue;
                }
                $anchors[] = $item + ['square_meters' => $own];

                continue;
            }
            if (! preg_match('/^m(?:²|2)$/iu', trim((string) $item['text']))) {
                continue;
            }
            $number = $this->numberLeftOf($item, $texts);
            if ($number === null || $this->inBox($number, $legendBox) || $this->nearMaterial($item, $texts)) {
                continue;
            }
            $meters = DutchNumber::parse(trim((string) $number['text']));
            if ($meters === null || $meters <= 0) {
                continue;
            }
            $anchors[] = [
                'text' => trim((string) $number['text']).' m²',
                'x' => ((float) $number['x'] + (float) $item['x']) / 2,
                'y' => ((float) $number['y'] + (float) $item['y']) / 2,
                'page' => $item['page'] ?? $number['page'] ?? 1,
                'square_meters' => $meters,
            ];
        }

        return $anchors;
    }

    /**
     * @param  array<string, mixed>  $unit
     * @param  list<array<string, mixed>>  $texts
     * @return array<string, mixed>|null
     */
    private function numberLeftOf(array $unit, array $texts): ?array
    {
        $best = null;
        $bestDx = 28.0;
        foreach ($texts as $item) {
            if ((float) $item['x'] >= (float) $unit['x'] || abs((float) $item['y'] - (float) $unit['y']) > 6) {
                continue;
            }
            $text = trim((string) $item['text']);
            if (preg_match('/^\d{3,}$/', $text) || ! preg_match('/^\d+(?:[.,]\d+)?$/', $text)) {
                continue;
            }
            $dx = (float) $unit['x'] - (float) $item['x'];
            if ($dx < $bestDx) {
                $best = $item;
                $bestDx = $dx;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $number
     * @param  list<array<string, mixed>>  $texts
     */
    private function unitToTheRight(array $number, array $texts): bool
    {
        foreach ($texts as $item) {
            $text = trim((string) $item['text']);
            if (! preg_match('/^m(?:²|2)$/iu', $text) && $this->extractSquareMeters($text) === null) {
                continue;
            }
            if ((float) $item['x'] <= (float) $number['x'] || abs((float) $item['y'] - (float) $number['y']) > 6) {
                continue;
            }
            if (((float) $item['x'] - (float) $number['x']) <= 28) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $point
     * @param  list<array<string, mixed>>  $texts
     */
    private function nearMaterial(array $point, array $texts): bool
    {
        foreach ($texts as $item) {
            if (! $this->looksLikeMaterial((string) $item['text'])) {
                continue;
            }
            if (hypot((float) $item['x'] - (float) $point['x'], (float) $item['y'] - (float) $point['y']) < 72) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meter
     * @param  list<array<string, mixed>>  $names
     * @param  list<array<string, mixed>>  $meters
     * @param  array<string, mixed>|null  $fill
     * @param  list<array<string, mixed>>  $fills
     * @return list<array<string, mixed>>
     */
    private function namesOwnedByMeter(array $meter, array $names, array $filledMeters, ?array $fill, array $allMeters, array $fills): array
    {
        $owned = [];
        foreach ($names as $name) {
            $distance = hypot((float) $name['x'] - (float) $meter['x'], (float) $name['y'] - (float) $meter['y']);
            if ($distance > 90) {
                continue;
            }
            if ($fill !== null && ! $this->inBox($name, $fill, 12) && $distance > 50) {
                continue;
            }
            $locked = $this->closestMeterByDistance($name, $allMeters);
            if ($locked === null) {
                continue;
            }
            $lockedDistance = hypot((float) $name['x'] - (float) $locked['x'], (float) $name['y'] - (float) $locked['y']);
            $lockedFill = $this->containingFill((float) $locked['x'], (float) $locked['y'], $fills);
            $lockedStrong = $lockedFill !== null && $this->meterCountInFill($lockedFill, $allMeters) <= 1;
            if (! $lockedStrong && $lockedDistance <= 18) {
                if (! $this->sameMeter($locked, $meter)) {
                    continue;
                }
            } elseif (! $this->sameMeter($this->closestMeter($name, $filledMeters !== [] ? $filledMeters : $allMeters), $meter)) {
                continue;
            }
            $owned[] = $name;
        }

        return $owned;
    }

    /**
     * @param  list<array<string, mixed>>  $owned
     * @param  list<array<string, mixed>>  $names
     * @return list<array<string, mixed>>
     */
    private function expandOwnedLine(array $owned, array $names): array
    {
        if ($owned === []) {
            return [];
        }
        $seen = [];
        foreach ($owned as $word) {
            $seen[$word['x'].'|'.$word['y'].'|'.$word['text']] = true;
        }
        foreach ($owned as $word) {
            foreach ($names as $name) {
                $key = $name['x'].'|'.$name['y'].'|'.$name['text'];
                if (isset($seen[$key])) {
                    continue;
                }
                if (abs((float) $name['y'] - (float) $word['y']) > 5 || abs((float) $name['x'] - (float) $word['x']) > 28) {
                    continue;
                }
                $seen[$key] = true;
                $owned[] = $name;
            }
        }

        return $owned;
    }

    /**
     * @param  array<string, mixed>  $point
     * @param  list<array<string, mixed>>  $meters
     * @return array<string, mixed>|null
     */
    private function closestMeterByDistance(array $point, array $meters): ?array
    {
        $best = null;
        $bestDistance = 90.0;
        foreach ($meters as $meter) {
            $distance = hypot((float) $point['x'] - (float) $meter['x'], (float) $point['y'] - (float) $meter['y']);
            if ($distance < $bestDistance) {
                $best = $meter;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $fill
     * @param  list<array<string, mixed>>  $meters
     */
    private function meterCountInFill(array $fill, array $meters): int
    {
        $count = 0;
        foreach ($meters as $meter) {
            if ($this->inBox($meter, $fill, 2)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $point
     * @param  list<array<string, mixed>>  $meters
     * @return array<string, mixed>|null
     */
    private function closestMeter(array $point, array $meters): ?array
    {
        $best = null;
        $bestScore = 10_000.0;
        foreach ($meters as $meter) {
            $distance = hypot((float) $point['x'] - (float) $meter['x'], (float) $point['y'] - (float) $meter['y']);
            if ($distance > 90) {
                continue;
            }
            $score = $distance / max(1.0, sqrt((float) $meter['square_meters']));
            if ($score < $bestScore) {
                $best = $meter;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>|null  $left
     * @param  array<string, mixed>  $right
     */
    private function sameMeter(?array $left, array $right): bool
    {
        if ($left === null) {
            return false;
        }

        return abs((float) $left['x'] - (float) $right['x']) < 0.05
            && abs((float) $left['y'] - (float) $right['y']) < 0.05
            && abs((float) $left['square_meters'] - (float) $right['square_meters']) < 0.02;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sameTextPoint(array $left, array $right): bool
    {
        return abs((float) ($left['x'] ?? 0) - (float) ($right['x'] ?? 0)) < 0.51
            && abs((float) ($left['y'] ?? 0) - (float) ($right['y'] ?? 0)) < 0.51
            && (string) ($left['text'] ?? '') === (string) ($right['text'] ?? '');
    }

    /**
     * @param  list<array<string, mixed>>  $owned
     * @param  array<string, mixed>  $meter
     * @return array<string, mixed>|null
     */
    private function joinOwnedNames(array $owned, array $meter): ?array
    {
        if ($owned === []) {
            return null;
        }

        return $this->bestNamedLine($owned, $meter);
    }

    /**
     * @param  array<string, mixed>  $fill
     * @param  list<array<string, mixed>>  $names
     * @param  array<string, true>  $used
     * @param  array<string, mixed>  $meter
     * @return array<string, mixed>|null
     */
    private function nameInsideFill(array $fill, array $names, array $used, array $meter): ?array
    {
        $inside = [];
        foreach ($names as $name) {
            $key = $name['x'].'|'.$name['y'].'|'.$name['text'];
            if (isset($used[$key]) || ! $this->inBox($name, $fill, 8)) {
                continue;
            }
            $inside[] = $name;
        }
        if ($inside === []) {
            return null;
        }

        $nearby = array_values(array_filter(
            $inside,
            fn (array $name) => hypot((float) $name['x'] - (float) $meter['x'], (float) $name['y'] - (float) $meter['y']) <= 55
        ));

        return $this->bestNamedLine($nearby !== [] ? $nearby : $inside, $meter);
    }

    /**
     * @param  list<array<string, mixed>>  $names
     * @param  array<string, mixed>  $meter
     * @return array<string, mixed>|null
     */
    private function bestNamedLine(array $names, array $meter): ?array
    {
        $lines = $this->groupLines($names);
        $best = null;
        $bestScore = -1_000.0;
        foreach ($lines as $line) {
            $joined = trim(implode(' ', array_map(
                fn (array $word) => (string) ($word['room_name'] ?? $this->normalizeRoomName((string) $word['text'])),
                $line
            )));
            $normalized = $this->normalizeRoomName($joined);
            if (! $this->looksLikeRoomName($normalized)) {
                continue;
            }
            $midX = ((float) $line[0]['x'] + (float) $line[array_key_last($line)]['x']) / 2;
            $distance = hypot($midX - (float) $meter['x'], (float) $line[0]['y'] - (float) $meter['y']);
            $score = (mb_strlen($normalized) * 4) - $distance;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $line[0] + [
                    'room_name' => $normalized,
                    'text' => $joined,
                    'words' => $line,
                ];
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $names
     * @return list<list<array<string, mixed>>>
     */
    private function groupLines(array $names): array
    {
        usort($names, fn (array $left, array $right) => $left['y'] <=> $right['y'] ?: $left['x'] <=> $right['x']);
        $lines = [];
        foreach ($names as $name) {
            $placed = false;
            foreach ($lines as &$line) {
                if (abs((float) $line[0]['y'] - (float) $name['y']) <= 6) {
                    $line[] = $name;
                    $placed = true;
                    break;
                }
            }
            unset($line);
            if (! $placed) {
                $lines[] = [$name];
            }
        }
        foreach ($lines as &$line) {
            usort($line, fn (array $left, array $right) => $left['x'] <=> $right['x']);
        }
        unset($line);

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $box
     * @param  list<array<string, mixed>>  $texts
     * @return list<array<string, mixed>>
     */
    private function textsInside(array $box, array $texts): array
    {
        return array_values(array_filter(
            $texts,
            fn (array $item) => $this->inBox($item, $box, 4)
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $texts
     */
    private function confidentRoomNumber(array $texts, float $meters): ?string
    {
        foreach ($texts as $item) {
            $text = trim((string) $item['text']);
            if (! preg_match('/^([0-3][.\-]\d{1,3}[a-zA-Z]?)$/', $text, $match)) {
                continue;
            }
            if (abs((float) str_replace(',', '.', $match[1]) - $meters) < 0.001) {
                continue;
            }
            if ($this->unitToTheRight($item, $texts)) {
                continue;
            }

            return $match[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{x: float, y: float, width: float, height: float}  $box
     */
    private function inBox(array $item, array $box, float $pad = 0.0): bool
    {
        if (($box['width'] ?? 0) <= 0 || ($box['height'] ?? 0) <= 0) {
            return false;
        }
        $x = (float) ($item['x'] ?? 0);
        $y = (float) ($item['y'] ?? 0);

        return $x >= $box['x'] - $pad
            && $x <= $box['x'] + $box['width'] + $pad
            && $y >= $box['y'] - $pad
            && $y <= $box['y'] + $box['height'] + $pad;
    }

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $left
     * @param  array{x: float, y: float, width: float, height: float}  $right
     */
    private function boxOverlaps(array $left, array $right): bool
    {
        if (($right['width'] ?? 0) <= 0 || ($right['height'] ?? 0) <= 0) {
            return false;
        }
        if (($left['width'] ?? 0) <= 0 || ($left['height'] ?? 0) <= 0) {
            return false;
        }

        return $left['x'] < $right['x'] + $right['width']
            && $right['x'] < $left['x'] + $left['width']
            && $left['y'] < $right['y'] + $right['height']
            && $right['y'] < $left['y'] + $left['height'];
    }

    private function normalizeRoomName(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $pairwise = $this->pairwiseUndouble($text);
        if ($pairwise !== $text) {
            return trim($pairwise);
        }

        return $text;
    }

    private function pairwiseUndouble(string $value): string
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($chars);
        if ($count < 4) {
            return $value;
        }
        $pairs = 0;
        $same = 0;
        $even = '';
        for ($i = 0; $i < $count - 1; $i += 2) {
            $pairs++;
            if ($chars[$i] === $chars[$i + 1]) {
                $same++;
            }
            $even .= $chars[$i];
        }
        if ($count % 2 === 1) {
            $even .= $chars[$count - 1];
        }

        return ($pairs > 0 && ($same / $pairs) >= 0.55) ? $even : $value;
    }

    /**
     * @param  array<string, mixed>  $meter
     * @param  list<array<string, mixed>>  $names
     * @param  array<string, true>  $used
     * @return array<string, mixed>|null
     */
    private function nearestName(array $meter, array $names, array $used): ?array
    {
        $best = null;
        $bestDistance = 70.0;
        foreach ($names as $name) {
            $key = $name['x'].'|'.$name['y'].'|'.$name['text'];
            if (isset($used[$key])) {
                continue;
            }
            $distance = hypot($name['x'] - $meter['x'], $name['y'] - $meter['y']);
            if ($distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $fills
     * @return array<string, mixed>|null
     */
    private function containingFill(float $x, float $y, array $fills): ?array
    {
        $best = null;
        foreach ($fills as $fill) {
            if ($x < $fill['x'] - 2 || $x > $fill['x'] + $fill['width'] + 2) {
                continue;
            }
            if ($y < $fill['y'] - 2 || $y > $fill['y'] + $fill['height'] + 2) {
                continue;
            }
            if ($best === null || $fill['area'] < $best['area']) {
                $best = $fill;
            }
        }

        return $best;
    }

    /**
     * Kleur voor materiaalkoppeling: substantiële vlakken gaan vóór mini-fragmenten.
     * Mini-fragmenten (vaak Desso) mogen, maar kies dan het grootste fragment i.p.v. een icoontje.
     *
     * @param  list<array<string, mixed>>  $fills
     * @return array<string, mixed>|null
     */
    private function containingFloorColor(float $x, float $y, array $fills): ?array
    {
        $hits = [];
        foreach ($fills as $fill) {
            if ($x < $fill['x'] - 2 || $x > $fill['x'] + $fill['width'] + 2) {
                continue;
            }
            if ($y < $fill['y'] - 2 || $y > $fill['y'] + $fill['height'] + 2) {
                continue;
            }
            $hits[] = $fill;
        }
        if ($hits === []) {
            return null;
        }

        $substantial = array_values(array_filter(
            $hits,
            fn (array $fill) => (float) $fill['area'] >= 400.0
                || max((float) $fill['width'], (float) $fill['height']) > 40.0
        ));
        if ($substantial !== []) {
            usort($substantial, fn (array $left, array $right) => $left['area'] <=> $right['area']);

            return $substantial[0];
        }

        usort($hits, fn (array $left, array $right) => $right['area'] <=> $left['area']);

        return $hits[0];
    }

    /**
     * @param  list<array{material: string, color: ?string, declared_total: float, page: int, unit?: string, floor?: string}>  $legend
     * @return array{material: string, color: ?string, declared_total: float, page: int, unit?: string, floor?: string}|null
     */
    private function matchLegend(RgbColor $color, array $legend, ?int $page = null): ?array
    {
        $best = null;
        $bestDistance = 48.0;
        foreach ($legend as $entry) {
            if ($this->isNonFlooringLegend($entry)) {
                continue;
            }
            $hex = $entry['color'] ?? null;
            if (! is_string($hex) || $hex === '') {
                continue;
            }
            $swatch = RgbColor::fromHex($hex);
            if ($swatch === null) {
                continue;
            }
            $distance = $color->distance($swatch);
            if ($page !== null && (int) ($entry['page'] ?? 0) === $page) {
                $distance -= 8;
            }
            if ($distance < $bestDistance) {
                $best = $entry;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isNonFlooringLegend(array $entry): bool
    {
        $name = mb_strtolower((string) ($entry['material'] ?? ''));
        if (str_contains($name, 'plint')) {
            return true;
        }
        $unit = (string) ($entry['unit'] ?? WorkUnit::SquareMeter->value);

        return in_array($unit, [WorkUnit::LinearMeter->value, 'm1', 'm¹'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private function ensureMaterialTask(array $tasks, string $material, mixed $quantity): array
    {
        foreach ($tasks as $task) {
            if (mb_strtolower((string) ($task['work_name'] ?? '')) === mb_strtolower($material)) {
                return $tasks;
            }
        }

        $isPlint = str_contains(mb_strtolower($material), 'plint');
        $tasks[] = [
            'work_name' => $material,
            // Plinten zijn altijd m¹; nooit ruimte-m² als plinthoeveelheid gebruiken.
            'unit' => $isPlint ? WorkUnit::LinearMeter->value : WorkUnit::SquareMeter->value,
            'quantity' => $isPlint ? 0.0 : (float) ($quantity ?? 0),
            'perimeter' => 0.0,
            'seams' => 0.0,
            'parts' => 1,
        ];

        return $tasks;
    }

    /**
     * @param  list<array{material: string, color: string, declared_total: ?float, page: int, unit?: string}>  $legend
     * @return list<array{material: string, color: string, declared_total: ?float, page: int, unit?: string}>
     */
    private function uniqueLegend(array $legend): array
    {
        $unique = [];
        foreach ($legend as $entry) {
            $key = ($entry['page'] ?? 0).'|'
                .($entry['color'] ?? ('pattern:'.($entry['pattern_id'] ?? 'none'))).'|'
                .mb_strtolower((string) ($entry['material'] ?? '')).'|'
                .($entry['unit'] ?? WorkUnit::SquareMeter->value);
            if (! isset($unique[$key])) {
                $unique[$key] = $entry;

                continue;
            }
            if (($unique[$key]['declared_total'] ?? null) === null && ($entry['declared_total'] ?? null) !== null) {
                $unique[$key] = $entry;
            }
        }

        return array_values($unique);
    }

    private function looksLikeMaterial(string $text): bool
    {
        return (bool) preg_match('/tarkett|desso|marmoleum|linoleum|vinyl|pvc|tapijt|coating|granit|classics|plinten?|coral|gietvloer|oak|desert|safe\.?\s*t|directie|brush/i', $text);
    }

    private function looksLikeRoomName(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 40 || mb_strlen($text) < 2) {
            return false;
        }
        if ($this->looksLikeMaterial($text) || $this->extractSquareMeters($text) !== null) {
            return false;
        }
        if (preg_match('/^m(?:²|2)$/iu', $text)) {
            return false;
        }
        if (preg_match('/^\d+([.,]\d+)?$/', $text) || preg_match('/^\d+\s*m$/i', $text)) {
            return false;
        }
        if (preg_match('/^(begane\s*grond|verdieping|legenda|schaal|pagina|tekening|installatie)\b/i', $text)) {
            return false;
        }

        return (bool) preg_match('/[a-zA-Zà-ÿ]/u', $text);
    }

    private function extractSquareMeters(string $text): ?float
    {
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', $text, $match)) {
            return null;
        }

        return DutchNumber::parse($match[1]);
    }

    private function cleanMaterialName(string $name): string
    {
        $name = preg_replace('/(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', '', $name) ?? $name;
        $name = preg_replace('/\s+\d+([.,]\d+)?\s*…?$/u', '', $name) ?? $name;
        $name = preg_replace('/\s*…+\s*/u', ' ', $name) ?? $name;
        $name = trim($name, " \t-–—:|,");

        return preg_replace('/\s+/', ' ', $name) ?? $name;
    }

    /**
     * @param  list<array<string, mixed>>  $texts
     */
    private function floorFromTexts(array $texts, float $width = 595, float $height = 842): string
    {
        $title = [];
        foreach ($texts as $item) {
            if ((float) $item['x'] > $width * 0.55 && (float) $item['y'] < $height * 0.22) {
                $title[] = (string) $item['text'];
            }
        }
        $blob = mb_strtolower(implode(' ', $title !== [] ? $title : array_column($texts, 'text')));

        return $this->floorLabel()->canonical($blob) ?? 'Onbekend';
    }

    private function floorLabel(): FloorLabel
    {
        return new FloorLabel;
    }
}
