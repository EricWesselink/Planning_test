@php
    $entry = $entry ?? null;
    $locked = $entry?->isApproved() ?? false;
    if ($entry !== null) {
        $startValue = old('start_time', $entry->startTimeLabel() ?? '');
        $endValue = old('end_time', $entry->endTimeLabel() ?? '');
        $breakValue = old('break_minutes', $entry->break_minutes ?? 0);
    } elseif ($prefillStandardDay ?? false) {
        $startValue = old('start_time', \App\Support\PlanningHours::REGISTERED_DAY_START);
        $endValue = old('end_time', \App\Support\PlanningHours::REGISTERED_DAY_END);
        $breakValue = old('break_minutes', \App\Support\PlanningHours::REGISTERED_BREAK_MINUTES);
    } else {
        $startValue = old('start_time', '');
        $endValue = old('end_time', '');
        $breakValue = old('break_minutes', '');
    }
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
        $statusText = ($entry->submittedIntervalLabel() ?? $entry->hoursLabel()).' ingediend';
    }
@endphp
<div class="vakman-hours">
    @if ($statusText)
        <p class="vakman-hours-status{{ $statusClass }}">{{ $statusText }}</p>
    @endif
    @if ($entry)
        <dl class="vakman-hours-history">
            <div><span>Ingediend</span> {{ $entry->submittedIntervalLabel() ?? $entry->hoursLabel() }}</div>
            @if ($entry->hasSubmittedTimes())
                <div><span>Pauze</span> {{ $entry->breakLabel() }}</div>
                <div><span>Netto</span> {{ $entry->hoursLabel() }}</div>
            @endif
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
        <form method="POST" action="{{ $entry ? route('vakman.hours.update', $entry) : route('vakman.hours.store') }}" class="vakman-hours-form" data-hours-clock>
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
            <div class="vakman-hours-times">
                <label class="vakman-hours-field">
                    <span>Van</span>
                    <input type="time" name="start_time" required value="{{ $startValue }}" data-clock-start>
                </label>
                <label class="vakman-hours-field">
                    <span>Tot</span>
                    <input type="time" name="end_time" required value="{{ $endValue }}" data-clock-end>
                </label>
                <label class="vakman-hours-field">
                    <span>Pauze</span>
                    <input type="number" name="break_minutes" min="0" max="1440" step="1" required value="{{ $breakValue }}" inputmode="numeric" data-clock-break>
                </label>
            </div>
            <p class="vakman-hours-total" data-clock-total>Totaal</p>
            <label class="vakman-hours-field">
                <span>Opmerking (optioneel)</span>
                <input type="text" name="note" value="{{ $noteValue }}" maxlength="2000">
            </label>
            <button class="vakman-job-btn vakman-job-btn-ink">{{ $entry ? 'Uren aanpassen' : 'Uren indienen' }}</button>
        </form>
    @endif
</div>
@once
    @push('scripts')
        @vite(['resources/js/vakman-hours.js'])
    @endpush
@endonce
