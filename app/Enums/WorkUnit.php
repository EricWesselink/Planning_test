<?php

namespace App\Enums;

enum WorkUnit: string
{
    case SquareMeter = 'm2';
    case LinearMeter = 'm1';
    case Pieces = 'stuks';
    case Hours = 'uren';
    case Kilogram = 'kg';
    case Liter = 'liter';

    public function label(): string
    {
        return match ($this) {
            self::SquareMeter => 'm²',
            self::LinearMeter => 'm¹',
            self::Pieces => 'stuks',
            self::Hours => 'uren',
            self::Kilogram => 'kg',
            self::Liter => 'liter',
        };
    }

    /**
     * @return list<self>
     */
    public static function shopCases(): array
    {
        return [self::SquareMeter, self::LinearMeter, self::Pieces];
    }
}
