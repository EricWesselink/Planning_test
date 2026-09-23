@php
    $projectGroup = $projectGroup ?? ['rooms' => collect()];
    $project = $project ?? null;
    $canApproveProgress = $canApproveProgress ?? false;
@endphp
<div class="overflow-x-auto">
    <table class="w-full min-w-[36rem] text-sm">
        <thead class="border-y border-nicon-line bg-nicon-paper text-left text-[11px] tracking-wide text-nicon-muted">
            <tr>
                <th class="px-3 py-1.5 font-medium">Ruimte</th>
                <th class="px-3 py-1.5 text-right font-medium">m² ruimte</th>
                <th class="px-3 py-1.5 font-medium">Materialen</th>
                <th class="px-3 py-1.5 font-medium">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($projectGroup['rooms'] as $room)
                <tr class="border-b border-nicon-line align-top">
                    <td class="px-3 py-1.5 font-medium text-nicon-ink">{{ $room['label'] }}</td>
                    <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">
                        @if ($room['area_m2'] > 0)
                            {{ \App\Support\Format::qty($room['area_m2'], 2) }} m²
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-1.5">
                        <ul class="flex flex-col gap-0.5">
                            @foreach ($room['materials'] as $material)
                                <li class="flex items-center gap-2">
                                    <span class="inline-block size-2.5 shrink-0 rounded-full border border-nicon-line" style="background: {{ $material['display_color'] ?? \App\Support\MaterialColor::resolve(null, $material['label'] ?? null) }}"></span>
                                    <span>
                                        {{ $material['label'] }}
                                        <span class="text-nicon-muted">
                                            {{ \App\Support\Format::qty($material['quantity'], 2) }}
                                            {{ $material['unit']?->label() }}
                                        </span>
                                        @if (($material['note'] ?? '') !== '')
                                            <span class="block text-xs text-nicon-muted">{{ $material['note'] }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </td>
                    <td class="px-3 py-1.5">
                        <ul class="flex flex-col gap-0.5">
                            @foreach ($room['materials'] as $material)
                                <li class="flex flex-wrap items-center gap-2">
                                    @if ($material['provisional'] ?? false)
                                        <span class="border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">Klaar gemeld · wacht op akkoord</span>
                                    @elseif ($material['approved'] ?? false)
                                        <span class="border border-nicon-line bg-nicon-sand px-1.5 py-0.5 text-[11px] text-nicon-ok">Akkoord</span>
                                    @elseif ($material['status_label'] ?? null)
                                        <span class="text-nicon-muted">{{ $material['status_label'] }}</span>
                                    @else
                                        <span class="text-nicon-muted">Geregistreerd</span>
                                    @endif
                                    @if ($material['klaar_on'] ?? null)
                                        <span class="text-[11px] text-nicon-muted">{{ $material['klaar_on'] }}</span>
                                    @endif
                                    @if ($canApproveProgress && ($material['provisional'] ?? false) && ($material['task_id'] ?? null) && $project)
                                        <form method="POST" action="{{ route('production.approve') }}" class="no-print">
                                            @csrf
                                            <input type="hidden" name="project_id" value="{{ $project->id }}">
                                            <input type="hidden" name="task_ids[]" value="{{ $material['task_id'] }}">
                                            <button class="text-xs text-nicon-orange-dark underline-offset-2 hover:underline">Akkoord</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
