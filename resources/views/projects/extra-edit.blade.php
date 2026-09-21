@extends('layouts.app')

@section('title', ($item->small_work_type?->label() ?? 'Extra werk').' · '.$project->displayTitle())

@section('content')
    @php
        $hours = $item->begrote_uren !== null ? (float) $item->begrote_uren : 4;
        $who = $item->assignments->map(fn ($assignment) => $assignment->worker?->displayName())->filter()->unique()->join(', ');
    @endphp
    <a href="{{ route('projects.show', $project) }}" class="text-sm text-nicon-muted">← Tekening {{ $project->displayTitle() }}</a>
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <span class="bg-nicon-ink px-2 py-0.5 text-[11px] font-semibold tracking-[0.14em] text-white">{{ $item->small_work_type?->badge() ?? 'EXTRA' }}</span>
        <h1 class="text-2xl font-semibold">{{ $item->name }}</h1>
    </div>
    <p class="mt-1 text-sm text-nicon-muted">{{ $project->labeledNumbersLine() !== '' ? $project->labeledNumbersLine().' · ' : '' }}{{ $project->displayTitle() }}</p>
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

    <form method="POST" action="{{ route('projects.extra.update', ['project' => $project, 'extraWerk' => $item]) }}" class="mt-6 max-w-2xl space-y-4 border border-nicon-line bg-white p-5">
        @csrf
        @method('PATCH')
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="description">Korte omschrijving</label>
            <input id="description" name="description" value="{{ old('description', $item->name) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! $canUpdate)>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="date">Start</label>
                <input id="date" type="date" name="date" value="{{ old('date', $item->planned_start_date?->toDateString()) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! $canUpdate)>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="klaar_date">Klaar</label>
                <input id="klaar_date" type="date" name="klaar_date" value="{{ old('klaar_date', $item->planned_end_date?->toDateString()) }}" class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! $canUpdate)>
            </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="hours">Geplande uren</label>
                <select id="hours" name="hours" required class="mt-1 w-full border border-nicon-line bg-white px-3 py-2" @disabled(! $canUpdate)>
                    @foreach ($hourOptions as $option)
                        <option value="{{ $option }}" @selected((string) old('hours', (string) (int) $hours) === (string) $option)>{{ $option }}u</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="actual_hours">Geworden uren</label>
                <input id="actual_hours" name="actual_hours" value="{{ old('actual_hours', $actualHours) }}" inputmode="decimal" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="10" @disabled(! $canUpdate)>
            </div>
        </div>
        @include('projects.partials.extra-lines', [
            'lines' => $lines,
            'showCompleted' => true,
            'canUpdate' => $canUpdate,
        ])
        <div>
            <span class="text-xs uppercase tracking-wide text-nicon-muted">Wie</span>
            <div class="mt-1 text-sm">{{ $who !== '' ? $who : 'Nog niet ingepland' }}</div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($canUpdate)
                <button type="submit" class="bg-nicon-orange px-4 py-2 text-white">Opslaan</button>
            @endif
            <a href="{{ route('planning', ['week' => $item->planned_start_date?->copy()->startOfWeek(\Carbon\Carbon::MONDAY)?->toDateString(), 'project_id' => $project->id]) }}" class="{{ $canUpdate ? 'border border-nicon-line px-4 py-2' : 'inline-block bg-nicon-orange px-4 py-2 text-white' }}">Open planning</a>
        </div>
    </form>
@endsection
