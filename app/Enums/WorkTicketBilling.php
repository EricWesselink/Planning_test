<?php

namespace App\Enums;

enum WorkTicketBilling: string
{
    case Unit = 'unit';
    case Hourly = 'hourly';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Prijs per m²/m¹',
            self::Hourly => 'Uurprijs',
            self::Fixed => 'Vaste prijs',
        };
    }
}
