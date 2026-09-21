@php
    $floorActivities = $floorActivities ?? collect();
    $selectedIds = collect($selectedIds ?? [])->map(fn ($id) => (int) $id);
    $activityQuantities = $activityQuantities ?? [];
    $activityUnits = $activityUnits ?? [];
    $canUpdate = $canUpdate ?? true;
@endphp
@if ($floorActivities->isNotEmpty())
    @pushOnce('scripts')
        <script>
            document.querySelectorAll('[data-small-activity]').forEach(function (row) {
                var toggle = row.querySelector('[data-small-activity-toggle]');
                var details = row.querySelector('[data-small-activity-details]');
                if (! toggle || ! details) {
                    return;
                }
                var sync = function () {
                    var enabled = Boolean(toggle.checked) && ! toggle.disabled;
                    details.classList.toggle('hidden', ! toggle.checked);
                    details.querySelectorAll('input, select').forEach(function (field) {
                        field.disabled = ! enabled;
                    });
                };
                toggle.addEventListener('change', sync);
                sync();
            });
        </script>
    @endpushOnce
    <fieldset>
        <legend class="text-xs uppercase tracking-wide text-nicon-muted">Werkzaamheden</legend>
        <p class="mt-1 text-sm text-nicon-muted">Vink aan wat er gedaan moet worden. Vul per onderdeel het aantal in (m² of m¹).</p>
        @error('work_activity_ids')
            <p class="mt-1 text-sm text-nicon-danger">{{ $message }}</p>
        @enderror
        @error('activity_quantities.*')
            <p class="mt-1 text-sm text-nicon-danger">{{ $message }}</p>
        @enderror
        @error('activity_units.*')
            <p class="mt-1 text-sm text-nicon-danger">{{ $message }}</p>
        @enderror
        <div class="mt-2 border border-nicon-line divide-y divide-nicon-line">
            @foreach ($floorActivities as $activity)
                @php
                    $checked = $selectedIds->contains((int) $activity->id);
                    $quantityValue = old('activity_quantities.'.$activity->id, $activityQuantities[$activity->id] ?? '');
                    if (is_numeric($quantityValue) && fmod((float) $quantityValue, 1.0) === 0.0) {
                        $quantityValue = (int) (float) $quantityValue;
                    }
                    $unitValue = old('activity_units.'.$activity->id, $activityUnits[$activity->id] ?? $activity->defaultShopUnit()->value);
                @endphp
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm" data-small-activity>
                    <label class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            name="work_activity_ids[]"
                            value="{{ $activity->id }}"
                            class="shrink-0"
                            data-small-activity-toggle
                            @checked($checked)
                            @disabled(! $canUpdate)
                        >
                        <span>{{ $activity->name }}</span>
                    </label>
                    <div
                        id="small-activity-details-{{ $activity->id }}"
                        @class(['hidden' => ! $checked])
                        data-small-activity-details
                    >
                        <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                            <label class="text-xs text-nicon-muted" for="small-activity-quantity-{{ $activity->id }}">Aantal</label>
                            <input
                                id="small-activity-quantity-{{ $activity->id }}"
                                type="text"
                                inputmode="decimal"
                                name="activity_quantities[{{ $activity->id }}]"
                                value="{{ $quantityValue }}"
                                class="w-24 shrink-0 border border-nicon-line px-1.5 py-1 text-sm"
                                placeholder="0"
                                @disabled(! $checked || ! $canUpdate)
                            >
                            <select
                                id="small-activity-unit-{{ $activity->id }}"
                                name="activity_units[{{ $activity->id }}]"
                                class="w-14 shrink-0 border border-nicon-line px-1 py-1 text-sm"
                                title="Eenheid"
                                @disabled(! $checked || ! $canUpdate)
                            >
                                @foreach (\App\Enums\WorkUnit::shopCases() as $unit)
                                    <option value="{{ $unit->value }}" @selected($unitValue === $unit->value)>{{ $unit->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </fieldset>
@endif
