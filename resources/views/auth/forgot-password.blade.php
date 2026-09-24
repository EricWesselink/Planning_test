<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Wachtwoord vergeten · Nicon Planning</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-nicon-paper p-6 text-nicon-ink">
    <div class="w-full max-w-md border border-nicon-line bg-white p-8 text-nicon-ink">
        <h1 class="text-2xl font-semibold">Wachtwoord vergeten</h1>
        <p class="mt-2 text-sm text-nicon-muted">Vul het e-mailadres van je account in. Accounts zonder e-mailadres worden door een beheerder hersteld.</p>
        @if (session('status'))
            <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
        @endif
        <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted" for="email">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            @error('email')
                <p class="text-sm text-nicon-danger">{{ $message }}</p>
            @enderror
            <button class="w-full bg-nicon-orange text-white py-2.5 font-medium hover:bg-nicon-orange-dark">Verstuur herstellink</button>
        </form>
        <p class="mt-6 text-sm text-nicon-muted"><a href="{{ route('login') }}" class="hover:text-nicon-ink">Terug naar inloggen</a></p>
    </div>
</body>
</html>
