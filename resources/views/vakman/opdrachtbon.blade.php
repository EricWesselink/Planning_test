<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opdrachtbon {{ $job['project_name'] }}</title>
    <style>
        body { font-family: sans-serif; color: #1c1917; margin: 24px; }
        h1 { font-size: 22px; margin: 0; }
        h2 { font-size: 16px; margin: 0 0 4px; }
        .muted { color: #78716c; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 16px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #e7e0d4; }
        th { color: #78716c; font-weight: 600; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .total td { font-weight: 700; border-top: 2px solid #1c1917; }
        .no-print { margin-bottom: 16px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    @php
        $total = collect($works)->sum(fn (array $work): float => (float) ($work['amount'] ?? 0));
    @endphp
    <p class="no-print">
        <a href="{{ route('vakman.planning.day', $detail['date']->toDateString()) }}">Terug naar de dag</a>
        <button onclick="window.print()" style="margin-left:12px">Afdrukken</button>
    </p>
    <h1>Opdrachtbon</h1>
    <p class="muted">{{ $worker?->planName() ?? auth()->user()?->name }} · {{ $detail['heading'] }}</p>
    <h2>{{ $job['project_name'] }}</h2>
    @if ($job['numbers'] !== '')
        <p class="muted">{{ $job['numbers'] }}</p>
    @endif
    @if ($job['address'])
        <p>{{ $job['address'] }}</p>
    @endif

    <table>
        <thead>
            <tr>
                <th>Werkzaamheid</th>
                <th class="num">Hoeveelheid</th>
                <th class="num">Prijsafspraak</th>
                <th class="num">Bedrag</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($works as $work)
                <tr>
                    <td>{{ $work['title'] }}</td>
                    <td class="num">{{ $work['quantity'] }} {{ $work['unit'] }}</td>
                    <td class="num">{{ $work['price_label'] ?? '—' }}</td>
                    <td class="num">{{ $work['amount'] !== null ? \App\Support\Format::money($work['amount']) : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Nog geen opdrachtregels vastgelegd.</td>
                </tr>
            @endforelse
            <tr class="total">
                <td colspan="3">Totaal</td>
                <td class="num">{{ \App\Support\Format::money($total) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
