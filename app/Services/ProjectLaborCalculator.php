<?php

namespace App\Services;

use App\Enums\SmallWorkType;
use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Support\Format;
use App\Support\PlanningHours;
use App\Support\PlanningLaborForecast;
use Illuminate\Support\Collection;

class ProjectLaborCalculator
{
    /**
     * @return array{
     *     hourly_rate: ?float,
     *     planned_hours: float,
     *     actual_hours: float,
     *     used_hours: float,
     *     budget_hours: float,
     *     remaining_hours: ?float,
     *     overrun_hours: float,
     *     used_percent: ?int,
     *     tone: string,
     *     labor_cost: float,
     *     actual_labor_cost: float,
     *     budget_labor_cost: float,
     *     completed_m2: float,
     *     cost_per_m2: ?float,
     *     budget_cost_per_m2: ?float,
     *     forecast_unit_price: ?float,
     *     cost_delta_per_m2: ?float,
     *     summary: ?string,
     *     budget_summary: ?string,
     *     overrun_label: ?string,
     *     detail: ?string,
     *     items: list<array<string, mixed>>,
     *     items_by_id: array<int, array<string, mixed>>,
     *     groups: array<string, array<string, mixed>>,
     * }
     */
    public function for(Project $project): array
    {
        $project->loadMissing(['assignments.crewMembers', 'workItems.progressEntries.crewMember', 'workItems.progressEntries.worker', 'workOrders']);

        $hoursByItem = $project->assignments->groupBy(
            fn (WorkerAssignment $assignment): int => $assignment->resolvedWorkItemId($project->workOrders) ?? 0
        );
        $unassignedHours = round(
            (float) ($hoursByItem->get(0)?->sum(
                fn (WorkerAssignment $assignment): float => $assignment->plannedPersonHours()
            ) ?? 0),
            2,
        );

        $items = [];
        foreach ($project->workItems->sortBy('sort_order') as $item) {
            $items[] = $this->forItem(
                $item,
                $project,
                $hoursByItem->get($item->id, collect()),
            );
        }

        $originalItems = collect($items)->reject(fn (array $row): bool => ! empty($row['is_extra']));
        $extraItems = collect($items)->filter(fn (array $row): bool => ! empty($row['is_extra']));

        $groups = [];
        foreach ($originalItems->groupBy('type_key') as $typeKey => $rows) {
            $groups[(string) $typeKey] = $this->rollUp($rows->all(), (string) $rows->first()['title']);
        }
        if ($extraItems->isNotEmpty()) {
            $groups['extra'] = $this->rollUp($extraItems->all(), 'Extra werk');
        }

        $plannedHours = round((float) $originalItems->sum('planned_hours') + $unassignedHours, 2);
        $actualHours = round((float) $originalItems->sum('actual_hours'), 2);
        $budgetHours = round((float) $originalItems->sum('budget_hours'), 2);
        $usedHours = round((float) $originalItems->sum('used_hours') + $unassignedHours, 2);
        $hourlyRate = $project->isSmallWork()
            ? SmallWorkType::HOURLY_RATE
            : $this->rate($project->basis_uurtarief);
        $actualLaborCost = round(
            (float) $originalItems->sum('actual_labor_cost') + ($hourlyRate === null ? 0.0 : $unassignedHours * $hourlyRate),
            2,
        );
        $budgetLaborCost = round((float) $originalItems->sum('budget_labor_cost'), 2);
        $forecastLaborCost = round(
            (float) $originalItems->sum('forecast_labor_cost')
                + ($hourlyRate === null ? 0.0 : $unassignedHours * $hourlyRate),
            2,
        );
        $forecastHours = round((float) $originalItems->sum('forecast_hours') + $unassignedHours, 2);
        $plannedLaborCost = round(
            (float) $originalItems->sum(
                fn (array $row): float => $row['hourly_rate'] === null ? 0.0 : $row['planned_hours'] * $row['hourly_rate']
            ) + ($hourlyRate === null ? 0.0 : $unassignedHours * $hourlyRate),
            2,
        );
        $laborCost = $actualHours > 0.0001 ? $actualLaborCost : $plannedLaborCost;
        $completedM2 = round(
            (float) $originalItems
                ->filter(fn (array $row): bool => ($row['unit_value'] ?? '') === WorkUnit::SquareMeter->value)
                ->sum('completed_qty'),
            2,
        );
        $orderedM2 = round(
            (float) $originalItems
                ->filter(fn (array $row): bool => ($row['unit_value'] ?? '') === WorkUnit::SquareMeter->value)
                ->sum('budget_qty'),
            2,
        );
        $prices = $this->priceFields(
            $budgetHours,
            $actualHours,
            $hourlyRate,
            $budgetLaborCost,
            $actualLaborCost,
            $forecastHours,
            $forecastLaborCost,
            $orderedM2,
            $completedM2,
            WorkUnit::SquareMeter->value,
            'm²',
        );
        $status = $this->status(
            $budgetHours,
            $usedHours,
            $plannedHours,
            $actualHours,
            $prices['budget_unit_price'],
            $prices['actual_unit_price'],
            'm²',
        );
        $summary = $this->hoursAndCostSummary($plannedHours, $hourlyRate, $laborCost, $prices['actual_unit_price']);
        $detail = $this->projectDetail($plannedHours, $actualHours, $hourlyRate, $laborCost, $completedM2, $prices['actual_unit_price']);

        if ($plannedHours <= 0.0001 && $actualHours <= 0.0001 && $hourlyRate === null && $budgetHours <= 0.0001) {
            $summary = null;
            $detail = null;
        }

        $extraPlanned = round((float) $extraItems->sum('planned_hours'), 2);
        $extraActual = round((float) $extraItems->sum('actual_hours'), 2);
        $extraBudget = round((float) $extraItems->sum('budget_hours'), 2);
        $extraShown = max($extraPlanned, $extraActual, $extraBudget);
        $extraSummary = $extraShown > 0.0001
            ? 'Extra werk '.PlanningHours::hoursLabel($extraShown).' (niet in oorspronkelijke begroting)'
            : null;

        return [
            'hourly_rate' => $hourlyRate,
            'planned_hours' => $plannedHours,
            'actual_hours' => $actualHours,
            ...$this->hoursDelta($plannedHours, $actualHours, $budgetHours),
            ...$this->budgetRemaining($budgetHours, $actualHours),
            'used_hours' => $usedHours,
            'budget_hours' => $budgetHours,
            'remaining_hours' => $status['remaining_hours'],
            'overrun_hours' => $status['overrun_hours'],
            'used_percent' => $status['used_percent'],
            'tone' => $status['tone'],
            'labor_cost' => $laborCost,
            'actual_labor_cost' => $actualLaborCost,
            'budget_labor_cost' => $budgetLaborCost,
            'completed_m2' => $completedM2,
            'cost_per_m2' => $prices['actual_unit_price'],
            'unit' => 'm²',
            ...$prices,
            'summary' => $summary,
            'budget_summary' => $this->budgetCompact(null, $budgetHours, $plannedHours, $actualHours),
            'overrun_label' => $this->overrunLabel($status, 'Project'),
            'extra_planned_hours' => $extraPlanned,
            'extra_actual_hours' => $extraActual,
            'extra_budget_hours' => $extraBudget,
            'extra_summary' => $extraSummary,
            'warnings' => $status['warnings'],
            'detail' => $detail,
            'items' => $items,
            'items_by_id' => collect($items)->keyBy('id')->all(),
            'groups' => $groups,
        ];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return array<string, mixed>
     */
    public function forItem(WorkItem $item, Project $project, Collection $assignments): array
    {
        $hourlyRate = $this->rateFor($item, $project);
        $plannedHours = round(
            (float) $assignments->sum(
                fn (WorkerAssignment $assignment): float => $assignment->plannedPersonHours()
            ),
            2,
        );
        $actualHours = round((float) $item->progressEntries->sum('worked_hours'), 2);
        $peopleHours = $item->progressEntries
            ->groupBy(function ($entry): string {
                $memberId = (int) ($entry->crew_member_id ?? 0);
                $workerId = (int) ($entry->worker_id ?? 0);

                return $memberId.':'.$workerId;
            })
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $name = $first?->crewMember?->displayName()
                    ?? $first?->worker?->planName()
                    ?? 'Onbekend';

                return [
                    'name' => $name,
                    'hours' => round((float) $rows->sum('worked_hours'), 2),
                    'hours_label' => PlanningHours::hoursLabel((float) $rows->sum('worked_hours')),
                ];
            })
            ->filter(fn (array $row): bool => $row['hours'] > 0.01)
            ->sortBy('name')
            ->values()
            ->all();
        $usedHours = round(max($plannedHours, $actualHours), 2);
        $budgetHours = $item->begrote_uren === null ? 0.0 : round((float) $item->begrote_uren, 2);
        $budgetQty = $item->begrote_hoeveelheid === null
            ? round((float) $item->ordered_quantity, 2)
            : round((float) $item->begrote_hoeveelheid, 2);
        $completedQty = round($item->completedQuantity(), 2);
        $actualLaborCost = $hourlyRate === null ? 0.0 : round($actualHours * $hourlyRate, 2);
        $budgetLaborCost = $hourlyRate === null ? 0.0 : round($budgetHours * $hourlyRate, 2);
        $plannedLaborCost = $hourlyRate === null ? 0.0 : round($plannedHours * $hourlyRate, 2);
        $forecastHours = PlanningLaborForecast::expectedHours(
            $plannedHours,
            $actualHours,
            $this->quantityComplete($budgetQty, $completedQty),
        );
        $forecastLaborCost = $hourlyRate === null ? 0.0 : round($forecastHours * $hourlyRate, 2);
        $laborCost = $actualHours > 0.0001 ? $actualLaborCost : $plannedLaborCost;
        $unitLabel = $item->unit?->label() ?? '';
        $prices = $this->priceFields(
            $budgetHours,
            $actualHours,
            $hourlyRate,
            $budgetLaborCost,
            $actualLaborCost,
            $forecastHours,
            $forecastLaborCost,
            $budgetQty,
            $completedQty,
            $item->unit?->value,
            $unitLabel,
        );
        $status = $this->status(
            $budgetHours,
            $usedHours,
            $plannedHours,
            $actualHours,
            $prices['budget_unit_price'],
            $prices['actual_unit_price'],
            $this->priceSuffix($item->unit?->value, $unitLabel),
        );
        $delta = $this->hoursDelta($plannedHours, $actualHours, $budgetHours);

