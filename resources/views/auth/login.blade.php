<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Inloggen · Nicon Planning</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-nicon-paper p-6 text-nicon-ink">
    <div class="w-full max-w-md border border-nicon-line bg-white p-8 text-nicon-ink">
        <img src="{{ asset(config('company.logo')) }}" alt="Nicon Vloeren" class="h-12 w-auto">
        <h1 class="mt-4 text-2xl font-semibold">Planning</h1>
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
        <p class="mt-6 text-sm text-nicon-muted">
            <a href="{{ route('vakman.login') }}" class="hover:text-nicon-ink">Vakman? Log in met 06-nummer of e-mailadres.</a>
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
