@extends('layouts.app')

@section('title', $worker->name.' · Nicon Planning')

@section('content')
    <a href="{{ route('workers.index') }}" class="text-sm text-nicon-muted">← Vakmensen</a>
    <div class="mt-4 flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold flex items-center gap-3">
                <span class="inline-block size-4 shrink-0 rounded-full" style="background: {{ $worker->planColor() }}" title="Planningskleur"></span>
                {{ $worker->displayName() }}
            </h1>
            @if ($worker->employment_type->isExternal())
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
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-3">
            @if (! $worker->active)
                <span class="border border-nicon-line bg-nicon-paper px-2 py-0.5 text-xs">Inactief</span>
            @endif
            @include('workers._status-actions', ['worker' => $worker])
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

    @can('update', $worker)
        @php
            $officeLogin = $worker->officeLogin();
        @endphp
        @if ($officeLogin)
            <p class="mt-6 max-w-2xl text-sm text-nicon-muted">Heeft al een kantoorinlog als {{ $officeLogin->role->label() }}. Geen aparte vakman-inlog nodig.</p>
        @elseif ($worker->users->isEmpty())
            <section class="mt-6 max-w-2xl border border-nicon-line bg-white p-5 space-y-3">
                <h2 class="font-semibold">Inlog</h2>
                <p class="text-sm text-nicon-muted">Nog geen inlog. Vul een e-mailadres in. De vakman krijgt een activatielink om zelf een wachtwoord te kiezen.</p>
                <form method="POST" action="{{ route('workers.login.store', $worker) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="text-xs uppercase tracking-wide text-nicon-muted" for="login-email">E-mail (inlog)</label>
                        <input id="login-email" type="email" name="email" value="{{ old('email', $worker->email) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" autocomplete="off">
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="invite" value="1" @checked((int) old('invite') === 1) class="size-4 accent-nicon-ok">
                        Stuur uitnodiging voor de planning
                    </label>
                    <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Inlog opslaan</button>
                </form>
            </section>
        @endif
    @endcan

    @if ($worker->employment_type->isExternal())
        <section class="mt-6 max-w-2xl border border-nicon-line bg-white p-5 space-y-3">
            <h2 class="font-semibold">Beschikbaarheid</h2>
            <p class="text-sm text-nicon-muted">
                Zet het team helemaal uit als ze niet ingepland mogen worden, of kies periodes.
            </p>
            @include('workers._availability', ['worker' => $worker])
        </section>
    @endif

    <form method="POST" action="{{ route('workers.update', $worker) }}" class="mt-6 max-w-2xl border border-nicon-line bg-white p-5 space-y-4">
        @csrf
        @method('PATCH')
        <h2 class="font-semibold">Gegevens</h2>
        @include('workers._form', ['worker' => $worker])
        <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Opslaan</button>
    </form>

    @if ($worker->employment_type->isExternal())
        @can('update', $worker)
            <div data-zzp-only>
                @include('workers._rates', ['worker' => $worker, 'rateRows' => $rateRows])
            </div>
        @endcan

        <div data-zzp-only>
            <h2 class="mt-8 font-semibold">Opdrachten</h2>
            <div class="mt-3 border border-nicon-line bg-white divide-y">
                @forelse ($worker->workOrders as $order)
                    <div class="px-4 py-3 text-sm">{{ $order->project?->name }} · {{ $order->label() }}</div>
                @empty
                    <p class="px-4 py-3 text-sm text-nicon-muted">Geen opdrachten.</p>
                @endforelse
            </div>
        </div>

    @endif

    @if ($worker->employment_type->isExternal() || $groups->isNotEmpty())
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
    @endif
@endsection
