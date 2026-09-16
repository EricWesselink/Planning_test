@php
    $source = \App\Enums\QuantitySource::tryFrom($oldLine['source'] ?? $line->source->value) ?? $line->source;
@endphp
<input type="hidden" name="lines[{{ $index }}][id]" value="{{ $line->id }}">
<input type="hidden" name="lines[{{ $index }}][unit]" value="{{ $unit->value }}">
<input type="hidden" name="lines[{{ $index }}][source]" value="{{ $source->value }}">
<input type="hidden" name="lines[{{ $index }}][note]" value="{{ $oldLine['note'] ?? $line->note }}">
