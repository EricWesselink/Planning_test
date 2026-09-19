Hallo {{ $leaveRequest->workerName() }},

Je vrij-aanvraag voor {{ $leaveRequest->periodLabel() }} is goedgekeurd.

Deze periode staat nu als afwezig in je planning.

Groet,
{{ config('company.name') }}
