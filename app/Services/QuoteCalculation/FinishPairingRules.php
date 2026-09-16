<?php

namespace App\Services\QuoteCalculation;

class FinishPairingRules
{
    public const GIETVLOER = 'v04';

    public const HOLPLINT = 'pl02';

    public const PLAKPLINT = 'pl01';

    public static function family(string $code): string
    {
        $code = mb_strtolower(trim($code));
        $position = mb_strpos($code, '.');
        if ($position === false) {
            return $code;
        }

        return mb_substr($code, 0, $position);
    }

    public static function isSpecific(string $code): bool
    {
        return str_contains(mb_strtolower(trim($code)), '.');
    }

    public static function compatible(string $left, string $right): bool
    {
        $left = mb_strtolower(trim($left));
        $right = mb_strtolower(trim($right));
        if ($left === '' || $right === '') {
            return $left === $right;
        }
        if ($left === $right) {
            return true;
        }
        if (self::family($left) !== self::family($right)) {
            return false;
        }

        return ! self::isSpecific($left) || ! self::isSpecific($right);
    }

    public static function prefer(?string $current, ?string $incoming): ?string
    {
        $current = is_string($current) ? mb_strtolower(trim($current)) : '';
        $incoming = is_string($incoming) ? mb_strtolower(trim($incoming)) : '';
        if ($incoming === '') {
            return $current === '' ? null : $current;
        }
        if ($current === '') {
            return $incoming;
        }
        if ($current === $incoming) {
            return $current;
        }
        if (self::compatible($current, $incoming)) {
            return self::isSpecific($incoming) ? $incoming : $current;
        }

        return $incoming;
    }

    public static function hasVariants(string $code, array $legendCodes): bool
    {
        $code = mb_strtolower(trim($code));
        if ($code === '' || self::isSpecific($code)) {
            return false;
        }
        $prefix = $code.'.';
        foreach ($legendCodes as $legendCode) {
            if (str_starts_with(mb_strtolower(trim((string) $legendCode)), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{code?: string, product?: string}>  $legend
     * @return array<string, list<array{code: string, product: string}>>
     */
    public static function variantsFromLegend(array $legend): array
    {
        $grouped = [];
        foreach ($legend as $entry) {
            $code = mb_strtolower(trim((string) ($entry['code'] ?? '')));
            if ($code === '' || ! self::isSpecific($code) || ! str_starts_with($code, 'v')) {
                continue;
            }
            $family = self::family($code);
            $grouped[$family][$code] = [
                'code' => $code,
                'product' => trim((string) ($entry['product'] ?? '')),
            ];
        }

        foreach ($grouped as $family => $variants) {
            ksort($variants, SORT_NATURAL);
            $grouped[$family] = array_values($variants);
        }
        ksort($grouped, SORT_NATURAL);

        return $grouped;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<array{code: string, product: string, kind: string}>  $legend
     * @return list<array<string, mixed>>
     */
    public function apply(array $rooms, array $legend): array
    {
        $legendMap = [];
        foreach ($legend as $entry) {
            $legendMap[mb_strtolower($entry['code'])] = $entry['product'];
        }

        foreach ($rooms as $index => $room) {
            $floor = mb_strtolower(trim((string) ($room['floor_code'] ?? '')));
            $plinth = mb_strtolower(trim((string) ($room['plinth_code'] ?? '')));
            $inferred = false;
            if ($plinth === '') {
                if ($floor !== self::GIETVLOER) {
                    continue;
                }
                $plinth = self::HOLPLINT;
                $rooms[$index]['plinth_code'] = self::HOLPLINT;
                $inferred = true;
            }

            $rooms[$index]['plinth_inferred'] = $inferred;
            $product = self::productFor($plinth, $legendMap, $room['plinth_product'] ?? null);
            if ($product === null || mb_strtolower(trim($product)) === $plinth) {
                $product = $plinth === self::HOLPLINT ? 'Holplint' : null;
            }
            if ($product !== null) {
                $rooms[$index]['plinth_product'] = $product;
            } else {
                unset($rooms[$index]['plinth_product']);
            }
        }

        return $rooms;
    }

    /**
     * @param  array<string, string>  $legendMap
     */
    public static function productFor(string $code, array $legendMap, mixed $fallback = null): ?string
    {
        $code = mb_strtolower(trim($code));
        if ($code === '') {
            return null;
        }
        $expected = $legendMap[$code] ?? null;
        if (is_string($expected) && $expected !== '' && ! self::productConflictsWithCode($code, $expected, $legendMap)) {
            return $expected;
        }
        if (! is_string($fallback) || trim($fallback) === '') {
            return null;
        }
        $fallback = trim($fallback);
        if (self::productConflictsWithCode($code, $fallback, $legendMap)) {
            return null;
        }

        return $fallback;
    }

    /**
     * @param  array<string, string>  $legendMap
     */
    private static function productConflictsWithCode(string $code, string $product, array $legendMap): bool
    {
        if ($code === self::PLAKPLINT && preg_match('/holplint/iu', $product)) {
            return true;
        }
        if ($code === self::HOLPLINT && preg_match('/plakplint/iu', $product)) {
            return true;
        }
        foreach ($legendMap as $otherCode => $otherProduct) {
            if (mb_strtolower((string) $otherCode) === $code || ! is_string($otherProduct)) {
                continue;
            }
            if (mb_strtolower($otherProduct) === mb_strtolower($product)) {
                return true;
            }
        }

        return false;
    }
}
