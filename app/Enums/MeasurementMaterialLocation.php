<?php

namespace App\Enums;

enum MeasurementMaterialLocation: string
{
    case Winkel = 'winkel';
    case Nicon = 'nicon';
    case Klant = 'klant';

    public function label(): string
    {
        return match ($this) {
            self::Winkel => 'Winkel',
            self::Nicon => 'Nicon',
            self::Klant => 'Klant',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
