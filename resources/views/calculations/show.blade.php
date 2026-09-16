@extends('layouts.app')

@section('title', $calculation->name.' · Calculatie')

@php
    $oldById = [];
    foreach (old('lines', []) as $oldRow) {
        if (is_array($oldRow) && isset($oldRow['id'])) {
            $oldById[(int) $oldRow['id']] = $oldRow;
        }
    }
    $lineIndex = 0;
@endphp

@section('content')
    <div id="calculation-review">
        <a href="{{ route('calculations.index') }}" class="text-sm text-nicon-muted">← Calculatie</a>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">{{ $calculation->name }}</h1>
                <p class="text-sm text-nicon-muted">{{ $roomCount }} ruimtes gevonden | {{ $certainCount }} zeker{{ ($generousCount ?? 0) > 0 ? ' | '.$generousCount.' berekend ruim' : '' }}{{ ($estimatedCount ?? 0) > 0 ? ' | '.$estimatedCount.' geschat ruim' : '' }} | {{ $reviewCount }} controleren</p>
                <p class="text-sm text-nicon-muted">{{ $plinthMetersCount ?? 0 }} van {{ $plinthLinkedCount ?? 0 }} plinten met m¹{{ isset($plinthNetCount) ? ' ('.$plinthNetCount.' exact, '.$plinthGenerousCount.' berekend ruim, '.$plinthEstimatedCount.' geschat ruim, '.$plinthMissingMetersCount.' zonder)' : '' }}</p>
                @if ($readyForExcel)
                    <p class="mt-1 text-sm text-nicon-ok">✓ Calculatie volledig gecontroleerd</p>
                @endif
            </div>
            @include('calculations.partials.excel-export')
        </div>
        @include('calculations.partials.tabs', ['calculation' => $calculation, 'tab' => 'regels'])

        @if (session('status'))
            <p class="mt-3 text-sm text-nicon-ok">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <ul class="mt-3 list-disc pl-5 text-sm text-nicon-danger">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @if (($calculation->warnings ?? []) !== [])
            <ul class="mt-3 list-disc pl-5 text-sm text-nicon-warn">
                @foreach ($calculation->warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif

        <div class="mt-3 flex flex-wrap gap-2 text-sm">
            @foreach ($calculation->drawings as $drawing)
                <a href="{{ route('calculations.drawings.show', [$calculation, $drawing]) }}" class="border border-nicon-line bg-white px-2 py-1 text-xs" target="_blank" rel="noopener">
                    {{ $drawing->original_filename }}
                </a>
            @endforeach
            @foreach ($calculation->workbooks as $workbook)
                <a href="{{ route('calculations.workbooks.show', [$calculation, $workbook]) }}" class="border border-nicon-line bg-white px-2 py-1 text-xs" target="_blank" rel="noopener">
                    {{ $workbook->original_filename }}
                    @if ($workbook->status === 'pending')
                        <span class="text-nicon-warn">niet automatisch gelezen</span>
                    @endif
                </a>
            @endforeach
            @if ($calculation->workbooks->isNotEmpty())
                @if ($calculation->workbooks->contains(fn ($workbook) => $workbook->status === 'pending'))
                    <a href="{{ route('calculations.workbooks.edit', $calculation) }}" class="border border-nicon-warn bg-amber-50 px-2 py-1 text-xs">Mapping aanpassen</a>
                @else
                    <a href="{{ route('calculations.workbooks.edit', $calculation) }}" class="border border-nicon-line bg-white px-2 py-1 text-xs text-nicon-muted">Geavanceerd / Mapping</a>
                @endif
            @endif
        </div>

        <form method="POST" action="{{ route('calculations.workbooks.store', $calculation) }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-end gap-2">
            @csrf
            <div>
                <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="extra-workbooks">Excel toevoegen</label>
                <input id="extra-workbooks" type="file" name="workbooks[]" accept=".xlsx,.xlsm,.xls,.csv,.txt" multiple class="mt-0.5 text-xs">
            </div>
            <button class="border border-nicon-line bg-white px-3 py-1 text-xs">Toevoegen</button>
        </form>

        <form method="POST" action="{{ route('calculations.update', $calculation) }}" class="mt-4">
            @csrf
            @method('PATCH')
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="name">Naam</label>
                    <input id="name" name="name" value="{{ old('name', $calculation->name) }}" required class="mt-0.5 w-full border border-nicon-line px-2 py-1 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="client_name">Opdrachtgever</label>
                    <input id="client_name" name="client_name" value="{{ old('client_name', $calculation->client_name) }}" class="mt-0.5 w-full border border-nicon-line px-2 py-1 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="project_name">Project / werk</label>
                    <input id="project_name" name="project_name" value="{{ old('project_name', $calculation->project_name) }}" class="mt-0.5 w-full border border-nicon-line px-2 py-1 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="dated_on">Datum</label>
                    <input id="dated_on" type="date" name="dated_on" value="{{ old('dated_on', $calculation->dated_on?->toDateString()) }}" required class="mt-0.5 w-full border border-nicon-line px-2 py-1 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="status">Status</label>
                    <select id="status" name="status" class="mt-0.5 w-full border border-nicon-line px-2 py-1 text-sm">
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(old('status', $calculation->status->value) === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto border border-nicon-line bg-white">
                <table class="w-full text-xs">
                    <caption class="px-2 py-1.5 text-left text-[11px] text-nicon-muted">Som meegenomen ruimtes: {{ \App\Support\Format::qty($squareMeters, 2) }} m²</caption>
                    <thead class="bg-nicon-ink text-left text-white">
                        <tr>
                            <th class="px-2 py-1.5 font-medium">Code</th>
                            <th class="px-2 py-1.5 font-medium">Product</th>
                            <th class="px-2 py-1.5 text-right font-medium">Totaal</th>
                            <th class="px-2 py-1.5 font-medium">Eenheid</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($totals as $total)
                        <tr class="border-t border-nicon-line">
                            <td class="px-2 py-1">{{ $total['product_code'] ?: '—' }}</td>
                            <td class="px-2 py-1">{{ $total['product'] ?: '—' }}</td>
                            <td class="px-2 py-1 text-right tabular-nums">{{ \App\Support\Format::qty($total['quantity'], 2) }}</td>
                            <td class="px-2 py-1">{{ $total['unit_label'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-2 py-3 text-nicon-muted">Nog geen hoeveelheden om op te tellen.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
                <div class="flex flex-wrap gap-1" role="group" aria-label="Filter">
                    <button type="button" data-filter="all" class="room-filter is-on">Alles</button>
                    <button type="button" data-filter="review" class="room-filter">Controleren</button>
                    <button type="button" data-filter="floors" class="room-filter">Vloeren</button>
                    <button type="button" data-filter="plinths" class="room-filter">Plinten</button>
                </div>
                <input type="search" data-room-search placeholder="Zoek ruimte, nummer, product of code" class="min-w-56 grow border border-nicon-line px-2 py-1 text-sm">
            </div>

            <div class="mt-2 overflow-x-auto border border-nicon-line bg-white">
                <table class="w-full min-w-[58rem] text-xs">
                    <thead class="bg-nicon-ink text-left text-white">
                        <tr>
                            <th class="px-1.5 py-1.5 font-medium">Ruimte</th>
                            <th class="px-1.5 py-1.5 font-medium">Naam</th>
                            <th class="px-1.5 py-1.5 text-right font-medium">m²</th>
                            <th class="px-1.5 py-1.5 font-medium">Vloercode</th>
                            <th class="px-1.5 py-1.5 font-medium">Vloerproduct</th>
                            <th class="px-1.5 py-1.5 font-medium">Plintcode</th>
                            <th class="px-1.5 py-1.5 font-medium">Plintproduct</th>
                            <th class="px-1.5 py-1.5 text-right font-medium">m¹</th>
                            <th class="px-1.5 py-1.5 font-medium">Status</th>
                            <th class="px-1.5 py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($roomRows as $row)
                        @php
                            $floor = $row['floor'];
                            $plinth = $row['plinth'];
                            $floors = $row['floors'] ?? array_values(array_filter([$floor]));
                            $extraFloors = [];
                            foreach ($floors as $finish) {
                                if ($floor && (int) $finish->id === (int) $floor->id) {
                                    continue;
                                }
                                $extraFloors[] = $finish;
                            }
                            $floorOld = $floor ? ($oldById[$floor->id] ?? []) : [];
                            $plinthOld = $plinth ? ($oldById[$plinth->id] ?? []) : [];
                            $floorIndex = null;
                            $plinthIndex = null;
                            $extraFloorIndexes = [];
                            if ($floor) {
                                $floorIndex = $lineIndex;
                                $lineIndex++;
                            }
                            foreach ($extraFloors as $finish) {
                                $extraFloorIndexes[$finish->id] = $lineIndex;
                                $lineIndex++;
                            }
                            if ($plinth) {
                                $plinthIndex = $lineIndex;
                                $lineIndex++;
                            }
                        @endphp
                        @php
                            $rowClass = $row['status'] === \App\Enums\CheckStatus::NotApplicable
                                ? 'text-nicon-muted'
                                : ($row['status']->isGreen()
                                    ? ''
                                    : 'bg-amber-50 shadow-[inset_3px_0_0_var(--color-nicon-warn)]');
                        @endphp
                        <tr
                            class="border-t border-nicon-line {{ $rowClass }}"
                            data-calc-room="1"
                            data-review="{{ $row['needs_review'] ? '1' : '0' }}"
                            data-floor="{{ $row['has_floor'] ? '1' : '0' }}"
                            data-plinth="{{ $row['has_plinth'] ? '1' : '0' }}"
                            data-search="{{ $row['search'] }}"
                        >
                            <td class="px-1.5 py-1">
                                @if ($floor)
                                    @include('calculations.partials.line-hidden', ['line' => $floor, 'oldLine' => $floorOld, 'index' => $floorIndex, 'unit' => \App\Enums\WorkUnit::SquareMeter])
                                    <input name="lines[{{ $floorIndex }}][room_number]" data-mirror="room_number" value="{{ $floorOld['room_number'] ?? $floor->room_number }}" class="w-[4.5rem] border border-nicon-line px-1 py-0.5">
                                @elseif ($plinth)
                                    @include('calculations.partials.line-hidden', ['line' => $plinth, 'oldLine' => $plinthOld, 'index' => $plinthIndex, 'unit' => \App\Enums\WorkUnit::LinearMeter])
                                    <input name="lines[{{ $plinthIndex }}][room_number]" value="{{ $plinthOld['room_number'] ?? $plinth->room_number }}" class="w-[4.5rem] border border-nicon-line px-1 py-0.5">
                                @endif
                            </td>
                            <td class="px-1.5 py-1">
                                @if ($floor)
                                    <input name="lines[{{ $floorIndex }}][room_name]" data-mirror="room_name" value="{{ $floorOld['room_name'] ?? $floor->room_name }}" class="w-32 border border-nicon-line px-1 py-0.5">
                                @elseif ($plinth)
                                    <input name="lines[{{ $plinthIndex }}][room_name]" value="{{ $plinthOld['room_name'] ?? $plinth->room_name }}" class="w-32 border border-nicon-line px-1 py-0.5">
                                @endif
                            </td>
                            <td class="px-1.5 py-1">
                                @if ($floor)
                                    <input name="lines[{{ $floorIndex }}][quantity]" value="{{ \App\Support\Format::qtyInput($floorOld['quantity'] ?? $floor->quantity) }}" inputmode="decimal" class="w-16 border border-nicon-line px-1 py-0.5 text-right tabular-nums">
                                    @if ($row['excel_quantity'] !== null)
                                        <div class="text-[10px] {{ ($row['excel_area_mismatch'] ?? false) || $row['excel_conflict'] ? 'text-nicon-warn' : 'text-nicon-muted' }}">
                                            @if ($row['excel_conflict'])
                                                Afwijking Excel<br>
                                                Excel takeoff: {{ \App\Support\Format::qty($row['excel_quantity'], 2) }} m²
                                            @else
                                                Excel {{ \App\Support\Format::qty($row['excel_quantity'], 2) }}
                                            @endif
                                        </div>
                                    @endif
                                @endif
                            </td>
                            <td class="px-1.5 py-1">
                                @if ($floor)
                                    <input name="lines[{{ $floorIndex }}][product_code]" @if (($row['floor_variants'] ?? []) !== []) data-floor-code @endif value="{{ $floorOld['product_code'] ?? $floor->product_code }}" class="w-14 border border-nicon-line px-1 py-0.5">
                                    @if (filled($row['excel_product_code']))
                                        <div class="text-[10px] {{ $row['excel_code_conflict'] ? 'text-nicon-warn' : 'text-nicon-muted' }}">Excel {{ $row['excel_product_code'] }}</div>
                                    @endif
                                @endif
                            </td>
                            <td class="relative overflow-visible px-1.5 py-1">
                                @if ($floor && ($row['floor_variants'] ?? []) !== [])
                                    @php
                                        $chosenCode = mb_strtolower(trim((string) ($floorOld['product_code'] ?? $floor->product_code)));
                                        $chosenProduct = (string) ($floorOld['product'] ?? $floor->product);
                                    @endphp
                                    <input type="hidden" name="lines[{{ $floorIndex }}][product]" data-floor-product value="{{ $chosenProduct }}">
                                    <div class="min-w-[18rem] max-w-[24rem]">
                                        <div class="text-[10px] leading-tight text-nicon-muted">{{ $floor->product_code }} gevonden op tekening</div>
                                        <select data-floor-variant="1" class="mt-0.5 w-full border border-nicon-line bg-white px-1 py-0.5" aria-label="Kies vloerproduct">
                                            <option value="" disabled @selected($chosenCode === '' || $chosenCode === mb_strtolower(trim((string) $floor->product_code)))>Kies vloerproduct</option>
                                            @foreach ($row['floor_variants'] as $variant)
                                                <option
                                                    value="{{ $variant['code'] }}"
                                                    data-product="{{ $variant['product'] }}"
                                                    @selected($chosenCode === $variant['code'])
                                                >{{ $variant['code'] }} – {{ $variant['product'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @elseif ($floor)
                                    <input name="lines[{{ $floorIndex }}][product]" value="{{ $floorOld['product'] ?? $floor->product }}" class="w-44 border border-nicon-line px-1 py-0.5">
                                @endif
                            </td>
                            <td class="px-1.5 py-1">
                                @if ($plinth && $floor)
                                    @include('calculations.partials.line-hidden', ['line' => $plinth, 'oldLine' => $plinthOld, 'index' => $plinthIndex, 'unit' => \App\Enums\WorkUnit::LinearMeter])
                                    <input type="hidden" name="lines[{{ $plinthIndex }}][room_number]" data-mirror-target="room_number" value="{{ $plinthOld['room_number'] ?? $plinth->room_number }}">
                                    <input type="hidden" name="lines[{{ $plinthIndex }}][room_name]" data-mirror-target="room_name" value="{{ $plinthOld['room_name'] ?? $plinth->room_name }}">
                                    <input name="lines[{{ $plinthIndex }}][product_code]" value="{{ $plinthOld['product_code'] ?? $plinth->product_code }}" class="w-14 border border-nicon-line px-1 py-0.5">
                                @elseif ($plinth)
                                    <input name="lines[{{ $plinthIndex }}][product_code]" value="{{ $plinthOld['product_code'] ?? $plinth->product_code }}" class="w-14 border border-nicon-line px-1 py-0.5">
                                @elseif ($floor && ($row['can_skip_plinth'] ?? false))
                                    <span class="text-[10px] text-nicon-muted">{{ ($row['plinth_not_applicable'] ?? false) ? 'n.v.t.' : '' }}</span>
                                @endif
                            </td>
                            <td class="px-1.5 py-1">
                                @if ($plinth)
                                    <input name="lines[{{ $plinthIndex }}][product]" value="{{ $plinthOld['product'] ?? $plinth->product }}" class="w-36 border border-nicon-line px-1 py-0.5">
                                @endif
                            </td>
                            <td class="px-1.5 py-1">
                                @if ($floor && ($row['can_skip_plinth'] ?? false))
                                    @php
                                        $skipPlinth = filter_var(
                                            $floorOld['plinth_not_applicable'] ?? $row['plinth_not_applicable'],
                                            FILTER_VALIDATE_BOOLEAN,
                                        );
                                    @endphp
                                    <input type="hidden" name="lines[{{ $floorIndex }}][plinth_not_applicable]" value="0">
                                    <label class="flex items-start gap-1 text-[10px] leading-tight text-nicon-muted">
                                        <input type="checkbox" name="lines[{{ $floorIndex }}][plinth_not_applicable]" value="1" class="mt-0.5 size-3.5 border-nicon-line" @checked($skipPlinth)>
                                        <span>Geen plint van toepassing</span>
                                    </label>
                                @elseif ($plinth)
                                    <input name="lines[{{ $plinthIndex }}][quantity]" value="{{ \App\Support\Format::qtyInput($plinthOld['quantity'] ?? $plinth->quantity) }}" inputmode="decimal" class="w-16 border border-nicon-line px-1 py-0.5 text-right tabular-nums" title="{{ $row['trace'] }}">
                                @endif
                            </td>
                            <td class="px-1.5 py-1 whitespace-nowrap {{ $row['status'] === \App\Enums\CheckStatus::NotApplicable ? 'text-nicon-muted' : ($row['status']->isGreen() ? 'font-medium text-nicon-ok' : 'font-medium text-nicon-warn') }}" title="{{ $row['trace'] }}">
                                {{ $row['status_label'] }}
                                @if ($row['trace'])
                                    <div class="max-w-40 truncate font-normal text-[10px] text-nicon-muted">{{ $row['trace'] }}</div>
                                @endif
                            </td>
                            <td class="px-1.5 py-1 whitespace-nowrap text-right">
                                <a href="{{ route('calculations.board', [$calculation, 'room' => $row['key']]) }}" class="text-nicon-orange-dark">Bekijk op tekening</a>
                                @if ($row['can_confirm'])
                                    <button form="confirm-line-{{ ($floor ?? $plinth)->id }}" class="ml-2 text-nicon-ok">Bevestigen</button>
                                @endif
                                @if ($floor)
                                    <button form="delete-line-{{ $floor->id }}" class="text-nicon-danger" title="Vloerregel verwijderen">×</button>
                                @endif
                                @if ($plinth)
                                    <button form="delete-line-{{ $plinth->id }}" class="ml-1 text-nicon-danger" title="Plintregel verwijderen">×</button>
                                @endif
                            </td>
                        </tr>
                        @foreach ($extraFloors as $extraFloor)
                            @php
                                $extraIndex = $extraFloorIndexes[$extraFloor->id];
                                $extraOld = $oldById[$extraFloor->id] ?? [];
                            @endphp
                            <tr
                                class="border-t border-nicon-line/60 {{ $row['status'] === \App\Enums\CheckStatus::NotApplicable ? 'text-nicon-muted' : ($row['status']->isGreen() ? '' : 'bg-amber-50') }}"
                                data-calc-room="1"
                                data-review="{{ $row['needs_review'] ? '1' : '0' }}"
                                data-floor="1"
                                data-plinth="0"
                                data-search="{{ $row['search'] }}"
                            >
                                <td class="px-1.5 py-1">
                                    @include('calculations.partials.line-hidden', ['line' => $extraFloor, 'oldLine' => $extraOld, 'index' => $extraIndex, 'unit' => \App\Enums\WorkUnit::SquareMeter])
                                    <input type="hidden" name="lines[{{ $extraIndex }}][room_number]" data-mirror-target="room_number" value="{{ $extraOld['room_number'] ?? $extraFloor->room_number }}">
                                    <input type="hidden" name="lines[{{ $extraIndex }}][room_name]" data-mirror-target="room_name" value="{{ $extraOld['room_name'] ?? $extraFloor->room_name }}">
                                </td>
                                <td class="px-1.5 py-1 text-[10px] text-nicon-muted">Deelvlak</td>
                                <td class="px-1.5 py-1">
                                    <input name="lines[{{ $extraIndex }}][quantity]" value="{{ \App\Support\Format::qtyInput($extraOld['quantity'] ?? $extraFloor->quantity) }}" inputmode="decimal" class="w-16 border border-nicon-line px-1 py-0.5 text-right tabular-nums">
                                </td>
                                <td class="px-1.5 py-1">
                                    <input name="lines[{{ $extraIndex }}][product_code]" value="{{ $extraOld['product_code'] ?? $extraFloor->product_code }}" class="w-14 border border-nicon-line px-1 py-0.5">
                                </td>
                                <td class="px-1.5 py-1">
                                    <input name="lines[{{ $extraIndex }}][product]" value="{{ $extraOld['product'] ?? $extraFloor->product }}" class="w-44 border border-nicon-line px-1 py-0.5">
                                </td>
                                <td colspan="3"></td>
                                <td class="px-1.5 py-1 text-[10px] text-nicon-muted">Deelvlak</td>
                                <td class="px-1.5 py-1 text-right">
                                    <button form="delete-line-{{ $extraFloor->id }}" class="text-nicon-danger" title="Deelvlak verwijderen">×</button>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="10" class="px-3 py-8 text-center text-nicon-muted">Nog geen regels. Voeg handmatig toe of lees een tekening opnieuw in.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <button class="bg-nicon-ink px-4 py-2 text-sm text-white">Controle opslaan</button>
                <button form="confirm-complete-rooms" class="border border-nicon-line bg-white px-4 py-2 text-sm">Alles bevestigen (alleen volledige regels)</button>
                <button form="add-calculation-line" class="border border-nicon-line bg-white px-4 py-2 text-sm">Handmatige regel toevoegen</button>
            </div>
        </form>

        @foreach ($calculation->drawings as $drawing)
            @if (($drawing->legend ?? []) !== [])
                <details class="mt-4 border border-nicon-line bg-white p-3 text-sm">
                    <summary class="cursor-pointer text-xs uppercase tracking-wide text-nicon-muted">Renvooi {{ $drawing->original_filename }}</summary>
                    <ul class="mt-2 columns-1 gap-x-6 text-xs sm:columns-2 lg:columns-3">
                        @foreach ($drawing->legend as $entry)
                            <li class="break-inside-avoid py-0.5"><span class="font-medium">{{ $entry['code'] ?? '' }}</span> = {{ $entry['product'] ?? '' }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endforeach
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const root = document.getElementById('calculation-review');
            if (!root) {
                return;
            }
            const rows = [...root.querySelectorAll('[data-calc-room]')];
            const search = root.querySelector('[data-room-search]');
            let filter = 'all';
            const apply = () => {
                const query = (search?.value || '').trim().toLowerCase();
                rows.forEach((row) => {
                    const matchFilter = filter === 'all'
                        || (filter === 'review' && row.dataset.review === '1')
                        || (filter === 'floors' && row.dataset.floor === '1')
                        || (filter === 'plinths' && row.dataset.plinth === '1');
                    const haystack = row.dataset.search || '';
                    row.hidden = !(matchFilter && (query === '' || haystack.includes(query)));
                });
            };
            root.querySelectorAll('[data-filter]').forEach((button) => {
                button.addEventListener('click', () => {
                    filter = button.dataset.filter || 'all';
                    root.querySelectorAll('[data-filter]').forEach((item) => {
                        item.classList.toggle('is-on', item === button);
                    });
                    apply();
                });
            });
            search?.addEventListener('input', apply);
            root.querySelectorAll('[data-calc-room]').forEach((row) => {
                row.querySelectorAll('[data-mirror]').forEach((input) => {
                    input.addEventListener('input', () => {
                        const name = input.dataset.mirror;
                        row.querySelectorAll(`[data-mirror-target="${name}"]`).forEach((target) => {
                            target.value = input.value;
                        });
                    });
                });
            });
            root.querySelectorAll('[data-floor-variant]').forEach((select) => {
                select.addEventListener('change', () => {
                    const option = select.selectedOptions[0];
                    const row = select.closest('[data-calc-room]');
                    const form = select.closest('form');
                    const code = option?.value || '';
                    const product = option?.dataset.product || '';
                    if (code === '') {
                        return;
                    }
                    const codeInput = row?.querySelector('[data-floor-code]');
                    const productInput = row?.querySelector('[data-floor-product]');
                    const noteInput = row?.querySelector('input[name*="[note]"]');
                    if (codeInput) {
                        codeInput.value = code;
                    }
                    if (productInput) {
                        productInput.value = product;
                    }
                    if (noteInput && /variant ontbreekt/i.test(noteInput.value)) {
                        noteInput.value = noteInput.value.replace(/Exacte\s+\S*variant ontbreekt\.?\s*/gi, '').trim();
                    }
                    form?.submit();
                });
            });
        });
    </script>
@endpush

@push('detached-forms')
    <form id="add-calculation-line" method="POST" action="{{ route('calculations.lines.store', $calculation) }}">
        @csrf
    </form>
    <form id="confirm-complete-rooms" method="POST" action="{{ route('calculations.confirm-complete', $calculation) }}">
        @csrf
    </form>
    @foreach ($calculation->lines as $line)
        <form id="delete-line-{{ $line->id }}" method="POST" action="{{ route('calculations.lines.destroy', [$calculation, $line]) }}" onsubmit="return confirm('Deze regel verwijderen?')">
            @csrf
            @method('DELETE')
        </form>
        <form id="confirm-line-{{ $line->id }}" method="POST" action="{{ route('calculations.lines.confirm', [$calculation, $line]) }}">
            @csrf
        </form>
    @endforeach
@endpush
