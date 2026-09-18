<?php

namespace App\Enums;

use App\Support\PlanningHours;

enum AvailabilitySlot: string
{
    case Full = 'full';
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Hours = 'hours';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Hele dag',
            self::Morning => 'Halve dag · ochtend',
            self::Afternoon => 'Halve dag · middag',
            self::Hours => 'Aantal uren',
        };
    }

    public function defaultHours(): float
    {
        return match ($this) {
            self::Full => (float) PlanningHours::WORKDAY_HOURS,
            self::Morning, self::Afternoon, self::Hours => 4.0,
        };
    }

    public function hint(float $hours): ?string
    {
        $remaining = max(0.0, PlanningHours::WORKDAY_HOURS - $hours);

        return match ($this) {
            self::Morning => 'ochtend vrij, middag beschikbaar',
            self::Afternoon => 'middag vrij, ochtend beschikbaar',
            self::Hours => PlanningHours::hoursLabel($hours).' vrij, '.PlanningHours::hoursLabel($remaining).' beschikbaar',
            self::Full => null,
        };
    }
}
