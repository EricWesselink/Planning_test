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
    <div class="toolbar no-print">
        @if ($preview ?? false)
            <a href="{{ $backUrl }}">← Terug</a>
            <form method="POST" action="{{ $pdfAction }}" style="display:inline">
                @csrf
                @foreach ($pdfFields as $field)
                    <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}">
                @endforeach
                <button type="submit" class="primary" id="ticket-pdf-link">Download PDF</button>
            </form>
        @else
            <a href="{{ route('projects.show', $project) }}">← {{ $project->kind?->label() ?? 'Klein werk' }}</a>
            <a class="primary" href="{{ route('projects.small.werkbon.pdf', $project) }}" id="ticket-pdf-link">Download PDF</a>
        @endif
        <button type="button" onclick="niconPrintTicket()">Afdrukken</button>
    </div>
    @include('work-tickets._document', ['isPdf' => false])
</body>
</html>
