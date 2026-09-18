@extends('layouts.app')

@section('title', $project->displayTitle().' · Nicon Planning')

@section('content')
    @php
        $bijlagen = $project->documents->where('document_type', \App\Services\ShopWorkService::ATTACHMENT_TYPE)->values();
        $selectedNotes = $project->workActivities->mapWithKeys(fn ($activity) => [$activity->id => $activity->pivot->notes])->all();
        $selectedQuantities = $project->workActivities->mapWithKeys(fn ($activity) => [$activity->id => $activity->pivot->quantity])->all();
        $selectedUnits = $project->workActivities->mapWithKeys(fn ($activity) => [$activity->id => $activity->pivot->unit?->value])->all();
        $selectedHours = $project->workItems
            ->whereNotNull('work_activity_id')
            ->mapWithKeys(fn ($item) => [$item->work_activity_id => $item->begrote_uren])
            ->all();
        $hourlyRate = old(
            'basis_uurtarief',
            $project->basis_uurtarief ?? \App\Enums\SmallWorkType::HOURLY_RATE
        );
        if (is_numeric($hourlyRate) && fmod((float) $hourlyRate, 1.0) === 0.0) {
            $hourlyRate = (int) (float) $hourlyRate;
        }
        $orderAmount = old('order_amount', $project->order_amount);
        if (is_numeric($orderAmount) && fmod((float) $orderAmount, 1.0) === 0.0) {
            $orderAmount = (int) (float) $orderAmount;
        }
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
    @if (auth()->user()?->canViewLaborCosts())
        @include('projects.partials.labor-summary', ['labor' => $labor, 'orderFinance' => $orderFinance ?? null])
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

    <div class="mt-8 space-y-6">
        @include('projects.partials.winkel-ticket')

        <form method="POST" action="{{ route('projects.winkel.update', $project) }}" enctype="multipart/form-data" class="space-y-4 border border-nicon-line bg-white p-4">
            @csrf
            @method('PATCH')
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="grid grid-cols-1 gap-x-3 gap-y-2 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="customer_name">Klant</label>
                        <input id="customer_name" name="customer_name" value="{{ old('customer_name', $project->customer?->name) }}" required class="mt-1 w-full border border-nicon-line px-2 py-1.5" @disabled(! auth()->user()?->can('update', $project))>
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="city">Plaats</label>
                        <input id="city" name="city" value="{{ old('city', $project->city) }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" @disabled(! auth()->user()?->can('update', $project))>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="address">Adres</label>
                        <input id="address" name="address" value="{{ old('address', $project->address) }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" @disabled(! auth()->user()?->can('update', $project))>
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="postal_code">Postcode</label>
                        <input id="postal_code" name="postal_code" value="{{ old('postal_code', $project->postal_code) }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" @disabled(! auth()->user()?->can('update', $project))>
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="contact_phone">Telefoon</label>
                        <input id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone', $project->contact_phone ?: $project->customer?->phone) }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="06 12345678" autocomplete="tel" @disabled(! auth()->user()?->can('update', $project))>
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="contact_email">E-mail</label>
                        <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', $project->contact_email ?: $project->customer?->email) }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="jansen@example.nl" autocomplete="email" @disabled(! auth()->user()?->can('update', $project))>
                    </div>
                    @if (auth()->user()?->canViewLaborCosts())
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="basis_uurtarief">Uurtarief (€) <span class="font-normal normal-case tracking-normal">Standaard €48/u</span></label>
                            <input id="basis_uurtarief" name="basis_uurtarief" value="{{ $hourlyRate }}" inputmode="decimal" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="48" title="Aanpasbaar. Begrote uren × dit tarief." @disabled(! auth()->user()?->can('update', $project))>
                        </div>
                        <div>
                            <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="order_amount">Orderbedrag excl. btw (€)</label>
                            <input id="order_amount" name="order_amount" value="{{ $orderAmount }}" inputmode="decimal" class="mt-1 w-full border border-nicon-line px-2 py-1.5" placeholder="8.500,00" title="Het totale verkoopbedrag van dit winkelwerk, exclusief btw." @disabled(! auth()->user()?->can('update', $project))>
                        </div>
                    @endif
                </div>
                <div class="flex flex-col gap-2">
                    <div class="flex min-h-0 grow flex-col">
                        <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="work_description">Omschrijving werkzaamheden</label>
                        <textarea id="work_description" name="work_description" rows="3" class="mt-1 min-h-[4.5rem] w-full grow border border-nicon-line px-2 py-1.5" @disabled(! auth()->user()?->can('update', $project))>{{ old('work_description', $project->work_description) }}</textarea>
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
                        'compact' => true,
                        'startYear' => $project->planningStartYear(),
                        'startWeek' => $project->planningStartWeek(),
                        'klaarYear' => $project->planningEndYear(),
                        'klaarWeek' => $project->planningEndWeek(),
                    ])
                    @can('update', $project)
                        @include('projects.partials.preferred-worker', [
                            'preferredWorkers' => $preferredWorkers,
                            'selectedWorkerId' => $selectedWorkerId ?? null,
                            'multiplePreferredWorkers' => $multiplePreferredWorkers ?? false,
                            'project' => $project,
                            'canUpdate' => true,
                        ])
                    @else
                        @if ($project->assignments->isNotEmpty())
                            <p class="text-sm">{{ $project->assignments->map(fn ($assignment) => $assignment->worker?->planName())->filter()->unique()->implode(', ') }}</p>
                        @endif
                    @endcan
                </div>
            </div>

            @include('projects.partials.shop-activities', [
                'categories' => $categories,
                'selectedIds' => old('work_activity_ids', $project->workActivities->pluck('id')),
                'activityNotes' => old('activity_notes', $selectedNotes),
                'activityQuantities' => old('activity_quantities', $selectedQuantities),
                'activityUnits' => old('activity_units', $selectedUnits),
                'activityHours' => old('activity_hours', $selectedHours),
                'hourlyRate' => $hourlyRate,
            ])

            @include('projects.partials.measurement-form', [
                'canEdit' => auth()->user()?->can('update', $project) ?? false,
            ])

            @can('update', $project)
                <button class="bg-nicon-ink text-white px-5 py-3 font-medium">Winkelwerk opslaan</button>
            @endcan
        </form>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="border border-nicon-line bg-white p-5">
                <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Planning</h2>
                <p class="mt-2 text-sm">Personen inplannen op dit Winkelwerk gebeurt in de bestaande Nicon Planning. Een voorkeur-vakman kun je daar altijd wijzigen.</p>
                @php
                    $assignedNames = $project->assignments
                        ->map(fn ($assignment) => $assignment->worker?->planName())
                        ->filter()
                        ->unique()
                        ->values();
                @endphp
                @if ($assignedNames->isNotEmpty())
                    <p class="mt-2 text-sm">Ingepland: {{ $assignedNames->implode(', ') }}</p>
                @endif
                @if ($project->assignments->isNotEmpty())
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($project->assignments as $assignment)
                            @php
                                $ticket = $assignment->workTickets->first();
                                $kindLabel = $assignment->worker
                                    ? \App\Enums\WorkTicketKind::forWorker($assignment->worker)->label()
                                    : 'Werkbon';
                            @endphp
                            @if ($ticket)
                                <li>
                                    <a href="{{ route('work-tickets.show', $ticket) }}" class="text-nicon-orange-dark">{{ $ticket->kind->label() }} {{ $ticket->number }}</a>
                                    <span class="text-nicon-muted">· {{ $assignment->worker?->planName() }}</span>
                                </li>
                            @else
                                @can('create', [\App\Models\WorkTicket::class, $assignment])
                                    <li>
                                        <a href="{{ route('projects.show', ['project' => $project, 'bon' => $assignment->id]) }}" class="text-nicon-orange-dark">{{ $kindLabel }} maken</a>
                                        <span class="text-nicon-muted">· {{ $assignment->worker?->planName() }} · werk uit de winkel</span>
                                    </li>
                                @endcan
                            @endif
                        @endforeach
                    </ul>
                @endif
                <a href="{{ route('planning', ['project_id' => $project->id]) }}" class="mt-4 inline-block bg-nicon-orange px-4 py-2 text-sm text-white">Open planning</a>
                @if ($project->nawLine())
                    <p class="mt-3 text-sm text-nicon-muted">{{ $project->nawLine() }}</p>
                    <a href="{{ $project->googleMapsUrl() }}" target="_blank" rel="noopener noreferrer" class="text-sm text-nicon-orange-dark">Navigeren</a>
                @endif
                @if ($project->contact_phone)
                    <p class="mt-3 text-sm"><a href="tel:{{ $project->contact_phone }}" class="text-nicon-orange-dark">{{ $project->contact_phone }}</a></p>
                @endif
                @if ($project->contact_email)
                    <p class="mt-1 text-sm"><a href="mailto:{{ $project->contact_email }}" class="text-nicon-orange-dark">{{ $project->contact_email }}</a></p>
                @endif
            </section>

            <section class="border border-nicon-line bg-white p-5">
                <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Geselecteerde onderdelen</h2>
                <ul class="mt-3 space-y-3 text-sm">
                    @forelse ($project->workActivities as $activity)
                        <li>
                            @php
                                $itemHours = (float) ($selectedHours[$activity->id] ?? 0);
                                $itemCost = $itemHours > 0.0001 && is_numeric($hourlyRate)
                                    ? $itemHours * (float) $hourlyRate
                                    : 0.0;
                                $itemQuantity = (float) ($activity->pivot->quantity ?? 0);
                                $itemUnit = $activity->pivot->unit instanceof \App\Enums\WorkUnit
                                    ? $activity->pivot->unit
                                    : \App\Enums\WorkUnit::tryFrom((string) $activity->pivot->unit);
                                $itemUnitPrice = $itemCost > 0.0001
                                    && $itemQuantity > 0.0001
                                    && ($itemUnit === \App\Enums\WorkUnit::SquareMeter || $itemUnit === \App\Enums\WorkUnit::LinearMeter)
                                    ? \App\Support\Format::euroWhole($itemCost / $itemQuantity).'/'.$itemUnit->label()
                                    : null;
                            @endphp
                            <div class="font-medium">
                                {{ $activity->name }}
                                @if ($activity->pivot->quantityLabel())
                                    <span class="font-normal text-nicon-muted">· {{ $activity->pivot->quantityLabel() }}</span>
                                @endif
                                @if ($itemHours > 0.0001)
                                    <span class="font-normal text-nicon-muted">· {{ \App\Support\PlanningHours::hoursLabel($itemHours) }}</span>
                                @endif
                                @if ($itemCost > 0.0001)
                                    <span class="font-normal text-nicon-muted">· {{ \App\Support\Format::euroWhole($itemCost) }}</span>
                                @endif
                                @if ($itemUnitPrice)
                                    <span class="font-normal text-nicon-muted">· {{ $itemUnitPrice }}</span>
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

@push('scripts')
    @vite(['resources/js/winkel-preferred-worker.js', 'resources/js/measurement-form.js'])
@endpush
