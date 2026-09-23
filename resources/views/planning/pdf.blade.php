<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>
        Planning
        @if ($clientProject)
            · {{ $clientProject->labeledNumbersLine() }} — {{ $clientProject->displayTitle() }}
        @endif
        · {{ $weekRangeLabel }}
    </title>
    <style>
        :root {
            --ink: #1c1917;
            --muted: #78716c;
            --line: #e7e0d4;
            --sand: #f4efe6;
            --paper: #fbf8f3;
            --orange: #e4572e;
            --days: {{ (int) $dayCount }};
        }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            color: var(--ink);
            background: white;
            margin: 18px;
            font-size: 12px;
        }
        h1 { font-size: 22px; margin: 2px 0 0; font-weight: 650; }
        h2 { font-size: 13px; margin: 22px 0 8px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
        .brand { color: var(--orange); font-size: 11px; letter-spacing: .2em; text-transform: uppercase; font-weight: 700; }
        .muted { color: var(--muted); }
        .meta { margin: 6px 0 0; line-height: 1.45; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 16px; align-items: center; flex-wrap: wrap; }
        .toolbar button, .toolbar a {
            border: 1px solid var(--line);
            background: white;
            color: var(--ink);
            padding: 8px 12px;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
        }
        .toolbar .primary { background: var(--orange); border-color: var(--orange); color: white; }
        .toolbar .is-on { background: var(--ink); border-color: var(--ink); color: white; }
        .hint { font-size: 11px; }

        .board {
            border: 1px solid var(--line);
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .board th, .board td {
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
        }
        .board thead th {
            background: var(--ink);
            color: white;
            font-size: 10px;
            letter-spacing: .04em;
            text-transform: uppercase;
            font-weight: 600;
            padding: 0;
        }
        .head-weeks { display: grid; grid-template-columns: 1fr; }
        .week-band {
            display: grid;
            grid-template-columns: repeat(var(--days), minmax(0, 1fr));
            background: #2a2623;
            color: var(--orange);
            font-size: 9px;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 700;
            height: 20px;
        }
        .week-band span {
            display: flex;
            align-items: center;
            justify-content: center;
            border-left: 1px solid rgba(255,255,255,.12);
            gap: 6px;
        }
        .week-band .month { color: rgba(255,255,255,.7); font-weight: 600; }
        .day-row {
            display: grid;
            grid-template-columns: repeat(var(--days), minmax(0, 1fr));
        }
        .day-row span {
            padding: 7px 4px;
            text-align: center;
            border-left: 1px solid rgba(255,255,255,.12);
            white-space: nowrap;
        }
        .col-werk { width: 28%; text-align: left; padding: 7px 10px; }
        .col-num { width: 7%; text-align: right; padding: 7px 8px; font-variant-numeric: tabular-nums; }
        .col-num.over { color: #b42318; font-weight: 700; }
        .col-num.warn { color: #b45309; font-weight: 650; }
        .col-num.ok { color: #3f6212; font-weight: 650; }
        .col-days { width: auto; }
        .project-row td { background: var(--sand); font-weight: 600; }
        .section-row td {
            background: var(--ink);
            color: white;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .16em;
            text-transform: uppercase;
            padding: 6px 8px;
        }
        .work-row td { font-size: 11px; }
        .indent { padding-left: 22px; }
        .city { font-weight: 400; color: var(--muted); font-size: 11px; }
        .plan-labor { margin-top: 2px; font-size: 10px; font-weight: 500; color: var(--muted); }
        .plan-labor--over { color: #b91c1c; font-weight: 700; }
        .plan-labor--warn { color: #b45309; }
        .plan-hour-bar { display: flex; align-items: center; gap: 6px; margin-top: 2px; }
        .plan-hour-bar-track { display: block; flex: 1; height: 4px; background: var(--line); overflow: hidden; }
        .plan-hour-bar-fill { display: block; height: 100%; background: #3f6212; }
        .plan-hour-bar--warn .plan-hour-bar-fill { background: #b45309; }
        .plan-hour-bar--over .plan-hour-bar-fill { background: #b91c1c; }
        .plan-hour-bar-label { font-size: 9px; color: var(--muted); white-space: nowrap; }
        .badge { display: inline-block; background: var(--ink); color: white; font-size: 9px; letter-spacing: .12em; font-weight: 700; padding: 1px 6px; margin-bottom: 2px; }
        .steps { color: var(--muted); font-size: 10px; font-weight: 400; }
        .days {
            position: relative;
            height: 100%;
            min-height: 28px;
        }
        .day-grid {
            display: grid;
            grid-template-columns: repeat(var(--days), minmax(0, 1fr));
            position: absolute;
            inset: 0;
        }
        .day-grid i {
            border-left: 1px solid var(--line);
            display: block;
        }
        .bars { position: relative; min-height: 28px; padding: 3px 0; }
        .bar {
            position: absolute;
            height: 18px;
            border-radius: 2px;
            color: white;
            font-size: 10px;
            line-height: 18px;
            padding: 0;
            overflow: hidden;
            white-space: nowrap;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .bar-name {
            position: relative;
            z-index: 2;
            display: block;
            padding: 0 6px;
        }
        .bar-overrun {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            z-index: 1;
            background: repeating-linear-gradient(-45deg, rgba(185, 28, 28, 0.62), rgba(185, 28, 28, 0.62) 3px, rgba(255, 255, 255, 0.18) 3px, rgba(255, 255, 255, 0.18) 7px);
            box-shadow: inset 1px 0 0 rgba(185, 28, 28, 0.85);
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .period-band {
            position: absolute;
            top: 2px;
            bottom: 2px;
            border-radius: 2px;
            background: repeating-linear-gradient(-62deg, rgba(180, 83, 9, 0.08), rgba(180, 83, 9, 0.08) 6px, rgba(180, 83, 9, 0.16) 6px, rgba(180, 83, 9, 0.16) 12px);
            box-shadow: inset 0 0 0 1px rgba(180, 83, 9, 0.22);
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .period-marker {
            position: absolute;
            top: 3px;
            color: #b91c1c;
            font-size: 9px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .period-marker.is-done { color: #3f6212; }
        .period-marker span { display: block; }
        .period-marker--paired.period-marker--end { top: 14px; }
        .missing-craftsman {
            float: right;
            clear: right;
            margin: 0 0 0 8px;
            color: #b91c1c;
            font-size: 14px;
            font-weight: 700;
            line-height: 1;
        }
        .legend { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-top: 14px; }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .swatch {
            width: 12px; height: 12px; border-radius: 2px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .list { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .list th, .list td { border-bottom: 1px solid var(--line); padding: 7px 8px; text-align: left; }
        .list th { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
        .empty { color: var(--muted); padding: 16px; }
        .foot { margin-top: 18px; color: var(--muted); font-size: 11px; }
        @media print {
            .no-print { display: none !important; }
            @page { size: landscape; margin: 0; }
            body { margin: 0; padding: 10mm; }
        }
    </style>
</head>
<body @if ($autoPrint ?? false) data-autoprint="1" @endif>
    @php
        $showNames = (bool) ($showNames ?? false);
        $query = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $assignments = [];
        $legend = [];
        foreach ($rows as $projectRow) {
            if (($projectRow['type'] ?? '') === 'section') {
                continue;
            }
            foreach (array_merge($projectRow['person_bars'] ?? [], ...array_map(fn ($work) => $work['person_bars'] ?? [], $projectRow['children'] ?? [])) as $bar) {
                $legend[$bar['worker_id']] = [
                    'label' => $bar['label'],
                    'color' => $bar['color'],
                ];
            }
            foreach ($projectRow['children'] as $work) {
                foreach ($work['person_bars'] as $bar) {
                    $assignments[] = [
                        'project' => trim(($projectRow['numbers_label'] ?? $projectRow['number']).' — '.$projectRow['title']),
                        'work' => $work['title'],
                        'who' => $bar['label'],
                        'start' => $bar['start_date'],
                        'end' => $bar['end_date'],
                    ];
                }
            }
            foreach ($projectRow['person_bars'] as $bar) {
                $assignments[] = [
                    'project' => trim(($projectRow['numbers_label'] ?? $projectRow['number']).' — '.$projectRow['title']),
                    'work' => 'Inzet',
                    'who' => $bar['label'],
                    'start' => $bar['start_date'],
                    'end' => $bar['end_date'],
                ];
            }
        }
        if (! $showNames) {
            $unique = [];
            foreach ($assignments as $row) {
                $unique[$row['project'].'|'.$row['work'].'|'.$row['start'].'|'.$row['end']] = $row;
            }
            $assignments = array_values($unique);
        }
        $showProjectColumn = collect($rows)->where('type', 'project')->count() > 1;
        $period = (string) ($period ?? $filters['period'] ?? 'week');
        if ($period === '') {
            $period = 'week';
        }
        $periodQuery = fn (string $value): array => array_filter(
            array_merge($query, [
                'period' => $value,
                'intern' => $showNames ? 1 : null,
            ]),
            fn ($item) => $item !== null && $item !== '',
        );
    @endphp

    <p class="toolbar no-print">
        <button type="button" class="primary" onclick="niconPrintPlanning()">Opslaan als PDF</button>
        <a href="{{ route('planning.export', $query) }}" @class(['is-on' => ! $showNames])>Opdrachtgever</a>
        <a href="{{ route('planning.export', array_merge($query, ['intern' => 1])) }}" @class(['is-on' => $showNames])>Intern</a>
        <a href="{{ route('planning.export', $periodQuery('week')) }}" @class(['is-on' => $period === 'week'])>Week</a>
        <a href="{{ route('planning.export', $periodQuery('month')) }}" @class(['is-on' => $period === 'month'])>Maand</a>
        <a href="{{ route('planning.export', $periodQuery('work')) }}" @class(['is-on' => $period === 'work'])>Gehele werk</a>
        <a href="{{ route('planning', array_filter($query, fn ($value, $key) => $key !== 'period', ARRAY_FILTER_USE_BOTH)) }}">Terug naar planbord</a>
        <span class="hint muted">
            @if ($periodFallback ?? false)
                Kies eerst een werk in de filters om het hele werk te printen.
            @elseif ($showNames)
                Intern: met namen van vakmensen.
            @else
                Voor de opdrachtgever: zonder namen.
            @endif
            Kies in het printvenster de printer <strong>Opslaan als PDF</strong> of <strong>Microsoft Print to PDF</strong>. Liggend papier geeft het beste resultaat.
        </span>
    </p>

    <div class="brand">Nicon Vloeren{{ $showNames ? ' · Intern' : '' }}</div>
    <h1>
        @if ($clientProject)
            {{ $clientProject->labeledNumbersLine() }}
            <span style="display:block; font-size:18px; font-weight:600; margin-top:4px;">{{ $clientProject->displayTitle() }}</span>
        @else
            Planning
        @endif
    </h1>
    <p class="meta muted">
        {{ $weekRangeLabel }} · {{ $days->first()->translatedFormat('d M') }} – {{ $days->last()->translatedFormat('d M Y') }}
        @if ($clientProject)
            @if ($clientProject->customer)
                · Opdrachtgever: {{ $clientProject->customer->name }}
            @endif
            @if ($clientProject->nawLine())
                · {{ $clientProject->nawLine() }}
            @endif
        @endif
        · Afgedrukt {{ now()->translatedFormat('d M Y') }}
    </p>

    @if (! count($rows))
        <p class="empty">Geen werken in deze periode.</p>
    @else
        <table class="board">
            <thead>
                <tr>
                    <th class="col-werk">Werk</th>
                    <th class="col-num">Opdracht</th>
                    <th class="col-num">Gereed</th>
                    <th class="col-num">Rest</th>
                    <th class="col-num">%</th>
                    @if ($canViewLaborCosts ?? false)
                        <th class="col-num">Begroot uren</th>
                        <th class="col-num">Ingepland uren</th>
                        <th class="col-num">Gemaakt uren</th>
                        <th class="col-num">Budget over</th>
                        <th class="col-num">Verschil</th>
                        <th class="col-num">Begroot €/m²</th>
                        <th class="col-num">Werkelijk €/m²</th>
                        <th class="col-num">Prognose €/m²</th>
                        <th class="col-num">Verschil €/m²</th>
                    @endif
                    <th class="col-days">
                        <div class="week-band">
                            @foreach ($weekBands as $band)
                                <span style="grid-column: span {{ $band['span'] }}">
                                    Week {{ $band['number'] }}
                                    <span class="month">{{ $band['month'] }}</span>
                                </span>
                            @endforeach
                        </div>
                        <div class="day-row">
                            @foreach ($days as $day)
                                <span>{{ $weeks > 1 ? $day->translatedFormat('D j') : $day->translatedFormat('D j M') }}</span>
                            @endforeach
                        </div>
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $projectRow)
                    @if (($projectRow['type'] ?? '') === 'section')
                        <tr class="section-row">
                            <td class="col-werk section-label" colspan="{{ ($canViewLaborCosts ?? false) ? 15 : 6 }}">{{ $projectRow['title'] }}</td>
                        </tr>
                        @continue
                    @endif
                    @php
                        $projectBars = $projectRow['person_bars'] ?? [];
                        $hasPeriodChrome = $projectRow['bar'] || ($projectRow['start_marker'] ?? null) || ($projectRow['end_marker'] ?? null);
                        $pairedPeriod = is_array($projectRow['start_marker'] ?? null)
                            && is_array($projectRow['end_marker'] ?? null)
                            && (int) $projectRow['start_marker']['index'] === (int) $projectRow['end_marker']['index'];
                        $periodPad = $hasPeriodChrome ? ($pairedPeriod ? 28 : 18) : 0;
                        $projectHeight = max($hasPeriodChrome ? ($pairedPeriod ? 52 : 42) : 28, 8 + (count($projectBars) * 20) + $periodPad);
                    @endphp
                    <tr class="project-row">
                        <td class="col-werk">
                            @if (! empty($projectRow['missing_craftsman']))
                                <span class="missing-craftsman" title="Geen vakman ingepland">⚠</span>
                            @endif
                            @if (! empty($projectRow['badge']))
                                <div class="badge">{{ $projectRow['badge'] }}</div>
                            @endif
                            @if (! empty($projectRow['numbers_label']))
                                <div>{{ $projectRow['numbers_label'] }}</div>
                            @endif
                            <div>{{ $projectRow['title'] }}@if (! empty($projectRow['hours_label'])) | {{ $projectRow['hours_label'] }}@endif</div>
                            @if (! empty($projectRow['subtitle']))
                                <div class="city">{{ $projectRow['subtitle'] }}</div>
                            @elseif (! $clientProject && ($projectRow['customer'] || $projectRow['city']))
                                <div class="city">{{ $projectRow['customer'] }}{{ $projectRow['customer'] && $projectRow['city'] ? ' · ' : '' }}{{ $projectRow['city'] }}</div>
                            @elseif ($projectRow['city'] && $clientProject)
                                <div class="city">{{ $projectRow['city'] }}</div>
                            @endif
                        </td>
                        <td class="col-num">
                            @if (($projectRow['ordered'] ?? null) !== null)
                                {{ \App\Support\Format::qty($projectRow['ordered'], $projectRow['ordered_decimals'] ?? 0) }}{{ ! empty($projectRow['unit']) ? ' '.$projectRow['unit'] : '' }}
                            @endif
                        </td>
                        <td class="col-num">
                            @if (($projectRow['completed'] ?? null) !== null)
                                {{ \App\Support\Format::qty($projectRow['completed']) }}
                            @endif
                        </td>
                        <td class="col-num">
                            @if (($projectRow['remaining'] ?? null) !== null)
                                {{ \App\Support\Format::qty($projectRow['remaining']) }}
                            @endif
                        </td>
                        <td class="col-num">
                            @if (($projectRow['percent'] ?? null) !== null)
                                {{ $projectRow['percent'] }}%
                            @endif
                        </td>
                        @if ($canViewLaborCosts ?? false)
                            @include('planning.partials.pdf-labor-cells', ['row' => $projectRow])
                        @endif
                        <td class="col-days">
                            @include('planning.partials.pdf-bars', [
                                'personBars' => $projectBars,
                                'height' => $projectHeight,
                                'showNames' => $showNames,
                                'showPeriod' => true,
                                'periodBar' => $projectRow['bar'] ?? null,
                                'startMarker' => $projectRow['start_marker'] ?? null,
                                'endMarker' => $projectRow['end_marker'] ?? null,
                            ])
                        </td>
                    </tr>
                    @foreach ($projectRow['children'] as $work)
                        @php
                            $workHeight = max(28, 8 + (count($work['person_bars']) * 20));
                        @endphp
                        <tr class="work-row">
                            <td class="col-werk indent">
                                {{ $work['title'] }}
                                @if (! empty($work['steps']))
                                    <div class="steps">{{ implode(' · ', $work['steps']) }}</div>
                                @endif
                            </td>
                            <td class="col-num">
                                @if ($work['ordered'] !== null)
                                    {{ \App\Support\Format::qty($work['ordered'], $work['ordered_decimals'] ?? 0) }} {{ $work['unit'] }}
                                @endif
                            </td>
                            <td class="col-num">
                                @if ($work['completed'] !== null)
                                    {{ \App\Support\Format::qty($work['completed']) }}
                                @endif
                            </td>
                            <td class="col-num">
                                @if ($work['remaining'] !== null)
                                    {{ \App\Support\Format::qty($work['remaining']) }}
                                @endif
                            </td>
                            <td class="col-num">
                                @if (($work['percent'] ?? null) !== null)
                                    {{ $work['percent'] }}%
                                @endif
                            </td>
                            @if ($canViewLaborCosts ?? false)
                                @include('planning.partials.pdf-labor-cells', ['row' => $work])
                            @endif
                            <td class="col-days">
                                @include('planning.partials.pdf-bars', [
                                    'personBars' => $work['person_bars'],
                                    'height' => $workHeight,
                                    'showNames' => $showNames,
                                    'showPeriod' => false,
                                ])
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>

        @if ($showNames && count($legend))
            <div class="legend">
                @foreach ($legend as $item)
                    <span><i class="swatch" style="background: {{ $item['color'] }}"></i>{{ $item['label'] }}</span>
                @endforeach
            </div>
        @endif

        @if (count($assignments))
            <h2>Inzet</h2>
            <table class="list">
                <thead>
                    <tr>
                        @if ($showProjectColumn)<th>Werk</th>@endif
                        <th>Werkzaamheid</th>
                        @if ($showNames)<th>Wie</th>@endif
                        <th>Van</th>
                        <th>Tot</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($assignments as $row)
                        <tr>
                            @if ($showProjectColumn)<td>{{ $row['project'] }}</td>@endif
                            <td>{{ $row['work'] }}</td>
                            @if ($showNames)<td>{{ $row['who'] }}</td>@endif
                            <td>{{ \Carbon\Carbon::parse($row['start'])->translatedFormat('D j M') }}</td>
                            <td>{{ \Carbon\Carbon::parse($row['end'])->translatedFormat('D j M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    <p class="foot">Planning onder voorbehoud van voortgang op de bouw. Nicon Vloeren</p>
    <script>
        function niconPrintPlanning() {
            const title = document.title;
            document.title = '';
            const restore = () => {
                document.title = title;
                window.removeEventListener('afterprint', restore);
            };
            window.addEventListener('afterprint', restore);
            window.print();
        }
        @if ($autoPrint ?? false)
        window.addEventListener('load', niconPrintPlanning);
        @endif
    </script>
</body>
</html>
