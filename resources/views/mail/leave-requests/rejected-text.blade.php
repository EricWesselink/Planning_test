Hallo {{ $leaveRequest->workerName() }},

Je vrij-aanvraag voor {{ $leaveRequest->periodLabel() }} is afgewezen.
@if (filled($leaveRequest->rejection_reason))

Reden:
{{ $leaveRequest->rejection_reason }}
@endif

Groet,
{{ config('company.name') }}
