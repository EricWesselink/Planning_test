@extends('layouts.app')

@section('title', 'Voortgang · Nicon Planning')

@section('content')
    <h1 class="text-2xl font-semibold">Voortgang</h1>
    <p class="text-sm text-nicon-muted">Eén regel: datum, werkzaamheid, wie, hoeveel, uren.</p>

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('progress.store') }}" class="mt-6 max-w-lg space-y-4 border border-nicon-line bg-white p-5">
        @csrf
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Datum</label>
            <input type="date" name="date" value="{{ old('date', $selectedDate) }}" required class="mt-1 w-full border border-nicon-line px-3 py-3 text-lg">
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Werkzaamheid</label>
            <select name="work_item_id" required class="mt-1 w-full border border-nicon-line px-3 py-3 text-lg">
                @foreach ($projects as $project)
                    <optgroup label="{{ $project->project_number }} {{ $project->name }}">
                        @foreach ($project->workItems as $item)
                            <option value="{{ $item->id }}" @selected((int) old('work_item_id', $selectedProjectId === $project->id ? $project->workItems->first()?->id : 0) === $item->id)>
                                {{ $item->name }} ({{ $item->unit->label() }})
                            </option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Wie</label>
            <select name="worker_id" required class="mt-1 w-full border border-nicon-line px-3 py-3 text-lg">
                @foreach ($workers as $worker)
                    <option value="{{ $worker->id }}">{{ $worker->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Hoeveel gedaan</label>
            <input type="number" step="0.01" name="completed_quantity" required inputmode="decimal" class="mt-1 w-full border border-nicon-line px-3 py-3 text-lg" placeholder="285">
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Uren</label>
            <input type="number" step="0.5" name="worked_hours" value="8" class="mt-1 w-full border border-nicon-line px-3 py-3 text-lg">
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Opmerking</label>
            <input type="text" name="note" class="mt-1 w-full border border-nicon-line px-3 py-3" placeholder="Optioneel">
        </div>
        @error('work_item_id') <p class="text-sm text-nicon-danger">{{ $message }}</p> @enderror
        <button class="w-full bg-nicon-orange text-white py-4 text-lg font-medium">Opslaan</button>
    </form>
@endsection
