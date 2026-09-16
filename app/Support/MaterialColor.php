<?php

namespace App\Support;

class MaterialColor
{
    public const UNKNOWN = '#9ca3af';

    /**
     * Vaste kleuren per volledige materiaalcode, niet per hoofdgroep.
     *
     * @var array<string, string>
     */
    private const CODE_COLORS = [
        'v01' => '#848482',
        'v01.a' => '#be0032',
        'v01.b' => '#f38400',
        'v01.c' => '#dcd300',
        'v01.d' => '#008856',
        'v01.e' => '#c026d3',
        'v01.f' => '#1d4ed8',
        'v01.g' => '#7c3aed',
        'v02' => '#e68fac',
        'v03' => '#5eead4',
        'v04' => '#8db600',
        'v05' => '#0067a5',
        'v06' => '#604e97',
        'v07' => '#882d17',
        'v08' => '#2b3d26',
        'v09' => '#222222',
        'v10' => '#b3446c',
    ];

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

    /**
     * Vaste kleur per vloer-/materiaalcode, gelijk op alle tekeningen.
     */
    public static function fromCode(?string $code, ?string $productName = null): string
    {
        $normalized = mb_strtolower(trim((string) $code));
        if ($normalized === '') {
            return self::resolve(null, $productName);
        }

        if (isset(self::CODE_COLORS[$normalized])) {
            return self::CODE_COLORS[$normalized];
        }

        return self::hashedColor($normalized);
    }

    private static function hashedColor(string $code): string
    {
        $seed = unpack('N', substr(hash('sha256', 'nicon-material:'.$code, true), 0, 4));
        $n = (int) ($seed[1] ?? 0);
        $hue = fmod(abs($n) * 0.38196601125, 360.0);
        $sat = 62 + abs($n % 16);
        $light = 36 + abs(($n >> 8) % 12);
        $hex = self::hslToHex($hue, $sat, $light);

        for ($step = 0; $step < 16; $step++) {
            $clash = false;
            foreach (self::CODE_COLORS as $taken) {
                if (self::hexesMatch($hex, $taken, 52)) {
                    $clash = true;
                    break;
                }
            }
            if (! $clash) {
                return $hex;
            }
            $hue = fmod($hue + 137.508, 360.0);
            $hex = self::hslToHex($hue, $sat, $light);
        }

        return $hex;
    }

    private static function hslToHex(float $hue, float $saturation, float $lightness): string
    {
        $h = fmod(($hue + 360.0), 360.0);
        $s = max(0.0, min(100.0, $saturation)) / 100;
        $l = max(0.0, min(100.0, $lightness)) / 100;
        $chroma = (1 - abs((2 * $l) - 1)) * $s;
        $x = $chroma * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - ($chroma / 2);
        [$r, $g, $b] = match (true) {
            $h < 60.0 => [$chroma, $x, 0.0],
            $h < 120.0 => [$x, $chroma, 0.0],
            $h < 180.0 => [0.0, $chroma, $x],
            $h < 240.0 => [0.0, $x, $chroma],
            $h < 300.0 => [$x, 0.0, $chroma],
            default => [$chroma, 0.0, $x],
        };

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        );
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
            str_contains($hay, 'english oak') || str_contains($hay, 'chapman oak') || str_contains($hay, 'classics') => '#c4a484',
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

    public static function hexesMatch(?string $left, ?string $right, int $tolerance = 36): bool
    {
        $a = self::normalizeHex($left);
        $b = self::normalizeHex($right);
        if ($a === null || $b === null) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $dr = hexdec(substr($a, 1, 2)) - hexdec(substr($b, 1, 2));
        $dg = hexdec(substr($a, 3, 2)) - hexdec(substr($b, 3, 2));
        $db = hexdec(substr($a, 5, 2)) - hexdec(substr($b, 5, 2));

        return sqrt(($dr ** 2) + ($dg ** 2) + ($db ** 2)) <= $tolerance;
    }
}
