<?php

namespace App\Services\QuoteCalculation;

use App\Enums\FinishRole;
use App\Support\DutchNumber;

class SpatialFinishLinker
{
    public function __construct(
        private DrawingTextPositions $positions = new DrawingTextPositions,
        private RoomFloorFinishes $floorFinishes = new RoomFloorFinishes,
    ) {}

    /**
     * Attach vloer/plint codes from the room finish symbol first, then leftover local codes.
     *
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>, fills?: list<array<string, mixed>>}>  $pages
     * @return list<array<string, mixed>>
     */
    public function link(array $rooms, string $path, array $pages = []): array
    {
        if ($rooms === []) {
            return $rooms;
        }

        try {
            $pages = $pages !== [] ? $pages : (is_file($path) ? $this->positions->extract($path) : []);
        } catch (\Throwable) {
            return $rooms;
        }

        foreach ($pages as $page) {
            $rooms = $this->assignPage($rooms, $page);
        }

        return $rooms;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  array{page?: int, width?: float, height?: float, texts?: list<array{text: string, x: float, y: float, page: int}>}  $page
     * @return list<array<string, mixed>>
     */
    public function assignPage(array $rooms, array $page): array
    {
        $items = $page['texts'] ?? [];
        if ($items === []) {
            return $rooms;
        }

        $width = max(1.0, (float) ($page['width'] ?? 1));
        $height = max(1.0, (float) ($page['height'] ?? 1));
        $threshold = hypot($width, $height) * 0.10;
        $anchors = $this->anchors($items);
        if ($anchors === []) {
            return $rooms;
        }

        $roomsByNumber = [];
        foreach ($rooms as $index => $room) {
            $number = mb_strtolower((string) ($room['room_number'] ?? ''));
            if ($number !== '') {
                $roomsByNumber[$number] = $index;
            }
        }

        foreach ($anchors as $anchor) {
            $number = mb_strtolower((string) $anchor['room_number']);
            if (! isset($roomsByNumber[$number])) {
                continue;
            }
            $rooms[$roomsByNumber[$number]]['extra_floors'] = [];
        }

        $stacks = $this->finishStacks($items);
        $assignedStackKeys = $this->assignStacks($rooms, $anchors, $stacks, $roomsByNumber, $threshold);
        $codes = $this->codes($items);
        if ($codes !== []) {
            $rooms = $this->assignFallbackCodes($rooms, $anchors, $codes, $roomsByNumber, $assignedStackKeys, $threshold);
        }
        $rooms = $this->assignLocalFloors($rooms, $anchors, $codes, $stacks, $assignedStackKeys, $roomsByNumber, $items, $width, $height, $threshold);

        foreach ($anchors as $anchor) {
            $number = mb_strtolower((string) $anchor['room_number']);
            if (! isset($roomsByNumber[$number])) {
                continue;
            }
            $rooms[$roomsByNumber[$number]] = $this->syncFloors($rooms[$roomsByNumber[$number]]);
        }

        return $rooms;
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return list<array{text: string, x: float, y: float, page: int, room_number: string}>
     */
    private function anchors(array $items): array
    {
        $anchors = [];
        $preferBuildingCodes = false;
        foreach ($items as $item) {
            if (preg_match('/\b[A-Z]-\d{2}-\d{2}\b/u', trim((string) $item['text']))) {
                $preferBuildingCodes = true;
                break;
            }
        }
        foreach ($items as $item) {
            $number = $this->roomNumberIn(trim((string) $item['text']), $preferBuildingCodes);
            if ($number === null) {
                continue;
            }
            $anchors[] = $item + ['room_number' => $number];
        }

        return $anchors;
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return list<array{text: string, x: float, y: float, page: int, code: string, kind: string, key: string}>
     */
    private function codes(array $items): array
    {
        $raw = [];
        foreach ($items as $offset => $item) {
            if ($this->looksLikeLegendItem((string) $item['text']) || $this->nearLegendCue($item, $items)) {
                continue;
            }
            foreach ($this->finishCodesIn((string) $item['text'], false) as $code) {
                $raw[] = $item + [
                    'code' => $code,
                    'kind' => $this->codeKind($code),
                    'key' => ((int) ($item['page'] ?? 1)).':'.$offset.':'.$code,
                ];
            }
        }

        return array_values($raw);
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return list<array{x: float, y: float, page: int, codes: list<array{code: string, kind: string, key: string, x: float, y: float, page: int}>, keys: array<string, true>, floor_code: ?string, plinth_code: ?string}>
     */
    private function finishStacks(array $items): array
    {
        $marks = [];
        foreach ($items as $offset => $item) {
            if ($this->looksLikeLegendItem((string) $item['text']) || $this->nearLegendCue($item, $items)) {
                continue;
            }
            foreach ($this->finishCodesIn((string) $item['text'], true) as $code) {
                $marks[] = $item + [
                    'code' => $code,
                    'kind' => $this->codeKind($code),
                    'key' => ((int) ($item['page'] ?? 1)).':'.$offset.':'.$code,
                ];
            }
        }
        if ($marks === []) {
            return [];
        }

        usort($marks, function (array $left, array $right): int {
            $x = $left['x'] <=> $right['x'];

            return $x !== 0 ? $x : ($left['y'] <=> $right['y']);
        });

        $columns = [];
        foreach ($marks as $mark) {
            $placed = false;
            foreach ($columns as $index => $column) {
                if (abs($mark['x'] - $column['x']) > 10) {
                    continue;
                }
                $columns[$index]['items'][] = $mark;
                $columns[$index]['x'] = (($column['x'] * count($column['items'])) + $mark['x']) / (count($column['items']) + 1);
                $placed = true;
                break;
            }
            if (! $placed) {
                $columns[] = ['x' => (float) $mark['x'], 'items' => [$mark]];
            }
        }

        $stacks = [];
        foreach ($columns as $column) {
            usort($column['items'], fn (array $left, array $right) => $left['y'] <=> $right['y']);
            $group = [];
            $previousY = null;
            foreach ($column['items'] as $item) {
                if ($previousY !== null && ((float) $item['y'] - $previousY) > 18) {
                    $stack = $this->stackFromGroup($group);
                    if ($stack !== null) {
                        $stacks[] = $stack;
                    }
                    $group = [];
                }
                $group[] = $item;
                $previousY = (float) $item['y'];
            }
            $stack = $this->stackFromGroup($group);
            if ($stack !== null) {
                $stacks[] = $stack;
            }
        }

        return $stacks;
    }

    /**
     * @param  list<array{code: string, kind: string, key: string, x: float, y: float, page: int}>  $group
     * @return array{x: float, y: float, page: int, codes: list<array{code: string, kind: string, key: string, x: float, y: float, page: int}>, keys: array<string, true>, floor_code: ?string, plinth_code: ?string}|null
     */
    private function stackFromGroup(array $group): ?array
    {
        if (count($group) < 2) {
            return null;
        }

        $kinds = [];
        foreach ($group as $item) {
            $kinds[$item['kind']] = true;
        }
        if (! isset($kinds['floor']) || count($kinds) < 2) {
            return null;
        }

        $xs = array_map(fn (array $item) => (float) $item['x'], $group);
        $ys = array_map(fn (array $item) => (float) $item['y'], $group);
        $keys = [];
        foreach ($group as $item) {
            $keys[$item['key']] = true;
        }

        return [
            'x' => array_sum($xs) / count($xs),
            'y' => array_sum($ys) / count($ys),
            'page' => (int) $group[0]['page'],
            'codes' => $group,
            'keys' => $keys,
            'floor_code' => $this->floorFromStack($group),
            'plinth_code' => $this->plinthFromStack($group),
        ];
    }

    /**
     * @param  list<array{code: string, kind: string, y: float}>  $group
     */
    private function floorFromStack(array $group): ?string
    {
        $floors = array_values(array_filter($group, fn (array $item) => $item['kind'] === 'floor'));
        if ($floors === []) {
            return null;
        }

        $family = array_values(array_filter($floors, fn (array $item) => ! str_contains($item['code'], '.')));
        if (count($family) === 1) {
            return $family[0]['code'];
        }

        $specific = array_values(array_filter($floors, fn (array $item) => str_contains($item['code'], '.')));
        if (count($specific) === 1) {
            return $specific[0]['code'];
        }
        if (count($specific) > 1) {
            $floors = $specific;
        }
        if (count($floors) === 1) {
            return $floors[0]['code'];
        }

        $plinthY = null;
        foreach ($group as $item) {
            if ($item['kind'] === 'plinth') {
                $plinthY = (float) $item['y'];
                break;
            }
        }
        if ($plinthY === null) {
            return $floors[0]['code'];
        }

        $best = $floors[0];
        $bestDistance = abs((float) $best['y'] - $plinthY);
        foreach ($floors as $floor) {
            $distance = abs((float) $floor['y'] - $plinthY);
            if ($distance < $bestDistance) {
                $best = $floor;
                $bestDistance = $distance;
            }
        }

        return $best['code'];
    }

    /**
     * @param  list<array{code: string, kind: string}>  $group
     */
    private function plinthFromStack(array $group): ?string
    {
        foreach ($group as $item) {
            if ($item['kind'] === 'plinth') {
                return $item['code'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     * @param  list<array{x: float, y: float, page: int, keys: array<string, true>, floor_code: ?string, plinth_code: ?string}>  $stacks
     * @param  array<string, int>  $roomsByNumber
     * @return array<string, true>
     */
    private function assignStacks(array &$rooms, array $anchors, array $stacks, array $roomsByNumber, float $threshold): array
    {
        $pairs = [];
        $maxDistance = $threshold * 2.5;
        foreach ($anchors as $anchor) {
            $number = mb_strtolower((string) $anchor['room_number']);
            if (! isset($roomsByNumber[$number])) {
                continue;
            }
            foreach ($stacks as $stackIndex => $stack) {
                if ((int) $stack['page'] !== (int) $anchor['page']) {
                    continue;
                }
                $distance = hypot((float) $stack['x'] - (float) $anchor['x'], (float) $stack['y'] - (float) $anchor['y']);
                if ($distance > $maxDistance) {
                    continue;
                }
                $pairs[] = [
                    'index' => $roomsByNumber[$number],
                    'stack' => $stackIndex,
                    'distance' => $distance,
                ];
            }
        }
        usort($pairs, fn (array $left, array $right) => $left['distance'] <=> $right['distance']);

        $usedRooms = [];
        $usedStacks = [];
        $assignedKeys = [];
        foreach ($pairs as $pair) {
            $index = $pair['index'];
            $stackIndex = $pair['stack'];
            if (isset($usedRooms[$index]) || isset($usedStacks[$stackIndex])) {
                continue;
            }
            $stack = $stacks[$stackIndex];
            if (filled($stack['floor_code'] ?? null)) {
                $rooms[$index]['floor_code'] = $stack['floor_code'];
            }
            if (filled($stack['plinth_code'] ?? null)) {
                $rooms[$index]['plinth_code'] = $stack['plinth_code'];
            }
            if (filled($rooms[$index]['floor_code'] ?? null)
                && ($rooms[$index]['square_meters'] ?? null) !== null
                && filled($rooms[$index]['room_name'] ?? null)) {
                $rooms[$index]['needs_review'] = false;
            }
            $usedRooms[$index] = true;
            $usedStacks[$stackIndex] = true;
            foreach ($stack['keys'] as $key => $_) {
                $assignedKeys[$key] = true;
            }
        }

        return $assignedKeys;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     * @param  list<array{code: string, kind: string, key: string, x: float, y: float, page: int}>  $codes
     * @param  array<string, int>  $roomsByNumber
     * @param  array<string, true>  $assignedStackKeys
     * @return list<array<string, mixed>>
     */
    private function assignFallbackCodes(
        array $rooms,
        array $anchors,
        array $codes,
        array $roomsByNumber,
        array $assignedStackKeys,
        float $threshold,
    ): array {
        foreach (['floor' => 'floor_code', 'plinth' => 'plinth_code'] as $kind => $field) {
            $pairs = $this->candidatePairs($anchors, $codes, $kind, $rooms, $roomsByNumber, $field, $threshold, $assignedStackKeys);
            usort($pairs, fn (array $left, array $right) => $left['distance'] <=> $right['distance']);
            $used = [];
            foreach ($pairs as $pair) {
                $index = $pair['index'];
                $key = $pair['key'];
                if (filled($rooms[$index][$field] ?? null) || isset($used[$key]) || isset($assignedStackKeys[$key])) {
                    continue;
                }
                $rooms[$index][$field] = $pair['code'];
                $used[$key] = true;
                if (filled($rooms[$index]['floor_code'] ?? null)
                    && ($rooms[$index]['square_meters'] ?? null) !== null
                    && filled($rooms[$index]['room_name'] ?? null)) {
                    $rooms[$index]['needs_review'] = false;
                }
            }
        }

        return $rooms;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     * @param  list<array{code: string, kind: string, key: string, x: float, y: float, page: int}>  $codes
     * @param  list<array{keys: array<string, true>}>  $stacks
     * @param  array<string, true>  $assignedStackKeys
     * @param  array<string, int>  $roomsByNumber
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return list<array<string, mixed>>
     */
    private function assignLocalFloors(
        array $rooms,
        array $anchors,
        array $codes,
        array $stacks,
        array $assignedStackKeys,
        array $roomsByNumber,
        array $items,
        float $width,
        float $height,
        float $threshold,
    ): array {
        $stackedKeys = $assignedStackKeys;
        foreach ($stacks as $stack) {
            foreach ($stack['keys'] as $key => $_) {
                $stackedKeys[$key] = true;
            }
        }

        $maxDistance = $threshold * 2.5;
        foreach ($codes as $code) {
            if ($code['kind'] !== 'floor' || isset($stackedKeys[$code['key']])) {
                continue;
            }
            $best = null;
            $bestDistance = null;
            foreach ($anchors as $anchor) {
                if ((int) $code['page'] !== (int) $anchor['page']) {
                    continue;
                }
                $number = mb_strtolower((string) $anchor['room_number']);
                if (! isset($roomsByNumber[$number])) {
                    continue;
                }
                $distance = hypot((float) $code['x'] - (float) $anchor['x'], (float) $code['y'] - (float) $anchor['y']);
                if ($distance > $maxDistance) {
                    continue;
                }
                $second = $this->distanceToNextAnchor($code, $anchor, $anchors);
                if ($second <= $distance) {
                    continue;
                }
                if ($bestDistance !== null && $distance >= $bestDistance) {
                    continue;
                }
                $bestDistance = $distance;
                $best = $roomsByNumber[$number];
            }
            if ($best === null) {
                continue;
            }
            $primary = mb_strtolower((string) ($rooms[$best]['floor_code'] ?? ''));
            if ($primary === '' || $primary === $code['code']) {
                continue;
            }

            $area = $this->localAreaNear($code, $items, $rooms[$best]['square_meters'] ?? null);
            $rooms[$best]['extra_floors'][] = [
                'floor_code' => $code['code'],
                'square_meters' => $area,
                'page' => (int) $code['page'],
                'x' => (float) $code['x'],
                'y' => (float) $code['y'],
                'page_width' => $width,
                'page_height' => $height,
            ];
        }

        return $rooms;
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    private function syncFloors(array $room): array
    {
        $codes = [];
        $localAreas = [];
        $locals = [];
        if (filled($room['floor_code'] ?? null)) {
            $codes[] = (string) $room['floor_code'];
        }
        foreach ($room['extra_floors'] ?? [] as $extra) {
            $code = mb_strtolower(trim((string) ($extra['floor_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $codes[] = $code;
            $localAreas[] = is_numeric($extra['square_meters'] ?? null) ? (float) $extra['square_meters'] : null;
            $locals[$code] = $extra;
        }

        $floors = $this->floorFinishes->split($codes, is_numeric($room['square_meters'] ?? null) ? (float) $room['square_meters'] : null, $localAreas);
        foreach ($floors as $index => $finish) {
            if (($finish['role'] ?? '') !== FinishRole::Local->value) {
                continue;
            }
            $extra = $locals[mb_strtolower((string) ($finish['code'] ?? ''))] ?? null;
            if (! is_array($extra)) {
                continue;
            }
            $floors[$index]['page'] = (int) ($extra['page'] ?? 1);
            $floors[$index]['x'] = (float) ($extra['x'] ?? 0);
            $floors[$index]['y'] = (float) ($extra['y'] ?? 0);
            $floors[$index]['page_width'] = (float) ($extra['page_width'] ?? 0);
            $floors[$index]['page_height'] = (float) ($extra['page_height'] ?? 0);
        }

        $room['floors'] = $floors;
        $room['room_area'] = $room['square_meters'] ?? $room['room_area'] ?? null;

        return $room;
    }

    /**
     * @param  array{x: float, y: float, page: int}  $code
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     */
    private function localAreaNear(array $code, array $items, mixed $roomArea): ?float
    {
        $roomArea = is_numeric($roomArea) ? (float) $roomArea : null;
        $units = [];
        foreach ($items as $item) {
            if ((int) $item['page'] !== (int) $code['page'] || ! preg_match('/^m(?:²|2)$/iu', (string) $item['text'])) {
                continue;
            }
            $units[] = $item;
        }

        $best = null;
        $bestDistance = null;
        foreach ($items as $item) {
            if ((int) $item['page'] !== (int) $code['page'] || ! preg_match('/^\d{1,4}(?:[.,]\d+)?$/u', (string) $item['text'])) {
                continue;
            }
            $value = DutchNumber::parse($item['text']);
            if ($value === null || $value < 0.2 || $value > 500) {
                continue;
            }
            if ($roomArea !== null && abs($value - $roomArea) < 0.05) {
                continue;
            }
            if ($roomArea !== null && $value > $roomArea * 0.5) {
                continue;
            }
            $nearUnit = false;
            foreach ($units as $unit) {
                if (hypot((float) $unit['x'] - (float) $item['x'], (float) $unit['y'] - (float) $item['y']) <= 36) {
                    $nearUnit = true;
                    break;
                }
            }
            if (! $nearUnit) {
                continue;
            }
            $distance = hypot((float) $item['x'] - (float) $code['x'], (float) $item['y'] - (float) $code['y']);
            if ($distance > 48) {
                continue;
            }
            if ($bestDistance !== null && $distance >= $bestDistance) {
                continue;
            }
            $bestDistance = $distance;
            $best = $value;
        }

        return $best ?? $this->localAreaFromMillimetres($code, $items, $roomArea);
    }

    /**
     * @param  array{x: float, y: float, page: int}  $code
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     */
    private function localAreaFromMillimetres(array $code, array $items, ?float $roomArea): ?float
    {
        $horizontal = null;
        $vertical = null;
        $fallback = [];
        foreach ($items as $item) {
            $candidate = $this->nearbyMillimetre($code, $item, $items);
            if ($candidate === null) {
                continue;
            }
            $fallback[] = $candidate;
            if (abs($candidate['dx']) >= abs($candidate['dy'])) {
                if ($horizontal === null || $candidate['distance'] < $horizontal['distance']) {
                    $horizontal = $candidate;
                }
            } elseif ($vertical === null || $candidate['distance'] < $vertical['distance']) {
                $vertical = $candidate;
            }
        }

        $area = null;
        if ($horizontal !== null && $vertical !== null && $horizontal['mm'] !== $vertical['mm']) {
            $area = $this->millimetrePairArea($horizontal['mm'], $vertical['mm']);
        }
        if ($area === null || ! $this->isPlausibleLocalArea($area, $roomArea)) {
            $area = $this->closestMillimetrePairArea($fallback);
        }
        if ($area === null || ! $this->isPlausibleLocalArea($area, $roomArea)) {
            return null;
        }

        return $area;
    }

    /**
     * @param  array{x: float, y: float, page: int}  $code
     * @param  array{text: string, x: float, y: float, page: int}  $item
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return array{mm: int, x: float, y: float, dx: float, dy: float, distance: float}|null
     */
    private function nearbyMillimetre(array $code, array $item, array $items): ?array
    {
        if ((int) $item['page'] !== (int) $code['page'] || ! preg_match('/^\d{3,5}$/u', (string) $item['text'])) {
            return null;
        }
        $mm = (int) $item['text'];
        if ($mm < 400 || $mm > 12000) {
            return null;
        }
        $dx = (float) $item['x'] - (float) $code['x'];
        $dy = (float) $item['y'] - (float) $code['y'];
        $distance = hypot($dx, $dy);
        if ($distance > 200) {
            return null;
        }
        if ($this->nearDoorMark($item, $items)) {
            return null;
        }

        return [
            'mm' => $mm,
            'x' => (float) $item['x'],
            'y' => (float) $item['y'],
            'dx' => $dx,
            'dy' => $dy,
            'distance' => $distance,
        ];
    }

    /**
     * @param  list<array{mm: int, x: float, y: float, dx: float, dy: float, distance: float}>  $candidates
     */
    private function closestMillimetrePairArea(array $candidates): ?float
    {
        usort($candidates, fn (array $left, array $right): int => $left['distance'] <=> $right['distance']);
        if ($candidates === []) {
            return null;
        }
        $first = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate['mm'] === $first['mm'] || $this->millimetresShareAChain($first, $candidate)) {
                continue;
            }

            return $this->millimetrePairArea($first['mm'], $candidate['mm']);
        }

        return null;
    }

    /**
     * @param  array{x: float, y: float}  $left
     * @param  array{x: float, y: float}  $right
     */
    private function millimetresShareAChain(array $left, array $right): bool
    {
        return abs($left['x'] - $right['x']) <= 12 || abs($left['y'] - $right['y']) <= 12;
    }

    private function millimetrePairArea(int $length, int $width): float
    {
        return round(($length / 1000) * ($width / 1000), 2);
    }

    private function isPlausibleLocalArea(float $area, ?float $roomArea): bool
    {
        if ($area < 0.2) {
            return false;
        }
        if ($roomArea === null) {
            return true;
        }
        if (abs($area - $roomArea) < 0.05) {
            return false;
        }

        return $area <= $roomArea * 0.5;
    }

    /**
     * @param  array{text: string, x: float, y: float, page: int}  $item
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     */
    private function nearDoorMark(array $item, array $items): bool
    {
        foreach ($items as $other) {
            if ((int) $other['page'] !== (int) $item['page']) {
                continue;
            }
            if (! preg_match('/^dm$/iu', trim((string) $other['text']))) {
                continue;
            }
            if (hypot((float) $other['x'] - (float) $item['x'], (float) $other['y'] - (float) $item['y']) <= 24) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     * @param  list<array{code: string, kind: string, key: string, x: float, y: float, page: int}>  $codes
     * @param  list<array<string, mixed>>  $rooms
     * @param  array<string, int>  $roomsByNumber
     * @param  array<string, true>  $assignedStackKeys
     * @return list<array{index: int, code: string, key: string, distance: float}>
     */
    private function candidatePairs(
        array $anchors,
        array $codes,
        string $kind,
        array $rooms,
        array $roomsByNumber,
        string $field,
        float $threshold,
        array $assignedStackKeys = [],
    ): array {
        $pairs = [];
        $maxDistance = $threshold * 2.5;
        foreach ($anchors as $anchor) {
            $number = mb_strtolower((string) $anchor['room_number']);
            if (! isset($roomsByNumber[$number])) {
                continue;
            }
            $index = $roomsByNumber[$number];
            if (filled($rooms[$index][$field] ?? null)) {
                continue;
            }
            foreach ($codes as $code) {
                if ($code['kind'] !== $kind || (int) $code['page'] !== (int) $anchor['page']) {
                    continue;
                }
                if (isset($assignedStackKeys[$code['key']])) {
                    continue;
                }
                $distance = hypot((float) $code['x'] - (float) $anchor['x'], (float) $code['y'] - (float) $anchor['y']);
                if ($distance > $maxDistance) {
                    continue;
                }
                $second = $this->distanceToNextAnchor($code, $anchor, $anchors);
                if ($second <= $distance) {
                    continue;
                }
                if ($distance > $threshold && $second < $distance * 1.45) {
                    continue;
                }
                $pairs[] = [
                    'index' => $index,
                    'code' => $code['code'],
                    'key' => $code['key'],
                    'distance' => $distance,
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param  array{x: float, y: float, page: int}  $code
     * @param  array{x: float, y: float, page: int, room_number: string}  $anchor
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     */
    private function distanceToNextAnchor(array $code, array $anchor, array $anchors): float
    {
        $best = INF;
        foreach ($anchors as $other) {
            if ((int) $other['page'] !== (int) $anchor['page'] || $other['room_number'] === $anchor['room_number']) {
                continue;
            }
            $distance = hypot((float) $code['x'] - (float) $other['x'], (float) $code['y'] - (float) $other['y']);
            if ($distance < $best) {
                $best = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  array{text: string, x: float, y: float, page: int}  $item
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     */
    private function nearLegendCue(array $item, array $items): bool
    {
        if (str_contains($item['text'], '=')) {
            return true;
        }
        foreach ($items as $other) {
            if ((int) $other['page'] !== (int) $item['page']) {
                continue;
            }
            if (hypot((float) $other['x'] - (float) $item['x'], (float) $other['y'] - (float) $item['y']) > 90) {
                continue;
            }
            if (str_contains((string) $other['text'], '=') || preg_match('/marmoleum|forbo|gietvloer|tapijt|plakplint|holplint|renvooi|behang|vescom/iu', (string) $other['text'])) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeLegendItem(string $text): bool
    {
        return str_contains($text, '=')
            || (bool) preg_match('/marmoleum|forbo|gietvloer|tapijt|plakplint|holplint|renvooi/iu', $text);
    }

    private function roomNumberIn(string $text, bool $preferBuildingCodes = false): ?string
    {
        if (preg_match('/\b([A-Z]-\d{2}-\d{2})\b/u', $text, $match)) {
            return $match[1];
        }
        if ($preferBuildingCodes) {
            return null;
        }
        if (preg_match('/(?<![0-9])(\d{1,2}[.\-]\d{2}[a-zA-Z]?)(?![0-9.\-])/u', $text, $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function finishCodesIn(string $text, bool $includeWallAndCeiling = false): array
    {
        $pattern = $includeWallAndCeiling
            ? '/(?<![a-z0-9])((?:pl|v|w|p)\d{2}(?:\.[a-z0-9]+)?)(?![a-z0-9])/iu'
            : '/(?<![a-z0-9])((?:v|pl)\d{2}(?:\.[a-z0-9]+)?)(?![a-z0-9])/iu';
        preg_match_all($pattern, $text, $matches);
        $codes = [];
        foreach ($matches[1] as $raw) {
            $codes[] = mb_strtolower(trim((string) $raw));
        }

        return $codes;
    }

    private function codeKind(string $code): string
    {
        if (str_starts_with($code, 'pl')) {
            return 'plinth';
        }
        if (str_starts_with($code, 'v')) {
            return 'floor';
        }
        if (str_starts_with($code, 'w')) {
            return 'wall';
        }

        return 'ceiling';
    }
}
