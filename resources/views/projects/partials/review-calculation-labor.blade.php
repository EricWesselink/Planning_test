@php
    $laborLines = $preview['calculation']['labor'] ?? [];
    $openLabor = (int) ($preview['calculation']['open_matches'] ?? 0);
    $options = $preview['calculation']['options'] ?? [];
@endphp
@if ($laborLines !== [])
    <x-review-fold
        id="begrote-arbeidsuren"
        title="Begrote arbeidsuren uit calculatie"
        :expanded="$openLabor > 0 || $errors->has('calculation_labor')"
        :badge="$openLabor > 0 ? 'Controleren' : null"
    >
        @error('calculation_labor')
            <p class="text-sm text-nicon-danger">{{ $message }}</p>
        @enderror
        <p class="text-sm text-nicon-muted">
            Uren en tarieven komen uit de Excel-calculatie (M/U = U en EH = uur). Het tarief wordt per regel bewaard, niet hardcoded.
            @if (! empty($preview['calculation']['filenames']))
                Bron: {{ implode(', ', $preview['calculation']['filenames']) }}
            @endif
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-nicon-ink text-white text-left">
                    <tr>
                        <th class="px-3 py-2">Omschrijving</th>
                        <th class="px-3 py-2">Werkzaamheid</th>
                        <th class="px-3 py-2">Hoeveelheid</th>
                        <th class="px-3 py-2">Begrote uren</th>
                        <th class="px-3 py-2">Uurtarief</th>
                        <th class="px-3 py-2">Arbeidskosten</th>
                        <th class="px-3 py-2">Koppeling/status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($laborLines as $index => $line)
                        @php
                            $selected = old('calculation_labor.'.$index.'.work_name', $line['work_name'] ?? '');
                            $status = filled($selected) ? ($line['status'] ?? 'matched') : 'review';
                            if (old('calculation_labor.'.$index.'.work_name') !== null) {
                                $status = filled($selected) ? 'matched' : 'review';
                            }
                            $qtyUnit = $line['quantity_unit'] ?? $line['unit'] ?? '';
                            $qtyLabel = $qtyUnit === 'm2' ? 'm²' : ($qtyUnit === 'm1' ? 'm¹' : $qtyUnit);
                            $quantityUncertain = ($line['quantity_status'] ?? '') === 'review';
                            if ($quantityUncertain && $status !== 'review') {
                                $status = 'review';
                            }
                        @endphp
                        <tr class="border-t border-nicon-line {{ $status === 'review' ? 'bg-amber-50' : '' }}">
                            <td class="px-3 py-2">
                                <div>{{ $line['description'] ?? $line['production_description'] }}</div>
                                @if (! empty($line['group_code']))
                                    <div class="text-xs text-nicon-muted">Groep {{ $line['group_code'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <select name="calculation_labor[{{ $index }}][work_name]" class="w-full border border-nicon-line bg-white px-2 py-1 text-sm">
                                    <option value="">Controleren…</option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option }}" @selected($selected === $option)>{{ $option }}</option>
                                    @endforeach
                                    @if (filled($selected) && ! in_array($selected, $options, true))
                                        <option value="{{ $selected }}" selected>{{ $selected }}</option>
                                    @endif
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                @if (($line['quantity_status'] ?? '') === 'review')
                                    —
                                    <div class="text-xs text-nicon-warn">Hoeveelheid niet betrouwbaar gekoppeld</div>
                                @elseif (($line['quantity'] ?? null) !== null && ! in_array(mb_strtolower((string) $qtyUnit), ['uur', 'uren', 'u'], true))
                                    {{ \App\Support\Format::qty($line['quantity'], 2) }} {{ $qtyLabel }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ \App\Support\Format::qty($line['hours'] ?? 0, 2) }}</td>
                            <td class="px-3 py-2">{{ ($line['hourly_rate'] ?? null) === null ? '—' : \App\Support\Format::euro($line['hourly_rate'], 2) }}</td>
                            <td class="px-3 py-2">{{ ($line['labor_cost'] ?? null) === null ? '—' : \App\Support\Format::euro($line['labor_cost'], 2) }}</td>
                            <td class="px-3 py-2 {{ $status === 'review' ? 'font-medium text-nicon-warn' : 'text-nicon-muted' }}">
                                {{ $status === 'review' ? 'Controleren' : 'Gekoppeld' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-nicon-ink font-medium">
                        <td class="px-3 py-2" colspan="3">Totaal begrote uren</td>
                        <td class="px-3 py-2">{{ \App\Support\Format::qty($preview['calculation']['total_hours'] ?? 0, 2) }}</td>
                        <td class="px-3 py-2">Totale begrote arbeidskosten</td>
                        <td class="px-3 py-2">{{ \App\Support\Format::euro($preview['calculation']['total_labor_cost'] ?? 0, 2) }}</td>
                        <td class="px-3 py-2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-review-fold>
@endif
