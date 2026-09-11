@php
    /** @var array<string, mixed> $row */
    $labor = $row['labor'] ?? null;
    $hasHours = $labor
        && (
            ($labor['budget_hours'] ?? 0) > 0.0001
            || ($labor['planned_hours'] ?? 0) > 0.0001
            || ($labor['actual_hours'] ?? 0) > 0.0001
        );
    $budgetM2 = $labor['budget_cost_per_m2'] ?? $labor['budget_unit_price'] ?? null;
    $actualM2 = $labor['actual_cost_per_m2'] ?? $labor['actual_unit_price'] ?? null;
    $deltaTone = $labor['cost_delta_tone'] ?? 'none';
    $deltaLabel = $labor['cost_delta_label'] ?? null;
    $priceClass = $deltaTone === 'over' ? ' over' : ($deltaTone === 'ok' ? ' ok' : '');
@endphp
<td class="col-num">{{ $hasHours && ($labor['budget_hours'] ?? 0) > 0.0001 ? \App\Support\PlanningHours::hoursLabel($labor['budget_hours']) : '' }}</td>
<td class="col-num">{{ $hasHours ? \App\Support\PlanningHours::hoursLabel($labor['planned_hours'] ?? 0) : '' }}</td>
<td class="col-num{{ ! empty($labor['hours_over']) ? ' over' : '' }}">{{ $hasHours ? \App\Support\PlanningHours::hoursLabel($labor['actual_hours'] ?? 0) : '' }}</td>
<td class="col-num{{ ! empty($labor['budget_remaining_over']) ? ' over' : '' }}">{{ ($labor['budget_remaining_label'] ?? null) === null ? '' : $labor['budget_remaining_label'] }}</td>
<td class="col-num{{ ! empty($labor['hours_over']) ? ' over' : '' }}">{{ $labor['hours_delta_label'] ?? '' }}</td>
<td class="col-num">{{ $budgetM2 === null ? '—' : \App\Support\Format::money($budgetM2) }}</td>
<td class="col-num{{ $actualM2 === null ? '' : $priceClass }}">{{ $actualM2 === null ? '—' : \App\Support\Format::money($actualM2) }}</td>
<td class="col-num{{ $deltaLabel === null ? '' : $priceClass }}">{{ $deltaLabel === null ? '—' : $deltaLabel }}</td>
