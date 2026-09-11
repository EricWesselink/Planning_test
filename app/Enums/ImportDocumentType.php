<?php

namespace App\Enums;

enum ImportDocumentType: string
{
    case Meetstaat = 'meetstaat';
    case Afmetingen = 'afmetingen';
    case Materialenstaat = 'materialenstaat';
    case Snijmaten = 'snijmaten';
    case Plattegrond = 'plattegrond';
    case Calculatie = 'calculatie';
    case Overig = 'overig';

    public function label(): string
    {
        return match ($this) {
            self::Meetstaat => 'Meetstaat',
            self::Afmetingen => 'Afmetingen',
            self::Materialenstaat => 'Materialenstaat',
            self::Snijmaten => 'Snijmaten',
            self::Plattegrond => 'Plattegrond',
            self::Calculatie => 'Calculatie',
            self::Overig => 'Overig',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
