<?php

namespace App\Support;

class WorkType
{
    public static function looksLikeRoom(string $name): bool
    {
        $name = trim($name);
        // Product-/werkcodes zoals 43.20.02 en 43.20.01a zijn geen ruimtenummers (0.07 / 2.01 wel).
        if (preg_match('/^\d{2,}\.\d{2}\.\d{2}[a-z]?\b/iu', $name) === 1) {
            return false;
        }

        return (bool) preg_match('/^\d+[.\-]\d+/', $name);
    }

    public static function labelFromName(string $name, ?string $fallback = null): string
    {
        $name = trim($name);
        if ($name === '' || self::looksLikeRoom($name)) {
            return $fallback ?: 'Vloer';
        }

        return self::knownType($name) ?? $name;
    }

    public static function productFromName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || self::looksLikeRoom($name)) {
            return null;
        }

        $type = self::knownType($name);
        if ($type === null) {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $name)), fn ($part) => $part !== ''));
        if (count($parts) >= 2 && self::knownType((string) end($parts)) !== null) {
            array_pop($parts);
            $product = trim(implode(', ', $parts));
        } else {
            $product = $name;
        }

        if ($product === '' || strcasecmp($product, $type) === 0) {
            return null;
        }

        if (str_starts_with(mb_strtolower($product), mb_strtolower($type))) {
            return null;
        }

        return $product;
    }

    public static function knownType(string $value): ?string
    {
        if (str_contains(mb_strtolower($value), 'gietvloer')) {
            return 'Gietvloer';
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($part) => $part !== ''));
        if (count($parts) >= 2) {
            $fromLast = self::matchType((string) end($parts));
            if ($fromLast !== null) {
                return $fromLast;
            }
        }

        return self::matchType($value);
    }

    public static function isWindowCovering(string $name): bool
    {
        return in_array(self::knownType($name), ['Screens', 'Rolgordijnen', 'Jaloezieën', 'Zonwering'], true);
    }

    /**
     * Of deze werksoort/productnaam een geëgaliseerde ondergrond vereist.
     * Linoleum, PVC en vinyl wel; tapijt, tapijttegels, schoonloopmat, coating, gietvloer en plinten niet.
     */
    public static function requiresPrimingLeveling(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || self::looksLikeRoom($name)) {
            return false;
        }

        $type = self::knownType($name);
        if ($type === null) {
            return false;
        }

        $categories = [];
        if (function_exists('app')) {
            try {
                if (app()->bound('config')) {
                    $loaded = config('flooring.categories', []);
                    $categories = is_array($loaded) ? $loaded : [];
                }
            } catch (\Throwable) {
                $categories = [];
            }
        }
        if ($categories === []) {
            $categories = self::defaultCategoryFlags();
        }

        if (! isset($categories[$type]) || ! is_array($categories[$type])) {
            return false;
        }

        return (bool) ($categories[$type]['requires_priming_leveling'] ?? false);
    }

    /**
     * @return array<string, array{requires_priming_leveling: bool}>
     */
    private static function defaultCategoryFlags(): array
    {
        return [
            'Linoleum' => ['requires_priming_leveling' => true],
            'PVC' => ['requires_priming_leveling' => true],
            'Vinyl' => ['requires_priming_leveling' => true],
            'Gietvloer' => ['requires_priming_leveling' => false],
            'Coating' => ['requires_priming_leveling' => false],
            'Tapijt' => ['requires_priming_leveling' => false],
            'Entreemat' => ['requires_priming_leveling' => false],
            'Plinten' => ['requires_priming_leveling' => false],
        ];
    }

    private static function matchType(string $value): ?string
    {
        $flat = mb_strtolower($value);

        return match (true) {
            str_contains($flat, 'plint') => 'Plinten',
            str_contains($flat, 'screen') => 'Screens',
            str_contains($flat, 'rolgordijn') => 'Rolgordijnen',
            str_contains($flat, 'jaloezie') => 'Jaloezieën',
            str_contains($flat, 'zonwer') => 'Zonwering',
            str_contains($flat, 'linoleum') || str_contains($flat, 'marmoleum') => 'Linoleum',
            str_contains($flat, 'pvc') => 'PVC',
            str_contains($flat, 'entreemat') || str_contains($flat, 'coral') || str_contains($flat, 'schoonloop') => 'Entreemat',
            str_contains($flat, 'gietvloer') => 'Gietvloer',
            str_contains($flat, 'coating') => 'Coating',
            str_contains($flat, 'tapijt') => 'Tapijt',
            str_contains($flat, 'vinyl') => 'Vinyl',
            default => null,
        };
    }
}
