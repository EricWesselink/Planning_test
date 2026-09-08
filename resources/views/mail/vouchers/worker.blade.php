<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $voucher->type->label() }} {{ $voucher->number }}</title>
</head>
<body style="font-family: sans-serif; color: #1c1917; margin: 0; padding: 24px; background: #fbf8f3;">
    <div style="max-width: 720px; margin: 0 auto; background: #ffffff; padding: 24px; border: 1px solid #e7e0d4;">
        <p style="margin: 0 0 4px; font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: #e4572e;">{{ config('company.name') }}</p>
        <h1 style="font-size: 22px; margin: 0 0 12px;">{{ $voucher->type->label() }} {{ $voucher->number }}</h1>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Beste {{ $voucher->worker?->displayName() }},
        </p>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Hierbij de {{ strtolower($voucher->type->label()) }} voor
            {{ $voucher->project?->project_number }} · {{ $voucher->project?->name }}
            ({{ $voucher->issued_on?->format('d-m-Y') }}).
            @if ($attachToInvoice)
                Voeg deze bon bij je factuur.
            @endif
        </p>

        <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin: 0 0 16px;">
            <thead>
                <tr>
                    <th style="text-align: left; padding: 8px 6px; border-bottom: 1px solid #e7e0d4; color: #78716c;">Werkzaamheid</th>
                    <th style="text-align: right; padding: 8px 6px; border-bottom: 1px solid #e7e0d4; color: #78716c;">Aantal</th>
                    <th style="text-align: right; padding: 8px 6px; border-bottom: 1px solid #e7e0d4; color: #78716c;">Prijs</th>
                    <th style="text-align: right; padding: 8px 6px; border-bottom: 1px solid #e7e0d4; color: #78716c;">Bedrag</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($voucher->lines as $line)
                    <tr>
                        <td style="padding: 8px 6px; border-bottom: 1px solid #e7e0d4;">{{ $line->description }}</td>
                        <td style="padding: 8px 6px; border-bottom: 1px solid #e7e0d4; text-align: right; white-space: nowrap;">{{ \App\Support\Format::qty($line->quantity, 2) }} {{ $line->unit?->label() }}</td>
                        <td style="padding: 8px 6px; border-bottom: 1px solid #e7e0d4; text-align: right; white-space: nowrap;">{{ \App\Support\Format::money($line->unit_price) }}</td>
                        <td style="padding: 8px 6px; border-bottom: 1px solid #e7e0d4; text-align: right; white-space: nowrap;">{{ \App\Support\Format::money($line->amount) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="3" style="padding: 8px 6px; font-weight: 700; border-top: 2px solid #1c1917;">
                        {{ $attachToInvoice ? 'Totaal te factureren' : 'Totaal' }}
                    </td>
                    <td style="padding: 8px 6px; font-weight: 700; border-top: 2px solid #1c1917; text-align: right; white-space: nowrap;">{{ \App\Support\Format::money($voucher->total_amount) }}</td>
                </tr>
            </tbody>
        </table>

        @if ($voucher->notes)
            <p style="margin: 0 0 16px; font-size: 13px;"><strong>Opmerkingen:</strong> {{ $voucher->notes }}</p>
        @endif

        <p style="margin: 0; font-size: 12px; color: #78716c;">
            {{ config('company.name') }}
            · {{ config('company.address') }}
            · {{ config('company.postal_code') }} {{ config('company.city') }}
            · {{ config('company.email') }}
            · {{ config('company.phone') }}
        </p>
    </div>
</body>
</html>
