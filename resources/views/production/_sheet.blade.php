@php
    $worker = $group['worker'];
    $project = $projectGroup['project'];
    $opdracht = $sheet['opdracht'];
    $bons = $sheet['bons'];
    $rows = $sheet['rows'];
    $billing = $sheet['billing'];
    $columnTotals = $sheet['column_totals'];
    $nextLabel = $sheet['next_label'];
    $hasRemaining = ! $billing['fully_settled'];
    $formId = 'bon-'.$worker->id.'-'.$project->id;
    $unitLabel = fn ($unit) => $unit instanceof \App\Enums\WorkUnit ? $unit->label() : '';
    $dash = fn (float $value, bool $money = false) => $value > 0.001
        ? ($money ? \App\Support\Format::money($value) : \App\Support\Format::qty($value, 2))
        : '—';
    $commissionedKeys = collect($rows)->pluck('key')->filter()->all();
    $klaarByKey = [];
    $klaarStatus = [];
    $extraKlaar = [];
    foreach ($projectGroup['rooms'] ?? [] as $room) {
        $areaId = isset($room['area']) && $room['area'] ? (int) $room['area']->id : null;
        foreach ($room['materials'] ?? [] as $material) {
            $itemId = isset($material['work_item_id']) && $material['work_item_id'] ? (int) $material['work_item_id'] : null;
            $key = \App\Models\VoucherLine::lineKey($areaId, $itemId);
            $qty = (float) ($material['quantity'] ?? 0);
            $klaarByKey[$key] = ($klaarByKey[$key] ?? 0) + $qty;
            $klaarStatus[$key] = [
                'provisional' => (bool) ($material['provisional'] ?? false),
                'approved' => (bool) ($material['approved'] ?? false),
                'klaar_on' => $material['klaar_on'] ?? null,
                'task_id' => $material['task_id'] ?? null,
            ];
            if ($qty > 0.001 && ! in_array($key, $commissionedKeys, true)) {
                $extraKlaar[] = [
                    'label' => ($room['label'] ?? 'Zonder ruimte').': '.($material['label'] ?? 'Werk'),
                    'quantity' => $qty,
                    'unit' => $material['unit'] ?? null,
                    'project_area_id' => $areaId,
                    'work_item_id' => $itemId,
                    'provisional' => (bool) ($material['provisional'] ?? false),
                    'klaar_on' => $material['klaar_on'] ?? null,
                ];
            }
        }
    }
