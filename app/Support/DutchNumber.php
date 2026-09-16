<?php

namespace App\Support;

class DutchNumber
{
    public static function parse(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-' || strcasecmp($raw, 'n.v.t.') === 0) {
            return null;
        }

        $raw = str_replace(["\xc2\xa0", ' '], '', $raw);
        $raw = str_ireplace(['m²', 'm2', 'm¹', 'm1', 'lm'], '', $raw);

        $looksLikeGroupedThousands = (bool) preg_match('/^-?\d{1,3}(\.\d{3})+$/', $raw);

        if (is_numeric($raw) && ! $looksLikeGroupedThousands) {
            return (float) $raw;
        }

        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');
        $raw = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';

        if ($raw === '' || $raw === '-' || $raw === ',' || $raw === '.') {
            return null;
        }

        $comma = strrpos($raw, ',');
        $dot = strrpos($raw, '.');

        if ($comma !== false && $dot !== false) {
            if ($comma > $dot) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        } elseif ($comma !== false) {
            $decimals = strlen($raw) - $comma - 1;
            $raw = $decimals <= 2
                ? str_replace(',', '.', $raw)
                : str_replace(',', '', $raw);
        } elseif ($dot !== false) {
            $decimals = strlen($raw) - $dot - 1;
            if ($decimals === 3 && ! str_contains(substr($raw, 0, $dot), '.')) {
                $raw = str_replace('.', '', $raw);
            }
        }

        if (! is_numeric($raw)) {
            return null;
        }

        $number = (float) $raw;

        return $negative ? -$number : $number;
    }

    /**
     * Spreadsheet cells use a machine decimal point. "1.105" is 1.105 m, not 1.105 as Dutch thousands.
     */
    public static function fromMachine(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, ',')) {
            return self::parse($raw);
        }

        $raw = str_replace(["\xc2\xa0", ' '], '', $raw);
        $raw = str_ireplace(['m²', 'm2', 'm¹', 'm1', 'lm'], '', $raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return self::parse($value);
        }

        return (float) $raw;
    }
}
