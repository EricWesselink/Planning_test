@php
    $floorActivities = $floorActivities ?? collect();
    $selectedIds = collect($selectedIds ?? [])->map(fn ($id) => (int) $id);
    $canUpdate = $canUpdate ?? true;
@endphp
@if ($floorActivities->isNotEmpty())
    <fieldset>
        <legend class="text-xs uppercase tracking-wide text-nicon-muted">Werkzaamheden</legend>
        <p class="mt-1 text-sm text-nicon-muted">Vink aan wat er gedaan moet worden.</p>
        <div class="mt-2 border border-nicon-line divide-y divide-nicon-line">
            @foreach ($floorActivities as $activity)
                <label class="flex items-center gap-2 px-3 py-2 text-sm">
                    <input
                        type="checkbox"
                        name="work_activity_ids[]"
                        value="{{ $activity->id }}"
                        class="shrink-0"
                        @checked($selectedIds->contains((int) $activity->id))
                        @disabled(! $canUpdate)
                    >
                    <span>{{ $activity->name }}</span>
                </label>
            @endforeach
        </div>
    </fieldset>
@endif
