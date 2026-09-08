@extends('layouts.app')

@section('title', 'Archief · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <a href="{{ route('projects.index') }}" class="text-xs text-nicon-muted">← Projecten</a>
            <h1 class="text-2xl font-semibold">Archief</h1>
            <p class="text-sm text-nicon-muted">Deze werken staan niet meer in planning of op de projectenlijst.</p>
        </div>
    </div>
    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    <div class="mt-6 overflow-x-auto border border-nicon-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-nicon-ink text-white text-left">
                <tr>
                    <th class="px-3 py-2">Projectnr.</th>
                    <th class="px-3 py-2">Werk</th>
                    <th class="px-3 py-2">Plaats</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Voortgang</th>
                    <th class="px-3 py-2">Gearchiveerd</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($projects as $project)
                @php $head = $project->headlineWorkItem(); @endphp
                <tr class="border-t border-nicon-line">
                    <td class="px-3 py-2">
                        <a class="text-nicon-orange-dark font-medium" href="{{ route('projects.show', $project) }}">
                            @if ($project->isWinkel())
                                WINKEL
                            @else
                                {{ $project->workCode() ?: $project->workNumber() }}
                            @endif
                        </a>
                    </td>
                    <td class="px-3 py-2">
                        @if ($project->isWinkel())
                            <div>{{ $project->displayTitle() }}</div>
                            @if ($project->shopWorkLine())
                                <div class="text-nicon-muted">{{ $project->shopWorkLine() }}</div>
                            @endif
                        @else
                            @if ($project->workCode() && $project->workNumber() !== '')
                                <div>Werk {{ $project->workNumber() }}</div>
                            @endif
                            <div>{{ $project->displayTitle() }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $project->city }}</td>
                    <td class="px-3 py-2">{{ $project->status->label() }}</td>
                    <td class="px-3 py-2">
                        @if ($head)
                            {{ \App\Support\Format::qty($head->completedQuantity()) }} / {{ \App\Support\Format::qty($head->ordered_quantity) }} {{ $head->unit->label() }}
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $project->archived_at?->format('d-m-Y') }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        <div class="flex justify-end gap-3">
                            @can('restore', $project)
                                <form method="POST" action="{{ route('projects.restore', $project) }}">
                                    @csrf
                                    <button class="text-nicon-orange-dark hover:text-nicon-ink">Terugzetten</button>
                                </form>
                            @endcan
                            @can('delete', $project)
                                <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm({{ json_encode($project->name.' wordt definitief verwijderd. Dit kan niet ongedaan worden gemaakt.') }})">
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
                    <td colspan="7" class="px-3 py-6 text-sm text-nicon-muted">Nog geen werken in het archief.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
