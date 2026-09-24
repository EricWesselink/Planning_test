Beste {{ $worker->planName() }},

Je team staat in Nicon Planning ({{ $worker->employment_type->label() }} · {{ $worker->peopleCountLabel() }}).
@if ($worker->specialtyLabel())
Vakkennis: {{ $worker->specialtyLabel() }}.
@endif
Log in om je planning te zien.

Je account voor Nicon Planning is aangemaakt.
Klik hier om je wachtwoord in te stellen:
{{ $activationUrl }}

De link is 24 uur geldig en werkt één keer.

{{ config('company.name') }}
