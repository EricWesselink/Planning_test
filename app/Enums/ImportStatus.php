<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'wachtend',
            self::Processing => 'verwerken',
            self::Ready => 'gereed',
            self::Failed => 'fout',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
