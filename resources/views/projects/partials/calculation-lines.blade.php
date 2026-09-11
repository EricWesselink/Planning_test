@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ProjectCalculationLine> $lines */
    $lines = $project->calculationLines->where('is_labor', true)->values();
@endphp
@if ($lines->isNotEmpty())
    <details class="mt-2 text-xs">
        <summary class="cursor-pointer text-nicon-muted">Oorspronkelijke calculatieregels ({{ $lines->count() }})</summary>
        <div class="mt-1 overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-nicon-muted">
                        <th class="pr-2 py-0.5 font-normal">Omschrijving</th>
                        <th class="pr-2 py-0.5 font-normal">Werkzaamheid</th>
                        <th class="pr-2 py-0.5 font-normal">Hoeveelheid</th>
                        <th class="pr-2 py-0.5 font-normal">Uren</th>
                        <th class="pr-2 py-0.5 font-normal">Tarief</th>
                        <th class="pr-2 py-0.5 font-normal">Arbeid</th>
                        <th class="py-0.5 font-normal">Bron</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td class="pr-2 py-0.5">{{ $line->description() }}</td>
                            <td class="pr-2 py-0.5">{{ $line->work_match_label ?: '—' }}</td>
                            <td class="pr-2 py-0.5">
                                @if ($line->quantity !== null && $line->normalizedUnit() !== \App\Enums\WorkUnit::Hours)
                                    {{ \App\Support\Format::qty((float) $line->quantity, 2) }} {{ $line->unit }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="pr-2 py-0.5">{{ $line->hours === null ? '—' : \App\Support\Format::qty((float) $line->hours, 2) }}</td>
                            <td class="pr-2 py-0.5">{{ $line->hourly_rate === null ? '—' : \App\Support\Format::euro((float) $line->hourly_rate, 2) }}</td>
                            <td class="pr-2 py-0.5">{{ $line->labor_cost === null ? '—' : \App\Support\Format::euro((float) $line->labor_cost, 2) }}</td>
                            <td class="py-0.5 text-nicon-muted">{{ $line->source_filename }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif
