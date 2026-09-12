@extends('layouts.app')

@section('title', 'Opleverpunten · '.$project->name)

@section('content')
    <a href="{{ route('projects.show', $project) }}" class="text-sm text-nicon-muted">← Tekening</a>
    <div class="mt-2 flex items-end justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold">Opleverpunten</h1>
            <p class="text-sm text-nicon-muted">{{ $project->name }}</p>
        </div>
        <a href="{{ route('projects.show', $project) }}" class="bg-nicon-ink text-white px-3 py-1.5 text-sm">+ Punt op tekening</a>
    </div>

    <form method="GET" class="mt-4 flex flex-wrap gap-2 text-sm">
        <a href="{{ route('projects.snags.index', $project) }}" class="border border-nicon-line px-2 py-1 {{ empty($filters['status']) && empty($filters['worker_id']) && empty($filters['floor_id']) ? 'bg-nicon-ink text-white' : 'bg-white' }}">Alles</a>
        <a href="{{ route('projects.snags.index', ['project' => $project, 'status' => 'open']) }}" class="border border-nicon-line px-2 py-1 {{ ($filters['status'] ?? '') === 'open' ? 'bg-nicon-ink text-white' : 'bg-white' }}">Open</a>
        <a href="{{ route('projects.snags.index', ['project' => $project, 'status' => 'assigned']) }}" class="border border-nicon-line px-2 py-1 {{ ($filters['status'] ?? '') === 'assigned' ? 'bg-nicon-ink text-white' : 'bg-white' }}">Toegewezen</a>
        <a href="{{ route('projects.snags.index', ['project' => $project, 'status' => 'in_progress']) }}" class="border border-nicon-line px-2 py-1 {{ ($filters['status'] ?? '') === 'in_progress' ? 'bg-nicon-ink text-white' : 'bg-white' }}">In behandeling</a>
        <a href="{{ route('projects.snags.index', ['project' => $project, 'status' => 'reported_done']) }}" class="border border-nicon-line px-2 py-1 {{ ($filters['status'] ?? '') === 'reported_done' ? 'bg-nicon-ink text-white' : 'bg-white' }}">Gereed gemeld</a>
        <a href="{{ route('projects.snags.index', ['project' => $project, 'status' => 'closed']) }}" class="border border-nicon-line px-2 py-1 {{ ($filters['status'] ?? '') === 'closed' ? 'bg-nicon-ink text-white' : 'bg-white' }}">Afgehandeld</a>
        <select name="status" class="border border-nicon-line px-2 py-1" onchange="this.form.submit()">
            <option value="">Status</option>
            <option value="open" @selected(($filters['status'] ?? '') === 'open')>Open</option>
            <option value="assigned" @selected(($filters['status'] ?? '') === 'assigned')>Toegewezen</option>
            <option value="in_progress" @selected(($filters['status'] ?? '') === 'in_progress')>In behandeling</option>
            <option value="reported_done" @selected(($filters['status'] ?? '') === 'reported_done')>Gereed gemeld</option>
            <option value="closed" @selected(($filters['status'] ?? '') === 'closed')>Afgehandeld</option>
        </select>
        <select name="worker_id" class="border border-nicon-line px-2 py-1" onchange="this.form.submit()">
            <option value="">Alle vakmensen</option>
            @foreach ($workers as $worker)
                <option value="{{ $worker->id }}" @selected((string) ($filters['worker_id'] ?? '') === (string) $worker->id)>{{ $worker->displayName() }}</option>
            @endforeach
        </select>
        <select name="floor_id" class="border border-nicon-line px-2 py-1" onchange="this.form.submit()">
            <option value="">Alle verdiepingen</option>
            @foreach ($project->floors as $floor)
                <option value="{{ $floor->id }}" @selected((string) ($filters['floor_id'] ?? '') === (string) $floor->id)>{{ $floor->name }}</option>
            @endforeach
        </select>
    </form>

    <form method="GET" action="{{ route('projects.snags.export', $project) }}" class="mt-3 flex flex-wrap items-end gap-2 border border-nicon-line bg-white p-3 text-sm" target="_blank">
        <div class="font-medium mr-2">Opleverlijst PDF</div>
        <select name="status" class="border border-nicon-line px-2 py-1">
            <option value="">Alle punten</option>
            <option value="open_all">Alleen open</option>
            <option value="open">Open</option>
            <option value="assigned">Toegewezen</option>
            <option value="in_progress">In behandeling</option>
            <option value="reported_done">Gereed gemeld</option>
            <option value="closed">Afgehandeld</option>
        </select>
        <select name="worker_id" class="border border-nicon-line px-2 py-1">
            <option value="">Per vakman: alle</option>
            @foreach ($workers as $worker)
                <option value="{{ $worker->id }}">{{ $worker->displayName() }}</option>
            @endforeach
        </select>
        <select name="floor_id" class="border border-nicon-line px-2 py-1">
            <option value="">Per verdieping: alle</option>
            @foreach ($project->floors as $floor)
                <option value="{{ $floor->id }}">{{ $floor->name }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-1 text-xs"><input type="hidden" name="photos" value="0"><input type="checkbox" name="photos" value="1" checked> Foto’s</label>
        <label class="flex items-center gap-1 text-xs"><input type="hidden" name="drawing" value="0"><input type="checkbox" name="drawing" value="1" checked> Tekening</label>
        <button class="bg-nicon-ink text-white px-3 py-1">PDF maken</button>
    </form>

    <div class="mt-4 overflow-x-auto border border-nicon-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-nicon-ink text-white text-left">
                <tr>
                    <th class="px-3 py-2">Nr</th>
                    <th class="px-3 py-2">Foto</th>
                    <th class="px-3 py-2">Ruimte</th>
                    <th class="px-3 py-2">Omschrijving</th>
                    <th class="px-3 py-2">Vakman</th>
                    <th class="px-3 py-2">Datum</th>
                    <th class="px-3 py-2">Deadline</th>
                    <th class="px-3 py-2">Status</th>
                    @if (auth()->user()?->canUpdateSnags())
                        <th class="px-3 py-2">Publieke link</th>
                    @endif
                    @if (auth()->user()?->canDeleteSnags())
                        <th class="px-3 py-2"></th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @forelse ($snags as $snag)
                <tr class="border-t border-nicon-line hover:bg-nicon-sand">
                    <td class="px-3 py-2">
                        <a href="{{ route('projects.show', $project) }}?snag={{ $snag->id }}" class="font-medium">{{ $snag->number }}</a>
                    </td>
                    <td class="px-3 py-2">
                        @if ($snag->photos->first())
                            <a href="{{ route('projects.show', $project) }}?snag={{ $snag->id }}">
                                <img src="{{ route('projects.snags.photo', [$project, $snag, $snag->photos->first()]) }}" alt="" class="h-10 w-10 object-cover">
                            </a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $snag->area?->label() ?: '—' }}</td>
                    <td class="px-3 py-2">
                        <a href="{{ route('projects.show', $project) }}?snag={{ $snag->id }}">{{ $snag->description }}</a>
                    </td>
                    <td class="px-3 py-2">{{ $snag->assignee?->displayName() ?: '—' }}</td>
                    <td class="px-3 py-2">{{ ($snag->logged_on ?? $snag->created_at)?->format('d-m-Y') }}</td>
                    <td class="px-3 py-2">{{ $snag->due_date?->format('d-m-Y') ?: '—' }}</td>
                    <td class="px-3 py-2">
                        {{ $snag->status->boardLabel() }}
                        @if ($snag->status === \App\Enums\SnagStatus::ReportedDone)
                            @can('close', $snag)
                                <form method="POST" action="{{ route('projects.snags.approve', [$project, $snag]) }}" class="inline">
                                    @csrf
                                    <button class="text-nicon-ok text-xs">Goedkeuren</button>
                                </form>
                            @endcan
                            @can('reject', $snag)
                                <form method="POST" action="{{ route('projects.snags.reject', [$project, $snag]) }}" class="inline">
                                    @csrf
                                    <button class="text-nicon-danger text-xs">Afkeuren</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                    @if (auth()->user()?->canUpdateSnags())
                        <td class="px-3 py-2">
                            @if ($snag->publicAccessIsActive())
                                <form method="POST" action="{{ route('projects.snags.revoke', [$project, $snag]) }}" onsubmit="return confirm('Deze publieke ZZP-link intrekken? De oude link werkt daarna niet meer.')">
                                    @csrf
                                    <button class="text-nicon-danger text-xs">Intrekken</button>
                                </form>
                            @elseif ($snag->public_token_revoked_at)
                                <span class="text-xs text-nicon-muted">Ingetrokken</span>
                            @else
                                <span class="text-xs text-nicon-muted">Verlopen</span>
                            @endif
                        </td>
                    @endif
                    @if (auth()->user()?->canDeleteSnags())
                        <td class="px-3 py-2">
                            <form method="POST" action="{{ route('projects.snags.destroy', [$project, $snag]) }}" onsubmit="return confirm('Dit opleverpunt definitief verwijderen?')">
                                @csrf
                                @method('DELETE')
                                <button class="text-nicon-danger text-xs">Verwijderen</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ 8 + (auth()->user()?->canUpdateSnags() ? 1 : 0) + (auth()->user()?->canDeleteSnags() ? 1 : 0) }}" class="px-3 py-6 text-nicon-muted">Nog geen opleverpunten. Plaats er een op de tekening.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
