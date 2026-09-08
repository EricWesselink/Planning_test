<?php

namespace App\Support;

class PdfTextNormalizer
{
    public static function name(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $text = implode(' ', array_map(fn (string $part) => self::undouble($part), $parts));
        $text = mb_strtolower(trim($text));
        $text = str_replace(['/', '+', '_', ';', ':', '|', ',', '.'], ' ', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public static function undouble(string $value): string
    {
        $pairwise = self::pairwiseUndouble($value);
        if ($pairwise !== $value) {
            return trim($pairwise);
        }

        return $value;
    }

    public static function pairwiseUndouble(string $value): string
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

    public static function collapseRuns(string $value): string
    {
        return preg_replace('/(.)\1+/u', '$1', $value) ?? $value;
    }

    public static function extractSquareMeters(string $text): ?float
    {
        $text = self::undouble($text);
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*m(?:²|2)\b/u', $text, $match)) {
            return null;
        }

        return DutchNumber::parse($match[1]);
    }

    public static function stripTrailingIndex(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^(.*?)(?:\s+\d{1,3})$/u', $name, $match) !== 1) {
            return $name;
        }
        $base = trim($match[1]);
        if (mb_strlen($base) < 5 || preg_match('/\d+[.\-]\d+/u', $base) === 1) {
            return $name;
        }

        return $base;
    }
}
