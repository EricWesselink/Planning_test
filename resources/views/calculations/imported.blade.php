@extends('layouts.app')

@section('title', 'Uitlezen klaar · '.$calculation->name)

@section('content')
    <a href="{{ route('calculations.index') }}" class="text-sm text-nicon-muted">← Calculatie</a>
    <h1 class="mt-2 text-2xl font-semibold">Bestanden uitgelezen</h1>
    <p class="mt-1 text-sm text-nicon-muted">Alleen uitzonderingen hoeven gecontroleerd te worden.</p>

    @if (session('status'))
        <p class="mt-3 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif

    <ul class="mt-6 max-w-lg space-y-3 text-lg">
        <li class="{{ $drawingCount > 0 ? 'text-nicon-ok' : 'text-nicon-muted' }}">
            {{ $drawingCount }} {{ $drawingCount === 1 ? 'tekening' : 'tekeningen' }} gevonden{{ $drawingCount > 0 ? ' ✓' : '' }}
        </li>
        <li class="{{ $workbookCount > 0 ? 'text-nicon-ok' : 'text-nicon-muted' }}">
            {{ $workbookCount }} {{ $workbookCount === 1 ? 'Excelbestand' : 'Excelbestanden' }} gevonden{{ $workbookCount > 0 ? ' ✓' : '' }}
        </li>
        <li class="{{ $roomCount > 0 ? 'text-nicon-ok' : 'text-nicon-muted' }}">
            {{ $roomCount }} {{ $roomCount === 1 ? 'ruimte' : 'ruimtes' }} gevonden{{ $roomCount > 0 ? ' ✓' : '' }}
        </li>
        <li class="{{ $floorLinkedCount > 0 ? 'text-nicon-ok' : 'text-nicon-muted' }}">
            {{ $floorLinkedCount }} vloer automatisch gekoppeld{{ $floorLinkedCount > 0 ? ' ✓' : '' }}
        </li>
        <li class="{{ $plinthLinkedCount > 0 ? 'text-nicon-ok' : 'text-nicon-muted' }}">
            {{ $plinthLinkedCount }} plint automatisch gekoppeld{{ $plinthLinkedCount > 0 ? ' ✓' : '' }}
            @if ($plinthLinkedCount > 0)
                <span class="block text-sm font-normal text-nicon-muted">waarvan {{ $plinthMetersCount }} met m¹ ({{ $plinthNetCount }} exact, {{ $plinthGenerousCount }} berekend ruim, {{ $plinthEstimatedCount }} geschat ruim, {{ $plinthMissingMetersCount }} zonder m¹)</span>
            @endif
        </li>
        <li class="{{ $excelConfirmedCount > 0 ? 'text-nicon-ok' : 'text-nicon-muted' }}">
            {{ $excelConfirmedCount }} Excel bevestigd{{ $excelConfirmedCount > 0 ? ' ✓' : '' }}
        </li>
        <li class="{{ $reviewCount > 0 ? 'text-nicon-warn' : 'text-nicon-muted' }}">
            {{ $reviewCount }} controleren
        </li>
    </ul>

    @if ($warnings !== [])
        <div class="mt-6 max-w-2xl border border-nicon-warn bg-amber-50 p-4">
            <h2 class="text-sm font-medium">Controleren</h2>
            <ul class="mt-2 list-disc pl-5 text-sm">
                @foreach ($warnings as $warning)
                    <li class="whitespace-pre-line">{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($excelNotices !== [])
        <div class="mt-6 max-w-2xl border border-nicon-line bg-stone-50 p-4">
            <h2 class="text-sm font-medium">Afwijking Excel</h2>
            <p class="mt-1 text-xs text-nicon-muted">De PDF-m² blijft leidend. Controleer of de juiste ruimte is gekoppeld.</p>
            <ul class="mt-2 list-disc pl-5 text-sm text-nicon-muted">
                @foreach ($excelNotices as $notice)
                    <li>{{ $notice }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($needsMapping)
        <p class="mt-6 max-w-lg text-sm text-nicon-warn">
            Een Excelbestand kon niet automatisch worden gelezen. Pas de mapping alleen aan als dat nodig is.
        </p>
    @endif

    <div class="mt-8 flex flex-wrap items-center gap-4">
        <a href="{{ route('calculations.board', $calculation) }}" class="bg-nicon-orange px-8 py-4 text-lg font-medium text-white">Calculatiebord openen</a>
        <a href="{{ route('calculations.show', $calculation) }}" class="text-sm text-nicon-muted">Naar regels</a>
        <a href="{{ route('calculations.workbooks.edit', $calculation) }}" class="text-sm text-nicon-muted">Geavanceerd / Mapping aanpassen</a>
    </div>
@endsection
