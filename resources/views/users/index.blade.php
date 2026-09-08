@extends('layouts.app')

@section('title', 'Gebruikers · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold">Gebruikersbeheer</h1>
            <p class="text-sm text-nicon-muted">Collega’s en vakmenteams aanmaken, rollen toekennen en inlogs van teamleden beheren.</p>
        </div>
        <a href="{{ route('users.create') }}" class="bg-nicon-orange text-white px-4 py-2 text-sm">Nieuwe gebruiker</a>
    </div>

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif

    <div class="mt-6 overflow-x-auto border border-nicon-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-nicon-ink text-white text-left">
                <tr>
                    <th class="px-3 py-2">Naam</th>
                    <th class="px-3 py-2">E-mail</th>
                    <th class="px-3 py-2">Rol</th>
                    <th class="px-3 py-2">Projecten</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($users as $user)
                <tr class="border-t border-nicon-line">
                    <td class="px-3 py-2">
                        <a class="text-nicon-orange-dark font-medium" href="{{ route('users.show', $user) }}">{{ $user->name }}</a>
                        @if ($user->isVakman() && $user->crewMember)
                            <div class="text-xs text-nicon-muted">Lid van {{ $user->worker?->planName() }}</div>
                        @elseif ($user->isVakman())
                            <div class="text-xs text-nicon-muted">{{ $user->worker?->employment_type?->label() }} · {{ $user->worker?->peopleCountLabel() }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $user->email }}</td>
                    <td class="px-3 py-2">{{ $user->role?->label() }}</td>
                    <td class="px-3 py-2">
                        @if ($user->isVakman())
                            Ingeplande werken van {{ $user->worker?->planName() ?: 'dit team' }}
                        @elseif ($user->can_access_all_projects)
                            Alle projecten
                        @else
                            {{ $user->projects->pluck('name')->join(', ') ?: 'Geen projecten' }}
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $user->active ? 'Actief' : 'Uitgeschakeld' }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        @can('delete', $user)
                            <form method="POST" action="{{ route('users.destroy', $user) }}" class="inline" onsubmit="return confirm('{{ $user->name }} verwijderen? Het team in de planning blijft bestaan.')">
                                @csrf
                                @method('DELETE')
                                <button class="text-sm text-nicon-danger hover:underline">Verwijderen</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
