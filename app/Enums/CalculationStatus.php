<?php

namespace App\Enums;

enum CalculationStatus: string
{
    case Concept = 'concept';
    case Reviewed = 'reviewed';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Concept => 'Concept',
            self::Reviewed => 'Gecontroleerd',
            self::Completed => 'Afgerond',
        };
    }
}
