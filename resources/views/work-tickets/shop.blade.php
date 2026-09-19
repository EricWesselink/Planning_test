<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $kindLabel }} {{ $number }} · {{ $companyName }}</title>
    @include('work-tickets._styles')
    @include('work-tickets._print')
</head>
<body>
    <p class="toolbar no-print">
        <a href="{{ route('projects.show', $project) }}">← Winkelwerk</a>
        <a class="primary" href="{{ route('projects.winkel.werkbon.pdf', $project) }}" id="ticket-pdf-link">Download PDF</a>
        <button type="button" onclick="niconPrintTicket()">Afdrukken</button>
    </p>
    @include('work-tickets._document', ['isPdf' => false])
    @if (($includeMeasurementForm ?? false) && is_array($measurementForm ?? null))
        @include('measurement-forms._document', $measurementForm)
    @endif
</body>
</html>
