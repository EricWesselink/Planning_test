<?php

namespace App\Enums;

enum AvailabilityKind: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Vacation = 'vakantie';
    case Sick = 'ziek';
    case DayOff = 'vrije_dag';
    case Leave = 'verlof';
    case Adv = 'adv';
    case Course = 'cursus';
    case Other = 'overig';

    /**
     * @return list<self>
     */
    public static function incidental(): array
    {
        return [
            self::Vacation,
            self::Sick,
            self::DayOff,
            self::Leave,
            self::Adv,
            self::Course,
            self::Other,
        ];
    }

    public function isAway(): bool
    {
        return $this !== self::Available;
    }

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Beschikbaar',
            self::Unavailable => 'Niet beschikbaar',
            self::Vacation => 'Vakantie',
            self::Sick => 'Ziek',
            self::DayOff => 'Vrije dag',
            self::Leave => 'Verlof',
            self::Adv => 'ADV',
            self::Course => 'Cursus',
            self::Other => 'Overig',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Available => 'Wel',
            self::Unavailable => 'Niet',
            self::Vacation => 'Vakantie',
            self::Sick => 'Ziek',
            self::DayOff => 'Vrij',
            self::Leave => 'Verlof',
            self::Adv => 'ADV',
            self::Course => 'Cursus',
            self::Other => 'Overig',
        };
    }

    public function awayLabel(): string
    {
        return $this->label();
    }
}
