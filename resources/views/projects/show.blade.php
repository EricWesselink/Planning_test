@extends('layouts.app')

@section('title', $project->name.' · Nicon Planning')
@section('main_class', 'p-0 min-h-0 overflow-hidden')

@push('scripts')
    @vite(['resources/js/drawing-board.js'])
@endpush

@section('content')
    @php
        $areas = collect($board['areas'])->sortBy(fn ($area) => \App\Support\RoomUniqueName::sortKey($area))->values();
        $floors = $areas->groupBy(fn ($area) => $area['floor'] ?: 'Overig');
        $counts = [
            'all' => $areas->count(),
            'open' => $areas->where('tone', 'open')->count(),
            'partial' => $areas->where('tone', 'partial')->count(),
            'pending' => $areas->where('tone', 'pending')->count(),
            'done' => $areas->where('tone', 'done')->count(),
        ];
        $first = $firstDetail['area'] ?? $areas->first();
        $canEnterProgress = auth()->user()?->canEnterProgress() ?? false;
        $canApproveProgress = auth()->user()?->canApproveProgress() ?? false;
        $lockedWorkerId = auth()->user()?->scheduledWorkerId();
    @endphp

    <div id="project-board" class="project-board" data-selected="{{ $selectedAreaId }}" data-open-snag="{{ $openSnagId ?? '' }}">
        <header class="board-top">
            <div>
                <a href="{{ $project->isArchived() ? route('projects.archived') : route('projects.index') }}" class="text-xs text-nicon-muted">← {{ $project->isArchived() ? 'Archief' : 'Projecten' }}</a>
                @if ($project->labeledNumbersLine() !== '')
                    <div class="mt-1 text-xs text-nicon-muted whitespace-nowrap">{{ $project->labeledNumbersLine() }}</div>
                @endif
                <h1 class="text-lg font-semibold leading-tight">{{ $project->displayTitle() }} <span class="text-nicon-muted font-normal">· {{ $project->isArchived() ? 'Archief' : $project->status->label() }}</span></h1>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-nicon-muted">
                    @if ($project->customer?->name)
                        <span>Opdrachtgever: {{ $project->customer->name }}</span>
                    @endif
                    @if ($project->planned_start_date)
                        <span>Start werk: {{ \App\Support\PlanningWeek::label($project->planned_start_date) }}</span>
                    @endif
                </div>
                @if (auth()->user()?->canViewLaborCosts())
                    @include('projects.partials.calculation-lines', ['project' => $project])
                @endif
                @if (session('status'))
                    <p class="mt-1 text-xs text-nicon-ok">{{ session('status') }}</p>
                @endif
                @foreach ((array) session('warnings', []) as $warning)
                    <p class="mt-0.5 text-xs text-nicon-danger">{{ $warning }}</p>
                @endforeach
                <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-nicon-muted">
                    @if ($project->nawLine())
                        <span>{{ $project->nawLine() }}</span>
                        <a href="{{ $project->googleMapsUrl() }}" target="_blank" rel="noopener noreferrer" class="text-nicon-orange-dark">Navigeren</a>
                    @endif
                    @can('update', $project)
                        <details class="relative" @if ($errors->hasAny(['address', 'postal_code', 'city', 'basis_uurtarief', 'work_items', 'start_year', 'start_week', 'klaar_year', 'klaar_week', 'start_date', 'klaar_date']) || $errors->has('work_items.*')) open @endif>
                            <summary class="cursor-pointer hover:text-nicon-ink">Project bewerken</summary>
                            <form method="POST" action="{{ route('projects.update', $project) }}" class="absolute z-30 mt-1 w-[28rem] max-h-[80vh] overflow-auto border border-nicon-line bg-white p-3 shadow-sm space-y-2">
                                @csrf
                                @method('PATCH')
                                @foreach (['address', 'postal_code', 'city', 'basis_uurtarief', 'start_year', 'start_week', 'klaar_year', 'klaar_week', 'start_date', 'klaar_date'] as $field)
                                    @error($field)
                                        <p class="text-xs text-nicon-danger">{{ $message }}</p>
                                    @enderror
                                @endforeach
                                @error('work_items.*')
                                    <p class="text-xs text-nicon-danger">{{ $message }}</p>
                                @enderror
                                <label class="block text-[11px] uppercase tracking-wide">Straat</label>
                                <input name="address" value="{{ old('address', $project->address) }}" class="w-full border border-nicon-line px-2 py-1" placeholder="Straat 12">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[11px] uppercase tracking-wide">Postcode</label>
                                        <input name="postal_code" value="{{ old('postal_code', $project->postal_code) }}" class="w-full border border-nicon-line px-2 py-1" placeholder="3811 AA">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] uppercase tracking-wide">Plaats</label>
                                        <input name="city" value="{{ old('city', $project->city) }}" class="w-full border border-nicon-line px-2 py-1" placeholder="Amersfoort">
                                    </div>
                                </div>
                                @if (auth()->user()?->canViewLaborCosts())
                                    <label class="block text-[11px] uppercase tracking-wide" for="basis_uurtarief">Basis uurtarief (€)</label>
                                    <input id="basis_uurtarief" name="basis_uurtarief" value="{{ old('basis_uurtarief', $project->basis_uurtarief) }}" inputmode="decimal" class="w-full border border-nicon-line px-2 py-1" placeholder="45,00">
                                    @if ($project->workItems->isNotEmpty())
                                        <div class="text-[11px] uppercase tracking-wide">Uren per onderdeel</div>
                                        @foreach ($project->workItems as $item)
                                            <div class="space-y-1 border border-nicon-line bg-nicon-sand/40 p-2">
                                                <div class="text-xs font-medium">{{ $item->name }}</div>
                                                <div class="grid grid-cols-3 gap-1">
                                                    <div>
                                                        <label class="block text-[10px] text-nicon-muted" for="work-hours-{{ $item->id }}">Begrote uren</label>
                                                        <input id="work-hours-{{ $item->id }}" name="work_items[{{ $item->id }}][begrote_uren]" value="{{ old('work_items.'.$item->id.'.begrote_uren', $item->begrote_uren) }}" inputmode="decimal" class="w-full border border-nicon-line px-1.5 py-1" placeholder="80">
                                                    </div>
                                                    <div>
                                                        <label class="block text-[10px] text-nicon-muted" for="work-qty-{{ $item->id }}">Begroot {{ $item->unit?->label() }}</label>
                                                        <input id="work-qty-{{ $item->id }}" name="work_items[{{ $item->id }}][begrote_hoeveelheid]" value="{{ old('work_items.'.$item->id.'.begrote_hoeveelheid', $item->begrote_hoeveelheid) }}" inputmode="decimal" class="w-full border border-nicon-line px-1.5 py-1" placeholder="{{ \App\Support\Format::qty($item->ordered_quantity) }}">
                                                    </div>
                                                    <div>
                                                        <label class="block text-[10px] text-nicon-muted" for="work-rate-{{ $item->id }}">Tarief €/u</label>
                                                        <input id="work-rate-{{ $item->id }}" name="work_items[{{ $item->id }}][uurtarief]" value="{{ old('work_items.'.$item->id.'.uurtarief', $item->uurtarief) }}" inputmode="decimal" class="w-full border border-nicon-line px-1.5 py-1" placeholder="basis">
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    @endif
                                @endif
                                @include('projects.partials.planning-weeks', [
                                    'idPrefix' => 'project-',
                                    'compact' => true,
                                    'startYear' => $project->planningStartYear(),
                                    'startWeek' => $project->planningStartWeek(),
                                    'klaarYear' => $project->planningEndYear(),
                                    'klaarWeek' => $project->planningEndWeek(),
                                    'startDate' => $project->planned_start_date?->toDateString(),
                                    'klaarDate' => $project->planned_end_date?->toDateString(),
                                ])
                                <button class="w-full bg-nicon-ink text-white px-2 py-1.5">Opslaan</button>
                            </form>
                        </details>
                    @endcan
                </div>
                @if (($todayPresence ?? collect())->isNotEmpty())
                    <div class="mt-1 space-y-0.5 text-xs">
                        @foreach ($todayPresence as $row)
                            <div>
                                <span class="text-nicon-muted">Vakman:</span> {{ $row['worker'] }}
                                · <span class="text-nicon-muted">Aanwezig:</span> {{ $row['present'] }}
                            </div>
                        @endforeach
                    </div>
                @endif
                @php
                    $extraItems = $project->workItems->filter(fn ($item) => $item->isExtraWork());
                @endphp
                @if ($extraItems->isNotEmpty())
                    <div class="mt-1 space-y-0.5 text-xs">
                        @foreach ($extraItems as $extra)
                            <div>
                                <a href="{{ route('projects.extra.edit', [$project, $extra]) }}" class="text-nicon-orange-dark">{{ $extra->small_work_type?->badge() ?? 'EXTRA' }} {{ $extra->name }} — klaar, uren en materiaal</a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            <nav class="board-tabs">
                <a class="is-on" href="{{ route('projects.show', $project) }}">Tekening</a>
                <a href="{{ route('projects.snags.index', $project) }}">Opleverpunten</a>
                <a href="{{ route('planning', ['project_id' => $project->id]) }}">Planning</a>
                <a href="{{ route('production.index', ['project_id' => $project->id]) }}">Productie</a>
            </nav>
        </header>

        <aside class="board-left">
            <div class="px-3 pt-3 pb-2">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <span id="room-count-label" class="text-xs text-nicon-muted">{{ $counts['all'] }} ruimtes</span>
                        <span id="room-work-qty" class="draw-work-qty" title="Totaal van de aangevinkte onderdelen op deze verdieping"></span>
                        <span id="picked-count" hidden></span>
                    </div>
                    @if ($canEnterProgress)
                        <button type="button" id="pick-all-rooms" class="room-pick-btn">Hele werk</button>
                    @endif
                </div>
                <div class="mt-2 flex flex-wrap gap-1 text-xs" id="room-filters">
                    <button type="button" data-filter="all" class="room-filter is-on">Alles ({{ $counts['all'] }})</button>
                    <button type="button" data-filter="open" class="room-filter">Open ({{ $counts['open'] }})</button>
                    <button type="button" data-filter="partial" class="room-filter">Deels gereed ({{ $counts['partial'] }})</button>
                    <button type="button" data-filter="pending" class="room-filter">Voorlopig ({{ $counts['pending'] }})</button>
                    <button type="button" data-filter="done" class="room-filter">Gereed ({{ $counts['done'] }})</button>
                </div>
            </div>
            <div class="overflow-auto flex-1">
                @foreach ($floors as $floorName => $floorAreas)
                    <div class="floor-head sticky top-0">
                        <span>{{ $floorName }}</span>
                        @if ($canEnterProgress)
                            <button type="button" class="room-pick-btn floor-pick" data-floor="{{ $floorName }}">Deze verdieping</button>
                        @endif
                    </div>
                    @foreach ($floorAreas as $area)
                        <button type="button" class="room-row {{ (int) $selectedAreaId === (int) $area['id'] ? 'is-on is-picked' : '' }}" data-area-id="{{ $area['id'] }}" data-tone="{{ $area['tone'] }}" data-floor="{{ $floorName }}" data-page="{{ $area['page'] ?? '' }}" data-works="{{ collect($area['works'] ?? [])->pluck('key')->implode(',') }}" style="--material-color: {{ $area['material_color'] ?? '#9ca3af' }}; --material-color-soft: {{ $area['material_color_soft'] ?? 'rgba(156, 163, 175, 0.14)' }};" title="{{ $area['number'] }} {{ $area['unique_name'] ?? $area['name'] }} · {{ $area['m2_label'] }} · {{ $area['status_label'] }}">
                            <span class="room-num">{{ $area['number'] ?: '—' }}</span>
                            <span class="room-name">{{ $area['unique_name'] ?? $area['name'] }}</span>
                            <span class="room-m2">{{ $area['m2_label'] }}</span>
                            <span class="status-pill tone-{{ $area['tone'] }}" title="{{ $area['status_label'] }}{{ $canEnterProgress ? ' · tik om extra aan te vinken' : '' }}"></span>
                        </button>
                    @endforeach
                @endforeach
            </div>
        </aside>

        <section class="board-mid">
            <div class="draw-toolbar">
                <div class="flex items-center gap-1 min-w-0 overflow-visible">
                    <select id="draw-page" class="border border-nicon-line px-2 py-1 text-sm bg-white min-w-40">
                        <option value="1">Pagina 1</option>
                    </select>
                    <div id="draw-work" class="draw-work-menu">
                        <button type="button" id="draw-work-toggle" class="draw-work-summary" aria-expanded="false" aria-haspopup="true" aria-controls="draw-work-panel">
                            <span id="draw-work-label">Materialen kiezen</span>
                        </button>
                    </div>
                    <div id="draw-work-panel" class="draw-work-panel">
                        <div class="draw-work-panel-head">Materialen selecteren</div>
                        <label class="draw-work-option is-all">
                            <input type="checkbox" data-work-all>
                            <span>Alles selecteren</span>
                        </label>
                        <div id="draw-work-list" class="draw-work-panel-list">
                            @foreach (($board['work_filters'] ?? []) as $work)
                                <label class="draw-work-option">
                                    <input type="checkbox" data-work-key="{{ $work['key'] }}">
                                    <span class="draw-work-option-name">{{ $work['label'] }}</span>
                                    <span class="draw-work-option-qty"></span>
                                </label>
                            @endforeach
                        </div>
                        <div class="draw-work-panel-foot">
                            <div id="draw-work-panel-total" class="draw-work-panel-total">Geselecteerd: 0,00 m²</div>
                            <div class="draw-work-panel-actions">
                                <button type="button" id="draw-work-clear">Wis selectie</button>
                                <button type="button" id="draw-work-apply">Toepassen</button>
                            </div>
                        </div>
                    </div>
                    <span id="draw-work-qty" class="draw-work-qty" aria-live="polite" title="Totaal van de aangevinkte onderdelen op deze verdieping"></span>
                    @if ($canEnterProgress)
                        <button type="button" id="pick-work-rooms" class="room-pick-btn hidden">Alle zichtbare</button>
                    @endif
                    <div class="room-measure-group">
                    <button type="button" id="pick-rooms-btn" class="room-measure-btn" aria-pressed="false">Ruimtes selecteren</button>
                    <div id="room-measure-bar" class="room-measure-bar hidden" aria-live="polite">
                        <span id="room-measure-count">0 ruimtes</span>
                        <span id="room-measure-qty" class="room-measure-qty">0,00 m²</span>
                        <button type="button" id="room-measure-view" aria-expanded="false" aria-controls="room-measure-panel">Selectie bekijken</button>
                        <button type="button" id="room-measure-clear">Wis selectie</button>
                    </div>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" id="draw-hand" class="tool-btn is-on" title="Verschuiven">✋</button>
                    <button type="button" id="draw-select" class="tool-btn" title="Selecteren">➤</button>
                    <button type="button" id="draw-zoom-out" class="tool-btn">−</button>
                    <span id="draw-zoom-label" class="text-xs w-10 text-center">100%</span>
                    <button type="button" id="draw-zoom-in" class="tool-btn">+</button>
                </div>
                <div class="flex items-center gap-1 text-xs">
                    <button type="button" data-layer="rooms" class="layer-btn is-on">Voortgang</button>
                    <button type="button" data-layer="snags" class="layer-btn">Opleverpunten</button>
                    <button type="button" data-layer="both" class="layer-btn">Beide</button>
                </div>
                <div class="ml-auto flex items-center gap-2">
                    <button type="button" id="toggle-rooms" class="board-panel-toggle">Ruimtes</button>
                    <button type="button" id="toggle-tasks" class="board-panel-toggle">Taken</button>
                    <button type="button" id="draw-snag" class="bg-nicon-ink text-white px-2 py-1 text-xs{{ auth()->user()?->canCreateSnags() ? '' : ' hidden' }}">+ Opleverpunt</button>
                </div>
            </div>
            <div id="draw-stage" class="draw-stage">
                @if (! $board['drawing'])
                    <div class="p-6 text-sm text-nicon-muted space-y-4">
                        @if ($project->workItems->isNotEmpty())
                            <div>
                                <div class="text-xs uppercase tracking-wide">Opdrachtregels</div>
                                <ul class="mt-2 space-y-1 text-nicon-ink">
                                    @foreach ($project->workItems as $item)
                                        <li>{{ $item->name }} · {{ \App\Support\Format::qty($item->ordered_quantity) }} {{ $item->unit === \App\Enums\WorkUnit::Pieces ? 'st' : $item->unit->label() }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <div>
                            Nog geen tekening. Upload een plattegrond (PDF).
                            @can('update', $project)
                                <form method="POST" action="{{ route('projects.plattegrond.store', $project) }}" enctype="multipart/form-data" class="mt-3 flex gap-2">
                                    @csrf
                                    <input type="file" name="plattegrond" accept=".pdf,.jpg,.jpeg,.png,.webp" required class="text-xs">
                                    <button class="border border-nicon-line bg-white px-3 py-1">Uploaden</button>
                                </form>
                            @endcan
                        </div>
                        @can('update', $project)
                            <div>
                                <div class="text-xs uppercase tracking-wide">Excel raambekleding / zonwering</div>
                                <form method="POST" action="{{ route('projects.screens.store', $project) }}" enctype="multipart/form-data" class="mt-3 flex gap-2">
                                    @csrf
                                    <input type="file" name="excel" accept=".csv,.txt,.xlsx,.xlsm" required class="text-xs">
                                    <button class="border border-nicon-line bg-white px-3 py-1">Inlezen</button>
                                </form>
                            </div>
                        @endcan
                    </div>
                @endif
                <div id="draw-world">
                    <canvas id="draw-canvas"></canvas>
                    <img id="draw-image" alt="" class="hidden max-w-none">
                    <svg id="draw-hit" viewBox="0 0 1 1" preserveAspectRatio="none"></svg>
                    <div id="draw-markers"></div>
                </div>
                <p id="draw-tip" class="hidden"></p>
                <p id="draw-hint" class="draw-hint hidden"></p>
                <div id="snag-popup" class="snag-popup hidden" role="dialog" aria-labelledby="snag-popup-title" hidden>
                    <button type="button" class="snag-popup-x" id="snag-popup-close" aria-label="Sluiten">×</button>
                    <div class="snag-popup-kicker" id="snag-popup-title">Opleverpunt</div>
                    <div class="snag-popup-status" id="snag-popup-status-label"></div>
                    <div class="snag-popup-grid">
                        <button type="button" id="snag-popup-thumb-btn" class="snag-popup-thumb hidden">
                            <img id="snag-popup-thumb" alt="Constateringfoto">
                        </button>
                        <dl class="snag-popup-meta">
                            <div>
                                <dt>Ruimte</dt>
                                <dd id="snag-popup-area">—</dd>
                            </div>
                            <div>
                                <dt>Omschrijving</dt>
                                <dd id="snag-popup-description">—</dd>
                            </div>
                            <div>
                                <dt>Deadline</dt>
                                <dd id="snag-popup-due">—</dd>
                            </div>
                        </dl>
                    </div>
                    <label class="snag-popup-status-label" for="snag-popup-worker">Vakman / ZZP</label>
                    <select id="snag-popup-worker" class="snag-popup-select">
                        @foreach ($workers as $worker)
                            <option value="{{ $worker->id }}">{{ $worker->displayName() }}</option>
                        @endforeach
                    </select>
                    <label class="snag-popup-status-label" for="snag-popup-status">Status</label>
                    <select id="snag-popup-status" class="snag-popup-select">
                        @foreach (\App\Enums\SnagStatus::cases() as $status)
                            <option value="{{ $status->value }}" @disabled($status === \App\Enums\SnagStatus::Closed && ! auth()->user()?->canCloseSnags())>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                    <div class="snag-popup-photos">
                        <div class="snag-popup-status-label">Foto bij status</div>
                        <div id="snag-popup-thumbs" class="snag-thumbs"></div>
                        <div class="snag-popup-photo-actions">
                            <button type="button" id="snag-popup-camera-btn" class="snag-photo-btn">Foto maken</button>
                            <button type="button" id="snag-popup-library-btn" class="snag-photo-btn is-light">Kiezen</button>
                        </div>
                        <input type="file" id="snag-popup-photo-camera" accept="image/*" capture="environment" multiple class="snag-file">
                        <input type="file" id="snag-popup-photo" accept="image/*" multiple class="snag-file">
                        <p class="snag-popup-photo-hint" id="snag-popup-photo-hint">Optioneel. Bij gereedmelden een gereedfoto; anders een foto bij de nieuwe status.</p>
                    </div>
                    <div class="snag-popup-actions">
                        <button type="button" id="snag-popup-edit" class="snag-popup-btn is-primary">Bekijken / bewerken</button>
                        <button type="button" id="snag-popup-status-btn" class="snag-popup-btn">Status wijzigen</button>
                        <button type="button" id="snag-popup-worker-btn" class="snag-popup-btn">Vakman wijzigen</button>
                        <button type="button" id="snag-popup-move" class="snag-popup-btn">Positie aanpassen</button>
                        <button type="button" id="snag-popup-dismiss" class="snag-popup-btn">Sluiten</button>
                        @if (auth()->user()?->canDeleteSnags())
                            <button type="button" id="snag-popup-delete" class="snag-popup-btn is-danger">Verwijderen</button>
                        @endif
                    </div>
                </div>
            </div>
            <div id="snag-photo-preview" class="snag-photo-preview hidden" hidden>
                <button type="button" id="snag-photo-preview-close" class="snag-photo-preview-close">Sluiten</button>
                <img id="snag-photo-preview-img" alt="">
            </div>
            <div class="draw-legend">
                <span><i class="lg-none"></i> Niets gedaan = niets extra</span>
                <span>E✓ V✓ P✓ = gereed onderdeel onder de kamernaam</span>
                <span><i class="lg-pending"></i> Open kring = voorlopig, wacht op akkoord</span>
                <span><i class="lg-select"></i> Geselecteerde ruimte</span>
                <span><i class="lg-done"></i> Hele ruimte gereed</span>
                <span class="snag-legend"><i class="lg-snag-open"></i> Open</span>
                <span class="snag-legend"><i class="lg-snag-assigned"></i> Toegewezen</span>
                <span class="snag-legend"><i class="lg-snag-progress"></i> In behandeling</span>
                <span class="snag-legend"><i class="lg-snag-wait"></i> Gereed gemeld</span>
                <span class="snag-legend"><i class="lg-snag-done"></i> Afgehandeld</span>
            </div>
        </section>

        <aside class="board-right" id="side-panel">
            <div id="room-panel" class="{{ $canEnterProgress ? '' : 'is-readonly' }}" data-area-id="{{ $first['id'] ?? '' }}">
            <div class="px-4 pt-4 pb-2">
                <div class="text-xs text-nicon-muted" id="room-floor">{{ $first['floor'] ?? '' }}</div>
                <h2 class="text-xl font-semibold" id="room-title">{{ $first['number'] ?? '' }} {{ $first['unique_name'] ?? $first['name'] ?? 'Kies een ruimte' }}</h2>
                <div class="text-sm text-nicon-muted" id="room-m2">{{ $first['m2_label'] ?? '' }}</div>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <div class="text-sm" id="room-progress-label">{{ $first ? $first['progress'].' · '.$first['status_label'] : '' }}</div>
                    @if ($canEnterProgress)
                        <button type="button" id="select-all-tasks" class="text-xs border border-nicon-line bg-white px-2 py-1">Alles aanvinken</button>
                    @endif
                </div>
                <div class="mt-1 h-1.5 bg-nicon-sand"><div id="room-progress-bar" class="h-1.5 bg-nicon-orange" style="width: {{ $first && $first['total'] ? round(100 * $first['done'] / $first['total']) : 0 }}%"></div></div>
                <div id="work-legend" class="work-legend">
                    @foreach ($firstDetail['groups'] ?? [] as $legendGroup)
                        <span data-kind="{{ $legendGroup['color_key'] ?? 'overige' }}" style="--work-accent: {{ $legendGroup['display_color'] ?? '#9ca3af' }}"><i></i>{{ $legendGroup['label'] ?? $legendGroup['color_label'] ?? \App\Support\WorkColor::legendLabel($legendGroup['color_key'] ?? 'overige') }}</span>
                    @endforeach
                </div>
            </div>
            <div id="room-groups" class="flex-1 overflow-auto px-4 py-2 space-y-3">
                @foreach ($firstDetail['groups'] ?? [] as $group)
                    @php
                        $openIds = $group['open_task_ids'] ?? [];
                        $doneIds = $group['done_task_ids'] ?? [];
                        $ids = ! empty($group['done']) ? ($group['task_ids'] ?? $doneIds) : $openIds;
                    @endphp
                    <section class="work-group {{ ! empty($group['done']) ? 'is-done' : (! empty($group['partial']) ? 'is-partial' : '') }}{{ ! empty($group['provisional']) ? ' is-provisional' : '' }}" data-kind="{{ $group['color_key'] ?? 'overige' }}" style="--material-color: {{ $group['display_color'] ?? '#9ca3af' }}; --work-accent: {{ $group['display_color'] ?? '#9ca3af' }}; --work-bg: {{ $group['display_color_soft'] ?? 'rgba(156, 163, 175, 0.14)' }};">
                        <button type="button" class="group-head" data-group="{{ $group['key'] }}" data-label="{{ $group['label'] }}" data-task-ids="{{ implode(',', $ids) }}" data-open-task-ids="{{ implode(',', $openIds) }}" data-done-task-ids="{{ implode(',', $doneIds) }}" data-remaining="{{ $group['remaining'] ?? '' }}" data-ordered="{{ $group['ordered'] ?? '' }}" data-unit="{{ $group['unit'] ?? '' }}" title="{{ $canEnterProgress && ! empty($group['done']) ? (! empty($group['provisional']) && $canApproveProgress ? 'Klik om akkoord te geven' : 'Klik om gereed uit te zetten') : '' }}" @disabled(! $canEnterProgress)>
                            <span class="task-check">{{ ! empty($group['done']) ? '✓' : '' }}</span>
                            <span class="min-w-0 text-left">
                                <span class="block font-medium"><i class="work-swatch" aria-hidden="true"></i>{{ $group['label'] }}</span>
                        <span class="block text-[11px] text-nicon-muted">{{ $group['progress_label'] ?? $group['quantity_label'] ?? ($group['tasks'][0]['progress_label'] ?? $group['tasks'][0]['quantity_label'] ?? '') }}@if (! empty($group['type_label']) && ! str_contains($group['label'], $group['type_label'])) · {{ $group['type_label'] }}@endif · {{ $group['status_label'] ?? 'Open' }}{{ ! empty($group['done']) && ! empty($group['tasks'][0]['worker']) ? ' · '.$group['tasks'][0]['worker'] : '' }}</span>
                            </span>
                            <span class="group-status text-[11px] {{ ! empty($group['done']) && empty($group['provisional']) ? 'text-nicon-ok' : 'text-nicon-muted' }}">{{ $group['status_label'] ?? 'Open' }}</span>
                        </button>
                    </section>
                @endforeach
            </div>
            @if ($canEnterProgress)
                <form id="complete-form" class="border-t border-nicon-line p-3 space-y-2 text-sm bg-white">
                    <div class="text-xs font-medium" id="complete-task-name">Kies een of meer werkzaamheden</div>
                    <p class="text-[11px] text-nicon-muted" id="complete-hint">@if ($lockedWorkerId)Klaar blijft voorlopig tot de projectleider akkoord geeft. Hele verdieping of hele werk aanvinken, daarna egaliseren of een vloertype.@elseif ($canApproveProgress)Hele verdieping of hele werk aanvinken, daarna egaliseren of een vloertype. Voorlopig klaar: klik en geef akkoord. Definitief gereed kun je uitzetten.@else Hele verdieping of hele werk aanvinken, daarna egaliseren of een vloertype. Klik op Gereed om het weer open te zetten.@endif</p>
                    <div class="grid grid-cols-2 gap-2">
                        <select name="worker_id" id="complete-worker" class="border border-nicon-line px-2 py-1" @disabled($lockedWorkerId || $progressWorkers->isEmpty())>
                            @forelse ($progressWorkers as $worker)
                                <option value="{{ $worker->id }}" @selected($lockedWorkerId === $worker->id)>{{ $worker->planName() }}</option>
                            @empty
                                <option value="" disabled selected>Niemand ingepland op dit werk</option>
                            @endforelse
                        </select>
                        <input type="date" name="date" id="complete-date" value="{{ now()->toDateString() }}" class="border border-nicon-line px-2 py-1">
                    </div>
                    <input name="quantity" id="complete-qty" type="number" step="0.01" min="0" placeholder="Aantal" class="w-full border border-nicon-line px-2 py-1">
                    <input name="note" id="complete-note" placeholder="Opmerking" class="w-full border border-nicon-line px-2 py-1">
                    <button class="w-full bg-nicon-ink text-white py-2" id="complete-submit" disabled>{{ $lockedWorkerId ? 'Klaar melden (voorlopig)' : 'Opslaan en verwerken' }}</button>
                    <button type="button" class="w-full border border-nicon-line py-2 hidden" id="complete-reopen" hidden>Weer openzetten</button>
                </form>
            @else
                <div class="border-t border-nicon-line p-3 text-sm bg-white">
                    <div class="text-xs font-medium">Voortgang</div>
                    <p class="mt-1 text-[11px] text-nicon-muted">Alleen ter inzage. Wijzigen kan de vakman, uitvoerder, projectleider of beheerder.</p>
                </div>
            @endif
            </div>

            <div id="snag-panel" class="snag-panel hidden">
                <form id="snag-form" class="flex h-full min-h-0 flex-col">
                    <div class="border-b border-nicon-line px-4 py-3">
                        <div class="text-xs text-nicon-muted" id="snag-status-label">Nieuw</div>
                        <h2 class="text-lg font-semibold leading-tight" id="snag-title">Opleverpunt</h2>
                        <p class="mt-1 text-[11px] text-nicon-muted" id="snag-help">Foto, korte tekst, vakman. Klaar.</p>
                    </div>
                    <div class="flex-1 space-y-2.5 overflow-auto px-4 py-3 text-sm">
                        <p id="snag-error" class="text-sm text-nicon-danger hidden"></p>
                        <label class="block text-[11px] font-medium text-nicon-muted" for="snag-area">Ruimte</label>
                        <select name="project_area_id" id="snag-area" class="w-full border border-nicon-line px-2 py-1.5">
                            <option value="">Niet gekoppeld</option>
                            @foreach ($areas as $area)
                                <option value="{{ $area['id'] }}">{{ trim(($area['number'] ?? '').' '.($area['name'] ?? '')) }}</option>
                            @endforeach
                        </select>
                        <label class="block text-[11px] font-medium text-nicon-muted" for="snag-description">Omschrijving</label>
                        <textarea name="description" id="snag-description" rows="3" required maxlength="500" placeholder="Wat is er aan de hand?" class="w-full border border-nicon-line px-2 py-1.5"></textarea>
                        <div>
                            <div class="mb-1 text-[11px] font-medium text-nicon-muted">Foto’s <span class="font-normal">(aanbevolen)</span></div>
                            <div id="snag-existing-photos" class="snag-thumbs"></div>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <button type="button" id="snag-camera-btn" class="snag-photo-btn">Foto maken</button>
                                <button type="button" id="snag-library-btn" class="snag-photo-btn is-light">Kiezen</button>
                            </div>
                            <input type="file" id="snag-photo-camera" accept="image/*" capture="environment" multiple class="snag-file">
                            <input type="file" id="snag-photo" accept="image/*" multiple class="snag-file">
                            <p class="mt-1 text-[11px] text-nicon-muted" id="snag-photo-hint">Constateringfoto. Op telefoon opent de camera.</p>
                        </div>
                        <label class="block text-[11px] font-medium text-nicon-muted" for="snag-worker">Vakman / ZZP’er</label>
                        <select name="assigned_worker_id" id="snag-worker" required class="w-full border border-nicon-line px-2 py-1.5">
                            <option value="">Kies vakman / ZZP</option>
                            @foreach ($workers as $worker)
                                <option value="{{ $worker->id }}" data-email="{{ $worker->email }}">{{ $worker->displayName() }}</option>
                            @endforeach
                        </select>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[11px] font-medium text-nicon-muted" for="snag-logged">Datum</label>
                                <input type="date" name="logged_on" id="snag-logged" value="{{ now()->toDateString() }}" class="w-full border border-nicon-line px-2 py-1.5">
                            </div>
                            <div>
                                <label class="block text-[11px] font-medium text-nicon-muted" for="snag-due">Gereed uiterlijk</label>
                                <input type="date" name="due_date" id="snag-due" class="w-full border border-nicon-line px-2 py-1.5">
                            </div>
                        </div>
                        <label class="block text-[11px] font-medium text-nicon-muted" for="snag-priority">Prioriteit</label>
                        <select name="priority" id="snag-priority" class="w-full border border-nicon-line px-2 py-1.5">
                            <option value="normal">Normaal</option>
                            <option value="high">Hoog</option>
                            <option value="low">Laag</option>
                        </select>
                        <label class="block text-[11px] font-medium text-nicon-muted" for="snag-status">Status</label>
                        <select name="status" id="snag-status" class="w-full border border-nicon-line px-2 py-1.5" disabled>
                            @foreach (\App\Enums\SnagStatus::cases() as $status)
                                <option value="{{ $status->value }}" @disabled($status === \App\Enums\SnagStatus::Closed && ! auth()->user()?->canCloseSnags())>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        <div id="snag-review" class="hidden space-y-2 border border-nicon-line bg-nicon-sand px-3 py-2">
                            <div class="text-xs font-medium">Wacht op controle</div>
                            <textarea name="review_note" id="snag-review-note" rows="2" placeholder="Opmerking bij afkeuren" class="w-full border border-nicon-line px-2 py-1.5"></textarea>
                            <div class="grid grid-cols-2 gap-2">
                                @if (auth()->user()?->canCloseSnags())
                                    <button type="button" id="snag-approve" class="bg-nicon-ok text-white px-2 py-2 text-xs">Goedkeuren</button>
                                @endif
                                @if (auth()->user()?->canRejectSnags())
                                    <button type="button" id="snag-reject" class="border border-nicon-danger text-nicon-danger px-2 py-2 text-xs">Afkeuren</button>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="space-y-2 border-t border-nicon-line p-3">
                        <button type="button" id="snag-move" class="hidden w-full border border-nicon-line bg-white px-3 py-2 text-xs">Positie aanpassen</button>
                        @if (auth()->user()?->canDeleteSnags())
                            <button type="button" id="snag-delete" class="hidden w-full border border-nicon-danger text-nicon-danger px-3 py-2 text-xs">Verwijderen</button>
                        @endif
                        <div class="flex gap-2">
                            <button type="button" id="snag-cancel" class="flex-1 border border-nicon-line px-3 py-2">Annuleren</button>
                            <button type="submit" class="flex-1 border border-nicon-line bg-white px-3 py-2" id="snag-save">Opslaan</button>
                        </div>
                        <button type="button" id="snag-send" class="w-full bg-nicon-ink text-white px-3 py-2.5">Opslaan &amp; versturen</button>
                    </div>
                </form>
            </div>
        </aside>
    </div>

    <script type="application/json" id="board-data">{!! json_encode($board, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
    <script type="application/json" id="outsource-selection-data">{}</script>
    <div id="room-measure-panel" class="room-measure-panel">
        <div class="room-measure-panel-head">Geselecteerde ruimtes</div>
        <div id="room-measure-rooms" class="room-measure-rooms"></div>
        <div class="room-measure-panel-head">Totalen</div>
        <div id="room-measure-totals" class="room-measure-totals"></div>
    </div>
@endsection
