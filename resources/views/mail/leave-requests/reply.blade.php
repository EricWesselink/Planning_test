<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Antwoord op vrij-aanvraag</title>
</head>
<body style="font-family: sans-serif; color: #1c1917; margin: 0; padding: 24px; background: #fbf8f3;">
    <div style="max-width: 720px; margin: 0 auto; background: #ffffff; padding: 24px; border: 1px solid #e7e0d4;">
        <p style="margin: 0 0 4px; font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: #e4572e;">{{ config('company.name') }}</p>
        <h1 style="font-size: 22px; margin: 0 0 12px;">Antwoord op vrij-aanvraag</h1>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            {{ $leaveRequest->workerName() }} heeft geantwoord op de vrij-aanvraag voor {{ $leaveRequest->periodLabel() }}.
        </p>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Bericht van {{ $leaveMessage->user?->name }}:<br>
            {{ $leaveMessage->body }}
        </p>
        <p style="margin: 0 0 16px;">
            <a href="{{ route('leave-requests.show', $leaveRequest) }}" style="display: inline-block; background: #e4572e; color: #fff; text-decoration: none; padding: 10px 16px; font-size: 14px;">
                Aanvraag bekijken
            </a>
        </p>
        <p style="margin: 0; font-size: 12px; color: #78716c;">
            Goedkeuren gebeurt in Nicon Planning na inloggen.
        </p>
    </div>
</body>
</html>
