@extends('layouts.app')

@section('title', $type->label().' · Nicon Planning')

@section('content')
    <a href="{{ route('production.index', array_filter(['worker_id' => $worker->id, 'project_id' => $project->id, 'from' => $filters['from'], 'to' => $filters['to']])) }}" class="text-sm text-nicon-muted">← Productie</a>
    <div class="mt-2 flex items-end justify-between gap-4 flex-wrap">
        <div>
            <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Facturatie</div>
            <h1 class="text-2xl font-semibold">{{ $type->label() }}</h1>
            <p class="text-sm text-nicon-muted">
                {{ $worker->displayName() }}
                · {{ $project->project_number }} {{ $project->name }}
                @if ($project->city) · {{ $project->city }} @endif
            </p>
        </div>
        <div class="flex gap-2 text-sm">
            <a class="border border-nicon-line bg-white px-3 py-2 {{ $type === \App\Enums\VoucherType::Opdracht ? 'bg-nicon-sand' : '' }}" href="{{ route('vouchers.create', ['worker_id' => $worker->id, 'project_id' => $project->id, 'type' => 'opdracht', 'from' => $filters['from'], 'to' => $filters['to']]) }}">Opdrachtbon</a>
        </div>
    </div>

    @if (session('status'))
        <p class="mt-4 text-sm text-nicon-ok">{{ session('status') }}</p>
    @endif
    @if (session('warning'))
        <p class="mt-4 text-sm text-nicon-danger">{{ session('warning') }}</p>
    @endif
    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    @if (! empty($warnings))
        @foreach ($warnings as $warning)
            <p class="mt-4 text-sm text-nicon-danger">{{ $warning }}</p>
        @endforeach
    @endif

    @if ($type === \App\Enums\VoucherType::Opdracht)
        <p class="mt-4 text-sm text-nicon-muted">Leg vast welke werkzaamheden, hoeveelheden en prijzen zijn opgedragen. De hoeveelheid is het maximum; op elke latere bon vul je zelf in wat deze keer mag, tot dat maximum op is. Per werkzaamheid kies je één prijs (m²/m¹/st × prijs of een vaste afgesproken prijs). Daaronder staan de ruimtes met hun m² ter controle.</p>
    @elseif ($opdracht)
        <p class="mt-4 text-sm text-nicon-muted">Alleen onderdelen van opdrachtbon {{ $opdracht->number }}. Vul in wat hij deze keer indient; je kunt nooit meer opgeven dan er nog openstaat.</p>
    @endif

    @isset($billing)
        @include('production._billing', ['billing' => $billing])
    @endisset

    @if ($existing->isNotEmpty())
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            @foreach ($existing as $voucher)
                <span class="inline-flex items-center gap-1">
                    <a class="border border-nicon-line bg-white px-2 py-1" href="{{ route('vouchers.show', $voucher) }}">
                        {{ $voucher->type->label() }} {{ $voucher->number }} · {{ \App\Support\Format::money($voucher->total_amount) }}
                    </a>
                    <a class="text-nicon-muted" href="{{ route('vouchers.pdf', $voucher) }}">Download PDF</a>
                    <a class="text-nicon-muted" href="{{ route('vouchers.edit', $voucher) }}">aanpassen</a>
                </span>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('vouchers.store') }}" class="mt-6" id="voucher-form">
        @csrf
        <input type="hidden" name="worker_id" value="{{ $worker->id }}">
        <input type="hidden" name="project_id" value="{{ $project->id }}">
        <input type="hidden" name="type" value="{{ $type->value }}">
        @if ($filters['from'])
            <input type="hidden" name="from" value="{{ $filters['from'] }}">
        @endif
        @if ($filters['to'])
            <input type="hidden" name="to" value="{{ $filters['to'] }}">
        @endif

        @include('vouchers._lines', ['formLines' => $lines, 'canAddLines' => $canAddLines ?? ($type === \App\Enums\VoucherType::Opdracht)])

        <div class="mt-4 max-w-xl">
            <label class="text-xs uppercase tracking-wide text-nicon-muted" for="notes">Toelichting</label>
            <textarea name="notes" id="notes" rows="2" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">{{ old('notes') }}</textarea>
        </div>

        <div class="mt-4">
            <button class="bg-nicon-orange text-white px-5 py-3 font-medium">{{ $type->label() }} vastleggen</button>
        </div>
    </form>
@endsection

@include('vouchers._script')
