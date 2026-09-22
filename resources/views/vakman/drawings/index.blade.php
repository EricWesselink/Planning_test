@extends('layouts.app')

@section('title', 'Tekeningen · '.$project->displayTitle())

@section('main_class', 'p-3 sm:p-6')

@section('content')
    <div class="vakman-drawing mx-auto w-full max-w-lg">
        <a href="{{ $backUrl }}" class="text-sm font-medium text-nicon-orange">← Terug naar Mijn planning</a>
        <h1 class="mt-3 text-2xl font-semibold">Tekeningen</h1>
        <p class="mt-1 text-sm text-nicon-muted">{{ $project->displayTitle() }}</p>
        <ul class="mt-4 divide-y divide-nicon-line border border-nicon-line bg-white">
            @foreach ($drawings as $drawing)
                <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="font-medium">{{ $drawing->drawingLabel() }}</p>
                    <p class="flex gap-3 text-sm">
                        <a href="{{ route('vakman.drawings.show', ['project' => $project, 'document' => $drawing, ...$day]) }}" class="font-semibold text-nicon-orange">Bekijken</a>
                        <a href="{{ route('vakman.drawings.download', ['project' => $project, 'document' => $drawing]) }}" class="font-semibold">PDF opslaan</a>
                    </p>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
