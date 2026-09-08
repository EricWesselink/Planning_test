<form method="POST" action="{{ route('workers.rates.store', $worker) }}" class="mt-8 max-w-2xl border border-nicon-line bg-white p-5 space-y-4">
    @csrf
    <div>
        <h2 class="font-semibold">Afgesproken prijzen</h2>
        <p class="text-sm text-nicon-muted">Deze prijzen komen automatisch op een opdrachtbon of facturatiebon. Op de bon zelf kun je ze nog overschrijven.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-nicon-muted">
                <tr>
                    <th class="py-2 font-medium">Onderdeel</th>
                    <th class="py-2 font-medium">Eenheid</th>
                    <th class="py-2 font-medium">Prijs</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rateRows as $index => $row)
                    <tr class="border-t border-nicon-line">
                        <td class="py-2 pr-3">
                            {{ $row['label'] }}
                            <input type="hidden" name="rates[{{ $index }}][specialty]" value="{{ $row['specialty'] }}">
                        </td>
                        <td class="py-2 pr-3">
                            @if (! empty($row['unit_locked']))
                                {{ \App\Enums\WorkUnit::from($row['unit'])->label() }}
                                <input type="hidden" name="rates[{{ $index }}][unit]" value="{{ $row['unit'] }}">
                            @else
                                <select name="rates[{{ $index }}][unit]" class="border border-nicon-line px-2 py-1 bg-white">
                                    @foreach (\App\Enums\WorkUnit::cases() as $unit)
                                        <option value="{{ $unit->value }}" @selected(old('rates.'.$index.'.unit', $row['unit']) === $unit->value)>{{ $unit->label() }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                        <td class="py-2">
                            <div class="flex items-center gap-1">
                                <span class="text-nicon-muted">€</span>
                                <input type="text" inputmode="decimal" name="rates[{{ $index }}][unit_price]" value="{{ old('rates.'.$index.'.unit_price', $row['unit_price']) }}" class="w-28 border border-nicon-line px-2 py-1 bg-white" placeholder="leeg = geen prijs">
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Prijzen opslaan</button>
</form>
