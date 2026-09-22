@extends('layouts.app')

@section('title', $document->drawingLabel().' · '.$project->displayTitle())

@section('main_class', 'p-0 min-h-0 overflow-hidden')

@push('scripts')
    @vite(['resources/js/vakman-drawing.js'])
@endpush

@section('content')
    <div class="vakman-drawing" data-vakman-drawing="{{ $fileUrl }}">
        <header class="vakman-drawing-bar">
            <a href="{{ $backUrl }}" class="vakman-drawing-back">← Terug naar Mijn planning</a>
            <h1 class="vakman-drawing-title">{{ $project->displayTitle() }}</h1>
            <p class="vakman-drawing-name">Tekening: {{ $document->drawingLabel() }}</p>
            <div class="vakman-drawing-actions">
                <a href="#tekening" class="vakman-drawing-action">PDF bekijken</a>
                <a href="{{ $downloadUrl }}" class="vakman-drawing-action is-save">PDF opslaan</a>
                <button type="button" class="vakman-drawing-action" data-drawing-zoom="out" aria-label="Kleiner">−</button>
                <button type="button" class="vakman-drawing-action" data-drawing-zoom="in" aria-label="Groter">+</button>
            </div>
            @if ($listUrl)
                <a href="{{ $listUrl }}" class="vakman-drawing-list">Alle tekeningen</a>
            @endif
        </header>
        <div id="tekening" class="vakman-drawing-pages" data-vakman-drawing-pages>
            <p class="vakman-drawing-status" data-vakman-drawing-status>Tekening laden…</p>
        </div>
    </div>
@endsection
