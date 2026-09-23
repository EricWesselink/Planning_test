<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Vrij-aanvraag</title>
</head>
<body style="font-family: sans-serif; color: #1c1917; margin: 0; padding: 24px; background: #f6f6f7;">
    <div style="max-width: 720px; margin: 0 auto; background: #ffffff; padding: 24px; border: 1px solid #e5e5e5;">
        <p style="margin: 0 0 4px; font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: #c41623;">{{ config('company.name') }}</p>
        <h1 style="font-size: 22px; margin: 0 0 12px;">Vrij-aanvraag</h1>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            {{ $leaveRequest->workerName() }} heeft vrij aangevraagd.
        </p>
        <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.5;">
            Periode:<br>
            {{ $leaveRequest->shortPeriodLabel() }}
        </p>
        <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.5;">
            Aantal werkdagen: {{ $leaveRequest->workdayCount() }}
        </p>
        @if (filled($leaveRequest->note))
            <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
                Opmerking: {{ $leaveRequest->note }}
            </p>
        @endif
        <p style="margin: 0 0 16px;">
            <a href="{{ route('leave-requests.show', $leaveRequest) }}" style="display: inline-block; background: #c41623; color: #fff; text-decoration: none; padding: 10px 16px; font-size: 14px;">
                Aanvraag bekijken
            </a>
        </p>
        <p style="margin: 0; font-size: 12px; color: #78716c;">
            Goedkeuren gebeurt in Nicon Planning na inloggen.
        </p>
    </div>
</body>
</html>
