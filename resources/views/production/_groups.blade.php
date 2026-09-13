@php
    $canCreateVouchers = $canCreateVouchers ?? false;
    $canApproveProgress = $canApproveProgress ?? false;
    $vouchersByKey = $vouchersByKey ?? collect();
    $billingByKey = $billingByKey ?? collect();
    $sheetsByKey = $sheetsByKey ?? collect();
    $filters = $filters ?? ['from' => null, 'to' => null];
@endphp
@forelse ($groups as $group)
    <section class="mt-8 border border-nicon-line bg-white">
        <header class="flex items-end justify-between gap-4 flex-wrap bg-nicon-ink px-4 py-3 text-white">
            <div>
                <h2 class="font-semibold flex items-center gap-2">
                    <span class="inline-block size-2.5 shrink-0 rounded-full" style="background: {{ $group['worker']->planColor() }}"></span>
                    {{ $group['worker']->displayName() }}
                </h2>
                <p class="text-xs text-white/70">{{ $group['worker']->employment_type->label() }}</p>
            </div>
            <div class="text-sm text-white/80">
                {{ $group['room_count'] }} {{ $group['room_count'] === 1 ? 'ruimte' : 'ruimtes' }}
                · {{ \App\Support\Format::qty($group['total_m2'], 2) }} m²
                @if ($group['total_m1'] > 0)
                    · {{ \App\Support\Format::qty($group['total_m1'], 2) }} m¹
                @endif
                @if (($group['provisional_count'] ?? 0) > 0)
                    · {{ $group['provisional_count'] }} {{ $group['provisional_count'] === 1 ? 'wacht op akkoord' : 'wachten op akkoord' }}
                @endif
            </div>
        </header>

        @foreach ($group['projects'] as $projectGroup)
            @php
                $voucherKey = $group['worker']->id.'.'.$projectGroup['project']->id;
                $sheet = $sheetsByKey[$voucherKey] ?? null;
                $formId = 'bon-'.$group['worker']->id.'-'.$projectGroup['project']->id;
                $voucherQuery = array_filter([
                    'worker_id' => $group['worker']->id,
                    'project_id' => $projectGroup['project']->id,
                    'from' => $filters['from'] ?? null,
                    'to' => $filters['to'] ?? null,
                ], fn ($value) => $value !== null && $value !== '');
                $hasRemaining = $sheet && ! $sheet['billing']['fully_settled'];
            @endphp
            <div class="border-t border-nicon-line">
                <div class="flex items-end justify-between gap-4 flex-wrap px-4 py-3 bg-nicon-sand">
                    <div>
                        <a class="font-medium text-nicon-orange-dark" href="{{ route('projects.show', $projectGroup['project']) }}">
                            <span class="block whitespace-nowrap">{{ $projectGroup['project']->labeledNumbersLine() }}</span>
                            <span class="block">{{ $projectGroup['project']->displayTitle() }}</span>
                        </a>
                        @if ($projectGroup['project']->city)
                            <div class="text-xs text-nicon-muted">{{ $projectGroup['project']->city }}</div>
                        @endif
                    </div>
                    <div class="flex items-center gap-3 flex-wrap text-sm">
                        @if (! $sheet)
                            <span class="text-nicon-muted">
                                {{ \App\Support\Format::qty($projectGroup['total_m2'], 2) }} m²
                                @if ($projectGroup['total_m1'] > 0)
                                    · {{ \App\Support\Format::qty($projectGroup['total_m1'], 2) }} m¹
                                @endif
                            </span>
                        @endif
                        @if ($canCreateVouchers)
                            @if ($sheet)
                                <a class="border border-nicon-line bg-white px-3 py-1.5 no-print" href="{{ route('vouchers.edit', $sheet['opdracht']) }}">Opdracht aanpassen</a>
                                <a class="border border-nicon-line bg-white px-3 py-1.5 no-print" href="{{ route('vouchers.pdf', $sheet['opdracht']) }}">Download PDF</a>
                                @if ($hasRemaining)
                                    <button type="submit" form="{{ $formId }}" class="bg-nicon-orange text-white px-3 py-1.5 no-print">Bon maken</button>
                                @endif
                            @else
                                <a class="border border-nicon-line bg-white px-3 py-1.5" href="{{ route('vouchers.create', $voucherQuery + ['type' => 'opdracht']) }}">Opdrachtbon</a>
                                <span class="text-xs text-nicon-muted">Eerst opdrachtbon</span>
                            @endif
                        @endif
                        @if ($canApproveProgress && count($projectGroup['provisional_task_ids'] ?? []) > 0)
                            <form method="POST" action="{{ route('production.approve') }}" class="no-print">
                                @csrf
                                <input type="hidden" name="project_id" value="{{ $projectGroup['project']->id }}">
                                @foreach ($projectGroup['provisional_task_ids'] as $taskId)
                                    <input type="hidden" name="task_ids[]" value="{{ $taskId }}">
                                @endforeach
                                <button class="bg-nicon-ink text-white px-3 py-1.5">Akkoord voorlopig werk</button>
                            </form>
                        @endif
                    </div>
                </div>

                @if ($sheet)
                    @include('production._sheet', [
                        'group' => $group,
                        'projectGroup' => $projectGroup,
                        'sheet' => $sheet,
                        'canCreateVouchers' => $canCreateVouchers,
                        'canApproveProgress' => $canApproveProgress,
                    ])
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-nicon-muted">
                                <tr>
                                    <th class="px-4 py-2 font-medium">Ruimte</th>
                                    <th class="px-4 py-2 font-medium">m² ruimte</th>
                                    <th class="px-4 py-2 font-medium">Materialen</th>
                                    <th class="px-4 py-2 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($projectGroup['rooms'] as $room)
                                    <tr class="border-t border-nicon-line align-top">
                                        <td class="px-4 py-2 font-medium">{{ $room['label'] }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap">
                                            @if ($room['area_m2'] > 0)
                                                {{ \App\Support\Format::qty($room['area_m2'], 2) }} m²
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-2">
                                            <ul class="space-y-1">
                                                @foreach ($room['materials'] as $material)
                                                    <li class="flex items-center gap-2">
                                                        <span class="inline-block size-2.5 shrink-0 rounded-full border border-nicon-line" style="background: {{ $material['display_color'] ?? \App\Support\MaterialColor::resolve(null, $material['label'] ?? null) }}"></span>
                                                        <span>
                                                            {{ $material['label'] }}
                                                            <span class="text-nicon-muted">
                                                                {{ \App\Support\Format::qty($material['quantity'], 2) }}
                                                                {{ $material['unit']?->label() }}
                                                            </span>
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </td>
                                        <td class="px-4 py-2">
                                            <ul class="space-y-2">
                                                @foreach ($room['materials'] as $material)
                                                    <li class="flex flex-wrap items-center gap-2">
                                                        @if ($material['provisional'] ?? false)
                                                            <span class="border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">Klaar gemeld · wacht op akkoord</span>
                                                        @elseif ($material['approved'] ?? false)
                                                            <span class="border border-nicon-line bg-nicon-sand px-1.5 py-0.5 text-[11px] text-nicon-ok">Akkoord</span>
                                                        @else
                                                            <span class="text-nicon-muted">Geregistreerd</span>
                                                        @endif
                                                        @if ($material['klaar_on'] ?? null)
                                                            <span class="text-[11px] text-nicon-muted">{{ $material['klaar_on'] }}</span>
                                                        @endif
                                                        @if ($canApproveProgress && ($material['provisional'] ?? false) && ($material['task_id'] ?? null))
                                                            <form method="POST" action="{{ route('production.approve') }}" class="no-print">
                                                                @csrf
                                                                <input type="hidden" name="project_id" value="{{ $projectGroup['project']->id }}">
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
                @endif
            </div>
        @endforeach
    </section>
@empty
    <p class="mt-8 border border-nicon-line bg-white px-4 py-6 text-sm text-nicon-muted">
        Nog geen productie. Vink werkzaamheden af op de tekening; ze komen hier per team of ZZP te staan, met klaar gemeld tot jij akkoord geeft.
    </p>
@endforelse
