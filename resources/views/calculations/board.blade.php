@extends('layouts.app')

@section('title', $calculation->name.' · Calculatiebord')
@section('main_class', 'p-0 min-h-0 overflow-hidden')

@push('scripts')
    @vite(['resources/js/calculation-board.js', 'resources/js/calculation-print-dialog.js'])
@endpush

@section('content')
    @php
        $rooms = collect($board['rooms']);
        $groups = $rooms->groupBy(fn ($room) => $room['group'] ?: 'Overig');
        $reviewCount = $rooms->where('review_kind', 'review')->count();
        $manualCount = $rooms->where('review_kind', 'manual')->count();
        $selectedKey = $board['selected_key'] ?? null;
        $materials = $board['materials'] ?? [];
    @endphp

    <div id="calculation-board" class="project-board calc-board" data-selected="{{ $selectedKey }}">
        <header class="board-top">
            <div>
                <a href="{{ route('calculations.index') }}" class="text-xs text-nicon-muted">← Calculatie</a>
                <h1 class="text-lg font-semibold leading-tight">{{ $calculation->name }}</h1>
                <p class="mt-0.5 text-xs text-nicon-muted">
                    {{ $rooms->count() }} ruimtes
                    @if ($reviewCount > 0)
                        · {{ $reviewCount }} controleren
                    @endif
                    @if ($manualCount > 0)
                        · {{ $manualCount }} handmatig
                    @endif
                </p>
            </div>
            @include('calculations.partials.tabs', ['calculation' => $calculation, 'tab' => 'board', 'compact' => true])
        </header>

        <aside class="board-left">
            <div class="px-3 pt-3 pb-2">
                <div class="flex items-center justify-between gap-2">
                    <span id="room-count-label" class="text-xs text-nicon-muted">{{ $rooms->count() }} ruimtes</span>
                </div>
                <div class="mt-2 flex flex-wrap gap-1 text-xs" id="room-filters">
                    <button type="button" data-filter="all" class="room-filter is-on">Alle ruimtes</button>
                    <button type="button" data-filter="review" class="room-filter">Controleren</button>
                    <button type="button" data-filter="manual" class="room-filter">Handmatig aangepast</button>
                    <button type="button" data-filter="floors" class="room-filter">Vloeren</button>
                    <button type="button" data-filter="plinths" class="room-filter">Plinten</button>
                </div>
                <input type="search" id="calc-room-search" placeholder="Zoek ruimtenummer, naam, product of code" class="mt-2 w-full border border-nicon-line px-2 py-1 text-sm">
            </div>
            <div class="overflow-auto flex-1">
                @foreach ($groups as $groupName => $groupRooms)
                    <div class="floor-head sticky top-0">
                        <span>{{ $groupName }}</span>
                    </div>
                    @foreach ($groupRooms as $room)
                        <button
                            type="button"
                            class="room-row calc-room-row {{ $selectedKey === $room['key'] ? 'is-on' : '' }} is-{{ $room['review_kind'] ?? 'review' }}"
                            data-room-key="{{ $room['key'] }}"
                            data-drawing-id="{{ $room['drawing_id'] ?? '' }}"
                            data-review="{{ $room['needs_review'] ? '1' : '0' }}"
                            data-review-kind="{{ $room['review_kind'] ?? '' }}"
                            data-floor="{{ $room['has_floor'] ? '1' : '0' }}"
                            data-plinth="{{ $room['has_plinth'] ? '1' : '0' }}"
                            data-material="{{ $room['material_key'] }}"
                            data-search="{{ $room['search'] }}"
                            style="--material-color: {{ $room['material_color'] }}; --material-color-soft: {{ $room['material_color_soft'] }};"
                            title="{{ $room['number'] }} {{ $room['name'] }} · {{ $room['m2_label'] }} · {{ $room['floor_codes_label'] ?: $room['floor_code'] }}"
                        >
                            <span class="room-num">{{ $room['number'] ?: '—' }}</span>
                            <span class="room-name">{{ $room['name'] ?: '—' }}</span>
                            <span class="room-m2">{{ $room['m2_label'] }}</span>
                            <span class="room-code">{{ $room['floor_codes_label'] ?: ($room['floor_code'] ?: '—') }}</span>
                        </button>
                    @endforeach
                @endforeach
            </div>
            <div id="calc-legend" class="calc-legend">
                @foreach ($materials as $material)
                    <div class="calc-legend-row" data-material-key="{{ $material['key'] }}">
                        <i style="background: {{ $material['color'] }}"></i>
                        <span class="calc-legend-code">{{ $material['code'] }}</span>
                        <span class="calc-legend-product">{{ $material['product'] ?: '—' }}</span>
                        <span class="calc-legend-m2">{{ $material['m2_label'] }}</span>
                    </div>
                @endforeach
            </div>
        </aside>

        <section class="board-mid">
            <div class="draw-toolbar">
                <div class="flex items-center gap-1 min-w-0 overflow-visible">
                    <select id="draw-drawing" class="border border-nicon-line px-2 py-1 text-sm bg-white min-w-40">
                        @forelse ($board['drawings'] as $drawing)
                            <option value="{{ $drawing['id'] }}">{{ $drawing['label'] }}</option>
                        @empty
                            <option value="">Geen tekening</option>
                        @endforelse
                    </select>
                    <select id="draw-page" class="border border-nicon-line px-2 py-1 text-sm bg-white min-w-28">
                        <option value="1">Pagina 1</option>
                    </select>
                    <div id="draw-work" class="draw-work-menu">
                        <button type="button" id="draw-work-toggle" class="draw-work-summary" aria-expanded="false" aria-haspopup="true" aria-controls="draw-work-panel">
                            <span id="draw-work-label">Alle materialen</span>
                        </button>
                    </div>
                    <div id="draw-work-panel" class="draw-work-panel">
                        <div class="draw-work-panel-head">Materialen selecteren</div>
                        <label class="draw-work-option is-all">
                            <input type="checkbox" data-work-all checked>
                            <span>Alle materialen</span>
                        </label>
                        <div id="draw-work-list" class="draw-work-panel-list">
                            @foreach ($materials as $material)
                                <label class="draw-work-option">
                                    <input type="checkbox" data-work-key="{{ $material['key'] }}">
                                    <i class="calc-swatch" style="background: {{ $material['color'] }}"></i>
                                    <span class="draw-work-option-name">{{ $material['label'] }}</span>
                                    <span class="draw-work-option-qty">{{ $material['m2_label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="draw-work-panel-foot">
                            <div id="draw-work-panel-total" class="draw-work-panel-total">Alle materialen</div>
                            <div class="draw-work-panel-actions">
                                <button type="button" id="draw-work-clear">Wis selectie</button>
                            </div>
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
                <div class="ml-auto flex items-center gap-2">
                    <button type="button" class="board-panel-toggle" data-print-open data-print-current-drawing data-print-options-url="{{ route('calculations.print.options', $calculation) }}">Print / PDF</button>
                    <button type="button" id="toggle-rooms" class="board-panel-toggle">Ruimtes</button>
                    <button type="button" id="toggle-tasks" class="board-panel-toggle">Controle</button>
                </div>
            </div>
            <div id="draw-stage" class="draw-stage">
                @if ($board['drawings'] === [])
                    <div class="p-6 text-sm text-nicon-muted">Nog geen PDF-tekening bij deze calculatie.</div>
                @endif
                <div id="draw-world">
                    <canvas id="draw-canvas"></canvas>
                    <img id="draw-image" alt="" class="hidden max-w-none">
                    <svg id="draw-hit" viewBox="0 0 1 1" preserveAspectRatio="none"></svg>
                    <div id="draw-markers"></div>
                </div>
                <p id="draw-tip" class="hidden"></p>
                <p id="draw-hint" class="draw-hint hidden"></p>
            </div>
        </section>

        <aside class="board-right" id="room-panel">
            <div class="room-panel-head">
                <div class="text-xs text-nicon-muted" id="room-drawing"></div>
                <h2 class="text-xl font-semibold" id="room-title">Kies een ruimte</h2>
                <div class="text-sm text-nicon-muted" id="room-m2"></div>
                <div id="room-review-kind" class="room-review-kind" hidden></div>
                <p id="room-material-line" class="mt-1 text-sm"></p>
                <div class="room-progress-row">
                    <div class="text-sm" id="room-progress-label"></div>
                    <div id="room-status" hidden></div>
                </div>
                <div class="room-progress-track"><div id="room-progress-bar" class="room-progress-fill bg-nicon-orange" style="width: 0%"></div></div>
                <div id="work-legend" class="work-legend"></div>
            </div>
            <p id="calc-room-empty" class="px-4 text-sm text-nicon-muted">Klik een ruimte in de lijst of op de tekening.</p>
            <div id="room-groups" class="room-groups"></div>
            <form id="calc-room-form" class="complete-form">
                <div id="calc-room-fields" class="space-y-2">
                    <div class="calc-room-review space-y-2">
                        <label class="block">
                            <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Oppervlakte aanpassen</span>
                            <span class="calc-qty-row">
                                <input name="floor_quantity" inputmode="decimal" class="mt-0.5 border border-nicon-line px-2 py-1">
                                <span class="text-sm text-nicon-muted">m²</span>
                            </span>
                        </label>
                        <div>
                            <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Materiaal kiezen/aanpassen</span>
                            <input type="hidden" name="floor_code">
                            <button type="button" id="calc-open-material" class="calc-open-material mt-0.5">
                                <span id="calc-open-material-swatch" class="calc-swatch" hidden></span>
                                <span id="calc-open-material-label">Materiaal kiezen</span>
                            </button>
                            <p class="mt-1 text-[11px] text-nicon-muted">Dubbelklik op een lege plek om een ontbrekende ruimte aan te maken, met materiaal en m².</p>
                        </div>
                    </div>
                    <details class="calc-room-edit">
                        <summary>Gegevens wijzigen</summary>
                        <div class="mt-2 space-y-2">
                            <label class="block">
                                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Ruimtenummer</span>
                                <input name="room_number" class="mt-0.5 w-full border border-nicon-line px-2 py-1">
                            </label>
                            <label class="block">
                                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Ruimtenaam</span>
                                <input name="room_name" class="mt-0.5 w-full border border-nicon-line px-2 py-1">
                            </label>
                            <div>
                                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Vloerafwerkingen</span>
                                <ul id="floor-finishes" class="mt-1 space-y-1 text-sm"></ul>
                                <p id="floor-finishes-total" class="mt-1 text-xs text-nicon-muted"></p>
                            </div>
                            <label class="block">
                                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Vloerproduct</span>
                                <input name="floor_product" class="mt-0.5 w-full border border-nicon-line px-2 py-1">
                            </label>
                            <label class="block">
                                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Plintcode</span>
                                <input name="plinth_code" class="mt-0.5 w-full border border-nicon-line px-2 py-1">
                            </label>
                            <label class="block">
                                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Plintproduct</span>
                                <input name="plinth_product" class="mt-0.5 w-full border border-nicon-line px-2 py-1">
                            </label>
                            <label class="block">
                                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Plint m¹</span>
                                <input name="plinth_quantity" inputmode="decimal" class="mt-0.5 w-full border border-nicon-line px-2 py-1">
                            </label>
                            <p class="text-[11px] uppercase tracking-wide text-nicon-muted">Status m¹</p>
                            <p id="plinth-status" class="text-xs text-nicon-muted"></p>
                            <p id="pdf-source" class="text-xs text-nicon-muted"></p>
                            <p id="excel-source" class="text-xs text-nicon-muted"></p>
                        </div>
                    </details>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <button type="submit" class="complete-form-submit bg-nicon-ink px-3 py-1.5 text-xs text-white">Opslaan</button>
                        <button type="button" id="calc-confirm" class="complete-form-reopen border border-nicon-line bg-white px-3 py-1.5 text-xs">Bevestigen</button>
                        <button type="button" id="calc-reset-chip" class="border border-nicon-line bg-white px-3 py-1.5 text-xs">Positie herstellen</button>
                        <button type="button" id="calc-restore-auto" class="border border-nicon-line bg-white px-3 py-1.5 text-xs">Automatisch herstellen</button>
                    </div>
                    <p id="calc-room-message" class="text-xs text-nicon-ok hidden"></p>
                    <p id="calc-room-error" class="text-xs text-nicon-danger hidden"></p>
                </div>
            </form>
        </aside>
    </div>

    <script type="application/json" id="board-data">{!! json_encode($board, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
    <dialog id="calc-material-dialog" class="calc-material-dialog" aria-labelledby="calc-material-dialog-title">
        <form method="dialog" class="space-y-3">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="calc-material-dialog-title" class="text-base font-semibold">Materiaal kiezen</h2>
                    <p id="calc-material-dialog-room" class="text-sm text-nicon-muted"></p>
                    <p id="calc-material-dialog-m2" class="text-sm"></p>
                </div>
                <button type="button" id="calc-material-dialog-close" class="text-sm text-nicon-muted">Sluiten</button>
            </div>
            <p id="calc-material-dialog-empty" class="text-sm text-nicon-muted" hidden>Geen materialen in de legenda. Vul hieronder zelf een code in.</p>
            <div id="calc-material-dialog-create" class="calc-material-create" hidden>
                <label class="block min-w-0">
                    <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Ruimtenummer</span>
                    <input id="calc-create-number" class="mt-0.5 w-full border border-nicon-line px-2 py-1" placeholder="K-01-09">
                </label>
                <label class="block min-w-0">
                    <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Ruimtenaam</span>
                    <input id="calc-create-name" class="mt-0.5 w-full border border-nicon-line px-2 py-1" placeholder="KANTOOR MAS">
                </label>
                <label class="block min-w-0">
                    <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Oppervlakte</span>
                    <span class="calc-qty-row">
                        <input id="calc-create-m2" inputmode="decimal" class="mt-0.5 border border-nicon-line px-2 py-1">
                        <span class="text-sm text-nicon-muted">m²</span>
                    </span>
                </label>
            </div>
            <div id="calc-material-dialog-list" class="calc-material-dialog-list"></div>
            <div class="calc-material-manual">
                <label class="block min-w-0">
                    <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Code</span>
                    <input id="calc-material-code" class="mt-0.5 w-full border border-nicon-line px-2 py-1" placeholder="v01e">
                </label>
                <label class="block min-w-0">
                    <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Product</span>
                    <input id="calc-material-product" class="mt-0.5 w-full border border-nicon-line px-2 py-1" placeholder="Marmoleum">
                </label>
                <button type="button" id="calc-material-apply" class="complete-form-submit bg-nicon-ink px-3 py-1.5 text-xs text-white">Toepassen</button>
            </div>
        </form>
    </dialog>
    @include('calculations.partials.print-dialog')
@endsection
