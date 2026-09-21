<?php

namespace App\Enums;

enum TimeEntryStatus: string
{
    case Submitted = 'ingediend';
    case Approved = 'goedgekeurd';
    case Rejected = 'afgewezen';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Ingediend',
            self::Approved => 'Goedgekeurd',
            self::Rejected => 'Afgewezen',
        };
    }

    public function weekLabel(): string
    {
        return match ($this) {
            self::Submitted => 'Te beoordelen',
            self::Approved => 'Goedgekeurd',
            self::Rejected => 'Afgewezen',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Approved;
    }
}
