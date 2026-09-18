@php
    /** @var array<string, mixed> $labor */
    /** @var array<string, mixed>|null $orderFinance */
    $orderFinance = $orderFinance ?? null;
    $budgetHours = (float) ($labor['budget_hours'] ?? 0);
    $plannedHours = (float) ($labor['planned_hours'] ?? 0);
    $actualHours = (float) ($labor['actual_hours'] ?? 0);
    $hasHours = $budgetHours > 0.0001 || $plannedHours > 0.0001 || $actualHours > 0.0001;
    $vsBudget = round($plannedHours - $budgetHours, 2);
    $plannedOver = $budgetHours > 0.0001 && $plannedHours > $budgetHours + 0.0001;
    $vsBudgetLabel = ($vsBudget > 0.0001 ? '+' : '').\App\Support\PlanningHours::hoursLabel($vsBudget);
    $hoursOverPercent = $plannedOver ? (int) round($vsBudget / $budgetHours * 100) : null;
    $unit = $labor['unit'] ?? 'm²';
    $otherWarnings = array_values(array_filter(
        $labor['warnings'] ?? [],
        fn (string $warning): bool => ! in_array($warning, [
            'Ingeplande uren liggen boven begroot',
            'uren overschreden',
            'uren bijna op',
        ], true),
    ));
