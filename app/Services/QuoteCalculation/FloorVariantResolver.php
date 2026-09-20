<?php

namespace App\Services\QuoteCalculation;

class FloorVariantResolver
{
    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array{code: string, product?: string, kind?: string}>  $legend
     * @param  list<array{page?: int, texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @return list<array<string, mixed>>
     */
    public function resolve(array $rooms, array $legend, array $pages = []): array
    {
        $exact = [];
        foreach ($legend as $entry) {
            $legendCode = mb_strtolower(trim((string) ($entry['code'] ?? '')));
            if ($legendCode !== '') {
                $exact[$legendCode] = true;
            }
        }
        $variants = $this->variantsByFamily($legend);
        if ($variants === [] || $rooms === []) {
            return $rooms;
        }

        $marks = $this->variantMarks($pages, $variants);
        $anchors = $this->anchors($rooms, $pages);
        foreach ($rooms as $index => $room) {
            $code = mb_strtolower(trim((string) ($room['floor_code'] ?? '')));
            if ($code === '' || FinishPairingRules::isSpecific($code) || isset($exact[$code])) {
                continue;
            }
            $options = $variants[$code] ?? [];
            if ($options === []) {
                continue;
            }
            $picked = $this->uniqueNearbyVariant($room, $index, $options, $marks, $anchors);
            if ($picked === null) {
                continue;
            }
            $rooms[$index]['floor_code'] = $picked;
            $rooms[$index] = $this->replaceMainFloorCode($rooms[$index], $code, $picked);
        }

        return $rooms;
    }

    /**
     * @param  list<array{code: string}>  $legend
     * @return array<string, list<string>>
     */
    private function variantsByFamily(array $legend): array
    {
        $variants = [];
        foreach ($legend as $entry) {
            $code = mb_strtolower(trim((string) ($entry['code'] ?? '')));
            if (! FinishPairingRules::isSpecific($code) || ! str_starts_with($code, 'v')) {
                continue;
            }
            $family = FinishPairingRules::family($code);
            $variants[$family][] = $code;
        }
        foreach ($variants as $family => $codes) {
            $variants[$family] = array_values(array_unique($codes));
        }

        return $variants;
    }

    /**
     * @param  list<array{page?: int, texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @param  array<string, list<string>>  $variants
     * @return list<array{code: string, x: float, y: float, page: int}>
     */
    private function variantMarks(array $pages, array $variants): array
    {
        $allowed = [];
        foreach ($variants as $codes) {
            foreach ($codes as $code) {
                $allowed[$code] = true;
            }
        }
        $marks = [];
        foreach ($pages as $page) {
            $items = $page['texts'] ?? [];
            foreach ($items as $item) {
                if ($this->looksLikeLegendItem((string) $item['text']) || $this->nearLegendCue($item, $items)) {
                    continue;
                }
                foreach ($this->floorCodesIn((string) $item['text']) as $code) {
                    if (! isset($allowed[$code])) {
                        continue;
                    }
                    $marks[] = [
                        'code' => $code,
                        'x' => (float) $item['x'],
                        'y' => (float) $item['y'],
                        'page' => (int) ($item['page'] ?? $page['page'] ?? 1),
                    ];
                }
            }
        }

        return $marks;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array{page?: int, texts?: list<array{text: string, x: float, y: float, page: int}>}>  $pages
     * @return array<int, list<array{x: float, y: float, page: int}>>
     */
    private function anchors(array $rooms, array $pages): array
    {
        $wanted = [];
        foreach ($rooms as $index => $room) {
            $number = mb_strtolower(trim((string) ($room['room_number'] ?? '')));
            if ($number !== '') {
                $wanted[$number] = $index;
            }
        }
        $anchors = [];
        foreach ($pages as $page) {
            foreach ($page['texts'] ?? [] as $item) {
                $number = $this->roomNumberIn((string) $item['text']);
                if ($number === null || ! isset($wanted[mb_strtolower($number)])) {
                    continue;
                }
                $index = $wanted[mb_strtolower($number)];
                $anchors[$index][] = [
                    'x' => (float) $item['x'],
                    'y' => (float) $item['y'],
                    'page' => (int) ($item['page'] ?? $page['page'] ?? 1),
                ];
            }
        }

        return $anchors;
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  list<string>  $options
     * @param  list<array{code: string, x: float, y: float, page: int}>  $marks
     * @param  array<int, list<array{x: float, y: float, page: int}>>  $anchors
     */
    private function uniqueNearbyVariant(array $room, int $index, array $options, array $marks, array $anchors): ?string
    {
        $here = $anchors[$index] ?? [];
        if ($here === []) {
            return null;
        }

        $hits = [];
        foreach ($marks as $mark) {
            if (! in_array($mark['code'], $options, true)) {
                continue;
            }
            $own = $this->nearestDistance($mark, $here);
            if ($own === null) {
                continue;
            }
            $other = INF;
            foreach ($anchors as $otherIndex => $points) {
                if ($otherIndex === $index) {
                    continue;
                }
                $distance = $this->nearestDistance($mark, $points);
                if ($distance !== null && $distance < $other) {
                    $other = $distance;
                }
            }
            if ($other + 6 < $own) {
                continue;
            }
            $hits[$mark['code']] = true;
        }
        $hits = array_keys($hits);
        if (count($hits) !== 1) {
            return null;
        }

        return $hits[0];
    }

    /**
     * @param  array{x: float, y: float, page: int}  $mark
     * @param  list<array{x: float, y: float, page: int}>  $points
     */
    private function nearestDistance(array $mark, array $points): ?float
    {
        $best = null;
        foreach ($points as $point) {
            if ((int) $point['page'] !== (int) $mark['page']) {
                continue;
            }
            $distance = hypot((float) $mark['x'] - (float) $point['x'], (float) $mark['y'] - (float) $point['y']);
            if ($best === null || $distance < $best) {
                $best = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    private function replaceMainFloorCode(array $room, string $from, string $to): array
    {
        $floors = $room['floors'] ?? [];
        foreach ($floors as $index => $finish) {
            $code = mb_strtolower(trim((string) ($finish['code'] ?? '')));
            $role = (string) ($finish['role'] ?? '');
            if ($code !== $from) {
                continue;
            }
            if ($role !== '' && $role !== 'main' && $index !== 0) {
                continue;
            }
            $floors[$index]['code'] = $to;
            break;
        }
        $room['floors'] = $floors;

        return $room;
    }

    /**
     * @return list<string>
     */
    private function floorCodesIn(string $text): array
    {
        preg_match_all('/(?<![a-z0-9])(v\d{2}(?:\.[a-z0-9]+)?)(?![a-z0-9])/iu', $text, $matches);
        $codes = [];
        foreach ($matches[1] as $raw) {
            $codes[] = mb_strtolower(trim((string) $raw));
        }

        return $codes;
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
            if (str_contains((string) $other['text'], '=') || preg_match('/marmoleum|forbo|gietvloer|tapijt|plakplint|holplint|renvooi/iu', (string) $other['text'])) {
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

    private function roomNumberIn(string $text): ?string
    {
        if (preg_match('/\b([A-Z]-\d{2}-\d{2})\b/u', $text, $match)) {
            return $match[1];
        }

        return null;
    }
}
