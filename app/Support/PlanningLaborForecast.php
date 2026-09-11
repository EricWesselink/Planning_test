<?php

namespace App\Support;

class PlanningLaborForecast
{
    /**
     * Extra planning-vs-budget check for the board. Does not change remaining hours.
     *
     * @return array{
     *     still_planned: float,
     *     expected_overrun: float,
     *     planned_tone: 'none'|'warn'|'over',
     *     planned_overrun_label: ?string,
     *     planned_title: ?string,
     *     rest_label: string,
     *     rest_tone: 'none'|'warn'|'over',
     *     rest_title: string,
     * }
     */
    public static function forBoard(
        float $budgetHours,
        float $plannedHours,
        float $actualHours,
        ?float $remaining = null,
    ): array {
        $stillPlanned = max(0.0, $plannedHours - $actualHours);
        $expectedTotal = $actualHours + $stillPlanned;
        $hasBudget = $budgetHours > 0.0001;
        $expectedOverrun = $hasBudget ? max(0.0, $expectedTotal - $budgetHours) : 0.0;
        $remainingHours = $hasBudget
            ? ($remaining ?? round($budgetHours - $actualHours, 2))
            : null;
        $showsPlanOverrun = $expectedOverrun > 0.0001
            && ($stillPlanned > 0.0001 || $plannedHours > $budgetHours + 0.0001);
        $plannedTone = self::plannedTone($hasBudget, $budgetHours, $plannedHours, $showsPlanOverrun);

        return [
            'still_planned' => $stillPlanned,
            'expected_overrun' => $expectedOverrun,
            'planned_tone' => $plannedTone,
            'planned_overrun_label' => $showsPlanOverrun
                ? '+'.PlanningHours::hoursLabel($expectedOverrun)
                : null,
            'planned_title' => match ($plannedTone) {
                'over' => implode("\n", [
                    'Begroot: '.PlanningHours::hoursLabel($budgetHours),
                    'Gepland: '.PlanningHours::hoursLabel($plannedHours),
                    'Overschrijding: +'.PlanningHours::hoursLabel($expectedOverrun),
                ]),
                'warn' => self::plannedTitle($budgetHours, $actualHours, $stillPlanned, 0.0),
                default => null,
            },
            ...self::restDisplay($hasBudget, $budgetHours, $remainingHours),
        ];
    }

    /**
     * Split assignment hours chronologically against a budget.
     *
     * @param  list<array{id: int, hours: float, start_date: string, start_time: string}>  $assignments
     * @return array<int, array{within_hours: float, over_hours: float, ok_percent: float, over_percent: float}>
     */
    public static function splitByBudget(float $budgetHours, array $assignments): array
    {
        usort($assignments, function (array $left, array $right): int {
            return [$left['start_date'], $left['start_time'], $left['id']]
                <=> [$right['start_date'], $right['start_time'], $right['id']];
        });

        $used = 0.0;
        $splits = [];

        foreach ($assignments as $assignment) {
            $hours = max(0.0, round((float) $assignment['hours'], 2));
            $room = $budgetHours > 0.0001 ? max(0.0, round($budgetHours - $used, 2)) : $hours;
            $within = min($hours, $room);
            $over = round($hours - $within, 2);
            $used = round($used + $hours, 2);
            $okPercent = $hours <= 0.0001 ? 100.0 : round($within / $hours * 100, 2);

            $splits[(int) $assignment['id']] = [
                'within_hours' => round($within, 2),
                'over_hours' => $over,
                'ok_percent' => $okPercent,
                'over_percent' => round(100 - $okPercent, 2),
            ];
        }

        return $splits;
    }

    /**
     * @return 'none'|'warn'|'over'
     */
    private static function plannedTone(
        bool $hasBudget,
        float $budgetHours,
        float $plannedHours,
        bool $showsPlanOverrun,
    ): string {
        if (! $hasBudget) {
            return 'none';
        }

        if ($showsPlanOverrun) {
            return 'over';
        }

        if ($plannedHours >= ($budgetHours * 0.80) - 0.0001) {
            return 'warn';
        }

        return 'none';
    }

    private static function plannedTitle(
        float $budgetHours,
        float $actualHours,
        float $stillPlanned,
        float $expectedOverrun,
    ): string {
        $parts = [
            'Begroot '.PlanningHours::hoursLabel($budgetHours),
            'Gemaakt '.PlanningHours::hoursLabel($actualHours),
            'Nog gepland '.PlanningHours::hoursLabel($stillPlanned),
        ];

        if ($expectedOverrun > 0.0001) {
            $parts[] = 'Verwachte overschrijding '.PlanningHours::hoursLabel($expectedOverrun);
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{rest_label: string, rest_tone: 'none'|'warn'|'over', rest_title: string}
     */
    private static function restDisplay(bool $hasBudget, float $budgetHours, ?float $remaining): array
    {
        if (! $hasBudget || $remaining === null) {
            return [
                'rest_label' => '—',
                'rest_tone' => 'none',
                'rest_title' => 'Geen urenbegroting',
            ];
        }

        if ($remaining < -0.0001) {
            $overrun = abs($remaining);

            return [
                'rest_label' => 'Overschreden +'.PlanningHours::hoursLabel($overrun),
                'rest_tone' => 'over',
                'rest_title' => 'Uren overschreden',
            ];
        }

        if ($remaining < ($budgetHours * 0.20) - 0.0001) {
            return [
                'rest_label' => PlanningHours::hoursLabel($remaining),
                'rest_tone' => 'warn',
                'rest_title' => 'Minder dan 20% van de begrote uren over',
            ];
        }

        return [
            'rest_label' => PlanningHours::hoursLabel($remaining),
            'rest_tone' => 'none',
            'rest_title' => 'Rest uren: begroot minus gemaakt',
        ];
    }
}
