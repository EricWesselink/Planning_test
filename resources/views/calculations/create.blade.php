@extends('layouts.app')

@section('title', 'Nieuwe calculatie · Nicon Planning')

@section('content')
    <a href="{{ route('calculations.index') }}" class="text-sm text-nicon-muted">← Calculatie</a>
    <h1 class="mt-2 text-2xl font-semibold">Nieuwe calculatie</h1>
    <p class="mt-1 text-sm text-nicon-muted">Upload PDF-tekeningen en eventueel Excelbestanden. Het systeem koppelt automatisch; jij controleert alleen uitzonderingen.</p>

    @if ($errors->any())
        <ul class="mt-4 list-disc pl-5 text-sm text-nicon-danger">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('calculations.store') }}" enctype="multipart/form-data" data-calculation-create class="mt-6 max-w-xl space-y-4 border border-nicon-line bg-white p-5">
        @csrf
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="name">Naam calculatie</label>
            <input id="name" name="name" value="{{ old('name') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Offerte AZC Oisterwijk">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="client_name">Opdrachtgever</label>
            <input id="client_name" name="client_name" value="{{ old('client_name') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="COA">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="project_name">Project / werknaam</label>
            <input id="project_name" name="project_name" value="{{ old('project_name') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="AZC Oisterwijk — Activiteitengebouw">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="dated_on">Datum</label>
            <input id="dated_on" type="date" name="dated_on" value="{{ old('dated_on', now()->toDateString()) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2">
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="drawings">PDF-tekeningen</label>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <label for="drawings" class="cursor-pointer bg-nicon-orange px-3 py-1.5 text-sm font-medium text-white hover:bg-nicon-orange-dark">Bladeren</label>
                <span class="text-sm text-nicon-muted" data-file-chosen="drawings">Geen bestanden geselecteerd.</span>
                <input id="drawings" type="file" name="drawings[]" accept=".pdf,application/pdf" multiple class="sr-only" data-file-input>
            </div>
            <p class="mt-1 text-xs text-nicon-muted">Meerdere plattegronden mag. Max. {{ $maxFileMegabytes }} MB per bestand. Originelen blijven bij de calculatie bewaard.</p>
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="workbooks">Excelbestanden</label>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <label for="workbooks" class="cursor-pointer bg-nicon-orange px-3 py-1.5 text-sm font-medium text-white hover:bg-nicon-orange-dark">Bladeren</label>
                <span class="text-sm text-nicon-muted" data-file-chosen="workbooks">Geen bestanden geselecteerd.</span>
                <input id="workbooks" type="file" name="workbooks[]" accept=".xlsx,.xlsm,.xls,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" multiple class="sr-only" data-file-input>
            </div>
            <p class="mt-1 text-xs text-nicon-muted">Optioneel. Afwerkstaat, ruimtestaat of calculatie — kolomnamen mogen per project verschillen. Wandtabbladen worden automatisch overgeslagen.</p>
        </div>
        <button type="submit" data-calculation-submit class="bg-nicon-ink px-5 py-3 font-medium text-white">Doorgaan</button>
    </form>

    <div data-calculation-progress class="fixed inset-0 z-[1100] hidden items-center justify-center bg-nicon-ink/70 p-6" hidden>
        <div class="w-full max-w-md border border-nicon-line bg-white p-6">
            <p class="font-medium">Bestanden verwerken</p>
            <p data-progress-status class="mt-1 text-sm text-nicon-muted">Bezig…</p>
            <div data-progress-bar class="mt-4 h-2 bg-nicon-sand" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Voortgang">
                <div data-progress-fill class="h-2 w-[8%] bg-nicon-orange transition-[width] duration-200"></div>
            </div>
            <p data-progress-detail class="mt-3 text-xs text-nicon-muted">Bestanden worden geüpload. Daarna volgt de voortgang per bestand.</p>
        </div>
    </div>
@endsection

@push('scripts')
    @vite(['resources/js/calculation-create.js'])
@endpush
