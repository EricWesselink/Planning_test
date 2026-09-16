<?php

namespace App\Services\QuoteCalculation;

class RoomNumberRange
{
    /**
     * Expand a verzamelnummer such as A-00-14-17 into A-00-14 … A-00-17.
     *
     * @return list<string>
     */
    public function expand(mixed $number): array
    {
        $number = trim((string) $number);
        if ($number === '') {
            return [];
        }
        if (! preg_match('/^([A-Z]-\d{1,2}-)(\d{2})[\/-](\d{2})$/u', $number, $match)) {
            return [$number];
        }

        $prefix = $match[1];
        $from = (int) $match[2];
        $to = (int) $match[3];
        if ($to < $from || ($to - $from) > 30) {
            return [$number];
        }

        $numbers = [];
        for ($value = $from; $value <= $to; $value++) {
            $numbers[] = $prefix.str_pad((string) $value, 2, '0', STR_PAD_LEFT);
        }

        return $numbers;
    }
}