@endphp
<div class="mt-4 space-y-3" @if ($labor['detail'] ?? null) title="{{ $labor['detail'] }}" @endif>
    @if (is_array($orderFinance))
        <p class="text-sm text-nicon-steel">{{ $orderFinance['compact'] }}</p>
        <div class="grid max-w-xl grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Order</div>
                <div class="text-lg font-semibold tabular-nums text-nicon-ink">{{ $orderFinance['order_label'] }}</div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Begrote kosten</div>
                <div class="text-lg font-semibold tabular-nums text-nicon-ink">{{ $orderFinance['budget_cost_label'] }}</div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Werkelijke kosten</div>
                <div class="text-lg font-semibold tabular-nums text-nicon-ink">{{ $orderFinance['actual_cost_label'] }}</div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Resultaat</div>
                <div @class([
                    'text-lg font-semibold tabular-nums',
                    'text-nicon-ok' => ($orderFinance['result_tone'] ?? '') === 'ok',
                    'text-nicon-danger' => ($orderFinance['result_tone'] ?? '') === 'over',
                    'text-nicon-ink' => ($orderFinance['result_tone'] ?? '') === 'none',
                ])>{{ $orderFinance['actual_result_label'] }}</div>
            </div>
        </div>
        <p @class([
            'text-sm',
            'text-nicon-ok' => ($orderFinance['budget_tone'] ?? '') === 'ok',
            'text-nicon-danger' => ($orderFinance['budget_tone'] ?? '') === 'over',
        ])>{{ $orderFinance['overrun_line'] }}</p>
        @if ($hasHours)
            <p class="text-xs text-nicon-muted">Begroot {{ \App\Support\PlanningHours::hoursLabel($budgetHours) }} · Ingepland <span @class(['font-medium text-nicon-warn' => $plannedOver])>{{ \App\Support\PlanningHours::hoursLabel($plannedHours) }}</span> · Gemaakt {{ \App\Support\PlanningHours::hoursLabel($actualHours) }}</p>
        @endif
        @if ($plannedOver)
            <p class="text-sm font-medium text-nicon-warn">Prognose {{ \App\Support\PlanningHours::hoursLabel($vsBudget) }} / {{ $hoursOverPercent }}% boven urenbudget</p>
        @endif
    @elseif ($hasHours)
        <div class="grid max-w-xl grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Begroot</div>
                <div class="text-lg font-semibold tabular-nums text-nicon-ink">{{ \App\Support\PlanningHours::hoursLabel($budgetHours) }}</div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Ingepland</div>
                <div @class([
                    'text-lg font-semibold tabular-nums',
                    'text-nicon-danger' => $plannedOver,
                    'text-nicon-ink' => ! $plannedOver,
                ])>{{ \App\Support\PlanningHours::hoursLabel($plannedHours) }}</div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Gemaakt</div>
                <div class="text-lg font-semibold tabular-nums text-nicon-ink">{{ \App\Support\PlanningHours::hoursLabel($actualHours) }}</div>
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Verschil</div>
                <div @class([
                    'text-lg font-semibold tabular-nums',
                    'text-nicon-danger' => $plannedOver,
                    'text-nicon-ink' => ! $plannedOver,
                ])>{{ $vsBudgetLabel }}</div>
            </div>
        </div>
        @if ($plannedOver)
            <p class="text-sm font-medium text-nicon-danger">⚠ {{ rtrim(\App\Support\PlanningHours::hoursLabel($vsBudget), 'u') }} uur meer ingepland dan begroot</p>
        @endif
    @endif

    @foreach ($otherWarnings as $warning)
        <p class="text-sm font-medium text-nicon-danger">⚠ {{ $warning }}</p>
    @endforeach
    @if (! empty($labor['extra_summary']))
        <p class="text-xs text-nicon-steel">{{ $labor['extra_summary'] }}</p>
    @endif

    @if (($labor['hourly_rate'] ?? null) !== null || $hasHours)
        <p class="text-xs text-nicon-steel">
            Tarief {{ $labor['hourly_rate'] === null ? '—' : \App\Support\Format::euroWhole($labor['hourly_rate']).'/u' }}
            <span class="text-nicon-muted"> | </span>
            Arbeid {{ $labor['hourly_rate'] === null ? '—' : \App\Support\Format::euroWhole(is_array($orderFinance) ? ($labor['actual_labor_cost'] ?? 0) : $labor['labor_cost']) }}
            <span class="text-nicon-muted"> | </span>
            Gereed {{ \App\Support\Format::qty($labor['completed_m2']) }} m²
            <span class="text-nicon-muted"> | </span>
            Begroot {{ ($labor['budget_cost_per_m2'] ?? $labor['budget_unit_price'] ?? null) === null ? '€/'.$unit.' —' : \App\Support\Format::euro($labor['budget_cost_per_m2'] ?? $labor['budget_unit_price'], 2).'/'.$unit }}
            <span class="text-nicon-muted"> | </span>
            Werkelijk €/{{ $unit }} {{ ($labor['actual_cost_per_m2'] ?? $labor['actual_unit_price'] ?? null) === null ? '—' : \App\Support\Format::euro($labor['actual_cost_per_m2'] ?? $labor['actual_unit_price'], 2) }}
        </p>
    @endif

    @if (! empty($labor['items']))
        <div class="space-y-2">
            @foreach ($labor['items'] as $itemLabor)
                @if (($itemLabor['budget_hours'] ?? 0) <= 0 && ($itemLabor['used_hours'] ?? 0) <= 0)
                    @continue
                @endif
                @php
                    $itemBudget = (float) ($itemLabor['budget_hours'] ?? 0);
                    $itemPlanned = (float) ($itemLabor['planned_hours'] ?? 0);
                    $itemOver = $itemBudget > 0.0001 && $itemPlanned > $itemBudget + 0.0001;
                    $itemUnit = $itemLabor['unit'] ?? $unit;
                    $itemBudgetPrice = $itemLabor['budget_cost_per_m2'] ?? $itemLabor['budget_unit_price'] ?? null;
                @endphp
                <div>
                    <div class="text-sm font-medium text-nicon-ink">{{ $itemLabor['title'] }}</div>
                    <div class="text-xs text-nicon-muted">Begroot {{ \App\Support\PlanningHours::hoursLabel($itemBudget) }} · Ingepland <span @class(['font-medium text-nicon-danger' => $itemOver])>{{ \App\Support\PlanningHours::hoursLabel($itemPlanned) }}</span> · Gemaakt {{ \App\Support\PlanningHours::hoursLabel($itemLabor['actual_hours'] ?? 0) }}</div>
                    @if (($itemLabor['bar_label'] ?? null) !== null && ($itemLabor['bar_percent'] ?? null) !== null)
                        <div class="plan-hour-bar plan-hour-bar--{{ $itemLabor['tone'] ?? 'none' }}" style="max-width: 16rem">
                            <span class="plan-hour-bar-track" aria-hidden="true">
                                <span class="plan-hour-bar-fill" style="width: {{ $itemLabor['bar_percent'] }}%"></span>
                            </span>
                            <span class="plan-hour-bar-label">{{ $itemLabor['bar_label'] }}</span>
                        </div>
                    @endif
                    @if ($itemBudgetPrice !== null)
                        <div class="text-xs text-nicon-muted">Begroot {{ \App\Support\Format::euro($itemBudgetPrice, 2) }}/{{ $itemUnit }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
