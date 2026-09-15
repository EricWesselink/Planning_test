@extends('layouts.app')

@section('title', 'Bronbestand bijwerken · Nicon Planning')

@section('content')
    @php
        $comparison = $comparison ?? [];
        $diffs = $comparison['diffs'] ?? [];
        $statusLabels = [
            'new' => 'Nieuw',
            'changed' => 'Gewijzigd',
            'unchanged' => 'Ongewijzigd',
            'removed' => 'Vervallen',
        ];
    @endphp
    <a href="{{ route('projects.show', $project) }}" class="text-sm text-nicon-muted">← {{ $project->displayTitle() }}</a>
    <h1 class="mt-2 text-2xl font-semibold">Bronbestanden bijwerken</h1>
    <p class="mt-1 text-sm text-nicon-muted">{{ $project->project_number }} · {{ $project->displayTitle() }}</p>
    <p class="mt-4 max-w-3xl text-sm">{{ $comparison['message'] ?? \App\Services\SourceUpdateService::CONFIRM_MESSAGE }}</p>

    @if (! empty($uploads))
        <ul class="mt-3 text-sm text-nicon-muted">
            @foreach ($uploads as $upload)
                <li>{{ $upload['original'] ?? '' }}@if (! empty($upload['type'])) · {{ $upload['type'] }}@endif</li>
            @endforeach
        </ul>
    @endif

    @foreach ($diffs as $type => $diff)
        <section class="mt-6 border border-nicon-line bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide">
                @if ($type === 'plattegrond')
                    Plattegrond
                @elseif ($type === 'calculatie')
                    Excel calculatie
                @elseif ($type === 'materialenstaat')
                    Materialenstaat
                @else
                    Meetstaat
                @endif
            </h2>

            @if ($type === 'plattegrond')
                <p class="mt-2 text-sm">
                    Nieuwe revisie {{ $diff['next_revision'] }} wordt opgeslagen.
                    @if (($diff['current_revision'] ?? 0) > 0)
                        Revisie {{ $diff['current_revision'] }} blijft bewaard.
                    @endif
                    Bestaande ruimtes, markers en opleverpunten blijven gekoppeld aan het project.
                    Markers of opleverpunten waarvan de positie op de nieuwe tekening niet betrouwbaar is, worden gemarkeerd als <strong>koppeling controleren</strong> en niet automatisch op de nieuwe tekening gezet.
                </p>
            @else
                @foreach (['new', 'changed', 'unchanged', 'removed'] as $status)
                    @php $rows = $diff[$status] ?? []; @endphp
                    <details class="mt-3" @if (in_array($status, ['new', 'changed', 'removed'], true) && $rows !== []) open @endif>
                        <summary class="cursor-pointer text-sm">
                            <span class="source-status source-status-{{ $status }}">{{ $statusLabels[$status] }}</span>
                            {{ count($rows) }}
                        </summary>
                        @if ($rows === [])
                            <p class="mt-1 text-sm text-nicon-muted">Geen.</p>
                        @else
                            <ul class="mt-2 space-y-1 text-sm">
                                @foreach ($rows as $row)
                                    <li>
                                        {{ $row['label'] ?? $row['name'] ?? '' }}
                                        @if ($type === 'meetstaat')
                                            · {{ $row['floor'] ?? '' }}
                                            @if (($row['square_meters'] ?? null) !== null)
                                                · {{ \App\Support\Format::qty($row['square_meters'], 2) }} m²
                                            @endif
                                        @endif
                                        @if ($type === 'materialenstaat')
                                            @if (($row['netto'] ?? null) !== null)
                                                · netto {{ \App\Support\Format::qty($row['netto'], 2) }}
                                            @endif
                                            @if (($row['bruto'] ?? null) !== null)
                                                · bruto {{ \App\Support\Format::qty($row['bruto'], 2) }}
                                            @endif
                                        @endif
                                        @if (! empty($row['changes']))
                                            <span class="text-nicon-muted">
                                                @foreach ($row['changes'] as $change)
                                                    · {{ $change['field'] }}:
                                                    {{ $change['from'] === null ? '—' : \App\Support\Format::qty($change['from'], 2) }}
                                                    →
                                                    {{ $change['to'] === null ? '—' : \App\Support\Format::qty($change['to'], 2) }}
                                                @endforeach
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </details>
                @endforeach
            @endif
        </section>
    @endforeach

    <form method="POST" action="{{ route('projects.sources.confirm', $token) }}" class="mt-6 flex flex-wrap gap-3">
        @csrf
        <button class="bg-nicon-ink text-white px-5 py-3 font-medium">Ja, bestaande gegevens bijwerken</button>
        <a href="{{ route('projects.show', $project) }}" class="border border-nicon-line bg-white px-5 py-3">Annuleren</a>
    </form>
    <p class="mt-3 max-w-3xl text-xs text-nicon-muted">Planning, ingeplande uren, gemaakte uren, voortgang, bonnen, opleverpunten, foto’s, vakmensen en handmatige aanpassingen blijven behouden. Alleen brongegevens worden bijgewerkt, daarna worden de projectberekeningen opnieuw uitgevoerd.</p>
@endsection
