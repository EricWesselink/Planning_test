@extends('layouts.app')

@section('title', 'Dashboard · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Overzicht</div>
            <h1 class="text-2xl font-semibold">Wat speelt er?</h1>
            <p class="text-sm text-nicon-muted">{{ $today->translatedFormat('l j F Y') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('production.index') }}" class="border border-nicon-line bg-white px-4 py-2 text-sm">Productie</a>
            <a href="{{ route('planning') }}" class="bg-nicon-orange text-white px-4 py-2 text-sm">Planning</a>
        </div>
    </div>

    <section class="mt-6 border border-nicon-line bg-white px-4 py-3">
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Werkoverzicht</div>
        <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
            <strong>{{ $overview['work_count'] === 1 ? '1 werk' : $overview['work_count'].' werken' }}</strong>
            <span class="text-nicon-muted" aria-hidden="true">|</span>
            <strong>Egaliseren {{ \App\Support\Format::qty(round($overview['egaliseren_m2'])) }} m²</strong>
            <span class="text-nicon-muted" aria-hidden="true">|</span>
            <strong>Materiaal leggen {{ \App\Support\Format::qty(round($overview['laying_m2'])) }} m²</strong>
            <span class="text-nicon-muted" aria-hidden="true">|</span>
            <strong>Deze week {{ \App\Support\Format::qty(round($overview['planned_week_m2'])) }} m² gepland</strong>
        </div>
        <p class="mt-1 text-[11px] text-nicon-muted">
            Nog niet bemand: {{ $overview['unmanned_count'] }} · Lopend: {{ $overview['running_count'] }} · Vandaag: {{ $overview['today_count'] }}
        </p>
    </section>

    <div class="mt-4 grid gap-6 lg:grid-cols-3">
        <section class="border border-nicon-line bg-white">
            <h2 class="bg-nicon-ink px-4 py-3 text-white font-semibold">Vandaag</h2>
            <div class="divide-y">
                @forelse ($todayBlocks as $block)
                    <a href="{{ route('projects.show', $block['project']) }}" class="block px-4 py-3 hover:bg-nicon-sand">
                        @if ($block['project']->isWinkel())
                            <div class="text-[11px] font-semibold tracking-[0.14em]">WINKEL</div>
                            <div class="font-medium">{{ $block['project']->displayTitle() }}</div>
                            @if ($block['project']->shopWorkLine())
                                <div class="text-sm text-nicon-muted">{{ $block['project']->shopWorkLine() }}</div>
                            @endif
                        @else
                            <div class="font-medium whitespace-nowrap">{{ $block['project']->labeledNumbersLine() }}</div>
                            <div class="text-sm">{{ $block['project']->displayTitle() }}</div>
                        @endif
                        <div class="text-sm text-nicon-muted">{{ $block['project']->city }}</div>
                        <div class="mt-1 text-sm">{{ $block['people']->join(', ') }}</div>
                        <div class="text-xs text-nicon-muted">{{ $block['work']->join(', ') }}</div>
                    </a>
                @empty
                    <p class="px-4 py-3 text-sm text-nicon-muted">Niemand ingepland.</p>
                @endforelse
            </div>
        </section>

        <section class="border border-nicon-line bg-white">
            <h2 class="bg-nicon-ink px-4 py-3 text-white font-semibold">Lopende werken</h2>
            <div class="divide-y">
                @forelse ($running as $project)
                    @php
                        $head = $project->headlineWorkItem();
                        $remaining = $project->remainingDaysLabel($today);
                    @endphp
                    <a href="{{ route('projects.show', $project) }}" class="block px-4 py-3 hover:bg-nicon-sand">
                        @if ($project->isWinkel())
                            <div class="text-[11px] font-semibold tracking-[0.14em]">WINKEL</div>
                        @endif
                        <div class="font-medium">{{ $project->name }}</div>
                        <div class="text-sm text-nicon-muted">
                            Eind {{ $project->planned_end_date?->format('d-m') }}
                            @if ($remaining)
                                · {{ $remaining }}
                            @endif
                        </div>
                        @if ($head && ! $project->isWinkel())
                            <div class="mt-1 text-sm">{{ $head->name }} {{ \App\Support\Format::qty($head->completedQuantity()) }} / {{ \App\Support\Format::qty($head->ordered_quantity) }} {{ $head->unit->label() }}</div>
                        @elseif ($project->isWinkel() && $project->shopWorkLine())
                            <div class="mt-1 text-sm text-nicon-muted">{{ $project->shopWorkLine() }}</div>
                        @endif
                        @if ($project->isBehind())
                            <div class="text-xs text-nicon-danger">Achter op schema</div>
                        @endif
                    </a>
                @empty
                    <p class="px-4 py-3 text-sm text-nicon-muted">Geen lopende werken.</p>
                @endforelse
            </div>
        </section>

        <section class="border border-nicon-line bg-white">
            <h2 class="bg-nicon-ink px-4 py-3 text-white font-semibold">Nieuwe werken</h2>
            <div class="divide-y">
                @forelse ($upcoming as $project)
                    @php
                        $manned = $project->assignments->isNotEmpty() || $project->workOrders->isNotEmpty();
                        $daysLeft = $project->daysUntilKlaar($today);
                        $remaining = $project->remainingDaysLabel($today);
                    @endphp
                    <a href="{{ route('planning', $project->planningBoardQuery()) }}" class="block px-4 py-3 hover:bg-nicon-sand">
                        @if ($project->isWinkel())
                            <div class="text-[11px] font-semibold tracking-[0.14em]">WINKEL</div>
                        @endif
                        <div class="font-medium">{{ $project->name }}</div>
                        <div class="text-sm text-nicon-muted">
                            Start {{ $project->planned_start_date?->format('d-m') }}
                            @if ($project->planned_end_date)
                                · Klaar {{ $project->planned_end_date->format('d-m') }}
                            @endif
                            @if ($project->city)
                                · {{ $project->city }}
                            @endif
                        </div>
                        @if ($project->isWinkel() && $project->shopWorkLine())
                            <div class="text-sm text-nicon-muted">{{ $project->shopWorkLine() }}</div>
                        @endif
                        @if ($remaining)
                            <div @class(['text-sm', 'text-nicon-danger' => $daysLeft !== null && $daysLeft < 0, 'text-nicon-muted' => $daysLeft === null || $daysLeft >= 0])>
                                {{ $remaining }}
                            </div>
                        @endif
                        <div class="mt-1 text-sm {{ $manned ? 'text-nicon-ok' : 'text-nicon-danger' }}">
                            {{ $manned ? 'Bemand' : 'Nog niet bemand' }}
                        </div>
                    </a>
                @empty
                    <p class="px-4 py-3 text-sm text-nicon-muted">Geen nieuwe werken.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
