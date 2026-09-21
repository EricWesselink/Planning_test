@extends('layouts.app')

@section('title', 'Uren buiten planning · Nicon Planning')

@section('main_class', 'p-3 sm:p-6')

@section('content')
    <div class="mx-auto max-w-lg">
        <a href="{{ $weekUrl }}" class="text-sm text-nicon-orange">← Weekoverzicht</a>
        <h1 class="mt-2 text-2xl font-semibold">Niet gepland</h1>
        <p class="text-sm text-nicon-muted">{{ $date->translatedFormat('l j F') }}</p>
        @if (session('status'))
            <p class="mt-3 text-sm text-nicon-ok">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <ul class="mt-3 list-disc pl-5 text-sm text-nicon-danger">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        <form method="POST" action="{{ route('vakman.hours.store') }}" class="mt-4 space-y-3 border border-nicon-line bg-white p-4">
            @csrf
            <input type="hidden" name="date" value="{{ $date->toDateString() }}">
            <label class="grid gap-1 text-sm">
                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Project</span>
                <select name="project_id" required class="border border-nicon-line px-3 py-2">
                    <option value="">Kies een project</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((int) old('project_id') === (int) $project->id)>{{ $project->displayTitle() }} · {{ $project->workNumber() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Werkzaamheid</span>
                <select name="work_item_id" required class="border border-nicon-line px-3 py-2">
                    <option value="">Kies een werkzaamheid</option>
                    @foreach ($projects as $project)
                        @foreach ($project->workItems as $item)
                            <option value="{{ $item->id }}" @selected((int) old('work_item_id') === (int) $item->id)>{{ $project->displayTitle() }} · {{ $item->planningTitle() }}</option>
                        @endforeach
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Gewerkte uren</span>
                <input type="number" name="hours" min="0.25" max="24" step="0.25" required value="{{ old('hours', 8) }}" class="border border-nicon-line px-3 py-2">
            </label>
            <label class="grid gap-1 text-sm">
                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Opmerking</span>
                <input type="text" name="note" value="{{ old('note') }}" class="border border-nicon-line px-3 py-2">
            </label>
            <button class="w-full bg-nicon-ink px-4 py-3 text-sm font-medium text-white">Uren indienen</button>
        </form>
    </div>
@endsection
