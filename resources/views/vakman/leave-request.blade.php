@extends('layouts.app')

@section('title', 'Vrij-aanvraag · Nicon Planning')

@section('content')
    <a href="{{ route('vakman.leave-requests.index') }}" class="text-sm text-nicon-muted">← Vrij aanvragen</a>
    <h1 class="mt-2 text-2xl font-semibold">Je vrij-aanvraag</h1>
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
        @if ($leaveRequest->hasAdjustedPeriod())
            <p>Oorspronkelijke aanvraag: {{ $leaveRequest->originalShortPeriodLabel() }}</p>
            <p>Afgesproken periode: {{ $leaveRequest->shortPeriodLabel() }}</p>
        @endif
        @if (filled($leaveRequest->note))
            <p>Opmerking: {{ $leaveRequest->note }}</p>
        @endif
        @if ($leaveRequest->reviewed_at && $leaveRequest->status === \App\Enums\LeaveRequestStatus::Approved)
            <p>Goedgekeurd: {{ $leaveRequest->reviewed_at->format('d-m-Y H:i') }}</p>
        @endif
        @if (filled($leaveRequest->rejection_reason))
            <p>Reden afwijzing: {{ $leaveRequest->rejection_reason }}</p>
        @endif
        @can('withdraw', $leaveRequest)
            <form method="POST" action="{{ route('vakman.leave-requests.withdraw', $leaveRequest) }}">
                @csrf
                <button class="text-sm text-nicon-danger hover:underline">Intrekken</button>
            </form>
        @endcan
    </div>

    @include('leave-requests.conversation', [
        'leaveRequest' => $leaveRequest,
        'messageAction' => route('vakman.leave-requests.messages', $leaveRequest),
        'submitLabel' => 'Versturen',
    ])
@endsection
