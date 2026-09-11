@php
    /** @var array<string, mixed>|null $labor */
    $labor = $labor ?? null;
    $showCost = $showCost ?? true;
@endphp
@if ($labor)
    @if (! empty($labor['overrun_label']))
        <div class="plan-labor plan-labor--over">{{ $labor['overrun_label'] }}</div>
    @endif
    @if (! empty($labor['budget_summary']) || ! empty($labor['board_line']) || ! empty($labor['compact']))
        <div class="plan-labor plan-labor--{{ $labor['tone'] ?? 'none' }}" title="{{ $labor['finance'] ?? $labor['detail'] ?? '' }}">{{ $labor['budget_summary'] ?? $labor['board_line'] ?? $labor['compact'] }}</div>
    @endif
    @if (($labor['bar_label'] ?? null) !== null && ($labor['bar_percent'] ?? null) !== null)
        <div class="plan-hour-bar plan-hour-bar--{{ $labor['tone'] ?? 'none' }}" title="{{ $labor['bar_label'] }}">
            <span class="plan-hour-bar-track" aria-hidden="true">
                <span class="plan-hour-bar-fill" style="width: {{ $labor['bar_percent'] }}%"></span>
            </span>
            <span class="plan-hour-bar-label">{{ $labor['bar_label'] }}</span>
        </div>
    @endif
    @if ($showCost && ! empty($labor['summary']) && empty($labor['compact']))
        <div class="plan-labor" title="{{ $labor['detail'] ?? '' }}">{{ $labor['summary'] }}</div>
    @endif
@endif
