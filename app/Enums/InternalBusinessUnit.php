<?php

namespace App\Enums;

enum InternalBusinessUnit: string
{
    case StucSpuitwerk = 'stuc_spuitwerk';
    case Vloeren = 'vloeren';
    case Buitengevelisolatie = 'buitengevelisolatie';
    case VerduurzamingVve = 'verduurzaming_vve';
    case BinnenwandenPlafonds = 'binnenwanden_plafonds';
    case Tegelwerken = 'tegelwerken';
    case Akoestiek = 'akoestiek';
    case Metselwerken = 'metselwerken';
    case Steigerwerken = 'steigerwerken';
    case KeukensSanitair = 'keukens_sanitair';
    case Interieur = 'interieur';

    public function label(): string
    {
        return match ($this) {
            self::StucSpuitwerk => 'Stuc- en spuitwerk',
            self::Vloeren => 'Vloeren',
            self::Buitengevelisolatie => 'Buitengevelisolatie',
            self::VerduurzamingVve => 'Verduurzaming VvE\'s',
            self::BinnenwandenPlafonds => 'Binnenwanden en plafonds',
            self::Tegelwerken => 'Tegelwerken',
            self::Akoestiek => 'Akoestiek',
            self::Metselwerken => 'Metselwerken',
            self::Steigerwerken => 'Steigerwerken',
            self::KeukensSanitair => 'Keukens en sanitair',
            self::Interieur => 'Interieur',
        };
    }

    /**
     * @return list<self>
     */
    public static function choices(): array
    {
        return self::cases();
    }
}
