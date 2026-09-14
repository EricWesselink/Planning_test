<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Werkbon {{ $detail['heading'] }}</title>
    @include('work-tickets._styles')
</head>
<body>
    <p class="toolbar no-print">
        <a href="{{ route('vakman.planning.day', $detail['date']->toDateString()) }}">← Terug naar de dag</a>
        <button type="button" onclick="window.print()">Afdrukken</button>
    </p>
    @foreach ($detail['jobs'] as $job)
        @php
            $project = $job['project'];
            $logoRelative = $project->issuerLogo();
        @endphp
        @include('work-tickets._document', [
            'isPdf' => false,
            'logo' => null,
            'logoUrl' => asset($logoRelative),
            'companyName' => $project->issuerName(),
            'companyAddress' => config('company.address'),
            'companyPostalCode' => config('company.postal_code'),
            'companyCity' => config('company.city'),
            'companyEmail' => config('company.email'),
            'companyPhone' => config('company.phone'),
            'documentTitle' => 'WERKBON',
            'kindLabel' => 'Werkbon',
            'number' => $detail['date']->format('d-m-Y'),
            'issuedOn' => $detail['date']->format('d-m-Y'),
            'recipient' => $worker?->planName() ?? auth()->user()?->name,
            'recipientKind' => $worker?->employment_type?->label(),
            'projectTitle' => $job['project_name'],
            'projectNumber' => $project->workCode(),
            'workNumber' => $project->workNumber(),
            'address' => $job['address'],
            'period' => $job['time_label'].' · '.$detail['heading'],
            'floors' => implode(', ', $job['floors']),
            'rooms' => implode(', ', $job['rooms']),
            'ticket' => null,
            'rows' => $job['works'],
            'showPrices' => false,
            'colleagues' => $job['colleagues'],
            'notesText' => implode("\n", $job['notes']),
            'drawingItems' => collect($job['drawings'])->map(fn ($drawing) => [
                'name' => $drawing->original_filename ?: 'Tekening',
                'url' => route('projects.documents.show', [$project, $drawing]),
                'path' => null,
                'is_image' => $drawing->isImage(),
            ])->all(),
        ])
    @endforeach
</body>
</html>
