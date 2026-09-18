@extends('layouts.app')

@section('title', $project->displayTitle().' · Nicon Planning')

@section('content')
    @php
        $item = $project->workItems->first();
        $hours = $item?->begrote_uren !== null ? (float) $item->begrote_uren : 4;
        $canUpdate = auth()->user()?->can('update', $project) ?? false;
    @endphp
    <a href="{{ $project->isArchived() ? route('projects.archived') : route('projects.index') }}" class="text-sm text-nicon-muted">← {{ $project->isArchived() ? 'Archief' : 'Projecten' }}</a>
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <span class="bg-nicon-ink px-2 py-0.5 text-[11px] font-semibold tracking-[0.14em] text-white">{{ $project->kind?->badge() }}</span>
        <h1 class="text-2xl font-semibold">{{ $project->displayTitle() }}</h1>
        <span class="text-nicon-muted">{{ $project->isArchived() ? 'Archief' : $project->status->label() }}</span>
    </div>
    @if (auth()->user()?->canViewLaborCosts())
        @include('projects.partials.labor-summary', ['labor' => $labor])
    @endif
    @if (session('status'))
        <p class="mt-3 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-3 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('projects.small.update', $project) }}" class="mt-8 max-w-xl space-y-4 border border-nicon-line bg-white p-5">
        @csrf
        @method('PATCH')
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="customer_name">Klant</label>
            <input id="customer_name" name="customer_name" value="{{ old('customer_name', $project->customer?->name) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! $canUpdate)>
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="description">Korte omschrijving</label>
            <input id="description" name="description" value="{{ old('description', $project->name) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! $canUpdate)>
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="location">Locatie</label>
            <input id="location" name="location" value="{{ old('location', $project->city) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! $canUpdate)>
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="work_number">Werknummer</label>
            <input id="work_number" name="work_number" value="{{ old('work_number', $project->project_number) }}" class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! $canUpdate)>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="date">Datum</label>
                <input id="date" type="date" name="date" value="{{ old('date', $project->planned_start_date?->toDateString()) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! $canUpdate)>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="hours">Geplande uren</label>
                <select id="hours" name="hours" required class="mt-1 w-full border border-nicon-line bg-white px-3 py-2" @disabled(! $canUpdate)>
                    @foreach ($hourOptions as $option)
                        <option value="{{ $option }}" @selected((string) old('hours', (string) (int) $hours) === (string) $option)>{{ $option }}u</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div>
            <span class="text-xs uppercase tracking-wide text-nicon-muted">Wie</span>
            <div class="mt-1 text-sm">
                {{ $project->assignments->map(fn ($assignment) => $assignment->worker?->displayName())->filter()->unique()->join(', ') ?: 'Nog niet ingepland' }}
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canUpdate)
                <button type="submit" class="bg-nicon-orange px-4 py-2 text-white">Opslaan</button>
            @endif
            <a href="{{ route('planning', ['week' => $project->planned_start_date?->startOfWeek(\Carbon\Carbon::MONDAY)?->toDateString(), 'project_id' => $project->id]) }}" class="{{ $canUpdate ? 'border border-nicon-line px-4 py-2' : 'inline-block bg-nicon-orange px-4 py-2 text-white' }}">Open planning</a>
        </div>
    </form>
@endsection
