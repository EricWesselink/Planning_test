<?php

namespace App\Enums;

enum AreaStatus: string
{
    case NietGestart = 'niet_gestart';
    case InUitvoering = 'in_uitvoering';
    case VoorlopigGereed = 'voorlopig_gereed';
    case Gereed = 'gereed';

    public function label(): string
    {
        return match ($this) {
            self::NietGestart => 'Open',
            self::InUitvoering => 'Deels gereed',
            self::VoorlopigGereed => 'Voorlopig',
            self::Gereed => 'Gereed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::NietGestart => 'open',
            self::InUitvoering => 'partial',
            self::VoorlopigGereed => 'pending',
            self::Gereed => 'done',
        };
    }
}
