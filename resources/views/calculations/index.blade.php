@extends('layouts.app')

@section('title', 'Calculatie · Nicon Planning')

@section('content')
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Calculatie</h1>
            <p class="text-sm text-nicon-muted">Hoeveelheden uit bouwtekeningen voor een offerte. Los van projecten en planning.</p>
        </div>
        @can('create', \App\Models\Calculation::class)
            <a href="{{ route('calculations.create') }}" class="bg-nicon-orange px-4 py-2 text-sm text-white">Nieuwe calculatie</a>
        @endcan
    </div>
    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 list-disc pl-5 text-sm text-nicon-danger">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
    <div class="mt-6 overflow-x-auto border border-nicon-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-nicon-ink text-left text-white">
                <tr>
                    <th class="px-3 py-2">Naam</th>
                    <th class="px-3 py-2">Opdrachtgever</th>
                    <th class="px-3 py-2">Project / werk</th>
                    <th class="px-3 py-2">Datum</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($calculations as $calculation)
                <tr class="border-t border-nicon-line">
                    <td class="px-3 py-2">
                        <a class="font-medium text-nicon-orange-dark" href="{{ $calculation->isImporting() ? route('calculations.processing', $calculation) : route('calculations.board', $calculation) }}">{{ $calculation->name }}</a>
                        <div class="text-xs text-nicon-muted">
                            @if ($calculation->isImporting())
                                Bestanden worden uitgelezen…
                            @else
                                {{ $calculation->drawings_count }} tekening(en) · {{ $calculation->lines_count }} regels
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-2">{{ $calculation->client_name ?: '—' }}</td>
                    <td class="px-3 py-2">{{ $calculation->project_name ?: '—' }}</td>
                    <td class="px-3 py-2">{{ $calculation->dated_on?->format('d-m-Y') }}</td>
                    <td class="px-3 py-2">{{ $calculation->isImporting() ? 'Uitlezen' : $calculation->status->label() }}</td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ $calculation->isImporting() ? route('calculations.processing', $calculation) : route('calculations.board', $calculation) }}" class="text-sm text-nicon-orange-dark">{{ $calculation->isImporting() ? 'Voortgang' : 'Openen' }}</a>
                        @can('delete', $calculation)
                            <form method="POST" action="{{ route('calculations.destroy', $calculation) }}" class="inline" onsubmit="return confirm({{ json_encode($calculation->name.' wordt verwijderd. Tekeningen en regels verdwijnen.') }})">
                                @csrf
                                @method('DELETE')
                                <button class="ml-3 text-sm text-nicon-danger">Verwijderen</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-3 py-8 text-center text-nicon-muted">Nog geen calculaties. Start met PDF-tekeningen en/of Excelbestanden.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
