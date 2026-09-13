@php
    $workedValues = $workedValues ?? \App\Support\VoucherWorkedPeriod::formValues(
        $workedDates ?? null,
        $workedFallbackDate ?? null,
    );
    $selectedDays = collect(old('worked_weekdays', $workedValues['weekdays']))->map(fn ($day) => (int) $day)->all();
    $weekdayDates = $workedValues['weekday_dates'] ?? \App\Support\VoucherWorkedPeriod::weekdayDates(
        (int) old('worked_year', $workedValues['year']),
        (int) old('worked_week', $workedValues['week']),
    );
    $selectedDates = collect($selectedDays)
        ->map(fn (int $day): ?string => $weekdayDates[$day] ?? null)
        ->filter()
        ->values()
        ->all();
    $datesLabel = \App\Support\VoucherWorkedPeriod::datesListLabel($selectedDates);
@endphp
<div class="voucher-worked-period flex min-w-0 flex-col gap-1.5" data-worked-period>
    <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Gewerkt</div>
    <div class="flex flex-wrap items-center gap-2">
        <label class="flex items-center gap-1 text-sm">
            <span class="text-nicon-muted">Week</span>
            <input type="number" name="worked_week" min="1" max="53" inputmode="numeric" value="{{ old('worked_week', $workedValues['week']) }}" class="h-8 w-14 border border-nicon-line bg-white px-1.5 text-sm" data-worked-week>
        </label>
        <label class="flex items-center gap-1 text-sm">
            <span class="text-nicon-muted">Jaar</span>
            <input type="number" name="worked_year" min="2000" max="2100" inputmode="numeric" value="{{ old('worked_year', $workedValues['year']) }}" class="h-8 w-20 border border-nicon-line bg-white px-1.5 text-sm" data-worked-year>
        </label>
        <label class="flex items-center gap-1 text-sm">
            <span class="text-nicon-muted">of datum</span>
            <input type="date" name="worked_on" value="{{ old('worked_on', $workedValues['date']) }}" class="h-8 border border-nicon-line bg-white px-1.5 text-sm" data-worked-on>
        </label>
    </div>
    <div class="flex flex-wrap gap-1">
        @foreach (\App\Support\VoucherWorkedPeriod::weekdayLabels() as $iso => $label)
            @php $dayDate = $weekdayDates[$iso] ?? null; @endphp
            <label class="inline-flex min-h-8 cursor-pointer flex-col items-center justify-center border border-nicon-line bg-white px-2 py-1 text-sm leading-tight">
                <span class="inline-flex items-center gap-1">
                    <input type="checkbox" name="worked_weekdays[]" value="{{ $iso }}" data-worked-weekday="{{ $iso }}" @checked(in_array($iso, $selectedDays, true))>
                    {{ $label }}
                </span>
                <span class="text-[11px] text-nicon-muted" data-worked-day-date="{{ $iso }}">{{ $dayDate ? substr($dayDate, 8, 2).'-'.substr($dayDate, 5, 2) : '' }}</span>
            </label>
        @endforeach
    </div>
    <div class="text-sm text-nicon-muted {{ $datesLabel === '' ? 'hidden' : '' }}" data-worked-dates>{{ $datesLabel === '' ? '' : 'Uitgevoerd: '.$datesLabel }}</div>
</div>
@once
<script>
document.addEventListener('DOMContentLoaded', () => {
    const pad = (value) => String(value).padStart(2, '0');
    const isoDate = (year, week, weekday) => {
        const jan4 = new Date(Date.UTC(year, 0, 4));
        const jan4Day = jan4.getUTCDay() || 7;
        const monday = new Date(jan4);
        monday.setUTCDate(jan4.getUTCDate() - jan4Day + 1 + ((week - 1) * 7));
        const date = new Date(monday);
        date.setUTCDate(monday.getUTCDate() + (weekday - 1));

        return date.getUTCFullYear() + '-' + pad(date.getUTCMonth() + 1) + '-' + pad(date.getUTCDate());
    };
    const isoWeek = (value) => {
        const date = new Date(value + 'T00:00:00');
        const utc = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
        const day = utc.getUTCDay() || 7;
        utc.setUTCDate(utc.getUTCDate() + 4 - day);
        const yearStart = new Date(Date.UTC(utc.getUTCFullYear(), 0, 1));
        const week = Math.ceil((((utc - yearStart) / 86400000) + 1) / 7);

        return { week, year: utc.getUTCFullYear(), weekday: day };
    };
    const displayDate = (iso) => {
        const parts = String(iso).split('-');
        if (parts.length !== 3) {
            return '';
        }

        return parts[2] + '-' + parts[1];
    };
    const labels = { 1: 'ma', 2: 'di', 3: 'wo', 4: 'do', 5: 'vr', 6: 'za', 7: 'zo' };

    document.querySelectorAll('[data-worked-period]').forEach((root) => {
        const dateInput = root.querySelector('[data-worked-on]');
        const weekInput = root.querySelector('[data-worked-week]');
        const yearInput = root.querySelector('[data-worked-year]');
        const dayInputs = [...root.querySelectorAll('[data-worked-weekday]')];
        const datesLabel = root.querySelector('[data-worked-dates]');
        let syncing = false;

        const selectedDays = () => dayInputs.filter((input) => input.checked).map((input) => Number(input.dataset.workedWeekday));

        const syncFromWeek = (fillDate) => {
            const week = Number(weekInput?.value);
            const year = Number(yearInput?.value);
            if (! week || ! year || week < 1 || week > 53) {
                return;
            }
            const days = selectedDays();
            const dates = days.map((weekday) => isoDate(year, week, weekday));
            dayInputs.forEach((input) => {
                const hint = root.querySelector('[data-worked-day-date="'+input.dataset.workedWeekday+'"]');
                if (hint) {
                    hint.textContent = displayDate(isoDate(year, week, Number(input.dataset.workedWeekday)));
                }
            });
            if (datesLabel) {
                const text = dates.length
                    ? 'Uitgevoerd: ' + dates.map((iso, index) => (labels[days[index]] || '') + ' ' + iso.split('-').reverse().join('-')).join(', ')
                    : '';
                datesLabel.textContent = text;
                datesLabel.classList.toggle('hidden', text === '');
            }
            if (! fillDate || ! dateInput) {
                return;
            }
            if (dates.length === 1) {
                dateInput.value = dates[0];
            } else if (dates.length > 1) {
                dateInput.value = '';
            }
        };

        dateInput?.addEventListener('change', () => {
            if (syncing || ! dateInput.value) {
                return;
            }
            const parts = isoWeek(dateInput.value);
            syncing = true;
            if (weekInput) {
                weekInput.value = String(parts.week);
            }
            if (yearInput) {
                yearInput.value = String(parts.year);
            }
            dayInputs.forEach((input) => {
                input.checked = Number(input.dataset.workedWeekday) === parts.weekday;
            });
            syncing = false;
            syncFromWeek(false);
        });

        const onWeekChange = () => {
            if (syncing) {
                return;
            }
            syncFromWeek(true);
        };

        weekInput?.addEventListener('change', onWeekChange);
        weekInput?.addEventListener('input', onWeekChange);
        yearInput?.addEventListener('change', onWeekChange);
        yearInput?.addEventListener('input', onWeekChange);
        dayInputs.forEach((input) => input.addEventListener('change', onWeekChange));

        syncFromWeek(false);
    });
});
</script>
@endonce
