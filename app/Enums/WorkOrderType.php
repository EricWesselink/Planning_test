<?php

namespace App\Enums;

enum WorkOrderType: string
{
    case Project = 'project';
    case WorkItem = 'work_item';
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Gehele project',
            self::WorkItem => 'Werkzaamheid',
            self::Partial => 'Deel van de hoeveelheid',
        };
    }
}
