@php
    $drawingUrl = $drawing ? route('projects.documents.show', [$project, $drawing], false) : null;
    $drawingIsImage = (bool) $drawing?->isImage();
    $drawingIsPdf = (bool) $drawing?->isPdf();
@endphp
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Opleverpunten · {{ $project->name }}</title>
    <style>
        body { font-family: sans-serif; color: #1c1917; margin: 24px; }
        h1 { font-size: 20px; margin: 0; }
        h2 { font-size: 16px; margin: 0 0 6px; }
        .muted { color: #78716c; font-size: 12px; }
        .point { page-break-inside: avoid; margin: 18px 0; padding-top: 12px; border-top: 1px solid #e7e0d4; }
        .point-photo { max-width: 280px; max-height: 180px; }
        .media { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; margin-top: 8px; }
        .map {
            position: relative;
            width: 100%;
            min-height: 180px;
            background: #f4efe6;
            border: 1px solid #e7e0d4;
            margin: 12px 0 18px;
        }
        .map-drawing {
            display: block;
            width: 100%;
            height: auto;
        }
        .map-loading { margin: 0; padding: 72px 12px; text-align: center; }
        .pin {
            position: absolute;
            transform: translate(-50%, -50%);
            min-width: 18px;
            height: 18px;
            border-radius: 99px;
            background: #b91c1c;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            z-index: 2;
        }
        .pin.open { background: #b91c1c; }
        .pin.assigned { background: #e4572e; }
        .pin.progress { background: #ca8a04; }
        .pin.wait { background: #1d4ed8; }
        .pin.done { background: #3f6212; }
        .excerpt {
            --x: 0.5;
            --y: 0.5;
            --zoom: 4;
            position: relative;
            width: 220px;
            height: 160px;
            overflow: hidden;
            background: #f4efe6;
            border: 1px solid #e7e0d4;
        }
        .excerpt-drawing {
            position: absolute;
            width: calc(var(--zoom) * 100%);
            height: calc(var(--zoom) * 100%);
            max-width: none;
            max-height: none;
            left: calc(50% - (var(--x) * var(--zoom) * 100%));
            top: calc(50% - (var(--y) * var(--zoom) * 100%));
        }
        .excerpt .pin { left: 50%; top: 50%; }
        .legend { display: flex; flex-wrap: wrap; gap: 10px; font-size: 11px; color: #78716c; margin: 8px 0 14px; }
        .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 99px; margin-right: 4px; vertical-align: -1px; }
        @media print {
            .no-print { display: none; }
            .map, .excerpt { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
    @if ($includeDrawing && $drawingIsPdf)
        @vite(['resources/js/snag-pdf.js'])
    @endif
</head>
<body @if ($includeDrawing && $drawingIsPdf && $drawingUrl) data-drawing-url="{{ $drawingUrl }}" @endif>
    <p class="no-print"><button onclick="window.print()">Afdrukken / PDF</button></p>
    <div class="muted">Nicon Vloeren</div>
    <h1>{{ $project->name }}</h1>
    <p class="muted">
        Opdrachtgever: {{ $project->customer?->name }}
        · {{ $project->nawLine() ?: 'geen werkadres' }}
        · {{ now()->format('d-m-Y') }}
        @if ($project->supervisor) · {{ $project->supervisor->name }} @endif
    </p>
    <p class="legend">
        <span><i style="background:#b91c1c"></i> Open</span>
        <span><i style="background:#e4572e"></i> Toegewezen</span>
        <span><i style="background:#ca8a04"></i> In behandeling</span>
        <span><i style="background:#1d4ed8"></i> Gereed gemeld</span>
        <span><i style="background:#3f6212"></i> Afgehandeld</span>
    </p>

    @if ($includeDrawing)
        @forelse ($pages as $page)
            <p class="muted">Tekening pagina {{ $page['page'] }} met genummerde punten</p>
            <div class="map" data-page="{{ $page['page'] }}">
                @if ($drawingIsImage && $drawingUrl)
                    <img class="map-drawing" src="{{ $drawingUrl }}" alt="Plattegrond pagina {{ $page['page'] }}">
                @elseif ($drawingIsPdf && $drawingUrl)
                    <p class="map-loading muted">Tekening laden…</p>
                @endif
                @foreach ($page['snags'] as $pin)
                    @if ($pin->x !== null)
                        <span class="pin {{ $pin->status->tone() }}" style="left: {{ $pin->x * 100 }}%; top: {{ $pin->y * 100 }}%;">{{ $pin->number }}</span>
                    @endif
                @endforeach
            </div>
        @empty
            <p class="muted">Geen punten om op de tekening te zetten.</p>
        @endforelse
    @endif

    @foreach ($snags as $snag)
        <section class="point">
            <h2>{{ $snag->title() }}</h2>
            <p class="muted">
                Status: {{ $snag->status->label() }}
                · Toegewezen: {{ $snag->assignee?->displayName() ?: '—' }}
                · Datum: {{ ($snag->logged_on ?? $snag->created_at)?->format('d-m-Y') }}
                · Gereed uiterlijk: {{ $snag->due_date?->format('d-m-Y') ?: '—' }}
            </p>
            <p>{{ $snag->description ?: 'Geen omschrijving' }}</p>
            <div class="media">
                @if ($includeDrawing && $drawingUrl && $snag->x !== null && $snag->y !== null)
                    <div>
                        <p class="muted">Deeltekening</p>
                        <div class="excerpt" data-page="{{ (int) $snag->drawing_page }}" style="--x: {{ $snag->x }}; --y: {{ $snag->y }};">
                            @if ($drawingIsImage)
                                <img class="excerpt-drawing" src="{{ $drawingUrl }}" alt="">
                            @endif
                            <span class="pin {{ $snag->status->tone() }}">{{ $snag->number }}</span>
                        </div>
                    </div>
                @endif
                @if ($includePhotos)
                    @foreach ($snag->issuePhotos() as $photo)
                        <div>
                            <p class="muted">Constatering</p>
                            <img class="point-photo" src="{{ route('projects.snags.photo', [$project, $snag, $photo]) }}" alt="">
                        </div>
                    @endforeach
                    @foreach ($snag->completionPhotos() as $photo)
                        <div>
                            <p class="muted">Gereedfoto</p>
                            <img class="point-photo" src="{{ route('projects.snags.photo', [$project, $snag, $photo]) }}" alt="">
                        </div>
                    @endforeach
                @endif
            </div>
        </section>
    @endforeach
</body>
</html>
