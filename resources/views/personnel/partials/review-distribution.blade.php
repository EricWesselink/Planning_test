@php
    $entries = collect($group)->map(fn (array $row) => $row['entry']);
    $timed = $entries->first(fn ($entry) => $entry->hasSubmittedTimes());
    $submittedTotal = round($entries->sum(fn ($entry): float => $entry->submittedHoursValue()), 2);
    $first = $group[0];
    $formId = 'hour-distribution-'.$entries->first()->id;
    $canAdjust = $canReviewHours && $entries->contains(fn ($entry) => $entry->isSubmitted() || $entry->isApproved());
@endphp
<article class="mt-3 border-t border-nicon-line pt-3">
    <dl class="grid gap-1">
        <div><span class="text-nicon-muted">Medewerker:</span> {{ $entries->first()->personName() }}</div>
        <div><span class="text-nicon-muted">Datum:</span> {{ $detailDay->translatedFormat('l j F') }}</div>
        @if ($first['work_number'] !== '')
            <div><span class="text-nicon-muted">Werknummer:</span> {{ $first['work_number'] }}</div>
        @endif
        <div><span class="text-nicon-muted">Project:</span> {{ $first['project'] }}</div>
        @if ($timed)
            <div><span class="text-nicon-muted">Ingediend:</span> {{ $timed->submittedIntervalLabel() }}</div>
            <div><span class="text-nicon-muted">Pauze:</span> {{ $timed->breakLabel() }}</div>
        @endif
        <div><span class="text-nicon-muted">Ingediend totaal:</span> {{ \App\Support\PlanningHours::hoursLabel($submittedTotal) }}</div>
        @foreach ($group as $row)
            <div>
                <span class="text-nicon-muted">{{ $row['work'] }}:</span>
                {{ $row['entry']->hoursLabel() }}
                <span class="text-nicon-muted">· gepland {{ $row['planned_label'] }}</span>
                @if ($row['entry']->isApproved())
                    · goedgekeurd {{ $row['entry']->approvedHoursLabel() }}
                @endif
            </div>
        @endforeach
        <div>
            <span class="text-nicon-muted">Opmerking medewerker:</span>
            {{ $entries->pluck('note')->filter()->first() ?: '—' }}
        </div>
    </dl>

    @if ($canAdjust)
        <form id="{{ $formId }}" method="POST" action="{{ route('personnel.hours.distribution') }}" class="mt-3 grid gap-2">
            @csrf
            @method('PATCH')
            @foreach ($group as $row)
                @php
                    $entry = $row['entry'];
                    $current = $entry->isApproved() ? ($entry->approvedHoursValue() ?? $entry->submittedHoursValue()) : $entry->submittedHoursValue();
                @endphp
                <label class="grid grid-cols-[minmax(0,1fr)_6rem_auto] items-center gap-2 text-xs">
                    <span>{{ $row['work'] }}</span>
                    <input
                        type="number"
                        name="lines[{{ $entry->id }}]"
                        min="0"
                        max="24"
                        step="0.25"
                        required
                        value="{{ old('lines.'.$entry->id, \App\Support\PlanningHours::hourInput($current)) }}"
                        class="border border-nicon-line bg-white px-2 py-1"
                    >
                    <span>uur</span>
                </label>
            @endforeach
            <label class="grid gap-1 text-xs">
                <span class="uppercase tracking-wide text-nicon-muted">Reden/opmerking beoordelaar</span>
                <textarea name="review_note" rows="2" maxlength="2000" class="border border-nicon-line bg-white px-2 py-1">{{ old('review_note', $entries->pluck('review_note')->filter()->first()) }}</textarea>
                @error('review_note')
                    <span class="text-nicon-danger">{{ $message }}</span>
                @enderror
            </label>
            <button class="justify-self-start border border-nicon-ink bg-nicon-ink px-2 py-1 text-xs text-white">Aanpassen &amp; goedkeuren</button>
        </form>
    @endif
</article>
