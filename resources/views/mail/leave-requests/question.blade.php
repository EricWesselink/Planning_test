<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Vraag over je vrij-aanvraag</title>
</head>
<body style="font-family: sans-serif; color: #1c1917; margin: 0; padding: 24px; background: #fbf8f3;">
    <div style="max-width: 720px; margin: 0 auto; background: #ffffff; padding: 24px; border: 1px solid #e7e0d4;">
        <p style="margin: 0 0 4px; font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: #e4572e;">{{ config('company.name') }}</p>
        <h1 style="font-size: 22px; margin: 0 0 12px;">Vraag over je vrij-aanvraag</h1>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Hallo {{ $leaveRequest->workerName() }},
        </p>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Er is een vraag over je vrij-aanvraag voor {{ $leaveRequest->periodLabel() }}.
        </p>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Bericht van {{ $leaveMessage->user?->name }}:<br>
            {{ $leaveMessage->body }}
        </p>
        <p style="margin: 0 0 16px;">
            <a href="{{ route('vakman.leave-requests.show', $leaveRequest) }}" style="display: inline-block; background: #e4572e; color: #fff; text-decoration: none; padding: 10px 16px; font-size: 14px;">
                Bekijk aanvraag en reageer
            </a>
        </p>
        <p style="margin: 0; font-size: 14px; line-height: 1.5;">
            Groet,<br>
            {{ config('company.name') }}
        </p>
    </div>
</body>
</html>
