<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }} {{ $ticket->number }}</title>
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
        .doc-title {
            font-size: 16pt;
            font-weight: 700;
            color: #163a5f;
            letter-spacing: 0.04em;
        }
        .meta { margin: 12px 0 10px; }
        .meta td { border: none; padding: 2px 0; }
        .lbl {
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #5b6570;
            width: 28%;
        }
        .lines { margin-top: 10px; }
        .lines th {
            text-align: left;
            border-bottom: 0.6pt solid #163a5f;
            color: #5b6570;
            font-size: 8pt;
            padding: 4px 6px;
        }
        .lines td { padding: 5px 6px; border-bottom: 0.4pt solid #e7e0d4; }
        .num { text-align: right; white-space: nowrap; }
        .notes { margin-top: 12px; }
        .foot { margin-top: 28px; font-size: 8pt; color: #5b6570; }
    </style>
</head>
<body>
    <table class="brand">
        <tr>
            <td class="logo">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $companyName }}">
                @endif
            </td>
            <td>
                <div class="doc-title">{{ $documentTitle }}</div>
                <div>{{ $ticket->number }}</div>
            </td>
            <td style="text-align:right;font-size:8pt;color:#5b6570">
                <strong>{{ $companyName }}</strong><br>
                {{ $companyAddress }}<br>
                {{ $companyPostalCode }} {{ $companyCity }}<br>
                {{ $companyEmail }} · {{ $companyPhone }}
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr><td class="lbl">Aan</td><td>{{ $recipient }}</td></tr>
        <tr><td class="lbl">Project</td><td>{{ $projectTitle }}</td></tr>
        @if ($address)
            <tr><td class="lbl">Adres</td><td>{{ $address }}</td></tr>
        @endif
        <tr><td class="lbl">Periode</td><td>{{ $period }}</td></tr>
        @if ($floors !== '')
            <tr><td class="lbl">Verdieping</td><td>{{ $floors }}</td></tr>
        @endif
        @if ($rooms !== '')
            <tr><td class="lbl">Ruimtes</td><td>{{ $rooms }}</td></tr>
        @endif
        @if ($drawings !== [])
            <tr><td class="lbl">Tekening</td><td>{{ implode(', ', $drawings) }}</td></tr>
        @endif
        @if ($colleagues !== [])
            <tr><td class="lbl">Ook op het werk</td><td>{{ implode(', ', $colleagues) }}</td></tr>
        @endif
        @if ($showPrices && $ticket->billingLabel())
            <tr><td class="lbl">Afrekening</td><td>{{ $ticket->billingLabel() }}</td></tr>
        @endif
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Werkzaamheid</th>
                <th class="num">Hoeveelheid</th>
                @if ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Unit)
                    <th class="num">Prijs</th>
                    <th class="num">Bedrag</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($ticket->lines as $line)
                <tr>
                    <td>{{ $line->workItem?->name ?? 'Werkzaamheid' }}</td>
                    <td class="num">{{ \App\Support\Format::qty($line->quantity, 2) }} {{ $line->unit?->label() }}</td>
                    @if ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Unit)
                        <td class="num">{{ \App\Support\Format::money($line->unit_price) }}/{{ $line->unit?->label() }}</td>
                        <td class="num">{{ \App\Support\Format::money($line->amount) }}</td>
                    @endif
                </tr>
            @endforeach
            @if ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Unit)
                <tr>
                    <td colspan="3"><strong>Totaal</strong></td>
                    <td class="num"><strong>{{ \App\Support\Format::money($ticket->totalAmount()) }}</strong></td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($ticket->notes)
        <div class="notes">
            <div class="lbl">Opmerking</div>
            <div>{{ $ticket->notes }}</div>
        </div>
    @endif

    @if ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Hourly)
        <div class="notes">
            Uurtarief {{ \App\Support\Format::money($ticket->hourly_rate) }}/uur
            @if ($ticket->worked_hours !== null)
                · {{ \App\Support\Format::hours($ticket->worked_hours) }}
                · {{ \App\Support\Format::money($ticket->totalAmount()) }}
            @endif
        </div>
    @endif

    @if ($showPrices && $ticket->billing_method === \App\Enums\WorkTicketBilling::Fixed)
        <div class="notes">Vaste prijs {{ \App\Support\Format::money($ticket->fixed_price) }}</div>
    @endif

    <div class="foot">{{ $companyName }} · {{ $ticket->kind->label() }} {{ $ticket->number }}</div>
</body>
</html>
