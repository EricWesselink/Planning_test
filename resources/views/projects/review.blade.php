@extends('layouts.app')

@section('title', 'Import controleren · Nicon Planning')

@section('content')
    @php
        $header = $preview['header'] ?? [];
        $projectIdentityMissing = blank($header['customer_name'] ?? null) || blank($header['project_name'] ?? null);
        $works = $preview['works'] ?? [];
        $areas = collect($preview['areas'] ?? []);
        $reviewCount = $areas->where('needs_review', true)->count();
        $drawing = $drawing ?? null;
        $hasMeetstaat = $hasMeetstaat ?? false;
        $uploads = $uploads ?? [];
        $sources = $preview['sources'] ?? [];
        $closure = $preview['import_closure'] ?? [];
        $closureReady = (bool) ($closure['ready'] ?? false);
        $withWarnings = ($closure['decision'] ?? '') === \App\Enums\ImportDecision::ReadyWithWarnings->value;
        $warningCount = (int) ($closure['warning_count'] ?? 0);
        $closurePerc = $closure['percentages'] ?? [];
        $closureTotals = $closure['totals'] ?? [];
        $closureIssues = $closure['issues'] ?? [];
        $closureChecks = $closure['checks'] ?? [];
        $report = $preview['import_report'] ?? [];
        $sourceAnalysis = $preview['source_analysis'] ?? $uploads;
        $issueCategories = collect($closureIssues)->pluck('category')->filter()->unique();
        $failedCheckKeys = collect($closureChecks)->filter(fn (array $check): bool => empty($check['ok']))->pluck('key');
        $materialCheckNeedsReview = collect($preview['material_check'] ?? [])->contains(
            fn (array $entry): bool => ($entry['status'] ?? '') === 'controleren'
        );
        $reportMaterialsNeedReview = collect($report['materials'] ?? [])->contains(
            fn (array $row): bool => ($row['status'] ?? '') === 'controleren'
        );
        $legendNeedsReview = collect($preview['legend'] ?? [])->contains(
            fn (array $entry): bool => ($entry['status'] ?? '') === 'controleren'
        );
        $worksNeedReview = collect($works)->contains(function (array $work): bool {
            $declared = (float) ($work['declared_total'] ?? 0);
            $calculated = (float) ($work['calculated_total'] ?? 0);

            return abs(round($calculated - $declared, 2)) > 0.05;
        });
        $openProject = $projectIdentityMissing
            || $errors->has('customer_name')
            || $errors->has('project_name')
            || (! $closureReady && (
                $issueCategories->contains('project_header')
                || $failedCheckKeys->contains('project_header')
                || ! empty($preview['project_header_mismatches'])
            ));
        $openSources = ! $closureReady && (
            $issueCategories->contains('source_rules')
            || $failedCheckKeys->contains('source_rules')
            || $failedCheckKeys->contains('duplicates')
            || ! empty($preview['uncertain'])
        );
        $openWorks = ! $closureReady && $worksNeedReview;
        $openMaterials = ! $closureReady && (
            $issueCategories->contains('materials')
            || $issueCategories->contains('quantities')
            || $failedCheckKeys->contains('materials')
            || $failedCheckKeys->contains('material_totals')
            || $failedCheckKeys->contains('project_total')
            || $materialCheckNeedsReview
            || $reportMaterialsNeedReview
        );
        $openRooms = ! $closureReady && (
            $reviewCount > 0
            || $legendNeedsReview
            || ! empty($report['incomplete_recognition'])
            || $issueCategories->contains('rooms')
            || $issueCategories->contains('floors')
            || $issueCategories->contains('legend')
            || $issueCategories->contains('tasks')
            || $issueCategories->contains('drawing')
            || $issueCategories->contains('task_source')
            || $failedCheckKeys->contains('rooms')
            || $failedCheckKeys->contains('floors')
            || $failedCheckKeys->contains('legend_totals')
            || $failedCheckKeys->contains('open_review')
            || $failedCheckKeys->contains('tasks')
            || $failedCheckKeys->contains('task_source')
            || $failedCheckKeys->contains('floor_totals')
        );
        $hasMaterialFold = ! empty($preview['material_check']) || ! empty($report['materials']);
    @endphp

    <a href="{{ route('projects.create') }}" class="text-sm text-nicon-muted">← Andere PDF kiezen</a>
    <h1 class="mt-2 text-2xl font-semibold">
        @if (! $closureReady)
            Import geblokkeerd — technische fout
        @elseif ($withWarnings)
            Meetstaat sluitend
        @else
            Importcontrole
        @endif
    </h1>
    @if (session('status'))
        <p class="mt-3 border border-nicon-line bg-white px-4 py-2 text-sm">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-3 list-disc border border-nicon-warn bg-amber-50 px-4 py-2 pl-8 text-sm text-nicon-warn">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
    <p class="text-sm text-nicon-muted">
        {{ $filename }}
        · {{ $areas->count() }} ruimtes
        · {{ count($works) }} werksoorten
        · {{ count($preview['floors'] ?? []) }} bouwlagen
        @if (!empty($preview['engine'])) · gelezen via {{ $preview['engine'] }} @endif
        @if (!empty($closure['decision']))
            · {{ $closure['decision'] }}
        @endif
    </p>

    <form method="POST" action="{{ route('projects.import', $token) }}" enctype="multipart/form-data" class="mt-4 space-y-4" data-review-form id="review-import-form" novalidate @if ($closureReady) data-import-ready="1" @endif>
        @csrf

        <div id="importcontrole" class="border {{ $closureReady ? ($withWarnings ? 'border-amber-400 bg-amber-50' : 'border-green-600 bg-green-50') : 'border-nicon-danger bg-red-50' }} px-4 py-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold tracking-wide">IMPORTCONTROLE</h2>
                @if (! $closureReady)
                    <span class="text-sm font-medium text-nicon-danger">{{ $closure['button_label'] ?? 'Importeren geblokkeerd' }}</span>
                @endif
            </div>
            @if ($closureReady)
                <p class="mt-2 text-sm font-medium">{{ $closure['summary'] ?? 'Meetstaat sluitend' }}</p>
                @if ($withWarnings && ($closure['warning_summary'] ?? null))
                    <p class="text-sm text-nicon-warn">{{ $closure['warning_summary'] }}</p>
                @endif
            @elseif (($closure['summary'] ?? null))
                <p class="mt-2 text-sm font-medium text-nicon-danger">{{ $closure['summary'] }}</p>
            @endif
            @if ($projectIdentityMissing)
                <p class="mt-2 text-sm">Klantnaam en projectnaam zijn niet in de geüploade bestanden gevonden. Vul ze in bij <a href="#projectgegevens" class="underline">Projectgegevens</a> voordat je definitief importeert.</p>
            @endif
            <div class="mt-3 flex flex-wrap items-end justify-between gap-4 text-sm">
                <div>
                    <div>
                        <span class="font-medium">Totaal verwacht:</span>
                        <span class="ml-1 text-[10px] font-semibold uppercase tracking-wide text-nicon-ok">Meetstaat netto LEIDEND</span>
                        @if (($closureTotals['expected'] ?? null) !== null)
                            {{ \App\Support\Format::qty($closureTotals['expected'], 2) }} m²
                        @else
                            —
                        @endif
                    </div>
                    <div><span class="font-medium">Totaal verwerkt:</span> {{ \App\Support\Format::qty($closureTotals['processed'] ?? 0, 2) }} m²</div>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($warningCount > 0)
                        <button
                            type="button"
                            data-open-warnings
                            class="border border-nicon-line bg-white px-5 py-3 font-medium"
                        >
                            Waarschuwingen bekijken
                        </button>
                    @endif
                    <button
                        type="submit"
                        form="review-import-form"
                        data-import-submit
                        data-warning-count="{{ $warningCount }}"
                        class="px-5 py-3 font-medium {{ $closureReady ? 'bg-nicon-orange text-white' : 'bg-nicon-sand text-nicon-muted border border-nicon-line' }}"
                        @disabled(! $closureReady)
                    >
                        Project definitief importeren
                    </button>
                </div>
            </div>
            @if ($warningCount > 0)
                <details id="import-warnings" class="mt-3">
                    <summary class="sr-only">Waarschuwingen</summary>
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs border border-nicon-line bg-white">
                            <thead class="bg-nicon-sand text-left">
                                <tr>
                                    <th class="px-2 py-1">Gevonden</th>
                                    <th class="px-2 py-1">Verwacht</th>
                                    <th class="px-2 py-1">Bron</th>
                                    <th class="px-2 py-1">Probleem</th>
                                    <th class="px-2 py-1">Voorgestelde match</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($closureIssues as $issue)
                                @if (($issue['severity'] ?? 'warning') === 'technical')
                                    @continue
                                @endif
                                <tr class="border-t border-nicon-line">
                                    <td class="px-2 py-1">
                                        @if (!empty($issue['anchor']))
                                            <a href="{{ $issue['anchor'] }}" class="text-nicon-orange underline">{{ $issue['found'] ?? '' }}</a>
                                        @else
                                            {{ $issue['found'] ?? '' }}
                                        @endif
                                    </td>
                                    <td class="px-2 py-1">{{ $issue['expected'] ?? '' }}</td>
                                    <td class="px-2 py-1">{{ $issue['source'] ?? '' }}</td>
                                    <td class="px-2 py-1">{{ $issue['problem'] ?? '' }}</td>
                                    <td class="px-2 py-1">{{ $issue['suggested_match'] ?? '' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
            @if (! $closureReady && ! empty($closureIssues))
                <ul class="mt-3 space-y-0.5 text-sm text-nicon-danger">
                    @foreach ($closureIssues as $issue)
                        @if (($issue['severity'] ?? '') !== 'technical')
                            @continue
                        @endif
                        <li>{{ $issue['problem'] ?? '' }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-2 text-xs text-nicon-muted">
                @if (! $closureReady)
                    Alleen een technische fout blokkeert definitief importeren. Inhoudelijke bronverschillen zijn waarschuwingen.
                @elseif ($withWarnings)
                    Meetstaat is leidend. Waarschuwingen hoef je niet eerst te corrigeren.
                @else
                    Geen waarschuwingen. Je kunt het project direct importeren.
                @endif
            </p>
        </div>

        <dialog id="import-confirm-dialog" class="w-[min(32rem,calc(100%-2rem))] border border-nicon-line bg-white p-4 shadow-sm">
            <p class="text-sm" data-import-confirm-text>
                Er zijn nog waarschuwingen. De Meetstaat blijft leidend. Wil je het project toch importeren?
            </p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" data-import-cancel class="border border-nicon-line bg-white px-4 py-2 text-sm">Annuleren</button>
                <button type="button" data-import-confirm class="bg-nicon-orange px-4 py-2 text-sm font-medium text-white">Toch importeren</button>
            </div>
        </dialog>

        <p class="text-sm text-nicon-muted">
            @if ($closureReady)
                Inhoudelijke waarschuwingen blokkeren importeren niet. Alleen een technische fout stopt het opslaan.
            @else
                De bestanden konden technisch niet verwerkt worden. Corrigeer de upload of kies een ander bestand.
            @endif
        </p>

        <x-review-fold
            id="projectgegevens"
            title="Projectgegevens"
            :expanded="$openProject"
            :badge="$projectIdentityMissing ? 'Invullen' : ($openProject ? 'Controleren' : null)"
        >
            @if ($projectIdentityMissing)
                <p class="text-sm">Deze gegevens staan niet in de geüploade bestanden. Vul klantnaam en projectnaam hier in.</p>
            @endif
            @if (!empty($header['source_label']))
                <p class="text-xs uppercase tracking-wide text-nicon-muted">Bron: {{ $header['source_label'] }}</p>
            @endif
            @if (!empty($preview['project_header_mismatches']))
                <div class="border border-nicon-warn bg-amber-50 px-3 py-2 text-sm text-nicon-warn">
                    <p class="font-medium">Mogelijk bestand van ander project</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($preview['project_header_mismatches'] as $mismatch)
                            <li>
                                {{ $mismatch['source'] ?? 'Ander bestand' }}:
                                {{ $mismatch['label'] ?? 'gegeven' }}
                                "{{ $mismatch['other'] ?? '' }}"
                                (Materialenstaat: "{{ $mismatch['primary'] ?? '' }}")
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-xs">Dit is een waarschuwing. Je kunt het project importeren; de Meetstaat blijft leidend.</p>
                </div>
            @endif
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="text-xs uppercase tracking-wide text-nicon-muted" for="customer_name">Klantnaam</label>
                    <input id="customer_name" name="customer_name" value="{{ old('customer_name', $header['customer_name']) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2">
                    @error('customer_name')
                        <p class="mt-1 text-sm text-nicon-warn">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-nicon-muted">Werknummer</label>
                    <input name="project_number" value="{{ old('project_number', $header['project_number']) }}" class="mt-1 w-full border border-nicon-line px-3 py-2" inputmode="text">
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs uppercase tracking-wide text-nicon-muted" for="project_name">Projectnaam</label>
                    <input id="project_name" name="project_name" value="{{ old('project_name', $header['project_name']) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2">
                    @error('project_name')
                        <p class="mt-1 text-sm text-nicon-warn">{{ $message }}</p>
                    @enderror
                    @if ($header['reference'] && ($header['reference'] !== $header['project_name']))
                        <p class="mt-1 text-xs text-nicon-muted">Uit PDF: {{ $header['reference'] }}</p>
                    @endif
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-nicon-muted">Datum</label>
                    <input type="date" name="date" value="{{ old('date', $header['date']) }}" class="mt-1 w-full border border-nicon-line px-3 py-2">
                </div>
                <div class="sm:col-span-2">
                    <x-work-address
                        class="mt-1"
                        :value="\App\Support\WorkAddress::compose($header['address'] ?? null, $header['postal_code'] ?? null, $header['city'] ?? null)"
                        hint="Optioneel. Later bij het project aan te vullen."
                        show-maps
                    />
                </div>
                <div class="sm:col-span-2">
                    @include('projects.partials.planning-weeks', ['idPrefix' => 'import-'])
                </div>
            </div>
        </x-review-fold>

        <x-review-fold
            id="bronbestanden"
            title="Bronbestanden"
            :expanded="$openSources"
            :badge="$openSources ? 'Controleren' : null"
        >
            <p class="text-sm text-nicon-muted">
                {{ $preview['source_priority_note'] ?? '' }}
                @if (empty($preview['source_priority_note']))
                    @if (!empty($sources['meetstaat']))
                        Meetstaat is leidend voor de netto m²/m¹ van het uit te voeren werk. Excel is calculatie/uren; Materialenstaat is materiaalcontrole; de plattegrond koppelt ruimtes.
                    @elseif (!empty($sources['plattegrond']))
                        De plattegrond bepaalt de fysieke ruimtes. Snijmaten en MaterialList mogen geen ruimtes of bouwlagen toevoegen; ze dienen alleen als controle/verrijking.
                    @elseif (!empty($sources['snijmaten']) || !empty($sources['materialenstaat']))
                        Zonder plattegrond of meetstaat maken Snijmaten en MaterialList geen fysieke ruimtes aan.
                    @else
                        Zonder meetstaat gebruiken we alleen betrouwbare plattegrond-tekst; ontbrekende m² blijft leeg.
                    @endif
                @endif
                Ruimte-m² is de fysieke ruimte. Materiaal-m² blijft per werkzaamheid.
            </p>

            @if (!empty($sourceAnalysis))
                <div class="overflow-x-auto">
                    <h3 class="mb-2 text-sm font-semibold">Bronanalyse geüploade bestanden</h3>
                    <table class="w-full text-xs border border-nicon-line bg-white">
                        <thead class="bg-nicon-sand text-left">
                            <tr>
                                <th class="px-2 py-1">Bestand</th>
                                <th class="px-2 py-1">Herkend als</th>
                                <th class="px-2 py-1">Bruikbare gegevens</th>
                                <th class="px-2 py-1">Betrouwbaarheid</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($sourceAnalysis as $doc)
                            <tr class="border-t border-nicon-line">
                                <td class="px-2 py-1">{{ $doc['original'] ?? '' }}</td>
                                <td class="px-2 py-1">
                                    {{ $doc['type_label'] ?? $doc['label'] ?? '' }}
                                    @if (!empty($doc['roles_label']))
                                        <span class="text-nicon-muted">· {{ $doc['roles_label'] }}</span>
                                    @elseif (!empty($doc['roles']))
                                        <span class="text-nicon-muted">· {{ implode(' + ', $doc['roles']) }}</span>
                                    @endif
                                </td>
                                <td class="px-2 py-1">{{ $doc['usable_data'] ?? '—' }}</td>
                                <td class="px-2 py-1">{{ $doc['reliability_label'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <p class="mt-1 text-xs text-nicon-muted">
                        Rollen zijn inhoudelijk (niet op bestandsnaam). Eén bestand mag meerdere rollen hebben.
                        Fysieke m² (`project_areas`) en materiaaltaken (`area_tasks`) blijven gescheiden.
                    </p>
                </div>
            @endif

            @if ($uploads !== [])
                <ul class="text-sm text-nicon-muted">
                    @foreach ($uploads as $upload)
                        <li>✓ {{ $upload['original'] }} — {{ $upload['label'] }}</li>
                    @endforeach
                </ul>
            @endif

            <div>
                <h3 class="font-semibold">Plattegrond / tekening</h3>
                <p class="mt-1 text-sm text-nicon-muted">Optioneel. De PDF blijft zichtbaar op het tabblad Tekening. Ruimtes uit meetstaat en plattegrond worden op exact ruimtenummer gekoppeld (1.19 is niet 1.19a).</p>
                @if ($drawing)
                    <p class="mt-3 text-sm">Meegeleverd: <span class="font-medium">{{ $drawing }}</span></p>
                @endif
                <div class="mt-3">
                    <label class="text-xs uppercase tracking-wide text-nicon-muted">{{ $drawing ? 'Andere PDF kiezen' : 'PDF-plattegrond' }}</label>
                    <input type="file" name="plattegrond" accept=".pdf,application/pdf" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
                </div>
            </div>

            @if (!empty($preview['uncertain']))
                <div>
                    <h3 class="font-semibold">Niet zeker herkend</h3>
                    <ul class="mt-2 text-sm text-nicon-warn list-disc pl-5">
                        @foreach ($preview['uncertain'] as $row)
                            <li id="uncertain-{{ $loop->index }}"><code>{{ $row['line'] }}</code> — {{ $row['reason'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-review-fold>

        @if ($works !== [])
            <x-review-fold
                id="totaal-per-werksoort"
                title="Totaal per werksoort"
                :expanded="$openWorks"
                :badge="$openWorks ? 'Controleren' : null"
            >
                <p class="text-sm text-nicon-muted">PDF-totaal is ter controle. Na import tellen de berekende regels.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-nicon-ink text-white text-left">
                            <tr>
                                <th class="px-3 py-2">Werk / product</th>
                                <th class="px-3 py-2">Eenheid</th>
                                <th class="px-3 py-2">PDF</th>
                                <th class="px-3 py-2">Berekend</th>
                                <th class="px-3 py-2">Verschil</th>
                                <th class="px-3 py-2">Skip</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($works as $work)
                            @php
                                $declared = (float) ($work['declared_total'] ?? 0);
                                $calculated = (float) ($work['calculated_total'] ?? 0);
                                $diff = round($calculated - $declared, 2);
                                $unit = ($work['unit'] ?? '') === 'm1' ? 'm¹' : 'm²';
                            @endphp
                            <tr class="border-t border-nicon-line">
                                <td class="px-3 py-2">{{ $work['name'] }}</td>
                                <td class="px-3 py-2">{{ $unit }}</td>
                                <td class="px-3 py-2">{{ \App\Support\Format::qty($declared, 2) }}</td>
                                <td class="px-3 py-2 font-medium">{{ \App\Support\Format::qty($calculated, 2) }}</td>
                                <td class="px-3 py-2 {{ abs($diff) > 0.05 ? 'text-nicon-warn' : 'text-nicon-muted' }}">
                                    {{ $diff > 0 ? '+' : '' }}{{ \App\Support\Format::qty($diff, 2) }}
                                </td>
                                <td class="px-3 py-2">
                                    <label class="text-xs text-nicon-muted">
                                        <input type="checkbox" name="exclude_works[]" value="{{ $work['name'] }}"> niet
                                    </label>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-review-fold>
        @endif

        @include('projects.partials.review-calculation-labor')

        @if ($hasMaterialFold)
            <x-review-fold
                id="materiallist"
                title="MaterialList versus tekeningen"
                :expanded="$openMaterials"
                :badge="$openMaterials ? 'Controleren' : null"
            >
                @if (!empty($preview['material_check']))
                    <p class="text-sm text-nicon-muted">Project-eindcontrole. MaterialList maakt geen ruimtes; alleen totalen worden vergeleken.</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-nicon-sand text-left">
                                <tr>
                                    <th class="px-3 py-2">Materiaal</th>
                                    <th class="px-3 py-2">Eenheid</th>
                                    <th class="px-3 py-2">MaterialList netto</th>
                                    <th class="px-3 py-2">Som tekeningen</th>
                                    <th class="px-3 py-2">Verschil</th>
                                    <th class="px-3 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($preview['material_check'] as $entry)
                                @php
                                    $declaredKnown = array_key_exists('declared_known', $entry)
                                        ? (bool) $entry['declared_known']
                                        : ($entry['declared_total'] ?? null) !== null;
                                @endphp
                                <tr class="border-t border-nicon-line {{ ($entry['status'] ?? '') === 'controleren' ? 'bg-amber-50' : '' }}">
                                    <td class="px-3 py-2">
                                        @php
                                            $matColor = \App\Support\MaterialColor::resolve($entry['color'] ?? null, $entry['material'] ?? null);
                                        @endphp
                                        <span class="inline-block size-3 border border-nicon-line align-middle" style="background: {{ $matColor }}"></span>
                                        <span class="align-middle">{{ $entry['material'] ?? '' }}</span>
                                    </td>
                                    <td class="px-3 py-2">{{ $entry['unit_label'] ?? 'm²' }}</td>
                                    <td class="px-3 py-2">{{ \App\Support\Format::qtyOrUnknown($entry['declared_total'] ?? null, 2, $declaredKnown) }}</td>
                                    <td class="px-3 py-2">{{ \App\Support\Format::qty($entry['calculated_total'] ?? 0, 2) }}</td>
                                    <td class="px-3 py-2">
                                        @if ($declaredKnown && ($entry['difference'] ?? null) !== null)
                                            {{ (($entry['difference'] ?? 0) > 0 ? '+' : '') . \App\Support\Format::qty($entry['difference'] ?? 0, 2) }}
                                        @else
                                            Onbekend
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 {{ ($entry['status'] ?? '') === 'ok' ? 'text-nicon-muted' : 'text-nicon-warn font-medium' }}">
                                        {{ $entry['status_label'] ?? 'Controleren' }}
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if (!empty($report['materials']))
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs border border-nicon-line bg-white">
                            <thead class="bg-nicon-sand text-left">
                                <tr>
                                    <th class="px-2 py-1">Materiaal</th>
                                    <th class="px-2 py-1">Gevonden taak-m²</th>
                                    <th class="px-2 py-1">Verwacht taak-m²</th>
                                    <th class="px-2 py-1">Verschil</th>
                                    <th class="px-2 py-1">Bron</th>
                                    <th class="px-2 py-1">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($report['materials'] as $materialRow)
                                <tr id="material-{{ $loop->index }}" class="border-t border-nicon-line">
                                    <td class="px-2 py-1">{{ \App\Support\Format::qty($materialRow['found_task_meters'] ?? 0, 2) }}</td>
                                    <td class="px-2 py-1">
                                        @if (($materialRow['expected_task_meters'] ?? null) !== null)
                                            {{ \App\Support\Format::qty($materialRow['expected_task_meters'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-2 py-1">
                                        @if (($materialRow['difference'] ?? null) !== null)
                                            {{ ((($materialRow['difference'] ?? 0) > 0) ? '+' : '') . \App\Support\Format::qty($materialRow['difference'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-2 py-1">{{ $materialRow['expected_source'] ?? $materialRow['source'] ?? '—' }}</td>
                                    <td class="px-2 py-1">{{ $materialRow['status_label'] ?? '' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-sm text-nicon-muted">
                        Materiaalkoppelingen:
                        {{ $report['material_by_color'] ?? 0 }} kleur+legenda
                        · {{ $report['material_by_snijmaten'] ?? 0 }} snijmaten
                        · {{ $report['material_by_both'] ?? 0 }} beide
                        · {{ $report['material_unknown'] ?? 0 }} onbekend
                    </p>
                @endif
            </x-review-fold>
        @endif

        @php
            $areasByFloor = $areas->groupBy(fn (array $area) => ($area['floor'] ?? '') !== '' ? $area['floor'] : 'Onbekend');
            $legendByFloor = collect($preview['legend'] ?? [])->groupBy(fn (array $entry) => ($entry['floor'] ?? '') !== '' ? $entry['floor'] : 'Onbekend');
        @endphp

        <x-review-fold
            id="ruimtes"
            title="Ruimtes"
            :expanded="$openRooms"
            :badge="$openRooms ? ($reviewCount > 0 ? $reviewCount.' controleren' : 'Controleren') : null"
        >
            @if (!empty($report))
                @if (!empty($report['incomplete_recognition']))
                    <div class="border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-nicon-warn">
                        <div class="font-semibold">{{ $report['quality_label'] ?? 'Onvolledige herkenning – controleren' }}</div>
                        <div class="mt-1">
                            Fysieke ruimte-m² {{ \App\Support\Format::qty($report['physical_meters'] ?? $report['meters_found'] ?? 0, 2) }}
                            · taak-m² {{ \App\Support\Format::qty($report['task_meters'] ?? 0, 2) }}
                            @if (($report['task_meters_expected'] ?? $report['meters_expected'] ?? null) !== null)
                                · verwacht taak-m² {{ \App\Support\Format::qty($report['task_meters_expected'] ?? $report['meters_expected'], 2) }}
                                · verschil taak-m² {{ ((($report['task_meters_difference'] ?? $report['meters_difference'] ?? 0) > 0) ? '+' : '') . \App\Support\Format::qty($report['task_meters_difference'] ?? $report['meters_difference'] ?? 0, 2) }}
                                @if (!empty($report['expected_task_totals']['source_label']))
                                    · bron {{ $report['expected_task_totals']['source_label'] }}
                                @endif
                            @else
                                · geen betrouwbaar meetstaat-/MaterialList-totaal om taak-m² mee te vergelijken
                            @endif
                        </div>
                        <div class="mt-1 text-xs">Fysieke ruimte-m² en materiaal-/taak-m² zijn verschillende grootheden en worden niet door elkaar vergeleken.</div>
                    </div>
                @elseif (($report['quality_label'] ?? '') === 'Import gereed')
                    <div class="border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        <div class="font-semibold">Import gereed · {{ $closure['decision'] ?? 'READY' }}</div>
                        <div class="mt-1">
                            Taak-m² {{ \App\Support\Format::qty($report['task_meters'] ?? 0, 2) }}
                            @if (($report['task_meters_expected'] ?? null) !== null)
                                · declared NET {{ \App\Support\Format::qty($report['task_meters_expected'], 2) }}
                                ·
                                @if (!empty($closureTotals['difference_label']))
                                    {{ $closureTotals['difference_label'] }}
                                @else
                                    verschil {{ ((($report['task_meters_difference'] ?? 0) > 0) ? '+' : '') . \App\Support\Format::qty($report['task_meters_difference'] ?? 0, 2) }}
                                    @if (!empty($closureTotals['rounding_explained']))
                                        — verklaarde bronafronding ✓
                                    @endif
                                @endif
                            @endif
                            · {{ $report['rooms'] ?? 0 }} fysieke ruimtes
                            · {{ ($report['rooms'] ?? 0) - ($report['without_square_meters'] ?? 0) }} met fysieke m²
                            @if (($report['without_square_meters'] ?? 0) > 0)
                                · {{ $report['without_square_meters'] }} zonder tekening-m²
                                @if (($report['without_square_meters_explained'] ?? 0) > 0)
                                    ({{ $report['without_square_meters_explained'] }} meetstaat-only, geen betrouwbare contour)
                                @endif
                            @endif
                        </div>
                    </div>
                @endif
                <p class="text-sm">
                    Eindrapport:
                    {{ $report['rooms'] ?? 0 }} fysieke ruimtes
                    · fysiek {{ \App\Support\Format::qty($report['physical_meters'] ?? $report['meters_found'] ?? 0, 2) }} m²
                    · taak {{ \App\Support\Format::qty($report['task_meters'] ?? 0, 2) }} m²
                    @if (($report['meetstaat_task_meters'] ?? null) !== null)
                        · meetstaat-taak {{ \App\Support\Format::qty($report['meetstaat_task_meters'], 2) }} m²
                    @endif
                    · {{ $report['hoog'] ?? 0 }} Hoog
                    · {{ $report['midden'] ?? 0 }} Midden
                    · {{ $report['controleren'] ?? 0 }} Controleren
                    · {{ $report['duplicates_removed'] ?? 0 }} dubbele verwijderd
                    · {{ $report['without_square_meters'] ?? 0 }} zonder fysieke m²
                    @if (($report['drawing_linked_rooms'] ?? null) !== null)
                        · {{ $report['drawing_linked_rooms'] }} gekoppeld aan tekening
                    @endif
                    @if (!empty($report['drawing_strategy_label']))
                        · strategie: {{ $report['drawing_strategy_label'] }}
                    @endif
                </p>
                @if (!empty($report['floors']))
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs border border-nicon-line bg-white">
                            <thead class="bg-nicon-sand text-left">
                                <tr>
                                    <th class="px-2 py-1">Bouwlaag</th>
                                    <th class="px-2 py-1">Fysieke ruimtes</th>
                                    <th class="px-2 py-1">Fysieke m²</th>
                                    <th class="px-2 py-1">Taak-m²</th>
                                    <th class="px-2 py-1">Meetstaat taak-m²</th>
                                    <th class="px-2 py-1">Verschil taak-m²</th>
                                    <th class="px-2 py-1">Hoog</th>
                                    <th class="px-2 py-1">Midden</th>
                                    <th class="px-2 py-1">Controleren</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($report['floors'] as $floorRow)
                                <tr id="floor-{{ $loop->index }}" class="border-t border-nicon-line">
                                    <td class="px-2 py-1">{{ $floorRow['floor'] }}</td>
                                    <td class="px-2 py-1">{{ $floorRow['rooms'] }}</td>
                                    <td class="px-2 py-1">{{ \App\Support\Format::qty($floorRow['physical_meters'] ?? $floorRow['meters_found'] ?? 0, 2) }}</td>
                                    <td class="px-2 py-1">{{ \App\Support\Format::qty($floorRow['task_meters'] ?? 0, 2) }}</td>
                                    <td class="px-2 py-1">
                                        @if (($floorRow['meetstaat_task_meters'] ?? $floorRow['meters_expected'] ?? null) !== null)
                                            {{ \App\Support\Format::qty($floorRow['meetstaat_task_meters'] ?? $floorRow['meters_expected'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-2 py-1">
                                        @if (($floorRow['task_meters_difference'] ?? $floorRow['meters_difference'] ?? null) !== null)
                                            {{ ((($floorRow['task_meters_difference'] ?? $floorRow['meters_difference'] ?? 0) > 0) ? '+' : '') . \App\Support\Format::qty($floorRow['task_meters_difference'] ?? $floorRow['meters_difference'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-2 py-1">{{ $floorRow['hoog'] ?? 0 }}</td>
                                    <td class="px-2 py-1">{{ $floorRow['midden'] ?? 0 }}</td>
                                    <td class="px-2 py-1">{{ $floorRow['controleren'] ?? 0 }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            <div class="flex flex-wrap items-end justify-between gap-3">
                <p class="text-sm text-nicon-muted">
                    Verdieping, nummer, naam, ruimte-m² en materiaal kun je hier nog aanpassen.
                    @if ($reviewCount > 0)
                        <span class="text-nicon-warn">{{ $reviewCount }} regels vragen controle.</span>
                    @endif
                </p>
                <button type="button" class="border border-nicon-line px-3 py-2 text-sm" data-add-area>Ruimte toevoegen</button>
            </div>

            @if (!empty($preview['warnings']))
                <div>
                    <h3 class="font-semibold">Samengevoegde ruimtes</h3>
                    <p class="text-sm text-nicon-muted">Zelfde ruimtenummer + werksoort stond meerdere keren in de PDF (deelvlakken). Die zijn tot één ruimte samengevoegd.</p>
                    <ul class="mt-2 text-sm text-nicon-muted list-disc pl-5 max-h-48 overflow-auto">
                        @foreach (array_slice($preview['warnings'], 0, 20) as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                    @if (count($preview['warnings']) > 20)
                        <p class="mt-2 text-xs">… en {{ count($preview['warnings']) - 20 }} meer</p>
                    @endif
                </div>
            @endif

            @php $areaIndex = 0; @endphp
            @forelse ($areasByFloor as $floor => $floorAreas)
                <h3 class="font-semibold">{{ $floor }}</h3>
                @php $floorLegend = $legendByFloor->get($floor, collect()); @endphp
                @if ($floorLegend->isNotEmpty())
                    <p class="text-sm text-nicon-muted">Legenda versus materiaaltaken — som van area_tasks per bouwlaag + canonieke materiaalkleur (niet fysieke ruimte-m²).</p>
                    <div class="overflow-x-auto border border-nicon-line">
                        <table class="w-full text-sm">
                            <thead class="bg-nicon-sand text-left">
                                <tr>
                                    <th class="px-3 py-2">Materiaal</th>
                                    <th class="px-3 py-2">Eenheid</th>
                                    <th class="px-3 py-2">Legenda</th>
                                    <th class="px-3 py-2">Som taken</th>
                                    <th class="px-3 py-2">Verschil</th>
                                    <th class="px-3 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($floorLegend as $entry)
                                @php
                                    $status = $entry['status'] ?? ((empty($entry['needs_review'])) ? 'ok' : 'controleren');
                                    $declaredKnown = array_key_exists('declared_known', $entry)
                                        ? (bool) $entry['declared_known']
                                        : ($entry['declared_total'] ?? null) !== null;
                                @endphp
                                <tr class="border-t border-nicon-line {{ $status === 'controleren' ? 'bg-amber-50' : '' }}">
                                    <td class="px-3 py-2">
                                        @php
                                            $legendColor = \App\Support\MaterialColor::resolve($entry['color'] ?? null, $entry['material'] ?? null);
                                        @endphp
                                        <span class="inline-block size-4 border border-nicon-line align-middle" style="background: {{ $legendColor }}"></span>
                                        <span class="align-middle">{{ $entry['canonical_material'] ?? $entry['material'] }}</span>
                                        @if (!empty($entry['canonical_material']) && ($entry['canonical_material'] ?? '') !== ($entry['material'] ?? ''))
                                            <span class="block text-xs text-nicon-muted">{{ $entry['material'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">{{ $entry['unit_label'] ?? 'm²' }}</td>
                                    <td class="px-3 py-2">{{ \App\Support\Format::qtyOrUnknown($entry['declared_total'] ?? null, 2, $declaredKnown) }}</td>
                                    <td class="px-3 py-2">{{ \App\Support\Format::qty($entry['calculated_total'] ?? 0, 2) }}</td>
                                    <td class="px-3 py-2">
                                        @if ($declaredKnown && ($entry['difference'] ?? null) !== null)
                                            {{ (($entry['difference'] ?? 0) > 0 ? '+' : '') . \App\Support\Format::qty($entry['difference'] ?? 0, 2) }}
                                        @else
                                            Onbekend
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 {{ in_array($status, ['ok', 'informatief'], true) ? 'text-nicon-muted' : 'text-nicon-warn font-medium' }}">
                                        {{ $entry['status_label'] ?? ($status === 'ok' ? 'OK' : 'Controleren') }}
                                    </td>
                                </tr>
                                @if (!empty($entry['hint']['message']))
                                    <tr class="border-t border-nicon-line bg-amber-50/60">
                                        <td colspan="6" class="px-3 py-2 text-sm text-nicon-warn">
                                            {{ $entry['hint']['message'] }}
                                            @if (!empty($entry['hint']['candidates']))
                                                <span class="text-nicon-muted">
                                                    — mogelijke controle:
                                                    {{ collect($entry['hint']['candidates'])->map(fn ($c) => ($c['room_name'] ?? '?').' ('.\App\Support\Format::qty($c['square_meters'] ?? 0, 2).' m²)')->implode(', ') }}
                                                </span>
                                            @endif
                                            <span class="text-nicon-muted"> (niet automatisch aangepast)</span>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="overflow-x-auto border border-nicon-line">
                    <table class="w-full text-sm" data-area-table>
                        <thead class="bg-nicon-sand text-left">
                            <tr>
                                <th class="px-2 py-1.5">Verdieping</th>
                                <th class="px-2 py-1.5">Ruimtenummer</th>
                                <th class="px-2 py-1.5">Ruimte</th>
                                <th class="px-2 py-1.5">m²</th>
                                <th class="px-2 py-1.5">Materiaal</th>
                                <th class="px-2 py-1.5">Materiaalbron</th>
                                <th class="px-2 py-1.5">Herkend via</th>
                                <th class="px-2 py-1.5">Confidence</th>
                                <th class="px-2 py-1.5">Reden</th>
                                <th class="px-2 py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody data-area-body>
                        @foreach ($floorAreas as $area)
                            @include('projects.partials.review-area-row', ['index' => $areaIndex, 'area' => $area])
                            @php $areaIndex++; @endphp
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <div class="overflow-x-auto border border-nicon-line">
                    <table class="w-full text-sm" data-area-table>
                        <tbody data-area-body></tbody>
                    </table>
                </div>
                <p class="text-sm text-nicon-muted">Nog geen ruimtes. Voeg ze handmatig toe of ga terug voor een andere PDF.</p>
            @endforelse
        </x-review-fold>

        @if (!empty($preview['debug_rooms']))
            <x-review-fold id="herkenning-debug" title="Herkenning (debug)">
                <p class="text-sm text-nicon-muted">Resultaat van de tekeningparser vóór database-import. Ruimtenummer mag leeg zijn. Alleen regels met m² én naam in hetzelfde ruimtevlak worden als ruimte toegevoegd.</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-nicon-sand text-left">
                            <tr>
                                <th class="px-3 py-2">Pagina</th>
                                <th class="px-3 py-2">Bouwlaag</th>
                                <th class="px-3 py-2">Ruimtenummer</th>
                                <th class="px-3 py-2">m²</th>
                                <th class="px-3 py-2">Gekozen naam</th>
                                <th class="px-3 py-2">Afstand</th>
                                <th class="px-3 py-2">Kamercontour-id</th>
                                <th class="px-3 py-2">Kandidaten</th>
                                <th class="px-3 py-2">Fill-kleur</th>
                                <th class="px-3 py-2">Materiaal</th>
                                <th class="px-3 py-2">Confidence</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($preview['debug_rooms'] as $row)
                            <tr class="border-t border-nicon-line {{ ($row['confidence'] ?? '') === 'controleren' || ($row['room_name'] ?? '') === '' ? 'bg-amber-50' : '' }}">
                                <td class="px-3 py-2">{{ $row['page'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['floor'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['room_number'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ \App\Support\Format::qty($row['square_meters'] ?? null, 2) }}</td>
                                <td class="px-3 py-2">{{ $row['room_name'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ isset($row['distance']) && $row['distance'] !== null ? number_format((float) $row['distance'], 1, ',', '.') : '' }}</td>
                                <td class="px-3 py-2 text-xs">{{ $row['contour_id'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['candidate_count'] ?? '' }}</td>
                                <td class="px-3 py-2">
                                    @if (!empty($row['fill_color']))
                                        <span class="inline-block size-4 border border-nicon-line align-middle" style="background: {{ $row['fill_color'] }}"></span>
                                        <span class="align-middle text-xs">{{ $row['fill_color'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">{{ $row['legend_material'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ ($row['confidence'] ?? '') === 'hoog' ? 'Hoog' : (($row['confidence'] ?? '') === 'midden' ? 'Midden' : 'Controleren') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-review-fold>
        @endif

        @if (! $closureReady)
            <p class="text-sm text-nicon-warn">
                Los eerst de open bronconflicten op. Een klein totaalverschil telt alleen mee als het aantoonbaar bronafronding is; onverklaarde verschillen blijven blokkeren.
            </p>
        @endif
    </form>

    <template data-area-template>
        @include('projects.partials.review-area-row', [
            'index' => '__INDEX__',
            'area' => [
                'floor' => '',
                'room_number' => '',
                'room_name' => '',
                'square_meters' => null,
                'tasks' => [[
                    'work_name' => '',
                    'quantity' => null,
                    'unit' => 'm2',
                    'perimeter' => null,
                    'seams' => null,
                ]],
                'source' => 'handmatig',
                'source_label' => 'Handmatig',
                'recognized_via' => ['handmatig'],
                'recognized_via_label' => 'Handmatig',
                'confidence' => 'controleren',
                'confidence_label' => 'Controleren',
                'fill_color' => '',
                'needs_review' => true,
            ],
        ])
    </template>

    <script>
        (() => {
            const template = document.querySelector('[data-area-template]');
            const addButton = document.querySelector('[data-add-area]');
            const renameFields = (rootEl, index) => {
                rootEl.querySelectorAll('[name]').forEach((input) => {
                    input.name = input.name.replace('areas[__INDEX__]', `areas[${index}]`);
                });
            };
            addButton?.addEventListener('click', () => {
                const bodies = document.querySelectorAll('[data-area-body]');
                const body = bodies[bodies.length - 1];
                if (! body || ! template) return;
                const index = document.querySelectorAll('[data-area-row]').length;
                const wrapper = document.createElement('tbody');
                wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(index));
                const row = wrapper.querySelector('[data-area-row]');
                if (! row) return;
                renameFields(row, index);
                body.append(row);
            });
            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-remove-area]');
                if (! button) return;
                event.preventDefault();
                button.closest('[data-area-row]')?.remove();
            });

            // READY_*: stuur geen area-velden mee (max_input_vars kapt anders TASK_SOURCE af).
            const reviewForm = document.querySelector('[data-review-form]');
            const importSubmit = reviewForm?.querySelector('[data-import-submit]');
            const warningsPanel = document.querySelector('#import-warnings');
            const confirmDialog = document.querySelector('#import-confirm-dialog');
            const confirmText = confirmDialog?.querySelector('[data-import-confirm-text]');
            let importConfirmed = false;
            document.querySelector('[data-open-warnings]')?.addEventListener('click', () => {
                if (! warningsPanel) return;
                warningsPanel.open = true;
                warningsPanel.scrollIntoView({ block: 'nearest' });
            });
            reviewForm?.addEventListener('submit', (event) => {
                const warningCount = Number(importSubmit?.getAttribute('data-warning-count') || '0');
                if (warningCount > 0 && ! importConfirmed) {
                    event.preventDefault();
                    if (confirmText) {
                        confirmText.textContent = warningCount === 1
                            ? 'Er is nog 1 waarschuwing. De Meetstaat blijft leidend. Wil je het project toch importeren?'
                            : `Er zijn nog ${warningCount} waarschuwingen. De Meetstaat blijft leidend. Wil je het project toch importeren?`;
                    }
                    confirmDialog?.showModal();
                    return;
                }
                try {
                    if (reviewForm.getAttribute('data-import-ready') === '1') {
                        reviewForm.querySelectorAll('[name^="areas["]').forEach((input) => {
                            input.disabled = true;
                        });
                    }
                } catch (error) {
                    console.error('import.submit: area-velden uitschakelen mislukt', error);
                }
                if (importSubmit) {
                    importSubmit.disabled = true;
                    importSubmit.textContent = 'Bezig met importeren…';
                }
            });
            confirmDialog?.querySelector('[data-import-cancel]')?.addEventListener('click', () => {
                confirmDialog.close();
            });
            confirmDialog?.querySelector('[data-import-confirm]')?.addEventListener('click', () => {
                importConfirmed = true;
                confirmDialog.close();
                reviewForm?.requestSubmit(importSubmit);
            });
        })();
    </script>
@endsection
