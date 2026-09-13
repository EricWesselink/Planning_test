@php
    $itemId = $group['work_item_id'];
    $unit = $group['unit'] ?? \App\Enums\WorkUnit::SquareMeter;
    $unitValue = $unit instanceof \App\Enums\WorkUnit ? $unit->value : (string) $unit;
    $priceKind = $group['price_kind'] ?? \App\Enums\VoucherPriceKind::Unit;
    $priceKindValue = $priceKind instanceof \App\Enums\VoucherPriceKind ? $priceKind->value : (string) $priceKind;
    if ($priceKindValue === '') {
        $priceKindValue = \App\Enums\VoucherPriceKind::Unit->value;
    }
    $priceSource = $group['price_source'] ?? null;
    if ($priceSource instanceof \App\Enums\VoucherPriceSource) {
        $priceSourceLabel = $priceSource->label();
    } elseif (is_string($priceSource) && $priceSource !== '') {
        $priceSourceLabel = \App\Enums\VoucherPriceSource::tryFrom($priceSource)?->label();
    } else {
        $priceSourceLabel = null;
    }
    $pricePrefix = 'activity_prices['.$itemId.']['.$unitValue.']';
    $isHours = $unitValue === \App\Enums\WorkUnit::Hours->value;
@endphp
<tr class="border-t border-nicon-line align-middle voucher-group" data-group="{{ $group['key'] }}">
    <td class="px-4 py-2">
        <input type="text" name="{{ $pricePrefix }}[description]" value="{{ $group['description'] }}" class="w-full min-w-48 border border-nicon-line px-2 py-1 bg-white voucher-activity-name" placeholder="Omschrijving, bijv. Primen & Egaliseren">
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <div class="voucher-group-qty tabular-nums {{ $isHours ? 'hidden' : '' }}">{{ \App\Support\Format::qty($group['quantity'], 2) }}</div>
        <input type="text" inputmode="decimal" name="{{ $pricePrefix }}[quantity]" value="{{ $isHours ? $group['quantity'] : '' }}" class="voucher-hours-qty w-24 border border-nicon-line px-2 py-1 bg-white {{ $isHours ? '' : 'hidden' }}" placeholder="uren">
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <select name="{{ $pricePrefix }}[unit]" class="border border-nicon-line px-2 py-1 bg-white voucher-unit">
            @foreach (\App\Enums\WorkUnit::cases() as $unitOption)
                <option value="{{ $unitOption->value }}" @selected($unitValue === $unitOption->value)>{{ $unitOption->label() }}</option>
            @endforeach
        </select>
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <select name="{{ $pricePrefix }}[price_kind]" class="border border-nicon-line px-2 py-1 bg-white voucher-kind">
            @foreach (\App\Enums\VoucherPriceKind::cases() as $kindOption)
                <option value="{{ $kindOption->value }}" @selected($priceKindValue === $kindOption->value)>{{ $kindOption->label() }}</option>
            @endforeach
        </select>
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <div class="flex items-center gap-1 voucher-unit-price-wrap {{ $priceKindValue === 'fixed' ? 'hidden' : '' }}">
            <span class="text-nicon-muted">€</span>
            <input type="text" inputmode="decimal" name="{{ $pricePrefix }}[unit_price]" value="{{ $group['unit_price'] ?? '' }}" class="w-24 border border-nicon-line px-2 py-1 bg-white voucher-price" placeholder="0,00">
        </div>
        @if ($priceSourceLabel)
            <div class="text-[11px] text-nicon-muted">{{ $priceSourceLabel }}</div>
        @endif
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <div class="flex items-center gap-1 {{ $priceKindValue === 'fixed' ? '' : 'hidden' }}">
            <span class="text-nicon-muted">€</span>
            <input type="text" inputmode="decimal" name="{{ $pricePrefix }}[amount]" value="{{ $group['amount'] ?? '' }}" class="w-24 border border-nicon-line px-2 py-1 bg-white voucher-fixed-amount" placeholder="0,00">
        </div>
        <div class="text-right text-sm voucher-amount {{ $priceKindValue === 'fixed' ? 'hidden' : '' }}">{{ \App\Support\Format::money($group['amount'] ?? 0) }}</div>
    </td>
    <td class="px-4 py-2 whitespace-nowrap no-print">
        <button type="button" class="text-nicon-muted hover:text-nicon-danger voucher-remove-group">Verwijderen</button>
    </td>
</tr>
@foreach ($group['entries'] as $entry)
    @include('vouchers._room', [
        'index' => $entry['index'],
        'line' => $entry['line'],
        'groupKey' => $group['key'],
        'activityName' => $group['description'],
        'unitValue' => $unitValue,
        'priceKindValue' => $priceKindValue,
        'unitPrice' => $group['unit_price'] ?? '',
        'roomLabel' => $entry['room_label'],
    ])
@endforeach
