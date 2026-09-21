@extends('layouts.app')

@section('title', 'Nieuw project · Nicon Planning')

@push('scripts')
    @vite(['resources/js/project-upload.js'])
@endpush

@section('content')
    <a href="{{ route('projects.index') }}" class="text-sm text-nicon-muted">← Projecten</a>
    <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
        <h1 class="text-2xl font-semibold">Nieuw project</h1>
        @can('create', \App\Models\Project::class)
            <a href="{{ route('projects.small.create') }}" class="border border-nicon-line bg-white px-4 py-2 text-sm">Klein werk</a>
            <a href="{{ route('projects.winkel.create') }}" class="border border-nicon-line bg-white px-4 py-2 text-sm">Nieuw Winkelwerk</a>
        @endcan
    </div>

    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <form method="POST" action="{{ route('projects.store') }}" enctype="multipart/form-data" class="flex h-full min-w-0 flex-col gap-4 border border-nicon-line bg-white p-5">
            @csrf
            <div>
                <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Handmatig project</h2>
                <p class="mt-1 text-sm text-nicon-muted">Screens en zonwering.</p>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="customer_name">Bedrijf / opdrachtgever</label>
                <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Opdrachtgever">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="work_code">Projectnr.</label>
                <input id="work_code" name="work_code" value="{{ old('work_code') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="11P260521">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="name">Projectnaam</label>
                <input id="name" name="name" value="{{ old('name') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Projectnaam">
            </div>
            <x-work-address id="address" class="mt-1" />
            @include('projects.partials.planning-weeks', ['idPrefix' => 'handmatig-'])
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="excel">Excel raambekleding / zonwering</label>
                <input id="excel" type="file" name="excel" accept=".csv,.txt,.xlsx,.xlsm,text/csv,text/plain,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="mt-1 w-full text-sm">
            </div>
            <button class="mt-auto bg-nicon-ink text-white px-5 py-3 font-medium">Project aanmaken</button>
        </form>

        <form method="POST" action="{{ route('projects.afmetingen.preview') }}" enctype="multipart/form-data" class="flex h-full min-w-0 flex-col gap-4 border border-nicon-line bg-white p-5">
            @csrf
            <div>
                <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Afmetingen-PDF</h2>
                <p class="mt-1 text-sm text-nicon-muted">Handmatige netto-afmetingen.</p>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="afmetingen">Afmetingen-PDF</label>
                <input id="afmetingen" type="file" name="afmetingen" accept=".pdf,application/pdf" required class="mt-1 w-full text-sm">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="afmetingen_plattegrond">Plattegrond (optioneel)</label>
                <input id="afmetingen_plattegrond" type="file" name="plattegrond" accept=".pdf,application/pdf" class="mt-1 w-full text-sm">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="afmetingen_work_type">Werksoort</label>
                <div class="mt-2 flex flex-col gap-2">
                    <select id="afmetingen_work_type" name="work_type" class="w-full border border-nicon-line bg-white px-3 py-2 text-sm" data-work-type-select>
                        <option value="">— kies werksoort —</option>
                        <option value="Raambekleding">Raambekleding</option>
                        <option value="Zonwering">Zonwering</option>
                        <option value="PVC">PVC</option>
                        <option value="Linoleum">Linoleum</option>
                        <option value="__custom__">Anders (vrij invoeren)</option>
                    </select>
                    <input type="text" name="work_type_custom" value="{{ old('work_type_custom') }}" placeholder="Vrije werksoort" class="hidden w-full border border-nicon-line px-3 py-2 text-sm" data-work-type-custom>
                </div>
            </div>
            <button class="mt-auto bg-nicon-ink text-white px-5 py-3 font-medium">Afmetingen uitlezen</button>
        </form>

        <form method="POST" action="{{ route('projects.preview') }}" enctype="multipart/form-data" class="flex h-full min-w-0 flex-col gap-4 border border-nicon-line bg-white p-5" data-project-upload>
            @csrf
            <div class="flex min-h-0 grow flex-col">
                <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Projectbestanden</h2>
                <p class="mt-1 text-sm text-nicon-muted">Vloerimport. PDF-meetstaat én Excel-calculatie (.xlsx) kunnen samen.</p>
                <label data-upload-dropzone class="mt-2 flex grow cursor-pointer flex-col items-center justify-center gap-2 border border-dashed border-nicon-line bg-nicon-sand/40 px-4 py-8 text-center">
                    <span class="text-sm font-medium">Sleep bestanden hierheen</span>
                    <span class="text-xs text-nicon-muted">Maximaal {{ $maxFileMegabytes }} MB per bestand</span>
                    <span class="mt-1 bg-white px-3 py-1.5 text-sm border border-nicon-line">Bestanden toevoegen</span>
                    <input type="file" name="files[]" multiple accept=".pdf,.csv,.txt,.xlsx,.xlsm,.xls,application/pdf,text/csv,text/plain,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="sr-only" data-upload-input>
                </label>
                <ul class="mt-3 flex flex-col gap-2" data-upload-list></ul>
                <p class="mt-2 hidden text-sm text-nicon-muted" data-upload-empty>Nog geen bestanden.</p>
            </div>
            <button class="mt-auto bg-nicon-orange text-white px-5 py-3 font-medium">Bestanden uitlezen en combineren</button>
        </form>
    </div>
@endsection
