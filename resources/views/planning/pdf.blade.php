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
            --muted: #57534e;
            --line: #e5e5e5;
            --head: #f3f4f6;
            --project: #fdf2f2;
            --red: #c41623;
            --days: {{ (int) $dayCount }};
        }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", system-ui, sans-serif;
            color: var(--ink);
            background: white;
            margin: 16px auto;
            max-width: 297mm;
            font-size: 11px;
        }
        body.paper-a3 { max-width: 420mm; }
        body.is-dense { font-size: 10px; }
        body.is-dense .mast { margin-bottom: 6px; padding-bottom: 4px; }
        body.is-dense .logo { height: 36px; }
        body.is-dense h1 { font-size: 14px; }
        body.is-dense h1 .project-title { font-size: 13px; }
        body.is-dense h2 { margin: 8px 0 4px; }
        body.is-dense .col-werk,
        body.is-dense .col-num { padding-top: 3px; padding-bottom: 3px; }
        body.is-dense .list th,
        body.is-dense .list td { padding: 4px 8px; }
        body.is-dense .foot { margin-top: 8px; }
        body.is-dense .company-foot { margin-top: 4px; padding-top: 4px; }
        body.is-dense .week-band { min-height: 22px; }
        h1 { font-size: 16px; margin: 0; font-weight: 650; line-height: 1.25; }
        h1 .project-title { display: block; font-size: 15px; font-weight: 600; margin-top: 2px; }
        h2 {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            margin: 16px 0 8px;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--ink);
            font-weight: 700;
        }
        h2::before {
            content: "";
            width: 3px;
            height: 14px;
            background: var(--red);
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .mast { display: flex; align-items: flex-start; gap: 16px; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 3px solid var(--red); }
        .logo { height: 48px; width: auto; display: block; flex: 0 0 auto; }
        .mast-copy { min-width: 0; }
        .brand { margin: 0 0 4px; color: var(--red); font-size: 10px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; }
        .muted { color: var(--muted); }
        .meta { margin: 4px 0 0; line-height: 1.4; font-size: 10px; }
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
        .toolbar .primary { background: var(--red); border-color: var(--red); color: white; }
        .toolbar .is-on { background: var(--red); border-color: var(--red); color: white; }
        .hint { font-size: 11px; }

        .board {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .board th, .board td {
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
        }
        .board thead th {
            background: var(--head);
            color: #44403c;
            font-size: 9px;
            letter-spacing: .04em;
            text-transform: uppercase;
            font-weight: 650;
            padding: 0;
        }
        .week-band {
            display: grid;
            grid-template-columns: repeat(var(--days), minmax(0, 1fr));
            background: var(--head);
            min-height: 28px;
        }
        .week-cell {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1px;
            min-width: 0;
            padding: 3px 1px 4px;
            border-left: 1px solid #e5e5e5;
            overflow: hidden;
        }
        .week-no, .week-date {
            display: block;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            line-height: 1.15;
        }
        .week-no {
            color: var(--red);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .03em;
            text-transform: uppercase;
        }
        .week-band.is-roomy .week-no { font-size: 11px; }
        .week-band.is-roomy .week-date { font-size: 10px; }
        .week-band.is-roomy + .day-row span { font-size: 9px; padding: 4px 2px 5px; }
        .board thead th .week-date,
        .board thead th .day-row span {
            letter-spacing: 0;
            text-transform: none;
        }
        .week-date {
            color: #44403c;
            font-size: 8px;
            font-weight: 600;
        }
        .day-row {
            display: grid;
            grid-template-columns: repeat(var(--days), minmax(0, 1fr));
            background: #fafafa;
        }
        .day-row span {
            min-width: 0;
            padding: 3px 1px 4px;
            text-align: center;
            border-left: 1px solid #ececec;
            overflow: hidden;
            text-overflow: clip;
            white-space: nowrap;
            color: #57534e;
            font-size: 7px;
            letter-spacing: 0;
            text-transform: none;
            font-weight: 600;
        }
        .day-row.is-quiet { height: 4px; }
        .day-row.is-quiet span { padding: 0; font-size: 0; }
        .col-werk { width: 24%; text-align: left; padding: 6px 8px; }
        .col-num { width: 6.2%; text-align: right; padding: 6px 4px; font-variant-numeric: tabular-nums; }
        .col-num.over { color: #b91c1c; font-weight: 700; }
        .col-num.warn { color: #9f1239; font-weight: 650; }
        .col-num.ok { color: #3f6212; font-weight: 650; }
        .col-days { width: auto; padding: 0; }
        .project-row td { background: var(--project); font-weight: 600; }
        .section-row td {
            background: var(--head);
            color: var(--ink);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: 6px 8px;
            box-shadow: inset 3px 0 0 var(--red);
        }
        .work-row td { font-size: 11px; background: white; }
        .indent { padding-left: 18px; }
        .city { font-weight: 400; color: var(--muted); font-size: 10px; }
        .plan-labor { margin-top: 2px; font-size: 10px; font-weight: 500; color: var(--muted); }
        .plan-labor--over { color: #b91c1c; font-weight: 700; }
        .plan-labor--warn { color: #9f1239; }
        .plan-hour-bar { display: flex; align-items: center; gap: 6px; margin-top: 2px; }
        .plan-hour-bar-track { display: block; flex: 1; height: 4px; background: var(--line); overflow: hidden; }
        .plan-hour-bar-fill { display: block; height: 100%; background: #3f6212; }
        .plan-hour-bar--warn .plan-hour-bar-fill { background: #9f1239; }
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
            border-left: 1px solid #efefef;
            display: block;
        }
        .day-grid i.is-week { border-left-color: #d6d3d1; }
        .bars { position: relative; min-height: 28px; padding: 3px 0; }
        .bar {
            position: absolute;
            height: 16px;
            border-radius: 2px;
            color: white;
            font-size: 9px;
            line-height: 16px;
            padding: 0;
            overflow: hidden;
            white-space: nowrap;
            box-shadow: none;
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
            top: 3px;
            bottom: 3px;
            border-radius: 2px;
            background: repeating-linear-gradient(-62deg, rgba(185, 28, 28, 0.05), rgba(185, 28, 28, 0.05) 6px, rgba(185, 28, 28, 0.13) 6px, rgba(185, 28, 28, 0.13) 12px);
            box-shadow: inset 0 0 0 1px rgba(185, 28, 28, 0.55);
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .period-marker {
            position: absolute;
            top: 3px;
            color: #9f1239;
            font-size: 8px;
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
        .legend { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-top: 12px; }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .swatch {
            width: 12px; height: 12px; border-radius: 2px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .list { width: 100%; border-collapse: collapse; margin-top: 0; }
        .list th, .list td { border: none; border-bottom: 1px solid #ececec; padding: 8px 10px; text-align: left; }
        .list th { background: var(--head); font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #44403c; font-weight: 650; }
        .empty { color: var(--muted); padding: 16px; }
        .foot { margin: 14px 0 0; color: var(--muted); font-size: 10px; }
        .company-foot {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 14px;
            margin: 8px 0 0;
            padding-top: 8px;
            border-top: 2px solid var(--red);
            color: var(--ink);
            font-size: 10px;
        }
        @media print {
            .no-print { display: none !important; }
            @page { size: A4 landscape; margin: 0; }
            @page planning-a3 { size: A3 landscape; margin: 0; }
            body { margin: 0; padding: 8mm; max-width: none; }
            body.paper-a3 { page: planning-a3; }
            .sheet { width: auto !important; zoom: var(--print-zoom, 1); }
        }
    </style>
</head>
@php
    $sheetRows = 0;
    foreach ($rows as $projectRow) {
        $sheetRows++;
        if (($projectRow['type'] ?? '') === 'section') {
            continue;
        }
        $sheetRows += count($projectRow['children'] ?? []);
        $sheetRows += count($projectRow['person_bars'] ?? []);
        foreach ($projectRow['children'] ?? [] as $work) {
            $sheetRows += count($work['person_bars'] ?? []);
        }
    }
    $useA3 = $sheetRows > 16 || (int) $dayCount > 35;
@endphp
<body @class(['paper-a3 is-dense' => $useA3]) @if ($autoPrint ?? false) data-autoprint="1" @endif>
    @php
        $showNames = (bool) ($showNames ?? false);
        $printPalette = ['#9f1239', '#b91c1c', '#7f1d1d', '#be123c', '#881337', '#a1122a'];
        $printShade = function (array $bar) use ($printPalette): string {
            if (! empty($bar['is_internal'])) {
                return '#4c0519';
            }

            return $printPalette[((int) ($bar['worker_id'] ?? 0)) % count($printPalette)];
        };
        $assignmentProject = function (array $projectRow): string {
            $title = (string) ($projectRow['title'] ?? '');
            if (! array_key_exists('numbers_label', $projectRow) && ! array_key_exists('number', $projectRow)) {
                return $title;
            }

            $numbers = $projectRow['numbers_label'] ?? ($projectRow['number'] ?? '');

            return trim($numbers.' — '.$title);
        };
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
                    'color' => $printShade($bar),
                ];
            }
            foreach ($projectRow['children'] as $work) {
                foreach ($work['person_bars'] as $bar) {
                    $assignments[] = [
                        'project' => $assignmentProject($projectRow),
                        'work' => $work['title'],
                        'who' => $bar['label'],
                        'start' => $bar['start_date'],
                        'end' => $bar['end_date'],
                    ];
                }
            }
            foreach ($projectRow['person_bars'] as $bar) {
                $assignments[] = [
                    'project' => $assignmentProject($projectRow),
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
            Kies in het printvenster de printer <strong>Opslaan als PDF</strong> of <strong>Microsoft Print to PDF</strong>.
            <span id="planning-paper">Past op één pagina, {{ $useA3 ? 'A3' : 'A4' }} liggend.</span>
        </span>
    </p>

    <div class="sheet" id="planning-sheet">
    <header class="mast">
        <img class="logo" src="{{ asset(config('company.logo')) }}" alt="{{ config('company.name') }}">
        <div class="mast-copy">
            @if ($showNames)
                <p class="brand">Nicon Vloeren · Intern</p>
            @endif
            <h1>
                @if ($clientProject)
                    {{ $clientProject->labeledNumbersLine() }}
                    <span class="project-title">{{ $clientProject->displayTitle() }}</span>
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
                · Afgedrukt: {{ now()->translatedFormat('d M Y') }}
            </p>
        </div>
    </header>

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
                        @php
                            $dayList = collect($days)->values();
                            $bandOffset = 0;
                            $showDayLabels = $dayList->count() <= 16;
                        @endphp
                        <div @class(['week-band', 'is-roomy' => $showDayLabels])>
                            @foreach ($weekBands as $band)
                                @php
                                    $bandStart = $dayList->get($bandOffset);
                                    $bandOffset += (int) $band['span'];
                                @endphp
                                <span class="week-cell" style="grid-column: span {{ $band['span'] }}">
                                    <span class="week-no">Week {{ $band['number'] }}</span>
                                    @if ($bandStart)
                                        <span class="week-date">{{ $bandStart->translatedFormat('j M') }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                        <div @class(['day-row', 'is-quiet' => ! $showDayLabels])>
                            @foreach ($dayList as $day)
                                <span>
                                    @if ($showDayLabels)
                                        {{ $weeks > 1 ? $day->translatedFormat('D j') : $day->translatedFormat('D j M') }}
                                    @endif
                                </span>
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
                            @elseif (! $clientProject && (($projectRow['customer'] ?? null) || ($projectRow['city'] ?? null)))
                                <div class="city">{{ $projectRow['customer'] ?? '' }}{{ ($projectRow['customer'] ?? null) && ($projectRow['city'] ?? null) ? ' · ' : '' }}{{ $projectRow['city'] ?? '' }}</div>
                            @elseif (($projectRow['city'] ?? null) && $clientProject)
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
    <p class="company-foot">
        <span>{{ config('company.name') }}</span>
        <span>{{ config('company.address') }}, {{ config('company.postal_code') }} {{ config('company.city') }}</span>
        <span>{{ config('company.phone') }}</span>
        <span>{{ config('company.email') }}</span>
    </p>
    </div>
    <script>
        function niconFitPlanningPage() {
            const sheet = document.getElementById('planning-sheet');
            if (!sheet) {
                return;
            }
            const mm = 96 / 25.4;
            const pad = 16 * mm;
            const a4Height = (210 * mm) - pad;
            const a3Height = (297 * mm) - pad;
            sheet.style.zoom = '1';
            document.body.classList.remove('paper-a3');
            sheet.style.width = ((297 * mm) - pad) + 'px';
            let height = sheet.offsetHeight;
            const useA3 = height > a4Height + 1;
            document.body.classList.toggle('paper-a3', useA3);
            document.body.classList.toggle('is-dense', useA3);
            if (useA3) {
                sheet.style.width = ((420 * mm) - pad) + 'px';
                height = sheet.offsetHeight;
            }
            const limit = useA3 ? a3Height : a4Height;
            const zoom = Math.min(1, limit / Math.max(height, 1));
            sheet.style.setProperty('--print-zoom', String(zoom));
            sheet.style.zoom = String(zoom);
            sheet.style.width = '';
            const note = document.getElementById('planning-paper');
            if (note) {
                note.textContent = 'Past op één pagina, ' + (useA3 ? 'A3' : 'A4') + ' liggend. Kies dat formaat in het printvenster.';
            }
        }
        function niconPrintPlanning() {
            niconFitPlanningPage();
            const title = document.title;
            document.title = '';
            const restore = () => {
                document.title = title;
                window.removeEventListener('afterprint', restore);
            };
            window.addEventListener('afterprint', restore);
            window.print();
        }
        window.addEventListener('load', niconFitPlanningPage);
        @if ($autoPrint ?? false)
        window.addEventListener('load', niconPrintPlanning);
        @endif
    </script>
</body>
</html>
