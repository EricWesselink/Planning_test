@extends('layouts.app')

@section('title', $voucher->type->label().' aanpassen · Nicon Planning')

@section('content')
    <a href="{{ route('vouchers.show', $voucher) }}" class="text-sm text-nicon-muted">← {{ $voucher->type->label() }} {{ $voucher->number }}</a>
    <div class="mt-2">
        <div class="text-[11px] uppercase tracking-[0.2em] text-nicon-orange">Facturatie</div>
        <h1 class="text-2xl font-semibold">{{ $voucher->type->label() }} {{ $voucher->number }} aanpassen</h1>
        <p class="text-sm text-nicon-muted">
            {{ $voucher->worker?->displayName() }}
            · {{ $voucher->project?->project_number }} {{ $voucher->project?->name }}
        </p>
    </div>

    @if ($errors->any())
        <ul class="mt-4 text-sm text-nicon-danger list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <p class="mt-4 text-sm text-nicon-muted">
        @if ($voucher->type === \App\Enums\VoucherType::Opdracht)
            Pas onderdelen, hoeveelheden, prijssoort en prijzen aan, of voeg een extra onderdeel toe. Je kunt ook overschakelen naar uren × uurtarief; de ruimtes blijven ter controle met hun m² staan.
        @else
            Pas aan wat deze keer wordt ingediend. Je kunt niet meer opgeven dan er nog openstaat op de opdrachtbon.
        @endif
    </p>

    <form method="POST" action="{{ route('vouchers.update', $voucher) }}" class="mt-6" id="voucher-form" data-hourly-rate="{{ $voucher->worker?->hourlyRate()?->unit_price }}">
        @csrf
        @method('PATCH')

        @include('vouchers._lines', ['formLines' => $formLines, 'canAddLines' => $voucher->type === \App\Enums\VoucherType::Opdracht])

        <div class="mt-4">
            @include('vouchers._worked_period', [
                'workedDates' => $voucher->worked_dates,
            ])
        </div>

        <div class="mt-4 max-w-xl">
            <label class="text-xs uppercase tracking-wide text-nicon-muted" for="notes">Toelichting</label>
            <textarea name="notes" id="notes" rows="2" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">{{ old('notes', $voucher->notes) }}</textarea>
        </div>

        <div class="mt-4 flex gap-2">
            <button class="bg-nicon-orange text-white px-5 py-3 font-medium">Opslaan</button>
            <a class="border border-nicon-line bg-white px-5 py-3 text-sm" href="{{ route('vouchers.show', $voucher) }}">Annuleren</a>
        </div>
    </form>
@endsection

@include('vouchers._script')
