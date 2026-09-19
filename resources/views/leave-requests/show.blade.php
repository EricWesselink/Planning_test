@extends('layouts.app')

@section('title', 'Vrij-aanvraag · Nicon Planning')

@section('content')
    <a href="{{ route('leave-requests.index') }}" class="text-sm text-nicon-muted">← Vrij-aanvragen</a>
    <h1 class="mt-2 text-2xl font-semibold">Vrij-aanvraag {{ $leaveRequest->workerName() }}</h1>
    <p class="text-sm text-nicon-muted">{{ $leaveRequest->shortPeriodLabel() }} · {{ $leaveRequest->workdayCount() }} werkdagen · {{ $leaveRequest->status->label() }}</p>

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

    <div class="mt-6 max-w-3xl border border-nicon-line bg-white p-5 space-y-3 text-sm">
        <p>Aangevraagd: {{ $leaveRequest->submitted_at?->format('d-m-Y H:i') }}</p>
        @if (filled($leaveRequest->note))
            <p>Opmerking: {{ $leaveRequest->note }}</p>
        @endif
        @if ($leaveRequest->reviewed_at)
            <p>Behandeld: {{ $leaveRequest->reviewed_at->format('d-m-Y H:i') }} door {{ $leaveRequest->reviewer?->name }}</p>
        @endif
        @if (filled($leaveRequest->rejection_reason))
            <p>Reden afwijzing: {{ $leaveRequest->rejection_reason }}</p>
        @endif
    </div>

    @if ($conflicts !== [])
        <div class="mt-6 max-w-3xl border border-nicon-orange bg-white p-5">
            <p class="font-medium">Let op: {{ $leaveRequest->workerName() }} staat in deze periode al ingepland.</p>
            <table class="mt-3 w-full text-sm">
                <thead class="text-left text-nicon-muted">
                    <tr>
                        <th class="py-1">Datum</th>
                        <th class="py-1">Project/werk</th>
                        <th class="py-1">Werkzaamheden</th>
                        <th class="py-1">Tijd</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($conflicts as $conflict)
                    <tr class="border-t border-nicon-line">
                        <td class="py-2">{{ $conflict['date_label'] }}</td>
                        <td class="py-2">{{ $conflict['project'] }}</td>
                        <td class="py-2">{{ $conflict['work'] }}</td>
                        <td class="py-2">{{ $conflict['time'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @can('review', $leaveRequest)
        <div class="mt-6 flex flex-wrap items-start gap-6">
            <form method="POST" action="{{ route('leave-requests.approve', $leaveRequest) }}">
                @csrf
                <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Goedkeuren</button>
            </form>
            <form method="POST" action="{{ route('leave-requests.reject', $leaveRequest) }}" class="max-w-md space-y-2">
                @csrf
                <label class="text-xs uppercase tracking-wide text-nicon-muted">Reden (optioneel)</label>
                <textarea name="rejection_reason" rows="3" class="w-full border border-nicon-line px-3 py-2 bg-white text-sm">{{ old('rejection_reason') }}</textarea>
                <button class="border border-nicon-danger text-nicon-danger px-5 py-3 text-sm hover:bg-nicon-danger hover:text-white">Afwijzen</button>
            </form>
        </div>
    @endcan
@endsection
