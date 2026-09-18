@php
    $member = $member ?? null;
    $unavailable = $member instanceof \App\Models\CrewMember ? $member->unavailable : $worker->unavailable;
    $windows = $worker->availabilities
        ->filter(function ($window) use ($member) {
            if (! $member instanceof \App\Models\CrewMember) {
                return $window->crew_member_id === null;
            }

            return $window->crew_member_id === null || (int) $window->crew_member_id === (int) $member->id;
        })
        ->values();
    $eigen = $worker->employment_type === \App\Enums\EmploymentType::Eigen;
    $kinds = $eigen
        ? \App\Enums\AvailabilityKind::incidental()
        : [\App\Enums\AvailabilityKind::Unavailable, \App\Enums\AvailabilityKind::Available];
@endphp
@can('update', $worker)
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <form method="POST" action="{{ route('workers.availability.update', $worker) }}" class="flex items-center">
            @csrf
            @method('PATCH')
            @if ($member instanceof \App\Models\CrewMember)
                <input type="hidden" name="crew_member_id" value="{{ $member->id }}">
            @endif
            <input type="hidden" name="unavailable" value="0">
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="unavailable" value="1" class="size-4 border-nicon-line" @checked($unavailable) onchange="this.form.submit()">
                Helemaal niet beschikbaar
            </label>
        </form>
    </div>
@endcan

@if ($windows->isNotEmpty())
    <div class="flex flex-wrap gap-1.5">
        @foreach ($windows as $window)
            <span @class([
                'inline-flex items-center gap-1 border px-2 py-0.5 text-xs',
                'border-nicon-ok/30 bg-nicon-ok/10 text-nicon-ok' => $window->kind === \App\Enums\AvailabilityKind::Available,
                'border-nicon-danger/30 bg-red-50 text-nicon-danger' => $window->kind->isAway(),
            ])>
                {{ $window->summaryLabel() }}
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
@elseif (! $unavailable)
    <p class="text-xs text-nicon-muted">{{ $eigen ? 'Geen incidentele afwezigheid gezet.' : 'Geen periodes gezet — standaard beschikbaar.' }}</p>
@endif

@can('update', $worker)
    <form method="POST" action="{{ route('workers.availability.store', $worker) }}" class="flex flex-wrap items-end gap-2">
        @csrf
        @if ($member instanceof \App\Models\CrewMember)
            <input type="hidden" name="crew_member_id" value="{{ $member->id }}">
        @endif
        <div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Van</label>
            <input type="date" name="start_date" required value="{{ old('start_date') }}" class="mt-0.5 border border-nicon-line bg-white px-2 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Tot</label>
            <input type="date" name="end_date" required value="{{ old('end_date') }}" class="mt-0.5 border border-nicon-line bg-white px-2 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">{{ $eigen ? 'Reden' : 'Status' }}</label>
            <select name="kind" class="mt-0.5 border border-nicon-line bg-white px-2 py-1.5 text-sm">
                @foreach ($kinds as $kind)
                    <option value="{{ $kind->value }}" @selected(old('kind', $eigen ? \App\Enums\AvailabilityKind::Vacation->value : \App\Enums\AvailabilityKind::Unavailable->value) === $kind->value)>{{ $kind->label() }}</option>
                @endforeach
            </select>
        </div>
        <button class="border border-nicon-line bg-white px-3 py-1.5 text-sm">Zet</button>
    </form>
@endcan
