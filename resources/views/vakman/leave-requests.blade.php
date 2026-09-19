@extends('layouts.app')

@section('title', 'Vrij aanvragen · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <a href="{{ route('vakman.planning') }}" class="text-sm text-nicon-muted">← Mijn planning</a>
            <h1 class="mt-2 text-2xl font-semibold">Vrij aanvragen</h1>
            <p class="text-sm text-nicon-muted">Je aanvraag gaat pas in de planning als een beheerder hem goedkeurt.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('vakman.leave-requests.store') }}" class="mt-6 max-w-xl border border-nicon-line bg-white p-5 space-y-4">
        @csrf
        @php
            $span = old('span', 'single');
            $today = now()->toDateString();
        @endphp
        <fieldset class="space-y-2 text-sm">
            <legend class="text-xs uppercase tracking-wide text-nicon-muted">Periode</legend>
            <label class="flex items-center gap-2">
                <input type="radio" name="span" value="single" @checked($span === 'single')>
                Eén vrije dag
            </label>
            <label class="flex items-center gap-2">
                <input type="radio" name="span" value="range" @checked($span === 'range')>
                Meerdere dagen
            </label>
        </fieldset>
        <div data-single-day @hidden($span === 'range')>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Datum</label>
            <input type="date" name="date" value="{{ old('date', $today) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
        </div>
        <div data-range-days class="grid gap-3 sm:grid-cols-2" @hidden($span !== 'range')>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">Van</label>
                <input type="date" name="starts_on" value="{{ old('starts_on') }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">Tot</label>
                <input type="date" name="ends_on" value="{{ old('ends_on') }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
            </div>
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Opmerking</label>
            <textarea name="note" rows="3" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">{{ old('note') }}</textarea>
        </div>
        <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Aanvraag versturen</button>
    </form>

    <h2 class="mt-10 text-lg font-semibold">Jouw aanvragen</h2>
    <div class="mt-3 overflow-x-auto border border-nicon-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-nicon-ink text-white text-left">
                <tr>
                    <th class="px-3 py-2">Periode</th>
                    <th class="px-3 py-2">Werkdagen</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Opmerking / reden</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($requests as $leaveRequest)
                <tr class="border-t border-nicon-line align-top">
                    <td class="px-3 py-2">
                        @if ($leaveRequest->hasAdjustedPeriod())
                            <div>Afgesproken: {{ $leaveRequest->shortPeriodLabel() }}</div>
                            <div class="text-xs text-nicon-muted">Oorspronkelijk: {{ $leaveRequest->originalShortPeriodLabel() }}</div>
                        @else
                            {{ $leaveRequest->shortPeriodLabel() }}
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $leaveRequest->workdayCount() }}</td>
                    <td class="px-3 py-2">{{ $leaveRequest->status->label() }}</td>
                    <td class="px-3 py-2">
                        @if ($leaveRequest->status === \App\Enums\LeaveRequestStatus::Rejected && filled($leaveRequest->rejection_reason))
                            <div>Reden: {{ $leaveRequest->rejection_reason }}</div>
                        @elseif (filled($leaveRequest->note))
                            {{ $leaveRequest->note }}
                        @else
                            —
                        @endif
                        @if ($leaveRequest->messages->isNotEmpty())
                            <div class="mt-1 text-nicon-muted">Er is overleg over deze aanvraag.</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right space-y-1">
                        <a class="block text-sm text-nicon-orange-dark hover:underline" href="{{ route('vakman.leave-requests.show', $leaveRequest) }}">Bekijken</a>
                        @can('withdraw', $leaveRequest)
                            <form method="POST" action="{{ route('vakman.leave-requests.withdraw', $leaveRequest) }}">
                                @csrf
                                <button class="text-sm text-nicon-danger hover:underline">Intrekken</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-3 py-4 text-nicon-muted">Nog geen aanvragen.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @push('scripts')
        <script>
            (() => {
                const radios = [...document.querySelectorAll('input[name="span"]')];
                const single = document.querySelector('[data-single-day]');
                const range = document.querySelector('[data-range-days]');
                const sync = () => {
                    const isRange = radios.find((radio) => radio.checked)?.value === 'range';
                    if (single) single.hidden = isRange;
                    if (range) range.hidden = ! isRange;
                };
                radios.forEach((radio) => radio.addEventListener('change', sync));
                sync();
            })();
        </script>
    @endpush
@endsection
