@extends('layouts.app')

@section('title', 'Personeel · Nicon Planning')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
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
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 list-disc pl-5 text-sm text-nicon-danger">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="mt-6 overflow-x-auto border border-nicon-line bg-white">
        <table class="min-w-full text-sm">
            <thead class="border-b border-nicon-line bg-nicon-paper text-left text-xs uppercase tracking-wide text-nicon-muted">
                <tr>
                    <th class="px-4 py-2 font-medium">Medewerker</th>
                    @foreach ($days as $day)
                        <th class="px-2 py-2 text-center font-medium">{{ \App\Models\CrewMember::WEEKDAY_LABELS[(int) $day->dayOfWeekIso] }} {{ $day->format('j') }}</th>
                    @endforeach
                    <th class="px-2 py-2 text-center font-medium">Uren</th>
                    <th class="px-2 py-2 text-center font-medium">Afwezig</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($people as $row)
                    @php
                        $worker = $row['worker'];
                        $member = $row['member'];
                    @endphp
                    <tr @class(['border-t border-nicon-line', 'opacity-60' => ! $worker->active || ($member->exists && ! $member->isActive())])>
                        <td class="px-4 py-2">
                            <span class="font-semibold text-nicon-ink">{{ $member->displayName() }}</span>
                            @if ($member->displayName() !== $worker->name)
                                <span class="block text-xs text-nicon-muted">{{ $worker->name }}</span>
                            @endif
                        </td>
                        @foreach ($row['cells'] as $cell)
                            <td class="px-2 py-2 text-center">
                                @if ($cell['status_label'])
                                    <span @class([
                                        'text-xs',
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
                        <td class="px-2 py-2 text-center font-medium">{{ $row['worked_label'] }}</td>
                        <td class="px-2 py-2 text-center text-nicon-muted">{{ $row['absence_label'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-6 text-nicon-muted">Nog geen eigen medewerkers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="mt-10 text-lg font-semibold">Vaste werkdagen</h2>
    <p class="text-sm text-nicon-muted">Uit = vaste vrije dag. Telt in de planning niet als beschikbaar.</p>
    <div class="mt-3 overflow-x-auto border border-nicon-line bg-white">
        <table class="min-w-full text-sm">
            <thead class="border-b border-nicon-line bg-nicon-paper text-left text-xs uppercase tracking-wide text-nicon-muted">
                <tr>
                    <th class="px-4 py-2 font-medium">Medewerker</th>
                    @foreach (\App\Models\CrewMember::WEEKDAY_LABELS as $label)
                        <th class="px-2 py-2 text-center font-medium">{{ $label }}</th>
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
                        <td class="px-4 py-2 font-semibold">{{ $member->displayName() }}</td>
                        @foreach (\App\Models\CrewMember::WEEKDAY_LABELS as $isoDay => $label)
                            <td class="px-2 py-2 text-center">
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
                                                class="size-4 border-nicon-line"
                                                @checked($member->worksOn($isoDay))
                                                onchange="this.form.submit()"
                                                aria-label="{{ $member->displayName() }} {{ $label }}"
                                            >
                                            <span class="sr-only">{{ $label }}</span>
                                        </label>
                                    </form>
                                @else
                                    <span class="text-nicon-muted">{{ $member->worksOn($isoDay) ? '✓' : 'Vrij' }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-nicon-muted">Nog geen eigen medewerkers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="mt-10 text-lg font-semibold">Afwezigheid</h2>
    <p class="text-sm text-nicon-muted">Incidenteel: vakantie, ziek, verlof, ADV, cursus of overig.</p>
    <div class="mt-3 grid gap-3">
        @foreach ($people as $row)
            @php
                $worker = $row['worker'];
                $member = $row['member'];
            @endphp
            @if ($member->exists)
                <article @class(['border border-nicon-line bg-white', 'opacity-60' => ! $worker->active || ! $member->isActive()])>
                    <div class="border-b border-nicon-line px-4 py-3 font-semibold">{{ $member->displayName() }}</div>
                    <div class="space-y-3 px-4 py-3">
                        @include('workers._availability', ['worker' => $worker, 'member' => $member])
                    </div>
                </article>
            @endif
        @endforeach
    </div>
@endsection
