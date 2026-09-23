<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Vrij-aanvraag afgewezen</title>
</head>
<body style="font-family: sans-serif; color: #1c1917; margin: 0; padding: 24px; background: #f6f6f7;">
    <div style="max-width: 720px; margin: 0 auto; background: #ffffff; padding: 24px; border: 1px solid #e5e5e5;">
        <p style="margin: 0 0 4px; font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: #c41623;">{{ config('company.name') }}</p>
        <h1 style="font-size: 22px; margin: 0 0 12px;">Je vrij-aanvraag is afgewezen</h1>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Hallo {{ $leaveRequest->workerName() }},
        </p>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Je vrij-aanvraag voor {{ $leaveRequest->periodLabel() }} is afgewezen.
        </p>
        @if (filled($leaveRequest->rejection_reason))
            <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
                Reden:<br>
                {{ $leaveRequest->rejection_reason }}
            </p>
        @endif
        <p style="margin: 0; font-size: 14px; line-height: 1.5;">
            Groet,<br>
            {{ config('company.name') }}
        </p>
    </div>
</body>
</html>
