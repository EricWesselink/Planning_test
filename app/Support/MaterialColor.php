<?php

namespace App\Support;

class MaterialColor
{
    public const UNKNOWN = '#9ca3af';

    /**
     * Centrale materiaalkleur: opgeslagen hex uit legenda/import, anders
     * deterministische productnaam-mapping, anders neutraal grijs.
     */
    public static function resolve(?string $storedHex = null, ?string $productName = null): string
    {
        $normalized = self::normalizeHex($storedHex);
        if ($normalized !== null) {
            return $normalized;
        }

        $fromName = self::fromProductName($productName);
        if ($fromName !== null) {
            return $fromName;
        }

        return self::UNKNOWN;
    }

    public static function normalizeHex(?string $hex): ?string
    {
        if ($hex === null) {
            return null;
        }
        $hex = strtolower(trim($hex));
        if (! preg_match('/^#?[0-9a-f]{6}$/', $hex)) {
            return null;
        }

        return '#'.ltrim($hex, '#');
    }

    public static function fromProductName(?string $name): ?string
    {
        $hay = mb_strtolower(trim((string) $name));
        if ($hay === '') {
            return null;
        }

        return match (true) {
            str_contains($hay, 'dark sand') => '#8d8676',
            str_contains($hay, 'desso') || str_contains($hay, 'desert') => '#9b5b5b',
            str_contains($hay, 'warm grey') || str_contains($hay, 'warm gray') => '#c9c985',
            str_contains($hay, 'pink clay') => '#eea8f5',
            str_contains($hay, 'aqua blue') => '#5a90ba',
            str_contains($hay, 'english oak') || str_contains($hay, 'classics') => '#c4a484',
            str_contains($hay, 'granit light') => '#558d60',
            str_contains($hay, 'dusty brick') => '#795549',
            str_contains($hay, 'dusty green') => '#647d66',
            str_contains($hay, 'light blue') => '#aad9ff',
            str_contains($hay, 'light green') => '#9cf593',
            preg_match('/\bnatural[-\s]?blue\b/', $hay) === 1 => '#3f51b5',
            str_contains($hay, 'natural-black') || str_contains($hay, 'iq natural-black') => '#f59e93',
            str_contains($hay, 'coral') || str_contains($hay, 'entreemat') => '#2b3033',
            str_contains($hay, 'vloercoating') || (str_contains($hay, 'coating') && ! str_contains($hay, 'giet')) => '#fbfa05',
            str_contains($hay, 'antislip') => '#fa050b',
            str_contains($hay, 'gietvloer') && ! str_contains($hay, 'antislip') => '#d4d6c2',
            str_contains($hay, 'directie') => '#d6e4eb',
            str_contains($hay, 'marmoleum') || str_contains($hay, 'linoleum') => '#c4a06a',
            str_contains($hay, 'primen') || str_contains($hay, 'egal') => '#5b7c99',
            str_contains($hay, 'plint') => '#65a30d',
            default => null,
        };
    }

    /**
     * Lichte tint voor kaartrand/achtergrond; tekst blijft leesbaar.
     */
    public static function softBackground(string $hex, float $alpha = 0.14): string
    {
        $hex = self::normalizeHex($hex) ?? self::UNKNOWN;
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        return sprintf('rgba(%d, %d, %d, %.2f)', $r, $g, $b, max(0.05, min(0.35, $alpha)));
    }
}
