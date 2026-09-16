<?php

namespace App\Services\QuoteCalculation;

use App\Support\DutchNumber;

class SpatialRoomAssembler
{
    public function __construct(private SpatialFinishLinker $linker = new SpatialFinishLinker) {}

    /**
     * @param  list<array{page: int, width: float, height: float, texts: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @param  list<array{code: string, product: string, kind: string}>  $legend
     * @return list<array<string, mixed>>
     */
    public function assemble(array $pages, array $legend = []): array
    {
        $rooms = [];
        foreach ($pages as $page) {
            $items = $page['texts'] ?? [];
            if ($items === []) {
                continue;
            }
            $threshold = hypot((float) ($page['width'] ?? 1), (float) ($page['height'] ?? 1)) * 0.10;
            $anchors = $this->roomAnchors($items);
            $areas = $this->areaItems($items, (int) ($page['page'] ?? 1));
            $names = $this->nameItems($items);
            $usedAreas = [];

            foreach ($anchors as $anchor) {
                $area = $this->nearestUnused($anchor, $areas, $usedAreas, $threshold, $anchors);
                $name = $this->nameCluster($anchor, $names, $items, $threshold, $anchors);

                if ($area === null && $name === null) {
                    continue;
                }

                if ($area !== null) {
                    $usedAreas[$area['key']] = true;
                }

                $rooms[] = [
                    'room_number' => $anchor['room_number'],
                    'room_name' => $name,
                    'square_meters' => $area['square_meters'] ?? null,
                    'floor_code' => null,
                    'plinth_code' => null,
                    'plinth_meters' => null,
                    'plinth_source' => 'review',
                    'extra_codes' => [],
                    'note' => null,
                    'needs_review' => $name === null || $area === null,
                ];
            }

            $rooms = $this->linker->assignPage($rooms, $page);
        }

        unset($legend);

        return $rooms;
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return list<array{text: string, x: float, y: float, page: int, room_number: string}>
     */
    private function roomAnchors(array $items): array
    {
        $anchors = [];
        $preferBuildingCodes = false;
        foreach ($items as $item) {
            if (preg_match('/^([A-Z]-\d{2}-\d{2})$/u', $item['text'])) {
                $preferBuildingCodes = true;
                break;
            }
        }
        foreach ($items as $item) {
            $text = $item['text'];
            if (preg_match('/^([A-Z]-\d{2}-\d{2})$/u', $text, $match)) {
                $anchors[] = $item + ['room_number' => $match[1]];

                continue;
            }
            if ($preferBuildingCodes || ! preg_match('/^(\d{1,2}[.\-]\d{2}[a-zA-Z]?)$/u', $text, $match)) {
                continue;
            }
            if ($this->hasNearbySquareMeterUnit($item, $items)) {
                continue;
            }
            $anchors[] = $item + ['room_number' => $match[1]];
        }

        return $anchors;
    }

    /**
     * @param  array{text: string, x: float, y: float, page: int}  $item
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     */
    private function hasNearbySquareMeterUnit(array $item, array $items): bool
    {
        foreach ($items as $other) {
            if ($other['page'] !== $item['page'] || ! preg_match('/^m(?:²|2)$/iu', $other['text'])) {
                continue;
            }
            if (hypot($other['x'] - $item['x'], $other['y'] - $item['y']) <= 36) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return list<array{text: string, x: float, y: float, page: int, square_meters: float, key: string}>
     */
    private function areaItems(array $items, int $page): array
    {
        $units = [];
        foreach ($items as $item) {
            if (preg_match('/^m(?:²|2)$/iu', $item['text'])) {
                $units[] = $item;
            }
        }

        $areas = [];
        foreach ($items as $offset => $item) {
            if (! preg_match('/^\d{1,4}(?:[.,]\d+)?$/u', $item['text'])) {
                continue;
            }
            $value = DutchNumber::parse($item['text']);
            if ($value === null || $value < 0.4 || $value > 5000) {
                continue;
            }
            foreach ($units as $unit) {
                if ($unit['page'] !== $item['page']) {
                    continue;
                }
                if (hypot($unit['x'] - $item['x'], $unit['y'] - $item['y']) > 36) {
                    continue;
                }
                $areas[] = $item + [
                    'square_meters' => $value,
                    'key' => $page.':a:'.$offset,
                ];
                break;
            }
        }

        return $areas;
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @return list<array{text: string, x: float, y: float, page: int}>
     */
    private function nameItems(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            $text = $item['text'];
            if (! preg_match('/^[A-Za-zÀ-ÿ][A-Za-zÀ-ÿ0-9 \/,-]{1,40}$/u', $text)) {
                continue;
            }
            if ($this->isNoiseName($text) || $this->isFinishCode($text) || $this->looksLikeRoomNumber($text) || $this->looksLikeFinishStack($text)) {
                continue;
            }
            $names[] = $item;
        }

        return $names;
    }

    /**
     * @param  array{x: float, y: float, page: int, room_number: string}  $anchor
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, true>  $used
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     * @return array<string, mixed>|null
     */
    private function nearestUnused(array $anchor, array $candidates, array $used, float $threshold, array $anchors): ?array
    {
        $best = null;
        $bestDistance = null;
        foreach ($candidates as $candidate) {
            $key = (string) ($candidate['key'] ?? '');
            if ($key !== '' && isset($used[$key])) {
                continue;
            }
            if ((int) ($candidate['page'] ?? 0) !== (int) $anchor['page']) {
                continue;
            }
            $distance = hypot((float) $candidate['x'] - $anchor['x'], (float) $candidate['y'] - $anchor['y']);
            if ($distance > $threshold) {
                continue;
            }
            $aligned = hypot(((float) $candidate['x'] - $anchor['x']) * 1.35, (float) $candidate['y'] - $anchor['y']);
            if ($this->closerToOtherAnchor($candidate, $anchor, $anchors, $distance)) {
                continue;
            }
            if ($bestDistance === null || $aligned < $bestDistance) {
                $bestDistance = $aligned;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array{x: float, y: float, page: int, room_number: string}  $anchor
     * @param  list<array{text: string, x: float, y: float, page: int}>  $names
     * @param  list<array{text: string, x: float, y: float, page: int}>  $items
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     */
    private function nameCluster(array $anchor, array $names, array $items, float $threshold, array $anchors): ?string
    {
        $parts = [];
        foreach ($names as $name) {
            if ((int) $name['page'] !== (int) $anchor['page']) {
                continue;
            }
            $distance = hypot($name['x'] - $anchor['x'], $name['y'] - $anchor['y']);
            if ($distance > $threshold) {
                continue;
            }
            if ($this->closerToOtherAnchor($name, $anchor, $anchors, $distance)) {
                continue;
            }
            $parts[] = $name;
        }
        foreach ($items as $item) {
            if ((int) $item['page'] !== (int) $anchor['page'] || ! preg_match('/^[A-Za-zÀ-ÿ]$/u', (string) $item['text'])) {
                continue;
            }
            $distance = hypot($item['x'] - $anchor['x'], $item['y'] - $anchor['y']);
            if ($distance > 40 || $this->closerToOtherAnchor($item, $anchor, $anchors, $distance)) {
                continue;
            }
            $parts[] = $item;
        }
        if ($parts === []) {
            return null;
        }

        usort($parts, function (array $left, array $right): int {
            $y = $left['y'] <=> $right['y'];

            return $y !== 0 ? $y : $left['x'] <=> $right['x'];
        });

        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', array_map(fn (array $part) => (string) $part['text'], $parts))) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * @param  array{x: float, y: float, page: int}  $candidate
     * @param  array{x: float, y: float, page: int, room_number: string}  $anchor
     * @param  list<array{x: float, y: float, page: int, room_number: string}>  $anchors
     */
    private function closerToOtherAnchor(array $candidate, array $anchor, array $anchors, float $distance): bool
    {
        foreach ($anchors as $other) {
            if ($other['page'] !== $anchor['page'] || $other['room_number'] === $anchor['room_number']) {
                continue;
            }
            $otherDistance = hypot((float) $candidate['x'] - $other['x'], (float) $candidate['y'] - $other['y']);
            if ($otherDistance + 6 < $distance) {
                return true;
            }
        }

        return false;
    }

    private function isFinishCode(string $text): bool
    {
        return (bool) preg_match('/^(?:v|pl|w|p)\d{2}(?:\.[a-z0-9]+)?$/iu', $text);
    }

    private function looksLikeFinishStack(string $text): bool
    {
        return (bool) preg_match('/(?:v|pl|w|p)\d{2}/iu', $text);
    }

    private function looksLikeRoomNumber(string $text): bool
    {
        return (bool) preg_match('/^[A-Z]-\d{2}-\d{2}$/u', $text)
            || (bool) preg_match('/^\d{1,2}[.\-]\d{1,3}[a-zA-Z]?$/u', $text);
    }

    private function isNoiseName(string $text): bool
    {
        return (bool) preg_match('/^(renvooi|legenda|disclaimer|schaal|noord|datum|formaat|getekend|project|adres|gebouw|verdieping|conform|minuten|brandwerend|m(?:²|2|¹|1))$/iu', $text);
    }
}
