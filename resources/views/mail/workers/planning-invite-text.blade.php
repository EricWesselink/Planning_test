Beste {{ $worker->planName() }},

Je team staat in Nicon Planning ({{ $worker->employment_type->label() }} · {{ $worker->peopleCountLabel() }}).
@if ($worker->specialtyLabel())
Vakkennis: {{ $worker->specialtyLabel() }}.
@endif
Log in om je planning te zien.

Inloggen: {{ $loginUrl }}
E-mail: {{ $account->email }}
Tijdelijk wachtwoord: {{ $temporaryPassword }}

Wijzig dit wachtwoord na de eerste keer inloggen.

{{ config('company.name') }}
