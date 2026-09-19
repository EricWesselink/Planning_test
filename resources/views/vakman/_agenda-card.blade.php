@php
    $title = trim((string) $job['project_name']);
    $city = trim((string) ($job['city'] ?? ''));
    $address = $job['address'] ?? null;
    $kindLabel = trim((string) ($job['kind_label'] ?? ''));
    $summary = trim((string) ($job['summary'] ?? ''));
    $holder = $job['work_ticket_holder'] ?? null;
    $isHolder = (bool) ($job['is_work_ticket_holder'] ?? false);
    $showActions = $showActions ?? true;
@endphp
<div class="vakman-job-card">
    <div class="vakman-job-card-body">
        @if ($kindLabel !== '')
            <p class="vakman-job-card-kind {{ $kindLabel === 'Winkel' ? 'is-winkel' : '' }}">{{ $kindLabel }}</p>
        @endif
        <h2 class="vakman-job-card-title">{{ $title }}</h2>
        @if (($job['numbers'] ?? '') !== '')
            <p class="vakman-job-card-numbers">{{ $job['numbers'] }}</p>
        @endif

        <p class="vakman-job-card-time">{{ $job['time_label'] }}</p>

        @if ($address)
            <p class="vakman-job-card-row">
                <span class="vakman-job-card-label">Werkadres</span>
                @if ($job['maps_url'] ?? null)
                    <a href="{{ $job['maps_url'] }}" target="_blank" rel="noopener noreferrer">{{ $address }}</a>
                @else
                    {{ $address }}
                @endif
            </p>
        @elseif ($city !== '')
            <p class="vakman-job-card-row">
                <span class="vakman-job-card-label">Werkadres</span>
                {{ $city }}
            </p>
        @endif

        @if (($job['headline'] ?? '') !== '')
            <p class="vakman-job-card-row">
                <span class="vakman-job-card-label">Werk</span>
                {{ $job['headline'] }}
            </p>
        @endif

        @if ($showActions && $summary !== '')
            <p class="vakman-job-card-row">
                <span class="vakman-job-card-label">Omschrijving</span>
                {{ $summary }}
            </p>
        @endif

        @if (($job['people'] ?? []) !== [])
            <p class="vakman-job-card-row">
                <span class="vakman-job-card-label">Vakmannen</span>
                {{ implode(', ', $job['people']) }}
            </p>
        @endif

        @if (($job['colleagues'] ?? []) !== [])
            <p class="vakman-job-card-row">
                <span class="vakman-job-card-label">Je werkt met</span>
                {{ implode(', ', $job['colleagues']) }}
            </p>
        @endif

        @if (($job['foreman'] ?? null) !== null && $job['foreman'] !== '')
            <p class="vakman-job-card-row">
                <span class="vakman-job-card-label">Voorman</span>
                {{ $job['foreman'] }}
            </p>
        @endif

        @if ($isHolder)
            <p class="vakman-job-card-werkbon is-own">WERKBON · Jij bent verantwoordelijk</p>
        @elseif ($holder)
            <p class="vakman-job-card-werkbon">Werkbon bij: {{ $holder }}</p>
        @endif
    </div>

    @if ($showActions)
        <div class="vakman-job-card-actions">
            <a href="{{ $job['url'] }}" class="vakman-job-btn vakman-job-btn-ink">Bekijk werk</a>
            @if ($job['werkbon_url'] ?? null)
                <a href="{{ $job['werkbon_url'] }}" class="vakman-job-btn vakman-job-btn-orange">Open werkbon</a>
            @elseif ($job['opdrachtbon_url'] ?? null)
                <a href="{{ $job['opdrachtbon_url'] }}" class="vakman-job-btn vakman-job-btn-orange">Opdrachtbon</a>
            @endif
            @if ($job['maps_url'] ?? null)
                <a href="{{ $job['maps_url'] }}" class="vakman-job-btn vakman-job-btn-ghost" target="_blank" rel="noopener noreferrer">Route</a>
            @endif
        </div>
    @endif
</div>
