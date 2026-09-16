<?php

namespace App\Enums;

enum QuantitySource: string
{
    case FromDrawing = 'from_drawing';
    case FromExcel = 'from_excel';
    case Calculated = 'calculated';
    case Manual = 'manual';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::FromDrawing => 'Uit tekening',
            self::FromExcel => 'Uit Excel',
            self::Calculated => 'Berekend',
            self::Manual => 'Handmatig',
            self::Review => 'Controleren',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::FromDrawing => 'Waarde letterlijk gevonden in de PDF',
            self::FromExcel => 'Waarde uit een aangeleverd Excelbestand',
            self::Calculated => 'Hoeveelheid door de software berekend',
            self::Manual => 'Door gebruiker ingevoerd of aangepast',
            self::Review => 'Software is niet zeker; niet gegokt',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::FromDrawing => 'ok',
            self::FromExcel => 'info',
            self::Calculated => 'info',
            self::Manual => 'muted',
            self::Review => 'warn',
        };
    }
}
