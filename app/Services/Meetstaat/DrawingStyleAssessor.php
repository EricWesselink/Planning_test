<?php

namespace App\Services\Meetstaat;

/**
 * Bepaalt per tekening/pagina welke signalen betrouwbaar beschikbaar zijn.
 * Geen projectnamen of bestandsnaam-uitzonderingen: alleen inhoudssignalen.
 */
class DrawingStyleAssessor
{
    public const StrategyNameMetersColor = 'name_meters_color';

    public const StrategyNumberNameMeters = 'number_name_meters';

    public const StrategyNumberNameOnly = 'number_name_only';

    public const StrategyMixed = 'mixed';

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $legend
     * @return array{
     *     strategy: string,
     *     strategy_label: string,
     *     signals: array<string, bool|int>,
     *     pages: list<array<string, mixed>>
     * }
     */
    public function assess(array $areas, array $legend = []): array
    {
        $pages = [];
        foreach ($areas as $area) {
            $page = max(1, (int) ($area['page'] ?? 1));
            $pages[$page] ??= [
                'page' => $page,
                'rooms' => 0,
                'with_number' => 0,
                'with_name' => 0,
                'with_meters' => 0,
                'with_color' => 0,
                'with_material' => 0,
            ];
            $pages[$page]['rooms']++;
            if (filled($area['room_number'] ?? null)) {
                $pages[$page]['with_number']++;
            }
            $name = trim((string) ($area['room_name'] ?? ''));
            $number = trim((string) ($area['room_number'] ?? ''));
            if ($name !== '' && $name !== $number) {
                $pages[$page]['with_name']++;
            }
            if (($area['square_meters'] ?? null) !== null) {
                $pages[$page]['with_meters']++;
            }
            if (filled($area['fill_color'] ?? null)) {
                $pages[$page]['with_color']++;
            }
            if (filled($area['legend_material'] ?? null) || $this->hasMaterialTask($area)) {
                $pages[$page]['with_material']++;
            }
        }

        $pageRows = [];
        foreach ($pages as $page) {
            $pageRows[] = [
                ...$page,
                'strategy' => $this->strategyForCounts($page, $legend !== []),
                'signals' => $this->signalFlags($page, $legend !== []),
            ];
        }

        $overall = $this->overallCounts($areas);
        $strategy = $this->strategyForCounts($overall, $legend !== []);

        return [
            'strategy' => $strategy,
            'strategy_label' => $this->strategyLabel($strategy),
            'signals' => $this->signalFlags($overall, $legend !== []),
            'pages' => array_values($pageRows),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function strategyForCounts(array $counts, bool $hasLegend): string
    {
        $rooms = max(1, (int) ($counts['rooms'] ?? 0));
        $numberRatio = (($counts['with_number'] ?? 0) / $rooms);
        $nameRatio = (($counts['with_name'] ?? 0) / $rooms);
        $metersRatio = (($counts['with_meters'] ?? 0) / $rooms);
        $colorRatio = (($counts['with_color'] ?? 0) / $rooms);

        if ($numberRatio >= 0.5 && $nameRatio >= 0.5 && $metersRatio < 0.35) {
            return self::StrategyNumberNameOnly;
        }

        if ($numberRatio >= 0.5 && $nameRatio >= 0.4) {
            return self::StrategyNumberNameMeters;
        }

        if ($numberRatio < 0.35 && $nameRatio >= 0.5 && ($metersRatio >= 0.5 || $colorRatio >= 0.4 || $hasLegend)) {
            return self::StrategyNameMetersColor;
        }

        if ($nameRatio >= 0.5 && $metersRatio >= 0.5) {
            return self::StrategyNameMetersColor;
        }

        return self::StrategyMixed;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, bool|int>
     */
    private function signalFlags(array $counts, bool $hasLegend): array
    {
        $rooms = (int) ($counts['rooms'] ?? 0);

        return [
            'rooms' => $rooms,
            'room_numbers' => ($counts['with_number'] ?? 0) > 0,
            'room_names' => ($counts['with_name'] ?? 0) > 0,
            'square_meters' => ($counts['with_meters'] ?? 0) > 0,
            'fill_colors' => ($counts['with_color'] ?? 0) > 0,
            'legend' => $hasLegend,
            'materials' => ($counts['with_material'] ?? 0) > 0,
            'with_number' => (int) ($counts['with_number'] ?? 0),
            'with_name' => (int) ($counts['with_name'] ?? 0),
            'with_meters' => (int) ($counts['with_meters'] ?? 0),
            'with_color' => (int) ($counts['with_color'] ?? 0),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, int>
     */
    private function overallCounts(array $areas): array
    {
        $counts = [
            'rooms' => count($areas),
            'with_number' => 0,
            'with_name' => 0,
            'with_meters' => 0,
            'with_color' => 0,
            'with_material' => 0,
        ];
        foreach ($areas as $area) {
            if (filled($area['room_number'] ?? null)) {
                $counts['with_number']++;
            }
            $name = trim((string) ($area['room_name'] ?? ''));
            $number = trim((string) ($area['room_number'] ?? ''));
            if ($name !== '' && $name !== $number) {
                $counts['with_name']++;
            }
            if (($area['square_meters'] ?? null) !== null) {
                $counts['with_meters']++;
            }
            if (filled($area['fill_color'] ?? null)) {
                $counts['with_color']++;
            }
            if (filled($area['legend_material'] ?? null) || $this->hasMaterialTask($area)) {
                $counts['with_material']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function hasMaterialTask(array $area): bool
    {
        foreach ($area['tasks'] ?? [] as $task) {
            if (trim((string) ($task['work_name'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public function strategyLabel(string $strategy): string
    {
        return match ($strategy) {
            self::StrategyNameMetersColor => 'Naam + m² + kleur/legenda',
            self::StrategyNumberNameMeters => 'Ruimtenummer + naam + m²',
            self::StrategyNumberNameOnly => 'Ruimtenummer + naam (m²/materiaal handmatig)',
            default => 'Gemengde signalen',
        };
    }
}