@endphp
<div class="voucher-blad px-4 py-4 border-t border-nicon-line bg-white">
    <div class="flex flex-wrap items-start justify-between gap-6">
        <img class="h-12 w-auto" src="{{ asset(config('company.logo')) }}" alt="{{ config('company.name') }}">
        <dl class="grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs uppercase tracking-wide text-nicon-muted">Projectnaam</dt>
                <dd>{{ $project->displayTitle() }}</dd>
            </div>
            @if ($project->workCode())
                <div>
                    <dt class="text-xs uppercase tracking-wide text-nicon-muted">Projectnr.</dt>
                    <dd>{{ $project->workCode() }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-xs uppercase tracking-wide text-nicon-muted">Werk</dt>
                <dd>{{ $project->workNumber() }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-nicon-muted">Projectplaats</dt>
                <dd>{{ $project->city ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-nicon-muted">Onderaannemer</dt>
                <dd>{{ $worker->displayName() }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-nicon-muted">Contactpersoon</dt>
                <dd>{{ $worker->contact_name ?: $worker->name }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-nicon-muted">Datum opdrachtbon</dt>
                <dd>{{ $opdracht->issued_on?->format('d-m-Y') }}</dd>
            </div>
        </dl>
        <div class="min-w-56 border-2 border-nicon-orange px-4 py-3 text-sm">
            <div class="text-xs uppercase tracking-wide text-nicon-muted">Opdrachtbon nr.</div>
            <div class="font-semibold">{{ $opdracht->number }}</div>
            <a class="mt-1 inline-block text-sm text-nicon-orange-dark underline-offset-2 hover:underline no-print" href="{{ route('vouchers.pdf', $opdracht) }}">Download PDF</a>
            <dl class="mt-2 space-y-1">
                <div class="flex justify-between gap-4">
                    <dt>Totaal opdracht</dt>
                    <dd>
                        @if ($billing['opdracht_m2'] > 0)
                            {{ \App\Support\Format::qty($billing['opdracht_m2'], 2) }} m²
                        @else
                            {{ \App\Support\Format::qty(collect($rows)->sum('quantity'), 2) }}
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt>Totaal bedrag</dt>
                    <dd>{{ \App\Support\Format::money($billing['opdracht_amount']) }}</dd>
                </div>
                @if ($sheet['agreed_price'] !== null)
                    <div class="flex justify-between gap-4">
                        <dt>Prijsafspraak</dt>
                        <dd>{{ \App\Support\Format::money($sheet['agreed_price']) }} per {{ $unitLabel($sheet['agreed_unit']) }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>
</div>

<div class="overflow-x-auto">
    <table class="w-full text-sm voucher-sheet">
        <thead class="bg-nicon-sand text-left text-nicon-muted">
            <tr>
                <th class="px-2 py-2 font-medium" rowspan="2">Nr.</th>
                <th class="px-2 py-2 font-medium" rowspan="2">Werkzaamheid / ruimte</th>
                <th class="px-2 py-2 font-medium" rowspan="2">Eenheid</th>
                <th class="px-2 py-2 font-medium text-center" colspan="2">Opdracht</th>
                @foreach ($bons as $index => $bon)
                    <th class="px-2 py-2 font-medium text-center border-l border-nicon-line" colspan="2">
                        <a class="text-nicon-orange-dark underline-offset-2 hover:underline" href="{{ route('vouchers.show', $bon) }}">{{ \App\Support\Format::bonOrdinal($index + 1) }}</a>
                    </th>
                @endforeach
                <th class="px-2 py-2 font-medium text-center border-l border-nicon-line" colspan="2">Totaal ontvangen</th>
                <th class="px-2 py-2 font-medium text-center" colspan="2">Nog te ontvangen</th>
            </tr>
            <tr>
                <th class="px-2 py-2 font-medium text-right">Aantal</th>
                <th class="px-2 py-2 font-medium text-right">Bedrag</th>
                @foreach ($bons as $bon)
                    <th class="px-2 py-2 font-medium text-right border-l border-nicon-line">Aantal</th>
                    <th class="px-2 py-2 font-medium text-right">Bedrag</th>
                @endforeach
                <th class="px-2 py-2 font-medium text-right border-l border-nicon-line">Aantal</th>
                <th class="px-2 py-2 font-medium text-right">Bedrag</th>
                <th class="px-2 py-2 font-medium text-right">Aantal</th>
                <th class="px-2 py-2 font-medium text-right">Bedrag</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $index => $row)
                @php
                    $unit = $row['unit'];
                    $open = $row['remaining_quantity'] > 0.001 || $row['remaining_amount'] > 0.001;
                    $kind = $row['price_kind'] ?? \App\Enums\VoucherPriceKind::Unit;
                    $isFixed = $kind instanceof \App\Enums\VoucherPriceKind ? $kind->isFixed() : $kind === 'fixed';
                @endphp
                <tr class="border-t border-nicon-line align-middle {{ $row['settled'] ? 'text-nicon-muted' : '' }}">
                    <td class="px-2 py-2 whitespace-nowrap">1.{{ $index + 1 }}</td>
                    <td class="px-2 py-2">
                        {{ $row['description'] }}
                        @if ($isFixed)
                            <span class="text-[11px] text-nicon-muted"> · Vaste afgesproken prijs</span>
                        @endif
                        @if ($row['settled'])
                            <span class="text-nicon-ok"> · Volledig afgerekend</span>
                        @endif
                        @php
                            $klaarQty = (float) ($klaarByKey[$row['key'] ?? ''] ?? 0);
                            $klaarMeta = $klaarStatus[$row['key'] ?? ''] ?? [];
                        @endphp
                        @if ($klaarQty > 0.001)
                            <div class="text-[11px] {{ ($klaarMeta['provisional'] ?? false) ? 'text-amber-800' : 'text-nicon-muted' }}">
                                @if ($klaarMeta['provisional'] ?? false)
                                    Klaar gemeld {{ \App\Support\Format::qty($klaarQty, 2) }} {{ $unitLabel($unit) }} · wacht op akkoord
                                @elseif ($klaarMeta['approved'] ?? false)
                                    Akkoord {{ \App\Support\Format::qty($klaarQty, 2) }} {{ $unitLabel($unit) }}
                                @else
                                    klaar {{ \App\Support\Format::qty($klaarQty, 2) }} {{ $unitLabel($unit) }}
                                @endif
                                @if ($klaarMeta['klaar_on'] ?? null)
                                    · {{ $klaarMeta['klaar_on'] }}
                                @endif
                            </div>
                        @endif
                    </td>
                    <td class="px-2 py-2 whitespace-nowrap">{{ $unitLabel($unit) }}</td>
                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ \App\Support\Format::qty($row['quantity'], 2) }}</td>
                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ \App\Support\Format::money($row['amount']) }}</td>
                    @foreach ($row['cells'] as $cell)
                        <td class="px-2 py-2 text-right whitespace-nowrap border-l border-nicon-line">{{ $dash($cell['quantity']) }}</td>
                        <td class="px-2 py-2 text-right whitespace-nowrap">{{ $dash($cell['amount'], true) }}</td>
                    @endforeach
                    <td class="px-2 py-2 text-right whitespace-nowrap border-l border-nicon-line">{{ $dash($row['received_quantity']) }}</td>
                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ $dash($row['received_amount'], true) }}</td>
                    <td @class(['px-2 py-2 text-right whitespace-nowrap', 'sheet-remain' => $open])>{{ $dash($row['remaining_quantity']) }}</td>
                    <td @class(['px-2 py-2 text-right whitespace-nowrap', 'sheet-remain' => $open])>{{ $open ? \App\Support\Format::money($row['remaining_amount']) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="sheet-total">
                <td class="px-2 py-2" colspan="3">Totaal opdracht</td>
                <td class="px-2 py-2 text-right whitespace-nowrap">
                    @if ($billing['opdracht_m2'] > 0)
                        {{ \App\Support\Format::qty($billing['opdracht_m2'], 2) }}
                    @endif
                </td>
                <td class="px-2 py-2 text-right whitespace-nowrap">{{ \App\Support\Format::money($billing['opdracht_amount']) }}</td>
                @foreach ($columnTotals as $total)
                    <td class="px-2 py-2 text-right whitespace-nowrap border-l border-nicon-line">{{ \App\Support\Format::qty($total['quantity'], 2) }}</td>
                    <td class="px-2 py-2 text-right whitespace-nowrap">{{ \App\Support\Format::money($total['amount']) }}</td>
                @endforeach
                <td class="px-2 py-2 text-right whitespace-nowrap border-l border-nicon-line">
                    @if ($billing['invoiced_m2'] > 0 || $billing['opdracht_m2'] > 0)
                        {{ \App\Support\Format::qty($billing['invoiced_m2'], 2) }}
                    @endif
                </td>
                <td class="px-2 py-2 text-right whitespace-nowrap">{{ \App\Support\Format::money($billing['invoiced_amount']) }}</td>
                <td class="px-2 py-2 text-right whitespace-nowrap sheet-remain">
                    @if (! $billing['fully_settled'] && $billing['opdracht_m2'] > 0)
                        {{ \App\Support\Format::qty($billing['remaining_m2'], 2) }}
                    @elseif (! $billing['fully_settled'])
                        {{ \App\Support\Format::qty(collect($rows)->sum('remaining_quantity'), 2) }}
                    @else
                        —
                    @endif
                </td>
                <td class="px-2 py-2 text-right whitespace-nowrap sheet-remain">
                    @if ($billing['fully_settled'])
                        —
                    @else
                        {{ \App\Support\Format::money($billing['remaining_amount']) }}
                    @endif
                </td>
            </tr>
        </tfoot>
    </table>
</div>

@if (count($extraKlaar) > 0)
    <section class="mx-4 mt-3 border border-nicon-line bg-nicon-sand/40 p-3 text-sm">
        <h3 class="text-xs uppercase tracking-wide text-nicon-muted">Klaar – nog niet in opdracht</h3>
        <p class="mt-1 text-xs text-nicon-muted">Dit telt financieel niet mee tot je het, inclusief prijs, aan de opdracht toevoegt.</p>
        <ul class="mt-2 space-y-2">
            @foreach ($extraKlaar as $extra)
                <li class="flex flex-wrap items-baseline justify-between gap-2">
                    <span>
                        {{ $extra['label'] }}
                        <span class="text-nicon-muted">
                            {{ \App\Support\Format::qty($extra['quantity'], 2) }}
                            {{ $unitLabel($extra['unit']) }}
                        </span>
                        @if ($extra['provisional'] ?? false)
                            <span class="ml-2 text-[11px] text-amber-800">Klaar gemeld · wacht op akkoord</span>
                        @endif
                    </span>
                    @if ($canCreateVouchers)
                        <a class="text-nicon-orange-dark underline-offset-2 hover:underline no-print" href="{{ route('vouchers.edit', array_filter([
                            'voucher' => $opdracht,
                            'area' => $extra['project_area_id'],
                            'item' => $extra['work_item_id'],
                            'quantity' => $extra['quantity'],
                        ])) }}">Toevoegen aan opdracht</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif

