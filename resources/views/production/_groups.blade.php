@php
    $canCreateVouchers = $canCreateVouchers ?? false;
    $canApproveProgress = $canApproveProgress ?? false;
    $vouchersByKey = $vouchersByKey ?? collect();
    $billingByKey = $billingByKey ?? collect();
    $sheetsByKey = $sheetsByKey ?? collect();
    $filters = $filters ?? ['from' => null, 'to' => null];
@endphp
@forelse ($groups as $group)
    @php
        $worker = $group['worker'];
        $typeLabel = $worker->employment_type->label();
        $workerTitle = str_starts_with($worker->displayName(), $typeLabel)
            ? $worker->displayName()
            : $typeLabel.' '.$worker->planName();
        $workerSheets = collect($group['projects'])->map(function (array $projectGroup) use ($sheetsByKey, $worker) {
            return $sheetsByKey[$worker->id.'.'.$projectGroup['project']->id] ?? null;
        })->filter();
        $workerOpdracht = (float) $workerSheets->sum(fn (array $sheet) => $sheet['billing']['opdracht_amount']);
        $workerInvoiced = (float) $workerSheets->sum(fn (array $sheet) => $sheet['billing']['invoiced_amount']);
        $workerOpen = (float) $workerSheets->sum(fn (array $sheet) => $sheet['billing']['remaining_amount']);
    @endphp
    <section class="mt-4 border border-nicon-line bg-white">
        <header class="flex h-8 items-center justify-between gap-3 bg-nicon-ink px-3 text-sm text-white">
            <h2 class="flex min-w-0 items-center gap-2 font-semibold leading-none">
                <span class="inline-block size-2.5 shrink-0 rounded-full" style="background: {{ $worker->planColor() }}"></span>
                <span class="truncate">{{ $workerTitle }}</span>
            </h2>
            <div class="shrink-0 whitespace-nowrap text-xs text-white/80">
                @if ($workerSheets->isNotEmpty())
                    Opdracht {{ \App\Support\Format::money($workerOpdracht) }}
                    | Op bon {{ \App\Support\Format::money($workerInvoiced) }}
                    | Open {{ $workerOpen > 0.001 ? \App\Support\Format::money($workerOpen) : '—' }}
                @else
                    {{ $group['room_count'] }} {{ $group['room_count'] === 1 ? 'ruimte' : 'ruimtes' }}
                    · {{ \App\Support\Format::qty($group['total_m2'], 2) }} m²
                    @if ($group['total_m1'] > 0)
                        · {{ \App\Support\Format::qty($group['total_m1'], 2) }} m¹
                    @endif
                @endif
                @if (($group['hours_pending'] ?? 0) > 0)
                    · {{ $group['hours_pending'] }} uren teruggestuurd
                @endif
                @if (($group['provisional_count'] ?? 0) > 0)
                    · {{ $group['provisional_count'] }} {{ $group['provisional_count'] === 1 ? 'wacht op akkoord' : 'wachten op akkoord' }}
                @endif
            </div>
        </header>

        @foreach ($group['projects'] as $projectGroup)
            @php
                $project = $projectGroup['project'];
                $voucherKey = $worker->id.'.'.$project->id;
                $sheet = $sheetsByKey[$voucherKey] ?? null;
                $formId = 'bon-'.$worker->id.'-'.$project->id;
                $voucherQuery = array_filter([
                    'worker_id' => $worker->id,
                    'project_id' => $project->id,
                    'from' => $filters['from'] ?? null,
                    'to' => $filters['to'] ?? null,
                ], fn ($value) => $value !== null && $value !== '');
                $hasRemaining = $sheet && ! $sheet['billing']['fully_settled'];
                $codeParts = array_values(array_filter([
                    $project->workCode(),
                    $project->workNumber() !== '' ? $project->workNumber() : null,
                ]));
                $projectRef = $codeParts !== [] ? implode(' · ', $codeParts) : $project->labeledNumbersLine();
            @endphp
            <div class="border-t border-nicon-line">
                <div class="flex min-h-8 items-center justify-between gap-3 bg-nicon-sand px-3 py-1 text-sm">
                    <a class="min-w-0 truncate text-nicon-orange-dark" href="{{ route('projects.show', $project) }}">
                        @if ($projectRef !== '')
                            {{ $projectRef }} |
                        @endif
                        {{ $project->displayTitle() }}
                        <span class="text-nicon-muted">
                            | {{ $projectGroup['room_count'] }} {{ $projectGroup['room_count'] === 1 ? 'ruimte' : 'ruimtes' }}
                            · {{ \App\Support\Format::qty($projectGroup['total_m2'], 2) }} m²
                            @if ($projectGroup['total_m1'] > 0)
                                · {{ \App\Support\Format::qty($projectGroup['total_m1'], 2) }} m¹
                            @endif
                        </span>
                    </a>
                    <div class="flex shrink-0 items-center gap-1.5 text-xs no-print">
                        @if ($canCreateVouchers)
                            @if ($sheet)
                                <a class="border border-nicon-line bg-white px-2 py-0.5" href="{{ route('vouchers.edit', $sheet['opdracht']) }}">Opdracht</a>
                                <a class="border border-nicon-line bg-white px-2 py-0.5" href="{{ route('vouchers.pdf', $sheet['opdracht']) }}">PDF</a>
                                @if ($sheet['bons']->isEmpty())
                                    <form method="POST" action="{{ route('vouchers.destroy', $sheet['opdracht']) }}" onsubmit="return confirm({{ json_encode($sheet['opdracht']->type->label().' '.$sheet['opdracht']->number.' wordt verwijderd. Ruimtes worden weer open gezet.') }})">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="border border-nicon-line bg-white px-2 py-0.5 text-nicon-danger">Verwijderen</button>
                                    </form>
                                @endif
                                @if ($hasRemaining)
                                    <button type="submit" form="{{ $formId }}" class="bg-nicon-orange px-2 py-0.5 text-white">Bon maken</button>
                                @endif
                            @else
                                <a class="border border-nicon-line bg-white px-2 py-0.5" href="{{ route('vouchers.create', $voucherQuery + ['type' => 'opdracht']) }}">Opdrachtbon</a>
                                <span class="text-nicon-muted">Eerst opdrachtbon</span>
                            @endif
                        @endif
                        @if ($canApproveProgress && count($projectGroup['provisional_task_ids'] ?? []) > 0)
                            <form method="POST" action="{{ route('production.approve') }}">
                                @csrf
                                <input type="hidden" name="project_id" value="{{ $project->id }}">
                                @foreach ($projectGroup['provisional_task_ids'] as $taskId)
                                    <input type="hidden" name="task_ids[]" value="{{ $taskId }}">
                                @endforeach
                                <button class="bg-nicon-ink px-2 py-0.5 text-white">Akkoord voorlopig werk</button>
                            </form>
                        @endif
                    </div>
                </div>

                @if (($projectGroup['tickets'] ?? []) !== [])
                    <ul class="border-t border-nicon-line bg-white px-3 py-2 text-sm">
                        @foreach ($projectGroup['tickets'] as $ticket)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-1">
                                <div>
                                    <a class="font-medium text-nicon-orange-dark" href="{{ $ticket['url'] }}">{{ $ticket['kind_label'] }} {{ $ticket['number'] }}</a>
                                    <span class="text-nicon-muted">· {{ $ticket['period'] }}</span>
                                    @if ($ticket['hours_submitted'])
                                        <span class="ml-1 border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">Uren teruggestuurd · {{ \App\Support\Format::hours($ticket['hours']) }}</span>
                                    @else
                                        <span class="text-nicon-muted">· uren nog niet ingevuld</span>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1.5 text-xs no-print">
                                    <a class="border border-nicon-line bg-white px-2 py-0.5" href="{{ $ticket['url'] }}">Open bon</a>
                                    @if ($canCreateVouchers && $ticket['hours_submitted'])
                                        <a class="bg-nicon-orange px-2 py-0.5 text-white" href="{{ route('vouchers.create', $ticket['voucher_query'] + ['type' => $sheet ? 'facturatie' : 'opdracht']) }}">Bon maken</a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

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
                                    <th class="px-3 py-1 font-medium">Ruimte</th>
                                    <th class="px-3 py-1 font-medium">m² ruimte</th>
                                    <th class="px-3 py-1 font-medium">Materialen</th>
                                    <th class="px-3 py-1 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($projectGroup['rooms'] as $room)
                                    <tr class="border-t border-nicon-line align-top">
                                        <td class="px-3 py-1 font-medium">{{ $room['label'] }}</td>
                                        <td class="whitespace-nowrap px-3 py-1">
                                            @if ($room['area_m2'] > 0)
                                                {{ \App\Support\Format::qty($room['area_m2'], 2) }} m²
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-3 py-1">
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
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </td>
                                        <td class="px-3 py-1">
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
                                                        @if ($canApproveProgress && ($material['provisional'] ?? false) && ($material['task_id'] ?? null))
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
                @endif
            </div>
        @endforeach
    </section>
@empty
    <p class="mt-4 border border-nicon-line bg-white px-4 py-4 text-sm text-nicon-muted">
        Nog geen productie. Vink werkzaamheden af op de tekening; ze komen hier per team of ZZP te staan, met klaar gemeld tot jij akkoord geeft.
    </p>
@endforelse
