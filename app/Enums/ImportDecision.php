<?php

namespace App\Enums;

/**
 * Centrale importeindbeslissing — geen handmatige bevestiging nodig bij READY_AUTOMATIC.
 */
enum ImportDecision: string
{
    case ReadyAutomatic = 'READY_AUTOMATIC';
    case BlockedConflict = 'BLOCKED_CONFLICT';

    public function label(): string
    {
        return match ($this) {
            self::ReadyAutomatic => 'Automatisch gereed',
            self::BlockedConflict => 'Geblokkeerd door bronconflict',
        };
    }
}
