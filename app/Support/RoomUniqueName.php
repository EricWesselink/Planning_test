<?php

namespace App\Support;

use App\Models\ProjectArea;

class RoomUniqueName
{
    /**
     * Number duplicate display names so list and drawing overlays stay in sync.
     * Does not change stored room names.
     *
     * @param  list<array<string, mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    public static function assign(array $areas): array
    {
        $groups = [];
        foreach ($areas as $index => $area) {
            $groups[self::groupKey($area['name'] ?? '')][] = $index;
        }

        foreach ($groups as $indexes) {
            usort($indexes, function (int $left, int $right) use ($areas): int {
                return self::sortKey($areas[$left]) <=> self::sortKey($areas[$right]);
            });

            $count = count($indexes);
            foreach ($indexes as $sequence => $index) {
                $areas[$index]['unique_name'] = self::label(
                    (string) ($areas[$index]['name'] ?? ''),
                    $count,
                    $sequence + 1,
                );
            }
        }

        return $areas;
    }

    public static function sortKey(array $area): string
    {
        $hasPosition = isset($area['x'], $area['y'])
            && is_numeric($area['x'])
            && is_numeric($area['y']);

        return sprintf(
            '%03d-%s-%03d-%s-%s-%s-%010d',
            (int) ($area['floor_sort'] ?? 999),
            (string) ($area['floor'] ?? ''),
            $hasPosition ? (int) ($area['page'] ?? 1) : 999,
            $hasPosition ? sprintf('%08.4f', (float) $area['x']) : '9.9999',
            $hasPosition ? sprintf('%08.4f', (float) $area['y']) : '9.9999',
            ProjectArea::numberSortKey($area['number'] ?? ''),
            (int) ($area['id'] ?? 0),
        );
    }

    public static function groupKey(string $name): string
    {
        $key = mb_strtolower(trim($name));

        return $key !== '' ? $key : '__empty__';
    }

    public static function label(string $name, int $count, int $sequence): string
    {
        $name = trim($name);
        if ($count <= 1) {
            return $name;
        }

        $base = self::titled($name !== '' ? $name : 'Ruimte');

        return $base.' '.$sequence;
    }

    public static function titled(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }
}
