@php
    $dayCount = (int) ($dayCount ?? count($days));
    $projects = $projects ?? [];
@endphp
@if ($projects === [])
    <p class="personnel-week-empty">Geen werken ingepland in deze week.</p>
@else
    <table class="personnel-week-board">
        <thead>
            <tr>
                <th class="personnel-week-werk">Werk</th>
                @foreach ($days as $day)
                    <th>
                        <div>{{ $day['name'] }}</div>
                        <div>{{ $day['date'] }}</div>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($projects as $project)
                @foreach ($project['works'] as $workIndex => $work)
                    @php
                        $barHeight = (int) ($work['bar_height'] ?? 16);
                        $hasMarkers = (int) $workIndex === 0 && (! empty($project['start_marker']) || ! empty($project['end_marker']));
                        $rowHeight = (int) ($work['height'] ?? 16) + ($hasMarkers ? 12 : 0);
                    @endphp
                    <tr>
                        <td class="personnel-week-werk{{ (int) $workIndex === 0 ? ' personnel-week-project-cell' : '' }}{{ ! empty($project['shop']) ? ' is-shop' : '' }}{{ ! empty($project['away']) ? ' is-away' : '' }}">
                            @if ((int) $workIndex === 0)
                                @if (! empty($project['type_label']))
                                    <div class="personnel-week-type">{{ $project['type_label'] }}</div>
                                @endif
                                @if (! empty($project['title']))
                                    <div class="personnel-week-title">{{ $project['title'] }}</div>
                                @endif
                                @if (! empty($project['customer']))
                                    <div class="personnel-week-customer">{{ $project['customer'] }}</div>
                                @endif
                                @if (! empty($project['place']))
                                    <div class="personnel-week-meta">{{ $project['place'] }}</div>
                                @endif
                                @if (! empty($project['number']))
                                    <div class="personnel-week-meta">{{ $project['number'] }}</div>
                                @endif
                            @endif
                            @if (! empty($work['title']))
                                <div class="personnel-week-work-title">{{ $work['title'] }}</div>
                            @endif
                        </td>
                        <td colspan="{{ $dayCount }}">
                            <div class="personnel-week-days" style="min-height: {{ $rowHeight }}px; --days: {{ $dayCount }}">
                                <div class="personnel-week-grid" style="grid-template-columns: repeat({{ $dayCount }}, minmax(0, 1fr));" aria-hidden="true">
                                    @foreach ($days as $day)
                                        <i></i>
                                    @endforeach
                                </div>
                                @if ($hasMarkers && ! empty($project['start_marker']))
                                    <div class="personnel-week-marker" style="width: calc(100% / {{ $dayCount }} - 4px); left: calc({{ $project['start_marker']['index'] }} * 100% / {{ $dayCount }} + 2px);">
                                        START {{ $project['start_marker']['date'] }}
                                    </div>
                                @endif
                                @if ($hasMarkers && ! empty($project['end_marker']))
                                    <div class="personnel-week-marker is-klaar" style="width: calc(100% / {{ $dayCount }} - 4px); left: calc({{ $project['end_marker']['index'] }} * 100% / {{ $dayCount }} + 2px);">
                                        KLAAR {{ $project['end_marker']['date'] }}
                                    </div>
                                @endif
                                <div class="personnel-week-bars" style="min-height: {{ $work['height'] }}px; padding-top: {{ $hasMarkers ? '12px' : '0' }};">
                                    @foreach (($work['cells'] ?? []) as $cellIndex => $lines)
                                        @foreach ($lines as $lineIndex => $cell)
                                            <div class="personnel-week-bar is-away"
                                                 style="background: {{ $cell['color'] }}; color: {{ $cell['text'] }}; top: {{ ($hasMarkers ? 12 : 1) + ((int) $lineIndex * ($barHeight + 1)) }}px; height: {{ $barHeight }}px; width: calc(100% / {{ $dayCount }} - 2px); left: calc({{ $cellIndex }} * 100% / {{ $dayCount }} + 1px);">
                                                <span class="personnel-week-bar-name">
                                                    {{ $cell['label'] }}
                                                    @if (! empty($cell['time_label']))
                                                        <span class="personnel-week-bar-time">{{ $cell['time_label'] }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                        @endforeach
                                    @endforeach
                                    @foreach ($work['bars'] as $bar)
                                        <div class="personnel-week-bar{{ ! empty($bar['away']) ? ' is-away' : '' }}"
                                             style="background: {{ $bar['color'] }}; color: {{ $bar['text'] }}; top: {{ ($hasMarkers ? 12 : 1) + ((int) $bar['stack'] * ($barHeight + 1)) }}px; height: {{ $barHeight }}px; width: calc(({{ $bar['bar']['span'] }} - {{ $bar['bar']['start_offset'] }} - (1 - {{ $bar['bar']['end_offset'] }})) * 100% / {{ $dayCount }} - 2px); left: calc(({{ $bar['bar']['start'] }} + {{ $bar['bar']['start_offset'] }}) * 100% / {{ $dayCount }} + 1px);">
                                            <span class="personnel-week-bar-name">
                                                {{ $bar['label'] }}
                                                @if (! empty($bar['time_label']))
                                                    <span class="personnel-week-bar-time">{{ $bar['time_label'] }}</span>
                                                @endif
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
@endif
