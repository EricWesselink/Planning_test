<?php

namespace App\Enums;

enum ContactRole: string
{
    case Uitvoerder = 'uitvoerder';
    case Aannemer = 'aannemer';
    case Opdrachtgever = 'opdrachtgever';

    public function label(): string
    {
        return match ($this) {
            self::Uitvoerder => 'Uitvoerder',
            self::Aannemer => 'Aannemer',
            self::Opdrachtgever => 'Opdrachtgever',
        };
    }
}
