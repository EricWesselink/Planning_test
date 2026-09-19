<?php

namespace App\Enums;

enum LeaveRequestStatus: string
{
    case Pending = 'in_behandeling';
    case Approved = 'goedgekeurd';
    case Rejected = 'afgewezen';
    case Withdrawn = 'ingetrokken';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In behandeling',
            self::Approved => 'Goedgekeurd',
            self::Rejected => 'Afgewezen',
            self::Withdrawn => 'Ingetrokken',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
