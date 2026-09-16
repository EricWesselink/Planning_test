@extends('layouts.app')

@section('title', $calculation->name.' · Totalen')

@section('content')
    <a href="{{ route('calculations.index') }}" class="text-sm text-nicon-muted">← Calculatie</a>
    <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ $calculation->name }}</h1>
            <p class="text-sm text-nicon-muted">Som meegenomen ruimtes: {{ \App\Support\Format::qty($squareMeters, 2) }} m²</p>
        </div>
        @include('calculations.partials.excel-export')
    </div>
    @include('calculations.partials.tabs', ['calculation' => $calculation, 'tab' => 'totals'])

    <div class="mt-4 overflow-x-auto border border-nicon-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-nicon-ink text-left text-white">
                <tr>
                    <th class="px-3 py-2 font-medium">Code</th>
                    <th class="px-3 py-2 font-medium">Product</th>
                    <th class="px-3 py-2 text-right font-medium">Totaal</th>
                    <th class="px-3 py-2 font-medium">Eenheid</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($totals as $total)
                <tr class="border-t border-nicon-line">
                    <td class="px-3 py-2">{{ $total['product_code'] ?: '—' }}</td>
                    <td class="px-3 py-2">{{ $total['product'] ?: '—' }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ \App\Support\Format::qty($total['quantity'], 2) }}</td>
                    <td class="px-3 py-2">{{ $total['unit_label'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-3 py-6 text-nicon-muted">Nog geen hoeveelheden om op te tellen.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
