@extends('layouts.app')

@section('title', 'Projecten · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold">Projecten</h1>
            <p class="text-sm text-nicon-muted">Actieve werken. Afgeronde of testdata zet je in het archief.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" action="{{ route('projects.index') }}" class="flex min-w-[16rem] flex-1 items-center gap-2 sm:max-w-lg">
                <label class="sr-only" for="project-search">Zoek op projectnummer of werk</label>
                <input
                    id="project-search"
                    type="search"
                    name="q"
                    value="{{ $search }}"
                    placeholder="Zoek op projectnr. of werk"
                    class="min-w-0 flex-1 border border-nicon-line bg-white px-3 py-2 text-sm"
                >
                <label class="sr-only" for="project-week">Startweek</label>
                <input
                    id="project-week"
                    type="number"
                    name="week"
                    min="1"
                    max="53"
                    value="{{ $week }}"
                    placeholder="Wk"
                    title="Startweek"
                    class="w-16 shrink-0 border border-nicon-line bg-white px-2 py-2 text-sm"
                >
                @if ($week !== null && $weekYear !== null)
                    <input type="hidden" name="year" value="{{ $weekYear }}">
                @endif
                <button type="submit" class="border border-nicon-line bg-white px-4 py-2 text-sm">Zoeken</button>
                @if ($search !== '' || $week !== null)
                    <a href="{{ route('projects.index') }}" class="whitespace-nowrap text-sm text-nicon-muted">Wis</a>
                @endif
            </form>
            <a
                href="{{ route('projects.pdf', array_filter(['q' => $search !== '' ? $search : null, 'week' => $week, 'year' => $week !== null ? $weekYear : null])) }}"
                class="border border-nicon-line bg-white px-4 py-2 text-sm"
            >PDF projectenoverzicht</a>
            @unless (auth()->user()?->isVakman())
                <a href="{{ route('projects.archived') }}" class="border border-nicon-line bg-white px-4 py-2 text-sm">Archief</a>
            @endunless
            @can('create', \App\Models\Project::class)
                <a href="{{ route('projects.small.create') }}" class="border border-nicon-line bg-white px-4 py-2 text-sm">Klein werk</a>
                <a href="{{ route('projects.winkel.create') }}" class="border border-nicon-line bg-white px-4 py-2 text-sm">Nieuw Winkelwerk</a>
                <a href="{{ route('projects.create') }}" class="bg-nicon-orange text-white px-4 py-2 text-sm">Nieuw project</a>
            @endcan
        </div>
    </div>
    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
    <div class="mt-6 overflow-x-auto border border-nicon-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-nicon-ink text-white text-left">
                <tr>
                    <th class="px-2 py-1.5">Projectnr.</th>
                    <th class="px-2 py-1.5">Werk</th>
                    <th class="px-2 py-1.5">Werkadres</th>
                    <th class="px-2 py-1.5">Start werk</th>
                    <th class="px-2 py-1.5">Klaar werk</th>
                    <th class="px-2 py-1.5">Status</th>
                    <th class="px-2 py-1.5">Voortgang</th>
                    <th class="px-2 py-1.5">Wie</th>
                    <th class="px-2 py-1.5"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($projects as $project)
                @php
                    $head = $project->headlineWorkItem();
                    $formId = 'plan-'.$project->id;
                    $editing = (int) old('planning_project_id') === (int) $project->id;
                @endphp
                <tr class="border-t border-nicon-line">
                    <td class="px-2 py-1.5">
                        @can('update', $project)
                            <form id="{{ $formId }}" method="POST" action="{{ route('projects.update', $project) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="planning_project_id" value="{{ $project->id }}">
                            </form>
                        @endcan
                        <a class="text-nicon-orange-dark font-medium" href="{{ route('projects.show', $project) }}">
                            @if ($project->isSmallWork())
                                {{ $project->kind?->badge() }}
                            @elseif ($project->isWinkel())
                                WINKEL
                            @else
                                {{ $project->workCode() ?: $project->workNumber() }}
                            @endif
                        </a>
                    </td>
                    <td class="px-2 py-1.5">
                        @if ($project->isWinkel())
                            <div>{{ $project->displayTitle() }}</div>
                            @if ($project->shopWorkLine())
                                <div class="text-xs text-nicon-muted">{{ $project->shopWorkLine() }}</div>
                            @endif
                        @elseif ($project->isSmallWork())
                            <div>{{ $project->displayTitle() }}</div>
                        @else
                            @if ($project->workCode() && $project->workNumber() !== '')
                                <div class="text-xs text-nicon-muted">Werk {{ $project->workNumber() }}</div>
                            @endif
                            <div>{{ $project->displayTitle() }}</div>
                        @endif
                    </td>
                    <td class="px-2 py-1.5">
                        @can('update', $project)
                            <div class="flex min-w-[12rem] flex-col gap-1">
                                <input
                                    id="index-{{ $project->id }}-address"
                                    form="{{ $formId }}"
                                    name="address"
                                    value="{{ $editing ? old('address', $project->address) : $project->address }}"
                                    aria-label="Straat"
                                    title="Straat"
                                    placeholder="Straat 12"
                                    class="border border-nicon-line bg-white px-1.5 py-1 text-sm leading-tight"
                                >
                                <div class="flex gap-1">
                                    <input
                                        id="index-{{ $project->id }}-postal"
                                        form="{{ $formId }}"
                                        name="postal_code"
                                        value="{{ $editing ? old('postal_code', $project->postal_code) : $project->postal_code }}"
                                        aria-label="Postcode"
                                        title="Postcode"
                                        placeholder="1234 AB"
                                        class="w-[6.5rem] shrink-0 border border-nicon-line bg-white px-1.5 py-1 text-sm leading-tight"
                                    >
                                    <input
                                        id="index-{{ $project->id }}-city"
                                        form="{{ $formId }}"
                                        name="city"
                                        value="{{ $editing ? old('city', $project->city) : $project->city }}"
                                        aria-label="Plaats"
                                        title="Plaats"
                                        placeholder="Plaats"
                                        class="min-w-0 flex-1 border border-nicon-line bg-white px-1.5 py-1 text-sm leading-tight"
                                    >
                                </div>
                            </div>
                        @else
                            {{ $project->nawLine() ?? '—' }}
                        @endcan
                    </td>
                    <td class="px-2 py-1.5">
                        @can('update', $project)
                            @include('projects.partials.planning-weeks', [
                                'idPrefix' => 'index-'.$project->id.'-start-',
                                'side' => 'start',
                                'table' => true,
                                'formId' => $formId,
                                'useOld' => $editing,
                                'startYear' => $project->planningStartYear(),
                                'startWeek' => $project->planningStartWeek(),
                                'startDate' => $project->planned_start_date?->toDateString(),
                            ])
                        @else
                            <div>{{ \App\Support\PlanningWeek::label($project->planned_start_date) ?? '—' }}</div>
                            @if ($project->planned_start_date)
                                <div class="text-xs text-nicon-muted">{{ $project->planned_start_date->format('d-m-Y') }}</div>
                            @endif
                        @endcan
                    </td>
                    <td class="px-2 py-1.5">
                        @can('update', $project)
                            <div class="flex items-center gap-1">
                                @include('projects.partials.planning-weeks', [
                                    'idPrefix' => 'index-'.$project->id.'-klaar-',
                                    'side' => 'klaar',
                                    'table' => true,
                                    'formId' => $formId,
                                    'useOld' => $editing,
                                    'klaarYear' => $project->planningEndYear(),
                                    'klaarWeek' => $project->planningEndWeek(),
                                    'klaarDate' => $project->planned_end_date?->toDateString(),
                                ])
                                <button type="submit" form="{{ $formId }}" class="shrink-0 bg-nicon-ink text-white px-2 py-1 text-xs">Opslaan</button>
                            </div>
                        @else
                            <div>{{ \App\Support\PlanningWeek::label($project->planned_end_date) ?? '—' }}</div>
                            @if ($project->planned_end_date)
                                <div class="text-xs text-nicon-muted">{{ $project->planned_end_date->format('d-m-Y') }}</div>
                            @endif
                        @endcan
                    </td>
                    <td class="px-2 py-1.5">{{ $project->status->label() }}</td>
                    <td class="px-2 py-1.5">
                        @if ($project->isWinkel())
                            {{ $project->shopWorkLine() }}
                        @elseif ($project->isSmallWork())
                            {{ \App\Support\PlanningHours::hoursLabel((float) ($project->workItems->first()?->begrote_uren ?? 0)) }}
                        @elseif ($head)
                            {{ \App\Support\Format::qty($head->completedQuantity()) }} / {{ \App\Support\Format::qty($head->ordered_quantity) }} {{ $head->unit->label() }}
                        @endif
                    </td>
                    <td class="px-2 py-1.5">{{ $project->assignments->map(function ($assignment) {
                        $team = $assignment->worker?->name;
                        $present = $assignment->presentNamesLabel();

                        return $present ? $team.' ('.$present.')' : $team;
                    })->unique()->join(', ') }}</td>
                    <td class="px-2 py-1.5 text-right whitespace-nowrap">
                        <div class="flex justify-end gap-3">
                            @can('archive', $project)
                                <form method="POST" action="{{ route('projects.archive', $project) }}" onsubmit="return confirm({{ json_encode($project->name.' verdwijnt uit planning en projecten. Je kunt het later terugzetten vanuit het archief.') }})">
                                    @csrf
                                    <button class="text-nicon-muted hover:text-nicon-ink">Archiveren</button>
                                </form>
                            @endcan
                            @can('delete', $project)
                                <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm({{ json_encode($project->name.' wordt definitief verwijderd. Ruimtes, planning, tekeningen en voortgang zijn dan weg. Dit kan niet ongedaan worden gemaakt.') }})">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-nicon-danger hover:text-nicon-ink">Verwijderen</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-3 py-6 text-sm text-nicon-muted">
                        @if ($search !== '' || $week !== null)
                            Geen projecten voor deze selectie.
                        @else
                            Geen actieve projecten.
                        @endif
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
