@php
    $selectedIds = collect($selectedIds ?? [])->map(fn ($id) => (int) $id);
    $activityNotes = $activityNotes ?? [];
    $activityQuantities = $activityQuantities ?? [];
    $activityUnits = $activityUnits ?? [];
@endphp
<fieldset class="space-y-5">
    <legend class="text-xs uppercase tracking-wide text-nicon-muted">Werkzaamheden</legend>
    <p class="text-sm text-nicon-muted">Vink alles aan wat bij dit Winkelwerk hoort. Meerdere keuzes tegelijk zijn mogelijk.</p>
    @error('work_activity_ids')
        <p class="text-sm text-nicon-danger">{{ $message }}</p>
    @enderror
    @error('work_activity_ids.0')
        <p class="text-sm text-nicon-danger">{{ $message }}</p>
    @enderror
    <div class="grid gap-5 md:grid-cols-2">
        @foreach ($categories as $category)
            <div class="border border-nicon-line p-4">
                <h3 class="text-xs uppercase tracking-wide text-nicon-muted">{{ $category->name }}</h3>
                <div class="mt-3 space-y-3">
                    @foreach ($category->activities as $activity)
                        @php
                            $checked = $selectedIds->contains((int) $activity->id);
                            $quantityValue = old('activity_quantities.'.$activity->id, $activityQuantities[$activity->id] ?? '');
                            if (is_numeric($quantityValue) && fmod((float) $quantityValue, 1.0) === 0.0) {
                                $quantityValue = (int) (float) $quantityValue;
                            }
                            $unitValue = old('activity_units.'.$activity->id, $activityUnits[$activity->id] ?? $activity->defaultShopUnit($category)->value);
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
                            <div class="mt-1 space-y-2 pl-6 {{ $checked ? '' : 'hidden' }}" data-shop-activity-notes>
                                <div class="flex flex-wrap items-end gap-2">
                                    <div>
                                        <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="activity-quantity-{{ $activity->id }}">Aantal</label>
                                        <input
                                            id="activity-quantity-{{ $activity->id }}"
                                            type="text"
                                            inputmode="decimal"
                                            name="activity_quantities[{{ $activity->id }}]"
                                            value="{{ $quantityValue }}"
                                            class="mt-1 w-24 border border-nicon-line px-3 py-2 text-sm"
                                            placeholder="0"
                                        >
                                    </div>
                                    <div>
                                        <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="activity-unit-{{ $activity->id }}">Eenheid</label>
                                        <select
                                            id="activity-unit-{{ $activity->id }}"
                                            name="activity_units[{{ $activity->id }}]"
                                            class="mt-1 border border-nicon-line px-3 py-2 text-sm"
                                        >
                                            @foreach (\App\Enums\WorkUnit::shopCases() as $unit)
                                                <option value="{{ $unit->value }}" @selected($unitValue === $unit->value)>{{ $unit->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="activity-note-{{ $activity->id }}">Omschrijving {{ $activity->name }}</label>
                                    <textarea
                                        id="activity-note-{{ $activity->id }}"
                                        name="activity_notes[{{ $activity->id }}]"
                                        rows="2"
                                        class="mt-1 w-full border border-nicon-line px-3 py-2 text-sm"
                                        placeholder="Bijvoorbeeld: plaatsen achterzijde woning"
                                    >{{ old('activity_notes.'.$activity->id, $activityNotes[$activity->id] ?? '') }}</textarea>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</fieldset>
