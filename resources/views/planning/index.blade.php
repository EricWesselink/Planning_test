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
        <div class="planning-controls">
            <div class="planning-controls-row">
                <div class="planning-controls-title">
                    <div class="planning-eyebrow">Planbord</div>
                    <h1 class="planning-heading">Planning</h1>
                    <p class="planning-week-label">{{ $weekRangeLabel }} · {{ $weekStart->translatedFormat('d M') }} – {{ $days->last()->translatedFormat('d M Y') }}</p>
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
                        <button type="submit" class="planning-btn" name="intern" value="1" title="Planning printen voor eigen gebruik, met namen">Intern</button>
                    </form>
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
                        <a class="planning-btn" href="{{ route('projects.small.create') }}">Klein werk</a>
                    @endif
                </div>
            </div>

            <form method="GET" class="planning-filters">
                <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
                <input type="hidden" name="weeks" value="{{ $weeks }}">
                <select name="kind" class="planning-filter" onchange="this.form.submit()" aria-label="Soort werk">
                    <option value="" @selected(($filters['kind'] ?? '') === '')>Alle werken</option>
                    <option value="{{ \App\Enums\ProjectKind::Project->value }}" @selected(($filters['kind'] ?? '') === \App\Enums\ProjectKind::Project->value)>Projecten</option>
                    <option value="{{ \App\Enums\ProjectKind::Winkel->value }}" @selected(($filters['kind'] ?? '') === \App\Enums\ProjectKind::Winkel->value)>Winkelwerk</option>
                    <option value="{{ \App\Enums\ProjectKind::KLEINE_FILTER }}" @selected(($filters['kind'] ?? '') === \App\Enums\ProjectKind::KLEINE_FILTER)>Kleine werken</option>
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
                    @php
                        $weekStaffedQuery = [
                            'week' => $weekStart->toDateString(),
                            'weeks' => $weeks,
                            'staffing' => 'planned',
                        ];
                        $weekStaffedActive = ($filters['staffing'] ?? '') === 'planned'
                            && empty($filters['worker_id'])
                            && empty($filters['project_id'])
                            && empty($filters['status'])
                            && empty($filters['kind']);
                    @endphp
                    <a
                        href="{{ route('planning', $weekStaffedQuery) }}"
                        class="planning-filter planning-filter--staffed{{ $weekStaffedActive ? ' is-active' : '' }}"
                        title="Alle werken waarop deze week een vakman staat"
                    >Deze week met vakman</a>
                @endif
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
                    $availabilityDays = $availabilityDays ?? [];
                    $availabilityTeams = $teamManDays;
                @endphp
                <div class="planning-available" data-plan-avail @if ($availabilityDays !== []) style="--avail-days: {{ count($availabilityDays) }}" @endif>
                    <div class="planning-available-head">
                        <span class="planning-available-title">Mandagen week <span class="planning-available-week-nr">{{ $manDayWeekNumber }}</span> — totaal vrij: <span class="planning-available-count">{{ \App\Support\PlanningHours::manDaysLabel($availableManDays) }}</span></span>
                    </div>
                    @if ($availabilityTeams === [])
                        <span class="planning-available-empty">Geen actieve teams {{ $weeks === 1 ? 'deze week' : 'in deze weken' }}</span>
                    @else
                        <div class="planning-avail-table">
                            <div class="planning-avail-corner">Team</div>
                            @foreach ($availabilityDays as $availabilityDay)
                                <div class="planning-avail-day">{{ $availabilityDay['label'] }}</div>
                            @endforeach
                            @foreach ($availabilityTeams as $team)
                                <div class="planning-avail-team" style="--chip-color: {{ $team['color'] }}" title="{{ $team['summary'] }}">
                                    <span class="planning-available-name">{{ $team['label'] }}</span>
                                </div>
                                @foreach ($availabilityDays as $availabilityDay)
                                    @php
                                        $cell = $team['days'][$availabilityDay['date']] ?? null;
                                        $people = $cell['people'] ?? [];
                                        $hover = collect($people)
                                            ->map(function (array $person) use ($people): string {
                                                $lead = count($people) > 1 && ($person['initials'] ?? '') !== ''
                                                    ? $person['initials'].' '
                                                    : '';

                                                return $lead.$person['mark'].' '.$person['name'].' — '.$person['detail'];
                                            })
                                            ->implode("\n");
                                        $popoverId = 'avail-'.$team['worker_id'].'-'.$availabilityDay['date'];
                                    @endphp
                                    <div class="planning-avail-slot">
                                        <button
                                            type="button"
                                            class="planning-avail-cell is-{{ $cell['tone'] ?? 'none' }}{{ count($people) > 1 ? ' is-crew' : '' }}"
                                            popovertarget="{{ $popoverId }}"
                                            title="{{ $hover }}"
                                            aria-label="{{ $team['label'] }} {{ $availabilityDay['label'] }} {{ $cell['label'] ?? 'Bezet' }}"
                                        >{{ $cell['label'] ?? 'Bezet' }}</button>
                                        <div id="{{ $popoverId }}" popover="auto" class="planning-avail-pop">
                                            <p class="planning-avail-pop-title">{{ $availabilityDay['label'] }} · {{ $team['label'] }}</p>
                                            <ul class="planning-avail-people">
                                                @foreach ($people as $person)
                                                    <li>
                                                        @if (! empty($person['selectable']))
                                                            <button
                                                                type="button"
                                                                class="planning-avail-pick"
                                                                data-plan-avail-pick
                                                                data-worker-id="{{ $team['worker_id'] }}"
                                                                data-date="{{ $availabilityDay['date'] }}"
                                                                data-crew-id="{{ $person['id'] }}"
                                                                data-hours="{{ $person['remaining_hours'] }}"
                                                            >@if (count($people) > 1 && ($person['initials'] ?? '') !== ''){{ $person['initials'] }} @endif{{ $person['mark'] }} {{ $person['name'] }} — {{ $person['detail'] }}</button>
                                                        @else
                                                            <span class="planning-avail-busy">@if (count($people) > 1 && ($person['initials'] ?? '') !== ''){{ $person['initials'] }} @endif{{ $person['mark'] }} {{ $person['name'] }} — {{ $person['detail'] }}</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    @endif
                </div>

                <p class="planning-hint">Sleep een balk horizontaal binnen de dag (snap op 2 uur) of naar een andere dag/onderdeel. Trek aan de zijkanten om 2–8 uur te maken. Klik op een tijdvak om iemand in te plannen.</p>
            @endif
        </div>

        <div class="planning-scroll-area" id="plan-scroller" data-scroll-key="nicon.planning.scroll">
            @php
                $workItemsByProject = $projects->mapWithKeys(function ($project) {
                    $items = $project->workItems
                        ->filter(fn ($item) => (float) $item->ordered_quantity > 0.0001 || $item->work_activity_id !== null)
                        ->map(fn ($item) => [
                            'id' => $item->id,
                            'name' => $item->productLabel() ?: (\App\Support\WorkType::looksLikeRoom($item->name) ? $item->typeLabel() : $item->name),
                            'group' => $item->planningTitle(),
                            'type_key' => $item->typeKey(),
                            'project_id' => $project->id,
                            'project' => $project->displayTitle(),
                        ])
                        ->values();

                    return [$project->id => $items];
                });
            @endphp
            <div class="plan-board{{ $canViewLaborCosts ? ' plan-board--labor' : '' }}" id="plan-board"
                 @if ($canViewLaborCosts) data-labor-fold-key="nicon.planning.laborFolded" @endif
                 data-shift-url="{{ route('planning.shift') }}"
                 data-move-url="{{ route('planning.assignments.move') }}"
                 data-store-url="{{ route('planning.assignments.store') }}"
                 data-candidates-url="{{ route('planning.candidates') }}"
                 data-assignment-url="{{ url('/planning/assignments') }}"
                 data-ticket-url="{{ url('/planning/assignments') }}"
                 data-readonly="{{ $canManagePlanning ? '0' : '1' }}"
                 data-week-year="{{ $weekStart->isoWeekYear() }}"
                 data-crews='@json($workers->mapWithKeys(fn ($worker) => [$worker->id => $worker->crewPeople->unique(function ($person) {
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
                                        <div class="plan-cell plan-cell--num plan-cell--labor-head" title="Begrote arbeidsprijs per eenheid">Begroot €/m²</div>
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
                    @foreach ($rows as $projectRow)
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
                            $isAttachedSmall = $isCompact
                                && ! empty($projectRow['work_item_id'])
                                && in_array($projectRow['kind'] ?? '', ['extra', 'klein'], true);
                            $projectHref = $isAttachedSmall
                                ? route('projects.extra.edit', [$projectRow['id'], $projectRow['work_item_id']])
                                : route('projects.show', $projectRow['id']);
                            $projectBarOffset = $projectHasPeriod ? 16 : 4;
                            $projectHeight = max(28, $projectBarOffset + 4 + ($projectRow['bar_count'] * 24));
                            $projectOver = $canViewLaborCosts && ! empty($projectRow['labor']['hours_over']);
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
                                            <span class="plan-project-title">{{ $projectRow['title'] }}@if ($isCompact && ! empty($projectRow['hours_label'])) | {{ $projectRow['hours_label'] }}@endif</span>
                                        </a>
                                        @if (! $isCompact && ! empty($projectRow['labor']['extra_summary']))
                                            <div class="text-xs font-normal text-nicon-muted">{{ $projectRow['labor']['extra_summary'] }}</div>
                                        @endif
                                        @if (! empty($projectRow['subtitle']))
                                            <div class="text-xs font-normal text-nicon-muted">{{ $projectRow['subtitle'] }}</div>
                                        @endif
                                        @if (! $isCompact && ($projectRow['naw_line'] ?? $projectRow['city']))
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
                                $workHeight = max(28, 6 + ($work['bar_count'] * 24));
                            @endphp
                            <div class="plan-line plan-line--work{{ count($work['warnings']) ? ' plan-line--warn' : '' }}{{ $workOver ? ' plan-line--hour-over' : '' }}" style="min-height: {{ $workHeight }}px">
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
                    <option value="worker:{{ $worker->id }}" data-men="{{ $worker->peopleCount() }}">{{ $worker->planName() }}</option>
                @endforeach
            </select>
            <div id="plan-crew" class="hidden space-y-1 rounded border border-nicon-line bg-nicon-sand/40 px-2 py-1.5">
                <div id="plan-crew-heading" class="text-[10px] uppercase tracking-wide text-nicon-muted">Wie gaat er naartoe</div>
                <p id="plan-crew-hint" class="hidden text-xs text-nicon-muted">Niet aangevinkt blijft op het huidige werk.</p>
                <div id="plan-crew-list" class="space-y-0.5"></div>
            </div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Wat gaan ze doen</label>
            <input type="hidden" name="work_item_id" id="plan-work" value="">
            <div id="plan-work-list" class="max-h-48 space-y-0.5 overflow-y-auto rounded border border-nicon-line bg-nicon-sand/40 px-2 py-1.5"></div>
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
