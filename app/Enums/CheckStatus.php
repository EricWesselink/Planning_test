<?php

namespace App\Enums;

enum CheckStatus: string
{
    case Certain = 'certain';
    case Review = 'review';
    case Missing = 'missing';
    case Confirmed = 'confirmed';
    case Generous = 'generous';
    case Estimated = 'estimated';

    public function label(): string
    {
        return match ($this) {
            self::Certain => 'Zeker',
            self::Review => 'Controleren',
            self::Missing => 'Ontbreekt',
            self::Confirmed => 'Bevestigd',
            self::Generous => 'Berekend ruim',
            self::Estimated => 'Geschat ruim',
        };
    }

    public function isBlocking(): bool
    {
        return $this === self::Review || $this === self::Missing;
    }

    public function isGreen(): bool
    {
        return $this === self::Certain
            || $this === self::Confirmed
            || $this === self::Generous
            || $this === self::Estimated;
    }
}
