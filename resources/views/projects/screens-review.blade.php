@extends('layouts.app')

@section('title', 'Zonwering controleren · Nicon Planning')

@section('content')
    @php
        $lines = $parsed['lines'] ?? [];
        $unrecognized = $parsed['unrecognized'] ?? [];
        $ready = (bool) ($parsed['ready'] ?? false);
    @endphp

    <a href="{{ route('projects.create') }}" class="text-sm text-nicon-muted">← Ander bestand kiezen</a>
    <h1 class="mt-2 text-2xl font-semibold">Excel raambekleding / zonwering</h1>
    <p class="text-sm text-nicon-muted">{{ $filename }} · geen vloerimport, geen m²-controle.</p>

    <div class="mt-4 border {{ $ready ? 'border-green-600 bg-green-50' : 'border-amber-400 bg-amber-50' }} px-4 py-3">
        <div class="text-sm font-medium">{{ $parsed['summary'] ?? '' }}</div>
        @if ($ready)
            <p class="mt-1 text-sm text-green-800">Alle relevante regels zijn eenduidig. Vul de projectgegevens in om direct klaar te zetten voor planning.</p>
        @elseif (count($lines) === 0)
            <p class="mt-1 text-sm">Geen screenregels met eenheid stuks gevonden.</p>
        @else
            <p class="mt-1 text-sm">De herkende regels kunnen worden ingelezen. Controleer de niet-herkende regels.</p>
        @endif
    </div>

    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('projects.screens.import', $token) }}" class="mt-6 max-w-3xl space-y-6">
        @csrf
        <div class="border border-nicon-line bg-white p-5 space-y-4">
            <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Projectgegevens</h2>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="customer_name">Bedrijf / opdrachtgever</label>
                <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="name">Projectnaam</label>
                <input id="name" name="name" value="{{ old('name') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="address">Werkadres</label>
                <input id="address" name="address" value="{{ old('address') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Straat 12">
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="postal_code">Postcode</label>
                        <input id="postal_code" name="postal_code" value="{{ old('postal_code') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="3811 AA">
                    </div>
                    <div>
                        <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="city">Plaats</label>
                        <input id="city" name="city" value="{{ old('city') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Amersfoort">
                    </div>
                </div>
            </div>
            @include('projects.partials.planning-weeks', ['idPrefix' => 'screens-'])
        </div>

        <div class="border border-nicon-line bg-white overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-nicon-ink text-white text-left">
                    <tr>
                        <th class="px-3 py-2">BNR</th>
                        <th class="px-3 py-2">Omschrijving</th>
                        <th class="px-3 py-2 text-right">Aantal</th>
                        <th class="px-3 py-2">EH</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lines as $line)
                        <tr class="border-t border-nicon-line">
                            <td class="px-3 py-2 whitespace-nowrap">{{ $line['bnr'] ? 'BNR '.$line['bnr'] : '—' }}</td>
                            <td class="px-3 py-2">{{ $line['description'] }}</td>
                            <td class="px-3 py-2 text-right">{{ \App\Support\Format::qty($line['quantity']) }}</td>
                            <td class="px-3 py-2">st</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-6 text-nicon-muted">Geen opdrachtregels.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (count($unrecognized) > 0)
            <div class="border border-nicon-line bg-white p-4">
                <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Niet herkende regels</h2>
                <ul class="mt-2 text-sm space-y-1">
                    @foreach ($unrecognized as $row)
                        <li>Regel {{ $row['row'] }}: {{ $row['description'] }} ({{ $row['reason'] }})</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <button class="bg-nicon-orange text-white px-5 py-3 font-medium" @disabled(count($lines) === 0)>
            Project aanmaken
        </button>
    </form>
@endsection
