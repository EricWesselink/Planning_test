@php
    $selectedIds = collect($selectedIds ?? [])->map(fn ($id) => (int) $id);
    $activityNotes = $activityNotes ?? [];
    $activityQuantities = $activityQuantities ?? [];
    $activityUnits = $activityUnits ?? [];
    $activityHours = $activityHours ?? [];
    $hourlyRate = is_numeric($hourlyRate ?? null) ? (float) $hourlyRate : \App\Enums\SmallWorkType::HOURLY_RATE;
@endphp
@pushOnce('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const rateInput = document.querySelector('#basis_uurtarief');
            const parseAmount = (value) => {
                let text = String(value ?? '').trim();
                if (text.includes(',')) {
                    text = text.replace(/\./g, '').replace(',', '.');
                }
                const number = Number.parseFloat(text);

                return Number.isFinite(number) ? number : 0;
            };
            const formatEuro = (value) => {
                const rounded = Math.round(value * 100) / 100;
                const decimals = Math.abs(rounded - Math.round(rounded)) < 0.001 ? 0 : 2;

                return new Intl.NumberFormat('nl-NL', {
                    style: 'currency',
                    currency: 'EUR',
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals,
                }).format(rounded);
            };
            const setOpen = (row, enabled) => {
                row.querySelectorAll('[data-shop-activity-details]').forEach((el) => {
                    el.classList.toggle('hidden', !enabled);
                    el.querySelectorAll('input, select').forEach((input) => {
                        input.disabled = !enabled;
                    });
                });
            };
            const updateCosts = () => {
                const rate = parseAmount(rateInput?.value) || {{ \App\Enums\SmallWorkType::HOURLY_RATE }};
                document.querySelectorAll('[data-shop-activity]').forEach((row) => {
                    const checked = row.querySelector('[data-shop-activity-toggle]')?.checked;
                    const hours = parseAmount(row.querySelector('[data-shop-activity-hours]')?.value);
                    const quantity = parseAmount(row.querySelector('[data-shop-activity-quantity]')?.value);
                    const unitSelect = row.querySelector('[data-shop-activity-unit]');
                    const unit = unitSelect?.value;
                    const out = row.querySelector('[data-shop-activity-cost]');
                    if (! out) {
                        return;
                    }
                    if (! checked || hours <= 0.0001) {
                        out.textContent = '';

                        return;
                    }
                    const total = hours * rate;
                    const parts = [formatEuro(total)];
                    if ((unit === 'm2' || unit === 'm1') && quantity > 0.0001) {
                        const unitLabel = unitSelect?.selectedOptions?.[0]?.text ?? (unit === 'm2' ? 'm²' : 'm¹');
                        parts.push(`${formatEuro(total / quantity)}/${unitLabel}`);
                    }
                    out.textContent = parts.join(' · ');
                });
            };

            document.querySelectorAll('[data-shop-activity-toggle]').forEach((input) => {
                const row = input.closest('[data-shop-activity]');
                if (row) {
                    setOpen(row, input.checked);
                }
                input.addEventListener('change', () => {
                    if (row) {
                        setOpen(row, input.checked);
                    }
                    updateCosts();
                });
            });
            document.querySelectorAll('[data-shop-activity-hours], [data-shop-activity-quantity]').forEach((input) => {
                input.addEventListener('input', updateCosts);
            });
            document.querySelectorAll('[data-shop-activity-unit]').forEach((input) => {
                input.addEventListener('change', updateCosts);
            });
            rateInput?.addEventListener('input', updateCosts);
            updateCosts();
        });
    </script>
