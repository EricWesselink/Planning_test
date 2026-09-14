<?php

namespace App\Support;

class DutchMobileNumber
{
    /**
     * Normalise a Dutch mobile number to 06XXXXXXXX.
     *
     * Accepts values such as 0612345678, 06 12345678, 06-12345678 and +31612345678.
     */
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '31') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 2);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '6')) {
            $digits = '0'.$digits;
        }

        if (preg_match('/^06\d{8}$/', $digits) !== 1) {
            return null;
        }

        return $digits;
    }
}
