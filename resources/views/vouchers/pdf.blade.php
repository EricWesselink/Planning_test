<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }} {{ $voucher->number }}</title>
    <style>
        @page { margin: 12mm 12mm 14mm 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            font-size: 9.5pt;
            margin: 0;
        }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .brand td {
            border: none;
            border-bottom: 0.7pt solid #163a5f;
            padding: 0 0 10px;
            vertical-align: middle;
        }
        .logo { width: 118px; padding-right: 14px; }
        .logo img { width: 110px; height: 54px; display: block; }
        .brand-name {
            font-size: 10pt;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #163a5f;
            line-height: 1.2;
        }
        .brand-lines {
            font-size: 8pt;
            color: #5b6570;
            line-height: 1.4;
            padding-top: 3px;
        }
        .doc-side { width: 38%; text-align: right; }
        .doc-title {
            font-size: 16pt;
            font-weight: 700;
            color: #163a5f;
            letter-spacing: 0.04em;
            line-height: 1.1;
        }
        .meta { width: auto; margin-left: auto; margin-top: 6px; }
        .meta td {
            border: none;
            padding: 1px 0;
            font-size: 8pt;
            line-height: 1.35;
        }
        .meta .lbl { color: #5b6570; padding-right: 10px; text-align: left; white-space: nowrap; }
        .meta .val { text-align: right; font-weight: 700; color: #163a5f; white-space: nowrap; }
        .party { margin: 12px 0 14px; }
        .party td { border: none; padding: 0 12px 0 0; }
        .party .lbl {
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #5b6570;
        }
        .party .val { font-size: 10pt; font-weight: 700; color: #1a1a1a; padding-top: 1px; }
        .party .sub { font-size: 8.5pt; color: #5b6570; padding-top: 2px; }
        .activity {
            margin: 0 0 8px;
            page-break-inside: avoid;
        }
        .activity-head td {
            border: none;
            border-bottom: 0.6pt solid #163a5f;
            padding: 0 0 4px;
            font-size: 9.5pt;
            font-weight: 700;
            color: #163a5f;
        }
        .activity-head .num { text-align: right; white-space: nowrap; }
        .rooms { margin: 2px 0 0; }
        .rooms td {
            border: none;
            padding: 1px 0;
            font-size: 8pt;
            color: #5b6570;
        }
        .activity-head .period {
            display: block;
            font-size: 8pt;
            font-weight: 400;
            color: #5b6570;
            padding-top: 1px;
        }
        .total td {
            border: none;
            border-top: 1.2pt solid #163a5f;
            padding: 8px 0 0;
            font-size: 11pt;
            font-weight: 700;
            color: #163a5f;
        }
        .total .num { text-align: right; white-space: nowrap; }
        .notes {
            margin: 10px 0 0;
            font-size: 8.5pt;
            color: #1a1a1a;
        }
        .notes .lbl {
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #5b6570;
            margin-bottom: 2px;
        }
        .sign {
            margin-top: 16px;
            border-top: 0.5pt solid #c5cdd6;
            padding-top: 8px;
        }
        .sign td { border: none; padding: 0; font-size: 8pt; color: #5b6570; line-height: 1.45; }
        .parent { font-size: 8pt; color: #5b6570; margin: 0 0 8px; }
    </style>
</head>
<body>
    <table class="brand">
        <tr>
            @if ($logo)
                <td class="logo">
                    <img src="{{ $logo }}" alt="{{ $companyName }}" width="110" height="54">
                </td>
            @endif
            <td>
                <div class="brand-name">{{ $companyName }}</div>
                <div class="brand-lines">
                    {{ $companyAddress }}<br>
                    {{ $companyPostalCode }} {{ $companyCity }}<br>
                    {{ $companyPhone }} · {{ $companyEmail }}
                </div>
            </td>
            <td class="doc-side">
                <div class="doc-title">{{ $documentTitle }}</div>
                <table class="meta">
                    <tr>
                        <td class="lbl">Bonnummer</td>
                        <td class="val">{{ $voucher->number }}</td>
                    </tr>
                    <tr>
                        <td class="lbl">Datum</td>
                        <td class="val">{{ $voucher->issued_on?->format('d-m-Y') }}</td>
                    </tr>
                    @if (filled($projectNumber))
                        <tr>
                            <td class="lbl">Projectnummer</td>
                            <td class="val">{{ $projectNumber }}</td>
                        </tr>
                    @endif
                    @if ($workNumber !== '')
                        <tr>
                            <td class="lbl">Werknummer</td>
                            <td class="val">{{ $workNumber }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="party">
        <tr>
            <td>
                <div class="lbl">Opdrachtnemer</div>
                <div class="val">{{ $recipient }}</div>
            </td>
            <td>
                <div class="lbl">Project</div>
                <div class="val">{{ $projectTitle }}</div>
                <div class="sub">
                    @if (filled($projectNumber))
                        Projectnr. {{ $projectNumber }}
                    @endif
                    @if (filled($projectNumber) && $workNumber !== '')
                        ·
                    @endif
                    @if ($workNumber !== '')
                        Werk: {{ $workNumber }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    @if ($voucher->parent)
        <p class="parent">Bij opdrachtbon {{ $voucher->parent->number }}</p>
    @endif

    @foreach ($groups as $group)
        @php
            $groupPeriod = \App\Support\VoucherActivityGroups::periodLabel($group);
        @endphp
        <div class="activity">
            <table class="activity-head">
                <tr>
                    <td>
                        {{ $group['description'] }}
                        @if ($groupPeriod !== '')
                            <span class="period">{{ $groupPeriod }}</span>
                        @endif
                    </td>
                    <td class="num">{{ \App\Support\VoucherActivityGroups::quantityLabel($group) }}</td>
                    <td class="num">{{ \App\Support\VoucherActivityGroups::priceLabel($group) }}</td>
                    <td class="num">{{ \App\Support\Format::money($group['amount']) }}</td>
                </tr>
            </table>
            @if ($group['has_rooms'])
                <table class="rooms">
                    @foreach ($group['entries'] as $entry)
                        @php
                            $roomPeriod = \App\Support\VoucherActivityGroups::roomPeriodLabel($entry);
                        @endphp
                        <tr>
                            <td>
                                {{ $entry['room_label'] }}
                                @if ($roomPeriod !== '' && $roomPeriod !== $groupPeriod)
                                    · {{ $roomPeriod }}
                                @endif
                            </td>
                            <td class="qty">{{ \App\Support\VoucherActivityGroups::roomQuantityLabel($group, $entry) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endforeach

    <table class="total">
        <tr>
            <td>{{ $voucher->type === \App\Enums\VoucherType::Opdracht ? 'Totaal opdracht' : 'Totaal te factureren' }}</td>
            <td class="num">{{ \App\Support\Format::money($total) }}</td>
        </tr>
    </table>

    @if ($voucher->notes)
        <div class="notes">
            <div class="lbl">Toelichting</div>
            {{ $voucher->notes }}
        </div>
    @endif

    <table class="sign">
        <tr>
            <td>
                Opdracht verstrekt door: {{ $companyName }}<br>
                Opdrachtnemer: {{ $recipient }}
            </td>
        </tr>
    </table>
</body>
</html>
