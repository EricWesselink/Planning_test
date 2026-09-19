<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $heading }} · Week {{ $weekNumber }} · {{ $weekYear }}</title>
    <style>
        @page { margin: 12mm 10mm 16mm 10mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            font-size: 9pt;
            margin: 0;
        }
        table.sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .brand-cell {
            border: none;
            border-bottom: 0.7pt solid #163a5f;
            padding: 0 0 10px;
            vertical-align: middle;
        }
        .brand { width: 100%; border-collapse: collapse; }
        .brand td { border: none; vertical-align: middle; padding: 0; }
        .logo { width: 118px; padding-right: 14px; }
        .logo img { width: 110px; height: 54px; display: block; }
        .brand-name {
            font-size: 11pt;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #163a5f;
        }
        .brand-title {
            font-size: 16pt;
            font-weight: 700;
            color: #163a5f;
            padding-top: 2px;
        }
        .brand-week { font-size: 10pt; color: #163a5f; padding-top: 3px; }
        .day-head th {
            background: #163a5f;
            color: #fff;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 6px 4px;
            text-align: center;
            border: 0.4pt solid #163a5f;
        }
        .day-head .werk {
            width: 22%;
            text-align: left;
            padding-left: 8px;
        }
        .day-name { display: block; font-weight: 700; }
        .day-date { display: block; font-weight: 500; font-size: 7.5pt; text-transform: none; letter-spacing: 0; }
        td.werk {
            width: 22%;
            border: 0.4pt solid #d5dde5;
            padding: 7px 8px;
            background: #f4efe6;
            vertical-align: top;
        }
        td.work-title {
            border: 0.4pt solid #d5dde5;
            padding: 5px 8px;
            font-size: 8pt;
            font-weight: 700;
            vertical-align: middle;
            background: #fff;
        }
        td.day {
            border: 0.4pt solid #d5dde5;
            padding: 3px;
            vertical-align: top;
        }
        .customer {
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #5b6570;
        }
        .title { font-size: 10pt; font-weight: 700; color: #163a5f; }
        .meta { font-size: 7.5pt; color: #44403c; padding-top: 1px; }
        .activities { margin: 4px 0 0; padding-left: 12px; font-size: 7.5pt; color: #44403c; }
        .marker {
            font-size: 7pt;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 2px 0;
        }
        .marker-start { color: #b91c1c; }
        .marker-klaar { color: #3f6212; }
        .block {
            border-radius: 2px;
            padding: 3px 5px;
            margin: 0 0 3px;
            font-size: 8pt;
            font-weight: 700;
            line-height: 1.25;
        }
        .block.is-away { font-weight: 600; }
        .block-time { display: block; font-weight: 500; font-size: 7pt; }
        .none { text-align: center; padding: 18px 8px; color: #6b7280; }
        .legend { margin-top: 8px; font-size: 8pt; }
        .legend span { margin-right: 12px; }
        .swatch {
            display: inline-block;
            width: 9px;
            height: 9px;
            margin-right: 4px;
            vertical-align: middle;
        }
        tr.project-row { page-break-inside: avoid; }
    </style>
</head>
<body>
    @foreach ($pages as $pageIndex => $pageProjects)
        @if ($pageIndex > 0)
            <div style="page-break-before: always;"></div>
        @endif
        <table class="sheet">
            <thead>
                <tr>
                    <td class="brand-cell" colspan="{{ 1 + count($days) }}">
                        <table class="brand">
                            <tr>
                                @if ($logo)
                                    <td class="logo">
                                        <img src="{{ $logo }}" alt="{{ $companyName }}" width="110" height="54">
                                    </td>
                                @endif
                                <td>
                                    <div class="brand-name">{{ $companyName }}</div>
                                    <div class="brand-title">{{ $heading }}</div>
                                    <div class="brand-week">{{ $weekLabel }} · {{ $weekRange }}</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr class="day-head">
                    <th class="werk">Werk</th>
                    @foreach ($days as $day)
                        <th>
                            <span class="day-name">{{ $day['name'] }}</span>
                            <span class="day-date">{{ $day['date'] }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($pageProjects as $project)
                    <tr class="project-row">
                        <td class="werk">
                            @if (! empty($project['customer']))
                                <div class="customer">{{ $project['customer'] }}</div>
                            @endif
                            <div class="title">{{ $project['title'] }}</div>
                            @if (! empty($project['city']))
                                <div class="meta">{{ $project['city'] }}</div>
                            @endif
                            @if (! empty($project['address']))
                                <div class="meta">{{ $project['address'] }}</div>
                            @endif
                            @if (! empty($project['number']))
                                <div class="meta">{{ $project['number'] }}</div>
                            @endif
                            @if (($project['activities'] ?? []) !== [])
                                <ul class="activities">
                                    @foreach ($project['activities'] as $activity)
                                        <li>{{ $activity }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        @foreach ($days as $index => $day)
                            <td class="day">
                                @if (! empty($project['start_marker']) && (int) $project['start_marker']['index'] === (int) $index)
                                    <div class="marker marker-start">START {{ $project['start_marker']['date'] }}</div>
                                @endif
                                @if (! empty($project['end_marker']) && (int) $project['end_marker']['index'] === (int) $index)
                                    <div class="marker marker-klaar">KLAAR {{ $project['end_marker']['date'] }}</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    @foreach ($project['works'] as $work)
                        <tr>
                            <td class="work-title">{{ $work['title'] }}</td>
                            @foreach ($days as $index => $day)
                                <td class="day">
                                    @foreach ($work['bars'] as $bar)
                                        @php
                                            $start = (int) $bar['bar']['start'];
                                            $end = $start + (int) $bar['bar']['span'] - 1;
                                        @endphp
                                        @if ($index >= $start && $index <= $end)
                                            <div class="block{{ ! empty($bar['away']) ? ' is-away' : '' }}" style="background: {{ $bar['color'] }}; color: {{ $bar['text'] }};">
                                                {{ $bar['label'] }}
                                                @if (! empty($bar['time_label']))
                                                    <span class="block-time">{{ $bar['time_label'] }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td class="none" colspan="{{ 1 + count($days) }}">Geen werken ingepland in deze week.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        @if (($legend ?? []) !== [])
            <div class="legend">
                @foreach ($legend as $item)
                    <span><span class="swatch" style="background: {{ $item['color'] }};"></span>{{ $item['label'] }}</span>
                @endforeach
            </div>
        @endif
    @endforeach
</body>
</html>
