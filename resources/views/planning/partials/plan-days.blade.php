@php
    $showPeriod = $showPeriod ?? false;
    $periodBar = $showPeriod ? ($periodBar ?? null) : null;
    $startMarker = $showPeriod ? ($startMarker ?? null) : null;
    $endMarker = $showPeriod ? ($endMarker ?? null) : null;
    $personBarOffset = $showPeriod ? ($personBarOffset ?? 16) : 4;
@endphp
<div class="plan-days person-stack"
     data-project-id="{{ $projectId }}"
     @if (! empty($workItemId)) data-work-item-id="{{ $workItemId }}" @endif
     @if (! empty($plannedHours)) data-hours="{{ $plannedHours }}" @endif>
    @foreach ($days as $day)
        <div class="drop-day{{ $loop->first ? '' : ' day-start' }}{{ $day->isMonday() && ! $loop->first ? ' week-start' : '' }}{{ $day->isSaturday() ? ' is-saturday' : '' }}" data-date="{{ $day->toDateString() }}"></div>
    @endforeach
    @if ($periodBar)
        <div class="period-band"
             style="width: calc({{ $periodBar['span'] }} * 100% / {{ $dayCount }} - 2px); left: calc({{ $periodBar['start'] }} * 100% / {{ $dayCount }} + 1px);"
             title="Geplande uitvoeringsperiode"></div>
    @endif
    @if ($startMarker)
        <div class="period-marker period-marker--start"
             title="Start werk {{ $startMarker['date'] }}"
             style="width: calc(100% / {{ $dayCount }} - 4px); left: calc({{ $startMarker['index'] }} * 100% / {{ $dayCount }} + 2px);">
            ▶ Start {{ $startMarker['date'] }}
        </div>
    @endif
    @if ($endMarker)
        <div class="period-marker period-marker--end{{ ! empty($endMarker['done']) ? ' is-done' : '' }}"
             title="Klaar werk {{ $endMarker['date'] }}"
             style="width: calc(100% / {{ $dayCount }} - 4px); left: calc({{ $endMarker['index'] }} * 100% / {{ $dayCount }} + 2px);">
            Klaar {{ $endMarker['date'] }}
        </div>
    @endif
    @foreach ($personBars as $index => $personBar)
        @include('planning.partials.person-bar', [
            'personBar' => $personBar,
            'index' => $personBar['stack'] ?? $index,
            'dayCount' => $dayCount,
            'personBarOffset' => $personBarOffset,
        ])
    @endforeach
</div>
