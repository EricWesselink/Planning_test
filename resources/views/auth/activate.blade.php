<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Account activeren · Nicon Planning</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-nicon-paper p-6 text-nicon-ink">
    <div class="w-full max-w-md border border-nicon-line bg-white p-8 text-nicon-ink">
        <h1 class="text-2xl font-semibold">Wachtwoord instellen</h1>
        @if (! $usable)
            <p class="mt-4 text-sm text-nicon-danger">Deze activatielink is ongeldig of verlopen. Vraag een nieuwe link aan.</p>
            <p class="mt-6 text-sm text-nicon-muted"><a href="{{ route('login') }}" class="hover:text-nicon-ink">Naar inloggen</a></p>
        @else
            <p class="mt-2 text-sm text-nicon-muted">Kies een wachtwoord van minstens 10 tekens.</p>
            <form method="POST" action="{{ route('activation.store', ['token' => $token]) }}" class="mt-6 space-y-4">
                @csrf
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
        @endif
    </div>
</body>
</html>
