<?php

namespace App\Enums;

enum VoucherPriceSource: string
{
    case Rate = 'rate';
    case Order = 'order';
    case Voucher = 'voucher';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Rate => 'Afgesproken prijs',
            self::Order => 'Van opdracht',
            self::Voucher => 'Van opdrachtbon',
            self::Manual => 'Handmatig',
        };
    }
}
