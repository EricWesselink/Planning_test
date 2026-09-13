@extends('layouts.app')

@section('title', 'Productie · Nicon Planning')

@section('content')
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-lg font-semibold">
                Productie
                @if ($ownWorker)
                    <span class="font-normal text-nicon-muted">· {{ $ownWorker->displayName() }}</span>
                @endif
            </h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="window.print()" class="border border-nicon-line bg-white px-3 py-1.5 text-sm">Printen</button>
            @if ($downloadVoucher ?? null)
                <a href="{{ route('vouchers.pdf', $downloadVoucher) }}" class="border border-nicon-ink bg-nicon-ink px-3 py-1.5 text-sm text-white">Download PDF</a>
            @endif
        </div>
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

    <form method="GET" class="mt-3 grid gap-2 sm:grid-cols-4 text-sm">
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

    <div class="mt-3 grid gap-2 sm:grid-cols-5 text-sm">
        <div class="flex items-baseline justify-between gap-2 border border-nicon-line bg-white px-3 py-1.5">
            <span class="text-xs text-nicon-muted">Vakmensen</span>
            <span class="font-semibold">{{ $totals['workers'] }}</span>
        </div>
        <div class="flex items-baseline justify-between gap-2 border border-nicon-line bg-white px-3 py-1.5">
            <span class="text-xs text-nicon-muted">Ruimtes</span>
            <span class="font-semibold">{{ $totals['rooms'] }}</span>
        </div>
        <div class="flex items-baseline justify-between gap-2 border border-nicon-line bg-white px-3 py-1.5">
            <span class="text-xs text-nicon-muted">m² gedaan</span>
            <span class="font-semibold">{{ \App\Support\Format::qty($totals['m2'], 2) }}</span>
        </div>
        <div class="flex items-baseline justify-between gap-2 border border-nicon-line bg-white px-3 py-1.5">
            <span class="text-xs text-nicon-muted">m¹ plinten</span>
            <span class="font-semibold">{{ \App\Support\Format::qty($totals['m1'], 2) }}</span>
        </div>
        <div class="flex items-baseline justify-between gap-2 border border-nicon-line bg-white px-3 py-1.5">
            <span class="text-xs text-nicon-muted">Wacht op akkoord</span>
            <span class="font-semibold {{ ($totals['pending'] ?? 0) > 0 ? 'text-nicon-orange-dark' : '' }}">{{ $totals['pending'] ?? 0 }}</span>
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
