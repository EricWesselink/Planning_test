@extends('layouts.app')

@section('title', 'Planning · Nicon Planning')
@section('main_class', 'p-0 min-h-0 overflow-hidden')

@push('scripts')
    @vite(['resources/js/planning.js'])
@endpush

@section('content')
    @php
        $query = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $excelYear = (int) $weekStart->isoWeekYear();
    @endphp
    <div class="planning-page">
        <div class="planning-mobile-bar">
            <a class="planning-mobile-btn" href="{{ route('planning', array_merge($query, ['week' => $prevWeek])) }}" title="Vorige week" aria-label="Vorige week">‹</a>
            <span class="planning-mobile-week">Week {{ $weekStart->isoWeek() }}</span>
            <a class="planning-mobile-btn" href="{{ route('planning', array_merge($query, ['week' => $nextWeek])) }}" title="Volgende week" aria-label="Volgende week">›</a>
            <button type="button" class="planning-mobile-btn" id="planning-mobile-filters" aria-expanded="false" aria-controls="planning-filters">Filters</button>
            @if ($canManagePlanning)
                <button type="button" class="planning-mobile-btn" id="planning-mobile-availability" aria-expanded="false" aria-controls="planning-available">Beschikbaarheid</button>
            @endif
            <a class="planning-mobile-btn" href="{{ route('planning', array_merge($query, ['week' => $thisWeek])) }}" title="Ga naar vandaag">Vandaag</a>
        </div>
        <div class="planning-controls" style="padding:4px 12px">
            <div class="planning-controls-row">
                <div class="planning-controls-title" style="display:flex;flex-direction:row;flex-wrap:nowrap;align-items:baseline;gap:8px;width:max-content;max-width:100%">
                    <div class="planning-eyebrow" style="margin:0;white-space:nowrap">Planbord</div>
                    <h1 class="planning-heading" style="margin:0;font-size:15px;line-height:1.1;white-space:nowrap">Planning</h1>
                    <p class="planning-week-label" style="margin:0;white-space:nowrap">
                        @if (($filters['day'] ?? '') !== '' && $days->count() === 1)
                            {{ $weekRangeLabel }} · {{ $days->first()->translatedFormat('l d M Y') }}
                        @elseif (($filters['day'] ?? '') !== '')
                            {{ $weekRangeLabel }} · {{ $days->first()->translatedFormat('l d M') }} – {{ $days->last()->translatedFormat('d M Y') }}
                        @else
                            {{ $weekRangeLabel }} · {{ $weekStart->translatedFormat('d M') }} – {{ $days->last()->translatedFormat('d M Y') }}
                        @endif
                    </p>
                    @php $hoursView = $hoursView ?? ($filters['hours_view'] ?? 'planned'); @endphp
                    <div class="flex shrink-0 gap-1 text-xs" style="margin:0">
                        <a href="{{ route('planning', array_merge($query, ['hours_view' => 'planned'])) }}" class="planning-filter{{ $hoursView === 'planned' ? ' is-active' : '' }}">Gepland</a>
                        <a href="{{ route('planning', array_merge($query, ['hours_view' => 'actual'])) }}" class="planning-filter{{ $hoursView === 'actual' ? ' is-active' : '' }}">Werkelijk</a>
                    </div>
                </div>
                <div class="planning-toolbar">
                    <a class="planning-btn planning-btn--icon" href="{{ route('planning', array_merge($query, ['week' => $prevWeek])) }}" title="Vorige week" aria-label="Vorige week">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M19 12H5"/>
                            <path d="m12 5-7 7 7 7"/>
                        </svg>
                    </a>
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
                    <a class="planning-btn planning-btn--icon" href="{{ route('planning', array_merge($query, ['week' => $nextWeek])) }}" title="Volgende week" aria-label="Volgende week">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 12h14"/>
                            <path d="m12 5 7 7-7 7"/>
                        </svg>
                    </a>
                    <a class="planning-btn" href="{{ route('planning', array_merge($query, ['week' => $thisWeek])) }}" title="Ga naar deze week">Deze week</a>
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
                    <button
                        type="button"
                        class="planning-btn"
                        id="weekplanning-open"
                        title="Weekplanning vakmannen als PDF"
                    >Weekplanning vakmannen</button>
                    <a
                        class="planning-btn"
                        href="{{ route('planning.personnel-week', ['week' => $weekStart->toDateString()]) }}"
                        title="Weekplanning personeel"
                    >Weekplanning personeel</a>
                    <button
                        type="button"
                        class="planning-btn"
                        id="planning-print-open"
                        title="Planning van één werk afdrukken, met namen"
                    >Afdruk</button>
                    <form method="GET" action="{{ route('planning.excel') }}" class="planning-pdf-form">
                        <select name="year" class="planning-btn planning-select" aria-label="Jaar voor intern Excel" title="Jaar voor intern Excel">
                            @for ($year = $excelYear - 2; $year <= $excelYear + 1; $year++)
                                <option value="{{ $year }}" @selected($year === $excelYear)>{{ $year }}</option>
                            @endfor
                        </select>
                        <button type="submit" class="planning-btn" title="Jaarplanning als Excel, gevuld vanuit het planbord">Intern Excel</button>
                    </form>
                    <a class="planning-btn planning-btn--accent" href="{{ route('production.index') }}">Productie</a>
                    @if ($canManagePlanning)
                        <button type="button" class="planning-btn" id="internal-open" title="Vakman inplannen voor een ander bedrijfsonderdeel">Interne inzet</button>
                        <a class="planning-btn" href="{{ route('projects.small.create') }}">Klein werk</a>
                    @endif
                </div>
            </div>

            <form method="GET" id="planning-filters" class="planning-filters" style="margin-top:6px">
                <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
                <input type="hidden" name="weeks" value="{{ $weeks }}">
                <select name="kind" class="planning-filter" onchange="this.form.submit()" aria-label="Soort werk">
                    <option value="" @selected(($filters['kind'] ?? '') === '')>Alle werken</option>
                    <option value="{{ \App\Enums\ProjectKind::Project->value }}" @selected(($filters['kind'] ?? '') === \App\Enums\ProjectKind::Project->value)>Projecten</option>
                    <option value="{{ \App\Enums\ProjectKind::Winkel->value }}" @selected(($filters['kind'] ?? '') === \App\Enums\ProjectKind::Winkel->value)>Winkelwerk</option>
                    <option value="{{ \App\Enums\ProjectKind::KLEINE_FILTER }}" @selected(($filters['kind'] ?? '') === \App\Enums\ProjectKind::KLEINE_FILTER)>Kleine werken</option>
                </select>
                <select name="project_id" class="planning-filter" data-planning-search data-search-placeholder="Zoek op projectnr., werk, opdrachtgever of adres" onchange="this.form.submit()" aria-label="Werk">
                    <option value="">Alle</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" data-search="{{ $project->listSearchText() }}" @selected(($filters['project_id'] ?? '') == $project->id)>{{ $project->labeledNumbersLine() }} — {{ $project->displayTitle() }}</option>
                    @endforeach
                </select>
                @if ($canManagePlanning)
                    <select name="who" class="planning-filter" onchange="this.form.submit()" aria-label="Vakman of naam">
                        <option value="">Iedereen</option>
                        <optgroup label="Teams">
                            @foreach ($workers as $worker)
                                <option value="worker:{{ $worker->id }}" @selected(($filters['who'] ?? '') === 'worker:'.$worker->id)>{{ $worker->planName() }}</option>
                            @endforeach
                        </optgroup>
                        @if (($filterPeople ?? collect())->isNotEmpty())
                            <optgroup label="Personen">
                                @foreach ($filterPeople as $person)
                                    <option value="member:{{ $person['id'] }}" @selected(($filters['who'] ?? '') === 'member:'.$person['id'])>{{ $person['label'] }} · {{ $person['team'] }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                @endif
                <select name="day" class="planning-filter" onchange="this.form.submit()" aria-label="Dag">
                    <option value="" @selected(($filters['day'] ?? '') === '')>Hele week</option>
                    @foreach (\App\Services\PlanningBoardService::DAY_OPTIONS as $isoDay => $dayLabel)
                        <option value="{{ $isoDay }}" @selected((string) ($filters['day'] ?? '') === (string) $isoDay)>{{ $dayLabel }}</option>
                    @endforeach
                </select>
                <a href="{{ route('planning', ['week' => $weekStart->toDateString(), 'weeks' => $weeks]) }}" class="planning-filter planning-filter--reset">Reset</a>
            </form>

            @if (session('status'))
                <p class="mt-2 text-sm text-nicon-ok">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <ul class="mt-2 text-sm text-nicon-danger list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            @php
                $planningWithoutDoubles = array_diff_key($query, array_flip(['doubles', 'double_crew', 'double_worker']));
                $doubleFilter = $doubleFilter ?? ['active' => false, 'count' => 0, 'label' => null];
                $doubleCountLabel = (int) $doubleFilter['count'] === 1
                    ? '1 inzet'
                    : $doubleFilter['count'].' inzetten';
                $doubleHeading = 'Dubbele planning';
                if (! empty($doubleFilter['label'])) {
                    $doubleHeading .= ' · '.$doubleFilter['label'];
                }
                $doubleHeading .= ' ('.$doubleCountLabel.')';
            @endphp
            @if (! empty($doubleFilter['active']))
                <div class="planning-doubles-banner" role="status">
                    <span>⚠ {{ $doubleHeading }}</span>
                    <a
                        class="planning-doubles-clear"
                        href="{{ route('planning', $planningWithoutDoubles) }}"
                        title="Filter wissen"
                    >Alles tonen</a>
                </div>
            @endif

            @if (count($warnings))
                <div class="planning-warnings" role="alert">
                    <a
                        href="{{ route('planning', array_merge($planningWithoutDoubles, ['doubles' => '1'])) }}"
                        class="planning-warnings-title"
                    >
                        <span>Dubbele planning</span>
                        <span class="planning-warnings-action">Bekijk dubbele planning</span>
                    </a>
                    @foreach ($warnings as $warning)
                        <div class="planning-warnings-item">
                            @if (($warning['people'] ?? []) !== [])
                                @foreach ($warning['people'] as $person)
                                    @if (! $loop->first), @endif
                                    <a
                                        href="{{ route('planning', array_merge($planningWithoutDoubles, ['doubles' => '1', 'double_crew' => $person['id']])) }}"
                                        class="planning-warnings-name"
                                    >{{ $person['name'] }}</a>
                                @endforeach
                                <a
                                    href="{{ route('planning', array_merge($planningWithoutDoubles, ['doubles' => '1'])) }}"
                                    class="planning-warnings-rest"
                                > van {{ $warning['team'] }} staat op meerdere werken.</a>
                            @else
                                <a
                                    href="{{ route('planning', array_merge($planningWithoutDoubles, ['doubles' => '1', 'double_worker' => $warning['worker_id']])) }}"
                                    class="planning-warnings-rest"
                                >{{ $warning['message'] }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($canManagePlanning)
                <p class="planning-hint">Sleep een balk horizontaal binnen de dag (snap op 2 uur) of naar een andere dag/onderdeel. Trek aan de zijkanten om 2–8 uur te maken. Klik op een tijdvak om iemand in te plannen.</p>
            @endif
        </div>

        <div class="planning-scroll-area" id="plan-scroller" data-scroll-key="nicon.planning.scroll">
            <div class="plan-board{{ $canViewLaborCosts ? ' plan-board--labor' : '' }}{{ $dayCount === 1 ? ' plan-board--one-day' : '' }}" id="plan-board"
                 @if ($canViewLaborCosts) data-labor-fold-key="nicon.planning.laborFolded" @endif
                 data-shift-url="{{ route('planning.shift') }}"
                 data-move-url="{{ route('planning.assignments.move') }}"
                 data-store-url="{{ route('planning.assignments.store') }}"
                 data-internal-store-url="{{ route('planning.internal.store') }}"
                 data-candidates-url="{{ route('planning.candidates') }}"
                 data-assignment-url="{{ url('/planning/assignments') }}"
                 data-ticket-url="{{ url('/planning/assignments') }}"
                 data-readonly="{{ ($canDragPlanning ?? $canManagePlanning) ? '0' : '1' }}"
                 data-week-year="{{ $weekStart->isoWeekYear() }}"
                 data-crews='@json($workers->mapWithKeys(fn ($worker) => [$worker->id => $worker->activeCrewPeople()->unique(function ($person) {
                    $name = trim((string) $person->name);

                    return $name === '' ? 'id:'.$person->id : mb_strtolower($name);
                })->map(fn ($person) => ['id' => $person->id, 'name' => $person->label()])->values()]))'
                 data-work-items='@json($workItemsByProject)'
                 style="--plan-days: {{ $dayCount }}; --plan-day-min: {{ $dayMin }}px">
                @if ($canViewLaborCosts)
                    <script>
                        (function () {
                            var board = document.getElementById('plan-board');
                            try {
                                if (board && localStorage.getItem(board.getAttribute('data-labor-fold-key')) === '1') {
                                    board.classList.add('plan-board--labor-collapsed');
                                }
                            } catch (e) {}
                        })();
                    </script>
                @endif
                <div class="plan-table">
                    <div class="plan-sticky-top">
                    @if ($canManagePlanning)
                        @php
                            $availabilityDays = $availabilityDays ?? [];
                            $availabilityTeams = $teamManDays;
                            $availabilityDayLabels = collect($availabilityDays)->keyBy('date');
                        @endphp
                        <div class="planning-available" id="planning-available" data-plan-avail>
                            <div class="plan-line plan-line--avail plan-line--avail-head">
                                <div class="plan-frozen plan-frozen--avail">
                                    <div class="plan-cell plan-cell--avail-title">
                                        <span class="planning-available-title">Beschikbare mandagen: <span class="planning-available-count">{{ \App\Support\PlanningHours::manDaysLabel($availableManDays) }}</span></span>
                                    </div>
                                </div>
                                <div class="plan-days plan-days--avail">
                                    @foreach ($days as $day)
                                        @php
                                            $availabilityDay = $availabilityDayLabels->get($day->toDateString());
                                            $availabilityLabel = is_array($availabilityDay)
                                                ? ($availabilityDay['label'] ?? $day->isoFormat('dd'))
                                                : $day->isoFormat('dd');
                                        @endphp
                                        <div class="plan-avail-day{{ $loop->first ? '' : ' day-start' }}{{ $day->isMonday() && ! $loop->first ? ' week-start' : '' }}{{ $day->isSaturday() ? ' is-saturday' : '' }}">{{ $availabilityLabel }}</div>
                                    @endforeach
                                </div>
                            </div>
                            @if ($availabilityTeams === [])
                                <div class="plan-line plan-line--avail">
                                    <div class="plan-frozen plan-frozen--avail">
                                        <div class="plan-cell plan-cell--avail-empty">Geen actieve teams {{ $weeks === 1 ? 'deze week' : 'in deze weken' }}</div>
                                    </div>
                                    <div class="plan-days plan-days--avail" aria-hidden="true">
                                        @foreach ($days as $day)
                                            <div class="plan-avail-slot{{ $loop->first ? '' : ' day-start' }}{{ $day->isMonday() && ! $loop->first ? ' week-start' : '' }}{{ $day->isSaturday() ? ' is-saturday' : '' }}"></div>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                @foreach ($availabilityTeams as $team)
                                    <div class="plan-line plan-line--avail">
                                        <div class="plan-frozen plan-frozen--avail">
                                            <div class="plan-cell plan-cell--avail-team" style="--chip-color: {{ $team['color'] }}" title="{{ $team['summary'] }}">
                                                <span class="planning-available-name">{{ $team['label'] }}</span>
                                            </div>
                                        </div>
                                        <div class="plan-days plan-days--avail">
                                            @foreach ($days as $day)
                                                @php
                                                    $date = $day->toDateString();
                                                    $availabilityDay = $availabilityDayLabels->get($date);
                                                    $availabilityLabel = is_array($availabilityDay)
                                                        ? ($availabilityDay['label'] ?? $day->isoFormat('dd'))
                                                        : $day->isoFormat('dd');
                                                    $cell = $team['days'][$date] ?? null;
                                                    $people = $cell['people'] ?? [];
                                                    $popoverId = 'avail-'.$team['worker_id'].'-'.$date;
                                                    $isCrew = count($people) > 1;
                                                @endphp
                                                <div class="plan-avail-slot{{ $loop->first ? '' : ' day-start' }}{{ $day->isMonday() && ! $loop->first ? ' week-start' : '' }}{{ $day->isSaturday() ? ' is-saturday' : '' }}">
                                                    @if ($isCrew)
                                                        <div class="planning-avail-cell is-crew" role="group" aria-label="{{ $team['label'] }} {{ $availabilityLabel }} {{ $cell['label'] ?? '' }}">
                                                            @foreach ($people as $person)
                                                                @if (! empty($person['selectable']))
                                                                    <button
                                                                        type="button"
                                                                        class="planning-avail-part is-{{ $person['tone'] ?? 'none' }}"
                                                                        title="{{ $person['title'] ?? $person['name'] }}"
                                                                        data-plan-avail-pick
                                                                        data-worker-id="{{ $team['worker_id'] }}"
                                                                        data-date="{{ $date }}"
                                                                        data-crew-id="{{ $person['id'] }}"
                                                                        data-hours="{{ $person['remaining_hours'] }}"
                                                                    >{{ $person['chip'] ?? $person['name'] }}</button>
                                                                @else
                                                                    <span
                                                                        class="planning-avail-part is-{{ $person['tone'] ?? 'none' }}"
                                                                        title="{{ $person['title'] ?? $person['name'] }}"
                                                                    >{{ $person['chip'] ?? $person['name'] }}</span>
                                                                @endif
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        @php
                                                            $hover = collect($people)
                                                                ->map(fn (array $person): string => $person['name'])
                                                                ->implode("\n");
                                                        @endphp
                                                        <button
                                                            type="button"
                                                            class="planning-avail-cell is-{{ $cell['tone'] ?? 'none' }}"
                                                            popovertarget="{{ $popoverId }}"
                                                            title="{{ $hover }}"
                                                            aria-label="{{ $team['label'] }} {{ $availabilityLabel }} {{ $cell['label'] ?? 'Bezet' }}"
                                                        >{{ $cell['label'] ?? 'Bezet' }}</button>
                                                        <div id="{{ $popoverId }}" popover="auto" class="planning-avail-pop">
                                                            <p class="planning-avail-pop-title">{{ $availabilityLabel }} · {{ $team['label'] }}</p>
                                                            <ul class="planning-avail-people">
                                                                @foreach ($people as $person)
                                                                    <li>
                                                                        @if (! empty($person['selectable']))
                                                                            <button
                                                                                type="button"
                                                                                class="planning-avail-pick"
                                                                                data-plan-avail-pick
                                                                                data-worker-id="{{ $team['worker_id'] }}"
                                                                                data-date="{{ $date }}"
                                                                                data-crew-id="{{ $person['id'] }}"
                                                                                data-hours="{{ $person['remaining_hours'] }}"
                                                                            >{{ $person['mark'] }} {{ $person['name'] }} — {{ $person['detail'] }}</button>
                                                                        @else
                                                                            <span class="planning-avail-busy">{{ $person['mark'] }} {{ $person['name'] }} — {{ $person['detail'] }}</span>
                                                                        @endif
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endif
                    <div class="plan-line plan-line--head sticky-head">
                        <div class="plan-frozen plan-frozen--head">
                            <div class="plan-week-gutter" aria-hidden="true"></div>
                            <div class="plan-cell">Werk</div>
                            <div class="plan-cell plan-cell--num">Opdracht</div>
                            <div class="plan-cell plan-cell--num">Gereed</div>
                            <div class="plan-cell plan-cell--num">Rest</div>
                            <div class="plan-cell plan-cell--num">%</div>
                            @if ($canViewLaborCosts)
                                <button type="button" class="plan-labor-toggle plan-labor-toggle--open" data-labor-fold aria-expanded="true" title="Urenkolommen tonen">›</button>
                                <div class="plan-labor-block">
                                    <div class="plan-labor-block-inner">
                                        <div class="plan-cell plan-cell--num plan-cell--labor-head" title="Begrote uren uit calculatie">Begroot</div>
                                        <div class="plan-cell plan-cell--num plan-cell--labor-head" title="Ingepland via het planbord">Gepland</div>
                                        <div class="plan-cell plan-cell--num plan-cell--labor-head" title="Geregistreerde uren">Gemaakt</div>
                                        <div class="plan-cell plan-cell--num plan-cell--labor-head" title="Begroot minus gemaakt">Rest</div>
                                        <div class="plan-cell plan-cell--num plan-cell--labor-head" title="Gecalculeerde arbeidsprijs: uren × uurprijs uit de calculatie, gedeeld door de productie-m² of m¹">Begroot €/m²</div>
                                        <div class="plan-cell plan-cell--num plan-cell--labor-head" title="Werkelijke arbeidsprijs per eenheid">Werkelijk €/m²</div>
                                        <div class="plan-cell plan-cell--num plan-cell--labor-head" title="Verwachte arbeidsprijs per eenheid op basis van gemaakt plus nog gepland">Prognose €/m²</div>
                                        <div class="plan-cell plan-cell--num plan-cell--labor-head" title="Prognose minus begroot">Verschil</div>
                                    </div>
                                </div>
                                <button type="button" class="plan-labor-toggle plan-labor-toggle--close" data-labor-fold aria-expanded="true" title="Urenkolommen invouwen">‹</button>
                            @endif
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
                    </div>
                    @foreach ($rows as $projectRow)
                        @if (($projectRow['type'] ?? '') === 'internal')
                            @php
                                $internalHeight = max(28, 8 + ($projectRow['bar_count'] * 24));
                            @endphp
                            <div class="plan-line plan-line--project plan-line--internal" style="min-height: {{ $internalHeight }}px">
                                <div class="plan-frozen">
                                    <div class="plan-cell plan-cell--werk">
                                        <div class="plan-project-meta">
                                            <span class="plan-small-badge plan-small-badge--internal">INTERN</span>
                                            <span class="plan-project-title">{{ $projectRow['title'] }}</span>
                                        </div>
                                    </div>
                                    @include('planning.partials.qty-cells', ['row' => $projectRow, 'showLabor' => $canViewLaborCosts])
                                </div>
                                @include('planning.partials.plan-days', [
                                    'projectId' => '',
                                    'workItemId' => null,
                                    'personBars' => $projectRow['person_bars'],
                                    'showPeriod' => false,
                                    'periodBar' => null,
                                    'startMarker' => null,
                                    'endMarker' => null,
                                    'personBarOffset' => 4,
                                ])
                            </div>
                            @continue
                        @endif
                        @if (($projectRow['type'] ?? '') === 'section')
                            <div class="plan-line plan-line--section" role="separator" aria-label="{{ $projectRow['title'] }}">
                                <div class="plan-frozen">
                                    <div class="plan-cell plan-cell--werk plan-cell--section">{{ $projectRow['title'] }}</div>
                                    <div class="plan-cell plan-cell--num"></div>
                                    <div class="plan-cell plan-cell--num"></div>
                                    <div class="plan-cell plan-cell--num"></div>
                                    <div class="plan-cell plan-cell--num"></div>
                                    @if ($canViewLaborCosts)
                                        <div class="plan-labor-toggle plan-labor-toggle--open" aria-hidden="true"></div>
                                        <div class="plan-labor-block">
                                            <div class="plan-labor-block-inner">
                                                <div class="plan-cell plan-cell--num plan-cell--labor"></div>
                                                <div class="plan-cell plan-cell--num plan-cell--labor"></div>
                                                <div class="plan-cell plan-cell--num plan-cell--labor"></div>
                                                <div class="plan-cell plan-cell--num plan-cell--labor"></div>
                                                <div class="plan-cell plan-cell--num plan-cell--labor"></div>
                                                <div class="plan-cell plan-cell--num plan-cell--labor"></div>
                                                <div class="plan-cell plan-cell--num plan-cell--labor"></div>
                                                <div class="plan-cell plan-cell--num plan-cell--labor"></div>
                                            </div>
                                        </div>
                                        <div class="plan-labor-toggle plan-labor-toggle--close" aria-hidden="true"></div>
                                    @endif
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
                            $isCompact = ! empty($projectRow['compact']);
                            $projectHasPeriod = $projectRow['bar'] || $projectRow['start_marker'] || $projectRow['end_marker'];
                            $isAttachedExtra = $isCompact
                                && ! empty($projectRow['work_item_id'])
                                && ($projectRow['kind'] ?? '') === 'extra';
                            $projectHref = $isAttachedExtra
                                ? route('projects.extra.edit', ['project' => $projectRow['id'], 'extraWerk' => $projectRow['work_item_id']])
                                : route('projects.show', $projectRow['id']);
                            $pairedPeriod = is_array($projectRow['start_marker'] ?? null)
                                && is_array($projectRow['end_marker'] ?? null)
                                && (int) $projectRow['start_marker']['index'] === (int) $projectRow['end_marker']['index'];
                            $projectBarOffset = $projectHasPeriod ? ($pairedPeriod ? 30 : 16) : 4;
                            $projectHeight = max(28, $projectBarOffset + 4 + ($projectRow['bar_count'] * 24));
                            $projectOver = $canViewLaborCosts && ! empty($projectRow['labor']['hours_over']);
                            $customerName = trim((string) ($projectRow['customer'] ?? ''));
                            $showCustomer = $customerName !== ''
                                && ! str_contains(mb_strtolower((string) $projectRow['title']), mb_strtolower($customerName));
                        @endphp
                        <div class="plan-line plan-line--project{{ $isCompact ? ' plan-line--small' : '' }}{{ $projectOver ? ' plan-line--hour-over' : '' }}" style="min-height: {{ $projectHeight }}px">
                            <div class="plan-frozen">
                                <div class="plan-cell plan-cell--werk{{ ! empty($projectRow['missing_craftsman']) ? ' has-missing-craftsman' : '' }}">
                                    <div class="plan-project-meta">
                                        <a href="{{ $projectHref }}" class="hover:text-nicon-orange">
                                            @if (! empty($projectRow['badge']))
                                                <span class="plan-small-badge plan-small-badge--{{ $projectRow['kind'] ?? 'klein' }}">{{ $projectRow['badge'] }}</span>
                                            @endif
                                            @if (! $isCompact && ! empty($projectRow['numbers_short']))
                                                <span class="plan-project-numbers">{{ $projectRow['numbers_short'] }}</span>
                                            @endif
                                            <span class="plan-project-title">{{ $projectRow['title'] }}</span>
                                            @if ($isCompact && ! empty($projectRow['hours_label']))
                                                <span class="plan-project-hours">| {{ $projectRow['hours_label'] }}</span>
                                            @endif
                                        </a>
                                        @if ($showCustomer)
                                            <div class="text-xs font-normal text-nicon-muted">{{ $customerName }}</div>
                                        @endif
                                        @if (! $isCompact && ! empty($projectRow['labor']['extra_summary']))
                                            <div class="text-xs font-normal text-nicon-muted">{{ $projectRow['labor']['extra_summary'] }}</div>
                                        @endif
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
                                        @if (! empty($projectRow['werk_start']))
                                            @if (! empty($projectRow['start_week']))
                                                <a
                                                    href="{{ route('planning', array_merge($query, [
                                                        'week' => $projectRow['start_week'],
                                                        'project_id' => $projectRow['id'],
                                                    ])) }}"
                                                    class="plan-werk-start"
                                                    title="Ga naar startweek"
                                                >▶ Start {{ $projectRow['werk_start'] }}</a>
                                            @else
                                                <span class="plan-werk-start">▶ Start {{ $projectRow['werk_start'] }}</span>
                                            @endif
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
                                @include('planning.partials.qty-cells', ['row' => $projectRow, 'showLabor' => $canViewLaborCosts])
                            </div>
                            @include('planning.partials.plan-days', [
                                'projectId' => $projectRow['id'],
                                'workItemId' => $isCompact ? ($projectRow['work_item_id'] ?? null) : null,
                                'plannedHours' => $isCompact ? ($projectRow['planned_hours'] ?? null) : null,
                                'personBars' => $projectRow['person_bars'],
                                'showPeriod' => (bool) $projectHasPeriod,
                                'periodBar' => $projectRow['bar'],
                                'startMarker' => $projectRow['start_marker'],
                                'endMarker' => $projectRow['end_marker'],
                                'personBarOffset' => $projectBarOffset,
                            ])
                        </div>
                        @foreach ($projectRow['children'] as $work)
                            @php
                                $workOver = $canViewLaborCosts && ! empty($work['labor']['hours_over']);
                                $emptyQuantity = ! empty($work['empty_quantity']);
                                $workHeight = max($emptyQuantity && $canManagePlanning ? 64 : 28, 6 + ($work['bar_count'] * 24));
                            @endphp
                            <div class="plan-line plan-line--work{{ count($work['warnings']) ? ' plan-line--warn' : '' }}{{ $workOver ? ' plan-line--hour-over' : '' }}{{ $emptyQuantity && ($work['planning_included'] ?? true) === false ? ' plan-line--off' : '' }}" style="min-height: {{ $workHeight }}px">
                                <div class="plan-frozen">
                                    <div class="plan-cell plan-cell--werk plan-cell--indent">
                                        <div>
                                            @if ($canManagePlanning)
                                                <form method="POST" action="{{ route('planning.work-label.update', $work['id']) }}" class="plan-work-label-form" onsubmit="window.niconRememberPlanningScroll && window.niconRememberPlanningScroll()">
                                                    @csrf
                                                    @method('PATCH')
                                                    <select name="work_activity_id" class="plan-work-label" aria-label="Werkzaamheid {{ $work['title'] }}" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                                        <option value="" @selected(empty($work['planning_work_activity_id']))>{{ $work['default_title'] ?? $work['title'] }}</option>
                                                        @foreach ($workActivityChoices as $category)
                                                            @if ($category->activities->isNotEmpty())
                                                                <optgroup label="{{ $category->name }}">
                                                                    @foreach ($category->activities as $activity)
                                                                        <option value="{{ $activity->id }}" @selected((int) ($work['planning_work_activity_id'] ?? 0) === (int) $activity->id)>{{ $activity->name }}</option>
                                                                    @endforeach
                                                                </optgroup>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                </form>
                                            @else
                                                <div>{{ $work['title'] }}</div>
                                            @endif
                                            @if ($emptyQuantity && $canManagePlanning)
                                                <form method="POST" action="{{ route('planning.work-line.update', $work['id']) }}" class="plan-empty-line" onsubmit="window.niconRememberPlanningScroll && window.niconRememberPlanningScroll()">
                                                    @csrf
                                                    @method('PATCH')
                                                    <label class="plan-empty-check">
                                                        <input type="hidden" name="included" value="0">
                                                        <input type="checkbox" name="included" value="1" @checked($work['planning_included'] ?? true) onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                                        Aan
                                                    </label>
                                                    <label class="plan-empty-qty">
                                                        <input name="quantity" value="{{ \App\Support\Format::qtyInput($work['ordered'] ?? 0) }}" inputmode="decimal" aria-label="Hoeveelheid {{ $work['title'] }}" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                                                        {{ ($work['unit'] ?? '') !== '' ? $work['unit'] : 'm²' }}
                                                    </label>
                                                </form>
                                                <form method="POST" action="{{ route('planning.work-line.destroy', $work['id']) }}" class="plan-empty-line" onsubmit="if (!confirm('Deze regel verwijderen?')) { return false; } window.niconRememberPlanningScroll && window.niconRememberPlanningScroll();">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="plan-empty-delete">Verwijder</button>
                                                </form>
                                            @endif
                                            @if (! empty($work['steps']))
                                                <div class="text-[10px] font-normal text-nicon-muted">{{ implode(' · ', $work['steps']) }}</div>
                                            @endif
                                            @if (count($work['warnings']))
                                                <div class="text-nicon-danger">{{ implode(', ', $work['warnings']) }}</div>
                                            @endif
                                        </div>
                                    </div>
                                    @include('planning.partials.qty-cells', ['row' => $work, 'showLabor' => $canViewLaborCosts])
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
            <script>
                (function () {
                    var scroller = document.getElementById('plan-scroller');
                    var key = scroller && scroller.getAttribute('data-scroll-key');
                    window.niconRememberPlanningScroll = function () {
                        if (!scroller || !key) {
                            return;
                        }
                        try {
                            sessionStorage.setItem(key, JSON.stringify({
                                left: scroller.scrollLeft,
                                top: scroller.scrollTop,
                                windowLeft: window.scrollX,
                                windowTop: window.scrollY,
                                href: location.pathname + location.search
                            }));
                        } catch (e) {}
                    };
                    if (!scroller || !key) {
                        return;
                    }
                    try {
                        var raw = sessionStorage.getItem(key);
                        if (!raw) {
                            return;
                        }
                        var pos = JSON.parse(raw);
                        if (!pos || pos.href !== (location.pathname + location.search)) {
                            sessionStorage.removeItem(key);
                            return;
                        }
                        scroller.scrollLeft = Number(pos.left) || 0;
                        scroller.scrollTop = Number(pos.top) || 0;
                        window.scrollTo(Number(pos.windowLeft) || 0, Number(pos.windowTop) || 0);
                    } catch (e) {}
                })();
            </script>
        </div>
    </div>
    <div id="plan-hours-hint" class="plan-hours-hint hidden" aria-hidden="true">10:00 - 12:00 · 2u</div>

    <dialog id="plan-dialog" class="plan-dialog">
        <form id="plan-form" class="space-y-2">
            <h2 id="plan-dialog-title" class="text-base font-semibold">Iemand inplannen</h2>
            <input type="hidden" name="project_id" id="plan-project-id">
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Wie</label>
            <select name="who" id="plan-who" required class="w-full border border-nicon-line px-2 py-1.5 bg-white">
                <option value="">Kies vakman of team</option>
                @foreach ($workers as $worker)
                    <option value="worker:{{ $worker->id }}" data-men="{{ $worker->peopleCount() }}" data-external="{{ $worker->employment_type?->isExternal() ? '1' : '0' }}">{{ $worker->planName() }}</option>
                @endforeach
            </select>
            <div id="plan-crew" class="hidden space-y-1 rounded border border-nicon-line bg-nicon-sand/40 px-2 py-1.5">
                <div id="plan-crew-heading" class="text-[10px] uppercase tracking-wide text-nicon-muted">Vakmannen</div>
                <p id="plan-crew-hint" class="hidden text-xs text-nicon-muted">Niet aangevinkt blijft op het huidige werk.</p>
                <div id="plan-crew-list" class="space-y-0.5"></div>
            </div>
            <div id="plan-roles" class="hidden grid grid-cols-2 gap-2">
                <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">
                    Voorman
                    <select name="foreman_crew_member_id" id="plan-foreman" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white">
                        <option value="">Kies voorman</option>
                    </select>
                </label>
                <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">
                    Werkbon bij
                    <select name="work_ticket_crew_member_id" id="plan-work-ticket-holder" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white">
                        <option value="">Kies wie de werkbon heeft</option>
                    </select>
                </label>
            </div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Wat gaan ze doen</label>
            <input type="hidden" name="work_item_id" id="plan-work" value="">
            <div id="plan-work-list" class="space-y-0.5 rounded border border-nicon-line bg-nicon-sand/40 px-2 py-1.5"></div>
            <div id="plan-men-wrap">
                <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Aantal personen</label>
                <input type="number" name="people_count" id="plan-men" min="1" max="50" value="1" required class="mt-0 w-full border border-nicon-line px-2 py-1.5">
            </div>
            <div id="plan-hours-wrap">
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
            </div>
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Wanneer</div>
                <div class="plan-when-options mt-1 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="when" id="plan-when-dates" value="dates" checked>
                        Exacte datum
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" name="when" id="plan-when-weeks" value="weeks">
                        Weeknummer(s)
                    </label>
                </div>
            </div>
            <div id="plan-dates-wrap" class="space-y-2">
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Van</label>
                        <div class="plan-date-wrap">
                            <input type="text" name="start_date" id="plan-start" required inputmode="numeric" autocomplete="off" placeholder="jjjj-mm-dd" spellcheck="false" class="w-full border border-nicon-line px-2 py-1.5 pr-8">
                            <button type="button" class="plan-date-icon" data-plan-calendar-for="plan-start" title="Kalender" aria-label="Kalender openen">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="16" rx="1"/>
                                    <path d="M3 9h18"/>
                                    <path d="M8 3v4"/>
                                    <path d="M16 3v4"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Tot</label>
                        <div class="plan-date-wrap">
                            <input type="text" name="end_date" id="plan-end" required inputmode="numeric" autocomplete="off" placeholder="jjjj-mm-dd" spellcheck="false" class="w-full border border-nicon-line px-2 py-1.5 pr-8">
                            <button type="button" class="plan-date-icon" data-plan-calendar-for="plan-end" title="Kalender" aria-label="Kalender openen">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="16" rx="1"/>
                                    <path d="M3 9h18"/>
                                    <path d="M8 3v4"/>
                                    <path d="M16 3v4"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="plan-weeks-wrap" class="hidden space-y-2">
                <div class="plan-week-fields">
                    <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">
                        Van week
                        <input type="number" name="start_week" id="plan-start-week" min="1" max="53" inputmode="numeric" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5">
                    </label>
                    <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">
                        Tot week
                        <input type="number" name="end_week" id="plan-end-week" min="1" max="53" inputmode="numeric" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5">
                    </label>
                    <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">
                        Jaar
                        <input type="number" name="year" id="plan-week-year" min="2000" max="2100" inputmode="numeric" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5">
                    </label>
                </div>
                <p class="text-xs text-nicon-muted">Voorlopige periode: er worden nog geen uren per werkdag ingepland.</p>
            </div>
            <div class="flex flex-wrap gap-x-4 gap-y-1 pt-0.5 text-sm">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="include_saturday" id="plan-include-saturday" value="1">
                    Zaterdag
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="include_sunday" id="plan-include-sunday" value="1">
                    Zondag
                </label>
            </div>
            <div class="flex flex-wrap gap-2 pt-1">
                <button type="submit" class="bg-nicon-orange text-white px-4 py-1.5">Opslaan</button>
                <button type="button" id="plan-cancel" class="border border-nicon-line px-4 py-1.5 bg-white">Annuleren</button>
                <button type="button" id="plan-delete" class="text-nicon-danger px-4 py-1.5 hidden">Verwijderen</button>
            </div>
            <p id="plan-ticket-wrap" class="hidden space-y-1 pt-1">
                <a id="plan-ticket-existing" href="#" class="hidden text-sm text-nicon-orange hover:underline"></a>
                <a id="plan-ticket-link" href="#" class="text-sm text-nicon-orange hover:underline">Werkbon maken</a>
            </p>
        </form>
    </dialog>
    @if ($canManagePlanning)
        <dialog id="internal-dialog" class="plan-dialog">
            <form id="internal-form" class="space-y-2">
                <h2 id="internal-dialog-title" class="text-base font-semibold">Interne inzet</h2>
                <p class="text-xs text-nicon-muted">De vakman is dan bezet voor dit onderdeel, zonder project of werkbon.</p>
                <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Vakman of team</label>
                <select name="who" id="internal-who" required class="w-full border border-nicon-line px-2 py-1.5 bg-white">
                    <option value="">Kies vakman of team</option>
                    @foreach ($workers as $worker)
                        <option value="worker:{{ $worker->id }}">{{ $worker->planName() }}</option>
                    @endforeach
                </select>
                <div id="internal-crew" class="hidden space-y-1 rounded border border-nicon-line bg-nicon-sand/40 px-2 py-1.5">
                    <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Vakmannen</div>
                    <div id="internal-crew-list" class="space-y-0.5"></div>
                </div>
                <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">
                    Onderdeel
                    <select name="business_unit" id="internal-unit" required class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white">
                        <option value="">Kies onderdeel</option>
                        @foreach (\App\Enums\InternalBusinessUnit::choices() as $unit)
                            <option value="{{ $unit->value }}">{{ $unit->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">
                    Contactpersoon
                    <input type="text" name="contact_name" id="internal-contact" required maxlength="255" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5">
                </label>
                <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">
                    Omschrijving
                    <input type="text" name="description" id="internal-description" required maxlength="255" placeholder="Werk op locatie" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5">
                </label>
                <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">
                    Opmerking
                    <input type="text" name="notes" id="internal-notes" maxlength="2000" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5">
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Van</label>
                        <div class="plan-date-wrap">
                            <input type="text" name="start_date" id="internal-start" required inputmode="numeric" autocomplete="off" placeholder="jjjj-mm-dd" spellcheck="false" class="w-full border border-nicon-line px-2 py-1.5 pr-8">
                            <button type="button" class="plan-date-icon" data-plan-calendar-for="internal-start" title="Kalender" aria-label="Kalender openen">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="16" rx="1"/>
                                    <path d="M3 9h18"/>
                                    <path d="M8 3v4"/>
                                    <path d="M16 3v4"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Tot</label>
                        <div class="plan-date-wrap">
                            <input type="text" name="end_date" id="internal-end" required inputmode="numeric" autocomplete="off" placeholder="jjjj-mm-dd" spellcheck="false" class="w-full border border-nicon-line px-2 py-1.5 pr-8">
                            <button type="button" class="plan-date-icon" data-plan-calendar-for="internal-end" title="Kalender" aria-label="Kalender openen">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="16" rx="1"/>
                                    <path d="M3 9h18"/>
                                    <path d="M8 3v4"/>
                                    <path d="M16 3v4"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1 pt-0.5 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="include_saturday" id="internal-saturday" value="1">
                        Zaterdag
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="include_sunday" id="internal-sunday" value="1">
                        Zondag
                    </label>
                </div>
                <div class="flex flex-wrap gap-2 pt-1">
                    <button type="submit" class="bg-nicon-orange text-white px-4 py-1.5">Opslaan</button>
                    <button type="button" id="internal-cancel" class="border border-nicon-line px-4 py-1.5 bg-white">Annuleren</button>
                    <button type="button" id="internal-delete" class="text-nicon-danger px-4 py-1.5 hidden">Verwijderen</button>
                </div>
            </form>
        </dialog>
    @endif
    <dialog id="planning-print-dialog" class="plan-dialog">
        <form
            id="planning-print-form"
            method="GET"
            action="{{ route('planning.export') }}"
            target="_blank"
            class="space-y-3"
        >
            <h2 class="text-base font-semibold">Afdruk</h2>
            <p class="text-sm text-nicon-muted">
                Kies een werk. Standaard wordt de planning van het <strong>gehele werk</strong> afgedrukt. In het afdrukvenster kun je printen of <strong>Opslaan als PDF</strong> kiezen.
            </p>
            <input type="hidden" name="week" value="{{ $filters['week'] ?? $weekStart->toDateString() }}">
            <input type="hidden" name="intern" value="1">
            <label class="block space-y-1">
                <span class="text-[10px] uppercase tracking-wide text-nicon-muted">Werk</span>
                <select name="project_id" id="planning-print-project" required class="w-full border border-nicon-line bg-white px-3 py-2 text-sm">
                    <option value="">Kies een werk…</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) ($filters['project_id'] ?? '') === (string) $project->id)>
                            {{ $project->labeledNumbersLine() }} — {{ $project->displayTitle() }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="block space-y-1">
                <span class="text-[10px] uppercase tracking-wide text-nicon-muted">Periode</span>
                <select name="period" id="planning-print-period" class="w-full border border-nicon-line bg-white px-3 py-2 text-sm" aria-label="Periode voor PDF" title="Periode voor PDF">
                    <option value="week" @selected(($period ?? '') === 'week')>Week</option>
                    <option value="month" @selected(($period ?? '') === 'month')>Maand</option>
                    <option value="work" @selected(($period ?? 'work') === 'work' || ($period ?? '') === '')>Gehele werk</option>
                </select>
            </label>
            <input type="hidden" name="print" value="1">
            <div class="flex flex-wrap justify-end gap-2 pt-1">
                <button type="button" id="planning-print-cancel" class="border border-nicon-line px-4 py-1.5 bg-white">Annuleren</button>
                <button type="submit" class="bg-nicon-orange text-white px-4 py-1.5">Afdrukken</button>
            </div>
        </form>
    </dialog>
    <dialog id="weekplanning-dialog" class="plan-dialog">
        <form
            id="weekplanning-form"
            method="GET"
            action="{{ route('planning.weekplanning') }}"
            target="_blank"
            class="space-y-3"
        >
            <h2 class="text-base font-semibold">Weekplanning exporteren</h2>
            <p class="text-sm text-nicon-muted">
                Week:
                <span class="font-medium text-nicon-ink">
                    Week {{ $weekStart->isoWeek() }} – {{ $weekStart->translatedFormat('j') }} t/m {{ $days->last()->translatedFormat('j F Y') }}
                </span>
            </p>
            <input type="hidden" name="week" value="{{ $filters['week'] ?? $weekStart->toDateString() }}">
            <div>
                <div class="text-[10px] uppercase tracking-wide text-nicon-muted">Teams / ZZP-bedrijven</div>
                <label class="mt-1 flex items-center gap-2 text-sm">
                    <input type="checkbox" name="all" value="1" id="weekplanning-all" checked>
                    Alles
                </label>
                @foreach ($weekplanningTeams ?? [] as $group)
                    <label class="mt-1 flex items-center gap-2 text-sm">
                        <input type="checkbox" name="teams[]" value="{{ $group['key'] }}" class="weekplanning-team">
                        {{ $group['name'] }}
                    </label>
                @endforeach
            </div>
            <div class="flex flex-wrap justify-end gap-2 pt-1">
                <button type="button" id="weekplanning-cancel" class="border border-nicon-line px-4 py-1.5 bg-white">Annuleren</button>
                <button type="submit" class="bg-nicon-orange text-white px-4 py-1.5">PDF maken</button>
            </div>
        </form>
    </dialog>
@endsection
