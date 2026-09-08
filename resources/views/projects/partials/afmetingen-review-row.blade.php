@php
    $label = old('rooms.'.$index.'.label', $row['label'] ?? '');
    $netto = old('rooms.'.$index.'.netto', $row['netto'] ?? '');
    $bruto = old('rooms.'.$index.'.bruto', $row['bruto'] ?? '');
    $rowWorkType = old('rooms.'.$index.'.work_type', $row['work_type'] ?? '');
    $status = old('rooms.'.$index.'.status', $row['status'] ?? 'ok');
    $options = $workTypeOptions ?? ['Raambekleding', 'Zonwering', 'PVC', 'Linoleum'];
    if ($rowWorkType !== '' && ! in_array($rowWorkType, $options, true)) {
        $options[] = $rowWorkType;
    }
    $uncertain = $status === 'controleren';
@endphp
<tr class="border-t border-nicon-line {{ $uncertain ? 'bg-amber-50' : '' }}" data-room-row data-room-index="{{ $index }}">
    <td class="px-2 py-1.5">
        <input name="rooms[{{ $index }}][label]" value="{{ $label }}" class="w-full min-w-28 border border-nicon-line px-2 py-1.5" placeholder="Ruimte 1">
    </td>
    <td class="px-2 py-1.5">
        <input name="rooms[{{ $index }}][netto]" value="{{ $netto }}" inputmode="decimal" class="w-24 border border-nicon-line px-2 py-1.5 text-right" placeholder="0" data-netto-input>
    </td>
    <td class="px-2 py-1.5">
        <input name="rooms[{{ $index }}][bruto]" value="{{ $bruto }}" inputmode="decimal" class="w-24 border border-nicon-line px-2 py-1.5 text-right" placeholder="—">
    </td>
    <td class="px-2 py-1.5">
        <select name="rooms[{{ $index }}][work_type]" class="w-full min-w-36 border border-nicon-line bg-white px-2 py-1.5" data-row-work-type>
            <option value="">— kies —</option>
            @foreach ($options as $option)
                <option value="{{ $option }}" @selected($rowWorkType === $option)>{{ $option }}</option>
            @endforeach
        </select>
    </td>
    <td class="px-2 py-1.5">
        <select name="rooms[{{ $index }}][status]" class="w-full min-w-32 border border-nicon-line bg-white px-2 py-1.5">
            <option value="ok" @selected($status === 'ok')>Ok</option>
            <option value="controleren" @selected($status === 'controleren')>Controleren</option>
        </select>
    </td>
    <td class="px-2 py-1.5 text-right">
        <button type="button" class="text-sm text-nicon-muted" data-remove-room>Verwijderen</button>
    </td>
</tr>
