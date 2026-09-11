@php
    /** @var array<string, mixed> $row */
    $showLabor = $showLabor ?? false;
    $labor = $row['labor'] ?? null;
    $budgetHours = (float) ($labor['budget_hours'] ?? 0);
    $plannedHours = (float) ($labor['planned_hours'] ?? 0);
    $actualHours = (float) ($labor['actual_hours'] ?? 0);
    $hasBudget = $budgetHours > 0.0001;
    $hasHours = $hasBudget || $plannedHours > 0.0001 || $actualHours > 0.0001;
    $remaining = $labor['budget_remaining'] ?? null;
    $actualM2 = $labor['actual_cost_per_m2'] ?? $labor['actual_unit_price'] ?? null;
    $budgetM2 = $labor['budget_cost_per_m2'] ?? $labor['budget_unit_price'] ?? null;
    $deltaTone = $labor['cost_delta_tone'] ?? 'none';
    $deltaLabel = $labor['cost_delta_label'] ?? null;
    $priceTitle = $labor['unit_price_title'] ?? null;
    $priceClass = match ($deltaTone) {
        'over' => ' plan-cell--labor-over text-nicon-danger font-semibold',
        'ok' => ' plan-cell--labor-ok text-nicon-ok font-semibold',
        default => '',
    };
    $forecast = \App\Support\PlanningLaborForecast::forBoard($budgetHours, $plannedHours, $actualHours, $remaining === null ? null : (float) $remaining);
    $plannedClass = match ($forecast['planned_tone']) {
        'over' => ' plan-cell--labor-over text-nicon-danger font-semibold',
        'warn' => ' plan-cell--labor-warn text-nicon-warn',
        default => '',
    };
    $restClass = match ($forecast['rest_tone']) {
        'over' => ' plan-cell--labor-over text-nicon-danger font-semibold',
        'warn' => ' plan-cell--labor-warn text-nicon-warn',
        default => '',
    };
@endphp
<div class="plan-cell plan-cell--num">
    @if (($row['ordered'] ?? null) !== null)
        {{ \App\Support\Format::qty($row['ordered'], $row['ordered_decimals'] ?? 0) }}{{ ! empty($row['unit']) ? ' '.$row['unit'] : '' }}
    @endif
</div>
<div class="plan-cell plan-cell--num">
    @if (($row['completed'] ?? null) !== null)
        {{ \App\Support\Format::qty($row['completed']) }}
    @endif
</div>
<div class="plan-cell plan-cell--num">
    @if (($row['remaining'] ?? null) !== null)
        {{ \App\Support\Format::qty($row['remaining']) }}
    @endif
</div>
<div class="plan-cell plan-cell--num">
    @if (($row['percent'] ?? null) !== null)
        {{ $row['percent'] }}%
    @endif
</div>
@if ($showLabor)
    <div class="plan-labor-toggle plan-labor-toggle--open" aria-hidden="true"></div>
    <div class="plan-labor-block">
        <div class="plan-labor-block-inner">
            <div class="plan-cell plan-cell--num plan-cell--labor">{{ $hasBudget ? \App\Support\PlanningHours::hoursLabel($budgetHours) : '—' }}</div>
            <div class="plan-cell plan-cell--num plan-cell--labor{{ $plannedClass }}{{ $forecast['planned_overrun_label'] ? ' plan-cell--labor-stack' : '' }}"@if ($forecast['planned_title'] && $hasHours) title="{{ $forecast['planned_title'] }}"@endif>
                @if ($hasHours)
                    {{ \App\Support\PlanningHours::hoursLabel($plannedHours) }}
                    @if ($forecast['planned_overrun_label'])
                        <span class="plan-labor-alert">{{ $forecast['planned_overrun_label'] }}</span>
                    @endif
                @else
                    —
                @endif
            </div>
            <div class="plan-cell plan-cell--num plan-cell--labor">{{ $hasHours ? \App\Support\PlanningHours::hoursLabel($actualHours) : '—' }}</div>
            <div class="plan-cell plan-cell--num plan-cell--labor{{ $restClass }}{{ $forecast['rest_tone'] === 'over' ? ' plan-cell--labor-stack' : '' }}" title="{{ $forecast['rest_title'] }}">
                @if ($forecast['rest_tone'] === 'over')
                    <span>Overschreden</span>
                    <span class="plan-labor-alert">+{{ \App\Support\PlanningHours::hoursLabel(abs((float) $remaining)) }}</span>
                @else
                    {{ $forecast['rest_label'] }}
                @endif
            </div>
            <div class="plan-cell plan-cell--num plan-cell--labor"@if ($priceTitle && $budgetM2 !== null) title="{{ $priceTitle }}"@endif>{{ $budgetM2 === null ? '—' : \App\Support\Format::euro($budgetM2, 2) }}</div>
            <div class="plan-cell plan-cell--num plan-cell--labor{{ $actualM2 === null ? '' : $priceClass }}"@if ($priceTitle) title="{{ $priceTitle }}"@endif>{{ $actualM2 === null ? '—' : \App\Support\Format::euro($actualM2, 2) }}</div>
            <div class="plan-cell plan-cell--num plan-cell--labor plan-cell--labor-delta{{ $deltaLabel === null ? '' : $priceClass }}"@if ($priceTitle) title="{{ $priceTitle }}"@endif>{{ $deltaLabel === null ? '—' : $deltaLabel }}</div>
        </div>
    </div>
    <div class="plan-labor-toggle plan-labor-toggle--close" aria-hidden="true"></div>
@endif
