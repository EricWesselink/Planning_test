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
            $people = $job['people'] ?? [];
            $ownKeys = [];
            foreach ($people as $person) {
                $normalized = mb_strtolower(trim((string) $person));
                if ($normalized === '') {
                    continue;
                }
                $ownKeys[] = $normalized;
                $ownKeys[] = explode(' ', $normalized)[0];
            }
            $colleagues = collect($job['colleagues'] ?? [])
                ->reject(function (string $name) use ($ownKeys): bool {
                    $needle = mb_strtolower(trim($name));
                    $first = explode(' ', $needle)[0] ?? '';

                    return in_array($needle, $ownKeys, true) || ($first !== '' && in_array($first, $ownKeys, true));
                })
                ->values()
                ->all();
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
            'whoHeading' => 'Vakmannen',
            'recipient' => implode(', ', $people) ?: (auth()->user()?->name ?? ''),
            'recipientKind' => null,
            'foreman' => $job['foreman'] ?? null,
            'workTicketHolder' => $job['work_ticket_holder'] ?? null,
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
            'colleagues' => $colleagues,
            'notesText' => implode("\n", $job['notes']),
            'drawingItems' => collect($job['drawings'])->map(fn ($drawing) => [
                'name' => $drawing->original_filename ?: 'Tekening',
                'url' => route('projects.documents.show', [$project, $drawing]),
                'path' => null,
                'is_image' => $drawing->isImage(),
                'is_pdf' => $drawing->isPdf(),
            ])->all(),
        ])
    @endforeach
</body>
</html>
