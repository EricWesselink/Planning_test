@php
    $source = \App\Enums\QuantitySource::tryFrom($oldLine['source'] ?? $line->source->value) ?? $line->source;
@endphp
<input type="hidden" name="lines[{{ $index }}][id]" value="{{ $line->id }}">
<input type="hidden" name="lines[{{ $index }}][unit]" value="{{ $unit->value }}">
<input type="hidden" name="lines[{{ $index }}][source]" value="{{ $source->value }}">
<input type="hidden" name="lines[{{ $index }}][note]" value="{{ $oldLine['note'] ?? $line->note }}">
@if (! empty($skipPlinth))
    <input type="hidden" name="lines[{{ $index }}][plinth_not_applicable]" value="0">
    <input type="checkbox" name="lines[{{ $index }}][plinth_not_applicable]" value="1" id="skip-plinth-{{ $line->id }}" class="sr-only" data-skip-plinth-early data-line-id="{{ $line->id }}" @checked(filter_var($oldLine['plinth_not_applicable'] ?? ($plinthNotApplicable ?? false), FILTER_VALIDATE_BOOLEAN))>
@endif
