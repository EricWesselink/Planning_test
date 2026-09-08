@php
    $billing = $billing ?? null;
@endphp
@if ($billing && ($billing['opdracht'] || $billing['invoiced_amount'] > 0))
    <div class="px-4 py-3 text-sm border-t border-nicon-line bg-white">
        <div class="flex flex-wrap gap-x-6 gap-y-1">
            @if ($billing['opdracht'])
                <div>
                    <span class="text-xs uppercase tracking-wide text-nicon-muted">Opdrachtbon</span>
                    <div>
                        {{ $billing['opdracht']->number }}
                        · {{ \App\Support\Format::money($billing['opdracht_amount']) }}
                        @if ($billing['opdracht_m2'] > 0)
                            · {{ \App\Support\Format::qty($billing['opdracht_m2'], 2) }} m²
                        @endif
                    </div>
                </div>
            @endif
            <div>
                <span class="text-xs uppercase tracking-wide text-nicon-muted">Gefactureerd</span>
                <div>
                    {{ \App\Support\Format::money($billing['invoiced_amount']) }}
                    @if ($billing['invoiced_m2'] > 0 || $billing['opdracht'])
                        · {{ \App\Support\Format::qty($billing['invoiced_m2'], 2) }} m²
                    @endif
                </div>
            </div>
            @if ($billing['opdracht'])
                @if ($billing['fully_settled'])
                    <div>
                        <span class="text-xs uppercase tracking-wide text-nicon-muted">Status</span>
                        <div class="text-nicon-ok">Volledig afgerekend</div>
                    </div>
                @else
                    <div>
                        <span class="text-xs uppercase tracking-wide text-nicon-muted">Nog open</span>
                        <div>
                            {{ \App\Support\Format::money($billing['remaining_amount']) }}
                            @if ($billing['opdracht_m2'] > 0)
                                · {{ \App\Support\Format::qty($billing['remaining_m2'], 2) }} m²
                            @endif
                        </div>
                    </div>
                @endif
            @endif
        </div>
        @foreach ($billing['warnings'] as $warning)
            <p class="mt-2 text-sm text-nicon-danger">{{ $warning }}</p>
        @endforeach
    </div>
@endif
