@php
    $preferredWorkers = $preferredWorkers ?? [];
    $selectedWorkerId = old('worker_id', $selectedWorkerId ?? null);
    $multiplePreferredWorkers = $multiplePreferredWorkers ?? false;
    $projectId = $project?->id;
    $canUpdate = $canUpdate ?? true;
@endphp
<div>
    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="worker_id">Voorkeur vakman</label>
    <select
        id="worker_id"
        name="worker_id"
        class="mt-1 w-full border border-nicon-line bg-white px-2 py-1.5"
        data-preferred-worker
        data-available-url="{{ route('projects.winkel.available-workers') }}"
        @if ($projectId) data-project-id="{{ $projectId }}" @endif
        @disabled(! $canUpdate)
    >
        <option value="">{{ $multiplePreferredWorkers ? 'Meerdere vakmannen — wijzig in de planning' : 'Geen voorkeur' }}</option>
        @foreach ($preferredWorkers as $worker)
            <option
                value="{{ $worker['id'] }}"
                @selected((string) $selectedWorkerId === (string) $worker['id'])
                @disabled(! $worker['selectable'] && (string) $selectedWorkerId !== (string) $worker['id'])
            >
                {{ $worker['name'] }}@if (! $worker['selectable'] && $worker['status_label'] !== '') — {{ $worker['status_label'] }}@endif
            </option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-nicon-muted">Alleen als die nog vrij is in de gekozen weken. In de planning kun je dit altijd wijzigen.</p>
</div>
