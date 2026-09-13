Beste {{ $voucher->worker?->displayName() }},

Hierbij de {{ strtolower($voucher->type->label()) }} {{ $voucher->number }} voor {{ $voucher->project?->project_number }} · {{ $voucher->project?->name }} ({{ $voucher->issued_on?->format('d-m-Y') }}).
@if ($attachToInvoice)
Voeg deze bon bij je factuur.
@endif

@foreach (\App\Support\VoucherActivityGroups::fromVoucher($voucher) as $group)
- {{ $group['description'] }}: {{ \App\Support\VoucherActivityGroups::quantityLabel($group) }} × {{ \App\Support\VoucherActivityGroups::priceLabel($group) }} = {{ \App\Support\Format::money($group['amount']) }}
@if ($group['has_rooms'])
@foreach ($group['entries'] as $entry)
  {{ $entry['room_label'] }}: {{ \App\Support\Format::qty($entry['quantity'], 2) }} {{ \App\Support\VoucherActivityGroups::unitLabel($group['unit']) }}
@endforeach
@endif
@endforeach

{{ $attachToInvoice ? 'Totaal te factureren' : 'Totaal' }}: {{ \App\Support\Format::money($voucher->total_amount) }}
@if ($voucher->notes)

Opmerkingen: {{ $voucher->notes }}
@endif

{{ config('company.name') }}
{{ config('company.address') }}, {{ config('company.postal_code') }} {{ config('company.city') }}
{{ config('company.email') }} · {{ config('company.phone') }}
