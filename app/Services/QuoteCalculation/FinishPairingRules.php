<?php

namespace App\Services\QuoteCalculation;

class FinishPairingRules
{
    public const GIETVLOER = 'v04';

    public const HOLPLINT = 'pl02';

    public const PLAKPLINT = 'pl01';

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
