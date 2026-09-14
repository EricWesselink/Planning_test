<?php

namespace App\Enums;

/**
 * Centrale importeindbeslissing.
 * READY en READY_WITH_WARNINGS mogen beide definitief importeren.
 * Alleen TECHNICAL_ERROR blokkeert.
 */
enum ImportDecision: string
{
    case Ready = 'READY';
    case ReadyWithWarnings = 'READY_WITH_WARNINGS';
    case TechnicalError = 'TECHNICAL_ERROR';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Gereed',
            self::ReadyWithWarnings => 'Importeren toegestaan met waarschuwingen',
            self::TechnicalError => 'Geblokkeerd door technische fout',
        };
    }

    public function allowsImport(): bool
    {
        return $this === self::Ready || $this === self::ReadyWithWarnings;
    }

    public static function parse(?string $value): ?self
    {
        return match ($value) {
            'READY', 'READY_AUTOMATIC' => self::Ready,
            'READY_WITH_WARNINGS' => self::ReadyWithWarnings,
            'TECHNICAL_ERROR', 'BLOCKED_CONFLICT' => self::TechnicalError,
            default => self::tryFrom((string) $value),
        };
    }
}
