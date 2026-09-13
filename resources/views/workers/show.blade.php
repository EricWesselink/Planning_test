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
        @if ($worker->users->isEmpty())
            <section class="mt-6 max-w-2xl border border-nicon-line bg-white p-5 space-y-3">
                <h2 class="font-semibold">Inlog</h2>
                <p class="text-sm text-nicon-muted">Nog geen inlog. Vul e-mail en een tijdelijk wachtwoord in. Optioneel stuur je meteen een uitnodiging voor de planning.</p>
                <form method="POST" action="{{ route('workers.login.store', $worker) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="text-xs uppercase tracking-wide text-nicon-muted" for="login-email">E-mail (inlog)</label>
                        <input id="login-email" type="email" name="email" value="{{ old('email', $worker->email) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" autocomplete="off">
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="text-xs uppercase tracking-wide text-nicon-muted" for="login-password">Tijdelijk wachtwoord</label>
                            <input id="login-password" type="password" name="password" required minlength="8" autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
                        </div>
                        <div>
                            <label class="text-xs uppercase tracking-wide text-nicon-muted" for="login-password-confirmation">Wachtwoord herhalen</label>
                            <input id="login-password-confirmation" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
                        </div>
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
