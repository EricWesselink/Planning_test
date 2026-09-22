@php
    $detailDay = \Carbon\Carbon::parse($detailDate);
@endphp
<div class="mt-4 max-w-xl border border-nicon-line bg-white p-4 text-sm">
    <h2 class="text-base font-semibold">{{ $detailDay->translatedFormat('l j F') }}</h2>
    @foreach ($dayDetails as $detail)
        @php
            /** @var \App\Models\TimeEntry $entry */
            $entry = $detail['entry'];
            $formId = 'hour-review-'.$entry->id;
        @endphp
        <article class="mt-3 border-t border-nicon-line pt-3">
            @if ($detail['is_unplanned'])
                <p class="text-xs font-medium text-nicon-warn">Niet gepland</p>
            @endif
            <dl class="grid gap-1">
                <div><span class="text-nicon-muted">Medewerker:</span> {{ $entry->personName() }}</div>
                <div><span class="text-nicon-muted">Datum:</span> {{ $detailDay->translatedFormat('l j F') }}</div>
                @if ($detail['work_number'] !== '')
                    <div><span class="text-nicon-muted">Werknummer:</span> {{ $detail['work_number'] }}</div>
                @endif
                <div><span class="text-nicon-muted">Werkzaamheid:</span> {{ $detail['work'] }}</div>
                <div><span class="text-nicon-muted">Project:</span> {{ $detail['project'] }}</div>
                <div><span class="text-nicon-muted">Gepland:</span> {{ $detail['planned_label'] }}</div>
                <div><span class="text-nicon-muted">Ingediend:</span> {{ $detail['submitted_label'] }}</div>
                @if ($detail['break_label'])
                    <div><span class="text-nicon-muted">Pauze:</span> {{ $detail['break_label'] }}</div>
                @endif
                @if ($detail['net_label'])
                    <div><span class="text-nicon-muted">Netto:</span> {{ $detail['net_label'] }}</div>
                @endif
                <div><span class="text-nicon-muted">Goedgekeurd:</span> {{ $detail['approved_label'] }}</div>
                <div @class(['text-nicon-warn' => abs($detail['difference']) > 0.01])>
                    <span class="text-nicon-muted">Verschil t.o.v. planning:</span> {{ $detail['difference_label'] }}
                </div>
                <div>
                    <span class="text-nicon-muted">Opmerking medewerker:</span>
                    {{ $detail['note'] ?: '—' }}
                </div>
                <div>
                    <span class="text-nicon-muted">Status:</span>
                    <span @class([
                        'font-medium',
                        'text-nicon-warn' => $entry->isSubmitted(),
                        'text-nicon-ok' => $entry->isApproved() && ! $entry->isAdjusted(),
                        'text-nicon-orange' => $entry->isAdjusted(),
                        'text-nicon-danger' => $entry->isRejected(),
                    ])>{{ $entry->reviewStatusLabel() }}</span>
                </div>
            </dl>

            @if ($entry->isApproved() || $entry->isRejected())
                <dl class="mt-3 grid gap-1 border border-nicon-line bg-nicon-paper p-3 text-xs">
                    <div>Ingediend door medewerker: {{ $entry->hoursLabel() }}</div>
                    @if ($entry->isApproved())
                        <div>Goedgekeurd door {{ $entry->reviewer?->name ?? 'beoordelaar' }}: {{ $entry->approvedHoursLabel() }}</div>
                    @endif
                    @if ($entry->review_note)
                        <div>Reden: {{ $entry->review_note }}</div>
                    @endif
                    @if ($entry->reviewed_at)
                        <div>Beoordeeld: {{ $entry->reviewed_at->timezone(config('app.timezone'))->format('d-m-Y H:i') }}</div>
                    @endif
                </dl>
                @if ($entry->isRejected())
                    <p class="mt-2 text-xs text-nicon-muted">De medewerker kan de uren aanpassen en opnieuw indienen.</p>
                @endif
            @endif

            @if ($canReviewHours && ($entry->isSubmitted() || $entry->isApproved()))
                <form id="{{ $formId }}" method="POST" action="{{ route('personnel.hours.update', $entry) }}" class="mt-3 grid gap-2">
                    @csrf
                    @if ($entry->hasSubmittedTimes())
                        <div class="grid grid-cols-[1fr_1fr_6rem] gap-2">
                            <label class="grid gap-1 text-xs">
                                <span class="uppercase tracking-wide text-nicon-muted">Van</span>
                                <input type="time" name="approved_start_time" required value="{{ old('approved_start_time', $entry->approvedStartLabel()) }}" class="border border-nicon-line bg-white px-2 py-1">
                            </label>
                            <label class="grid gap-1 text-xs">
                                <span class="uppercase tracking-wide text-nicon-muted">Tot</span>
                                <input type="time" name="approved_end_time" required value="{{ old('approved_end_time', $entry->approvedEndLabel()) }}" class="border border-nicon-line bg-white px-2 py-1">
                            </label>
                            <label class="grid gap-1 text-xs">
                                <span class="uppercase tracking-wide text-nicon-muted">Pauze</span>
                                <input type="number" name="approved_break_minutes" min="0" max="1440" step="1" required value="{{ old('approved_break_minutes', $entry->approvedBreakMinutes()) }}" class="border border-nicon-line bg-white px-2 py-1">
                            </label>
                        </div>
                    @else
                        <label class="grid gap-1 text-xs">
                            <span class="uppercase tracking-wide text-nicon-muted">Goedgekeurde uren</span>
                            <span class="inline-flex items-center gap-2">
                                <input
                                    type="number"
                                    name="approved_hours"
                                    min="0"
                                    max="24"
                                    step="0.25"
                                    required
                                    value="{{ old('approved_hours', $entry->approvedHoursValue() ?? $entry->submittedHoursValue()) }}"
                                    class="w-24 border border-nicon-line bg-white px-2 py-1"
                                >
                                uur
                            </span>
                        </label>
                    @endif
                    <label class="grid gap-1 text-xs">
                        <span class="uppercase tracking-wide text-nicon-muted">Reden/opmerking beoordelaar</span>
                        <textarea name="review_note" rows="2" maxlength="2000" class="border border-nicon-line bg-white px-2 py-1">{{ old('review_note', $entry->review_note) }}</textarea>
                    </label>
                </form>
                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                    @if ($entry->isSubmitted())
                        <form method="POST" action="{{ route('personnel.hours.approve', $entry) }}">
                            @csrf
                            <button class="border border-nicon-ok bg-white px-2 py-1 text-nicon-ok">Goedkeuren</button>
                        </form>
                    @endif
                    <button form="{{ $formId }}" formaction="{{ route('personnel.hours.update', $entry) }}" name="_method" value="PATCH" class="border border-nicon-ink bg-nicon-ink px-2 py-1 text-white">Aanpassen &amp; goedkeuren</button>
                    @if ($entry->isSubmitted())
                        <button form="{{ $formId }}" formaction="{{ route('personnel.hours.reject', $entry) }}" class="border border-nicon-danger bg-white px-2 py-1 text-nicon-danger">Afwijzen</button>
                    @endif
                </div>
            @endif
        </article>
    @endforeach
</div>
