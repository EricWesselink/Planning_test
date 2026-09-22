@php
    $overview = $overview ?? null;
    $filters = $filters ?? ['people' => [], 'projects' => collect()];
@endphp
<form method="GET" action="{{ route('personnel.index') }}" class="mt-4 grid gap-2 border border-nicon-line bg-white p-3 text-sm sm:grid-cols-4">
    <input type="hidden" name="tab" value="overzicht">
    <label class="grid gap-1">
        <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Week</span>
        <input type="date" name="week" value="{{ request('week', $weekStart->toDateString()) }}" class="border border-nicon-line px-2 py-1.5">
    </label>
    <label class="grid gap-1">
        <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Van</span>
        <input type="date" name="from" value="{{ request('from') }}" class="border border-nicon-line px-2 py-1.5">
    </label>
    <label class="grid gap-1">
        <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Tot</span>
        <input type="date" name="to" value="{{ request('to') }}" class="border border-nicon-line px-2 py-1.5">
    </label>
    <label class="grid gap-1">
        <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Medewerker</span>
        <select name="person" class="border border-nicon-line px-2 py-1.5">
            <option value="">Alle medewerkers</option>
            @foreach ($filters['people'] as $person)
                <option value="{{ $person['value'] }}" @selected(request('person') === $person['value'])>{{ $person['label'] }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1">
        <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Project</span>
        <select name="project_id" class="border border-nicon-line px-2 py-1.5">
            <option value="">Alle</option>
            @foreach ($filters['projects'] as $project)
                <option value="{{ $project->id }}" @selected((int) request('project_id') === (int) $project->id)>{{ $project->displayTitle() }}</option>
            @endforeach
        </select>
    </label>
    <label class="grid gap-1">
        <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Werknummer</span>
        <input type="text" name="work_number" value="{{ request('work_number') }}" class="border border-nicon-line px-2 py-1.5">
    </label>
    <label class="grid gap-1">
        <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Status</span>
        <select name="status" class="border border-nicon-line px-2 py-1.5">
            <option value="">Alle</option>
            @foreach (\App\Enums\TimeEntryStatus::cases() as $status)
                <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
    </label>
    <div class="flex items-end">
        <button class="border border-nicon-ink bg-nicon-ink px-3 py-1.5 text-white">Filter</button>
    </div>
</form>
<p class="mt-3 text-sm">{{ $overviewTotals['label'] ?? 'Ingediend: 0u | Goedgekeurd: 0u | Verschil: 0u' }}</p>
<div class="mt-4 overflow-x-auto border border-nicon-line bg-white">
    <table class="min-w-full text-xs">
        <thead class="border-b border-nicon-line bg-nicon-paper text-left uppercase tracking-wide text-nicon-muted">
            <tr>
                <th class="px-2 py-1.5 font-medium">Datum</th>
                <th class="px-2 py-1.5 font-medium">Medewerker</th>
                <th class="px-2 py-1.5 font-medium">Project</th>
                <th class="px-2 py-1.5 font-medium">Werknummer</th>
                <th class="px-2 py-1.5 font-medium">Werkzaamheid</th>
                <th class="px-2 py-1.5 font-medium">Gepland</th>
                <th class="px-2 py-1.5 font-medium">Uren</th>
                <th class="px-2 py-1.5 font-medium">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($overview ?? [] as $entry)
                <tr class="border-t border-nicon-line">
                    <td class="px-2 py-1.5 whitespace-nowrap">{{ $entry->date->translatedFormat('D j M Y') }}</td>
                    <td class="px-2 py-1.5 font-semibold">{{ $entry->personName() }}</td>
                    <td class="px-2 py-1.5">
                        {{ $entry->project?->displayTitle() }}
                        @if ($entry->is_unplanned)
                            <span class="text-nicon-warn"> · niet gepland</span>
                        @endif
                    </td>
                    <td class="px-2 py-1.5">{{ $entry->project?->workNumber() }}</td>
                    <td class="px-2 py-1.5">{{ $entry->workName() }}</td>
                    <td class="px-2 py-1.5">{{ \App\Support\PlanningHours::hoursLabel($entry->plannedHoursValue()) }}</td>
                    <td class="px-2 py-1.5">
                        {{ \App\Support\PlanningHours::hoursLabel($entry->accountedHoursValue()) }}
                        @if ($entry->isAdjusted())
                            <div class="text-nicon-muted">ingediend {{ $entry->hoursLabel() }}</div>
                        @endif
                    </td>
                    <td class="px-2 py-1.5">{{ $entry->reviewStatusLabel() }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-2 py-4 text-nicon-muted">Geen uren gevonden.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($overview)
    <div class="mt-3">{{ $overview->links() }}</div>
@endif
