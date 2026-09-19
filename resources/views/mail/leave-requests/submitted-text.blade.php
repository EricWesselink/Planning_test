{{ $leaveRequest->workerName() }} heeft vrij aangevraagd.

Periode:
{{ $leaveRequest->shortPeriodLabel() }}

Aantal werkdagen: {{ $leaveRequest->workdayCount() }}
@if (filled($leaveRequest->note))
Opmerking: {{ $leaveRequest->note }}
@endif

Aanvraag bekijken: {{ route('leave-requests.show', $leaveRequest) }}

Goedkeuren gebeurt in Nicon Planning na inloggen.
