<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Uitnodiging planning</title>
</head>
<body style="font-family: sans-serif; color: #1c1917; margin: 0; padding: 24px; background: #f6f6f7;">
    <div style="max-width: 720px; margin: 0 auto; background: #ffffff; padding: 24px; border: 1px solid #e5e5e5;">
        <p style="margin: 0 0 4px; font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: #c41623;">{{ config('company.name') }}</p>
        <h1 style="font-size: 22px; margin: 0 0 12px;">Uitnodiging voor de planning</h1>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Beste {{ $worker->planName() }},
        </p>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Je team staat in Nicon Planning
            ({{ $worker->employment_type->label() }} · {{ $worker->peopleCountLabel() }}).
            @if ($worker->specialtyLabel())
                Vakkennis: {{ $worker->specialtyLabel() }}.
            @endif
            Log in om je planning te zien.
        </p>
        <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.5;">
            E-mail: {{ $account->email }}
        </p>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Je account voor Nicon Planning is aangemaakt.
            Klik hier om je wachtwoord in te stellen:
            <a href="{{ $activationUrl }}">{{ $activationUrl }}</a>
        </p>
        <p style="margin: 0 0 16px; font-size: 13px; color: #78716c;">
            De link is 24 uur geldig en werkt één keer. Daarna log je in met je e-mailadres.
        </p>
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
