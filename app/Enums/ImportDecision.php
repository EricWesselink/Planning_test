<?php

namespace App\Enums;

/**
 * Centrale importeindbeslissing.
 * READY_AUTOMATIC en READY_WITH_WARNINGS mogen beide definitief importeren.
 */
enum ImportDecision: string
{
    case ReadyAutomatic = 'READY_AUTOMATIC';
    case ReadyWithWarnings = 'READY_WITH_WARNINGS';
    case BlockedConflict = 'BLOCKED_CONFLICT';

    public function label(): string
    {
        return match ($this) {
            self::ReadyAutomatic => 'Automatisch gereed',
            self::ReadyWithWarnings => 'Importeren toegestaan met waarschuwingen',
            self::BlockedConflict => 'Geblokkeerd door bronconflict',
        };
    }

    public function allowsImport(): bool
    {
        return $this === self::ReadyAutomatic || $this === self::ReadyWithWarnings;
    }
}
