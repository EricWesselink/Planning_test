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
                <tr>
                    <td class="personnel-week-werk personnel-week-project-cell">
                        @if (! empty($project['customer']))
                            <div class="personnel-week-customer">{{ $project['customer'] }}</div>
                        @endif
                        <div class="personnel-week-title">{{ $project['title'] }}</div>
                        @if (! empty($project['city']))
                            <div class="personnel-week-meta">{{ $project['city'] }}</div>
                        @endif
                        @if (! empty($project['address']))
                            <div class="personnel-week-meta">{{ $project['address'] }}</div>
                        @endif
                        @if (! empty($project['number']))
                            <div class="personnel-week-meta">{{ $project['number'] }}</div>
                        @endif
                        @if (($project['activities'] ?? []) !== [])
                            <ul class="personnel-week-activities">
                                @foreach ($project['activities'] as $activity)
                                    <li>{{ $activity }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </td>
                    <td colspan="{{ $dayCount }}">
                        @php $headerHeight = max(36, (int) ($project['height'] ?? 36)); @endphp
                        <div class="personnel-week-days" style="min-height: {{ $headerHeight }}px; --days: {{ $dayCount }}">
                            <div class="personnel-week-grid" style="grid-template-columns: repeat({{ $dayCount }}, minmax(0, 1fr));" aria-hidden="true">
                                @foreach ($days as $day)
                                    <i></i>
                                @endforeach
                            </div>
                            @if (! empty($project['start_marker']))
                                <div class="personnel-week-marker" style="width: calc(100% / {{ $dayCount }} - 4px); left: calc({{ $project['start_marker']['index'] }} * 100% / {{ $dayCount }} + 2px);">
                                    START {{ $project['start_marker']['date'] }}
                                </div>
                            @endif
                            @if (! empty($project['end_marker']))
                                <div class="personnel-week-marker is-klaar" style="width: calc(100% / {{ $dayCount }} - 4px); left: calc({{ $project['end_marker']['index'] }} * 100% / {{ $dayCount }} + 2px);">
                                    KLAAR {{ $project['end_marker']['date'] }}
                                </div>
                            @endif
                        </div>
                    </td>
                </tr>
                @foreach ($project['works'] as $work)
                    <tr>
                        <td class="personnel-week-work-title">{{ $work['title'] }}</td>
                        <td colspan="{{ $dayCount }}">
                            <div class="personnel-week-days" style="min-height: {{ $work['height'] }}px;">
                                <div class="personnel-week-grid" style="grid-template-columns: repeat({{ $dayCount }}, minmax(0, 1fr));" aria-hidden="true">
                                    @foreach ($days as $day)
                                        <i></i>
                                    @endforeach
                                </div>
                                <div class="personnel-week-bars" style="min-height: {{ $work['height'] }}px;">
                                    @foreach ($work['bars'] as $bar)
                                        <div class="personnel-week-bar{{ ! empty($bar['away']) ? ' is-away' : '' }}"
                                             style="background: {{ $bar['color'] }}; color: {{ $bar['text'] }}; top: {{ 4 + ((int) $bar['stack'] * 22) }}px; width: calc(({{ $bar['bar']['span'] }} - {{ $bar['bar']['start_offset'] }} - (1 - {{ $bar['bar']['end_offset'] }})) * 100% / {{ $dayCount }} - 2px); left: calc(({{ $bar['bar']['start'] }} + {{ $bar['bar']['start_offset'] }}) * 100% / {{ $dayCount }} + 1px);">
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
