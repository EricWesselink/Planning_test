@extends('layouts.app')

@section('title', 'Weekplanning personeel · Nicon Planning')

@section('content')
    <div class="personnel-week-page">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Planning</div>
                <h1 class="text-2xl font-semibold">Weekplanning personeel</h1>
                <p class="text-sm text-nicon-muted">{{ $weekLabel }} · {{ $weekRange }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <a class="planning-btn" href="{{ route('planning', ['week' => $weekStart->toDateString()]) }}">Planbord</a>
                <a class="planning-btn" href="{{ route('planning.personnel-week', ['week' => $prevWeek]) }}" aria-label="Vorige week">‹ Vorige week</a>
                <span class="px-2 font-medium">{{ $weekLabel }}</span>
                <a class="planning-btn" href="{{ route('planning.personnel-week', ['week' => $nextWeek]) }}" aria-label="Volgende week">Volgende week ›</a>
                <a
                    class="planning-btn planning-btn--accent"
                    href="{{ route('planning.personnel-week.pdf', ['week' => $weekStart->toDateString()]) }}"
                    target="_blank"
                    rel="noopener"
                >Weekplanning personeel PDF</a>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto border border-nicon-line bg-white">
            @include('planning.partials.personnel-week-board')
        </div>

        @if (($legend ?? []) !== [])
            <div class="personnel-week-legend">
                @foreach ($legend as $item)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="personnel-week-swatch" style="background: {{ $item['color'] }}"></span>
                        {{ $item['label'] }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>
@endsection
