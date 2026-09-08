<?php

namespace App\Enums;

enum VoucherPriceKind: string
{
    case Unit = 'unit';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Prijs per eenheid',
            self::Fixed => 'Vaste afgesproken prijs',
        };
    }

    public function isFixed(): bool
    {
        return $this === self::Fixed;
    }
}
