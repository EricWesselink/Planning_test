<?php

namespace App\Enums;

enum SmallWorkType: string
{
    case Service = 'service';
    case Extra = 'extra';
    case Klein = 'klein';

    public const HOURLY_RATE = 48.0;

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Service',
            self::Extra => 'Extra werk',
            self::Klein => 'Klein werk',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Service => 'SERVICE',
            self::Extra => 'EXTRA',
            self::Klein => 'KLEIN',
        };
    }

    public function projectKind(): ?ProjectKind
    {
        return match ($this) {
            self::Service => ProjectKind::Service,
            self::Klein => ProjectKind::Klein,
            self::Extra => null,
        };
    }

    public function isStandalone(): bool
    {
        return $this === self::Service;
    }

    public function attachesToExistingProject(): bool
    {
        return $this === self::Extra || $this === self::Klein;
    }
}
