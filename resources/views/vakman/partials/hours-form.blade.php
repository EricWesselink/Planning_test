@php
    $entry = $entry ?? null;
    $locked = $entry?->isApproved() ?? false;
    $hoursValue = old('hours', $entry?->hoursValue() ?? ($plannedHours ?? 8));
    $noteValue = old('note', $entry?->note);
    $statusText = null;
    if ($entry?->isApproved()) {
        $statusText = '✓ '.$entry->hoursLabel().' goedgekeurd';
    } elseif ($entry) {
        $statusText = $entry->hoursLabel().' ingediend';
    }
@endphp
<div class="vakman-hours">
    @if ($statusText)
        <p class="vakman-hours-status{{ $locked ? ' is-approved' : '' }}">{{ $statusText }}</p>
    @endif
    @if (! $locked)
        <form method="POST" action="{{ $entry ? route('vakman.hours.update', $entry) : route('vakman.hours.store') }}" class="vakman-hours-form">
            @csrf
            @if ($entry)
                @method('PATCH')
            @else
                <input type="hidden" name="date" value="{{ $date }}">
                @if ($assignmentId ?? null)
                    <input type="hidden" name="worker_assignment_id" value="{{ $assignmentId }}">
                @endif
                @if ($projectId ?? null)
                    <input type="hidden" name="project_id" value="{{ $projectId }}">
                @endif
                @if ($workItemId ?? null)
                    <input type="hidden" name="work_item_id" value="{{ $workItemId }}">
                @endif
            @endif
            <label class="vakman-hours-field">
                <span>Gewerkte uren</span>
                <input type="number" name="hours" min="0.25" max="24" step="0.25" required value="{{ $hoursValue }}" inputmode="decimal">
            </label>
            <label class="vakman-hours-field">
                <span>Opmerking (optioneel)</span>
                <input type="text" name="note" value="{{ $noteValue }}" maxlength="2000">
            </label>
            <button class="vakman-job-btn vakman-job-btn-ink">{{ $entry ? 'Uren aanpassen' : 'Uren invullen' }}</button>
        </form>
    @endif
</div>
