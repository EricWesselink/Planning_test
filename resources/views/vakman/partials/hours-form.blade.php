@php
    $entry = $entry ?? null;
    $locked = $entry?->isApproved() ?? false;
    $hoursValue = old('hours', $entry?->submittedHoursValue() ?? ($plannedHours ?? 8));
    $noteValue = old('note', $entry?->note);
    $statusText = null;
    $statusClass = '';
    if ($entry?->isAdjusted()) {
        $statusText = 'Aangepast & goedgekeurd';
        $statusClass = ' is-adjusted';
    } elseif ($entry?->isApproved()) {
        $statusText = '✓ '.$entry->approvedHoursLabel().' goedgekeurd';
        $statusClass = ' is-approved';
    } elseif ($entry?->isRejected()) {
        $statusText = 'Afgewezen / Ter correctie';
        $statusClass = ' is-rejected';
    } elseif ($entry) {
        $statusText = $entry->hoursLabel().' ingediend';
    }
@endphp
<div class="vakman-hours">
    @if ($statusText)
        <p class="vakman-hours-status{{ $statusClass }}">{{ $statusText }}</p>
    @endif
    @if ($entry)
        <dl class="vakman-hours-history">
            <div><span>Ingediend</span> {{ $entry->hoursLabel() }}</div>
            @if ($entry->isApproved())
                <div><span>Goedgekeurd</span> {{ $entry->approvedHoursLabel() }}</div>
            @endif
            @if ($entry->isAdjusted())
                <div><span>Correctie</span> {{ $entry->hoursLabel() }} → {{ $entry->approvedHoursLabel() }}</div>
            @endif
            @if ($entry->review_note)
                <div><span>Opmerking beoordelaar</span> {{ $entry->review_note }}</div>
            @endif
            @if ($entry->reviewer && $entry->reviewed_at)
                <div><span>Beoordeeld door</span> {{ $entry->reviewer->name }}, {{ $entry->reviewed_at->timezone(config('app.timezone'))->format('d-m-Y H:i') }}</div>
            @endif
        </dl>
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
