@php
    $name = $line['name'] ?? '';
    $quantity = $line['quantity'] ?? '';
    $completed = $line['completed'] ?? '';
    $placeholder = $placeholder ?? 'Marmoleum, PVC, plinten…';
    $rowPlaceholder = $name === 'Materiaal' ? 'Marmoleum, PVC…' : $placeholder;
@endphp
<tr class="align-top" data-extra-line>
    <td class="pr-2 pb-2">
        <input
            name="lines[{{ $index }}][name]"
            value="{{ $name }}"
            class="w-full border border-nicon-line px-3 py-2"
            placeholder="{{ $rowPlaceholder }}"
            @disabled(! $canUpdate)
        >
    </td>
    <td class="pr-2 pb-2">
        <input
            name="lines[{{ $index }}][quantity]"
            value="{{ $quantity }}"
            inputmode="decimal"
            class="w-full border border-nicon-line px-3 py-2"
            placeholder="30"
            @disabled(! $canUpdate)
        >
    </td>
    @if ($showCompleted)
        <td class="pr-2 pb-2">
            <input
                name="lines[{{ $index }}][completed]"
                value="{{ $completed }}"
                inputmode="decimal"
                class="w-full border border-nicon-line px-3 py-2"
                placeholder="30"
                @disabled(! $canUpdate)
            >
        </td>
    @endif
    <td class="pb-2 pt-2">
        @if ($canUpdate)
            <button type="button" class="text-xs text-nicon-muted hover:text-nicon-danger" data-extra-lines-remove>✕</button>
        @endif
    </td>
</tr>
