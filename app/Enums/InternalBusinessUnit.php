<?php

namespace App\Enums;

enum InternalBusinessUnit: string
{
    case NicoDekvloeren = 'nico_dekvloeren';
    case ScreensZonwering = 'screens_zonwering';
    case Overig = 'overig';

    public function label(): string
    {
        return match ($this) {
            self::NicoDekvloeren => 'Nico Dekvloeren',
            self::ScreensZonwering => 'Screens & zonwering',
            self::Overig => 'Overig bedrijfsonderdeel',
        };
    }

    /**
     * @return list<self>
     */
    public static function choices(): array
    {
        return [
            self::NicoDekvloeren,
            self::ScreensZonwering,
            self::Overig,
        ];
    }
}
