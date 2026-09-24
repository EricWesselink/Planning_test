<?php

namespace App\Enums;

enum DocumentMailType: string
{
    case Weekplanning = 'weekplanning';
    case PersonnelWeek = 'personnel_week';
    case Werkbon = 'werkbon';
    case Opdrachtbon = 'opdrachtbon';

    public function label(): string
    {
        return match ($this) {
            self::Weekplanning => 'Weekplanning',
            self::PersonnelWeek => 'Personeelsplanning',
            self::Werkbon => 'Werkbon',
            self::Opdrachtbon => 'Opdrachtbon',
        };
    }
}
