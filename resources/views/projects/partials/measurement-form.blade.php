@php
    $measurementFilled = $measurementFilled ?? false;
    $measurementOpen = $measurementOpen ?? false;
    $measurementRows = $measurementRows ?? [];
    $meterUsers = $meterUsers ?? collect();
    $measurementMeterUserId = $measurementMeterUserId ?? null;
    $measurementOrderedAt = $measurementOrderedAt ?? null;
    $measurementInstallationAt = $measurementInstallationAt ?? null;
    $canEdit = $canEdit ?? true;
    $units = \App\Services\MeasurementFormService::units();
    $locations = \App\Enums\MeasurementMaterialLocation::cases();
    $selectedIds = collect($selectedIds ?? old('work_activity_ids', isset($project) ? $project->workActivities?->pluck('id') : []))
        ->map(fn ($id) => (int) $id);
    $floorProductNames = collect($categories ?? [])
        ->flatMap(fn ($category) => $category->activities)
        ->filter(fn ($activity) => $activity->isMeasurementProduct($activity->category) && $selectedIds->contains((int) $activity->id))
        ->pluck('name')
        ->values()
        ->all();
@endphp
<section class="border border-nicon-line bg-nicon-paper p-4" data-measurement-form data-measurement-editable="{{ $canEdit ? '1' : '0' }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Inmeetformulier vloeren / plint / trap</h2>
            <p class="mt-1 text-sm">
                @if ($measurementFilled)
                    Inmeetformulier ✓ Ingevuld
                @else
                    Inmeetformulier — Nog niet ingevuld
                @endif
            </p>
            <p class="mt-1 text-xs text-nicon-muted">Klantgegevens komen uit dit winkelwerk. Niet verplicht.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($measurementFilled && isset($project) && $project->id)
                <a href="{{ route('projects.winkel.measurement.pdf', $project) }}" class="border border-nicon-line bg-white px-3 py-2 text-sm">PDF</a>
            @endif
            @if ($canEdit)
                <button type="button" class="bg-nicon-ink px-3 py-2 text-sm text-white" data-measurement-toggle data-open-label="Inmeetformulier verbergen" data-closed-label="{{ $measurementFilled ? 'Bekijken / wijzigen' : 'Inmeetformulier invullen' }}">
                    {{ $measurementOpen ? 'Inmeetformulier verbergen' : ($measurementFilled ? 'Bekijken / wijzigen' : 'Inmeetformulier invullen') }}
                </button>
            @endif
        </div>
    </div>

    <div class="mt-4 space-y-4{{ $measurementOpen ? '' : ' hidden' }}" data-measurement-panel @if (! $measurementOpen) hidden @endif>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[980px] border-collapse text-xs">
                <thead>
                    <tr class="text-left text-[10px] uppercase tracking-wide text-nicon-muted">
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Ruimte</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Product</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Merk</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Type</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Kleurnr.</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">M1/M2</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Ondervloer</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Plinten</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Treden</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Profiel</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium">Aanwezig</th>
                        <th class="border border-nicon-line bg-white px-1 py-1 font-medium"></th>
                    </tr>
                </thead>
                <tbody data-measurement-rows>
                    @foreach ($measurementRows as $index => $row)
                        @include('projects.partials.measurement-form-row', [
                            'row' => $row,
                            'units' => $units,
                            'canEdit' => $canEdit,
                            'index' => $index,
                            'floorProductNames' => $floorProductNames,
                            'locations' => $locations,
                        ])
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($canEdit)
            <button type="button" class="border border-nicon-line bg-white px-3 py-2 text-sm" data-measurement-add>+ Regel toevoegen</button>
        @endif
        <p class="text-xs text-nicon-muted">Producten komen uit de aangevinkte vloerwerkzaamheden. M¹/M² mag per product niet boven het aantal bovenaan uitkomen.</p>
        <ul class="space-y-0.5 text-xs text-nicon-steel" data-measurement-allocation></ul>
        <p class="hidden text-sm text-nicon-danger" data-measurement-allocation-error hidden></p>

        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="measurement_meter_user_id">Inmeter</label>
                <select id="measurement_meter_user_id" name="measurement[meter_user_id]" class="mt-1 w-full border border-nicon-line bg-white px-2 py-1.5" @disabled(! $canEdit)>
                    <option value="">—</option>
                    @foreach ($meterUsers as $meter)
                        <option value="{{ $meter->id }}" @selected((string) $measurementMeterUserId === (string) $meter->id)>{{ $meter->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="measurement_ordered_at">Besteld op</label>
                <input id="measurement_ordered_at" type="date" name="measurement[ordered_at]" value="{{ $measurementOrderedAt }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" @disabled(! $canEdit)>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="measurement_installation_at">Montage</label>
                <input id="measurement_installation_at" type="date" name="measurement[installation_at]" value="{{ $measurementInstallationAt }}" class="mt-1 w-full border border-nicon-line px-2 py-1.5" @disabled(! $canEdit)>
            </div>
        </div>
    </div>

    @if ($canEdit)
        <template data-measurement-row-template>
            @include('projects.partials.measurement-form-row', [
                'row' => [
                    'room' => '', 'product' => '', 'brand' => '', 'type' => '', 'color_number' => '',
                    'quantity' => '', 'unit' => '', 'underlay' => '', 'skirting' => '', 'steps' => '',
                    'profile' => '', 'available_on_site' => false, 'available_location' => '',
                ],
                'units' => $units,
                'canEdit' => true,
                'floorProductNames' => $floorProductNames,
                'locations' => $locations,
            ])
        </template>
    @endif
</section>
