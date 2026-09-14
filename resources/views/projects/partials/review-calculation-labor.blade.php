@php
    $laborLines = $preview['calculation']['labor'] ?? [];
    $openLabor = (int) ($preview['calculation']['open_matches'] ?? 0);
    $laborWarnings = (int) ($preview['calculation']['warnings'] ?? 0);
    $options = $preview['calculation']['options'] ?? [];
    $products = $preview['calculation']['products'] ?? [];
    $sourceChecks = $preview['calculation']['source_checks'] ?? [];
    $quantitiesDiffer = collect($products)->contains(fn (array $product): bool => (bool) ($product['quantities_differ'] ?? false));
    $productReview = collect($products)->contains(fn (array $product): bool => ($product['status'] ?? '') === 'review');
@endphp
@if ($laborLines !== [])
    <x-review-fold
        id="begrote-arbeidsuren"
        title="Begrote arbeidsuren uit calculatie"
        :expanded="$openLabor > 0 || $productReview || $quantitiesDiffer || $errors->has('calculation_labor')"
        :badge="$openLabor > 0 || $productReview ? 'Handmatige controle' : ($laborWarnings > 0 || $quantitiesDiffer ? 'Meetstaat leidend' : null)"
    >
        @error('calculation_labor')
            <p class="text-sm text-nicon-danger">{{ $message }}</p>
        @enderror
        <p class="text-sm text-nicon-muted">
            Uren en tarieven komen uit de Excel-calculatie (M/U = U en EH = uur). Netto m²/m¹ komen uit de Meetstaat en worden niet overschreven door Excel of Materialenstaat.
            @if (! empty($preview['calculation']['filenames']))
                Bron: {{ implode(', ', $preview['calculation']['filenames']) }}
            @endif
        </p>
        @if ($openLabor === 0)
            <p class="text-sm text-nicon-ok">Arbeidsregels, producten en hoeveelheden zijn automatisch gekoppeld uit Excel, meetstaat en materialenstaat.</p>
        @else
            <p class="text-sm text-nicon-warn">Ongekoppelde Excel-arbeidsregels zijn waarschuwingen en blokkeren importeren niet.</p>
        @endif
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
                            $contextMaterials = $line['context_materials'] ?? [];
                            $rowClass = match ($status) {
                                'review' => 'bg-amber-50',
                                'warning' => 'bg-amber-50/60',
                                default => '',
                            };
                        @endphp
                        <tr class="border-t border-nicon-line {{ $rowClass }}">
                            <td class="px-3 py-2">
                                <div>{{ $line['description'] ?? $line['production_description'] }}</div>
                                @if (! empty($line['group_code']))
                                    <div class="text-xs text-nicon-muted">Groep {{ $line['group_code'] }}</div>
                                @endif
                                @foreach ($contextMaterials as $material)
                                    <div class="text-xs text-nicon-muted">
                                        {{ $material['label'] ?? '' }}
                                        @if (($material['m2'] ?? 0) > 0.0001)
                                            · {{ \App\Support\Format::qty($material['m2'], 2) }} m²
                                        @elseif (($material['m1'] ?? 0) > 0.0001)
                                            · {{ \App\Support\Format::qty($material['m1'], 2) }} m¹
                                        @endif
                                    </div>
                                @endforeach
                            </td>
                            <td class="px-3 py-2">
                                <select name="calculation_labor[{{ $index }}][work_name]" class="w-full border border-nicon-line bg-white px-2 py-1 text-sm">
                                    <option value="">{{ $status === 'review' ? 'Kies werkzaamheid…' : '' }}</option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option }}" @selected($selected === $option)>{{ $option }}</option>
                                    @endforeach
                                    @if (filled($selected) && ! in_array($selected, $options, true))
                                        <option value="{{ $selected }}" selected>{{ $selected }}</option>
                                    @endif
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                @if (($line['quantity'] ?? null) !== null && ! in_array(mb_strtolower((string) $qtyUnit), ['uur', 'uren', 'u'], true))
                                    {{ \App\Support\Format::qty($line['quantity'], 2) }} {{ $qtyLabel }}
                                @elseif ($contextMaterials !== [])
                                    —
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ \App\Support\Format::qty($line['hours'] ?? 0, 2) }}</td>
                            <td class="px-3 py-2">{{ ($line['hourly_rate'] ?? null) === null ? '—' : \App\Support\Format::euro($line['hourly_rate'], 2) }}</td>
                            <td class="px-3 py-2">{{ ($line['labor_cost'] ?? null) === null ? '—' : \App\Support\Format::euro($line['labor_cost'], 2) }}</td>
                            <td class="px-3 py-2 {{ $status === 'review' || $status === 'warning' ? 'font-medium text-nicon-warn' : 'text-nicon-muted' }}">
                                {{ $line['status_label'] ?? ($status === 'review' ? 'Handmatige controle vereist' : 'Automatisch bevestigd') }}
                                @if (! empty($line['warning']))
                                    <div class="text-xs font-normal">{{ $line['warning'] }}</div>
                                @endif
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
        @if ($products !== [])
            <h3 class="mt-4 text-sm font-medium">Hoeveelheden per werkzaamheid</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-nicon-sand text-left">
                        <tr>
                            <th class="px-3 py-2">Code</th>
                            <th class="px-3 py-2">Omschrijving</th>
                            <th class="px-3 py-2">
                                Meetstaat netto
                                <span class="ml-1 text-[10px] font-semibold uppercase tracking-wide text-nicon-ok">LEIDEND</span>
                            </th>
                            <th class="px-3 py-2">Excel</th>
                            <th class="px-3 py-2">Materialenstaat</th>
                            <th class="px-3 py-2">Verschil</th>
                            <th class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $product)
                            <tr class="border-t border-nicon-line">
                                <td class="px-3 py-2 font-medium">{{ $product['work_code'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $product['description'] ?? $product['name'] ?? '' }}</td>
                                <td class="px-3 py-2">
                                    {{ $product['meetstaat_quantity'] === null ? '—' : \App\Support\Format::qty($product['meetstaat_quantity'], 2) }}
                                    @if (($product['meetstaat_is_leading'] ?? false) && ($product['quantities_differ'] ?? false))
                                        <span class="ml-1 text-[10px] font-semibold uppercase tracking-wide text-nicon-ok">LEIDEND</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $product['excel_quantity'] === null ? '—' : \App\Support\Format::qty($product['excel_quantity'], 2) }}</td>
                                <td class="px-3 py-2">{{ $product['materialenstaat_quantity'] === null ? '—' : \App\Support\Format::qty($product['materialenstaat_quantity'], 2) }}</td>
                                <td class="px-3 py-2">
                                    @if (($product['difference'] ?? null) !== null)
                                        {{ (($product['difference'] ?? 0) > 0 ? '+' : '') . \App\Support\Format::qty($product['difference'], 2) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-3 py-2 {{ in_array($product['status'] ?? '', ['warning', 'review'], true) ? 'text-nicon-warn' : 'text-nicon-muted' }}">
                                    {{ $product['status_label'] ?? '' }}
                                    @if (! empty($product['message']))
                                        <div class="text-xs">{{ $product['message'] }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if ($sourceChecks !== [])
            <ul class="mt-3 text-xs text-nicon-muted">
                @foreach ($sourceChecks as $check)
                    <li>
                        {{ $check['label'] ?? '' }}:
                        {{ ($check['status'] ?? '') === 'confirmed' ? 'automatisch bevestigd' : (($check['status'] ?? '') === 'warning' ? 'waarschuwing' : 'niet vergeleken') }}
                    </li>
                @endforeach
            </ul>
        @endif
    </x-review-fold>
@endif
