Hallo {{ $leaveRequest->workerName() }},

De periode van je vrij-aanvraag is aangepast.

Oorspronkelijke aanvraag:
{{ $leaveRequest->originalShortPeriodLabel() }}

Afgesproken periode:
{{ $leaveRequest->shortPeriodLabel() }}

Bekijk aanvraag: {{ route('vakman.leave-requests.show', $leaveRequest) }}

Groet,
{{ config('company.name') }}
