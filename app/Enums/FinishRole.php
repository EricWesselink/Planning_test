<?php

namespace App\Enums;

enum FinishRole: string
{
    case Main = 'main';
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Hoofdvloer',
            self::Local => 'Deelvlak',
        };
    }
}
