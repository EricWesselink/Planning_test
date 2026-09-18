@php
    $row = $row ?? [];
    $units = $units ?? \App\Services\MeasurementFormService::units();
    $canEdit = $canEdit ?? true;
    $index = $index ?? '__INDEX__';
    $floorProductNames = $floorProductNames ?? [];
    $locations = $locations ?? \App\Enums\MeasurementMaterialLocation::cases();
    $product = (string) ($row['product'] ?? '');
    $productOptions = $floorProductNames;
    if ($product !== '' && ! in_array($product, $productOptions, true)) {
        $productOptions = [$product, ...$productOptions];
    }
    $available = ! empty($row['available_on_site']);
    $location = (string) ($row['available_location'] ?? '');
@endphp
<tr data-measurement-row>
    <td class="border border-nicon-line p-0"><input name="measurement[rows][{{ $index }}][room]" value="{{ $row['room'] ?? '' }}" class="w-full min-w-20 px-1 py-1" @disabled(! $canEdit)></td>
    <td class="border border-nicon-line p-0">
        <select name="measurement[rows][{{ $index }}][product]" data-measurement-product class="w-full min-w-28 bg-white px-1 py-1" @disabled(! $canEdit)>
            <option value=""></option>
            @foreach ($productOptions as $name)
                <option value="{{ $name }}" @selected($product === $name)>{{ $name }}</option>
            @endforeach
        </select>
    </td>
    <td class="border border-nicon-line p-0"><input name="measurement[rows][{{ $index }}][brand]" value="{{ $row['brand'] ?? '' }}" class="w-full min-w-16 px-1 py-1" @disabled(! $canEdit)></td>
    <td class="border border-nicon-line p-0"><input name="measurement[rows][{{ $index }}][type]" value="{{ $row['type'] ?? '' }}" class="w-full min-w-16 px-1 py-1" @disabled(! $canEdit)></td>
    <td class="border border-nicon-line p-0"><input name="measurement[rows][{{ $index }}][color_number]" value="{{ $row['color_number'] ?? '' }}" class="w-full min-w-14 px-1 py-1" @disabled(! $canEdit)></td>
    <td class="border border-nicon-line p-0">
        <div class="flex min-w-28 gap-1 p-0.5">
            <input name="measurement[rows][{{ $index }}][quantity]" value="{{ $row['quantity'] ?? '' }}" inputmode="decimal" data-measurement-quantity class="w-14 px-1 py-1" @disabled(! $canEdit)>
            <select name="measurement[rows][{{ $index }}][unit]" data-measurement-unit class="min-w-12 bg-white px-1 py-1" @disabled(! $canEdit)>
                <option value=""></option>
                @foreach ($units as $unit)
                    <option value="{{ $unit->value }}" @selected(($row['unit'] ?? '') === $unit->value)>{{ $unit->label() }}</option>
                @endforeach
            </select>
        </div>
    </td>
    <td class="border border-nicon-line p-0"><input name="measurement[rows][{{ $index }}][underlay]" value="{{ $row['underlay'] ?? '' }}" class="w-full min-w-16 px-1 py-1" @disabled(! $canEdit)></td>
    <td class="border border-nicon-line p-0"><input name="measurement[rows][{{ $index }}][skirting]" value="{{ $row['skirting'] ?? '' }}" class="w-full min-w-16 px-1 py-1" @disabled(! $canEdit)></td>
    <td class="border border-nicon-line p-0"><input name="measurement[rows][{{ $index }}][steps]" value="{{ $row['steps'] ?? '' }}" class="w-full min-w-16 px-1 py-1" @disabled(! $canEdit)></td>
    <td class="border border-nicon-line p-0"><input name="measurement[rows][{{ $index }}][profile]" value="{{ $row['profile'] ?? '' }}" class="w-full min-w-16 px-1 py-1" @disabled(! $canEdit)></td>
    <td class="border border-nicon-line p-0.5">
        <div class="flex min-w-36 items-center gap-1">
            <input type="hidden" name="measurement[rows][{{ $index }}][available_on_site]" value="0" @disabled(! $canEdit)>
            <select data-measurement-available name="measurement[rows][{{ $index }}][available_on_site]" class="w-14 shrink-0 bg-white px-1 py-1" @disabled(! $canEdit)>
                <option value="0" @selected(! $available)>Nee</option>
                <option value="1" @selected($available)>Ja</option>
            </select>
            <select
                data-measurement-location
                name="measurement[rows][{{ $index }}][available_location]"
                class="min-w-16 bg-white px-1 py-1{{ $available ? '' : ' hidden' }}"
                @disabled(! $canEdit || ! $available)
                @if (! $available) hidden @endif
            >
                <option value="">Waar</option>
                @foreach ($locations as $place)
                    <option value="{{ $place->value }}" @selected($location === $place->value)>{{ $place->label() }}</option>
                @endforeach
            </select>
        </div>
    </td>
    <td class="border border-nicon-line px-1 py-1 text-center">
        @if ($canEdit)
            <button type="button" class="text-nicon-danger" data-measurement-remove aria-label="Regel verwijderen">×</button>
        @endif
    </td>
</tr>
