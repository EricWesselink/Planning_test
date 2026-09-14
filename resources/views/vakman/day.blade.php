@extends('layouts.app')

@section('title', $detail['heading'].' · Nicon Planning')

@section('main_class', 'p-3 sm:p-6')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ $weekUrl }}" class="text-sm text-nicon-orange">← Weekoverzicht</a>
        <h1 class="mt-2 text-2xl font-semibold">{{ $detail['heading'] }}</h1>
        <p class="text-sm text-nicon-muted">{{ $worker?->planName() ?? auth()->user()?->name }}</p>

        @forelse ($detail['jobs'] as $job)
            @php
                $project = $job['project'];
            @endphp
            <article class="mt-4 border border-nicon-line bg-white">
                <div class="bg-nicon-ink px-4 py-3 text-white">
                    <h2 class="text-lg font-semibold leading-snug">{{ $job['project_name'] }}</h2>
                    @if ($job['numbers'] !== '')
                        <p class="mt-1 text-sm text-white/80">{{ $job['numbers'] }}</p>
                    @endif
                </div>
                <div class="space-y-3 px-4 py-4 text-sm">
                    <p class="text-base">{{ $job['time_label'] }}</p>
                    @if ($job['address'])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Adres</div>
                            @if ($job['maps_url'])
                                <a href="{{ $job['maps_url'] }}" class="text-nicon-orange" target="_blank" rel="noopener noreferrer">{{ $job['address'] }}</a>
                            @else
                                <p>{{ $job['address'] }}</p>
                            @endif
                        </div>
                    @elseif ($job['city'] !== '')
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Plaats</div>
                            <p>{{ $job['city'] }}</p>
                        </div>
                    @endif

                    @if ($job['works'] !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Werkzaamheden</div>
                            <ul class="mt-1 space-y-1">
                                @foreach ($job['works'] as $work)
                                    <li>{{ $work['title'] }} · {{ $work['quantity'] }} {{ $work['unit'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($job['floors'] !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Bouwlaag / verdieping</div>
                            <p>{{ implode(', ', $job['floors']) }}</p>
                        </div>
                    @endif

                    @if ($job['rooms'] !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Ruimtes</div>
                            <p>{{ implode(', ', $job['rooms']) }}</p>
                        </div>
                    @endif

                    @if ($job['colleagues'] !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Samen met</div>
                            <p>{{ implode(', ', $job['colleagues']) }}</p>
                        </div>
                    @endif

                    @if ($job['notes'] !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Opmerkingen</div>
                            @foreach ($job['notes'] as $note)
                                <p class="mt-1">{{ $note }}</p>
                            @endforeach
                        </div>
                    @endif

                    @if ($job['drawings'] !== [])
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Tekeningen</div>
                            <ul class="mt-1 space-y-1">
                                @foreach ($job['drawings'] as $drawing)
                                    <li>
                                        <a href="{{ route('projects.documents.show', [$project, $drawing]) }}" class="text-nicon-orange">{{ $drawing->original_filename ?: 'Tekening' }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
                <div class="flex flex-col gap-2 border-t border-nicon-line p-4">
                    <a href="{{ $job['project_url'] }}" class="bg-nicon-ink px-4 py-3 text-center text-sm font-medium text-white">Projectinformatie</a>
                    @forelse ($job['tickets'] as $ticket)
                        <a href="{{ route('work-tickets.show', $ticket) }}" class="bg-nicon-orange px-4 py-3 text-center text-sm font-medium text-white">{{ $ticket->kind->label() }} {{ $ticket->number }}</a>
                    @empty
                        @if ($job['werkbon_url'])
                            <a href="{{ $job['werkbon_url'] }}" class="bg-nicon-orange px-4 py-3 text-center text-sm font-medium text-white">Werkbon</a>
                        @endif
                        @if ($job['opdrachtbon_url'])
                            <a href="{{ $job['opdrachtbon_url'] }}" class="bg-nicon-orange px-4 py-3 text-center text-sm font-medium text-white">Opdrachtbon</a>
                        @endif
                    @endforelse
                </div>
            </article>
        @empty
            <p class="mt-6 border border-nicon-line bg-white px-4 py-6 text-sm text-nicon-muted">
                Je staat deze dag niet ingepland.
            </p>
        @endforelse
    </div>
@endsection
