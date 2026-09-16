@extends('layouts.app')

@section('title', $calculation->name.' · Bronbestanden')

@section('content')
    <a href="{{ route('calculations.index') }}" class="text-sm text-nicon-muted">← Calculatie</a>
    <h1 class="mt-2 text-2xl font-semibold">{{ $calculation->name }}</h1>
    @include('calculations.partials.tabs', ['calculation' => $calculation, 'tab' => 'files'])

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

    <h2 class="mt-6 text-sm font-medium uppercase tracking-wide text-nicon-muted">Tekeningen</h2>
    <ul class="mt-2 space-y-1 text-sm">
        @forelse ($calculation->drawings as $drawing)
            <li>
                <a href="{{ route('calculations.drawings.show', [$calculation, $drawing]) }}" class="text-nicon-orange-dark" target="_blank" rel="noopener">
                    {{ $drawing->original_filename }}
                </a>
            </li>
        @empty
            <li class="text-nicon-muted">Geen PDF-tekeningen.</li>
        @endforelse
    </ul>

    <h2 class="mt-6 text-sm font-medium uppercase tracking-wide text-nicon-muted">Excelbestanden</h2>
    <ul class="mt-2 space-y-1 text-sm">
        @forelse ($calculation->workbooks as $workbook)
            <li>
                <a href="{{ route('calculations.workbooks.show', [$calculation, $workbook]) }}" class="text-nicon-orange-dark" target="_blank" rel="noopener">
                    {{ $workbook->original_filename }}
                </a>
                @if ($workbook->status === 'pending')
                    <span class="text-nicon-warn">niet automatisch gelezen</span>
                @endif
            </li>
        @empty
            <li class="text-nicon-muted">Geen Excelbestanden.</li>
        @endforelse
    </ul>

    @if ($calculation->workbooks->isNotEmpty())
        <p class="mt-3">
            @if ($calculation->workbooks->contains(fn ($workbook) => $workbook->status === 'pending'))
                <a href="{{ route('calculations.workbooks.edit', $calculation) }}" class="border border-nicon-warn bg-amber-50 px-2 py-1 text-xs">Mapping aanpassen</a>
            @else
                <a href="{{ route('calculations.workbooks.edit', $calculation) }}" class="border border-nicon-line bg-white px-2 py-1 text-xs text-nicon-muted">Geavanceerd / Mapping</a>
            @endif
        </p>
    @endif

    <form method="POST" action="{{ route('calculations.workbooks.store', $calculation) }}" enctype="multipart/form-data" class="mt-6 flex flex-wrap items-end gap-2">
        @csrf
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="extra-workbooks">Excel toevoegen</label>
            <input id="extra-workbooks" type="file" name="workbooks[]" accept=".xlsx,.xlsm,.xls,.csv,.txt" multiple class="mt-0.5 text-xs">
        </div>
        <button class="border border-nicon-line bg-white px-3 py-1 text-xs">Toevoegen</button>
    </form>
@endsection
