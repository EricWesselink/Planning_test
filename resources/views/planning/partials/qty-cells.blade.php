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
