@php
    $roomLabel = $roomLabel ?? \App\Support\VoucherActivityGroups::roomLabel($line);
    $quantity = $line['quantity'] ?? '';
    $isHours = ($unitValue ?? '') === \App\Enums\WorkUnit::Hours->value;
    $specM2 = $line['spec_m2'] ?? ($isHours ? '' : $quantity);
@endphp
<tr class="voucher-line voucher-room h-6 align-middle" data-group="{{ $groupKey }}" data-spec-m2="{{ $specM2 }}">
    <td class="px-4 py-0 pl-8 text-xs leading-6 text-nicon-muted">
        <input type="hidden" name="lines[{{ $index }}][project_area_id]" value="{{ $line['project_area_id'] ?? '' }}">
        <input type="hidden" name="lines[{{ $index }}][work_item_id]" value="{{ $line['work_item_id'] ?? '' }}">
        <input type="hidden" name="lines[{{ $index }}][description]" value="{{ $activityName }}" class="voucher-activity-description">
        <input type="hidden" name="lines[{{ $index }}][unit]" value="{{ $unitValue }}" class="voucher-unit-value">
        <input type="hidden" name="lines[{{ $index }}][price_kind]" value="{{ $priceKindValue }}" class="voucher-kind-value">
        <input type="hidden" name="lines[{{ $index }}][unit_price]" value="{{ $unitPrice }}" class="voucher-price">
        <input type="hidden" name="lines[{{ $index }}][amount]" value="{{ $line['amount'] ?? '' }}" class="voucher-fixed-amount">
        <input type="text" name="lines[{{ $index }}][room_label]" value="{{ $roomLabel }}" class="w-full min-w-40 border-0 bg-transparent px-0 py-0 text-xs leading-6 text-nicon-muted" placeholder="Ruimte">
    </td>
    <td class="px-4 py-0 whitespace-nowrap">
        <input type="text" inputmode="decimal" name="lines[{{ $index }}][quantity]" value="{{ $quantity }}" class="h-6 w-24 border border-nicon-line bg-white px-1 py-0 text-xs leading-5 voucher-qty {{ $isHours ? 'hidden' : '' }}" placeholder="m²">
        <span class="voucher-room-spec text-xs leading-6 {{ $isHours ? '' : 'hidden' }}">{{ $specM2 !== '' && $specM2 !== null ? \App\Support\Format::qty($specM2, 2).' m²' : '' }}</span>
        @if (isset($line['ordered']))
            <div class="text-[11px] leading-4 text-nicon-muted">nog {{ \App\Support\Format::qty($line['remaining'] ?? $line['quantity'] ?? 0, 2) }} van {{ \App\Support\Format::qty($line['ordered'], 2) }}</div>
        @endif
    </td>
    <td class="px-4 py-0 text-xs leading-6 text-nicon-muted whitespace-nowrap">
        <span class="voucher-room-unit">{{ $isHours ? 'm²' : (\App\Enums\WorkUnit::tryFrom($unitValue)?->label() ?? $unitValue) }}</span>
    </td>
    <td class="px-4 py-0"></td>
    <td class="px-4 py-0"></td>
    <td class="px-4 py-0"></td>
    <td class="px-4 py-0 whitespace-nowrap no-print">
        <button type="button" class="text-[11px] leading-6 text-nicon-muted hover:text-nicon-danger voucher-remove">Verwijderen</button>
    </td>
</tr>
