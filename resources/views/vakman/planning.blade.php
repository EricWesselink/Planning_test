@extends('layouts.app')

@section('title', 'Mijn planning · Nicon Planning')

@section('main_class', 'p-3 sm:p-6')

@section('content')
    @php
        $view = $agenda['view'];
        $weekQuery = ['view' => 'week', 'week' => $agenda['weekStart']->toDateString()];
        $monthQuery = ['view' => 'month', 'month' => $agenda['monthStart']->format('Y-m')];
    @endphp
    <div class="mx-auto max-w-lg">
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Vakman</div>
        <h1 class="text-2xl font-semibold">Mijn planning</h1>
        <p class="text-sm text-nicon-muted">{{ $worker?->planName() ?? auth()->user()?->name }}</p>

        <div class="mt-4 flex rounded border border-nicon-line bg-white p-1 text-sm font-medium">
            <a href="{{ route('vakman.planning', $weekQuery) }}" class="flex-1 rounded px-3 py-2.5 text-center {{ $view === 'week' ? 'bg-nicon-ink text-white' : 'text-nicon-steel' }}">Week</a>
            <a href="{{ route('vakman.planning', $monthQuery) }}" class="flex-1 rounded px-3 py-2.5 text-center {{ $view === 'month' ? 'bg-nicon-ink text-white' : 'text-nicon-steel' }}">Maand</a>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <a href="{{ $agenda['prevUrl'] }}" class="flex h-11 w-11 items-center justify-center border border-nicon-line bg-white text-lg" aria-label="Vorige">‹</a>
            <div class="min-w-0 flex-1 text-center text-sm font-medium leading-tight">{{ $agenda['periodLabel'] }}</div>
            <a href="{{ $agenda['nextUrl'] }}" class="flex h-11 w-11 items-center justify-center border border-nicon-line bg-white text-lg" aria-label="Volgende">›</a>
        </div>
        <div class="mt-2 text-center">
            <a href="{{ $agenda['todayUrl'] }}" class="text-sm text-nicon-orange">Vandaag</a>
        </div>

        @if (! $agenda['hasJobs'])
            <p class="mt-4 border border-nicon-line bg-white px-4 py-4 text-sm text-nicon-muted">
                {{ $view === 'month' ? 'Je staat deze maand niet ingepland.' : 'Je staat deze week niet ingepland.' }}
            </p>
        @endif

        @if ($view === 'month')
            <div class="mt-4 grid grid-cols-7 gap-1 text-center text-[11px] uppercase tracking-wide text-nicon-muted">
                <span>Ma</span><span>Di</span><span>Wo</span><span>Do</span><span>Vr</span><span>Za</span><span>Zo</span>
            </div>
            <div class="mt-1 grid grid-cols-7 gap-1">
                @foreach ($agenda['days'] as $day)
                    @if ($day['url'])
                        <a href="{{ $day['url'] }}" class="min-h-16 border border-nicon-line bg-white p-1 text-left {{ $day['in_month'] ? '' : 'opacity-40' }} {{ $day['is_today'] ? 'ring-2 ring-nicon-orange' : '' }}">
                            <div class="text-xs font-semibold">{{ $day['short'] }}</div>
                            @foreach (array_slice($day['jobs'], 0, 2) as $job)
                                <div class="mt-0.5 truncate text-[10px] leading-tight text-nicon-steel">{{ $job['project_name'] }}</div>
                            @endforeach
                        </a>
                    @else
                        <div class="min-h-16 border border-nicon-line bg-white p-1 text-left {{ $day['in_month'] ? '' : 'opacity-40' }} {{ $day['is_today'] ? 'ring-2 ring-nicon-orange' : '' }}">
                            <div class="text-xs font-semibold">{{ $day['short'] }}</div>
                        </div>
                    @endif
                @endforeach
            </div>
        @else
            <div class="mt-4 space-y-5">
                @foreach ($agenda['days'] as $day)
                    <section>
                        <h2 class="text-base font-semibold {{ $day['is_today'] ? 'text-nicon-orange' : '' }}">{{ $day['heading'] }}</h2>
                        @forelse ($day['jobs'] as $job)
                            <a href="{{ $job['url'] }}" class="mt-2 block border border-nicon-line bg-white px-4 py-3">
                                <div class="text-base font-semibold leading-snug">{{ $job['project_name'] }}</div>
                                @if ($job['city'] !== '')
                                    <div class="mt-1 text-sm text-nicon-steel">{{ $job['city'] }}</div>
                                @endif
                                <div class="mt-1 text-sm">{{ $job['time_label'] }}</div>
                                @if ($job['headline'] !== '')
                                    <div class="mt-1 text-sm">{{ $job['headline'] }}</div>
                                @endif
                                @if ($job['colleagues'] !== [])
                                    <div class="mt-1 text-sm text-nicon-muted">Samen met: {{ implode(', ', $job['colleagues']) }}</div>
                                @endif
                                <div class="mt-2 text-sm font-medium text-nicon-orange">Bekijk werk</div>
                            </a>
                        @empty
                            <p class="mt-2 text-sm text-nicon-muted">Niet ingepland</p>
                        @endforelse
                    </section>
                @endforeach
            </div>
        @endif
    </div>
@endsection
