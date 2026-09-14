<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Key used to match a typed login against a stored number.
     *
     * Dutch mobiles become 06XXXXXXXX. Other numbers become digits without a leading 00.
     */
    public static function loginKey(?string $value): ?string
    {
        $dutch = DutchMobileNumber::normalize($value);
        if ($dutch !== null) {
            return $dutch;
        }

        $digits = self::digits($value);
        if ($digits === null || strlen($digits) < 8) {
            return null;
        }

        return $digits;
    }

    /**
     * International WhatsApp id without a plus, e.g. 31657925505 or 48690668857.
     */
    public static function whatsAppId(?string $value): ?string
    {
        $dutch = DutchMobileNumber::normalize($value);
        if ($dutch !== null) {
            return '31'.substr($dutch, 1);
        }

        $digits = self::digits($value);
        if ($digits === null || strlen($digits) < 8) {
            return null;
        }

        return $digits;
    }

    public static function display(?string $value): string
    {
        $dutch = DutchMobileNumber::normalize($value);
        if ($dutch !== null) {
            return substr($dutch, 0, 2).'-'.substr($dutch, 2);
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : '';
    }

    public static function hasNumber(?string $value): bool
    {
        return self::whatsAppId($value) !== null;
    }

    private static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits !== '' ? $digits : null;
    }
}
