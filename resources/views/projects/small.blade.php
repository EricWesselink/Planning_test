@extends('layouts.app')

@section('title', ($project->smallWorkContextLine() ?: $project->name).' · Nicon Planning')

@section('content')
    @php
        $item = $project->workItems->first(fn ($workItem) => $workItem->work_activity_id === null)
            ?? $project->workItems->first();
        $hours = $item?->begrote_uren !== null ? (float) $item->begrote_uren : 4;
        $canUpdate = auth()->user()?->can('update', $project) ?? false;
        $tekeningen = $project->documents
            ->filter(fn ($document) => in_array($document->document_type, [\App\Services\SmallWorkService::ATTACHMENT_TYPE, 'plattegrond'], true))
            ->values();
        $context = $project->smallWorkContextLine();
        $description = trim((string) $project->name);
    @endphp
    <a href="{{ $project->isArchived() ? route('projects.archived') : route('projects.index') }}" class="text-xs text-nicon-muted">← {{ $project->isArchived() ? 'Archief' : 'Projecten' }}</a>
    <div class="mt-1 flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-nicon-orange-dark">
                <span class="bg-nicon-ink px-1.5 py-0.5 text-[10px] font-semibold tracking-[0.14em] text-white">{{ $project->kind?->badge() }}</span>
                @if ($project->labeledNumbersLine() !== '')
                    <span class="whitespace-nowrap">{{ $project->labeledNumbersLine() }}</span>
                @endif
                @if ($context !== '' && $description !== '')
                    <span class="whitespace-nowrap">{{ $context }}</span>
                @endif
            </div>
            <h1 class="max-w-3xl text-sm font-semibold leading-tight text-nicon-orange-dark">{{ $description !== '' ? $description : $context }} <span class="font-normal">· {{ $project->isArchived() ? 'Archief' : $project->status->label() }}</span></h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('projects.small.werkbon', $project) }}" class="border border-nicon-line bg-white px-3 py-1.5 text-sm">{{ $project->printedBonLabel() }}</a>
            <a href="{{ route('projects.small.werkbon.pdf', $project) }}" class="border border-nicon-line bg-white px-3 py-1.5 text-sm">Download PDF</a>
        </div>
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
            @include('projects.partials.small-contact', ['project' => $project, 'canUpdate' => $canUpdate, 'contactRoles' => $contactRoles])
        </div>
        <div>
            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="description">Korte omschrijving</label>
            <input id="description" name="description" value="{{ old('description', $project->name) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! $canUpdate)>
        </div>
        <div>
            <x-work-address id="address" class="mt-1" :value="$project->nawLine()" :required="$canUpdate" :disabled="! $canUpdate" show-maps />
        </div>
        @include('projects.partials.small-work-activities', [
            'floorActivities' => $floorActivities,
            'selectedIds' => $selectedIds,
            'activityQuantities' => $activityQuantities,
            'activityNotes' => $activityNotes ?? [],
            'canUpdate' => $canUpdate,
        ])
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
                <button type="submit" formaction="{{ route('projects.small.preview.saved', $project) }}" class="border border-nicon-line bg-white px-4 py-2">Bon bekijken</button>
            @else
                <a href="{{ route('projects.small.werkbon', $project) }}" class="border border-nicon-line px-4 py-2">Bon bekijken</a>
            @endif
            <a href="{{ route('planning', ['week' => $project->planned_start_date?->startOfWeek(\Carbon\Carbon::MONDAY)?->toDateString(), 'project_id' => $project->id]) }}" class="{{ $canUpdate ? 'border border-nicon-line px-4 py-2' : 'inline-block bg-nicon-orange px-4 py-2 text-white' }}">Open planning</a>
        </div>
    </form>

    <section class="mt-6 max-w-xl border border-nicon-line bg-white p-5">
        <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Tekeningen</h2>
        <ul class="mt-3 space-y-2 text-sm">
            @forelse ($tekeningen as $document)
                <li class="space-y-2">
                    @if ($document->isImage())
                        <a href="{{ route('projects.documents.show', [$project, $document]) }}" class="block">
                            <img src="{{ route('projects.documents.show', [$project, $document]) }}" alt="{{ $document->original_filename }}" class="max-h-80 w-full border border-nicon-line object-contain bg-nicon-sand/40">
                        </a>
                    @endif
                    <div class="flex items-center justify-between gap-2">
                        <a href="{{ route('projects.documents.show', [$project, $document]) }}" class="text-nicon-orange-dark">{{ $document->original_filename }}</a>
                        @can('update', $project)
                            <form method="POST" action="{{ route('projects.small.attachments.destroy', [$project, $document]) }}" onsubmit="return confirm('Deze tekening verwijderen?')">
                                @csrf
                                @method('DELETE')
                                <button class="text-nicon-danger">Verwijderen</button>
                            </form>
                        @endcan
                    </div>
                </li>
            @empty
                <li class="text-nicon-muted">Nog geen tekening.</li>
            @endforelse
        </ul>
        @if ($canUpdate)
            <form method="POST" action="{{ route('projects.small.attachments.store', $project) }}" enctype="multipart/form-data" class="mt-4 space-y-2">
                @csrf
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="attachments">Tekening toevoegen</label>
                <div class="flex flex-wrap items-center gap-2">
                    <input id="attachments" type="file" name="attachments[]" multiple accept="image/*,.pdf,application/pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp" required class="text-sm">
                    <button class="bg-nicon-orange px-4 py-2 text-white">Uploaden</button>
                </div>
                <p class="text-xs text-nicon-muted">Foto, PDF of tekening. Maximaal {{ $maxFileMegabytes }} MB per bestand.</p>
            </form>
        @endif
    </section>
@endsection
