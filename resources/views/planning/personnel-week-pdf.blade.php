<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $heading }} · Week {{ $weekNumber }} · {{ $weekYear }}</title>
    <style>
        @page { margin: 8mm 8mm 12mm 8mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            font-size: 8pt;
            margin: 0;
        }
        table.sheet { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .brand-cell {
            border: none;
            border-bottom: 0.6pt solid #163a5f;
            padding: 0 0 6px;
            vertical-align: middle;
        }
        .brand { width: 100%; border-collapse: collapse; }
        .brand td { border: none; vertical-align: middle; padding: 0; }
        .logo { width: 88px; padding-right: 10px; }
        .logo img { width: 80px; height: 39px; display: block; }
        .brand-copy {
            width: auto;
            border-collapse: collapse;
        }
        .brand-copy td {
            border: none;
            vertical-align: top;
            padding: 0;
        }
        .brand-name {
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #163a5f;
            line-height: 1.3;
            padding: 0 0 3px;
        }
        .brand-title {
            font-size: 12pt;
            font-weight: 700;
            color: #163a5f;
            line-height: 1.25;
            padding: 3px 0;
        }
        .brand-week {
            font-size: 8pt;
            color: #163a5f;
            line-height: 1.3;
            padding: 3px 0 0;
        }
        .day-head th {
            background: #163a5f;
            color: #fff;
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 3px 3px;
            text-align: center;
            border: 0.4pt solid #163a5f;
        }
        .col-werk, .day-head .werk, td.werk { width: 20%; }
        .day-head .werk { text-align: left; padding-left: 6px; }
        .day-name { display: block; font-weight: 700; }
        .day-date { display: block; font-weight: 500; font-size: 6.5pt; text-transform: none; letter-spacing: 0; }
        td.werk {
            border: 0.4pt solid #d5dde5;
            padding: 4px 6px;
            background: #f4efe6;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-wrap: break-word;
            word-break: break-word;
        }
        td.day {
            border: 0.4pt solid #d5dde5;
            padding: 1px 2px;
            vertical-align: top;
        }
        .customer {
            font-size: 6.5pt;
            font-weight: 700;
            text-transform: uppercase;
            color: #5b6570;
            line-height: 1.35;
            padding: 0 0 1px;
        }
        .title {
            font-size: 8pt;
            font-weight: 700;
            color: #163a5f;
            line-height: 1.35;
            padding: 0 0 1px;
        }
        .meta {
            font-size: 7pt;
            color: #44403c;
            line-height: 1.35;
            padding: 0 0 1px;
        }
        .work-name {
            font-size: 7pt;
            font-weight: 700;
            color: #163a5f;
            padding-top: 2px;
            line-height: 1.35;
        }
        .marker {
            font-size: 6pt;
            font-weight: 800;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            line-height: 1.25;
            padding: 0;
        }
        .marker-start { color: #b91c1c; }
        .marker-klaar { color: #3f6212; }
        .block {
            border-radius: 1px;
            padding: 1px 4px 2px;
            margin: 0 0 2px;
            font-size: 7.5pt;
            font-weight: 700;
            line-height: 1.3;
            overflow-wrap: anywhere;
            word-wrap: break-word;
        }
        .block.is-away { font-weight: 600; font-size: 7pt; }
        .block-time { display: block; font-weight: 500; font-size: 6.5pt; }
        .none { text-align: center; padding: 10px 8px; color: #6b7280; }
        .legend { margin-top: 6px; font-size: 7pt; }
        .legend span { margin-right: 10px; }
        .swatch {
            display: inline-block;
            width: 8px;
            height: 8px;
            margin-right: 3px;
            vertical-align: middle;
        }
        td.wrap { padding: 0; border: none; }
        table.project {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-inside: avoid;
            page-break-after: auto;
            page-break-before: auto;
        }
        tr.keep {
            page-break-inside: avoid;
            page-break-after: auto;
            page-break-before: auto;
        }
    </style>
</head>
<body>
    <table class="sheet">
        <colgroup>
            <col class="col-werk">
            @foreach ($days as $day)
                <col>
            @endforeach
        </colgroup>
        <thead>
            <tr>
                <td class="brand-cell" colspan="{{ 1 + count($days) }}">
                    <table class="brand">
                        <tr>
                            @if ($logo)
                                <td class="logo">
                                    <img src="{{ $logo }}" alt="{{ $companyName }}" width="80" height="39">
                                </td>
                            @endif
                            <td>
                                <table class="brand-copy">
                                    <tr>
                                        <td class="brand-name">{{ $companyName }}</td>
                                    </tr>
                                    <tr>
                                        <td class="brand-title">{{ $heading }}</td>
                                    </tr>
                                    <tr>
                                        <td class="brand-week">{{ $weekLabel }} · {{ $weekRange }}</td>
                                    </tr>
                                </table>
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
            @forelse ($projects as $project)
                <tr class="keep">
                    <td class="wrap" colspan="{{ 1 + count($days) }}">
                        <table class="project">
                            <colgroup>
                                <col class="col-werk">
                                @foreach ($days as $day)
                                    <col>
                                @endforeach
                            </colgroup>
                            @foreach ($project['works'] as $workIndex => $work)
                                <tr>
                                    <td class="werk">
                                        @if ((int) $workIndex === 0)
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
                                        @endif
                                        @if (! empty($work['title']))
                                            <div class="work-name">{{ $work['title'] }}</div>
                                        @endif
                                    </td>
                                    @foreach ($days as $index => $day)
                                        <td class="day">
                                            @if ((int) $workIndex === 0)
                                                @if (! empty($project['start_marker']) && (int) $project['start_marker']['index'] === (int) $index)
                                                    <div class="marker marker-start">START {{ $project['start_marker']['date'] }}</div>
                                                @endif
                                                @if (! empty($project['end_marker']) && (int) $project['end_marker']['index'] === (int) $index)
                                                    <div class="marker marker-klaar">KLAAR {{ $project['end_marker']['date'] }}</div>
                                                @endif
                                            @endif
                                            @foreach (($work['cells'][$index] ?? []) as $cell)
                                                <div class="block is-away" style="background: {{ $cell['color'] }}; color: {{ $cell['text'] }};">
                                                    {{ $cell['label'] }}
                                                    @if (! empty($cell['time_label']))
                                                        <span class="block-time">{{ $cell['time_label'] }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
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
                        </table>
                    </td>
                </tr>
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
</body>
</html>
