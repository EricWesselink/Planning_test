<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opleverpunt #{{ $snag->number }}</title>
    <style>
        body { font-family: sans-serif; margin: 0; color: #1c1917; background: #fbf8f3; }
        main { max-width: 32rem; margin: 0 auto; padding: 16px 16px 32px; }
        .card { background: #fff; border: 1px solid #e7e0d4; padding: 16px; }
        img { max-width: 100%; display: block; }
        .thumbs { display: flex; flex-wrap: wrap; gap: 8px; }
        .thumbs img { width: 100%; max-height: 220px; object-fit: cover; }
        label { display: block; margin-top: 12px; font-size: 13px; color: #78716c; }
        textarea, input { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 10px; border: 1px solid #e7e0d4; font: inherit; }
        button { margin-top: 12px; width: 100%; background: #1c1917; color: white; border: 0; padding: 14px; font-size: 16px; }
        button.light { background: #fff; color: #1c1917; border: 1px solid #1c1917; }
        .ok { background: #ecfccb; color: #3f6212; padding: 10px; margin-bottom: 12px; }
        .muted { color: #78716c; font-size: 13px; }
        h1 { font-size: 22px; margin: 4px 0 8px; }
    </style>
</head>
<body>
<main>
    <p class="muted">Nicon Vloeren</p>
    <div class="card">
        <p class="muted">{{ $snag->project->name }}</p>
        <h1>Opleverpunt #{{ $snag->number }}</h1>
        @if ($snag->project->nawLine())
            <p>
                {{ $snag->project->nawLine() }}
                @if ($snag->project->googleMapsUrl())
                    · <a href="{{ $snag->project->googleMapsUrl() }}" target="_blank" rel="noopener noreferrer">Navigeren</a>
                @endif
            </p>
        @endif
        <p><strong>Ruimte:</strong> {{ $snag->area?->label() ?: '—' }}</p>
        <p>{{ $snag->description }}</p>
        <p class="muted">Gereed uiterlijk: {{ $snag->due_date?->format('d-m-Y') ?: '—' }} · {{ $snag->status->label() }}</p>

        @if ($snag->issuePhotos()->isNotEmpty())
            <p class="muted">Constatering</p>
            <div class="thumbs">
                @foreach ($snag->issuePhotos() as $photo)
                    <img src="{{ $snag->publicPhotoUrl($photo) }}" alt="Constatering">
                @endforeach
            </div>
        @endif

        @if ($snag->completionPhotos()->isNotEmpty())
            <p class="muted">Gereedfoto</p>
            <div class="thumbs">
                @foreach ($snag->completionPhotos() as $photo)
                    <img src="{{ $snag->publicPhotoUrl($photo) }}" alt="Gereedfoto">
                @endforeach
            </div>
        @endif

        @if (session('status'))
            <p class="ok">{{ session('status') }}</p>
        @endif

        @if ($snag->publicAccessAllowsChanges())
            @if ($snag->status !== \App\Enums\SnagStatus::InProgress)
                <form method="POST" action="{{ route('snags.public.progress', $snag->public_token) }}">
                    @csrf
                    <button class="light" type="submit">In behandeling</button>
                </form>
            @endif
            <form method="POST" action="{{ route('snags.public.comment', $snag->public_token) }}">
                @csrf
                <label>Opmerking
                    <textarea name="note" rows="3" required></textarea>
                </label>
                <button class="light" type="submit">Opmerking plaatsen</button>
            </form>
            <form method="POST" action="{{ route('snags.public.complete', $snag->public_token) }}" enctype="multipart/form-data">
                @csrf
                <label>Opmerking bij gereedmelden
                    <textarea name="note" rows="2"></textarea>
                </label>
                <label>Gereedfoto maken of kiezen
                    <input type="file" name="photos[]" accept="image/*" capture="environment" multiple>
                </label>
                <button>Gereed melden</button>
            </form>
        @else
            <p class="ok">Dit punt is {{ $snag->status->label() }}.</p>
        @endif
    </div>
</main>
</body>
</html>
