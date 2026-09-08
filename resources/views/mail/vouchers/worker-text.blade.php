Beste {{ $voucher->worker?->displayName() }},

Hierbij de {{ strtolower($voucher->type->label()) }} {{ $voucher->number }} voor {{ $voucher->project?->project_number }} · {{ $voucher->project?->name }} ({{ $voucher->issued_on?->format('d-m-Y') }}).
@if ($attachToInvoice)
Voeg deze bon bij je factuur.
@endif

@foreach ($voucher->lines as $line)
- {{ $line->description }}: {{ \App\Support\Format::qty($line->quantity, 2) }} {{ $line->unit?->label() }} × {{ \App\Support\Format::money($line->unit_price) }} = {{ \App\Support\Format::money($line->amount) }}
@endforeach

{{ $attachToInvoice ? 'Totaal te factureren' : 'Totaal' }}: {{ \App\Support\Format::money($voucher->total_amount) }}
@if ($voucher->notes)

Opmerkingen: {{ $voucher->notes }}
@endif

{{ config('company.name') }}
{{ config('company.address') }}, {{ config('company.postal_code') }} {{ config('company.city') }}
{{ config('company.email') }} · {{ config('company.phone') }}
