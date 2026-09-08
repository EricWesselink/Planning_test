@extends('layouts.app')

@section('title', 'Projecten · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold">Projecten</h1>
            <p class="text-sm text-nicon-muted">Actieve werken. Afgeronde of testdata zet je in het archief.</p>
        </div>
        <div class="flex gap-2">
            @unless (auth()->user()?->isVakman())
                <a href="{{ route('projects.archived') }}" class="border border-nicon-line bg-white px-4 py-2 text-sm">Archief</a>
            @endunless
            @can('create', \App\Models\Project::class)
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
                    <th class="px-2 py-1.5">Plaats</th>
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
                            @if ($project->isWinkel())
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
                        @else
                            @if ($project->workCode() && $project->workNumber() !== '')
                                <div class="text-xs text-nicon-muted">Werk {{ $project->workNumber() }}</div>
                            @endif
                            <div>{{ $project->displayTitle() }}</div>
                        @endif
                    </td>
                    <td class="px-2 py-1.5">{{ $project->city }}</td>
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
                    <td colspan="9" class="px-3 py-6 text-sm text-nicon-muted">Geen actieve projecten.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
