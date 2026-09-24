@extends('layouts.app')

@section('title', $user->name.' · Nicon Planning')

@section('content')
    <a href="{{ route('users.index') }}" class="text-sm text-nicon-muted">← Gebruikers</a>
    <h1 class="mt-2 text-2xl font-semibold">Gebruiker bewerken</h1>
    <p class="text-sm text-nicon-muted">{{ $user->name }} · {{ $user->email }} · {{ $user->role?->label() }}</p>
    <p class="text-sm text-nicon-muted">Laatst ingelogd: {{ $user->last_login_at?->format('d-m-Y H:i') ?? 'Nog niet' }}</p>
    <p class="text-sm {{ $user->activated_at ? 'text-nicon-ok' : 'text-nicon-orange' }}">
        {{ $user->activated_at ? 'Geactiveerd' : 'Nog niet geactiveerd' }}
    </p>

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

    @can('impersonate', $user)
        @unless (session()->has('impersonator_id'))
            <form method="POST" action="{{ route('users.impersonate.start', $user) }}" class="mt-4">
                @csrf
                <button class="border border-nicon-line px-4 py-2 text-sm hover:bg-nicon-paper">Account overnemen</button>
            </form>
        @endunless
    @endcan

    @can('update', $user)
        @if ($user->activated_at === null && $user->hasDeliverableEmail())
            <form method="POST" action="{{ route('users.activation.store', $user) }}" class="mt-4">
                @csrf
                <button class="border border-nicon-line px-4 py-2 text-sm hover:bg-nicon-paper">Activatielink opnieuw versturen</button>
            </form>
        @elseif ($user->activated_at === null)
            <p class="mt-4 text-sm text-nicon-muted">Dit account heeft geen e-mailadres. Verstuur de activatielink via het inlogbericht bij de vakman.</p>
        @endif
    @endcan

    @can('update', $user)
    <form method="POST" action="{{ route('users.update', $user) }}" class="mt-6 max-w-6xl border border-nicon-line bg-white p-5 space-y-4">
        @csrf
        @method('PATCH')
        @include('users._form', ['user' => $user, 'projects' => $projects, 'requirePassword' => false, 'specialtyCatalog' => $specialtyCatalog ?? null])
        <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Opslaan</button>
    </form>
    @endcan

    @can('delete', $user)
        <form method="POST" action="{{ route('users.destroy', $user) }}" class="mt-6 max-w-2xl" onsubmit="return confirm('{{ $user->name }} verwijderen? Het team in de planning blijft bestaan.')">
            @csrf
            @method('DELETE')
            <button class="border border-nicon-danger text-nicon-danger px-4 py-2 text-sm hover:bg-nicon-danger hover:text-white">Gebruiker verwijderen</button>
        </form>
    @endcan
@endsection
