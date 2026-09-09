@extends('layouts.app')

@section('title', 'Planning · Nicon Planning')
@section('main_class', 'p-0 min-h-0 overflow-hidden')

@push('scripts')
    @vite(['resources/js/planning.js'])
@endpush

@section('content')
    @php
        $query = array_filter($filters, fn ($value) => $value !== null && $value !== '');
    @endphp
    <div class="planning-page">
        <div class="planning-controls">
            <div class="planning-controls-row">
                <div class="planning-controls-title">
                    <div class="planning-eyebrow">Planbord</div>
                    <h1 class="planning-heading">Planning</h1>
                    <p class="planning-week-label">{{ $weekRangeLabel }} · {{ $weekStart->translatedFormat('d M') }} – {{ $days->last()->translatedFormat('d M Y') }}</p>
                </div>
                <div class="planning-toolbar">
                    <a class="planning-btn" href="{{ route('planning', array_merge($query, ['week' => $prevWeek])) }}">Vorige</a>
                    <form method="GET" class="inline">
                        @foreach ($query as $key => $value)
                            @if (! in_array($key, ['weeks', 'week'], true))
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
                        <select name="weeks" class="planning-btn planning-select" onchange="this.form.submit()">
                            @foreach ($weekOptions as $option)
                                <option value="{{ $option }}" @selected($weeks === $option)>{{ $option }} {{ $option === 1 ? 'week' : 'weken' }}</option>
                            @endforeach
                        </select>
                    </form>
                    <a class="planning-btn" href="{{ route('planning', array_merge($query, ['week' => $nextWeek])) }}">Volgende</a>
                    <form method="GET" class="planning-week-jump">
                        @foreach ($query as $key => $value)
                            @if (! in_array($key, ['week', 'weeks', 'week_nr', 'year'], true))
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <input type="hidden" name="weeks" value="{{ $weeks }}">
                        <input type="hidden" name="year" value="{{ $weekStart->isoWeekYear }}">
                        <label for="week-nr" class="planning-week-jump-label">Week</label>
                        <input id="week-nr" type="number" name="week_nr" min="1" max="53" required
                               value="{{ $weekStart->isoWeek() }}" inputmode="numeric"
                               class="planning-week-jump-input"
                               title="Spring naar weeknummer">
                        <button type="submit" class="planning-week-jump-submit">Toon</button>
                    </form>
                    <form method="GET" action="{{ route('planning.export') }}" target="_blank" class="planning-pdf-form">
                        @foreach ($query as $key => $value)
                            @if (! in_array($key, ['period', 'intern', 'week', 'week_nr', 'year'], true))
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <input type="hidden" name="week" value="{{ $filters['week'] ?? $weekStart->toDateString() }}">
                        <select name="period" class="planning-btn planning-select" aria-label="Periode voor PDF" title="Periode voor PDF">
                            <option value="week" @selected(($period ?? 'week') === 'week' || ($period ?? '') === '')>Week</option>
                            <option value="month" @selected(($period ?? '') === 'month')>Maand</option>
                            <option value="work" @selected(($period ?? '') === 'work')>Gehele werk</option>
                        </select>
                        <button type="submit" class="planning-btn" title="Planning als PDF voor de opdrachtgever, zonder namen">PDF</button>
                        <button type="submit" class="planning-btn" name="intern" value="1" title="Planning printen voor eigen gebruik, met namen">Intern</button>
                    </form>
                    <a class="planning-btn planning-btn--accent" href="{{ route('production.index') }}">Productie</a>
                </div>
            </div>

            <form method="GET" class="planning-filters">
                <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
                <input type="hidden" name="weeks" value="{{ $weeks }}">
                <select name="kind" class="planning-filter" onchange="this.form.submit()" aria-label="Soort werk">
                    <option value="" @selected(($filters['kind'] ?? '') === '')>Alle werken</option>
                    <option value="{{ \App\Enums\ProjectKind::Project->value }}" @selected(($filters['kind'] ?? '') === \App\Enums\ProjectKind::Project->value)>Projecten</option>
                    <option value="{{ \App\Enums\ProjectKind::Winkel->value }}" @selected(($filters['kind'] ?? '') === \App\Enums\ProjectKind::Winkel->value)>Winkelwerk</option>
                </select>
                <select name="project_id" class="planning-filter" onchange="this.form.submit()" aria-label="Werk">
                    <option value="">Alle</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected(($filters['project_id'] ?? '') == $project->id)>{{ $project->labeledNumbersLine() }} — {{ $project->displayTitle() }}</option>
                    @endforeach
                </select>
                @if ($canManagePlanning)
                    <select name="worker_id" class="planning-filter" onchange="this.form.submit()" aria-label="Vakman">
                        <option value="">Iedereen</option>
                        @foreach ($workers as $worker)
                            <option value="{{ $worker->id }}" @selected(($filters['worker_id'] ?? '') == $worker->id)>{{ $worker->planName() }}</option>
                        @endforeach
                    </select>
                @endif
                <select name="status" class="planning-filter" onchange="this.form.submit()" aria-label="Status">
                    <option value="">Status</option>
                    <option value="in_uitvoering" @selected(($filters['status'] ?? '') === 'in_uitvoering')>Lopend</option>
                    <option value="gepland" @selected(($filters['status'] ?? '') === 'gepland')>Nieuw</option>
                </select>
                @if ($canManagePlanning)
                    <select name="staffing" class="planning-filter" onchange="this.form.submit()" aria-label="Inplanning">
                        <option value="" @selected(($filters['staffing'] ?? '') === '')>Inplanning</option>
                        <option value="open" @selected(($filters['staffing'] ?? '') === 'open')>Nog niet ingepland</option>
                        <option value="planned" @selected(($filters['staffing'] ?? '') === 'planned')>Ingepland</option>
                    </select>
                @endif
                <a href="{{ route('planning', ['week' => $weekStart->toDateString(), 'weeks' => $weeks]) }}" class="planning-filter planning-filter--reset">Reset</a>
            </form>

            @if (count($warnings))
                <div class="planning-warnings" role="alert">
                    <div class="planning-warnings-title">Dubbele planning</div>
                    @foreach ($warnings as $warning)
                        <button
                            type="button"
                            class="planning-warnings-item"
                            data-focus-worker="{{ $warning['worker_id'] }}"
                            data-focus-date="{{ $warning['date'] ?? '' }}"
                            title="Ga naar deze planning om aan te passen"
                        >{{ $warning['message'] }}</button>
                    @endforeach
                </div>
            @endif

            @if ($canManagePlanning)
                @php
                    $manDayWeekFirst = (int) (data_get($weekBands->first(), 'number') ?? $weekStart->isoWeek());
                    $manDayWeekLast = (int) (data_get($weekBands->last(), 'number') ?? $manDayWeekFirst);
                    $manDayWeekNumber = $manDayWeekFirst === $manDayWeekLast
                        ? (string) $manDayWeekFirst
                        : $manDayWeekFirst.'–'.$manDayWeekLast;
                    $visibleTeamManDays = collect($teamManDays)->filter(
                        fn (array $team): bool => $team['remaining'] > 0.0001
                            && (($team['external'] ?? false) || ($team['people_count'] ?? 1) > 1)
                    );
                @endphp
                <div class="planning-available">
                    <div class="planning-available-head">
                        <span class="planning-available-title">Mandagen week <span class="planning-available-week-nr">{{ $manDayWeekNumber }}</span></span>
                        <span class="planning-available-total">Totaal vrij: <span class="planning-available-count">{{ \App\Support\PlanningHours::manDaysLabel($availableManDays) }}</span></span>
                    </div>
                    <div class="planning-available-teams">
                        @forelse ($visibleTeamManDays as $team)
                            @php
                                $plannedShare = $team['available'] > 0
                                    ? min(100, max(0, ($team['planned'] / $team['available']) * 100))
                                    : 0;
                            @endphp
                            <article class="planning-available-team" style="--chip-color: {{ $team['color'] }}" title="{{ $team['summary'] }}">
                                <div class="planning-available-team-row">
                                    <span class="planning-available-name">{{ $team['label'] }}</span>
                                    <span class="planning-available-ratio"><span class="planning-available-planned">{{ \App\Support\PlanningHours::manDaysLabel($team['planned']) }}</span> / {{ \App\Support\PlanningHours::manDaysLabel($team['available']) }}</span>
                                </div>
                                <div class="planning-available-team-row">
                                    <span class="planning-available-bar" aria-hidden="true">
                                        <span class="planning-available-bar-fill" style="width: {{ round($plannedShare, 2) }}%"></span>
                                    </span>
                                    <span class="planning-available-free">{{ \App\Support\PlanningHours::manDaysLabel($team['remaining']) }} vrij</span>
                                </div>
                            </article>
                        @empty
                            @if (count($teamManDays) === 0)
                                <span class="planning-available-empty">Geen actieve teams {{ $weeks === 1 ? 'deze week' : 'in deze weken' }}</span>
                            @endif
                        @endforelse
                    </div>
                </div>

                <p class="planning-hint">Sleep een balk horizontaal binnen de dag (snap op 2 uur) of naar een andere dag/onderdeel. Trek aan de zijkanten om 2–8 uur te maken. Klik op een tijdvak om iemand in te plannen.</p>
            @endif
        </div>

        <div class="planning-scroll-area" id="plan-scroller">
            <div class="plan-board" id="plan-board"
                 data-shift-url="{{ route('planning.shift') }}"
                 data-move-url="{{ route('planning.assignments.move') }}"
                 data-store-url="{{ route('planning.assignments.store') }}"
                 data-candidates-url="{{ route('planning.candidates') }}"
                 data-assignment-url="{{ url('/planning/assignments') }}"
                 data-readonly="{{ $canManagePlanning ? '0' : '1' }}"
                 data-crews='@json($workers->mapWithKeys(fn ($worker) => [$worker->id => $worker->crewPeople->map(fn ($person) => ['id' => $person->id, 'name' => $person->label()])->values()]))'
                 data-work-items='@json($projects->mapWithKeys(fn ($project) => [$project->id => $project->workItems->filter(fn ($item) => (float) $item->ordered_quantity > 0.0001 || $item->work_activity_id !== null)->map(fn ($item) => ['id' => $item->id, 'name' => $item->productLabel() ?: (\App\Support\WorkType::looksLikeRoom($item->name) ? $item->typeLabel() : $item->name), 'group' => $item->typeLabel()])->values()]))'
                 style="--plan-days: {{ $dayCount }}; --plan-day-min: {{ $dayMin }}px">
                <div class="plan-table">
                    <div class="plan-line plan-line--head sticky-head">
                        <div class="plan-frozen plan-frozen--head">
                            <div class="plan-week-gutter" aria-hidden="true"></div>
                            <div class="plan-cell">Werk</div>
                            <div class="plan-cell plan-cell--num">Opdracht</div>
                            <div class="plan-cell plan-cell--num">Gereed</div>
                            <div class="plan-cell plan-cell--num">Rest</div>
                            <div class="plan-cell plan-cell--num">%</div>
                        </div>
                        <div class="plan-days plan-days--head{{ $weeks > 1 ? ' plan-days--multi' : '' }}">
                            @foreach ($weekBands as $band)
                                <div class="plan-week {{ $loop->first ? '' : 'week-start' }}" style="grid-column: span {{ $band['span'] }}">
                                    <span>Week {{ $band['number'] }}</span>
                                    <span class="plan-week-month">{{ $band['month'] }}</span>
                                </div>
                            @endforeach
                            @foreach ($days as $day)
                                <div class="plan-day{{ $loop->first ? '' : ' day-start' }}{{ $day->isMonday() && ! $loop->first ? ' week-start' : '' }}{{ $day->isSaturday() ? ' is-saturday' : '' }}" data-date="{{ $day->toDateString() }}">
                                    <span class="plan-day-weekday">{{ $day->translatedFormat('l') }}</span>
                                    <span class="plan-day-date">{{ $day->translatedFormat('j F') }}</span>
                                    <span class="plan-day-times" aria-hidden="true">
                                        <span>08:00</span>
                                        <span>10:00</span>
                                        <span>12:00</span>
                                        <span>14:00</span>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @foreach ($rows as $projectRow)
                        @if (($projectRow['type'] ?? '') === 'section')
                            <div class="plan-line plan-line--section" role="separator" aria-label="{{ $projectRow['title'] }}">
                                <div class="plan-frozen">
                                    <div class="plan-cell plan-cell--werk plan-cell--section">{{ $projectRow['title'] }}</div>
                                    <div class="plan-cell plan-cell--num"></div>
                                    <div class="plan-cell plan-cell--num"></div>
                                    <div class="plan-cell plan-cell--num"></div>
                                    <div class="plan-cell plan-cell--num"></div>
                                </div>
                                <div class="plan-days plan-days--section" aria-hidden="true">
                                    @foreach ($days as $day)
                                        <div class="drop-day{{ $loop->first ? '' : ' day-start' }}{{ $day->isSaturday() ? ' is-saturday' : '' }}"></div>
                                    @endforeach
                                </div>
                            </div>
                            @continue
                        @endif
                        @php
                            $projectHasPeriod = $projectRow['bar'] || $projectRow['start_marker'] || $projectRow['end_marker'];
                            $projectBarOffset = $projectHasPeriod ? 16 : 4;
                            $projectHeight = max(28, $projectBarOffset + 4 + ($projectRow['bar_count'] * 24));
                        @endphp
                        <div class="plan-line plan-line--project" style="min-height: {{ $projectHeight }}px">
                            <div class="plan-frozen">
                                <div class="plan-cell plan-cell--werk{{ ! empty($projectRow['missing_craftsman']) ? ' has-missing-craftsman' : '' }}">
                                    <div class="plan-project-meta">
                                        <a href="{{ route('projects.show', $projectRow['id']) }}" class="hover:text-nicon-orange">
                                            @if (! empty($projectRow['badge']))
                                                <span class="plan-winkel-badge">{{ $projectRow['badge'] }}</span>
                                            @endif
                                            @if (! empty($projectRow['numbers_label']))
                                                <span class="block whitespace-nowrap">{{ $projectRow['numbers_label'] }}</span>
                                            @endif
                                            <span class="block">{{ $projectRow['title'] }}</span>
                                        </a>
                                        @if (! empty($projectRow['subtitle']))
                                            <div class="text-xs font-normal text-nicon-muted">{{ $projectRow['subtitle'] }}</div>
                                        @endif
                                        @if ($projectRow['naw_line'] ?? $projectRow['city'])
                                            <div class="text-xs font-normal text-nicon-muted">
                                                {{ $projectRow['naw_line'] ?? $projectRow['city'] }}
                                                @if (! empty($projectRow['maps_url']))
                                                    · <a href="{{ $projectRow['maps_url'] }}" target="_blank" rel="noopener noreferrer" class="text-nicon-orange-dark">Navigeren</a>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                    @if (! empty($projectRow['missing_craftsman']) && ! empty($projectRow['start_week']))
                                        <a
                                            href="{{ route('planning', array_merge($query, [
                                                'week' => $projectRow['start_week'],
                                                'project_id' => $projectRow['id'],
                                            ])) }}"
                                            class="plan-missing-craftsman"
                                            title="Ga naar startweek — geen vakman ingepland"
                                            aria-label="Ga naar startweek, geen vakman ingepland"
                                        >⚠</a>
                                    @elseif (! empty($projectRow['missing_craftsman']))
                                        <span class="plan-missing-craftsman" title="Geen vakman ingepland" aria-label="Geen vakman ingepland">⚠</span>
                                    @endif
                                </div>
                                @include('planning.partials.qty-cells', ['row' => $projectRow])
                            </div>
                            @include('planning.partials.plan-days', [
                                'projectId' => $projectRow['id'],
                                'workItemId' => null,
                                'personBars' => $projectRow['person_bars'],
                                'showPeriod' => true,
                                'periodBar' => $projectRow['bar'],
                                'startMarker' => $projectRow['start_marker'],
                                'endMarker' => $projectRow['end_marker'],
                                'personBarOffset' => $projectBarOffset,
                            ])
                        </div>
                        @foreach ($projectRow['children'] as $work)
                            @php $workHeight = max(28, 6 + ($work['bar_count'] * 24)); @endphp
                            <div class="plan-line plan-line--work{{ count($work['warnings']) ? ' plan-line--warn' : '' }}" style="min-height: {{ $workHeight }}px">
                                <div class="plan-frozen">
                                    <div class="plan-cell plan-cell--werk plan-cell--indent">
                                        <div>
                                            <div>{{ $work['title'] }}</div>
                                            @if (! empty($work['steps']))
                                                <div class="text-[10px] font-normal text-nicon-muted">{{ implode(' · ', $work['steps']) }}</div>
                                            @endif
                                            @if (count($work['warnings']))
                                                <div class="text-nicon-danger">{{ implode(', ', $work['warnings']) }}</div>
                                            @endif
                                        </div>
                                    </div>
                                    @include('planning.partials.qty-cells', ['row' => $work])
                                </div>
                                @include('planning.partials.plan-days', [
                                    'projectId' => $projectRow['id'],
                                    'workItemId' => $work['id'],
                                    'personBars' => $work['person_bars'],
                                    'showPeriod' => false,
                                    'periodBar' => null,
                                    'startMarker' => null,
                                    'endMarker' => null,
                                    'missingCraftsman' => false,
                                    'personBarOffset' => 4,
                                ])
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div id="plan-hours-hint" class="plan-hours-hint hidden" aria-hidden="true">10:00 - 12:00 · 2u</div>

    <dialog id="plan-dialog" class="plan-dialog">
        <form id="plan-form" class="space-y-2">
            <h2 id="plan-dialog-title" class="text-base font-semibold">Iemand inplannen</h2>
            <input type="hidden" name="project_id" id="plan-project-id">
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Wie</label>
            <select name="who" id="plan-who" required class="w-full border border-nicon-line px-2 py-1.5 bg-white">
                <option value="">Kies team</option>
                @foreach ($workers as $worker)
                    <option value="worker:{{ $worker->id }}" data-men="{{ $worker->peopleCount() }}">{{ $worker->planName() }}</option>
                @endforeach
            </select>
            <div id="plan-crew" class="hidden space-y-1 rounded border border-nicon-line bg-nicon-sand/40 px-2 py-1.5">
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Wie gaat er naartoe</div>
                <div id="plan-crew-list" class="space-y-0.5"></div>
            </div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Wat gaan ze doen</label>
            <select name="work_item_id" id="plan-work" required class="w-full border border-nicon-line px-2 py-1.5 bg-white"></select>
            <div id="plan-men-wrap">
                <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Aantal personen</label>
                <input type="number" name="people_count" id="plan-men" min="1" max="50" value="1" required class="mt-0 w-full border border-nicon-line px-2 py-1.5">
            </div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Uren</label>
            <select name="hours" id="plan-hours" class="w-full border border-nicon-line px-2 py-1.5 bg-white">
                <option value="8">Hele dag · 8u</option>
                <option value="6">6 uur</option>
                <option value="4">Halve dag · 4u</option>
                <option value="2">2 uur</option>
            </select>
            <div id="plan-slot-wrap" class="hidden">
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Tijdvak</div>
                <div id="plan-slot-list" class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-sm"></div>
            </div>
            <p id="plan-hours-summary" class="text-xs text-nicon-muted">08:00–16:00 · 8u</p>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Van</label>
                    <input type="date" name="start_date" id="plan-start" required class="w-full border border-nicon-line px-2 py-1.5">
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Tot</label>
                    <input type="date" name="end_date" id="plan-end" required class="w-full border border-nicon-line px-2 py-1.5">
                </div>
            </div>
            <div class="flex flex-wrap gap-2 pt-1">
                <button type="submit" class="bg-nicon-orange text-white px-4 py-1.5">Opslaan</button>
                <button type="button" id="plan-cancel" class="border border-nicon-line px-4 py-1.5 bg-white">Annuleren</button>
                <button type="button" id="plan-delete" class="text-nicon-danger px-4 py-1.5 hidden">Verwijderen</button>
            </div>
        </form>
    </dialog>
@endsection
