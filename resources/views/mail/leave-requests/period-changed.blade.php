<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>Periode van je vrij-aanvraag is aangepast</title>
</head>
<body style="font-family: sans-serif; color: #1c1917; margin: 0; padding: 24px; background: #f6f6f7;">
    <div style="max-width: 720px; margin: 0 auto; background: #ffffff; padding: 24px; border: 1px solid #e5e5e5;">
        <p style="margin: 0 0 4px; font-size: 12px; letter-spacing: 0.16em; text-transform: uppercase; color: #c41623;">{{ config('company.name') }}</p>
        <h1 style="font-size: 22px; margin: 0 0 12px;">Periode van je vrij-aanvraag is aangepast</h1>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Hallo {{ $leaveRequest->workerName() }},
        </p>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            De periode van je vrij-aanvraag is aangepast.
        </p>
        <p style="margin: 0 0 8px; font-size: 14px; line-height: 1.5;">
            Oorspronkelijke aanvraag:<br>
            {{ $leaveRequest->originalShortPeriodLabel() }}
        </p>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5;">
            Afgesproken periode:<br>
            {{ $leaveRequest->shortPeriodLabel() }}
        </p>
        <p style="margin: 0 0 16px;">
            <a href="{{ route('vakman.leave-requests.show', $leaveRequest) }}" style="display: inline-block; background: #c41623; color: #fff; text-decoration: none; padding: 10px 16px; font-size: 14px;">
                Bekijk aanvraag
            </a>
        </p>
        <p style="margin: 0; font-size: 14px; line-height: 1.5;">
            Groet,<br>
            {{ config('company.name') }}
        </p>
    </div>
</body>
</html>
