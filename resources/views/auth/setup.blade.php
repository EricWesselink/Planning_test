<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Beheerdersaccount aanmaken · Nicon Planning</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-nicon-ink text-white flex items-center justify-center p-6">
    <div class="w-full max-w-md bg-white text-nicon-ink p-8">
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Nicon Vloeren</div>
        <h1 class="mt-2 text-2xl font-semibold">Eerste beheerder</h1>
        <p class="mt-2 text-sm text-nicon-muted">Maak het eerste beheerdersaccount aan om projecten, vakmensen en de balkenplanning te openen.</p>
        <form method="POST" action="{{ route('setup.store') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">Naam</label>
                <input name="name" type="text" value="{{ old('name') }}" required autocomplete="name"
                       class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">E-mail</label>
                <input name="email" type="email" value="{{ old('email') }}" required autocomplete="username"
                       class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">Wachtwoord</label>
                <input name="password" type="password" required autocomplete="new-password"
                       class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">Wachtwoord bevestigen</label>
                <input name="password_confirmation" type="password" required autocomplete="new-password"
                       class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            @if ($errors->any())
                <div class="space-y-1">
                    @foreach ($errors->all() as $message)
                        <p class="text-sm text-nicon-danger">{{ $message }}</p>
                    @endforeach
                </div>
            @endif
            <button class="w-full bg-nicon-orange text-white py-2.5 font-medium hover:bg-nicon-orange-dark">Beheerdersaccount aanmaken</button>
        </form>
        <p class="mt-4 text-sm text-nicon-muted">
            <a href="{{ route('login') }}" class="hover:text-nicon-ink">← Terug naar inloggen</a>
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
