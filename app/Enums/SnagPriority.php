<?php

namespace App\Enums;

enum SnagPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Laag',
            self::Normal => 'Normaal',
            self::High => 'Hoog',
        };
    }
}
