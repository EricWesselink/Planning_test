<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $kindLabel }} {{ $ticket->number }} · {{ $companyName }}</title>
    @include('work-tickets._styles')
    @include('work-tickets._print')
    @if (($drawingIsPdf ?? false) && filled($drawingUrl ?? null))
        @vite(['resources/js/snag-pdf.js'])
    @endif
</head>
<body
    @if (($drawingIsPdf ?? false) && filled($drawingUrl ?? null))
        data-drawing-url="{{ $drawingUrl }}"
        data-print-when-ready="1"
    @endif
>
    <p class="toolbar no-print">
        <a href="{{ route('work-tickets.show', $ticket) }}">← Terug naar bon</a>
        <button type="button" class="primary" onclick="niconPrintTicket()">Afdrukken / PDF</button>
    </p>
    <p class="no-print status">De tekening staat op de volgende pagina. Kies daarna Opslaan als PDF.</p>
    @include('work-tickets._document', ['isPdf' => true])
</body>
</html>
