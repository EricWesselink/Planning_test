@extends('layouts.app')

@section('title', $project->displayTitle().' · Nicon Planning')

@push('scripts')
    <script>
        document.querySelectorAll('[data-shop-activity-toggle]').forEach((input) => {
            input.addEventListener('change', () => {
                const notes = input.closest('[data-shop-activity]')?.querySelector('[data-shop-activity-notes]');
                notes?.classList.toggle('hidden', !input.checked);
            });
        });
    </script>
@endpush

@section('content')
    @php
        $bijlagen = $project->documents->where('document_type', \App\Services\ShopWorkService::ATTACHMENT_TYPE)->values();
        $selectedNotes = $project->workActivities->mapWithKeys(fn ($activity) => [$activity->id => $activity->pivot->notes])->all();
        $selectedQuantities = $project->workActivities->mapWithKeys(fn ($activity) => [$activity->id => $activity->pivot->quantity])->all();
        $selectedUnits = $project->workActivities->mapWithKeys(fn ($activity) => [$activity->id => $activity->pivot->unit?->value])->all();
    @endphp
    <a href="{{ $project->isArchived() ? route('projects.archived') : route('projects.index') }}" class="text-sm text-nicon-muted">← {{ $project->isArchived() ? 'Archief' : 'Projecten' }}</a>
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <span class="bg-nicon-ink px-2 py-0.5 text-[11px] font-semibold tracking-[0.14em] text-white">WINKEL</span>
        <h1 class="text-2xl font-semibold">{{ $project->displayTitle() }}</h1>
        <span class="text-nicon-muted">{{ $project->isArchived() ? 'Archief' : $project->status->label() }}</span>
    </div>
    @if ($project->shopWorkLine())
        <p class="mt-1 text-sm text-nicon-muted">{{ $project->shopWorkLine() }}</p>
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

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('projects.winkel.update', $project) }}" enctype="multipart/form-data" class="space-y-6 border border-nicon-line bg-white p-5 lg:col-span-2">
            @csrf
            @method('PATCH')
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="customer_name">Klant</label>
                    <input id="customer_name" name="customer_name" value="{{ old('customer_name', $project->customer?->name) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! auth()->user()?->can('update', $project))>
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="city">Plaats</label>
                    <input id="city" name="city" value="{{ old('city', $project->city) }}" class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! auth()->user()?->can('update', $project))>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="address">Adres</label>
                    <input id="address" name="address" value="{{ old('address', $project->address) }}" class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! auth()->user()?->can('update', $project))>
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="postal_code">Postcode</label>
                    <input id="postal_code" name="postal_code" value="{{ old('postal_code', $project->postal_code) }}" class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! auth()->user()?->can('update', $project))>
                </div>
            </div>

            @include('projects.partials.shop-activities', [
                'categories' => $categories,
                'selectedIds' => old('work_activity_ids', $project->workActivities->pluck('id')),
                'activityNotes' => old('activity_notes', $selectedNotes),
                'activityQuantities' => old('activity_quantities', $selectedQuantities),
                'activityUnits' => old('activity_units', $selectedUnits),
            ])

            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="work_description">Omschrijving werkzaamheden</label>
                <textarea id="work_description" name="work_description" rows="4" class="mt-1 w-full border border-nicon-line px-3 py-2" @disabled(! auth()->user()?->can('update', $project))>{{ old('work_description', $project->work_description) }}</textarea>
            </div>

            @can('update', $project)
                <div>
                    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="attachments">Extra bijlagen</label>
                    <input id="attachments" type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/jpeg,image/png,image/webp,image/gif,application/pdf" class="mt-1 w-full text-sm">
                    <p class="mt-1 text-xs text-nicon-muted">Meerdere foto’s, PDF’s of tekeningen. Maximaal {{ $maxFileMegabytes }} MB per bestand.</p>
                </div>
            @endcan

            @include('projects.partials.planning-weeks', [
                'idPrefix' => 'winkel-',
                'startYear' => $project->planningStartYear(),
                'startWeek' => $project->planningStartWeek(),
                'klaarYear' => $project->planningEndYear(),
                'klaarWeek' => $project->planningEndWeek(),
            ])

            @can('update', $project)
                <button class="bg-nicon-ink text-white px-5 py-3 font-medium">Winkelwerk opslaan</button>
            @endcan
        </form>

        <div class="space-y-6">
            <section class="border border-nicon-line bg-white p-5">
                <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Planning</h2>
                <p class="mt-2 text-sm">Personen inplannen op dit Winkelwerk gebeurt in de bestaande Nicon Planning.</p>
                <a href="{{ route('planning', ['project_id' => $project->id]) }}" class="mt-4 inline-block bg-nicon-orange px-4 py-2 text-sm text-white">Open planning</a>
                @if ($project->nawLine())
                    <p class="mt-3 text-sm text-nicon-muted">{{ $project->nawLine() }}</p>
                    <a href="{{ $project->googleMapsUrl() }}" target="_blank" rel="noopener noreferrer" class="text-sm text-nicon-orange-dark">Navigeren</a>
                @endif
            </section>

            <section class="border border-nicon-line bg-white p-5">
                <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Geselecteerde onderdelen</h2>
                <ul class="mt-3 space-y-3 text-sm">
                    @forelse ($project->workActivities as $activity)
                        <li>
                            <div class="font-medium">
                                {{ $activity->name }}
                                @if ($activity->pivot->quantityLabel())
                                    <span class="font-normal text-nicon-muted">· {{ $activity->pivot->quantityLabel() }}</span>
                                @endif
                            </div>
                            @if ($activity->pivot->notes)
                                <div class="text-nicon-muted">{{ $activity->pivot->notes }}</div>
                            @endif
                        </li>
                    @empty
                        <li class="text-nicon-muted">Nog geen werkzaamheden.</li>
                    @endforelse
                </ul>
                @if ($project->work_description)
                    <div class="mt-4 border-t border-nicon-line pt-3">
                        <div class="text-[11px] uppercase tracking-wide text-nicon-muted">Omschrijving</div>
                        <p class="mt-1 whitespace-pre-line text-sm">{{ $project->work_description }}</p>
                    </div>
                @endif
            </section>

            <section class="border border-nicon-line bg-white p-5">
                <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Bijlagen</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($bijlagen as $document)
                        <li class="flex items-center justify-between gap-2">
                            <a href="{{ route('projects.documents.show', [$project, $document]) }}" class="text-nicon-orange-dark">{{ $document->original_filename }}</a>
                            @can('update', $project)
                                <form method="POST" action="{{ route('projects.winkel.attachments.destroy', [$project, $document]) }}" onsubmit="return confirm('Deze bijlage verwijderen?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-nicon-danger">Verwijderen</button>
                                </form>
                            @endcan
                        </li>
                    @empty
                        <li class="text-nicon-muted">Nog geen bijlagen.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
@endsection
