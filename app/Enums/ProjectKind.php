<?php

namespace App\Enums;

enum ProjectKind: string
{
    case Project = 'project';
    case Winkel = 'winkel';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Project',
            self::Winkel => 'Winkel',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Project => 'PROJECT',
            self::Winkel => 'WINKEL',
        };
    }
}
