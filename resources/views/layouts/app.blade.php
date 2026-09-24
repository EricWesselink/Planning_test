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
    @php
        $user = auth()->user();
        $isImpersonating = session()->has('impersonator_id') && session()->has('impersonate_id');
    @endphp
    @if ($isImpersonating)
        <div class="nicon-impersonate flex items-center justify-between gap-4 bg-nicon-orange px-4 py-2 text-white">
            <p class="text-sm font-medium">Je bekijkt het account van {{ $user?->name }}</p>
            <form method="POST" action="{{ route('users.impersonate.stop') }}" class="shrink-0">
                @csrf
                <button class="bg-white px-3 py-1 text-sm font-medium text-nicon-ink">Terug naar mijn account</button>
            </form>
        </div>
    @endif
    <header class="nicon-topbar border-b border-nicon-line bg-white text-nicon-ink{{ $user?->isVakman() ? ' nicon-topbar--vakman' : '' }}">
        <div class="nicon-topbar-bar flex items-center gap-3 px-4 py-0.5">
            <div class="nicon-topbar-brand flex shrink-0 items-center gap-2">
                <img src="{{ asset(config('company.logo')) }}" alt="Nicon Vloeren" class="nicon-logo">
                @if ($user?->isReadOnlyOfficeUser())
                    <span class="rounded border border-nicon-line px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-nicon-steel">Alleen lezen</span>
                @endif
            </div>
            <div class="nicon-topbar-menu flex min-w-0 grow items-center gap-1">
            <nav class="flex min-w-0 grow items-center gap-1 overflow-x-auto text-sm">
                @php
                    $user = auth()->user();
                    $officeLinks = [];
                    if ($user?->canViewDashboard()) {
                        $officeLinks[] = ['href' => route('dashboard'), 'label' => 'Dashboard', 'active' => request()->routeIs('dashboard')];
                    }
                    if ($user?->canViewPlanning()) {
                        $officeLinks[] = ['href' => route('planning'), 'label' => 'Planning', 'active' => request()->routeIs('planning') || request()->routeIs('planning.personnel-week') || (request()->routeIs('work-tickets.*') && ! $user?->isVakman())];
                    }
                    if ($user?->canViewProduction()) {
                        $officeLinks[] = ['href' => route('production.index'), 'label' => 'Productie', 'active' => request()->routeIs('production.*')];
                    }
                    if ($user?->canViewProjects()) {
                        $officeLinks[] = ['href' => route('projects.index'), 'label' => 'Projecten', 'active' => request()->routeIs('projects.*') && ! request()->routeIs('projects.archived')];
                    }
                    $links = $user?->isVakman()
                        ? [
                            ['href' => route('vakman.planning'), 'label' => 'Mijn planning', 'active' => request()->routeIs('vakman.planning*') || (request()->routeIs('work-tickets.*') && $user?->isVakman()), 'home' => true],
                            ['href' => route('vakman.hours.index'), 'label' => 'Mijn uren', 'active' => request()->routeIs('vakman.hours.index')],
                            ['href' => route('vakman.password.edit'), 'label' => 'Wachtwoord wijzigen', 'short' => 'Wachtwoord', 'active' => request()->routeIs('vakman.password.*')],
                        ]
                        : $officeLinks;
                    if ($user?->isVakman() && $user->canRequestLeave()) {
                        $links[] = ['href' => route('vakman.leave-requests.index'), 'label' => 'Vrij aanvragen', 'active' => request()->routeIs('vakman.leave-requests.*')];
                    }
                    if (! $user?->isVakman()) {
                        if ($user?->canViewCalculations()) {
                            $links[] = ['href' => route('calculations.index'), 'label' => 'Calculatie', 'active' => request()->routeIs('calculations.*')];
                        }
                        if ($user?->canViewProjects()) {
                            $links[] = ['href' => route('projects.archived'), 'label' => 'Archief', 'active' => request()->routeIs('projects.archived')];
                        }
                        if ($user?->canViewWorkers()) {
                            $links[] = ['href' => route('workers.index'), 'label' => 'Vakmensen / ZZP', 'active' => request()->routeIs('workers.*')];
                        }
                        if ($user?->canViewPersonnelWeek()) {
                            $links[] = ['href' => route('personnel.index'), 'label' => 'Personeel', 'active' => request()->routeIs('personnel.*')];
                        }
                        if ($user?->canViewLeaveRequests()) {
                            $leaveLabel = ($pendingLeaveRequestCount ?? 0) > 0
                                ? 'Vrij-aanvragen ('.$pendingLeaveRequestCount.')'
                                : 'Vrij-aanvragen';
                            $links[] = ['href' => route('leave-requests.index'), 'label' => $leaveLabel, 'active' => request()->routeIs('leave-requests.*')];
                        }
                    }
                    if ($user?->canViewUsers()) {
                        $links[] = ['href' => route('users.index'), 'label' => 'Gebruikers', 'active' => request()->routeIs('users.*')];
                    }
                    if ($user?->canManageCatalog() || $user?->canViewCatalog()) {
                        $links[] = ['href' => route('work-activities.index'), 'label' => 'Werkzaamheden', 'active' => request()->routeIs('work-activities.*') || request()->routeIs('work-activity-categories.*')];
                    }
                @endphp
                @foreach ($links as $link)
                    <a href="{{ $link['href'] }}" class="whitespace-nowrap rounded px-2 py-1 text-[13px] leading-none {{ $link['active'] ? 'active' : 'text-nicon-ink hover:bg-nicon-red-light' }}{{ ! empty($link['home']) ? ' nicon-topbar-home' : '' }}">
                        @if (! empty($link['short']))
                            <span class="nicon-topbar-full">{{ $link['label'] }}</span>
                            <span class="nicon-topbar-short">{{ $link['short'] }}</span>
                        @else
                            {{ $link['label'] }}
                        @endif
                    </a>
                @endforeach
                @unless ($user?->isVakman())
                    <a
                        href="https://app.decoloop.com/dossier/floor_browse"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="rounded px-2 py-1 leading-none hover:bg-nicon-sand"
                        title="Decoloop"
                    >
                        <img src="{{ asset('images/decoloop.png') }}" alt="Decoloop" class="h-6 w-auto max-w-full">
                    </a>
                @endunless
            </nav>
            <a href="{{ route('account.password.edit') }}" class="text-sm text-nicon-steel hover:text-nicon-orange {{ request()->routeIs('account.password.*') ? 'text-nicon-orange' : '' }}">Wachtwoord</a>
            <form method="POST" action="{{ route('logout') }}" class="nicon-topbar-logout shrink-0">
                @csrf
                <button class="text-sm text-nicon-steel hover:text-nicon-orange">Uitloggen</button>
            </form>
            </div>
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
