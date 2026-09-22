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
        <form method="POST" action="{{ route('vakman.hours.store') }}" class="mt-4 space-y-3 border border-nicon-line bg-white p-4" data-hours-clock>
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
            @php
                $plannedSlots = collect($detail['jobs'] ?? [])->sum(
                    fn (array $job): int => empty($job['can_register_hours']) ? 0 : count($job['hour_slots'] ?? [])
                );
                $standardDay = $plannedSlots === 0;
            @endphp
            <div class="grid grid-cols-[1fr_1fr_6rem] gap-2">
                <label class="grid gap-1 text-sm">
                    <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Van</span>
                    <input type="time" name="start_time" required value="{{ old('start_time', $standardDay ? \App\Support\PlanningHours::REGISTERED_DAY_START : '') }}" class="border border-nicon-line px-3 py-2" data-clock-start>
                </label>
                <label class="grid gap-1 text-sm">
                    <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Tot</span>
                    <input type="time" name="end_time" required value="{{ old('end_time', $standardDay ? \App\Support\PlanningHours::REGISTERED_DAY_END : '') }}" class="border border-nicon-line px-3 py-2" data-clock-end>
                </label>
                <label class="grid gap-1 text-sm">
                    <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Pauze</span>
                    <input type="number" name="break_minutes" min="0" max="1440" step="1" required value="{{ old('break_minutes', $standardDay ? \App\Support\PlanningHours::REGISTERED_BREAK_MINUTES : '') }}" class="border border-nicon-line px-3 py-2" inputmode="numeric" data-clock-break>
                </label>
            </div>
            <p class="text-sm font-semibold" data-clock-total>Totaal</p>
            <label class="grid gap-1 text-sm">
                <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Opmerking</span>
                <input type="text" name="note" value="{{ old('note') }}" class="border border-nicon-line px-3 py-2">
            </label>
            <button class="w-full bg-nicon-ink px-4 py-3 text-sm font-medium text-white">Uren indienen</button>
        </form>
    </div>
@endsection

@push('scripts')
    @vite(['resources/js/vakman-hours.js'])
@endpush
