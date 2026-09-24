@extends('layouts.app')

@section('title', 'Wachtwoord wijzigen · Nicon Planning')

@section('content')
    <h1 class="text-2xl font-semibold">Wachtwoord wijzigen</h1>
    <p class="text-sm text-nicon-muted">Vul je huidige wachtwoord in en kies een nieuw wachtwoord van minstens 10 tekens.</p>

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

    <form method="POST" action="{{ route('account.password.update') }}" class="mt-6 max-w-md border border-nicon-line bg-white p-5 space-y-4">
        @csrf
        @method('PATCH')
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted" for="current_password">Huidig wachtwoord</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted" for="password">Nieuw wachtwoord</label>
            <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted" for="password_confirmation">Nieuw wachtwoord herhalen</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="10" autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
        </div>
        <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Wachtwoord opslaan</button>
    </form>
@endsection
