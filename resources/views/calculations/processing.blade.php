@extends('layouts.app')

@section('title', 'Bestanden verwerken · '.$calculation->name)

@push('scripts')
    @vite(['resources/js/calculation-import-progress.js'])
@endpush

@section('content')
    <a href="{{ route('calculations.index') }}" class="text-sm text-nicon-muted">← Calculatie</a>
    <h1 class="mt-2 text-2xl font-semibold">Bestanden verwerken</h1>
    <p class="mt-1 text-sm text-nicon-muted">{{ $calculation->name }} — tekeningen en Excel worden op de achtergrond uitgelezen.</p>

    <div
        data-calculation-import
        data-status-url="{{ route('calculations.import-status', $calculation) }}"
        class="mt-6 max-w-xl border border-nicon-line bg-white p-6"
    >
        <p data-progress-status class="font-medium">{{ $progress['label'] }}</p>
        <div data-progress-bar class="mt-4 h-2 bg-nicon-sand" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}" aria-label="Voortgang">
            <div data-progress-fill class="h-2 bg-nicon-orange transition-[width] duration-200" style="width: {{ $progress['percent'] }}%"></div>
        </div>
        <p data-progress-detail class="mt-3 text-xs text-nicon-muted">Deze pagina blijft open tot alles klaar is. Vernieuwen is veilig.</p>
        <ul data-progress-files class="mt-4 space-y-2 text-sm">
            @foreach ($progress['files'] as $file)
                <li data-file-status="{{ $file['status'] }}">
                    <span class="font-medium">{{ $file['name'] }}</span>
                    <span class="text-nicon-muted"> — {{ $file['label'] }}</span>
                    @if ($file['error'])
                        <span class="block text-nicon-danger">{{ $file['error'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endsection
