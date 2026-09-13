@php
    $formLines = old('lines', $formLines ?? []);
    if ($formLines === []) {
        $formLines = [[
            'description' => '',
            'quantity' => '',
            'unit' => \App\Enums\WorkUnit::SquareMeter->value,
            'unit_price' => '',
            'amount' => 0,
            'price_kind' => \App\Enums\VoucherPriceKind::Unit->value,
        ]];
    }
    $groups = \App\Support\VoucherActivityGroups::fromFormLines($formLines);
@endphp
<div class="overflow-x-auto border border-nicon-line bg-white">
    <table class="w-full text-sm" id="voucher-table">
        <thead class="text-left text-nicon-muted bg-nicon-sand">
            <tr>
                <th class="px-4 py-2 font-medium">Onderdeel</th>
                <th class="px-4 py-2 font-medium">Hoeveelheid</th>
                <th class="px-4 py-2 font-medium">Eenheid</th>
                <th class="px-4 py-2 font-medium">Prijssoort</th>
                <th class="px-4 py-2 font-medium">Prijs</th>
                <th class="px-4 py-2 font-medium">Bedrag</th>
                <th class="px-4 py-2 font-medium no-print"></th>
            </tr>
        </thead>
        <tbody id="voucher-lines">
            @foreach ($groups as $group)
                @if ($group['has_rooms'])
                    @include('vouchers._group', ['group' => $group])
                @else
                    @include('vouchers._line', ['index' => $group['entries'][0]['index'], 'line' => $group['entries'][0]['line']])
                @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr class="border-t border-nicon-line font-medium">
                <td class="px-4 py-3" colspan="5">Totaal</td>
                <td class="px-4 py-3 text-right" id="voucher-total">{{ \App\Support\Format::money(collect($groups)->sum('amount')) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
@if ($canAddLines ?? true)
<div class="mt-3">
    <button type="button" class="border border-nicon-line bg-white px-3 py-2 text-sm" id="voucher-add-line">Onderdeel toevoegen</button>
</div>
<template id="voucher-line-template">
    @include('vouchers._line', ['index' => '__INDEX__', 'line' => [
        'description' => '',
        'quantity' => '',
        'unit' => \App\Enums\WorkUnit::SquareMeter->value,
        'unit_price' => '',
        'amount' => 0,
        'price_kind' => \App\Enums\VoucherPriceKind::Unit->value,
    ]])
</template>
@endif
