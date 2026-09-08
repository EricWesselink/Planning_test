@extends('layouts.app')

@section('title', 'Productie · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Facturatie</div>
            <h1 class="text-2xl font-semibold">
                Productie
                @if ($ownWorker)
                    <span class="font-normal text-nicon-muted">· {{ $ownWorker->displayName() }}</span>
                @endif
            </h1>
            @if ($ownPage)
                <p class="text-sm text-nicon-muted">Eigen blad van dit team of deze ZZP: wat klaar is gemeld, wat nog op akkoord wacht, opdracht en bonnen.</p>
            @else
                <p class="text-sm text-nicon-muted">Elk team en elke ZZP heeft een eigen productiepagina. Open die om snel te zien wat zij klaar hebben gemeld en akkoord te geven.</p>
            @endif
        </div>
        <button type="button" onclick="window.print()" class="border border-nicon-line bg-white px-4 py-2 text-sm">Printen</button>
    </div>

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if (session('warning'))
        <p class="mt-4 text-sm text-nicon-danger">{{ session('warning') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="GET" class="mt-6 grid gap-2 sm:grid-cols-4 text-sm">
        @if (auth()->user()?->scheduledWorkerId())
            <input type="hidden" name="worker_id" value="{{ $filters['worker_id'] }}">
            <div class="border border-nicon-line bg-nicon-sand px-2 py-2">{{ $ownWorker?->displayName() ?? 'Jouw productie' }}</div>
        @else
            <select name="worker_id" class="border border-nicon-line px-2 py-2 bg-white" onchange="this.form.submit()">
                <option value="">Alle vakmensen</option>
                @foreach ($workers as $worker)
                    <option value="{{ $worker->id }}" @selected($filters['worker_id'] == $worker->id)>{{ $worker->displayName() }}</option>
                @endforeach
            </select>
        @endif
        <select name="project_id" class="border border-nicon-line px-2 py-2 bg-white" onchange="this.form.submit()">
            <option value="">Alle projecten</option>
            @foreach ($projects as $project)
                <option value="{{ $project->id }}" @selected($filters['project_id'] == $project->id)>{{ $project->labeledNumbersLine() }} — {{ $project->displayTitle() }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ $filters['from'] }}" class="border border-nicon-line px-2 py-2 bg-white" onchange="this.form.submit()">
        <input type="date" name="to" value="{{ $filters['to'] }}" class="border border-nicon-line px-2 py-2 bg-white" onchange="this.form.submit()">
    </form>

    @if (! auth()->user()?->scheduledWorkerId() && $groups->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-1 text-sm">
            @php
                $chipQuery = array_filter([
                    'project_id' => $filters['project_id'] ?? null,
                    'from' => $filters['from'] ?? null,
                    'to' => $filters['to'] ?? null,
                ], fn ($value) => $value !== null && $value !== '');
            @endphp
            <a href="{{ route('production.index', $chipQuery) }}" @class(['border px-3 py-1.5', 'border-nicon-ink bg-nicon-ink text-white' => ! $filters['worker_id'], 'border-nicon-line bg-white' => $filters['worker_id']])>Alle teams</a>
            @foreach ($groups as $group)
                <a href="{{ route('production.index', $chipQuery + ['worker_id' => $group['worker']->id]) }}" @class(['border px-3 py-1.5', 'border-nicon-ink bg-nicon-ink text-white' => (int) $filters['worker_id'] === (int) $group['worker']->id, 'border-nicon-line bg-white' => (int) $filters['worker_id'] !== (int) $group['worker']->id])>
                    {{ $group['worker']->planName() }}
                    @if (($group['provisional_count'] ?? 0) > 0)
                        <span @class(['text-nicon-orange' => (int) $filters['worker_id'] === (int) $group['worker']->id, 'text-nicon-orange-dark' => (int) $filters['worker_id'] !== (int) $group['worker']->id])>· {{ $group['provisional_count'] }} te keuren</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

    <div class="mt-6 grid gap-3 sm:grid-cols-5">
        <div class="border border-nicon-line bg-white px-4 py-3">
            <div class="text-xs uppercase tracking-wide text-nicon-muted">Vakmensen</div>
            <div class="mt-1 text-xl font-semibold">{{ $totals['workers'] }}</div>
        </div>
        <div class="border border-nicon-line bg-white px-4 py-3">
            <div class="text-xs uppercase tracking-wide text-nicon-muted">Ruimtes</div>
            <div class="mt-1 text-xl font-semibold">{{ $totals['rooms'] }}</div>
        </div>
        <div class="border border-nicon-line bg-white px-4 py-3">
            <div class="text-xs uppercase tracking-wide text-nicon-muted">m² gedaan</div>
            <div class="mt-1 text-xl font-semibold">{{ \App\Support\Format::qty($totals['m2'], 2) }}</div>
        </div>
        <div class="border border-nicon-line bg-white px-4 py-3">
            <div class="text-xs uppercase tracking-wide text-nicon-muted">m¹ plinten</div>
            <div class="mt-1 text-xl font-semibold">{{ \App\Support\Format::qty($totals['m1'], 2) }}</div>
        </div>
        <div class="border border-nicon-line bg-white px-4 py-3">
            <div class="text-xs uppercase tracking-wide text-nicon-muted">Wacht op akkoord</div>
            <div class="mt-1 text-xl font-semibold {{ ($totals['pending'] ?? 0) > 0 ? 'text-nicon-orange-dark' : '' }}">{{ $totals['pending'] ?? 0 }}</div>
        </div>
    </div>

    @include('production._groups', [
        'groups' => $groups,
        'canCreateVouchers' => $canCreateVouchers,
        'vouchersByKey' => $vouchersByKey,
        'billingByKey' => $billingByKey,
        'sheetsByKey' => $sheetsByKey ?? collect(),
        'filters' => $filters,
        'canApproveProgress' => $canApproveProgress ?? false,
    ])
@endsection
