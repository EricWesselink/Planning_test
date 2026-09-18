@extends('layouts.app')

@section('title', 'Personeel · Nicon Planning')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Eigen personeel</div>
            <h1 class="text-2xl font-semibold">Personeel</h1>
            <p class="text-sm text-nicon-muted">Uren en afwezigheid van eigen medewerkers. ZZP blijft bij Vakmensen / ZZP.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <a class="border border-nicon-line bg-white px-3 py-1.5" href="{{ route('personnel.index', ['week' => $prevWeek]) }}" aria-label="Vorige week">←</a>
            <a class="border border-nicon-line bg-white px-3 py-1.5" href="{{ route('personnel.index', ['week' => $thisWeek]) }}">Deze week</a>
            <a class="border border-nicon-line bg-white px-3 py-1.5" href="{{ route('personnel.index', ['week' => $nextWeek]) }}" aria-label="Volgende week">→</a>
            <form method="GET" action="{{ route('personnel.index') }}" class="flex items-center gap-2">
                <label for="personnel-week-nr" class="text-nicon-muted">Week</label>
                <input id="personnel-week-nr" type="number" name="week_nr" min="1" max="53" required value="{{ $weekStart->isoWeek() }}" class="w-16 border border-nicon-line bg-white px-2 py-1.5">
                <input type="hidden" name="year" value="{{ $weekStart->isoWeekYear }}">
                <button class="border border-nicon-line bg-white px-3 py-1.5">Toon</button>
            </form>
        </div>
    </div>
    <p class="mt-2 text-sm text-nicon-muted">Week {{ $weekStart->isoWeek() }} · {{ $weekStart->translatedFormat('j M') }} – {{ $days->last()->translatedFormat('j M Y') }}</p>

    @if (session('status'))
        <p class="mt-3 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-3 list-disc pl-5 text-sm text-nicon-danger">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="mt-4 overflow-x-auto border border-nicon-line bg-white">
        <table class="min-w-full text-xs">
            <thead class="border-b border-nicon-line bg-nicon-paper text-left uppercase tracking-wide text-nicon-muted">
                <tr>
                    <th class="px-2 py-1.5 font-medium">Medewerker</th>
                    @foreach ($days as $day)
                        <th class="px-1.5 py-1.5 text-center font-medium">{{ \App\Models\CrewMember::WEEKDAY_LABELS[(int) $day->dayOfWeekIso] }} {{ $day->format('j') }}</th>
                    @endforeach
                    <th class="px-2 py-1.5 font-medium">Week</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($people as $row)
                    @php
                        $worker = $row['worker'];
                        $member = $row['member'];
                    @endphp
                    <tr @class(['border-t border-nicon-line', 'opacity-60' => ! $worker->active || ($member->exists && ! $member->isActive())])>
                        <td class="px-2 py-1 whitespace-nowrap font-semibold text-nicon-ink">{{ $member->displayName() }}</td>
                        @foreach ($row['cells'] as $cell)
                            <td class="px-1.5 py-1 text-center">
                                @if ($cell['status_label'])
                                    <span @class([
                                        'text-nicon-muted' => $cell['status'] === 'vrij',
                                        'text-nicon-danger' => in_array($cell['status'], ['ziek', 'overig'], true),
                                        'text-nicon-ok' => $cell['status'] === 'vakantie',
                                    ])>{{ $cell['status_label'] }}</span>
                                @elseif ($cell['hours_label'] !== '')
                                    {{ $cell['hours_label'] }}
                                @else
                                    <span class="text-nicon-muted">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-2 py-1 whitespace-nowrap text-nicon-muted">{{ $row['week_summary'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-2 py-4 text-nicon-muted">Nog geen eigen medewerkers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="mt-6 text-base font-semibold">Vaste werkdagen</h2>
    <div class="mt-2 overflow-x-auto border border-nicon-line bg-white">
        <table class="min-w-full text-xs">
            <thead class="border-b border-nicon-line bg-nicon-paper text-left uppercase tracking-wide text-nicon-muted">
                <tr>
                    <th class="px-2 py-1.5 font-medium">Medewerker</th>
                    @foreach (\App\Models\CrewMember::WEEKDAY_LABELS as $label)
                        <th class="px-1 py-1.5 text-center font-medium">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($people as $row)
                    @php
                        $worker = $row['worker'];
                        $member = $row['member'];
                    @endphp
                    <tr @class(['border-t border-nicon-line', 'opacity-60' => ! $worker->active || ($member->exists && ! $member->isActive())])>
                        <td class="px-2 py-1 whitespace-nowrap font-semibold">{{ $member->displayName() }}</td>
                        @foreach (\App\Models\CrewMember::WEEKDAY_LABELS as $isoDay => $label)
                            <td class="px-1 py-1 text-center">
                                @if ($member->exists && auth()->user()?->can('update', $worker))
                                    <form method="POST" action="{{ route('personnel.work-days.update', [$worker, $member]) }}" class="inline-flex items-center justify-center">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="day" value="{{ $isoDay }}">
                                        <input type="hidden" name="works" value="0">
                                        <label class="inline-flex cursor-pointer items-center justify-center">
                                            <input
                                                type="checkbox"
                                                name="works"
                                                value="1"
                                                class="size-3.5 border-nicon-line"
                                                @checked($member->worksOn($isoDay))
                                                onchange="this.form.submit()"
                                                aria-label="{{ $member->displayName() }} {{ $label }}"
                                            >
                                            <span class="sr-only">{{ $label }}</span>
                                        </label>
                                    </form>
                                @else
                                    <span class="text-nicon-muted">{{ $member->worksOn($isoDay) ? '✓' : '' }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-2 py-4 text-nicon-muted">Nog geen eigen medewerkers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="mt-6 text-base font-semibold">Afwezigheid</h2>
    <div class="mt-2 overflow-x-auto border border-nicon-line bg-white">
        <table class="min-w-full text-xs">
            <thead class="border-b border-nicon-line bg-nicon-paper text-left uppercase tracking-wide text-nicon-muted">
                <tr>
                    <th class="px-2 py-1.5 font-medium">Medewerker</th>
                    <th class="px-1 py-1.5 font-medium">Van</th>
                    <th class="px-1 py-1.5 font-medium">Tot</th>
                    <th class="px-1 py-1.5 font-medium">Reden</th>
                    <th class="px-1 py-1.5 font-medium">Duur</th>
                    <th class="px-1 py-1.5 font-medium">Uren</th>
                    <th class="px-1 py-1.5 font-medium"><span class="sr-only">Opslaan</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($people as $row)
                    @php
                        $worker = $row['worker'];
                        $member = $row['member'];
                        $windows = $worker->availabilities
                            ->filter(fn ($window) => $member->exists && ((int) $window->crew_member_id === (int) $member->id || $window->crew_member_id === null))
                            ->values();
                    @endphp
                    @if ($member->exists)
                        @php $formId = 'absence-'.$member->id; @endphp
                        <tr @class(['border-t border-nicon-line align-top', 'opacity-60' => ! $worker->active || ! $member->isActive()])>
                            <td class="px-2 py-1">
                                <div class="whitespace-nowrap font-semibold text-nicon-ink">{{ $member->displayName() }}</div>
                                @if ($windows->isNotEmpty())
                                    <div class="mt-0.5 flex flex-wrap gap-1">
                                        @foreach ($windows as $window)
                                            <span class="inline-flex items-center gap-1 border border-nicon-line bg-nicon-paper px-1.5 py-px text-[11px]">
                                                {{ $window->chipLabel($member) }}
                                                @can('update', $worker)
                                                    <form method="POST" action="{{ route('workers.availability.destroy', [$worker, $window]) }}" class="inline">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="leading-none text-nicon-muted hover:text-nicon-ink" title="Periode verwijderen" aria-label="Periode verwijderen">×</button>
                                                    </form>
                                                @endcan
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            @can('update', $worker)
                                <td class="px-1 py-1">
                                    <form
                                        id="{{ $formId }}"
                                        method="POST"
                                        action="{{ route('workers.availability.store', $worker) }}"
                                        data-absence-row
                                        data-work-days="{{ json_encode($member->workDays()) }}"
                                    >
                                        @csrf
                                        <input type="hidden" name="crew_member_id" value="{{ $member->id }}">
                                    </form>
                                    <input form="{{ $formId }}" type="date" name="start_date" required value="{{ old('start_date') }}" class="w-[9.5rem] border border-nicon-line bg-white px-1 py-0.5" aria-label="Van" onchange="window.niconAbsenceRow?.(this.form)">
                                </td>
                                <td class="px-1 py-1">
                                    <input form="{{ $formId }}" type="date" name="end_date" required value="{{ old('end_date') }}" class="w-[9.5rem] border border-nicon-line bg-white px-1 py-0.5" aria-label="Tot" onchange="window.niconAbsenceRow?.(this.form)">
                                </td>
                                <td class="px-1 py-1">
                                    <select form="{{ $formId }}" name="kind" class="border border-nicon-line bg-white px-1 py-0.5" aria-label="Reden" onchange="window.niconAbsenceMaybeSave?.(this.form)">
                                        @foreach (\App\Enums\AvailabilityKind::incidental() as $kind)
                                            <option value="{{ $kind->value }}" @selected(old('kind', \App\Enums\AvailabilityKind::Vacation->value) === $kind->value)>{{ $kind->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-1 py-1">
                                    <select form="{{ $formId }}" name="slot" class="border border-nicon-line bg-white px-1 py-0.5" aria-label="Duur" onchange="window.niconAbsenceRow?.(this.form); window.niconAbsenceMaybeSave?.(this.form)">
                                        @foreach (\App\Enums\AvailabilitySlot::cases() as $slot)
                                            <option value="{{ $slot->value }}" @selected(old('slot', \App\Enums\AvailabilitySlot::Full->value) === $slot->value)>{{ $slot->label() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-1 py-1">
                                    <span class="inline-flex items-center gap-1">
                                        <input form="{{ $formId }}" type="number" name="hours" min="1" max="8" step="1" value="{{ old('hours', 8) }}" class="w-12 border border-nicon-line bg-white px-1 py-0.5" aria-label="Uren" data-hours-input onchange="window.niconAbsenceMaybeSave?.(this.form)">
                                        <span data-hours-total class="whitespace-nowrap text-nicon-muted"></span>
                                    </span>
                                </td>
                                <td class="px-1 py-1">
                                    <button form="{{ $formId }}" class="border border-nicon-line bg-white px-2 py-0.5">Opslaan</button>
                                </td>
                            @else
                                <td colspan="6" class="px-1 py-1 text-nicon-muted"></td>
                            @endcan
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="px-2 py-4 text-nicon-muted">Nog geen eigen medewerkers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

@pushOnce('scripts')
    <script>
        window.niconAbsenceWorkdays = function (form) {
            const start = form.start_date?.value;
            const end = form.end_date?.value;
            if (! start || ! end) {
                return 1;
            }
            let days = [1, 2, 3, 4, 5];
            try {
                days = JSON.parse(form.dataset.workDays || '[1,2,3,4,5]');
            } catch (error) {}
            let count = 0;
            const cursor = new Date(start + 'T00:00:00');
            const last = new Date(end + 'T00:00:00');
            while (cursor <= last) {
                const iso = cursor.getDay() === 0 ? 7 : cursor.getDay();
                if (days.includes(iso)) {
                    count++;
                }
                cursor.setDate(cursor.getDate() + 1);
            }
            return Math.max(count, 1);
        };
        window.niconAbsenceRow = function (form) {
            const row = form.closest('tr');
            const slot = form.slot?.value || 'full';
            const hoursInput = form.querySelector('[data-hours-input]') || row?.querySelector('[data-hours-input]');
            const preview = row?.querySelector('[data-hours-total]');
            const perDay = slot === 'full' ? 8 : (slot === 'hours' ? Number(hoursInput?.value || 0) : 4);
            if (hoursInput) {
                hoursInput.readOnly = slot !== 'hours';
                if (slot !== 'hours') {
                    hoursInput.value = String(perDay);
                }
            }
            if (preview) {
                const days = window.niconAbsenceWorkdays(form);
                const total = perDay * days;
                preview.textContent = slot === 'hours' && days === 1 ? '' : (total + 'u');
            }
        };
        window.niconAbsenceMaybeSave = function (form) {
            window.niconAbsenceRow(form);
            if (form.start_date?.value && form.end_date?.value && form.kind?.value) {
                form.submit();
            }
        };
        document.querySelectorAll('[data-absence-row]').forEach((form) => window.niconAbsenceRow(form));
    </script>
@endpushOnce
