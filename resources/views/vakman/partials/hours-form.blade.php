@php
    $slots = array_values($slots ?? []);
    $entries = collect($slots)->pluck('entry')->filter();
    $timed = $entries->first(fn ($row) => $row->hasSubmittedTimes());
    $locked = $entries->isNotEmpty() && $entries->every(fn ($row) => $row->isApproved());
    if ($timed !== null) {
        $startValue = old('start_time', $timed->startTimeLabel() ?? '');
        $endValue = old('end_time', $timed->endTimeLabel() ?? '');
        $breakValue = old('break_minutes', $timed->break_minutes ?? 0);
    } elseif ($prefillStandardDay ?? false) {
        $startValue = old('start_time', \App\Support\PlanningHours::REGISTERED_DAY_START);
        $endValue = old('end_time', \App\Support\PlanningHours::REGISTERED_DAY_END);
        $breakValue = old('break_minutes', \App\Support\PlanningHours::REGISTERED_BREAK_MINUTES);
    } else {
        $startValue = old('start_time', '');
        $endValue = old('end_time', '');
        $breakValue = old('break_minutes', '');
    }
    $noteValue = old('note', $entries->pluck('note')->filter()->first());
    $submittedTotal = round($entries->sum(fn ($row): float => $row->submittedHoursValue()), 2);
    $statusText = null;
    $statusClass = '';
    if ($entries->isNotEmpty() && $entries->every(fn ($row) => $row->isAdjusted())) {
        $statusText = 'Aangepast & goedgekeurd';
        $statusClass = ' is-adjusted';
    } elseif ($locked) {
        $approvedTotal = round($entries->sum(fn ($row): float => $row->approvedHoursValue() ?? 0), 2);
        $statusText = '✓ '.\App\Support\PlanningHours::hoursLabel($approvedTotal).' goedgekeurd';
        $statusClass = ' is-approved';
    } elseif ($entries->contains(fn ($row) => $row->isRejected())) {
        $statusText = 'Afgewezen / Ter correctie';
        $statusClass = ' is-rejected';
    } elseif ($entries->isNotEmpty()) {
        $statusText = \App\Support\PlanningHours::hoursLabel($submittedTotal).' ingediend';
    }
@endphp
<div class="vakman-hours">
    @if ($statusText)
        <p class="vakman-hours-status{{ $statusClass }}">{{ $statusText }}</p>
    @endif
    @if ($entries->isNotEmpty())
        <dl class="vakman-hours-history">
            @if ($timed)
                <div><span>Ingediend</span> {{ $timed->submittedIntervalLabel() }}</div>
                <div><span>Pauze</span> {{ $timed->breakLabel() }}</div>
            @endif
            <div><span>Ingediend totaal</span> {{ \App\Support\PlanningHours::hoursLabel($submittedTotal) }}</div>
            @foreach ($slots as $slot)
                @if ($slot['entry'])
                    <div>
                        <span>{{ $slot['work_title'] }}</span>
                        {{ $slot['entry']->hoursLabel() }}
                        @if ($slot['entry']->isApproved())
                            · goedgekeurd {{ $slot['entry']->approvedHoursLabel() }}
                        @endif
                    </div>
                @endif
            @endforeach
            @if ($entries->pluck('review_note')->filter()->first())
                <div><span>Opmerking beoordelaar</span> {{ $entries->pluck('review_note')->filter()->first() }}</div>
            @endif
            @php $reviewer = $entries->first(fn ($row) => $row->reviewer && $row->reviewed_at); @endphp
            @if ($reviewer)
                <div><span>Beoordeeld door</span> {{ $reviewer->reviewer->name }}, {{ $reviewer->reviewed_at->timezone(config('app.timezone'))->format('d-m-Y H:i') }}</div>
            @endif
        </dl>
    @endif
    @if (! $locked)
        <form method="POST" action="{{ route('vakman.hours.store') }}" class="vakman-hours-form" data-hours-clock>
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            @if ($projectId ?? null)
                <input type="hidden" name="project_id" value="{{ $projectId }}">
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
            <div class="vakman-hours-split">
                @foreach ($slots as $index => $slot)
                    @php
                        $hourValue = old('allocations.'.$index.'.hours');
                        if ($hourValue === null && $slot['entry']) {
                            $hourValue = \App\Support\PlanningHours::hourInput($slot['entry']->submittedHoursValue());
                        }
                        if ($hourValue === null && count($slots) === 1 && ($prefillStandardDay ?? false)) {
                            $hourValue = \App\Support\PlanningHours::hourInput(\App\Support\PlanningHours::netHours(
                                \App\Support\PlanningHours::REGISTERED_DAY_START,
                                \App\Support\PlanningHours::REGISTERED_DAY_END,
                                \App\Support\PlanningHours::REGISTERED_BREAK_MINUTES,
                            ));
                        }
                    @endphp
                    <label class="vakman-hours-line">
                        <span>
                            {{ $slot['work_title'] }}
                            <span class="vakman-hours-plan">gepland {{ \App\Support\PlanningHours::hoursLabel($slot['planned_hours'] ?? 0) }}</span>
                        </span>
                        <input type="hidden" name="allocations[{{ $index }}][worker_assignment_id]" value="{{ $slot['assignment_id'] }}">
                        <input type="hidden" name="allocations[{{ $index }}][work_item_id]" value="{{ $slot['work_item_id'] }}">
                        <input
                            type="number"
                            name="allocations[{{ $index }}][hours]"
                            min="0"
                            max="24"
                            step="0.25"
                            required
                            value="{{ $hourValue }}"
                            inputmode="decimal"
                            data-split-hours
                        >
                        <span>uur</span>
                    </label>
                @endforeach
            </div>
            <p class="vakman-hours-remainder" data-split-remainder></p>
            @error('allocations')
                <p class="vakman-hours-remainder is-bad">{{ $message }}</p>
            @enderror
            <label class="vakman-hours-field">
                <span>Opmerking (optioneel)</span>
                <input type="text" name="note" value="{{ $noteValue }}" maxlength="2000">
            </label>
            <button class="vakman-job-btn vakman-job-btn-ink" data-split-submit>{{ $entries->isNotEmpty() ? 'Uren aanpassen' : 'Uren indienen' }}</button>
        </form>
    @endif
</div>
@once
    @push('scripts')
        @vite(['resources/js/vakman-hours.js'])
    @endpush
@endonce
