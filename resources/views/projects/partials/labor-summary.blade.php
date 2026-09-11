@php
    /** @var array<string, mixed> $labor */
@endphp
<div class="mt-1 space-y-1 text-xs" @if ($labor['detail'] ?? null) title="{{ $labor['detail'] }}" @endif>
    @if (! empty($labor['overrun_label']))
        <div class="font-semibold text-nicon-danger">{{ $labor['overrun_label'] }}</div>
    @endif
    @if (! empty($labor['budget_summary']))
        <div @class([
            'font-medium',
            'text-nicon-danger' => ($labor['tone'] ?? '') === 'over',
            'text-nicon-warn' => ($labor['tone'] ?? '') === 'warn',
            'text-nicon-muted' => ! in_array($labor['tone'] ?? '', ['over', 'warn'], true),
        ])>{{ $labor['budget_summary'] }}</div>
    @endif
    <div class="flex flex-wrap gap-x-4 gap-y-0.5 text-nicon-muted">
        @if (($labor['budget_hours'] ?? 0) > 0.0001)
            <span>Begroot: {{ \App\Support\PlanningHours::hoursLabel($labor['budget_hours']) }}</span>
        @endif
        <span>Ingepland: {{ \App\Support\PlanningHours::hoursLabel($labor['planned_hours']) }}</span>
        <span>Gemaakt: {{ \App\Support\PlanningHours::hoursLabel($labor['actual_hours']) }}</span>
        @if (($labor['budget_remaining'] ?? null) !== null)
            <span @class([
                'font-semibold',
                'text-nicon-danger' => ! empty($labor['budget_remaining_over']),
            ])>Budget over: {{ $labor['budget_remaining_label'] }}</span>
        @endif
        @if (($labor['hours_delta_label'] ?? null) !== null)
            <span class="text-nicon-muted" title="Planning: Ingepland − Gemaakt">Planningverschil: {{ $labor['hours_delta_label'] }}</span>
        @endif
        <span>Tarief: {{ $labor['hourly_rate'] === null ? '—' : \App\Support\Format::euroWhole($labor['hourly_rate']).'/u' }}</span>
        <span>Gereed: {{ \App\Support\Format::qty($labor['completed_m2']) }} m²</span>
        <span>Arbeid: {{ $labor['hourly_rate'] === null ? '—' : \App\Support\Format::euroWhole($labor['labor_cost']) }}</span>
        <span>Begroot €/{{ $labor['unit'] ?? 'm²' }}: {{ ($labor['budget_cost_per_m2'] ?? $labor['budget_unit_price'] ?? null) === null ? '—' : \App\Support\Format::euro($labor['budget_cost_per_m2'] ?? $labor['budget_unit_price'], 2) }}</span>
        <span>Werkelijk €/{{ $labor['unit'] ?? 'm²' }}: {{ ($labor['actual_cost_per_m2'] ?? $labor['actual_unit_price'] ?? null) === null ? '—' : \App\Support\Format::euro($labor['actual_cost_per_m2'] ?? $labor['actual_unit_price'], 2) }}</span>
        @if (($labor['cost_delta_label'] ?? null) !== null)
            <span @class([
                'font-semibold',
                'text-nicon-ok' => ($labor['cost_delta_tone'] ?? '') === 'ok',
                'text-nicon-danger' => ($labor['cost_delta_tone'] ?? '') === 'over',
            ])>Verschil: {{ $labor['cost_delta_label'] }}</span>
        @endif
    </div>
    @if (! empty($labor['items']))
        <div class="mt-1 space-y-1">
            @foreach ($labor['items'] as $itemLabor)
                @if (($itemLabor['budget_hours'] ?? 0) <= 0 && ($itemLabor['used_hours'] ?? 0) <= 0)
                    @continue
                @endif
                <div>
                    @if (! empty($itemLabor['compact']))
                        <div @class([
                            'text-nicon-danger' => ($itemLabor['tone'] ?? '') === 'over',
                            'text-nicon-warn' => ($itemLabor['tone'] ?? '') === 'warn',
                        ])>{{ $itemLabor['compact'] }}@if (! empty($itemLabor['warning'])) · {{ $itemLabor['warning'] }}@endif</div>
                    @else
                        <div class="text-nicon-muted">{{ $itemLabor['title'] }} · ingepland {{ \App\Support\PlanningHours::hoursLabel($itemLabor['planned_hours']) }} · gemaakt {{ \App\Support\PlanningHours::hoursLabel($itemLabor['actual_hours']) }}</div>
                    @endif
                    @if (($itemLabor['bar_label'] ?? null) !== null && ($itemLabor['bar_percent'] ?? null) !== null)
                        <div class="plan-hour-bar plan-hour-bar--{{ $itemLabor['tone'] ?? 'none' }}" style="max-width: 16rem">
                            <span class="plan-hour-bar-track" aria-hidden="true">
                                <span class="plan-hour-bar-fill" style="width: {{ $itemLabor['bar_percent'] }}%"></span>
                            </span>
                            <span class="plan-hour-bar-label">{{ $itemLabor['bar_label'] }}</span>
                        </div>
                    @endif
                    @if (! empty($itemLabor['finance']))
                        <div @class([
                            'text-nicon-ok' => ($itemLabor['cost_delta_tone'] ?? '') === 'ok',
                            'text-nicon-danger' => ($itemLabor['cost_delta_tone'] ?? '') === 'over',
                            'text-nicon-muted' => ($itemLabor['cost_delta_tone'] ?? 'none') === 'none',
                        ])>{{ $itemLabor['finance'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
