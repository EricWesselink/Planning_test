@php
    $pendingEntries = $pendingEntries ?? [];
@endphp
<div class="mt-4 overflow-x-auto border border-nicon-line bg-white">
    <table class="min-w-full text-xs">
        <thead class="border-b border-nicon-line bg-nicon-paper text-left uppercase tracking-wide text-nicon-muted">
            <tr>
                <th class="px-2 py-1.5 font-medium">Medewerker</th>
                <th class="px-2 py-1.5 font-medium">Project</th>
                <th class="px-2 py-1.5 font-medium">Werknummer</th>
                <th class="px-2 py-1.5 font-medium">Werkzaamheid</th>
                <th class="px-2 py-1.5 font-medium">Datum</th>
                <th class="px-2 py-1.5 font-medium">Gepland</th>
                <th class="px-2 py-1.5 font-medium">Ingediend</th>
                <th class="px-2 py-1.5 font-medium">Verschil</th>
                <th class="px-2 py-1.5 font-medium">Opmerking</th>
                <th class="px-2 py-1.5 font-medium">Status</th>
                <th class="px-2 py-1.5 font-medium">Actie</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($pendingEntries as $entry)
                @php
                    $diff = $entry->differenceHours();
                @endphp
                <tr @class(['border-t border-nicon-line', 'bg-nicon-paper/60' => abs($diff) > 0.01])>
                    <td class="px-2 py-1.5 whitespace-nowrap font-semibold">{{ $entry->personName() }}</td>
                    <td class="px-2 py-1.5">
                        {{ $entry->project?->displayTitle() }}
                        @if ($entry->is_unplanned)
                            <div class="text-[11px] font-medium text-nicon-warn">Niet gepland</div>
                        @endif
                    </td>
                    <td class="px-2 py-1.5 whitespace-nowrap">{{ $entry->project?->workNumber() }}</td>
                    <td class="px-2 py-1.5">{{ $entry->workName() }}</td>
                    <td class="px-2 py-1.5 whitespace-nowrap">{{ $entry->date->translatedFormat('D j M') }}</td>
                    <td class="px-2 py-1.5">{{ \App\Support\PlanningHours::hoursLabel($entry->plannedHoursValue()) }}</td>
                    <td class="px-2 py-1.5 font-medium">{{ $entry->hoursLabel() }}</td>
                    <td @class(['px-2 py-1.5', 'font-medium text-nicon-warn' => abs($diff) > 0.01])>{{ ($diff > 0.0001 ? '+' : '').\App\Support\PlanningHours::hoursLabel($diff) }}</td>
                    <td class="px-2 py-1.5 text-nicon-muted">{{ $entry->note }}</td>
                    <td class="px-2 py-1.5">{{ $entry->reviewStatusLabel() }}</td>
                    <td class="px-2 py-1.5">
                        @if ($canReviewHours && $entry->isSubmitted())
                            <div class="flex flex-wrap items-center gap-1">
                                <form method="POST" action="{{ route('personnel.hours.approve', $entry) }}">
                                    @csrf
                                    <button class="border border-nicon-ok bg-white px-2 py-0.5 text-nicon-ok">Goedkeuren</button>
                                </form>
                                <form method="POST" action="{{ route('personnel.hours.update', $entry) }}" class="flex flex-wrap items-center gap-1">
                                    @csrf
                                    @method('PATCH')
                                    <input type="number" name="approved_hours" value="{{ $entry->submittedHoursValue() }}" min="0.25" max="24" step="0.1" class="w-16 border border-nicon-line px-1 py-0.5" aria-label="Goedgekeurde uren">
                                    <input type="text" name="review_note" placeholder="Reden bij aanpassing" class="w-36 border border-nicon-line px-1 py-0.5" aria-label="Reden">
                                    <button class="border border-nicon-line bg-white px-2 py-0.5">Aanpassen &amp; goedkeuren</button>
                                </form>
                                <form method="POST" action="{{ route('personnel.hours.reject', $entry) }}" class="flex items-center gap-1">
                                    @csrf
                                    <input type="text" name="review_note" placeholder="Reden" required class="w-28 border border-nicon-line px-1 py-0.5" aria-label="Reden afwijzen">
                                    <button class="border border-nicon-danger bg-white px-2 py-0.5 text-nicon-danger">Afwijzen</button>
                                </form>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="px-2 py-4 text-nicon-muted">Geen uren te beoordelen deze week.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($canReviewHours)
    <div class="mt-3 space-y-2">
        @foreach ($people as $row)
            @if (($row['hours_status'] ?? null)?->value === 'ingediend')
                <form method="POST" action="{{ route('personnel.hours.approve-week') }}" class="inline-block">
                    @csrf
                    <input type="hidden" name="worker_id" value="{{ $row['worker']->id }}">
                    @if ($row['member']->exists)
                        <input type="hidden" name="crew_member_id" value="{{ $row['member']->id }}">
                    @endif
                    <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
                    <button class="border border-nicon-ink bg-nicon-ink px-3 py-1.5 text-xs text-white">Hele week goedkeuren · {{ $row['member']->displayName() }}</button>
                </form>
            @endif
        @endforeach
    </div>
@endif
