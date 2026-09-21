<?php

namespace App\Enums;

enum AssignmentKind: string
{
    case Project = 'project';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Project',
            self::Internal => 'Interne inzet',
        };
    }
}
