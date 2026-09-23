@extends('layouts.app')

@section('title', 'Productie · Nicon Planning')

@section('content')
    @php
        $fieldClass = 'h-9 w-full border border-nicon-line bg-white px-2 text-sm text-nicon-ink focus:border-nicon-orange focus:outline-none focus:ring-1 focus:ring-nicon-orange';
    @endphp

    <div class="flex items-center gap-3">
        <span class="h-6 w-1 shrink-0 bg-nicon-orange" aria-hidden="true"></span>
        <h1 class="text-lg font-semibold leading-none text-nicon-ink">
            Productie
            @if ($ownWorker)
                <span class="font-normal text-nicon-muted">· {{ $ownWorker->displayName() }}</span>
            @endif
        </h1>
    </div>

    @if (session('status'))
        <p class="mt-3 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if (session('warning'))
        <p class="mt-3 text-sm text-nicon-danger">{{ session('warning') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-3 list-disc pl-5 text-sm text-nicon-danger">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="GET" class="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
        @if (auth()->user()?->scheduledWorkerId())
            <input type="hidden" name="worker_id" value="{{ $filters['worker_id'] }}">
            <label class="flex w-full min-w-0 flex-col gap-1 sm:w-auto sm:min-w-48 sm:flex-1">
                <span class="text-[11px] text-nicon-muted">Vakman</span>
                <div class="flex h-9 items-center border border-nicon-line bg-nicon-paper px-2 text-sm text-nicon-ink">{{ $ownWorker?->displayName() ?? 'Jouw productie' }}</div>
            </label>
        @else
            <label class="flex w-full min-w-0 flex-col gap-1 sm:w-auto sm:min-w-48 sm:flex-1">
                <span class="text-[11px] text-nicon-muted">Vakman</span>
                <select name="worker_id" class="{{ $fieldClass }}" onchange="this.form.submit()">
                    <option value="">Alle vakmensen</option>
                    @foreach ($workers as $worker)
                        <option value="{{ $worker->id }}" @selected($filters['worker_id'] == $worker->id)>{{ $worker->displayName() }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="flex w-full min-w-0 flex-col gap-1 sm:w-auto sm:min-w-56 sm:flex-[1.4]">
            <span class="text-[11px] text-nicon-muted">Project</span>
            <select name="project_id" class="{{ $fieldClass }}" onchange="this.form.submit()">
                <option value="">Alle projecten</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected($filters['project_id'] == $project->id)>{{ $project->labeledNumbersLine() }} — {{ $project->displayTitle() }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex w-full min-w-0 flex-col gap-1 sm:w-40">
            <span class="text-[11px] text-nicon-muted">Van</span>
            <input type="date" name="from" value="{{ $filters['from'] }}" class="{{ $fieldClass }}" onchange="this.form.submit()">
        </label>
        <label class="flex w-full min-w-0 flex-col gap-1 sm:w-40">
            <span class="text-[11px] text-nicon-muted">Tot</span>
            <input type="date" name="to" value="{{ $filters['to'] }}" class="{{ $fieldClass }}" onchange="this.form.submit()">
        </label>
        <div class="flex w-full flex-wrap gap-2 sm:w-auto">
            <button type="button" onclick="window.print()" class="inline-flex h-9 items-center border border-nicon-line bg-white px-3 text-sm text-nicon-ink">Printen</button>
            @if ($downloadVoucher ?? null)
                <a href="{{ route('vouchers.pdf', $downloadVoucher) }}" class="inline-flex h-9 items-center border border-nicon-orange bg-nicon-orange px-3 text-sm text-white">Download PDF</a>
            @endif
        </div>
    </form>

    @if (! auth()->user()?->scheduledWorkerId() && $groups->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-1.5 text-sm">
            @php
                $chipQuery = array_filter([
                    'project_id' => $filters['project_id'] ?? null,
                    'from' => $filters['from'] ?? null,
                    'to' => $filters['to'] ?? null,
                ], fn ($value) => $value !== null && $value !== '');
            @endphp
            <a href="{{ route('production.index', $chipQuery) }}" @class(['inline-flex max-w-full flex-wrap items-center border px-3 py-1 leading-snug break-words', 'border-nicon-orange bg-nicon-orange text-white' => ! $filters['worker_id'], 'border-nicon-line bg-white text-nicon-ink' => $filters['worker_id']])>Alle teams</a>
            @foreach ($groups as $group)
                @php $chipActive = (int) $filters['worker_id'] === (int) $group['worker']->id; @endphp
                <a href="{{ route('production.index', $chipQuery + ['worker_id' => $group['worker']->id]) }}" @class(['inline-flex max-w-72 flex-wrap items-center border px-3 py-1 text-left leading-snug break-words', 'border-nicon-orange bg-nicon-orange text-white' => $chipActive, 'border-nicon-line bg-white text-nicon-ink' => ! $chipActive])>
                    {{ $group['worker']->planName() }}
                    @if (($group['hours_pending'] ?? 0) > 0)
                        <span @class(['ml-1', 'text-white/90' => $chipActive, 'text-nicon-orange-dark' => ! $chipActive])>· {{ $group['hours_pending'] }} uren</span>
                    @endif
                    @if (($group['provisional_count'] ?? 0) > 0)
                        <span @class(['ml-1', 'text-white/90' => $chipActive, 'text-nicon-orange-dark' => ! $chipActive])>· {{ $group['provisional_count'] }} te keuren</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif

    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-5">
        <div class="border border-nicon-line bg-white px-3 py-2">
            <div class="text-[11px] leading-none text-nicon-muted">Vakmensen</div>
            <div class="mt-1 text-sm font-semibold leading-none text-nicon-ink">{{ $totals['workers'] }}</div>
        </div>
        <div class="border border-nicon-line bg-white px-3 py-2">
            <div class="text-[11px] leading-none text-nicon-muted">Ruimtes</div>
            <div class="mt-1 text-sm font-semibold leading-none text-nicon-ink">{{ $totals['rooms'] }}</div>
        </div>
        <div class="border border-nicon-line bg-white px-3 py-2">
            <div class="text-[11px] leading-none text-nicon-muted">m² gedaan</div>
            <div class="mt-1 text-sm font-semibold leading-none text-nicon-ink tabular-nums">{{ \App\Support\Format::qty($totals['m2'], 2) }}</div>
        </div>
        <div class="border border-nicon-line bg-white px-3 py-2">
            <div class="text-[11px] leading-none text-nicon-muted">m¹ plinten</div>
            <div class="mt-1 text-sm font-semibold leading-none text-nicon-ink tabular-nums">{{ \App\Support\Format::qty($totals['m1'], 2) }}</div>
        </div>
        <div class="border border-nicon-line bg-white px-3 py-2">
            <div class="text-[11px] leading-none text-nicon-muted">Wacht op akkoord</div>
            <div class="mt-1 text-sm font-semibold leading-none {{ ($totals['pending'] ?? 0) > 0 ? 'text-nicon-orange-dark' : 'text-nicon-ink' }}">{{ $totals['pending'] ?? 0 }}</div>
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
