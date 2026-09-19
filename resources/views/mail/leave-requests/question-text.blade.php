Hallo {{ $leaveRequest->workerName() }},

Er is een vraag over je vrij-aanvraag voor {{ $leaveRequest->periodLabel() }}.

Bericht van {{ $leaveMessage->user?->name }}:
{{ $leaveMessage->body }}

Bekijk aanvraag en reageer: {{ route('vakman.leave-requests.show', $leaveRequest) }}

Groet,
{{ config('company.name') }}