<div class="grid gap-4 px-4 py-4 border-t border-nicon-line lg:grid-cols-3">
    <section class="border border-nicon-line p-3 text-sm">
        <h3 class="text-xs uppercase tracking-wide text-nicon-muted">Overzicht</h3>
        <table class="mt-2 w-full">
            <tbody>
                <tr>
                    <th class="py-1 text-left font-medium">Totaal opdracht</th>
                    <td class="py-1 text-right whitespace-nowrap">
                        @if ($billing['opdracht_m2'] > 0)
                            {{ \App\Support\Format::qty($billing['opdracht_m2'], 2) }} m²
                        @endif
                    </td>
                    <td class="py-1 text-right whitespace-nowrap">{{ \App\Support\Format::money($billing['opdracht_amount']) }}</td>
                </tr>
                <tr>
                    <th class="py-1 text-left font-medium">Totaal ontvangen</th>
                    <td class="py-1 text-right whitespace-nowrap">
                        @if ($billing['invoiced_m2'] > 0 || $billing['opdracht_m2'] > 0)
                            {{ \App\Support\Format::qty($billing['invoiced_m2'], 2) }} m²
                        @endif
                    </td>
                    <td class="py-1 text-right whitespace-nowrap">{{ \App\Support\Format::money($billing['invoiced_amount']) }}</td>
                </tr>
                <tr>
                    <th class="py-1 text-left font-medium">{{ $billing['fully_settled'] ? 'Status' : 'Nog te ontvangen' }}</th>
                    <td @class(['py-1 text-right whitespace-nowrap', 'sheet-remain' => ! $billing['fully_settled'], 'text-nicon-ok' => $billing['fully_settled']])>
                        @if ($billing['fully_settled'])
                            Volledig afgerekend
                        @elseif ($billing['opdracht_m2'] > 0)
                            {{ \App\Support\Format::qty($billing['remaining_m2'], 2) }} m²
                        @endif
                    </td>
                    <td @class(['py-1 text-right whitespace-nowrap', 'sheet-remain' => ! $billing['fully_settled']])>
                        @if (! $billing['fully_settled'])
                            {{ \App\Support\Format::money($billing['remaining_amount']) }}
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="border border-nicon-line p-3 text-sm">
        <h3 class="text-xs uppercase tracking-wide text-nicon-muted">Bonoverzicht (historie)</h3>
        @if ($bons->isEmpty())
            <p class="mt-2 text-nicon-muted">Nog geen bonnen. Vul hieronder de m² of m¹ in die de vakman mag indienen.</p>
        @else
            <table class="mt-2 w-full">
                <thead class="text-nicon-muted">
                    <tr>
                        <th class="py-1 text-left font-medium">Bon</th>
                        <th class="py-1 text-left font-medium">Datum</th>
                        <th class="py-1 text-right font-medium">Aantal</th>
                        <th class="py-1 text-right font-medium">Bedrag</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bons as $index => $bon)
                        <tr class="border-t border-nicon-line">
                            <td class="py-1">
                                <a class="text-nicon-orange-dark underline-offset-2 hover:underline" href="{{ route('vouchers.show', $bon) }}">{{ \App\Support\Format::bonOrdinal($index + 1) }} · {{ $bon->number }}</a>
                                @if ($canCreateVouchers && filled($worker->email))
                                    <form method="POST" action="{{ route('vouchers.send', $bon) }}" class="mt-0.5 inline no-print">
                                        @csrf
                                        <button type="submit" class="text-xs text-nicon-orange-dark underline-offset-2 hover:underline">Verstuur</button>
                                    </form>
                                @endif
                            </td>
                            <td class="py-1 whitespace-nowrap">{{ $bon->issued_on?->format('d-m-Y') }}</td>
                            <td class="py-1 text-right whitespace-nowrap">{{ \App\Support\Format::qty($columnTotals[$index]['quantity'] ?? 0, 2) }}</td>
                            <td class="py-1 text-right whitespace-nowrap">{{ \App\Support\Format::money($bon->total_amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($canCreateVouchers && $hasRemaining)
        <form id="{{ $formId }}" method="POST" action="{{ route('vouchers.store') }}" class="border border-nicon-line p-3 text-sm">
            @csrf
            <input type="hidden" name="worker_id" value="{{ $worker->id }}">
            <input type="hidden" name="project_id" value="{{ $project->id }}">
            <input type="hidden" name="type" value="facturatie">
            <h3 class="text-xs uppercase tracking-wide text-nicon-muted">Huidige bon ({{ $nextLabel }})</h3>
            @if ($bons->isNotEmpty())
                <p class="mt-1 text-xs text-nicon-muted">Er staat nog rest open. Vul hier zelf in wat deze {{ $nextLabel }} mag, tot het nog openstaande aantal of bedrag. Het veld blijft leeg tot jij typt.</p>
            @else
                <p class="mt-1 text-xs text-nicon-muted">Het opdrachtaantal is het maximum. Vul hier zelf in wat deze keer mag, tot het nog openstaande aantal of bedrag.</p>
            @endif
            <table class="mt-2 w-full">
                <thead class="text-nicon-muted">
                    <tr>
                        <th class="py-1 text-left font-medium">Werkzaamheid</th>
                        <th class="py-1 text-right font-medium">Aantal</th>
                        <th class="py-1 text-left font-medium">Eenheid</th>
                        <th class="py-1 text-right font-medium">Prijs</th>
                        <th class="py-1 text-right font-medium">Bedrag</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $index => $row)
                        @continue($row['settled'])
                        @php
                            $unit = $row['unit'];
                            $kind = $row['price_kind'] ?? \App\Enums\VoucherPriceKind::Unit;
                            $isFixed = $kind instanceof \App\Enums\VoucherPriceKind ? $kind->isFixed() : $kind === 'fixed';
                            $kindValue = $kind instanceof \App\Enums\VoucherPriceKind ? $kind->value : (string) $kind;
                        @endphp
                        <tr class="border-t border-nicon-line align-middle">
                            <td class="py-1 pr-2">
                                {{ $row['description'] }}
                                @if ($isFixed)
                                    <div class="text-[11px] text-nicon-muted">Vaste afgesproken prijs · nog {{ \App\Support\Format::money($row['remaining_amount']) }}</div>
                                @else
                                    <div class="text-[11px] text-nicon-muted">nog {{ \App\Support\Format::qty($row['remaining_quantity'], 2) }} van {{ \App\Support\Format::qty($row['quantity'], 2) }}</div>
                                @endif
                            </td>
                            <td class="py-1">
                                <input type="hidden" name="lines[{{ $index }}][project_area_id]" value="{{ $row['project_area_id'] ?? '' }}">
                                <input type="hidden" name="lines[{{ $index }}][work_item_id]" value="{{ $row['work_item_id'] ?? '' }}">
                                <input type="hidden" name="lines[{{ $index }}][description]" value="{{ $row['description'] }}">
                                <input type="hidden" name="lines[{{ $index }}][unit]" value="{{ $unit instanceof \App\Enums\WorkUnit ? $unit->value : $unit }}">
                                <input type="hidden" name="lines[{{ $index }}][unit_price]" value="{{ $row['unit_price'] }}">
                                <input type="hidden" name="lines[{{ $index }}][price_kind]" value="{{ $kindValue }}">
                                <input type="text" inputmode="decimal" name="lines[{{ $index }}][quantity]" value="" placeholder="max. {{ \App\Support\Format::qty($row['remaining_quantity'], 2) }}" data-kind="{{ $kindValue }}" data-price="{{ $row['unit_price'] }}" data-max="{{ $row['remaining_quantity'] }}" data-max-amount="{{ $row['remaining_amount'] }}" data-ordered="{{ $row['quantity'] }}" data-ordered-amount="{{ $row['amount'] }}" class="sheet-qty w-24 border border-nicon-line px-2 py-1 bg-white text-right">
                            </td>
                            <td class="py-1 whitespace-nowrap">{{ $unitLabel($unit) }}</td>
                            <td class="py-1 text-right whitespace-nowrap">
                                @if ($isFixed)
                                    vast
                                @else
                                    {{ \App\Support\Format::money($row['unit_price']) }}
                                @endif
                            </td>
                            <td class="py-1 text-right whitespace-nowrap">
                                @if ($isFixed)
                                    <input type="text" inputmode="decimal" name="lines[{{ $index }}][amount]" value="" placeholder="max. {{ \App\Support\Format::money($row['remaining_amount']) }}" data-max-amount="{{ $row['remaining_amount'] }}" class="sheet-fixed-amount w-28 border border-nicon-line px-2 py-1 bg-white text-right">
                                @else
                                    <span class="sheet-amount">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr class="sheet-remain font-medium">
                        <td class="py-2" colspan="4">Totaal {{ $nextLabel }}</td>
                        <td class="py-2 text-right whitespace-nowrap" id="{{ $formId }}-total">—</td>
                    </tr>
                </tbody>
            </table>
            <label class="mt-3 block text-xs uppercase tracking-wide text-nicon-muted">Opmerkingen</label>
            <textarea name="notes" rows="2" class="mt-1 w-full border border-nicon-line px-2 py-1">{{ old('notes') }}</textarea>
        </form>
    @elseif ($billing['fully_settled'])
        <section class="border border-nicon-line p-3 text-sm">
            <h3 class="text-xs uppercase tracking-wide text-nicon-muted">Huidige bon</h3>
            <p class="mt-2 text-nicon-ok">Volledig afgerekend. Er is niets meer open.</p>
        </section>
    @else
        <section class="border border-nicon-line p-3 text-sm">
            <h3 class="text-xs uppercase tracking-wide text-nicon-muted">Huidige bon</h3>
            <p class="mt-2 text-nicon-muted">Alleen wie opdrachtbonnen mag maken, kan hier een nieuwe bon invullen.</p>
        </section>
    @endif
</div>

<div class="grid gap-4 px-4 pb-4 lg:grid-cols-3">
    <div class="border border-nicon-line p-3 text-sm">
        <div class="text-xs uppercase tracking-wide text-nicon-muted">Akkoord onderaannemer</div>
        <div class="mt-8 border-t border-nicon-line pt-1 text-nicon-muted">Datum / handtekening</div>
    </div>
    <div class="border border-nicon-line p-3 text-sm">
        <div class="text-xs uppercase tracking-wide text-nicon-muted">Akkoord opdrachtgever</div>
        <div class="mt-8 border-t border-nicon-line pt-1 text-nicon-muted">Datum / handtekening</div>
    </div>
    @if ($canCreateVouchers && $hasRemaining)
        <div class="border-2 border-nicon-orange bg-nicon-orange/5 p-3 text-sm">
            <div class="text-xs uppercase tracking-wide text-nicon-muted">Te betalen {{ $nextLabel }}</div>
            <div class="mt-1 text-2xl font-semibold text-nicon-orange-dark" id="{{ $formId }}-payable">€ 0,00</div>
            <div class="text-xs text-nicon-muted">excl. btw</div>
            <p class="mt-2 text-xs text-nicon-muted">Na opslaan blijf je op dit blad. De rest blijft staan tot je de volgende bon maakt. Mail de bon vanuit het bonoverzicht.</p>
        </div>
    @endif
</div>

@foreach ($billing['warnings'] as $warning)
    <p class="px-4 py-2 text-sm text-nicon-danger">{{ $warning }}</p>
@endforeach

@once
<script>
document.addEventListener('DOMContentLoaded', () => {
    const money = (value) => '€ ' + value.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const number = (value) => Number(String(value).replace(/\s/g, '').replace(',', '.')) || 0;

    document.querySelectorAll('form[id^="bon-"]').forEach((form) => {
        const recount = () => {
            let total = 0;
            form.querySelectorAll('.sheet-qty').forEach((input) => {
                const max = number(input.dataset.max);
                const maxAmount = number(input.dataset.maxAmount);
                const kind = input.dataset.kind || 'unit';
                let qty = number(input.value);
                if (max > 0 && qty > max) {
                    qty = max;
                    input.value = String(max).replace('.', ',');
                }
                const row = input.closest('tr');
                const amountInput = row?.querySelector('.sheet-fixed-amount');
                let amount = qty * number(input.dataset.price);
                if (kind === 'fixed') {
                    amount = number(amountInput?.value);
                    if (amount <= 0 && qty > 0) {
                        const ordered = number(input.dataset.ordered);
                        const orderedAmount = number(input.dataset.orderedAmount);
                        amount = ordered > 0 ? Math.round(qty / ordered * orderedAmount * 100) / 100 : 0;
                    }
                    if (maxAmount > 0 && amount > maxAmount) {
                        amount = maxAmount;
                        if (amountInput) {
                            amountInput.value = String(maxAmount).replace('.', ',');
                        }
                    }
                }
                total += amount;
                const cell = row?.querySelector('.sheet-amount');
                if (cell) {
                    cell.textContent = amount > 0 ? money(amount) : '—';
                }
            });
            const totalCell = document.getElementById(form.id + '-total');
            if (totalCell) {
                totalCell.textContent = total > 0 ? money(total) : '—';
            }
            const payable = document.getElementById(form.id + '-payable');
            if (payable) {
                payable.textContent = money(total);
            }
        };

        form.addEventListener('input', recount);
    });
});
</script>
@endonce
