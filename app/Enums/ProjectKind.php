<?php

namespace App\Enums;

enum ProjectKind: string
{
    case Project = 'project';
    case Winkel = 'winkel';
    case Service = 'service';
    case Klein = 'klein';

    public const KLEINE_FILTER = 'kleine';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Project',
            self::Winkel => 'Winkel',
            self::Service => 'Service',
            self::Klein => 'Klein werk',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Project => 'PROJECT',
            self::Winkel => 'WINKEL',
            self::Service => 'SERVICE',
            self::Klein => 'KLEIN',
        };
    }

    public function isSmallWork(): bool
    {
        return $this === self::Service || $this === self::Klein;
    }

    /**
     * @return list<self>
     */
    public static function smallWorkCases(): array
    {
        return [self::Service, self::Klein];
    }
}
