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
            const setFieldsEnabled = (row, enabled) => {
                row.querySelectorAll('[data-shop-activity-notes] input, [data-shop-activity-notes] select').forEach((el) => {
                    el.disabled = !enabled;
                });
            };
            const updateCosts = () => {
                const rate = parseAmount(rateInput?.value) || {{ \App\Enums\SmallWorkType::HOURLY_RATE }};
                document.querySelectorAll('[data-shop-activity]').forEach((row) => {
                    const checked = row.querySelector('[data-shop-activity-toggle]')?.checked;
                    const hours = parseAmount(row.querySelector('[data-shop-activity-hours]')?.value);
                    const out = row.querySelector('[data-shop-activity-cost]');
                    if (! out) {
                        return;
                    }
                    out.textContent = checked && hours > 0.0001 ? formatEuro(hours * rate) : '';
                });
            };

            document.querySelectorAll('[data-shop-activity-toggle]').forEach((input) => {
                const row = input.closest('[data-shop-activity]');
                if (row) {
                    setFieldsEnabled(row, input.checked);
                }
                input.addEventListener('change', () => {
                    if (row) {
                        setFieldsEnabled(row, input.checked);
                    }
                    updateCosts();
                });
            });
            document.querySelectorAll('[data-shop-activity-hours]').forEach((input) => {
                input.addEventListener('input', updateCosts);
            });
            rateInput?.addEventListener('input', updateCosts);
            updateCosts();
        });
    </script>
@endpushOnce
<fieldset class="space-y-4">
    <legend class="text-xs uppercase tracking-wide text-nicon-muted">Werkzaamheden</legend>
    <p class="text-sm text-nicon-muted">Vier groepen naast elkaar. Aantal en uren blijven zichtbaar; invullen kan na aanvinken.</p>
    @error('work_activity_ids')
        <p class="text-sm text-nicon-danger">{{ $message }}</p>
    @enderror
    @error('work_activity_ids.0')
        <p class="text-sm text-nicon-danger">{{ $message }}</p>
    @enderror
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($categories as $category)
            <div class="border border-nicon-line p-3">
                <h3 class="text-xs uppercase tracking-wide text-nicon-muted">{{ $category->name }}</h3>
                <div class="mt-2 space-y-2">
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
                            $hoursNumber = is_numeric($hoursValue) ? (float) $hoursValue : 0.0;
                            $costLabel = $hoursNumber > 0.0001 ? \App\Support\Format::euroWhole($hoursNumber * $hourlyRate) : '';
                        @endphp
                        <div data-shop-activity>
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="work_activity_ids[]"
                                    value="{{ $activity->id }}"
                                    class="mt-0.5"
                                    data-shop-activity-toggle
                                    @checked($checked)
                                >
                                <span>{{ $activity->name }}</span>
                            </label>
                            <div class="mt-1.5 space-y-1.5 pl-6" data-shop-activity-notes>
                                <div class="grid grid-cols-2 gap-1.5">
                                    <div>
                                        <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="activity-quantity-{{ $activity->id }}">Aantal</label>
                                        <div class="mt-1 flex gap-1">
                                            <input
                                                id="activity-quantity-{{ $activity->id }}"
                                                type="text"
                                                inputmode="decimal"
                                                name="activity_quantities[{{ $activity->id }}]"
                                                value="{{ $quantityValue }}"
                                                class="min-w-0 grow border border-nicon-line px-2 py-1.5 text-sm disabled:bg-nicon-sand disabled:text-nicon-muted"
                                                placeholder="0"
                                                @disabled(! $checked)
                                            >
                                            <select
                                                id="activity-unit-{{ $activity->id }}"
                                                name="activity_units[{{ $activity->id }}]"
                                                class="w-20 shrink-0 border border-nicon-line px-1 py-1.5 text-sm disabled:bg-nicon-sand disabled:text-nicon-muted"
                                                title="Eenheid"
                                                @disabled(! $checked)
                                            >
                                                @foreach (\App\Enums\WorkUnit::shopCases() as $unit)
                                                    <option value="{{ $unit->value }}" @selected($unitValue === $unit->value)>{{ $unit->label() }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="activity-hours-{{ $activity->id }}">Uren</label>
                                        <input
                                            id="activity-hours-{{ $activity->id }}"
                                            type="text"
                                            inputmode="decimal"
                                            name="activity_hours[{{ $activity->id }}]"
                                            value="{{ $hoursValue }}"
                                            data-shop-activity-hours
                                            class="mt-1 w-full border border-nicon-line px-2 py-1.5 text-sm disabled:bg-nicon-sand disabled:text-nicon-muted"
                                            placeholder="0"
                                            @disabled(! $checked)
                                        >
                                        <div class="mt-0.5 min-h-4 text-[11px] text-nicon-muted" data-shop-activity-cost>{{ $costLabel }}</div>
                                    </div>
                                </div>
                                <div>
                                    <label class="sr-only" for="activity-note-{{ $activity->id }}">Omschrijving {{ $activity->name }}</label>
                                    <input
                                        id="activity-note-{{ $activity->id }}"
                                        type="text"
                                        name="activity_notes[{{ $activity->id }}]"
                                        value="{{ old('activity_notes.'.$activity->id, $activityNotes[$activity->id] ?? '') }}"
                                        class="w-full border border-nicon-line px-2 py-1.5 text-sm disabled:bg-nicon-sand disabled:text-nicon-muted"
                                        placeholder="Korte omschrijving"
                                        @disabled(! $checked)
                                    >
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</fieldset>
