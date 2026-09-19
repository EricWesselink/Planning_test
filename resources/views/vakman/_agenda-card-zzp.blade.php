@php
    $title = trim((string) $job['project_name']);
    $city = trim((string) ($job['city'] ?? ''));
@endphp
<a href="{{ $job['url'] }}" class="vakman-agenda-card">
    <div class="vakman-agenda-card-title">{{ $title }}</div>
    <div class="vakman-agenda-card-meta">
        @if ($city !== '')
            <div>{{ $city }}</div>
        @endif
        <div>{{ $job['time_label'] }}</div>
        @if ($job['headline'] !== '')
            <div>{{ $job['headline'] }}</div>
        @endif
        @if ($job['colleagues'] !== [])
            <div class="vakman-agenda-card-people">Samen met: {{ implode(', ', $job['colleagues']) }}</div>
        @endif
    </div>
</a>
