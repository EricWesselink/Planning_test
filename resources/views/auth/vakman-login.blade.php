<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Inloggen · Mijn planning</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-nicon-ink text-white flex items-center justify-center p-6">
    <div class="w-full max-w-md bg-white text-nicon-ink p-8">
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Nicon Vloeren</div>
        <h1 class="mt-2 text-2xl font-semibold">Mijn planning</h1>
        <p class="mt-2 text-sm text-nicon-muted">Log in met je 06-nummer of e-mailadres om je eigen planning te zien. Na het inloggen kun je zelf je wachtwoord wijzigen.</p>
        <form method="POST" action="{{ route('vakman.login.store') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted" for="login">06-nummer of e-mailadres</label>
                <input id="login" name="login" type="text" value="{{ old('login') }}" required autocomplete="username"
                       autocapitalize="none" spellcheck="false"
                       class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted" for="password">Wachtwoord</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            @error('login')
                <p class="text-sm text-nicon-danger">{{ $message }}</p>
            @enderror
            @error('password')
                <p class="text-sm text-nicon-danger">{{ $message }}</p>
            @enderror
            <button class="w-full bg-nicon-orange text-white py-2.5 font-medium hover:bg-nicon-orange-dark">Inloggen</button>
        </form>
        <p class="mt-6 text-sm text-nicon-muted">
            <a href="{{ route('login') }}" class="hover:text-nicon-ink">Planner of beheerder? Ga naar de kantoorlogin.</a>
        </p>
    </div>
    <script>
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                window.location.reload();
            }
        });
    </script>
</body>
</html>
