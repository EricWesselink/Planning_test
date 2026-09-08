<?php

namespace App\Enums;

enum WorkPhase: string
{
    case Voorbereiden = 'voorbereiden';
    case Primeren = 'primeren';
    case Egaliseren = 'egaliseren';
    case Vloer = 'vloer';
    case Plinten = 'plinten';
    case Overige = 'overige';

    public function label(): string
    {
        return match ($this) {
            self::Voorbereiden => 'Primen & Egaliseren',
            self::Primeren => 'Primen & Egaliseren',
            self::Egaliseren => 'Primen & Egaliseren',
            self::Vloer => 'Vloer leggen',
            self::Plinten => 'Plinten',
            self::Overige => 'Overige',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::Voorbereiden, self::Primeren, self::Egaliseren => 'ondergrond',
            self::Vloer => 'vloer',
            self::Plinten => 'plinten',
            self::Overige => 'overige',
        };
    }

    public function groupLabel(): string
    {
        return match ($this->group()) {
            'ondergrond' => 'Primen & Egaliseren',
            'vloer' => 'Vloer',
            'plinten' => 'Plinten',
            'overige' => 'Overige',
        };
    }

    public function sort(): int
    {
        return match ($this) {
            self::Voorbereiden => 10,
            self::Primeren => 20,
            self::Egaliseren => 30,
            self::Vloer => 40,
            self::Plinten => 50,
            self::Overige => 60,
        };
    }
}
