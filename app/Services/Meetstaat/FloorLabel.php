<?php

namespace App\Services\Meetstaat;

/**
 * Generieke bouwlaaglabels uit header, bestandsnaam, tekeningpagina of ruimtenummerprefix.
 * Geen projectnamen: 03.08 → verdieping 3, 0.07 → begane grond, kelder blijft kelder.
 */
class FloorLabel
{
    public function isUnknown(?string $floor): bool
    {
        $floor = mb_strtolower(trim((string) $floor));

        return $floor === '' || $floor === 'onbekend';
    }

    public function canonical(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $flat = mb_strtolower(trim($text));
        if ($flat === '') {
            return null;
        }

        $phase = null;
        if (preg_match('/\bfase\s*(\d+)\b/u', $flat, $match)) {
            $phase = 'fase '.$match[1];
        }

        $base = $this->storeyName($flat);
        if ($base === null) {
            return null;
        }

        if ($phase !== null) {
            if (str_contains($flat, 'souterrain')) {
                return $phase.' souterrain';
            }

            return $phase.' '.$base;
        }

        return $base;
    }

    /**
     * Basisbouwlaag zonder fase/sectie: "fase 1 verdieping 1" → "verdieping 1",
     * "fase 1 souterrain" → "kelder", "begane grond sporthal" → "begane grond".
     */
    public function base(?string $floor): string
    {
        $flat = mb_strtolower(trim((string) $floor));
        if ($flat === '' || $flat === 'onbekend') {
            return $flat;
        }
        $flat = preg_replace('/\bfase\s*\d+\b/u', '', $flat) ?? $flat;
        $flat = preg_replace('/\bsporthal\b/u', '', $flat) ?? $flat;
        $flat = trim(preg_replace('/\s+/u', ' ', $flat) ?? $flat);
        if (str_contains($flat, 'souterrain')) {
            $flat = trim(str_replace('souterrain', 'kelder', $flat));
        }

        return $this->canonical($flat) ?? $flat;
    }

    public function sameStorey(?string $left, ?string $right): bool
    {
        $leftBase = $this->base($left);
        $rightBase = $this->base($right);
        if ($leftBase === '' || $rightBase === '' || $leftBase === 'onbekend' || $rightBase === 'onbekend') {
            return true;
        }

        return $leftBase === $rightBase;
    }

    private function storeyName(string $flat): ?string
    {
        if (preg_match('/\b(tussenlaag|middenlaag)\b/u', $flat)) {
            return 'tussenlaag';
        }
        if (preg_match('/begane\s*grond/u', $flat)) {
            return 'begane grond';
        }
        if (preg_match('/installatie\s*ruimt/u', $flat)) {
            return 'installatie ruimten';
        }
        if (preg_match('/\b(kelder|souterrain)\b/u', $flat)) {
            return 'kelder';
        }
        if (preg_match('/verdieping\s*(\d+)\b/u', $flat, $match)) {
            return 'verdieping '.$match[1];
        }
        if (preg_match('/\b(\d+)e?\s*verdieping\b/u', $flat, $match)) {
            return 'verdieping '.$match[1];
        }

        return null;
    }

    public function fromFilename(?string $name): ?string
    {
        if (! is_string($name) || $name === '') {
            return null;
        }

        $flat = mb_strtolower($name);
        $fromWords = $this->canonical($flat);
        if ($fromWords !== null) {
            return $fromWords;
        }
        if (preg_match('/\bbg\b/u', $flat) || preg_match('/begane/u', $flat)) {
            return 'begane grond';
        }
        if (preg_match('/installatie/u', $flat)) {
            return 'installatie ruimten';
        }
        if (preg_match('/(?:^|[_\-\s])(\d+)(?:e|de)?(?:[_\-\s]?verd)/u', $flat, $match)) {
            return 'verdieping '.$match[1];
        }

        return null;
    }

    public function fromRoomNumber(?string $number): ?string
    {
        $storey = $this->storeyPrefix((string) $number);
        if ($storey === null) {
            return null;
        }
        if ($storey === 0) {
            return 'begane grond';
        }

        return 'verdieping '.$storey;
    }

    public function storeyPrefix(string $number): ?int
    {
        $number = mb_strtolower(trim($number));
        if ($number === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})[.\-]\d+/u', $number, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    public function numberPlausibleForFloor(string $number, string $floor): bool
    {
        $floor = mb_strtolower(trim($floor));
        if ($this->isUnknown($floor)) {
            return true;
        }
        $storey = $this->storeyPrefix($number);
        if ($storey === null) {
            return false;
        }
        if (str_contains($floor, 'kelder') || str_contains($floor, 'souterrain')) {
            return true;
        }
        if (str_contains($floor, 'begane') || $floor === 'bg') {
            return $storey === 0;
        }
        if (preg_match('/verdieping\s*(\d+)/u', $floor, $match)) {
            return $storey === (int) $match[1];
        }
        if (preg_match('/\b(\d+)e\b/u', $floor, $match)) {
            return $storey === (int) $match[1];
        }

        return true;
    }
}
