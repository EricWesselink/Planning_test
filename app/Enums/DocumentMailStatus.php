<?php

namespace App\Enums;

enum DocumentMailStatus: string
{
    case Sent = 'verzonden';
    case Failed = 'mislukt';

    public function label(): string
    {
        return match ($this) {
            self::Sent => 'Verzonden',
            self::Failed => 'Mislukt',
        };
    }
}
