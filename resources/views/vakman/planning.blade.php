@extends('layouts.app')

@section('title', 'Mijn planning · Nicon Planning')

@section('main_class', 'p-0 overflow-hidden')

@section('content')
    @php
        $view = $agenda['view'];
        $weekQuery = ['view' => 'week', 'week' => $agenda['weekStart']->toDateString()];
        $monthQuery = ['view' => 'month', 'month' => $agenda['monthStart']->format('Y-m')];
        $monthWeeks = $view === 'month' ? $agenda['days']->chunk(7) : collect();
    @endphp
    <div class="vakman-agenda">
        <div class="vakman-agenda-toolbar">
            <div class="vakman-agenda-title">
                <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Vakman</div>
                <h1 class="text-xl font-semibold leading-tight sm:text-2xl">Mijn planning</h1>
                <p class="text-sm text-nicon-muted">{{ auth()->user()?->name ?? $worker?->displayName() }}</p>
            </div>

            <div class="vakman-agenda-nav">
                <a href="{{ $agenda['prevUrl'] }}" aria-label="Vorige">‹</a>
                <div class="vakman-agenda-period">{{ $agenda['periodLabel'] }}</div>
                <a href="{{ $agenda['nextUrl'] }}" aria-label="Volgende">›</a>
            </div>

            <div class="vakman-agenda-switch">
                <a href="{{ route('vakman.planning', $weekQuery) }}" class="{{ $view === 'week' ? 'is-active' : '' }}">Week</a>
                <a href="{{ route('vakman.planning', $monthQuery) }}" class="{{ $view === 'month' ? 'is-active' : '' }}">Maand</a>
            </div>
        </div>

        @if (! $agenda['hasJobs'])
            <p class="border border-nicon-line bg-white px-4 py-3 text-sm text-nicon-muted">
                {{ $view === 'month' ? 'Je staat deze maand niet ingepland.' : 'Je staat deze week niet ingepland.' }}
            </p>
        @endif

        @if ($view === 'month')
            <div class="vakman-month">
                <div class="vakman-month-grid">
                    <div class="vakman-month-head">Wk</div>
                    <div class="vakman-month-head">Ma</div>
                    <div class="vakman-month-head">Di</div>
                    <div class="vakman-month-head">Wo</div>
                    <div class="vakman-month-head">Do</div>
                    <div class="vakman-month-head">Vr</div>
                    <div class="vakman-month-head">Za</div>
                    <div class="vakman-month-head">Zo</div>
                    @foreach ($monthWeeks as $week)
                        <div class="vakman-month-weeknr">{{ $week->first()['date']->isoWeek() }}</div>
                        @foreach ($week as $day)
                            @php
                                $classes = 'vakman-month-day';
                                if (! $day['in_month']) {
                                    $classes .= ' is-outside';
                                }
                                if ($day['is_today']) {
                                    $classes .= ' is-today';
                                }
                            @endphp
                            @if ($day['url'])
                                <a href="{{ $day['url'] }}" class="{{ $classes }}">
                                    <div class="vakman-month-day-num">{{ $day['short'] }}</div>
                                    @foreach ($day['jobs'] as $job)
                                        <div class="vakman-month-chip">{{ $job['project_name'] }}</div>
                                    @endforeach
                                </a>
                            @else
                                <div class="{{ $classes }}">
                                    <div class="vakman-month-day-num">{{ $day['short'] }}</div>
                                </div>
                            @endif
                        @endforeach
                    @endforeach
                </div>
            </div>
        @else
            <div class="vakman-week">
                @foreach ($agenda['days'] as $day)
                    <section class="vakman-week-day {{ $day['is_today'] ? 'is-today' : '' }}">
                        <header class="vakman-week-day-head">
                            <span class="vakman-week-dayname">{{ $day['heading'] }}</span>
                            @if ($day['is_today'])
                                <span class="vakman-week-today-mark">Vandaag</span>
                            @endif
                        </header>
                        <div class="vakman-week-day-body">
                            @forelse ($day['jobs'] as $job)
                                @include('vakman._agenda-card', ['job' => $job])
                            @empty
                                <p class="vakman-week-empty">Vrij</p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
@endsection
