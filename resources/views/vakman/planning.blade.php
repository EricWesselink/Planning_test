@extends('layouts.app')

@section('title', 'Mijn planning · Nicon Planning')

@section('main_class', 'p-0 overflow-hidden')

@section('content')
    @php
        $view = $agenda['view'];
        $weekQuery = ['view' => 'week', 'week' => $agenda['weekStart']->toDateString()];
        $monthQuery = ['view' => 'month', 'month' => $agenda['monthStart']->format('Y-m')];
        $monthWeeks = $view === 'month' ? $agenda['days']->chunk(7) : collect();
        $weekEnd = $agenda['weekStart']->copy()->addDays(5);
        $requestedDay = request()->query('day');
        $selectedDay = (is_string($requestedDay) ? $agenda['days']->firstWhere('key', $requestedDay) : null)
            ?? $agenda['days']->firstWhere('is_today')
            ?? $agenda['days']->first(fn (array $day): bool => $day['jobs'] !== [])
            ?? $agenda['days']->first();
        $selectedDayKey = is_array($selectedDay) ? ($selectedDay['key'] ?? '') : '';
    @endphp
    <div class="vakman-agenda{{ $view === 'week' ? ' vakman-agenda--split' : '' }}">
        <div class="vakman-agenda-toolbar">
            <div class="vakman-agenda-title">
                <div class="vakman-agenda-kicker">Vakman</div>
                <h1 class="text-xl font-semibold leading-tight sm:text-2xl">Mijn planning</h1>
                <p class="text-sm text-nicon-muted">{{ auth()->user()?->name ?? $worker?->displayName() }}</p>
            </div>

            <div class="vakman-agenda-nav">
                <a href="{{ $agenda['prevUrl'] }}" aria-label="Vorige">‹</a>
                <div class="vakman-agenda-period">
                    @if ($view === 'week')
                        <span class="vakman-agenda-weeknr">Week {{ $agenda['weekStart']->isoWeek() }}</span>
                        <span class="vakman-agenda-dates">{{ $agenda['weekStart']->translatedFormat('j M') }} – {{ $weekEnd->translatedFormat('j M Y') }}</span>
                    @else
                        {{ $agenda['periodLabel'] }}
                    @endif
                </div>
                <a href="{{ $agenda['nextUrl'] }}" aria-label="Volgende">›</a>
            </div>

            <div class="vakman-agenda-switch">
                <a href="{{ route('vakman.planning', $weekQuery) }}" class="{{ $view === 'week' ? 'is-active' : '' }}">Week</a>
                <a href="{{ route('vakman.planning', $monthQuery) }}" class="{{ $view === 'month' ? 'is-active' : '' }}">Maand</a>
            </div>
        </div>
        @can('create', \App\Models\LeaveRequest::class)
            <div class="vakman-agenda-leave-row">
                <a href="{{ route('vakman.leave-requests.index') }}" class="vakman-agenda-leave inline-flex bg-nicon-orange px-4 py-2 text-sm font-medium text-white">Vrij aanvragen</a>
            </div>
        @endcan

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
            <div class="vakman-week-split">
                <nav class="vakman-day-strip" aria-label="Dagen">
                    @foreach ($agenda['days'] as $day)
                        @php
                            $isSelected = $day['key'] === $selectedDayKey;
                        @endphp
                        <button
                            type="button"
                            class="vakman-day-strip-btn{{ $isSelected ? ' is-selected' : '' }}{{ $day['is_today'] ? ' is-today' : '' }}{{ $day['jobs'] !== [] ? ' has-jobs' : '' }}"
                            data-day-target="{{ $day['key'] }}"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                        >
                            <span class="vakman-day-strip-name">{{ str_replace('.', '', mb_strtoupper($day['weekday'])) }}</span>
                            <span class="vakman-day-strip-num">{{ $day['short'] }}</span>
                        </button>
                    @endforeach
                </nav>
                @include('vakman._week-board')
                <div class="vakman-week-details">
                    <h2 class="vakman-week-pane-title">Details</h2>
                    <div class="vakman-week">
                        @foreach ($agenda['days'] as $day)
                            <section
                                class="vakman-week-day{{ $day['is_today'] ? ' is-today' : '' }}{{ $day['key'] === $selectedDayKey ? ' is-active' : '' }}"
                                id="vakman-day-{{ $day['key'] }}"
                                data-day-key="{{ $day['key'] }}"
                            >
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
                                        @if (! empty($day['absence']))
                                            <p class="vakman-week-empty">{{ $day['absence']['label'] }}</p>
                                        @else
                                            <p class="vakman-week-empty">Vrij</p>
                                        @endif
                                    @endforelse
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const dayButtons = document.querySelectorAll('[data-day-target]');
            const jobButtons = document.querySelectorAll('[data-job-target]');

            const selectDay = (key) => {
                document.querySelectorAll('.vakman-week-day').forEach((el) => {
                    el.classList.toggle('is-active', el.getAttribute('data-day-key') === key);
                });
                dayButtons.forEach((button) => {
                    const selected = button.getAttribute('data-day-target') === key;
                    button.classList.toggle('is-selected', selected);
                    button.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
            };

            dayButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    selectDay(button.getAttribute('data-day-target') ?? '');
                });
            });

            if (jobButtons.length === 0) {
                return;
            }

            const selectJob = (id, source) => {
                document.querySelectorAll('.vakman-job-card.is-selected, .vakman-week-job.is-selected').forEach((el) => {
                    el.classList.remove('is-selected');
                });
                const chip = source instanceof Element ? source.closest('.vakman-week-job') : null;
                chip?.classList.add('is-selected');
                const card = document.getElementById(id);
                if (! (card instanceof HTMLElement)) {
                    return;
                }
                card.classList.add('is-selected');
                const day = card.closest('.vakman-week-day');
                if (day instanceof HTMLElement) {
                    selectDay(day.getAttribute('data-day-key') ?? '');
                }
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            };

            jobButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    selectJob(button.getAttribute('data-job-target') ?? '', button);
                });
            });
        });
    </script>
@endpush
