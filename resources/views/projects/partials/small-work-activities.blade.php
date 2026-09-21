@php
    $floorActivities = $floorActivities ?? collect();
    $selectedIds = collect($selectedIds ?? [])->map(fn ($id) => (int) $id);
    $activityQuantities = $activityQuantities ?? [];
    $activityNotes = $activityNotes ?? [];
    $canUpdate = $canUpdate ?? true;
@endphp
@if ($floorActivities->isNotEmpty())
    <fieldset>
        <legend class="text-xs uppercase tracking-wide text-nicon-muted">Werkzaamheden</legend>
        <p class="mt-1 text-sm text-nicon-muted">Vink aan wat er gedaan moet worden. Vul daarna de hoeveelheid en een korte omschrijving in.</p>
        @error('work_activity_ids')
            <p class="mt-1 text-sm text-nicon-danger">{{ $message }}</p>
        @enderror
        @error('activity_quantities.*')
            <p class="mt-1 text-sm text-nicon-danger">{{ $message }}</p>
        @enderror
        @error('activity_notes.*')
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
                    $unitLabel = $activity->defaultShopUnit()->label();
                @endphp
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-1.5 text-sm" data-small-activity>
                    <label class="flex shrink-0 items-center gap-2">
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
                        @class(['flex min-w-0 flex-1 items-center gap-1.5', 'hidden' => ! $checked])
                        data-small-activity-details
                    >
                        <label class="sr-only" for="small-activity-quantity-{{ $activity->id }}">Hoeveelheid {{ $activity->name }}</label>
                        <input
                            id="small-activity-quantity-{{ $activity->id }}"
                            type="text"
                            inputmode="decimal"
                            name="activity_quantities[{{ $activity->id }}]"
                            value="{{ $quantityValue }}"
                            class="w-20 shrink-0 border border-nicon-line px-1.5 py-1 text-sm"
                            placeholder="0"
                            @disabled(! $checked || ! $canUpdate)
                        >
                        <span class="shrink-0 text-xs text-nicon-muted">{{ $unitLabel }}</span>
                        <label class="sr-only" for="small-activity-note-input-{{ $activity->id }}">Korte omschrijving {{ $activity->name }}</label>
                        <input
                            id="small-activity-note-input-{{ $activity->id }}"
                            type="text"
                            name="activity_notes[{{ $activity->id }}]"
                            value="{{ old('activity_notes.'.$activity->id, $activityNotes[$activity->id] ?? '') }}"
                            class="min-w-40 flex-1 border border-nicon-line px-1.5 py-1 text-sm"
                            placeholder="Korte omschrijving"
                            @disabled(! $checked || ! $canUpdate)
                        >
                    </div>
                </div>
            @endforeach
        </div>
    </fieldset>
    <script>
        document.querySelectorAll('[data-small-activity]').forEach(function (row) {
            if (row.dataset.smallActivityBound === '1') {
                return;
            }
            row.dataset.smallActivityBound = '1';
            var toggle = row.querySelector('[data-small-activity-toggle]');
            var details = row.querySelector('[data-small-activity-details]');
            if (! toggle || ! details) {
                return;
            }
            var sync = function () {
                var enabled = Boolean(toggle.checked) && ! toggle.disabled;
                details.classList.toggle('hidden', ! toggle.checked);
                details.querySelectorAll('input').forEach(function (field) {
                    field.disabled = ! enabled;
                });
            };
            toggle.addEventListener('change', sync);
            sync();
        });
    </script>
@endif
