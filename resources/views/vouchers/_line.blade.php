@php
    $unit = $line['unit'] ?? \App\Enums\WorkUnit::SquareMeter;
    $unitValue = $unit instanceof \App\Enums\WorkUnit ? $unit->value : (string) $unit;
    $description = $line['description'] ?? '';
    if (($line['room_label'] ?? '') !== '' && $description !== '' && ! str_contains($description, (string) $line['room_label'])) {
        $description = $line['room_label'].' · '.$description;
    } elseif (($line['room_label'] ?? '') !== '' && $description === '') {
        $description = $line['room_label'];
    }
    $priceSource = $line['price_source'] ?? null;
    if ($priceSource instanceof \App\Enums\VoucherPriceSource) {
        $priceSourceLabel = $priceSource->label();
    } elseif (is_string($priceSource) && $priceSource !== '') {
        $priceSourceLabel = \App\Enums\VoucherPriceSource::tryFrom($priceSource)?->label();
    } else {
        $priceSourceLabel = null;
    }
    $priceKind = $line['price_kind'] ?? \App\Enums\VoucherPriceKind::Unit;
    $priceKindValue = $priceKind instanceof \App\Enums\VoucherPriceKind ? $priceKind->value : (string) $priceKind;
    if ($priceKindValue === '') {
        $priceKindValue = \App\Enums\VoucherPriceKind::Unit->value;
    }
@endphp
<tr class="border-t border-nicon-line align-middle voucher-line">
    <td class="px-4 py-2">
        <input type="hidden" name="lines[{{ $index }}][project_area_id]" value="{{ $line['project_area_id'] ?? '' }}">
        <input type="hidden" name="lines[{{ $index }}][work_item_id]" value="{{ $line['work_item_id'] ?? '' }}">
        <input type="text" name="lines[{{ $index }}][description]" value="{{ $description }}" class="w-full min-w-48 border border-nicon-line px-2 py-1 bg-white" placeholder="Omschrijving, bijv. Primen & Egaliseren">
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <input type="text" inputmode="decimal" name="lines[{{ $index }}][quantity]" value="{{ $line['quantity'] ?? '' }}" class="w-24 border border-nicon-line px-2 py-1 bg-white voucher-qty" placeholder="max. opdracht">
        @if (isset($line['ordered']))
            <div class="text-[11px] text-nicon-muted">nog {{ \App\Support\Format::qty($line['remaining'] ?? $line['quantity'] ?? 0, 2) }} van {{ \App\Support\Format::qty($line['ordered'], 2) }}</div>
        @endif
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <select name="lines[{{ $index }}][unit]" class="border border-nicon-line px-2 py-1 bg-white">
            @foreach (\App\Enums\WorkUnit::cases() as $unitOption)
                <option value="{{ $unitOption->value }}" @selected($unitValue === $unitOption->value)>{{ $unitOption->label() }}</option>
            @endforeach
        </select>
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <select name="lines[{{ $index }}][price_kind]" class="border border-nicon-line px-2 py-1 bg-white voucher-kind">
            @foreach (\App\Enums\VoucherPriceKind::cases() as $kindOption)
                <option value="{{ $kindOption->value }}" @selected($priceKindValue === $kindOption->value)>{{ $kindOption->label() }}</option>
            @endforeach
        </select>
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <div class="flex items-center gap-1 voucher-unit-price-wrap {{ $priceKindValue === 'fixed' ? 'hidden' : '' }}">
            <span class="text-nicon-muted">€</span>
            <input type="text" inputmode="decimal" name="lines[{{ $index }}][unit_price]" value="{{ $line['unit_price'] ?? '' }}" class="w-24 border border-nicon-line px-2 py-1 bg-white voucher-price" placeholder="0,00">
        </div>
        @if ($priceSourceLabel)
            <div class="text-[11px] text-nicon-muted">{{ $priceSourceLabel }}</div>
        @endif
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <div class="flex items-center gap-1 {{ $priceKindValue === 'fixed' ? '' : 'hidden' }}">
            <span class="text-nicon-muted">€</span>
            <input type="text" inputmode="decimal" name="lines[{{ $index }}][amount]" value="{{ $line['amount'] ?? '' }}" class="w-24 border border-nicon-line px-2 py-1 bg-white voucher-fixed-amount" placeholder="0,00">
        </div>
        <div class="text-right text-sm voucher-amount {{ $priceKindValue === 'fixed' ? 'hidden' : '' }}">{{ \App\Support\Format::money($line['amount'] ?? 0) }}</div>
    </td>
    <td class="px-4 py-2 whitespace-nowrap">
        <button type="button" class="text-nicon-muted hover:text-nicon-danger voucher-remove">Verwijderen</button>
    </td>
</tr>
