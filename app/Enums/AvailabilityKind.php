<?php

namespace App\Enums;

enum AvailabilityKind: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Beschikbaar',
            self::Unavailable => 'Niet beschikbaar',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Available => 'Wel',
            self::Unavailable => 'Niet',
        };
    }
}
