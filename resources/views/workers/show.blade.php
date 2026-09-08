@extends('layouts.app')

@section('title', $worker->name.' · Nicon Planning')

@section('content')
    <a href="{{ route('workers.index') }}" class="text-sm text-nicon-muted">← Vakmensen</a>
    <div class="mt-2 flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold flex items-center gap-3">
                <span class="inline-block size-4 shrink-0 rounded-full" style="background: {{ $worker->planColor() }}" title="Planningskleur"></span>
                {{ $worker->displayName() }}
            </h1>
            <p class="text-sm text-nicon-muted">
                {{ $worker->employment_type->label() }}
                · {{ $worker->peopleCountLabel() }}
                @foreach ($worker->crewMembers() as $member)
                    @if ($member['name'] !== '' || $member['phone'] !== '')
                        · {{ trim($member['name'].($member['phone'] !== '' ? ' '.$member['phone'] : '')) }}
                    @endif
                @endforeach
                @if ($worker->specialtyLabel()) · {{ $worker->specialtyLabel() }} @endif
                @if ($worker->company) · {{ $worker->company }} @endif
                @if ($worker->contact_name) · {{ $worker->contact_name }} @endif
                @if ($worker->email) · {{ $worker->email }} @endif
            </p>
            @if ($worker->nawLine())
                <p class="text-sm text-nicon-muted">{{ $worker->nawLine() }}</p>
            @endif
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

    <section class="mt-6 max-w-2xl border border-nicon-line bg-white p-5 space-y-3">
        <h2 class="font-semibold">Beschikbaarheid</h2>
        <p class="text-sm text-nicon-muted">
            @if ($worker->employment_type === \App\Enums\EmploymentType::Eigen)
                Zet het team helemaal uit als ze niet ingepland mogen worden. Vink vrijdagen af als dit team dan niet werkt. Extra periodes voor vakantie of andere vrije dagen.
            @else
                Zet het team helemaal uit als ze niet ingepland mogen worden, of kies periodes.
            @endif
        </p>
        @include('workers._availability', ['worker' => $worker])
    </section>

    <form method="POST" action="{{ route('workers.update', $worker) }}" class="mt-6 max-w-2xl border border-nicon-line bg-white p-5 space-y-4">
        @csrf
        @method('PATCH')
        <h2 class="font-semibold">Gegevens</h2>
        @include('workers._form', ['worker' => $worker])
        <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Opslaan</button>
    </form>

    @can('update', $worker)
        @include('workers._rates', ['worker' => $worker, 'rateRows' => $rateRows])
    @endcan

    <h2 class="mt-8 font-semibold">Opdrachten</h2>
    <div class="mt-3 border border-nicon-line bg-white divide-y">
        @forelse ($worker->workOrders as $order)
            <div class="px-4 py-3 text-sm">{{ $order->project?->name }} · {{ $order->label() }}</div>
        @empty
            <p class="px-4 py-3 text-sm text-nicon-muted">Geen opdrachten.</p>
        @endforelse
    </div>

    <div class="mt-8 flex items-end justify-between gap-4 flex-wrap">
        <h2 class="font-semibold">Uitgevoerd</h2>
        <a href="{{ route('production.index', ['worker_id' => $worker->id]) }}" class="text-sm text-nicon-orange-dark">Open in productie</a>
    </div>
    @include('production._groups', [
        'groups' => $groups,
        'canCreateVouchers' => $canCreateVouchers,
        'vouchersByKey' => $vouchersByKey,
        'billingByKey' => $billingByKey ?? collect(),
        'sheetsByKey' => $sheetsByKey ?? collect(),
    ])
@endsection
