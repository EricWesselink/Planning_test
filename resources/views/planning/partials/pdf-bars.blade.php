@php
    $showPeriod = $showPeriod ?? false;
    $periodBar = $showPeriod ? ($periodBar ?? null) : null;
    $startMarker = $showPeriod ? ($startMarker ?? null) : null;
    $endMarker = $showPeriod ? ($endMarker ?? null) : null;
@endphp
<div class="days" style="min-height: {{ $height }}px">
    <div class="day-grid" aria-hidden="true">
        @foreach ($days as $day)
            <i></i>
        @endforeach
    </div>
    <div class="bars" style="min-height: {{ $height }}px">
        @if ($periodBar)
            <div class="period-band"
                 style="width: calc({{ $periodBar['span'] }} * 100% / {{ $dayCount }} - 2px); left: calc({{ $periodBar['start'] }} * 100% / {{ $dayCount }} + 1px);"></div>
        @endif
        @if ($startMarker)
            <div class="period-marker"
                 style="width: calc(100% / {{ $dayCount }} - 4px); left: calc({{ $startMarker['index'] }} * 100% / {{ $dayCount }} + 2px);">
                ▶ Start {{ $startMarker['date'] }}
            </div>
        @endif
        @if ($endMarker)
            <div class="period-marker{{ ! empty($endMarker['done']) ? ' is-done' : '' }}"
                 style="width: calc(100% / {{ $dayCount }} - 4px); left: calc({{ $endMarker['index'] }} * 100% / {{ $dayCount }} + 2px);">
                Klaar {{ $endMarker['date'] }}
            </div>
        @endif
        @foreach ($personBars as $index => $personBar)
            <div class="bar{{ ! empty($personBar['has_budget_overrun']) ? ' is-over' : '' }}"
                 style="background: {{ $personBar['color'] }}; top: {{ 4 + ($index * 20) }}px; width: calc(({{ $personBar['bar']['span'] }} - {{ $personBar['bar']['start_offset'] ?? 0 }} - (1 - {{ $personBar['bar']['end_offset'] ?? 1 }})) * 100% / {{ $dayCount }} - 2px); left: calc(({{ $personBar['bar']['start'] }} + {{ $personBar['bar']['start_offset'] ?? 0 }}) * 100% / {{ $dayCount }} + 1px);">
                @if (! empty($personBar['has_budget_overrun']))
                    <span class="bar-overrun" style="left: {{ $personBar['overrun_from'] ?? '100%' }}"></span>
                @endif
                @if (! empty($showNames))
                    <span class="bar-name">{{ $personBar['label'] }}</span>
                @endif
            </div>
        @endforeach
    </div>
</div>
