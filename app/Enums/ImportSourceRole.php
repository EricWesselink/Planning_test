<?php

namespace App\Enums;

/**
 * Inhoudelijke bronrollen — onafhankelijk van bestandsnaam of project.
 * Eén bestand kan meerdere rollen hebben.
 */
enum ImportSourceRole: string
{
    case RoomSource = 'ROOM_SOURCE';
    case TaskSource = 'TASK_SOURCE';
    case MaterialTotalSource = 'MATERIAL_TOTAL_SOURCE';
    case MaterialRoomHintSource = 'MATERIAL_ROOM_HINT_SOURCE';
    case DrawingLegendSource = 'DRAWING_LEGEND_SOURCE';

    public function label(): string
    {
        return match ($this) {
            self::RoomSource => 'Fysieke ruimtes',
            self::TaskSource => 'Materiaaltaken per ruimte',
            self::MaterialTotalSource => 'Materiaaltotalen',
            self::MaterialRoomHintSource => 'Materiaal-ruimtehints',
            self::DrawingLegendSource => 'Tekeninglegenda',
        };
    }

    public function usableData(): string
    {
        return match ($this) {
            self::RoomSource => 'contour / positie / nummer-naam / fysieke m²',
            self::TaskSource => 'materiaal + bouwlaag + ruimte + hoeveelheid',
            self::MaterialTotalSource => 'totaal per materiaal (project/bouwlaag)',
            self::MaterialRoomHintSource => 'materiaal gekoppeld aan bouwlaag/ruimtenaam',
            self::DrawingLegendSource => 'kleur/patroon → materiaal + legenda-totaal',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
