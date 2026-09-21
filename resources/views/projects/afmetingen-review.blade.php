@extends('layouts.app')

@section('title', 'Afmetingen controleren · Nicon Planning')

@section('content')
    @php
        $warnings = $parsed['warnings'] ?? [];
        $drawingLabels = $parsed['drawing_labels'] ?? [];
        $currentWorkType = old('work_type', $workType ?? '');
        $isCustom = $currentWorkType !== '' && ! in_array($currentWorkType, ['Raambekleding', 'Zonwering', 'PVC', 'Linoleum'], true);
        $rows = old('rooms', $reviewRows ?? []);
        $recognitionFailed = (bool) ($parsed['recognition_failed'] ?? false);
        $hasUncertain = collect($rows)->contains(fn ($row) => ($row['status'] ?? '') === 'controleren');
        $bannerClass = ($recognitionFailed || $hasUncertain)
            ? 'border-amber-400 bg-amber-50'
            : 'border-green-600 bg-green-50';
    @endphp

    <a href="{{ route('projects.create') }}" class="text-sm text-nicon-muted">← Andere bestanden kiezen</a>
    <h1 class="mt-2 text-2xl font-semibold">Afmetingen-PDF</h1>
    <p class="text-sm text-nicon-muted">
        {{ $filename }}
        · {{ count($rows) }} ruimtes
        · netto {{ \App\Support\Format::qty($parsed['netto_total'] ?? 0, 2) }} m²
        · geen Meetstaat-/MaterialList-import
        @if ($drawing)
            · plattegrond: {{ $drawing }}
        @endif
    </p>

    <div class="mt-4 border {{ $bannerClass }} px-4 py-3 text-sm">
        @if ($recognitionFailed)
            <p class="font-medium">{{ $parsed['recognition_message'] }}</p>
        @else
            <p class="font-medium">NETTO is leidend voor de planning.</p>
            @if ($hasUncertain)
                <p class="mt-1">Een deel van de regels is onzeker (status Controleren). Vul ontbrekende ruimtes en netto m² aan of verwijder lege regels.</p>
            @endif
        @endif
        <p class="mt-1 text-nicon-muted">
            @if (($parsed['bruto_total'] ?? null) !== null)
                Bruto {{ \App\Support\Format::qty($parsed['bruto_total'], 2) }} m² (informatief)
            @endif
            @if (($parsed['snijverlies_pct'] ?? null) !== null)
                · snijverlies {{ \App\Support\Format::qty($parsed['snijverlies_pct'], 0) }}% (informatief)
            @endif
            @if (($parsed['used_ocr'] ?? false))
                · gelezen via OCR
            @endif
        </p>
        @if ($drawingLabels !== [])
            <p class="mt-1">Plattegrond-labels gekoppeld: {{ implode(', ', $drawingLabels) }}</p>
        @endif
    </div>

    @if ($warnings !== [])
        <ul class="mt-4 text-sm text-nicon-warn list-disc pl-5">
            @foreach ($warnings as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    @endif

    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('projects.afmetingen.import', $token) }}" class="mt-6 max-w-5xl space-y-6" data-afmetingen-review>
        @csrf
        <div class="border border-nicon-line bg-white p-5 space-y-4">
            <h2 class="text-xs uppercase tracking-wide text-nicon-muted">Projectgegevens</h2>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="customer_name">Bedrijf / opdrachtgever</label>
                <input id="customer_name" name="customer_name" value="{{ old('customer_name') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="name">Projectnaam</label>
                <input id="name" name="name" value="{{ old('name') }}" required class="mt-1 w-full border border-nicon-line px-3 py-2">
            </div>
            <x-work-address id="address" class="mt-1" />
            @include('projects.partials.planning-weeks', ['idPrefix' => 'afmetingen-'])
            <div>
                <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="work_type">Werksoort</label>
                <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                    <select id="work_type" name="work_type" required class="w-full border border-nicon-line bg-white px-3 py-2 text-sm" data-work-type-select>
                        <option value="">— kies werksoort —</option>
                        @foreach (['Raambekleding', 'Zonwering', 'PVC', 'Linoleum'] as $option)
                            <option value="{{ $option }}" @selected($currentWorkType === $option)>{{ $option }}</option>
                        @endforeach
                        <option value="__custom__" @selected($isCustom)>Anders (vrij invoeren)</option>
                    </select>
                    <input type="text" name="work_type_custom" value="{{ old('work_type_custom', $isCustom ? $currentWorkType : '') }}" placeholder="Vrije werksoort" class="{{ $isCustom ? '' : 'hidden ' }}w-full border border-nicon-line px-3 py-2 text-sm" data-work-type-custom>
                </div>
            </div>
        </div>

        <div class="border border-nicon-line bg-white overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-nicon-ink text-white text-left">
                    <tr>
                        <th class="px-3 py-2">Ruimte</th>
                        <th class="px-3 py-2">Netto m²</th>
                        <th class="px-3 py-2">Bruto m²</th>
                        <th class="px-3 py-2">Werksoort</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody data-room-body>
                    @foreach ($rows as $index => $row)
                        @include('projects.partials.afmetingen-review-row', [
                            'index' => $index,
                            'row' => $row,
                            'workTypeOptions' => $workTypeOptions,
                        ])
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-nicon-line font-medium">
                        <td class="px-3 py-2">Totaal netto</td>
                        <td class="px-3 py-2" data-netto-total>{{ \App\Support\Format::qty($parsed['netto_total'] ?? 0, 2) }}</td>
                        <td class="px-3 py-2 text-nicon-muted" colspan="4">m² (bruto/snijverlies niet meegerekend)</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="button" class="border border-nicon-line px-3 py-2 text-sm" data-add-room>+ Ruimte toevoegen</button>
            <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Project aanmaken</button>
        </div>
    </form>

    <template data-room-template>
        @include('projects.partials.afmetingen-review-row', [
            'index' => '__INDEX__',
            'row' => [
                'label' => '',
                'netto' => '',
                'bruto' => '',
                'work_type' => $currentWorkType === '__custom__' ? old('work_type_custom', $workType) : $currentWorkType,
                'status' => 'controleren',
            ],
            'workTypeOptions' => $workTypeOptions,
        ])
    </template>

    <script>
        (() => {
            const select = document.querySelector('[data-work-type-select]');
            const custom = document.querySelector('[data-work-type-custom]');
            if (select && custom) {
                const sync = () => {
                    const show = select.value === '__custom__';
                    custom.classList.toggle('hidden', ! show);
                    custom.required = show;
                };
                select.addEventListener('change', sync);
                sync();
            }

            const form = document.querySelector('[data-afmetingen-review]');
            const body = document.querySelector('[data-room-body]');
            const template = document.querySelector('[data-room-template]');
            const addButton = document.querySelector('[data-add-room]');
            const totalCell = document.querySelector('[data-netto-total]');

            const nextIndex = () => {
                let max = -1;
                body?.querySelectorAll('[data-room-row]').forEach((row) => {
                    const value = Number(row.getAttribute('data-room-index') || '-1');
                    if (value > max) {
                        max = value;
                    }
                });
                return max + 1;
            };

            const formatNetto = (value) => value.toFixed(2).replace('.', ',');

            const refreshTotal = () => {
                if (! totalCell || ! body) {
                    return;
                }
                let sum = 0;
                body.querySelectorAll('[data-netto-input]').forEach((input) => {
                    const raw = String(input.value || '').replace(/\s/g, '').replace(',', '.');
                    const qty = Number.parseFloat(raw);
                    if (! Number.isNaN(qty) && qty > 0) {
                        sum += qty;
                    }
                });
                totalCell.textContent = formatNetto(sum);
            };

            addButton?.addEventListener('click', () => {
                if (! body || ! template) {
                    return;
                }
                const index = nextIndex();
                const html = template.innerHTML.replaceAll('__INDEX__', String(index));
                const wrapper = document.createElement('tbody');
                wrapper.innerHTML = html.trim();
                const row = wrapper.querySelector('[data-room-row]');
                if (! row) {
                    return;
                }
                const workType = select?.value === '__custom__' ? (custom?.value || '') : (select?.value || '');
                const workSelect = row.querySelector('[data-row-work-type]');
                if (workSelect && workType) {
                    if (![...workSelect.options].some((option) => option.value === workType)) {
                        const option = document.createElement('option');
                        option.value = workType;
                        option.textContent = workType;
                        workSelect.append(option);
                    }
                    workSelect.value = workType;
                }
                body.append(row);
                row.querySelector('input')?.focus();
                refreshTotal();
            });

            form?.addEventListener('click', (event) => {
                const button = event.target.closest('[data-remove-room]');
                if (! button) {
                    return;
                }
                event.preventDefault();
                button.closest('[data-room-row]')?.remove();
                refreshTotal();
            });

            form?.addEventListener('input', (event) => {
                if (event.target.matches('[data-netto-input]')) {
                    refreshTotal();
                }
            });
        })();
    </script>
@endsection
