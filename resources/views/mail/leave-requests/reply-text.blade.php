{{ $leaveRequest->workerName() }} heeft geantwoord op de vrij-aanvraag voor {{ $leaveRequest->periodLabel() }}.

Bericht van {{ $leaveMessage->user?->name }}:
{{ $leaveMessage->body }}

Aanvraag bekijken: {{ route('leave-requests.show', $leaveRequest) }}

Goedkeuren gebeurt in Nicon Planning na inloggen.
