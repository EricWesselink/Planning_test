<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>NICON VLOEREN · {{ $heading }}</title>
    <style>
        @page {
            margin: 12mm 8mm 14mm 8mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            font-size: 8pt;
            margin: 0;
        }
        table.sheet {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .brand-cell {
            border: none;
            border-bottom: 0.7pt solid #163a5f;
            padding: 0 0 8px;
            vertical-align: middle;
        }
        .brand {
            width: 100%;
            border-collapse: collapse;
        }
        .brand td {
            border: none;
            vertical-align: middle;
            padding: 0;
        }
        .logo {
            width: 118px;
            padding-right: 14px;
        }
        .logo img {
            width: 110px;
            height: 54px;
            display: block;
        }
        .brand-name {
            font-size: 11pt;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #163a5f;
            line-height: 1.2;
        }
        .brand-title {
            font-size: 16pt;
            font-weight: 700;
            color: #163a5f;
            line-height: 1.15;
            padding-top: 2px;
        }
        .brand-meta {
            font-size: 8.5pt;
            color: #5b6570;
            padding-top: 3px;
        }
        .cols th {
            background: #163a5f;
            color: #ffffff;
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-align: left;
            padding: 4px 5px;
            border: 0.4pt solid #163a5f;
        }
        .row td {
            border: 0.4pt solid #d5d0c8;
            padding: 4px 5px;
            vertical-align: top;
            font-size: 7.5pt;
            line-height: 1.25;
            color: #1a1a1a;
        }
        tr.row {
            page-break-inside: avoid;
        }
        .row:nth-child(even) td {
            background: #f7f4ef;
        }
        .c-nr { width: 10%; }
        .c-work { width: 9%; }
        .c-name { width: 15%; }
        .c-addr { width: 16%; }
        .c-start { width: 8%; }
        .c-week { width: 5%; }
        .c-end { width: 10%; }
        .c-status { width: 8%; }
        .c-progress { width: 9%; }
        .c-team { width: 10%; }
        .empty {
            text-align: center;
            padding: 16px 8px;
            color: #6b7280;
        }
        .summary {
            margin-top: 10px;
            width: 100%;
            border-collapse: collapse;
        }
        .summary td {
            border: none;
            border-top: 0.7pt solid #163a5f;
            padding: 8px 10px 0 0;
            font-size: 8pt;
            color: #163a5f;
            white-space: nowrap;
        }
        .summary .lbl {
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #5b6570;
        }
        .summary .val {
            font-size: 10pt;
            font-weight: 700;
            padding-top: 1px;
        }
    </style>
</head>
<body>
    <table class="sheet">
        <thead>
            <tr>
                <td class="brand-cell" colspan="10">
                    <table class="brand">
                        <tr>
                            @if ($logo)
                                <td class="logo">
                                    <img src="{{ $logo }}" alt="{{ $companyName }}" width="110" height="54">
                                </td>
                            @endif
                            <td>
                                <div class="brand-name">NICON VLOEREN</div>
                                <div class="brand-title">{{ $heading }}</div>
                                <div class="brand-meta">Gegenereerd op: {{ $generatedOn }}@if ($filterLabel) · {{ $filterLabel }}@endif</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            <tr class="cols">
                <th class="c-nr">Projectnr.</th>
                <th class="c-work">Werk</th>
                <th class="c-name">Project</th>
                <th class="c-addr">Werkadres</th>
                <th class="c-start">Start</th>
                <th class="c-week">Week</th>
                <th class="c-end">Eind</th>
                <th class="c-status">Status</th>
                <th class="c-progress">Voortgang</th>
                <th class="c-team">Uitvoerder/team</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="row">
                    <td class="c-nr">{{ $row['project_number'] }}</td>
                    <td class="c-work">{{ $row['work_number'] }}</td>
                    <td class="c-name">{{ $row['name'] }}</td>
                    <td class="c-addr">{{ $row['address'] }}</td>
                    <td class="c-start">{{ $row['start_date'] }}</td>
                    <td class="c-week">{{ $row['start_week'] }}</td>
                    <td class="c-end">{{ $row['end'] }}</td>
                    <td class="c-status">{{ $row['status'] }}</td>
                    <td class="c-progress">{{ $row['progress'] }}</td>
                    <td class="c-team">{{ $row['team'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="empty">Geen actieve projecten voor dit overzicht.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <table class="summary">
        <tr>
            <td>
                <div class="lbl">Actieve projecten</div>
                <div class="val">{{ $projectCount }}</div>
            </td>
            <td>
                <div class="lbl">Totaal m²</div>
                <div class="val">{{ $squareMeters }} m²</div>
            </td>
            <td>
                <div class="lbl">Ingepland</div>
                <div class="val">{{ $scheduledCount }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
