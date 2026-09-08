@php
    $tasks = $area['tasks'] ?? [];
    $meters = $area['square_meters'] ?? null;
    $metersValue = $meters === null || $meters === '' ? '' : str_replace('.', ',', (string) $meters);
    $needsReview = (bool) ($area['needs_review'] ?? false);
@endphp
<tr id="area-{{ $index }}" class="border-t border-nicon-line align-top {{ $needsReview ? 'bg-amber-50' : '' }}" data-area-row>
    <td class="px-2 py-1.5">
        <input name="areas[{{ $index }}][floor]" value="{{ $area['floor'] ?? '' }}" class="w-36 border border-nicon-line px-2 py-1">
    </td>
    <td class="px-2 py-1.5">
        <input name="areas[{{ $index }}][room_number]" value="{{ $area['room_number'] ?? '' }}" class="w-24 border border-nicon-line px-2 py-1">
    </td>
    <td class="px-2 py-1.5">
        <input name="areas[{{ $index }}][room_name]" value="{{ $area['room_name'] ?? '' }}" class="w-40 border border-nicon-line px-2 py-1">
    </td>
    <td class="px-2 py-1.5">
        <input name="areas[{{ $index }}][square_meters]" value="{{ $metersValue }}" inputmode="decimal" placeholder="leeg" class="w-24 border border-nicon-line px-2 py-1">
    </td>
    <td class="px-2 py-1.5">
        <div class="flex flex-col gap-1">
            @forelse ($tasks as $taskIndex => $task)
                @php
                    $taskColor = \App\Support\MaterialColor::resolve(
                        $area['fill_color'] ?? null,
                        $task['work_name'] ?? null,
                    );
                @endphp
                <div class="flex flex-wrap items-center gap-1">
                    <span class="inline-block size-2.5 shrink-0 rounded-full border border-nicon-line" style="background: {{ $taskColor }}" title="Materiaalkleur"></span>
                    <input name="areas[{{ $index }}][tasks][{{ $taskIndex }}][work_name]" value="{{ $task['work_name'] ?? '' }}" class="min-w-40 grow border border-nicon-line px-2 py-1" placeholder="Materiaal">
                    <input name="areas[{{ $index }}][tasks][{{ $taskIndex }}][quantity]" value="{{ isset($task['quantity']) && $task['quantity'] !== null && $task['quantity'] !== '' ? str_replace('.', ',', (string) $task['quantity']) : '' }}" inputmode="decimal" class="w-20 border border-nicon-line px-2 py-1" placeholder="m²">
                    <input type="hidden" name="areas[{{ $index }}][tasks][{{ $taskIndex }}][unit]" value="{{ $task['unit'] ?? 'm2' }}">
                    <input type="hidden" name="areas[{{ $index }}][tasks][{{ $taskIndex }}][perimeter]" value="{{ $task['perimeter'] ?? '' }}">
                    <input type="hidden" name="areas[{{ $index }}][tasks][{{ $taskIndex }}][seams]" value="{{ $task['seams'] ?? '' }}">
                    @if (($task['unit'] ?? 'm2') === 'm1' && ! empty($task['perimeter']))
                        <span class="text-[11px] text-nicon-muted">{{ \App\Support\Format::qty($task['perimeter'], 2) }} m¹</span>
                    @endif
                </div>
            @empty
                <div class="flex flex-wrap items-center gap-1">
                    <span class="inline-block size-2.5 shrink-0 rounded-full border border-nicon-line" style="background: {{ \App\Support\MaterialColor::UNKNOWN }}" title="Materiaalkleur"></span>
                    <input name="areas[{{ $index }}][tasks][0][work_name]" value="" class="min-w-40 grow border border-nicon-line px-2 py-1" placeholder="Materiaal (optioneel)">
                    <input name="areas[{{ $index }}][tasks][0][quantity]" value="" inputmode="decimal" class="w-20 border border-nicon-line px-2 py-1" placeholder="m²">
                    <input type="hidden" name="areas[{{ $index }}][tasks][0][unit]" value="m2">
                    <input type="hidden" name="areas[{{ $index }}][tasks][0][perimeter]" value="">
                    <input type="hidden" name="areas[{{ $index }}][tasks][0][seams]" value="">
                </div>
            @endforelse
        </div>
    </td>
    <td class="px-2 py-1.5 whitespace-nowrap text-xs">
        <input type="hidden" name="areas[{{ $index }}][material_source]" value="{{ $area['material_source'] ?? 'onbekend' }}">
        {{ $area['material_source_label'] ?? 'Onbekend' }}
    </td>
    <td class="px-2 py-1.5 whitespace-nowrap">
        <input type="hidden" name="areas[{{ $index }}][source]" value="{{ $area['source'] ?? 'handmatig' }}">
        <input type="hidden" name="areas[{{ $index }}][recognized_via]" value="{{ is_array($area['recognized_via'] ?? null) ? implode(',', $area['recognized_via']) : ($area['recognized_via'] ?? '') }}">
        <input type="hidden" name="areas[{{ $index }}][fill_color]" value="{{ $area['fill_color'] ?? '' }}">
        {{ $area['recognized_via_label'] ?? ($area['source_label'] ?? 'Handmatig') }}
    </td>
    <td class="px-2 py-1.5 whitespace-nowrap">
        <select name="areas[{{ $index }}][confidence]" class="border border-nicon-line px-2 py-1 text-xs">
            <option value="controleren" @selected(($area['confidence'] ?? '') === 'controleren')>Controleren</option>
            <option value="midden" @selected(($area['confidence'] ?? '') === 'midden')>Midden</option>
            <option value="hoog" @selected(($area['confidence'] ?? '') === 'hoog')>Hoog (bevestigd)</option>
        </select>
    </td>
    <td class="px-2 py-1.5 text-xs {{ !empty($area['review_reason_label']) ? 'text-nicon-warn' : 'text-nicon-muted' }}">
        {{ $area['review_reason_label'] ?? '' }}
    </td>
    <td class="px-2 py-1.5">
        <button type="button" class="text-xs text-nicon-danger" data-remove-area>Verwijderen</button>
    </td>
</tr>
