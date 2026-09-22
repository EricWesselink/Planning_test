@extends('layouts.app')

@section('title', 'Mijn uren · Nicon Planning')

@section('content')
    <div class="mx-auto grid w-full min-w-0 max-w-lg gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Mijn uren</h1>
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

        <section class="grid gap-1 border border-nicon-line bg-white p-4 text-sm">
            <p>Totaal ingediend deze week: {{ $overview['submitted_label'] }}</p>
            <p>Totaal goedgekeurd deze week: {{ $overview['approved_label'] }}</p>
            <p class="font-semibold">Ingediend: {{ $overview['submitted_label'] }}</p>
            <p class="font-semibold">Goedgekeurd: {{ $overview['approved_label'] }}</p>
            <p class="font-semibold">Verschil: {{ $overview['difference_label'] }}</p>
        </section>

        @forelse ($overview['days'] as $day)
            <section class="grid min-w-0 gap-3 border border-nicon-line bg-nicon-paper p-4">
                <h2 class="text-base font-semibold">{{ $day['heading'] }}</h2>
                @foreach ($day['entries'] as $entry)
                    <article class="grid min-w-0 gap-1 text-sm {{ $loop->first ? '' : 'border-t border-nicon-line pt-3' }}">
                        <h3 class="font-semibold">{{ $entry['project'] }}</h3>
                        @foreach ($entry['lines'] as $line)
                            <p class="min-w-0 break-words">{{ $line }}</p>
                        @endforeach
                        @if ($entry['reason'])
                            <p class="min-w-0 break-words">Reden: {{ $entry['reason'] }}</p>
                        @endif
                        <p>Status: {{ $entry['status'] }}</p>
                    </article>
                @endforeach
            </section>
        @empty
            <p class="border border-nicon-line bg-white px-4 py-3 text-sm text-nicon-muted">Deze week zijn er geen uren ingediend.</p>
        @endforelse
    </div>
@endsection
