<?php

namespace App\Enums;

enum VoucherType: string
{
    case Opdracht = 'opdracht';
    case Facturatie = 'facturatie';

    public function label(): string
    {
        return match ($this) {
            self::Opdracht => 'Opdrachtbon',
            self::Facturatie => 'Bon',
        };
    }
}