@endpushOnce
<fieldset class="space-y-3">
    <legend class="text-xs uppercase tracking-wide text-nicon-muted">Werkzaamheden</legend>
    <p class="text-sm text-nicon-muted">Vink aan om aantal, uren en een korte omschrijving in te vullen.</p>
    @error('work_activity_ids')
        <p class="text-sm text-nicon-danger">{{ $message }}</p>
    @enderror
    @error('work_activity_ids.0')
        <p class="text-sm text-nicon-danger">{{ $message }}</p>
    @enderror
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($categories as $category)
            <div class="border border-nicon-line p-2">
                <h3 class="text-xs uppercase tracking-wide text-nicon-muted">{{ $category->name }}</h3>
                <div class="mt-2 flex flex-col gap-2">
                    @foreach ($category->activities as $activity)
                        @php
                            $checked = $selectedIds->contains((int) $activity->id);
                            $quantityValue = old('activity_quantities.'.$activity->id, $activityQuantities[$activity->id] ?? '');
                            if (is_numeric($quantityValue) && fmod((float) $quantityValue, 1.0) === 0.0) {
                                $quantityValue = (int) (float) $quantityValue;
                            }
                            $hoursValue = old('activity_hours.'.$activity->id, $activityHours[$activity->id] ?? '');
                            if (is_numeric($hoursValue) && fmod((float) $hoursValue, 1.0) === 0.0) {
                                $hoursValue = (int) (float) $hoursValue;
                            }
                            $unitValue = old('activity_units.'.$activity->id, $activityUnits[$activity->id] ?? $activity->defaultShopUnit($category)->value);
                            $quantityNumber = is_numeric($quantityValue)
                                ? (float) $quantityValue
                                : (float) \App\Support\Format::decimalInput($quantityValue);
                            $hoursNumber = is_numeric($hoursValue)
                                ? (float) $hoursValue
                                : (float) \App\Support\Format::decimalInput($hoursValue);
                            $totalCost = $hoursNumber * $hourlyRate;
                            $costLabel = '';
                            if ($checked && $hoursNumber > 0.0001) {
                                $costLabel = \App\Support\Format::euroWhole($totalCost);
                                $unit = \App\Enums\WorkUnit::tryFrom((string) $unitValue);
                                if (
                                    $quantityNumber > 0.0001
                                    && ($unit === \App\Enums\WorkUnit::SquareMeter || $unit === \App\Enums\WorkUnit::LinearMeter)
                                ) {
                                    $costLabel .= ' · '.\App\Support\Format::euroWhole($totalCost / $quantityNumber).'/'.$unit->label();
                                }
                            }
                        @endphp
                        <div class="flex flex-col gap-1" data-shop-activity @if ($category->slug === 'vloeren' && $activity->isMeasurementProduct($category)) data-shop-floor-product data-activity-name="{{ $activity->name }}" @endif>
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                <label class="flex items-center gap-1.5">
                                    <input
                                        type="checkbox"
                                        name="work_activity_ids[]"
                                        value="{{ $activity->id }}"
                                        class="shrink-0"
                                        data-shop-activity-toggle
                                        @checked($checked)
                                    >
                                    <span>{{ $activity->name }}</span>
                                </label>
                                <div
                                    id="activity-details-{{ $activity->id }}"
                                    @class(['hidden' => ! $checked])
                                    data-shop-activity-details
                                >
                                    <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                        <label class="text-xs text-nicon-muted" for="activity-quantity-{{ $activity->id }}">Aantal</label>
                                        <input
                                            id="activity-quantity-{{ $activity->id }}"
                                            type="text"
                                            inputmode="decimal"
                                            name="activity_quantities[{{ $activity->id }}]"
                                            value="{{ $quantityValue }}"
                                            data-shop-activity-quantity
                                            class="w-24 shrink-0 border border-nicon-line px-1.5 py-1 text-sm"
                                            placeholder="0"
                                            @disabled(! $checked)
                                        >
                                        <select
                                            id="activity-unit-{{ $activity->id }}"
                                            name="activity_units[{{ $activity->id }}]"
                                            data-shop-activity-unit
                                            class="w-14 shrink-0 border border-nicon-line px-1 py-1 text-sm"
                                            title="Eenheid"
                                            @disabled(! $checked)
                                        >
                                            @foreach (\App\Enums\WorkUnit::shopCases() as $unit)
                                                <option value="{{ $unit->value }}" @selected($unitValue === $unit->value)>{{ $unit->label() }}</option>
                                            @endforeach
                                        </select>
                                        <label class="text-xs text-nicon-muted" for="activity-hours-{{ $activity->id }}">Uren</label>
                                        <input
                                            id="activity-hours-{{ $activity->id }}"
                                            type="text"
                                            inputmode="decimal"
                                            name="activity_hours[{{ $activity->id }}]"
                                            value="{{ $hoursValue }}"
                                            data-shop-activity-hours
                                            class="w-20 shrink-0 border border-nicon-line px-1.5 py-1 text-sm"
                                            placeholder="0"
                                            @disabled(! $checked)
                                        >
                                        <span class="text-[11px] whitespace-nowrap text-nicon-muted" data-shop-activity-cost>{{ $costLabel }}</span>
                                    </div>
                                </div>
                            </div>
                            <div
                                id="activity-note-row-{{ $activity->id }}"
                                @class(['hidden' => ! $checked])
                                data-shop-activity-details
                            >
                                <label class="sr-only" for="activity-note-{{ $activity->id }}">Omschrijving {{ $activity->name }}</label>
                                <input
                                    id="activity-note-{{ $activity->id }}"
                                    type="text"
                                    name="activity_notes[{{ $activity->id }}]"
                                    value="{{ old('activity_notes.'.$activity->id, $activityNotes[$activity->id] ?? '') }}"
                                    class="w-full border border-nicon-line px-1.5 py-1 text-sm"
                                    placeholder="Korte omschrijving"
                                    @disabled(! $checked)
                                >
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</fieldset>
