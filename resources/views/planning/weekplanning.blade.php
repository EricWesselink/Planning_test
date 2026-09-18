<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $heading ?? 'Weekplanning vakmannen' }} · Week {{ $weekNumber }} · {{ $weekYear }}</title>
    <style>
        @page {
            margin: 12mm 10mm 16mm 10mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            font-size: 9pt;
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
            padding: 0 0 10px;
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
        .brand-week {
            font-size: 10pt;
            color: #163a5f;
            padding-top: 3px;
        }
        .brand-side {
            width: 34%;
            text-align: right;
            font-size: 8pt;
            color: #5b6570;
            line-height: 1.35;
        }
        .brand-legend {
            width: auto;
            margin-left: auto;
            border-collapse: collapse;
        }
        .brand-legend td {
            border: none;
            padding: 2px 0;
            vertical-align: middle;
            font-size: 7.5pt;
            color: #163a5f;
            font-weight: 700;
        }
        .legend-logo {
            width: 92px;
            padding-right: 8px;
            text-align: left;
        }
        .legend-logo img {
            height: 22px;
            width: auto;
            max-height: 22px;
            display: block;
            object-fit: contain;
        }
        .legend-label {
            text-align: left;
            white-space: nowrap;
        }
        .block-brand {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 3px;
        }
        .block-brand td {
            border: none;
            padding: 0;
            vertical-align: middle;
            line-height: 1;
        }
        .block-brand-label {
            font-size: 6pt;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #5b6570;
            text-align: left;
            padding-right: 4px;
        }
        .block-brand-logo {
            width: 1%;
            text-align: right;
            white-space: nowrap;
            padding: 0;
        }
        .block-brand-logo img {
            height: 20px;
            width: auto;
            max-height: 20px;
            object-fit: contain;
            display: block;
            margin-left: auto;
        }
        .sheet thead .day-head th {
            background: #163a5f;
            color: #ffffff;
            font-size: 8pt;
            font-weight: 700;
            text-align: center;
            padding: 5px 3px;
            border: 0.4pt solid #0f2a45;
            vertical-align: middle;
        }
        .sheet thead .day-head th.person {
            width: 11%;
            text-align: left;
            padding-left: 6px;
        }
        .day-name { display: block; }
        .day-date {
            display: block;
            font-weight: 400;
            font-size: 7.5pt;
            padding-top: 1px;
        }
        .sheet tbody td {
            border: 0.4pt solid #c5cdd6;
            vertical-align: top;
            padding: 4px;
            overflow: hidden;
            word-wrap: break-word;
        }
        td.person {
            background: #f4f6f8;
            width: 11%;
            padding-left: 6px;
            padding-right: 4px;
        }
        .person-name {
            font-weight: 700;
            font-size: 8.5pt;
            color: #1a1a1a;
            line-height: 1.2;
        }
        .person-team {
            font-size: 7pt;
            color: #5b6570;
            padding-top: 1px;
            line-height: 1.2;
        }
        .empty {
            color: #9aa3ad;
            text-align: center;
            padding: 2px 0;
            font-size: 8pt;
        }
        .block {
            border: 0.5pt solid rgba(0, 0, 0, 0.12);
            padding: 5px 6px;
            margin: 0 0 3px;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        .block:last-child { margin-bottom: 0; }
        .block.is-away {
            border-style: dashed;
        }
        .block.is-away .block-title {
            color: #4b5d73;
        }
        .block-title {
            font-weight: 700;
            font-size: 8pt;
            line-height: 1.25;
            color: #1a1a1a;
            word-wrap: normal;
            overflow-wrap: normal;
            word-break: keep-all;
            overflow: visible;
        }
        .block-title-word {
            white-space: nowrap;
        }
        .block-place,
        .block-line,
        .block-hours {
            font-size: 7pt;
            line-height: 1.2;
            padding-top: 1px;
        }
        .block-place {
            font-weight: 600;
        }
        .none {
            text-align: center;
            padding: 18px 8px;
            color: #6b7280;
        }
        tr.person-row {
            page-break-inside: auto;
        }
    </style>
</head>
<body>
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
                                <div class="brand-title">{{ $heading ?? 'Weekplanning vakmannen' }}</div>
                                <div class="brand-week">{{ $weekLabel }}</div>
                            </td>
                            <td class="brand-side">
                                <table class="brand-legend">
                                    <tr>
                                        <td class="legend-logo">
                                            @if ($logo)
                                                <img src="{{ $logo }}" alt="{{ $companyName }}" height="22" width="45">
                                            @endif
                                        </td>
                                        <td class="legend-label">{{ $companyName }} · Projecten</td>
                                    </tr>
                                    <tr>
                                        <td class="legend-logo">
                                            @if ($shopLogo)
                                                <img src="{{ $shopLogo }}" alt="{{ $shopName }}" height="22" width="88">
                                            @endif
                                        </td>
                                        <td class="legend-label">{{ $shopName }} · Winkel</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            <tr class="day-head">
                <th class="person">Team</th>
                @foreach ($days as $day)
                    <th>
                        <span class="day-name">{{ $day['name'] }}</span>
                        <span class="day-date">{{ $day['date'] }}</span>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($people as $person)
                <tr class="person-row">
                    <td class="person">
                        <div class="person-name">{{ $person['name'] }}</div>
                        @if (! empty($person['people_label']))
                            <div class="person-team">{{ $person['people_label'] }}</div>
                        @endif
                    </td>
                    @foreach ($days as $day)
                        @php $blocks = $person['days'][$day['key']] ?? []; @endphp
                        <td>
                            @forelse ($blocks as $block)
                                <div class="block{{ ! empty($block['away']) ? ' is-away' : '' }}" style="background: {{ $block['color'] }};">
                                    @if (empty($block['away']))
                                        <table class="block-brand">
                                            <tr>
                                                <td class="block-brand-label">{{ $block['source_label'] }}</td>
                                                @if (! empty($block['source_logo']))
                                                    <td class="block-brand-logo">
                                                        @php
                                                            $shopLogoCard = str_contains((string) $block['source_logo'], 'kloppenburg');
                                                            $logoHeight = 20;
                                                            $logoWidth = $shopLogoCard ? 80 : 41;
                                                        @endphp
                                                        <img src="{{ $block['source_logo'] }}" alt="{{ $block['source_label'] }}" height="{{ $logoHeight }}" width="{{ $logoWidth }}">
                                                    </td>
                                                @endif
                                            </tr>
                                        </table>
                                    @endif
                                    <div class="block-title">@foreach (preg_split('/\s+/u', (string) $block['title'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $index => $word)@if ($index > 0) {{ ' ' }}@endif<span class="block-title-word">{{ $word }}</span>@endforeach</div>
                                    @if (! empty($block['city']))
                                        <div class="block-place">{{ $block['city'] }}</div>
                                    @endif
                                    @if (! empty($block['numbers']))
                                        <div class="block-line">{{ $block['numbers'] }}</div>
                                    @endif
                                    @if (! empty($block['activity']))
                                        <div class="block-line">{{ $block['activity'] }}@if (! empty($block['badge'])) ({{ $block['badge'] }})@endif</div>
                                    @elseif (! empty($block['badge']))
                                        <div class="block-line">{{ $block['badge'] }}</div>
                                    @endif
                                    @if (! empty($block['who']))
                                        <div class="block-line">{{ $block['who'] }}</div>
                                    @endif
                                    <div class="block-hours">{{ $block['hours'] }}</div>
                                </div>
                            @empty
                                <div class="empty">{{ $day['empty_label'] }}</div>
                            @endforelse
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="none" colspan="{{ 1 + count($days) }}">Geen vakmannen ingepland in deze week.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
