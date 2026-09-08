@extends('layouts.app')

@section('title', 'Vakmensen · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Teams</div>
            <h1 class="text-2xl font-semibold">Vakmensen en ZZP</h1>
            <p class="text-sm text-nicon-muted">Alleen teams met een inlog. Beschikbaarheid zet je per team.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    @can('create', \App\Models\Worker::class)
        <form method="POST" action="{{ route('workers.store') }}" class="mt-6 border border-nicon-line bg-white p-4">
            @csrf
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-56 flex-1">
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="worker-name">Naam van het team</label>
                    <input id="worker-name" type="text" name="name" value="{{ old('name') }}" required placeholder="Bijv. Team Wespro" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="worker-type">Type</label>
                    <select id="worker-type" name="employment_type" class="mt-1 border border-nicon-line px-3 py-2 bg-white text-sm">
                        @foreach ([\App\Enums\EmploymentType::Eigen, \App\Enums\EmploymentType::Zzp] as $type)
                            <option value="{{ $type->value }}" @selected(old('employment_type', 'eigen') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-28">
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="worker-people">Personen</label>
                    <input id="worker-people" type="number" name="people_count" value="{{ old('people_count', 1) }}" min="1" max="50" required class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
                </div>
                <button class="bg-nicon-orange text-white px-4 py-2 text-sm">Toevoegen</button>
            </div>
        </form>
    @endcan

    <div class="mt-6">
        @include('workers._specialties', [
            'inputName' => 'vakkennis[]',
            'selected' => $filterSpecialties,
            'filterAction' => route('workers.index'),
            'storeAction' => auth()->user()?->can('create', \App\Models\Worker::class) ? route('workers.specialties.store') : null,
            'allowAdd' => auth()->user()?->can('create', \App\Models\Worker::class),
            'hint' => 'Alles staat aan. Vink uit wat je niet zoekt; dan blijven alleen teams over die de aangevinkte vakkennis hebben.',
        ])
    </div>

    <div class="mt-6 grid gap-3">
        @forelse ($workers as $worker)
            <article class="border border-nicon-line bg-white">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-nicon-line px-4 py-3">
                    <a href="{{ route('workers.show', $worker) }}" class="min-w-0 flex items-center gap-3">
                        <span class="inline-block size-3 shrink-0 rounded-full" style="background: {{ $worker->planColor() }}"></span>
                        <span>
                            <span class="block font-semibold text-nicon-ink">{{ $worker->name }}</span>
                            @if ($worker->crew_names)
                                <span class="block text-xs text-nicon-muted">{{ $worker->crew_names }}</span>
                            @endif
                        </span>
                    </a>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="border border-nicon-line bg-nicon-paper px-2 py-0.5">{{ $worker->employment_type->label() }}</span>
                        <span class="bg-nicon-ink px-2 py-0.5 font-medium text-white">{{ $worker->peopleCountLabel() }}</span>
                        @if ($worker->unavailable)
                            <span class="border border-nicon-danger/30 bg-red-50 px-2 py-0.5 text-nicon-danger">Niet beschikbaar</span>
                        @endif
                    </div>
                </div>
                <div class="space-y-3 px-4 py-3">
                    @if ($worker->specialtyLabel())
                        <p class="text-xs text-nicon-muted">{{ $worker->specialtyLabel() }}</p>
                    @endif
                    @include('workers._availability', ['worker' => $worker])
                </div>
            </article>
        @empty
            <p class="border border-nicon-line bg-white px-4 py-6 text-sm text-nicon-muted">{{ $filtering ? 'Geen teams met deze vakkennis.' : 'Nog geen vakmensen met een inlog.' }}</p>
        @endforelse
    </div>
@endsection
