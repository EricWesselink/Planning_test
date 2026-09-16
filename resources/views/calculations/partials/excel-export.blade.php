@if ($roomCount > 0)
    <div class="flex flex-col items-end gap-1">
        <a href="{{ route('calculations.excel', $calculation) }}" class="bg-nicon-orange px-4 py-2 text-sm text-white">Exporteren naar Excel</a>
        @unless ($readyForExcel)
            <span class="text-xs text-nicon-muted">{{ $reviewCount }} nog controleren; die regels gaan ook mee.</span>
        @endunless
    </div>
@endif
