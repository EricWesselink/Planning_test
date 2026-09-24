<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wachtwoord herstellen · Nicon Planning</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-nicon-paper p-6 text-nicon-ink">
    <div class="w-full max-w-md border border-nicon-line bg-white p-8 text-nicon-ink">
        <h1 class="text-2xl font-semibold">Nieuw wachtwoord</h1>
        <p class="mt-2 text-sm text-nicon-muted">Kies een wachtwoord van minstens 10 tekens.</p>
        <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted" for="email">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted" for="password">Nieuw wachtwoord</label>
                <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted" for="password_confirmation">Herhaal wachtwoord</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required minlength="10" autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            @if ($errors->any())
                <ul class="text-sm text-nicon-danger list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
            <button class="w-full bg-nicon-orange text-white py-2.5 font-medium hover:bg-nicon-orange-dark">Wachtwoord opslaan</button>
        </form>
    </div>
</body>
</html>
