@can('update', $worker)
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <form method="POST" action="{{ route('workers.availability.update', $worker) }}" class="flex items-center">
            @csrf
            @method('PATCH')
            <input type="hidden" name="unavailable" value="0">
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="unavailable" value="1" class="size-4 border-nicon-line" @checked($worker->unavailable) onchange="this.form.submit()">
                Helemaal niet beschikbaar
            </label>
        </form>
        @if ($worker->employment_type === \App\Enums\EmploymentType::Eigen)
            <form method="POST" action="{{ route('workers.friday.update', $worker) }}" class="flex items-center">
                @csrf
                @method('PATCH')
                <input type="hidden" name="friday_off" value="0">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="friday_off" value="1" class="size-4 border-nicon-line" @checked($worker->friday_off) onchange="this.form.submit()">
                    Vrij op vrijdag
                </label>
            </form>
        @endif
    </div>
@endcan

@if ($worker->availabilities->isNotEmpty())
    <div class="flex flex-wrap gap-1.5">
        @foreach ($worker->availabilities as $window)
            <span @class([
                'inline-flex items-center gap-1 border px-2 py-0.5 text-xs',
                'border-nicon-ok/30 bg-nicon-ok/10 text-nicon-ok' => $window->kind === \App\Enums\AvailabilityKind::Available,
                'border-nicon-danger/30 bg-red-50 text-nicon-danger' => $window->kind === \App\Enums\AvailabilityKind::Unavailable,
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
@elseif (! $worker->unavailable && ! ($worker->employment_type === \App\Enums\EmploymentType::Eigen && $worker->friday_off))
    <p class="text-xs text-nicon-muted">Geen periodes gezet — standaard beschikbaar.</p>
@endif

@can('update', $worker)
    <form method="POST" action="{{ route('workers.availability.store', $worker) }}" class="flex flex-wrap items-end gap-2">
        @csrf
        <div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Van</label>
            <input type="date" name="start_date" required value="{{ old('start_date') }}" class="mt-0.5 border border-nicon-line bg-white px-2 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Tot</label>
            <input type="date" name="end_date" required value="{{ old('end_date') }}" class="mt-0.5 border border-nicon-line bg-white px-2 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-[10px] uppercase tracking-wide text-nicon-muted">Status</label>
            <select name="kind" class="mt-0.5 border border-nicon-line bg-white px-2 py-1.5 text-sm">
                <option value="{{ \App\Enums\AvailabilityKind::Unavailable->value }}" @selected(old('kind', 'unavailable') === 'unavailable')>Niet beschikbaar</option>
                <option value="{{ \App\Enums\AvailabilityKind::Available->value }}" @selected(old('kind') === 'available')>Beschikbaar</option>
            </select>
        </div>
        <button class="border border-nicon-line bg-white px-3 py-1.5 text-sm">Zet</button>
    </form>
@endcan
