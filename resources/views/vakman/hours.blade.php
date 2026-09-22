@extends('layouts.app')

@section('title', 'Mijn uren · Nicon Planning')

@section('content')
    <div class="mx-auto grid w-full min-w-0 max-w-3xl gap-3">
        <div>
            <h1 class="text-xl font-semibold">Mijn uren</h1>
            <p class="text-sm text-nicon-muted">Wat je hebt ingediend en wat is goedgekeurd.</p>
        </div>

        <div class="flex min-w-0 items-center justify-between gap-2">
            <a href="{{ $overview['prev_url'] }}" class="shrink-0 border border-nicon-line bg-white px-3 py-2 text-lg leading-none" aria-label="Vorige week">‹</a>
            <div class="min-w-0 text-center">
                <div class="font-semibold">Week {{ $overview['number'] }}</div>
                <div class="text-sm text-nicon-muted">{{ $overview['period'] }}</div>
            </div>
            <a href="{{ $overview['next_url'] }}" class="shrink-0 border border-nicon-line bg-white px-3 py-2 text-lg leading-none" aria-label="Volgende week">›</a>
        </div>

        <p class="text-sm font-semibold leading-snug">
            {{ $overview['submitted_label'] }} ingediend · {{ $overview['approved_label'] }} goedgekeurd · {{ $overview['pending_label'] }} te beoordelen
        </p>

        @if ($overview['days'] === [])
            <p class="border border-nicon-line bg-white px-3 py-2 text-sm text-nicon-muted">Deze week zijn er geen uren ingediend.</p>
        @else
            <div class="divide-y divide-nicon-line border border-nicon-line bg-white">
                @foreach ($overview['days'] as $day)
                    @foreach ($day['entries'] as $entry)
                        <details class="mijn-uren-row">
                            <summary class="grid cursor-pointer list-none grid-cols-[3.15rem_minmax(0,1fr)_auto_auto_auto] items-center gap-x-2 px-2 py-1.5 text-xs sm:px-3 sm:py-2 sm:text-sm [&::-webkit-details-marker]:hidden">
                                <span class="font-semibold">{{ $day['day_label'] }}</span>
                                <span class="min-w-0 truncate" title="{{ $entry['project'] }}">{{ $entry['project'] }}</span>
                                <span class="shrink-0 tabular-nums text-nicon-muted">{{ $entry['time'] }}</span>
                                <span class="shrink-0 text-right font-semibold tabular-nums">{{ $entry['hours'] }}</span>
                                <span @class([
                                    'shrink-0 whitespace-nowrap rounded px-1.5 py-0.5 text-center text-[11px] font-semibold leading-none sm:text-xs',
                                    'text-nicon-ok' => $entry['badge_tone'] === 'approved',
                                    'text-nicon-warn' => in_array($entry['badge_tone'], ['adjusted', 'open'], true),
                                    'text-nicon-danger' => $entry['badge_tone'] === 'rejected',
                                ])>{{ $entry['badge'] }}</span>
                            </summary>
                            <div class="grid gap-1 border-t border-nicon-line px-2 py-2 text-sm sm:grid-cols-2 sm:px-3">
                                <p>Projectnummer: {{ $entry['project_code'] }}</p>
                                <p>Werknummer: {{ $entry['work_number'] }}</p>
                                <p>Werkzaamheid: {{ $entry['work'] }}</p>
                                <p>Pauze: {{ $entry['break'] }}</p>
                                <p>Ingediend: {{ $entry['submitted'] }}</p>
                                <p>Goedgekeurd: {{ $entry['approved'] }}</p>
                                <p>Verschil: {{ $entry['difference'] }}</p>
                                <p>Status: {{ $entry['status'] }}</p>
                                @if ($entry['reason'])
                                    <p class="sm:col-span-2">Reden: {{ $entry['reason'] }}</p>
                                @endif
                            </div>
                        </details>
                    @endforeach
                @endforeach
                <p class="px-2 py-2 text-right text-sm font-semibold sm:px-3">Totaal deze week: {{ $overview['total_label'] }}</p>
            </div>
        @endif
    </div>
@endsection
