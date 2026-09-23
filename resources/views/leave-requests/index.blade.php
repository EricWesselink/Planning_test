@extends('layouts.app')

@section('title', 'Vrij-aanvragen · Nicon Planning')

@section('content')
    <div class="flex items-end justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold">Vrij-aanvragen{{ $pendingCount > 0 ? ' ('.$pendingCount.')' : '' }}</h1>
            <p class="text-sm text-nicon-muted">Aanvragen van eigen vakmannen. Goedkeuren zet de periode als afwezig in de bestaande planning.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif

    <div class="mt-6 overflow-x-auto border border-nicon-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-nicon-ink text-white text-left">
                <tr>
                    <th class="px-3 py-2">Vakman</th>
                    <th class="px-3 py-2">Periode</th>
                    <th class="px-3 py-2">Werkdagen</th>
                    <th class="px-3 py-2">Opmerking</th>
                    <th class="px-3 py-2">Aangevraagd</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Behandeld</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($requests as $leaveRequest)
                <tr class="border-t border-nicon-line align-top">
                    <td class="px-3 py-2">{{ $leaveRequest->workerName() }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $leaveRequest->shortPeriodLabel() }}</td>
                    <td class="px-3 py-2">{{ $leaveRequest->workdayCount() }}</td>
                    <td class="px-3 py-2">{{ $leaveRequest->note ?: '—' }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ $leaveRequest->submitted_at?->format('d-m-Y H:i') }}</td>
                    <td class="px-3 py-2"><span class="nicon-status nicon-status--{{ $leaveRequest->status->value }}">{{ $leaveRequest->status->label() }}</span></td>
                    <td class="px-3 py-2">
                        @if ($leaveRequest->reviewed_at)
                            <div>{{ $leaveRequest->reviewed_at->format('d-m-Y H:i') }}</div>
                            <div class="text-xs text-nicon-muted">{{ $leaveRequest->reviewer?->name }}</div>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <div class="flex flex-col items-end gap-1">
                            <a class="text-sm text-nicon-orange-dark hover:underline" href="{{ route('leave-requests.show', $leaveRequest) }}">Bekijken</a>
                            @can('delete', $leaveRequest)
                                <form method="POST" action="{{ route('leave-requests.destroy', $leaveRequest) }}" onsubmit="return confirm({{ json_encode('Deze vrij-aanvraag van '.$leaveRequest->workerName().' verwijderen?') }})">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-sm text-nicon-danger hover:underline">Verwijderen</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-3 py-4 text-nicon-muted">Nog geen vrij-aanvragen.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
