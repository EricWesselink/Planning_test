<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }}{{ filled($number ?? '') ? ' '.$number : '' }}</title>
    @include('work-tickets._styles')
</head>
<body>
    @include('work-tickets._document', ['isPdf' => true])
</body>
</html>
