<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Nicon Planning')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Feature libraries such as PDF.js belong in page-specific @stack('scripts') entries, not in app.js. --}}
    @stack('scripts')
</head>
<body class="antialiased">
<div class="nicon-shell">
    <header class="nicon-topbar bg-nicon-ink text-white">
        <div class="flex items-center gap-4 px-4 py-3">
            @php
                $user = auth()->user();
            @endphp
            <div class="shrink-0">
                <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Nicon Vloeren</div>
                <div class="text-lg font-semibold leading-tight">{{ $user?->isVakman() ? 'Mijn planning' : 'Planning' }}</div>
            </div>
            <nav class="flex min-w-0 grow items-center gap-1 overflow-x-auto text-sm">
                @php
                    $links = $user?->isVakman()
                        ? [
                            ['href' => route('vakman.planning'), 'label' => 'Mijn planning', 'active' => request()->routeIs('vakman.planning*')],
                            ['href' => route('vakman.password.edit'), 'label' => 'Wachtwoord wijzigen', 'active' => request()->routeIs('vakman.password.*')],
                        ]
                        : [
                            ['href' => route('dashboard'), 'label' => 'Dashboard', 'active' => request()->routeIs('dashboard')],
                            ['href' => route('planning'), 'label' => 'Planning', 'active' => request()->routeIs('planning')],
                            ['href' => route('production.index'), 'label' => 'Productie', 'active' => request()->routeIs('production.*')],
                            ['href' => route('projects.index'), 'label' => 'Projecten', 'active' => request()->routeIs('projects.*') && ! request()->routeIs('projects.archived')],
                        ];
                    if (! $user?->isVakman()) {
                        $links[] = ['href' => route('projects.archived'), 'label' => 'Archief', 'active' => request()->routeIs('projects.archived')];
                        $links[] = ['href' => route('workers.index'), 'label' => 'Vakmensen / ZZP', 'active' => request()->routeIs('workers.*')];
                    }
                    if ($user?->can('viewAny', \App\Models\User::class)) {
                        $links[] = ['href' => route('users.index'), 'label' => 'Gebruikers', 'active' => request()->routeIs('users.*')];
                    }
                    if ($user?->can('manage-catalog')) {
                        $links[] = ['href' => route('work-activities.index'), 'label' => 'Werkzaamheden', 'active' => request()->routeIs('work-activities.*') || request()->routeIs('work-activity-categories.*')];
                    }
                @endphp
                @foreach ($links as $link)
                    <a href="{{ $link['href'] }}" class="rounded px-3 py-2 whitespace-nowrap {{ $link['active'] ? 'active' : 'text-white/80 hover:bg-white/10' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
                @unless ($user?->isVakman())
                    <a
                        href="https://app.decoloop.com/dossier/floor_browse"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded px-3 py-2 text-white/80 hover:bg-white/10"
                        title="Decoloop"
                    >
                        <img src="{{ asset('images/decoloop.png') }}" alt="Decoloop" class="h-6 w-auto max-w-full">
                    </a>
                @endunless
            </nav>
            <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                @csrf
                <button class="text-sm text-white/70 hover:text-white">Uitloggen</button>
            </form>
        </div>
    </header>
    <div class="nicon-main">
        <main class="@yield('main_class', 'p-4 lg:p-8')">
            @yield('content')
        </main>
    </div>
</div>
@stack('detached-forms')
</body>
</html>
