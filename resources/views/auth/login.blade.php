<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Inloggen · Nicon Planning</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-nicon-ink text-white flex items-center justify-center p-6">
    <div class="w-full max-w-md bg-white text-nicon-ink p-8">
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Nicon Vloeren</div>
        <h1 class="mt-2 text-2xl font-semibold">Planning</h1>
        <p class="mt-2 text-sm text-nicon-muted">Log in om projecten, vakmensen en de balkenplanning te openen.</p>
        @if ($needsSetup)
            <div class="mt-6 border border-nicon-line p-4">
                <p class="text-sm">Er is nog geen beheerdersaccount ingesteld.</p>
                <a href="{{ route('setup.create') }}" class="mt-3 flex w-full items-center justify-center bg-nicon-orange text-white py-2.5 font-medium hover:bg-nicon-orange-dark">Beheerdersaccount aanmaken</a>
            </div>
        @endif
        <form method="POST" action="{{ url('/login') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">E-mail</label>
                <input name="email" type="email" value="{{ old('email') }}" required autocomplete="username"
                       class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">Wachtwoord</label>
                <input name="password" type="password" required autocomplete="current-password"
                       class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            @error('email')
                <p class="text-sm text-nicon-danger">{{ $message }}</p>
            @enderror
            <button class="w-full bg-nicon-orange text-white py-2.5 font-medium hover:bg-nicon-orange-dark">Inloggen</button>
        </form>
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