        return [
            'id' => $item->id,
            'title' => $item->planningTitle(),
            'name' => $item->name,
            'type_key' => $item->typeKey(),
            'is_extra' => $item->isExtraWork(),
            'unit' => $unitLabel,
            'unit_value' => $item->unit?->value,
            'hourly_rate' => $hourlyRate,
            'own_hourly_rate' => $this->usesSmallWorkRate($item, $project)
                ? SmallWorkType::HOURLY_RATE
                : $this->rate($item->uurtarief),
            'planned_hours' => $plannedHours,
            'actual_hours' => $actualHours,
            'people' => $peopleHours,
            ...$delta,
            ...$this->budgetRemaining($budgetHours, $actualHours),
            'used_hours' => $usedHours,
            'budget_hours' => $budgetHours,
            'remaining_hours' => $status['remaining_hours'],
            'overrun_hours' => $status['overrun_hours'],
            'used_percent' => $status['used_percent'],
            'tone' => $status['tone'],
            'warning' => $status['warning'],
            'warnings' => $status['warnings'],
            'overrun_label' => $this->overrunLabel($status, $item->planningTitle()),
            'budget_qty' => $budgetQty,
            'completed_qty' => $completedQty,
            'labor_cost' => $laborCost,
            'actual_labor_cost' => $actualLaborCost,
            'budget_labor_cost' => $budgetLaborCost,
            ...$prices,
            'compact' => $this->hoursCompact(
                $item->isExtraWork() ? 'Extra werk: '.$item->name : $item->name,
                $budgetHours,
                $usedHours,
                $plannedHours,
                $actualHours,
                $status,
            ),
            'board_line' => $this->hoursCompact(null, $budgetHours, $usedHours, $plannedHours, $actualHours, $status),
            'bar_label' => $budgetHours > 0.0001
                ? $this->hoursNumber($usedHours).' / '.$this->hoursNumber($budgetHours).' uur'
                : null,
            'bar_percent' => $budgetHours > 0.0001
                ? (int) round(min(100, max(0, $usedHours / $budgetHours * 100)))
                : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function rollUp(array $rows, string $title): array
    {
        $plannedHours = round((float) collect($rows)->sum('planned_hours'), 2);
        $actualHours = round((float) collect($rows)->sum('actual_hours'), 2);
        $usedHours = round((float) collect($rows)->sum('used_hours'), 2);
        $budgetHours = round((float) collect($rows)->sum('budget_hours'), 2);
        $rates = collect($rows)->pluck('hourly_rate')->filter(fn ($rate) => $rate !== null)->unique()->values();
        $hourlyRate = $rates->count() === 1 ? (float) $rates->first() : null;
        $actualLaborCost = round((float) collect($rows)->sum('actual_labor_cost'), 2);
        $budgetLaborCost = round((float) collect($rows)->sum('budget_labor_cost'), 2);
        $forecastLaborCost = round((float) collect($rows)->sum('forecast_labor_cost'), 2);
        $forecastHours = round((float) collect($rows)->sum('forecast_hours'), 2);
        $laborCost = $actualHours > 0.0001 ? $actualLaborCost : round((float) collect($rows)->sum('labor_cost'), 2);
        $completedQty = round((float) collect($rows)->sum('completed_qty'), 2);
        $budgetQty = round((float) collect($rows)->sum('budget_qty'), 2);
        $units = collect($rows)->pluck('unit')->unique()->values();
        $unitLabel = $units->count() === 1 ? (string) $units->first() : '';
        $unitValue = collect($rows)->pluck('unit_value')->unique()->values();
        $unitValueKey = $unitValue->count() === 1 ? (string) $unitValue->first() : '';
        $prices = $this->priceFields(
            $budgetHours,
            $actualHours,
            $hourlyRate,
            $budgetLaborCost,
            $actualLaborCost,
            $forecastHours,
            $forecastLaborCost,
            $budgetQty,
            $completedQty,
            $unitValueKey !== '' ? $unitValueKey : null,
            $unitLabel,
        );
        $status = $this->status(
            $budgetHours,
            $usedHours,
            $plannedHours,
            $actualHours,
            $prices['budget_unit_price'],
            $prices['actual_unit_price'],
            $this->priceSuffix($unitValueKey !== '' ? $unitValueKey : null, $unitLabel),
        );

        return [
            'id' => $rows[0]['id'] ?? null,
            'title' => $title,
            'hourly_rate' => $hourlyRate,
            'planned_hours' => $plannedHours,
            'actual_hours' => $actualHours,
            ...$this->hoursDelta($plannedHours, $actualHours, $budgetHours),
            ...$this->budgetRemaining($budgetHours, $actualHours),
            'used_hours' => $usedHours,
            'budget_hours' => $budgetHours,
            'remaining_hours' => $status['remaining_hours'],
            'overrun_hours' => $status['overrun_hours'],
            'used_percent' => $status['used_percent'],
            'tone' => $status['tone'],
            'warning' => $status['warning'],
            'warnings' => $status['warnings'],
            'overrun_label' => $this->overrunLabel($status, $title),
            'labor_cost' => $laborCost,
            'actual_labor_cost' => $actualLaborCost,
            'budget_labor_cost' => $budgetLaborCost,
            'completed_qty' => $completedQty,
            'budget_qty' => $budgetQty,
            'unit' => $unitLabel,
            'unit_value' => $unitValueKey !== '' ? $unitValueKey : null,
            ...$prices,
            'compact' => $this->hoursCompact($title, $budgetHours, $usedHours, $plannedHours, $actualHours, $status),
            'board_line' => $this->hoursCompact(null, $budgetHours, $usedHours, $plannedHours, $actualHours, $status),
            'bar_label' => $budgetHours > 0.0001
                ? $this->hoursNumber($usedHours).' / '.$this->hoursNumber($budgetHours).' uur'
                : null,
            'bar_percent' => $budgetHours > 0.0001
                ? (int) round(min(100, max(0, $usedHours / $budgetHours * 100)))
                : null,
        ];
    }

    private function rateFor(WorkItem $item, Project $project): ?float
    {
        if ($this->usesSmallWorkRate($item, $project)) {
            return SmallWorkType::HOURLY_RATE;
        }

        return $this->rate($item->uurtarief) ?? $this->rate($project->basis_uurtarief);
    }

    private function usesSmallWorkRate(WorkItem $item, Project $project): bool
    {
        return $item->isExtraWork() || $project->isSmallWork();
    }

    private function rate(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }

    /**
     * @return array{
     *     forecast_hours: float,
     *     forecast_labor_cost: float,
     *     budget_unit_price: ?float,
     *     actual_unit_price: ?float,
     *     forecast_unit_price: ?float,
     *     budget_cost_per_m2: ?float,
     *     actual_cost_per_m2: ?float,
     *     forecast_cost_per_m2: ?float,
     *     price_per_unit: ?float,
     *     cost_delta: ?float,
     *     cost_delta_per_m2: ?float,
     *     cost_delta_label: ?string,
     *     cost_delta_tone: 'none'|'ok'|'over',
     *     forecast_delta: ?float,
     *     forecast_delta_label: ?string,
     *     forecast_delta_tone: 'none'|'ok'|'warn'|'over',
     *     forecast_over_percent: ?int,
     *     forecast_over_percent_label: ?string,
     *     unit_price_title: ?string,
     *     finance: ?string,
     * }
     */
    private function priceFields(
        float $budgetHours,
        float $actualHours,
        ?float $hourlyRate,
        float $budgetLaborCost,
        float $actualLaborCost,
        float $forecastHours,
        float $forecastLaborCost,
        float $orderedQty,
        float $completedQty,
        ?string $unitValue,
        string $unitLabel,
    ): array {
        $suffix = $this->priceSuffix($unitValue, $unitLabel);
        $showsPrice = $this->isPricedUnit($unitValue);
        $forecastHours = round($forecastHours, 2);
        $budgetPrice = $showsPrice ? $this->unitPrice($budgetLaborCost, $orderedQty) : null;
        $actualPrice = $showsPrice && $actualHours > 0.0001 && $completedQty > 0.0001
            ? $this->unitPrice($actualLaborCost, $completedQty)
            : null;
        $forecastPrice = $showsPrice && $forecastHours > 0.0001
            ? $this->unitPrice($forecastLaborCost, $orderedQty)
            : null;
        $delta = $this->delta($actualPrice, $budgetPrice);
        $forecastDelta = $this->delta($forecastPrice, $budgetPrice);
        $forecastOverPercent = $this->overPercent($forecastPrice, $budgetPrice);

        return [
            'forecast_hours' => $forecastHours,
            'forecast_labor_cost' => round($forecastLaborCost, 2),
            'budget_unit_price' => $budgetPrice,
            'actual_unit_price' => $actualPrice,
            'forecast_unit_price' => $forecastPrice,
            'budget_cost_per_m2' => $budgetPrice,
            'actual_cost_per_m2' => $actualPrice,
            'forecast_cost_per_m2' => $forecastPrice,
            'price_per_unit' => $actualPrice,
            'cost_delta' => $delta,
            'cost_delta_per_m2' => $delta,
            'cost_delta_label' => $delta === null ? null : $this->deltaLabel($delta, $suffix),
            'cost_delta_tone' => $this->deltaTone($delta),
            'forecast_delta' => $forecastDelta,
            'forecast_delta_label' => $forecastDelta === null ? null : $this->deltaLabel($forecastDelta, $suffix),
            'forecast_delta_tone' => $this->forecastTone($forecastPrice, $budgetPrice),
            'forecast_over_percent' => $forecastOverPercent,
            'forecast_over_percent_label' => $forecastOverPercent === null ? null : '+'.$forecastOverPercent.'%',
            'unit_price_title' => $this->unitPriceTitle(
                $budgetHours,
                $forecastHours,
                $actualHours,
                $hourlyRate,
                $orderedQty,
                $completedQty,
                $budgetPrice,
                $forecastPrice,
                $actualPrice,
                $forecastDelta ?? $delta,
                $suffix,
            ),
            'finance' => $this->financeLine($budgetPrice, $actualPrice, $suffix),
        ];
    }

    private function quantityComplete(float $orderedQty, float $completedQty): bool
    {
        return $orderedQty > 0.0001 && $completedQty >= $orderedQty - 0.0001;
    }

    private function isPricedUnit(?string $unitValue): bool
    {
        return $unitValue === WorkUnit::SquareMeter->value
            || $unitValue === WorkUnit::LinearMeter->value;
    }

    private function priceSuffix(?string $unitValue, string $unitLabel): string
    {
        if ($unitValue === WorkUnit::LinearMeter->value) {
            return 'm¹';
        }

        if ($unitValue === WorkUnit::SquareMeter->value) {
            return 'm²';
        }

        return $unitLabel !== '' ? $unitLabel : 'm²';
    }

    private function unitPrice(float $cost, float $quantity): ?float
    {
        if ($cost <= 0.0001 || $quantity <= 0.0001) {
            return null;
        }

        return round($cost / $quantity, 2);
    }

    private function delta(?float $actual, ?float $budget): ?float
    {
        if ($actual === null || $budget === null) {
            return null;
        }

        return round($actual - $budget, 2);
    }

    /**
     * @return 'none'|'ok'|'over'
     */
    private function deltaTone(?float $delta): string
    {
        if ($delta === null) {
            return 'none';
        }

        if ($delta < -0.0001) {
            return 'ok';
        }

        if ($delta > 0.0001) {
            return 'over';
        }

        return 'none';
    }

    /**
     * @return 'none'|'ok'|'warn'|'over'
     */
    private function forecastTone(?float $forecast, ?float $budget): string
    {
        if ($forecast === null || $budget === null) {
            return 'none';
        }

        if ($forecast <= $budget + 0.0001) {
            return $forecast < $budget - 0.0001 ? 'ok' : 'none';
        }

        $percent = ($forecast - $budget) / $budget * 100;

        return $percent > 20.0001 ? 'over' : 'warn';
    }

    private function overPercent(?float $forecast, ?float $budget): ?int
    {
        if ($forecast === null || $budget === null || $budget <= 0.0001) {
            return null;
        }

        $percent = (int) round(($forecast - $budget) / $budget * 100);

        return $percent > 0 ? $percent : null;
    }

    private function deltaLabel(float $delta, string $suffix): string
    {
        $sign = $delta > 0.0001 ? '+' : ($delta < -0.0001 ? '-' : '');

        return $sign.Format::euro(abs($delta), 2).'/'.$suffix;
    }

    private function unitPriceTitle(
        float $budgetHours,
        float $forecastHours,
        float $actualHours,
        ?float $hourlyRate,
        float $orderedQty,
        float $completedQty,
        ?float $budgetPrice,
        ?float $forecastPrice,
        ?float $actualPrice,
        ?float $delta,
        string $suffix,
    ): ?string {
        if ($hourlyRate === null && $budgetPrice === null && $forecastPrice === null && $actualPrice === null) {
            return null;
        }

        $lines = [];
        if ($budgetPrice !== null && $hourlyRate !== null) {
            $lines[] = 'Begroot: '.PlanningHours::hoursLabel($budgetHours)
                .' × '.Format::euroWhole($hourlyRate)
                .' / '.Format::qty($orderedQty).$suffix
                .' = '.Format::euro($budgetPrice, 2).'/'.$suffix;
        }

        if ($forecastPrice !== null && $hourlyRate !== null) {
            $lines[] = 'Prognose: '.PlanningHours::hoursLabel($forecastHours)
                .' × '.Format::euroWhole($hourlyRate)
                .' / '.Format::qty($orderedQty).$suffix
                .' = '.Format::euro($forecastPrice, 2).'/'.$suffix;
        }

        if ($actualPrice !== null && $hourlyRate !== null) {
            $lines[] = 'Werkelijk: '.PlanningHours::hoursLabel($actualHours)
                .' × '.Format::euroWhole($hourlyRate)
                .' / '.Format::qty($completedQty).$suffix
                .' = '.Format::euro($actualPrice, 2).'/'.$suffix;
        }

        if ($delta !== null) {
            $lines[] = 'Verschil: '.$this->deltaLabel($delta, $suffix);
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @return array{remaining_hours: ?float, overrun_hours: float, used_percent: ?int, tone: string, warning: ?string, warnings: list<string>}
     */
    private function status(
        float $budgetHours,
        float $usedHours,
        float $plannedHours = 0.0,
        float $actualHours = 0.0,
        ?float $budgetCostPerM2 = null,
        ?float $actualCostPerM2 = null,
        string $priceSuffix = 'm²',
    ): array {
        $warnings = [];
        if ($budgetHours > 0.0001 && $actualHours > $budgetHours + 0.0001) {
            $warnings[] = 'Gemaakte uren liggen boven begroot';
        }
        if ($budgetHours > 0.0001 && $plannedHours > $budgetHours + 0.0001) {
            $warnings[] = 'Ingeplande uren liggen boven begroot';
        }
        if ($budgetCostPerM2 !== null && $actualCostPerM2 !== null && $actualCostPerM2 > $budgetCostPerM2 + 0.0001) {
            $warnings[] = 'Werkelijke arbeidsprijs per '.$priceSuffix.' ligt boven begroot';
        }

        if ($budgetHours <= 0.0001) {
            return [
                'remaining_hours' => null,
                'overrun_hours' => 0.0,
                'used_percent' => null,
                'tone' => $warnings !== [] ? 'over' : 'none',
                'warning' => $warnings[0] ?? null,
                'warnings' => $warnings,
            ];
        }

        $percent = (int) round($usedHours / $budgetHours * 100);
        $overrun = round(max(0, $usedHours - $budgetHours), 2);
        $remaining = round(max(0, $budgetHours - $usedHours), 2);
        $tone = $percent > 100 || $warnings !== [] ? 'over' : ($percent >= 80 ? 'warn' : 'ok');
        if ($tone === 'over' && $warnings === []) {
            $warnings[] = 'uren overschreden';
        } elseif ($tone === 'warn' && $warnings === []) {
            $warnings[] = 'uren bijna op';
        }

        return [
            'remaining_hours' => $remaining,
            'overrun_hours' => $overrun,
            'used_percent' => $percent,
            'tone' => $tone,
            'warning' => $warnings[0] ?? null,
            'warnings' => $warnings,
        ];
    }

    private function budgetCompact(?string $title, float $budgetHours, float $plannedHours, float $actualHours): ?string
    {
        if ($budgetHours <= 0.0001) {
            return null;
        }

        $remaining = round($budgetHours - $actualHours, 2);
        $parts = array_values(array_filter([
            $title,
            'Begroot '.PlanningHours::hoursLabel($budgetHours),
            'Ingepland '.PlanningHours::hoursLabel($plannedHours),
            'Gemaakt '.PlanningHours::hoursLabel($actualHours),
            'Budget over '.PlanningHours::hoursLabel($remaining),
        ], fn (?string $part): bool => $part !== null && $part !== ''));

        return implode(' | ', $parts);
    }

    /**
     * @param  array{remaining_hours: ?float, overrun_hours: float, used_percent: ?int, tone: string, warning: ?string, warnings?: list<string>}  $status
     */
    private function hoursCompact(
        ?string $title,
        float $budgetHours,
        float $usedHours,
        float $plannedHours,
        float $actualHours,
        array $status,
    ): ?string {
        if ($budgetHours > 0.0001) {
            return $this->budgetCompact($title, $budgetHours, $plannedHours, $actualHours);
        }

        if ($plannedHours <= 0.0001 && $actualHours <= 0.0001) {
            return null;
        }

        return implode(' | ', array_values(array_filter([
            $title,
            $plannedHours > 0.0001 ? 'Ingepland '.PlanningHours::hoursLabel($plannedHours) : null,
            $actualHours > 0.0001 ? 'Gemaakt '.PlanningHours::hoursLabel($actualHours) : null,
        ], fn (?string $part): bool => $part !== null && $part !== '')));
    }

    private function hoursAndCostSummary(float $plannedHours, ?float $hourlyRate, float $laborCost, ?float $costPerM2): string
    {
        $parts = [PlanningHours::hoursLabel($plannedHours)];
        if ($hourlyRate === null) {
            return $parts[0];
        }

        $parts[] = Format::euroWhole($hourlyRate).'/u';
        $parts[] = Format::euroWhole($laborCost).' arbeid';
        $parts[] = $costPerM2 === null ? '—' : Format::euro($costPerM2, 2).'/m²';

        return implode(' · ', $parts);
    }

    private function projectDetail(
        float $plannedHours,
        float $actualHours,
        ?float $hourlyRate,
        float $laborCost,
        float $completedM2,
        ?float $costPerM2,
    ): string {
        $rateLabel = $hourlyRate === null ? '—' : Format::euroWhole($hourlyRate);
        $laborLabel = $hourlyRate === null ? '—' : Format::euroWhole($laborCost);
        $m2Label = $costPerM2 === null ? '—' : Format::euro($costPerM2, 2).'/m²';

        return implode(' | ', [
            'Uren: '.PlanningHours::hoursLabel($plannedHours),
            'Tarief: '.$rateLabel,
            'Arbeid: '.$laborLabel,
            'Gereed: '.Format::qty($completedM2).' m²',
            'Werkelijk: '.PlanningHours::hoursLabel($actualHours),
            $m2Label,
        ]);
    }

    private function financeLine(?float $budgetPrice, ?float $actualPrice, string $unit): ?string
    {
        if ($budgetPrice === null && $actualPrice === null) {
            return null;
        }

        $suffix = $unit !== '' ? '/'.$unit : '';
        $parts = [];
        if ($budgetPrice !== null) {
            $parts[] = 'Begroot '.Format::euro($budgetPrice, 2).$suffix;
        }
        if ($actualPrice !== null) {
            $parts[] = 'Werkelijk '.Format::euro($actualPrice, 2).$suffix;
        }
        if ($budgetPrice !== null && $actualPrice !== null) {
            $parts[] = $this->deltaLabel(round($actualPrice - $budgetPrice, 2), $unit);
        }

        return implode(' · ', $parts);
    }

    private function hoursNumber(float $hours): string
    {
        return fmod($hours, 1.0) === 0.0
            ? (string) (int) $hours
            : rtrim(rtrim(number_format($hours, 1, ',', ''), '0'), ',');
    }

    /**
     * @return array{hours_delta: float, hours_delta_label: ?string, hours_over: bool}
     */
    private function hoursDelta(float $plannedHours, float $actualHours, float $budgetHours = 0.0): array
    {
        if ($plannedHours <= 0.0001 && $actualHours <= 0.0001) {
            return [
                'hours_delta' => 0.0,
                'hours_delta_label' => null,
                'hours_over' => $budgetHours > 0.0001 && $plannedHours > $budgetHours + 0.0001,
            ];
        }

        $delta = round($plannedHours - $actualHours, 2);
        $over = $delta < -0.0001
            || ($budgetHours > 0.0001 && ($actualHours > $budgetHours + 0.0001 || $plannedHours > $budgetHours + 0.0001));
        $label = ($delta < -0.0001)
            ? PlanningHours::hoursLabel($delta).' ⚠'
            : (($delta > 0.0001 ? '+' : '').PlanningHours::hoursLabel($delta));

        return [
            'hours_delta' => $delta,
            'hours_delta_label' => $label,
            'hours_over' => $over,
        ];
    }

    /**
     * @return array{budget_remaining: ?float, budget_remaining_label: ?string, budget_remaining_over: bool}
     */
    private function budgetRemaining(float $budgetHours, float $actualHours): array
    {
        if ($budgetHours <= 0.0001) {
            return [
                'budget_remaining' => null,
                'budget_remaining_label' => null,
                'budget_remaining_over' => false,
            ];
        }

        $remaining = round($budgetHours - $actualHours, 2);

        return [
            'budget_remaining' => $remaining,
            'budget_remaining_label' => PlanningHours::hoursLabel($remaining),
            'budget_remaining_over' => $remaining < -0.0001,
        ];
    }

    /**
     * @param  array{warnings?: list<string>, overrun_hours?: float}  $status
     */
    private function overrunLabel(array $status, string $scope): ?string
    {
        $warnings = $status['warnings'] ?? [];
        if ($warnings === []) {
            return null;
        }

        return '⚠ '.$scope.': '.implode(' · ', $warnings);
    }
}
