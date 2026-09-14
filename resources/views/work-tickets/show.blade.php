<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $kindLabel }} {{ $ticket->number }} · {{ $companyName }}</title>
    @include('work-tickets._styles')
</head>
<body>
    @php
        $backUrl = auth()->user()?->isVakman()
            ? route('vakman.planning')
            : route('planning');
        $backLabel = auth()->user()?->isVakman() ? 'Mijn planning' : 'Planning';
    @endphp
    <p class="toolbar no-print">
        <a href="{{ $backUrl }}">← {{ $backLabel }}</a>
        <a class="primary" href="{{ route('work-tickets.pdf', $ticket) }}">Download PDF</a>
        <button type="button" onclick="window.print()">Afdrukken</button>
    </p>
    @if (session('status'))
        <p class="no-print status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="no-print error">{{ $errors->first() }}</p>
    @endif

    @include('work-tickets._document', ['isPdf' => false])

    @if ($canRecordHours)
        <form method="POST" action="{{ route('work-tickets.hours.update', $ticket) }}" class="hours-form no-print">
            @csrf
            @method('PATCH')
            <h2 style="margin:0 0 6px;font-size:15px">Bestede uren</h2>
            <p style="margin:0 0 10px;color:#5b6570">Uurtarief {{ \App\Support\Format::money($ticket->hourly_rate) }}/uur. Uren later op deze bon bijwerken.</p>
            <label for="worked_hours">Uren</label>
            <input id="worked_hours" type="text" inputmode="decimal" name="worked_hours" value="{{ old('worked_hours', $ticket->worked_hours) }}">
            <button type="submit" class="primary" style="margin-left:8px">Opslaan</button>
            @if ($showPrices && $ticket->worked_hours !== null)
                <p style="margin:10px 0 0">Totaal {{ \App\Support\Format::money($ticket->totalAmount()) }}</p>
            @endif
        </form>
    @endif
</body>
</html>
