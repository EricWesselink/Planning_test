@php
    $worker = $group['worker'];
    $project = $projectGroup['project'];
    $opdracht = $sheet['opdracht'];
    $bons = $sheet['bons'];
    $rows = $sheet['rows'];
    $billing = $sheet['billing'];
    $columnTotals = $sheet['column_totals'];
    $hasRemaining = ! $billing['fully_settled'];
    $formId = 'bon-'.$worker->id.'-'.$project->id;
    $unitLabel = fn ($unit) => $unit instanceof \App\Enums\WorkUnit ? $unit->label() : '';
    $dash = fn (float $value, bool $money = false) => $value > 0.001
        ? ($money ? \App\Support\Format::money($value) : \App\Support\Format::qty($value, 2))
        : '—';
    $commissionedRoomKeys = collect($rows)->flatMap(fn (array $row) => $row['room_keys'] ?? [])->filter()->all();
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
            if ($qty > 0.001 && ! in_array($key, $commissionedRoomKeys, true)) {
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
    $agreedUnit = $sheet['agreed_unit'] ?? null;
    $filters = $filters ?? ['from' => null, 'to' => null];
@endphp

@if ($canCreateVouchers && $hasRemaining)
<form id="{{ $formId }}" method="POST" action="{{ route('vouchers.store') }}">
    @csrf
    <input type="hidden" name="worker_id" value="{{ $worker->id }}">
    <input type="hidden" name="project_id" value="{{ $project->id }}">
    <input type="hidden" name="type" value="facturatie">
@endif
    <div class="overflow-x-auto">
        <table class="w-full min-w-[48rem] text-sm voucher-sheet">
            <thead class="border-y border-nicon-line bg-nicon-paper text-left text-[11px] tracking-wide text-nicon-muted">
                <tr>
                    <th class="px-2 py-1 font-medium">Werkzaamheid</th>
                    <th class="px-2 py-1 text-right font-medium">Opdracht</th>
                    <th class="px-2 py-1 text-right font-medium">Op bon</th>
                    <th class="px-2 py-1 text-right font-medium">Deze bon</th>
                    <th class="px-2 py-1 text-right font-medium">Open</th>
                    <th class="px-2 py-1 text-right font-medium">Prijs</th>
                    <th class="px-2 py-1 text-right font-medium">Bedrag</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $index => $row)
                    @php
                        $unit = $row['unit'];
                        $open = $row['remaining_quantity'] > 0.001 || $row['remaining_amount'] > 0.001;
                        $kind = $row['price_kind'] ?? \App\Enums\VoucherPriceKind::Unit;
                        $isFixed = $kind instanceof \App\Enums\VoucherPriceKind ? $kind->isFixed() : $kind === 'fixed';
                        $kindValue = $kind instanceof \App\Enums\VoucherPriceKind ? $kind->value : (string) $kind;
                        $rooms = $row['rooms'] ?? [];
                    @endphp
                    <tr class="border-t border-nicon-line {{ $row['settled'] ? 'text-nicon-muted' : '' }}">
                        <td class="px-2 py-1 align-middle">
                            <div class="font-medium leading-tight text-nicon-ink">{{ $row['description'] }}</div>
                            @if ($isFixed)
                                <div class="text-[11px] leading-tight text-nicon-muted">Vaste afgesproken prijs</div>
                            @endif
                            @if ($row['settled'])
                                <div class="text-[11px] leading-tight text-nicon-ok">Volledig afgerekend</div>
                            @endif
                            @if ($rooms !== [])
                                <div class="text-[11px] leading-tight text-nicon-muted">
                                    @foreach ($rooms as $room)
                                        @php
                                            $klaarQty = (float) ($klaarByKey[$room['key'] ?? ''] ?? 0);
                                            $klaarMeta = $klaarStatus[$room['key'] ?? ''] ?? [];
                                        @endphp
                                        <span>
                                            {{ $room['label'] }} – {{ \App\Support\Format::qty($room['quantity'], 2) }} {{ \App\Support\VoucherActivityGroups::roomSpecUnitLabel($unit) }}
                                            @if ($klaarQty > 0.001)
                                                <span class="{{ ($klaarMeta['provisional'] ?? false) ? 'text-amber-800' : '' }}">
                                                    · klaar {{ \App\Support\Format::qty($klaarQty, 2) }} {{ \App\Support\VoucherActivityGroups::roomSpecUnitLabel($unit) }}
                                                    @if ($klaarMeta['provisional'] ?? false)
                                                        · wacht op akkoord
                                                    @endif
                                                    @if ($klaarMeta['klaar_on'] ?? null)
                                                        · {{ $klaarMeta['klaar_on'] }}
                                                    @endif
                                                </span>
                                            @endif
                                        </span>
                                        @if (! $loop->last)
                                            <span> · </span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-2 py-1 text-right align-middle">{{ \App\Support\Format::qty($row['quantity'], 2) }} {{ $unitLabel($unit) }}</td>
                        <td class="whitespace-nowrap px-2 py-1 text-right align-middle">{{ $dash($row['received_quantity']) }}</td>
                        <td class="whitespace-nowrap px-2 py-1 text-right align-middle">
                            @if ($canCreateVouchers && $open)
                                <input type="hidden" name="lines[{{ $index }}][project_area_id]" value="{{ $row['project_area_id'] ?? '' }}">
                                <input type="hidden" name="lines[{{ $index }}][work_item_id]" value="{{ $row['work_item_id'] ?? '' }}">
                                <input type="hidden" name="lines[{{ $index }}][description]" value="{{ $row['description'] }}">
                                <input type="hidden" name="lines[{{ $index }}][unit]" value="{{ $unit instanceof \App\Enums\WorkUnit ? $unit->value : $unit }}">
                                <input type="hidden" name="lines[{{ $index }}][unit_price]" value="{{ $row['unit_price'] }}">
                                <input type="hidden" name="lines[{{ $index }}][price_kind]" value="{{ $kindValue }}">
                                <input type="text" inputmode="decimal" name="lines[{{ $index }}][quantity]" value="" placeholder="max. {{ \App\Support\Format::qty($row['remaining_quantity'], 2) }}" data-kind="{{ $kindValue }}" data-price="{{ $row['unit_price'] }}" data-unit="{{ $unitLabel($unit) }}" data-max="{{ $row['remaining_quantity'] }}" data-max-amount="{{ $row['remaining_amount'] }}" data-ordered="{{ $row['quantity'] }}" data-ordered-amount="{{ $row['amount'] }}" class="sheet-qty h-7 w-20 border border-nicon-line bg-white px-1.5 text-right focus:border-nicon-orange focus:outline-none focus:ring-1 focus:ring-nicon-orange">
                            @else
                                —
                            @endif
                        </td>
                        <td @class(['whitespace-nowrap px-2 py-1 text-right align-middle', 'sheet-remain' => $open])>{{ $dash($row['remaining_quantity']) }}</td>
                        <td class="whitespace-nowrap px-2 py-1 text-right align-middle">
                            @if ($isFixed)
                                vast
                            @else
                                {{ \App\Support\Format::money($row['unit_price']) }}
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-2 py-1 text-right align-middle">
                            @if ($canCreateVouchers && $open && $isFixed)
                                <input type="text" inputmode="decimal" name="lines[{{ $index }}][amount]" value="" placeholder="max. {{ \App\Support\Format::money($row['remaining_amount']) }}" data-max-amount="{{ $row['remaining_amount'] }}" class="sheet-fixed-amount h-7 w-24 border border-nicon-line bg-white px-1.5 text-right focus:border-nicon-orange focus:outline-none focus:ring-1 focus:ring-nicon-orange">
                            @elseif ($canCreateVouchers && $open)
                                <span class="sheet-amount">—</span>
                            @else
                                {{ $open ? \App\Support\Format::money($row['remaining_amount']) : '—' }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($canCreateVouchers && $hasRemaining)
        <div class="flex flex-wrap items-end justify-between gap-3 px-3 py-2 text-sm">
            @php
                $klaarFallback = collect($klaarStatus)
                    ->pluck('klaar_on')
                    ->filter()
                    ->map(function ($value) {
                        try {
                            return \Carbon\Carbon::createFromFormat('d-m-Y', (string) $value)->toDateString();
                        } catch (\Throwable) {
                            return null;
                        }
                    })
                    ->filter()
                    ->first();
            @endphp
            @include('vouchers._worked_period', [
                'workedFallbackDate' => $klaarFallback ?? ($filters['from'] ?? null),
            ])
            <details @if (old('notes')) open @endif>
                <summary class="cursor-pointer text-nicon-orange-dark select-none">+ Opmerking toevoegen</summary>
                <textarea name="notes" rows="2" class="mt-1 w-full min-w-72 border border-nicon-line px-2 py-1">{{ old('notes') }}</textarea>
            </details>
            <div class="ml-auto whitespace-nowrap text-nicon-ink" id="{{ $formId }}-payable">Deze bon: —</div>
        </div>
    @elseif ($billing['fully_settled'])
        <p class="px-3 py-1 text-sm text-nicon-ok">Volledig afgerekend</p>
    @endif
@if ($canCreateVouchers && $hasRemaining)
</form>
@endif

@if (count($extraKlaar) > 0)
    <div class="flex flex-col gap-0.5 px-3 py-1 text-sm">
        <div class="text-[11px] text-nicon-muted">Klaar – nog niet in opdracht</div>
        @foreach ($extraKlaar as $extra)
            <div class="flex flex-wrap items-baseline gap-2 leading-6">
                <span>
                    {{ $extra['label'] }}
                    <span class="text-nicon-muted">
                        {{ \App\Support\Format::qty($extra['quantity'], 2) }}
                        {{ $unitLabel($extra['unit']) }}
                    </span>
                    @if ($extra['provisional'] ?? false)
                        <span class="text-[11px] text-amber-800">Klaar gemeld · wacht op akkoord</span>
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
            </div>
        @endforeach
    </div>
@endif

@if ($bons->isNotEmpty())
    <div class="mx-3 mb-2 flex flex-col gap-1 text-[13px]">
        <div class="text-[11px] text-nicon-muted">Bonnen</div>
        @foreach ($bons as $index => $bon)
            @php
                $bonQty = (float) ($columnTotals[$index]['quantity'] ?? 0);
                $bonUnit = $agreedUnit instanceof \App\Enums\WorkUnit ? $agreedUnit->label() : $unitLabel($agreedUnit);
            @endphp
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border border-nicon-line bg-nicon-paper px-2 py-1">
                <a class="font-medium text-nicon-ink underline-offset-2 hover:underline" href="{{ route('vouchers.show', $bon) }}">{{ $bon->type->label() }} {{ $bon->number }}</a>
                <span class="text-nicon-muted">
                    {{ \App\Support\Format::qty($bonQty, 2) }}{{ $bonUnit !== '' ? ' '.$bonUnit : '' }}
                    · {{ \App\Support\Format::money($bon->total_amount) }}
                    · {{ $bon->issued_on?->format('d-m-Y') }}
                </span>
                <span class="ml-auto flex flex-wrap items-center gap-2 no-print">
                    <a class="inline-flex h-7 items-center border border-nicon-line bg-white px-2 text-xs text-nicon-ink" href="{{ route('vouchers.pdf', $bon) }}">PDF</a>
                    @if ($canCreateVouchers)
                        <form method="POST" action="{{ route('vouchers.destroy', $bon) }}" class="inline" onsubmit="return confirm({{ json_encode($bon->type->label().' '.$bon->number.' wordt verwijderd.') }})">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex h-7 items-center border border-nicon-line bg-white px-2 text-xs text-nicon-danger">Verwijderen</button>
                        </form>
                        @if (filled($worker->email))
                            <form method="POST" action="{{ route('vouchers.send', $bon) }}" class="inline">
                                @csrf
                                <button type="submit" class="inline-flex h-7 items-center border border-nicon-line bg-white px-2 text-xs text-nicon-ink">Verstuur</button>
                            </form>
                        @endif
                    @endif
                </span>
            </div>
        @endforeach
    </div>
@endif

@foreach ($billing['warnings'] as $warning)
    <p class="px-3 py-1 text-sm text-nicon-danger">{{ $warning }}</p>
@endforeach

@once
<script>
document.addEventListener('DOMContentLoaded', () => {
    const money = (value) => '€ ' + value.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const qtyText = (value) => value.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const number = (value) => Number(String(value).replace(/\s/g, '').replace(',', '.')) || 0;

    document.querySelectorAll('form[id^="bon-"]').forEach((form) => {
        const recount = () => {
            let total = 0;
            const filled = [];
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
                if (qty > 0 || amount > 0) {
                    filled.push({
                        qty,
                        amount,
                        price: number(input.dataset.price),
                        unit: input.dataset.unit || '',
                        kind,
                    });
                }
            });
            const payable = document.getElementById(form.id + '-payable');
            if (payable) {
                if (total <= 0) {
                    payable.textContent = 'Deze bon: —';
                } else if (filled.length === 1 && filled[0].kind !== 'fixed' && filled[0].qty > 0) {
                    const unit = filled[0].unit ? ' ' + filled[0].unit : '';
                    payable.textContent = 'Deze bon: ' + qtyText(filled[0].qty) + unit + ' × ' + money(filled[0].price) + ' = ' + money(total);
                } else {
                    payable.textContent = 'Deze bon: ' + money(total);
                }
            }
        };

        form.addEventListener('input', recount);
    });
});
</script>
@endonce
