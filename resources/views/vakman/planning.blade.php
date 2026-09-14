@extends('layouts.app')

@section('title', 'Mijn planning · Nicon Planning')

@section('content')
    <div>
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Vakman</div>
        <h1 class="text-2xl font-semibold">Mijn planning</h1>
        <p class="text-sm text-nicon-muted">
            {{ $worker?->planName() ?? auth()->user()?->name }}
            · {{ $today->translatedFormat('l j F Y') }}
        </p>
    </div>

    <div class="mt-6 space-y-4">
        @forelse ($entries as $entry)
            @php
                $project = $entry['project'];
                $assignment = $entry['assignment'];
            @endphp
            <article class="border border-nicon-line bg-white">
                <div class="bg-nicon-ink px-4 py-3 text-white">
                    <h2 class="font-semibold">{{ $project->displayTitle() }}</h2>
                    @if ($project->labeledNumbersLine() !== '')
                        <p class="text-sm text-white/80">{{ $project->labeledNumbersLine() }}</p>
                    @endif
                </div>
                <div class="space-y-2 px-4 py-3 text-sm">
                    <p>{{ $assignment->dateRangeLabel() }} · {{ $assignment->timeRangeLabel() }}</p>
                    @if ($entry['address'])
                        <p>
                            @if ($entry['maps_url'])
                                <a href="{{ $entry['maps_url'] }}" class="text-nicon-orange hover:underline" target="_blank" rel="noopener noreferrer">{{ $entry['address'] }}</a>
                            @else
                                {{ $entry['address'] }}
                            @endif
                        </p>
                    @elseif ($project->city)
                        <p>{{ $project->city }}</p>
                    @endif
                    @if ($entry['work'] !== [])
                        <p>
                            <span class="text-xs uppercase tracking-wide text-nicon-muted">Werkzaamheden</span><br>
                            {{ implode(', ', $entry['work']) }}
                        </p>
                    @endif
                    @if ($entry['colleagues'] !== [])
                        <p>
                            <span class="text-xs uppercase tracking-wide text-nicon-muted">Samen met</span><br>
                            {{ implode(', ', $entry['colleagues']) }}
                        </p>
                    @endif
                    <p>
                        <a href="{{ route('projects.show', $project) }}" class="text-nicon-orange hover:underline">Projectinformatie</a>
                    </p>
                </div>
            </article>
        @empty
            <p class="border border-nicon-line bg-white px-4 py-6 text-sm text-nicon-muted">
                Je staat de komende weken niet ingepland.
            </p>
        @endforelse
    </div>
@endsection
