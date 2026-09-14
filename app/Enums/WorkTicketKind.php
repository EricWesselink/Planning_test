<?php

namespace App\Enums;

use App\Models\Worker;

enum WorkTicketKind: string
{
    case Werkbon = 'werkbon';
    case Opdrachtbon = 'opdrachtbon';

    public function label(): string
    {
        return match ($this) {
            self::Werkbon => 'Werkbon',
            self::Opdrachtbon => 'Opdrachtbon',
        };
    }

    public static function forWorker(Worker $worker): self
    {
        return $worker->employment_type?->isExternal()
            ? self::Opdrachtbon
            : self::Werkbon;
    }
}
