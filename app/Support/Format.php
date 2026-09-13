<?php

namespace App\Support;

use Carbon\CarbonInterface;

class Format
{
    public static function qty(float|int|string|null $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, ',', '.');
    }

    /**
     * Toon een hoeveelheid, of "Onbekend" wanneer de waarde niet uit de bron gelezen is.
     */
    public static function qtyOrUnknown(float|int|string|null $value, int $decimals = 0, bool $known = true): string
    {
        if (! $known || $value === null || $value === '') {
            return 'Onbekend';
        }

        return self::qty($value, $decimals);
    }

    public static function money(float|int|string|null $value): string
    {
        return '€ '.number_format((float) $value, 2, ',', '.');
    }

    public static function euro(float|int|string|null $value, int $decimals = 2): string
    {
        return '€'.number_format((float) $value, $decimals, ',', '.');
    }

    public static function euroWhole(float|int|string|null $value): string
    {
        $number = round((float) $value, 2);
        $decimals = abs($number - round($number)) < 0.001 ? 0 : 2;

        return self::euro($number, $decimals);
    }

    public static function bonOrdinal(int $number): string
    {
        return $number.'e bon';
    }

    public static function decimalInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        if ($value === '') {
            return $value;
        }

        if (str_contains($value, ',')) {
            return str_replace(',', '.', str_replace('.', '', $value));
        }

        return $value;
    }

    public static function hours(float|int|string|null $value): string
    {
        $number = (float) $value;
        $decimals = fmod($number, 1) === 0.0 ? 0 : 1;

        return self::qty($number, $decimals).' u';
    }

    public static function date(CarbonInterface $date): string
    {
        return $date->translatedFormat('D j M');
    }

    public static function dayAndWeek(CarbonInterface $from, ?CarbonInterface $to = null): string
    {
        $from = $from->copy()->startOfDay();
        $to = ($to ?? $from)->copy()->startOfDay();

        $fromDay = rtrim($from->translatedFormat('D'), '.');
        $toDay = rtrim($to->translatedFormat('D'), '.');

        $days = $from->equalTo($to)
            ? $fromDay.' '.$from->format('d-m-Y')
            : $fromDay.' '.$from->format('d-m-Y').' – '.$toDay.' '.$to->format('d-m-Y');

        $fromWeek = (int) $from->isoWeek();
        $toWeek = (int) $to->isoWeek();
        $fromYear = (int) $from->isoWeekYear();
        $toYear = (int) $to->isoWeekYear();

        if ($fromYear === $toYear && $fromWeek === $toWeek) {
            return $days.' · week '.$fromWeek;
        }

        if ($fromYear === $toYear) {
            return $days.' · week '.$fromWeek.'–'.$toWeek;
        }

        return $days.' · week '.$fromWeek.' '.$fromYear.' – week '.$toWeek.' '.$toYear;
    }

    /** @return list<string> */
    public static function workerPalette(): array
    {
        return [
            '#c2410c',
            '#1d4ed8',
            '#15803d',
            '#7c3aed',
            '#0f766e',
            '#be123c',
            '#a16207',
            '#0369a1',
            '#4d7c0f',
            '#c026d3',
            '#9a3412',
            '#4338ca',
            '#db2777',
            '#155e75',
            '#854d0e',
            '#1e3a8a',
            '#65a30d',
            '#e11d48',
        ];
    }

    /** Stable kleur per vakman of ploeg. */
    public static function planColor(int $id): string
    {
        $palette = self::workerPalette();

        return $palette[abs($id) % count($palette)];
    }

    public static function normalizeColor(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || ! preg_match('/^#[0-9a-f]{6}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $taken
     */
    public static function colorIsUsed(string $hex, array $taken): bool
    {
        $hex = self::normalizeColor($hex);
        if ($hex === null) {
            return false;
        }

        foreach ($taken as $value) {
            if (self::normalizeColor(is_string($value) ? $value : null) === $hex) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $taken
     */
    public static function nextDistinctColor(array $taken): string
    {
        $used = [];
        foreach ($taken as $value) {
            $hex = self::normalizeColor(is_string($value) ? $value : null);
            if ($hex !== null) {
                $used[$hex] = true;
            }
        }

        foreach (self::workerPalette() as $hex) {
            if (! isset($used[$hex])) {
                return $hex;
            }
        }

        $usedList = array_keys($used);
        for ($i = count($used); $i < count($used) + 80; $i++) {
            $hex = self::hslToHex(fmod($i * 137.508, 360), 72, 38);
            if (! isset($used[$hex]) && self::isDistinctFromUsed($hex, $usedList)) {
                return $hex;
            }
        }

        return sprintf('#%06x', (crc32((string) count($used)) & 0x7FFFFF) | 0x203000);
    }

    /**
     * @param  list<string>  $used
     */
    private static function isDistinctFromUsed(string $hex, array $used): bool
    {
        foreach ($used as $other) {
            if (self::colorDistance($hex, $other) < 90) {
                return false;
            }
        }

        return true;
    }

    private static function colorDistance(string $left, string $right): float
    {
        $lr = hexdec(substr($left, 1, 2));
        $lg = hexdec(substr($left, 3, 2));
        $lb = hexdec(substr($left, 5, 2));
        $rr = hexdec(substr($right, 1, 2));
        $rg = hexdec(substr($right, 3, 2));
        $rb = hexdec(substr($right, 5, 2));

        return sqrt((($lr - $rr) ** 2) + (($lg - $rg) ** 2) + (($lb - $rb) ** 2));
    }

    private static function hslToHex(float $hue, float $saturation, float $lightness): string
    {
        $saturation /= 100;
        $lightness /= 100;
        $chroma = (1 - abs((2 * $lightness) - 1)) * $saturation;
        $hue = fmod($hue, 360);
        if ($hue < 0) {
            $hue += 360;
        }
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $match = $lightness - ($chroma / 2);

        if ($hue < 60) {
            [$red, $green, $blue] = [$chroma, $x, 0.0];
        } elseif ($hue < 120) {
            [$red, $green, $blue] = [$x, $chroma, 0.0];
        } elseif ($hue < 180) {
            [$red, $green, $blue] = [0.0, $chroma, $x];
        } elseif ($hue < 240) {
            [$red, $green, $blue] = [0.0, $x, $chroma];
        } elseif ($hue < 300) {
            [$red, $green, $blue] = [$x, 0.0, $chroma];
        } else {
            [$red, $green, $blue] = [$chroma, 0.0, $x];
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round(($red + $match) * 255),
            (int) round(($green + $match) * 255),
            (int) round(($blue + $match) * 255),
        );
    }
}
