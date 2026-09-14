<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opdrachtbon {{ $job['project_name'] }}</title>
    @include('work-tickets._styles')
</head>
<body>
    @php
        $project = $job['project'];
        $total = collect($works)->sum(fn (array $work): float => (float) ($work['amount'] ?? 0));
        $hasUnitPrices = collect($works)->contains(fn (array $work): bool => ($work['price_label'] ?? null) !== null);
    @endphp
    <p class="toolbar no-print">
        <a href="{{ route('vakman.planning.day', $detail['date']->toDateString()) }}">← Terug naar de dag</a>
        <button type="button" onclick="window.print()">Afdrukken</button>
    </p>
    @include('work-tickets._document', [
        'isPdf' => false,
        'logo' => null,
        'logoUrl' => asset($project->issuerLogo()),
        'companyName' => $project->issuerName(),
        'companyAddress' => config('company.address'),
        'companyPostalCode' => config('company.postal_code'),
        'companyCity' => config('company.city'),
        'companyEmail' => config('company.email'),
        'companyPhone' => config('company.phone'),
        'documentTitle' => 'OPDRACHTBON',
        'kindLabel' => 'Opdrachtbon',
        'number' => $detail['date']->format('d-m-Y'),
        'issuedOn' => $detail['date']->format('d-m-Y'),
        'recipient' => $worker?->company ?: ($worker?->planName() ?? auth()->user()?->name),
        'recipientKind' => $worker?->employment_type?->label(),
        'projectTitle' => $job['project_name'],
        'projectNumber' => $project->workCode(),
        'workNumber' => $project->workNumber(),
        'address' => $job['address'],
        'period' => $job['time_label'].' · '.$detail['heading'],
        'floors' => implode(', ', $job['floors']),
        'rooms' => implode(', ', $job['rooms']),
        'ticket' => null,
        'rows' => $works,
        'showPrices' => true,
        'priceMode' => $hasUnitPrices ? 'unit' : null,
        'total' => $total,
        'colleagues' => $job['colleagues'] ?? [],
        'notesText' => implode("\n", $job['notes'] ?? []),
        'drawingItems' => collect($job['drawings'] ?? [])->map(fn ($drawing) => [
            'name' => $drawing->original_filename ?: 'Tekening',
            'url' => route('projects.documents.show', [$project, $drawing]),
            'path' => null,
            'is_image' => $drawing->isImage(),
        ])->all(),
    ])
</body>
</html>
