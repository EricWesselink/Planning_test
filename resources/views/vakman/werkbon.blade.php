<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Werkbon {{ $detail['heading'] }}</title>
    <style>
        body { font-family: sans-serif; color: #1c1917; margin: 24px; }
        h1 { font-size: 22px; margin: 0; }
        h2 { font-size: 16px; margin: 0 0 4px; }
        .muted { color: #78716c; font-size: 12px; }
        .job { margin-top: 20px; padding-top: 16px; border-top: 1px solid #e7e0d4; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #e7e0d4; }
        th { color: #78716c; font-weight: 600; }
        td.num, th.num { text-align: right; }
        .no-print { margin-bottom: 16px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <p class="no-print">
        <a href="{{ route('vakman.planning.day', $detail['date']->toDateString()) }}">Terug naar de dag</a>
        <button onclick="window.print()" style="margin-left:12px">Afdrukken</button>
    </p>
    <h1>Werkbon</h1>
    <p class="muted">{{ $worker?->planName() ?? auth()->user()?->name }} · {{ $detail['heading'] }}</p>

    @foreach ($detail['jobs'] as $job)
        <section class="job">
            <h2>{{ $job['project_name'] }}</h2>
            @if ($job['numbers'] !== '')
                <p class="muted">{{ $job['numbers'] }}</p>
            @endif
            @if ($job['address'])
                <p>{{ $job['address'] }}</p>
            @endif
            <p>{{ $job['time_label'] }}</p>
            @if ($job['colleagues'] !== [])
                <p class="muted">Samen met: {{ implode(', ', $job['colleagues']) }}</p>
            @endif
            <table>
                <thead>
                    <tr>
                        <th>Werkzaamheid</th>
                        <th class="num">Hoeveelheid</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($job['works'] as $work)
                        <tr>
                            <td>{{ $work['title'] }}</td>
                            <td class="num">{{ $work['quantity'] }} {{ $work['unit'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2">Geen werkzaamheden vastgelegd.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            @if ($job['floors'] !== [])
                <p>Verdieping: {{ implode(', ', $job['floors']) }}</p>
            @endif
            @if ($job['rooms'] !== [])
                <p>Ruimtes: {{ implode(', ', $job['rooms']) }}</p>
            @endif
            @if ($job['notes'] !== [])
                <p>{{ implode(' · ', $job['notes']) }}</p>
            @endif
        </section>
    @endforeach
</body>
</html>
