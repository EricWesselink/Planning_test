<?php

namespace App\Enums;

enum EmploymentType: string
{
    case Eigen = 'eigen';
    case Zzp = 'zzp';
    case Onderaannemer = 'onderaannemer';

    public function label(): string
    {
        return match ($this) {
            self::Eigen => 'Eigen medewerker',
            self::Zzp => 'ZZP',
            self::Onderaannemer => 'Onderaannemer',
        };
    }

    public function isExternal(): bool
    {
        return $this !== self::Eigen;
    }
}
