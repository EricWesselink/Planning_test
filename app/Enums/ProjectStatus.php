<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Concept = 'concept';
    case Gepland = 'gepland';
    case NietGestart = 'niet_gestart';
    case InUitvoering = 'in_uitvoering';
    case Geblokkeerd = 'geblokkeerd';
    case Gereed = 'gereed';
    case Opgeleverd = 'opgeleverd';

    public function label(): string
    {
        return match ($this) {
            self::Concept => 'Concept',
            self::Gepland => 'Gepland',
            self::NietGestart => 'Niet gestart',
            self::InUitvoering => 'In uitvoering',
            self::Geblokkeerd => 'Geblokkeerd',
            self::Gereed => 'Gereed',
            self::Opgeleverd => 'Opgeleverd',
        };
    }
}
