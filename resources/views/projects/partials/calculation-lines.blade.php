@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ProjectCalculationLine> $lines */
    $lines = $project->calculationLines->where('is_labor', true)->values();
    $totalAmount = round((float) $lines->sum(fn ($line) => (float) ($line->total_cost ?? $line->labor_cost ?? 0)), 2);
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
                        <th class="py-0.5 font-normal">Totaal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        @php
                            $quantityUnit = $line->normalizedUnit();
                            $quantityLabel = match ($quantityUnit) {
                                \App\Enums\WorkUnit::SquareMeter => 'm²',
                                \App\Enums\WorkUnit::LinearMeter => 'm¹',
                                \App\Enums\WorkUnit::Pieces => 'st',
                                default => $line->unit,
                            };
                            $showQuantity = $line->quantity !== null
                                && $quantityUnit !== null
                                && $quantityUnit !== \App\Enums\WorkUnit::Hours;
                        @endphp
                        <tr>
                            <td class="pr-2 py-0.5">{{ $line->description() }}</td>
                            <td class="pr-2 py-0.5">{{ $line->work_match_label ?: '—' }}</td>
                            <td class="pr-2 py-0.5">
                                @if ($showQuantity)
                                    {{ \App\Support\Format::qty((float) $line->quantity, 2) }} {{ $quantityLabel }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="pr-2 py-0.5">{{ $line->hours === null ? '—' : \App\Support\Format::qty((float) $line->hours, 2) }}</td>
                            <td class="pr-2 py-0.5">{{ $line->hourly_rate === null ? '—' : \App\Support\Format::euro((float) $line->hourly_rate, 2) }}</td>
                            <td class="pr-2 py-0.5">{{ $line->labor_cost === null ? '—' : \App\Support\Format::euro((float) $line->labor_cost, 2) }}</td>
                            <td class="py-0.5">{{ $line->total_cost === null ? '—' : \App\Support\Format::euro((float) $line->total_cost, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-nicon-line font-medium">
                        <td class="pr-2 py-1" colspan="6">Totaal bedrag</td>
                        <td class="py-1">{{ \App\Support\Format::euro($totalAmount, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </details>
@endif
