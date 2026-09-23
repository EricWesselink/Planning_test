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
    <section class="mt-3 overflow-hidden border border-nicon-line bg-white">
        <header class="flex items-stretch bg-nicon-paper text-sm">
            <span class="w-1 shrink-0 bg-nicon-orange" aria-hidden="true"></span>
            <div class="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-x-3 gap-y-0.5 px-3 py-1.5">
            <h2 class="flex min-w-0 items-center gap-2 font-semibold leading-tight text-nicon-ink">
                <span class="inline-block size-2.5 shrink-0 rounded-full" style="background: {{ $worker->planColor() }}"></span>
                <span class="truncate">{{ $workerTitle }}</span>
            </h2>
            <div class="text-xs leading-snug text-nicon-muted sm:max-w-[58%] sm:text-right">
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
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1.5 px-3 py-2 text-sm">
                    <a class="min-w-0" href="{{ route('projects.show', $project) }}">
                        @if ($projectRef !== '')
                            <div class="text-xs font-semibold leading-tight text-nicon-orange">{{ $projectRef }}</div>
                        @endif
                        <div class="font-semibold leading-tight text-nicon-ink">{{ $project->displayTitle() }}</div>
                        <div class="text-xs leading-tight text-nicon-muted">
                            {{ $projectGroup['room_count'] }} {{ $projectGroup['room_count'] === 1 ? 'ruimte' : 'ruimtes' }}
                            · {{ \App\Support\Format::qty($projectGroup['total_m2'], 2) }} m²
                            @if ($projectGroup['total_m1'] > 0)
                                · {{ \App\Support\Format::qty($projectGroup['total_m1'], 2) }} m¹
                            @endif
                        </div>
                    </a>
                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5 text-xs no-print">
                        @if ($canCreateVouchers)
                            @if ($sheet)
                                <a class="inline-flex h-7 items-center border border-nicon-line bg-white px-2 text-nicon-ink" href="{{ route('vouchers.edit', $sheet['opdracht']) }}">Opdracht</a>
                                <a class="inline-flex h-7 items-center border border-nicon-line bg-white px-2 text-nicon-ink" href="{{ route('vouchers.pdf', $sheet['opdracht']) }}">PDF</a>
                                @if ($sheet['bons']->isEmpty())
                                    <form method="POST" action="{{ route('vouchers.destroy', $sheet['opdracht']) }}" onsubmit="return confirm({{ json_encode($sheet['opdracht']->type->label().' '.$sheet['opdracht']->number.' wordt verwijderd. Ruimtes worden weer open gezet.') }})">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex h-7 items-center border border-nicon-line bg-white px-2 text-nicon-danger">Verwijderen</button>
                                    </form>
                                @endif
                                @if ($hasRemaining)
                                    <button type="submit" form="{{ $formId }}" class="inline-flex h-7 items-center border border-nicon-orange bg-nicon-orange px-2 font-medium text-white">Bon maken</button>
                                @endif
                            @else
                                <a class="inline-flex h-7 items-center border border-nicon-line bg-white px-2 text-nicon-ink" href="{{ route('vouchers.create', $voucherQuery + ['type' => 'opdracht']) }}">Opdrachtbon</a>
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
                                <button class="inline-flex h-7 items-center border border-nicon-orange bg-nicon-orange px-2 font-medium text-white">Akkoord voorlopig werk</button>
                            </form>
                        @endif
                    </div>
                </div>

                @php
                    $ticketRows = $projectGroup['tickets'] ?? [];
                    $collapseRooms = ($projectGroup['rooms_from_ticket'] ?? false) && $ticketRows !== [];
                @endphp

                @if ($ticketRows !== [])
                    @foreach ($ticketRows as $ticket)
                        <div class="mx-3 mb-2 flex flex-wrap items-start justify-between gap-2 border border-nicon-line bg-nicon-paper px-3 py-1.5 text-sm">
                            @if ($collapseRooms && $loop->first)
                                <details class="min-w-0 flex-1">
                                    <summary class="cursor-pointer">
                                        <span class="font-medium text-nicon-ink">{{ $ticket['kind_label'] }} {{ $ticket['number'] }}</span>
                                        @if (($ticket['summary'] ?? '') !== '')
                                            <span class="text-xs text-nicon-muted">· {{ $ticket['summary'] }}</span>
                                        @endif
                                        <span class="text-xs text-nicon-muted">· {{ $ticket['period'] }}</span>
                                        @if ($ticket['hours_submitted'])
                                            <span class="text-xs text-nicon-orange-dark">· {{ \App\Support\Format::hours($ticket['hours']) }}</span>
                                        @endif
                                    </summary>
                                    @include('production._rooms', [
                                        'projectGroup' => $projectGroup,
                                        'project' => $project,
                                        'canApproveProgress' => $canApproveProgress,
                                    ])
                                </details>
                            @else
                                <div class="min-w-0">
                                    <a class="font-medium text-nicon-ink" href="{{ $ticket['url'] }}">{{ $ticket['kind_label'] }} {{ $ticket['number'] }}</a>
                                    <div class="text-xs leading-snug text-nicon-muted">
                                        @if (($ticket['summary'] ?? '') !== '')
                                            {{ $ticket['summary'] }}
                                            <span>· </span>
                                        @endif
                                        {{ $ticket['period'] }}
                                        @if ($ticket['hours_submitted'])
                                            <span class="text-nicon-orange-dark">· {{ \App\Support\Format::hours($ticket['hours']) }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endif
                            <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5 text-xs no-print">
                                <a class="inline-flex h-7 items-center border border-nicon-line bg-white px-2 text-nicon-ink" href="{{ $ticket['url'] }}">Open</a>
                                @if ($canCreateVouchers && $ticket['hours_submitted'])
                                    <a class="inline-flex h-7 items-center border border-nicon-orange bg-nicon-orange px-2 font-medium text-white" href="{{ route('vouchers.create', $ticket['voucher_query'] + ['type' => $sheet ? 'facturatie' : 'opdracht']) }}">Bon maken</a>
                                @endif
                                @if ($canCreateVouchers)
                                    <form method="POST" action="{{ $ticket['destroy_url'] }}" onsubmit="return confirm({{ json_encode($ticket['kind_label'].' '.$ticket['number'].' wordt verwijderd.') }})">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex h-7 items-center border border-nicon-line bg-white px-2 text-nicon-danger">Verwijderen</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endif

                @if ($sheet)
                    @include('production._sheet', [
                        'group' => $group,
                        'projectGroup' => $projectGroup,
                        'sheet' => $sheet,
                        'canCreateVouchers' => $canCreateVouchers,
                        'canApproveProgress' => $canApproveProgress,
                    ])
                @elseif (! $collapseRooms)
                    @include('production._rooms', [
                        'projectGroup' => $projectGroup,
                        'project' => $project,
                        'canApproveProgress' => $canApproveProgress,
                    ])
                @endif
            </div>
        @endforeach
    </section>
@empty
    <p class="mt-4 border border-nicon-line bg-white px-4 py-4 text-sm text-nicon-muted">
        Nog geen productie. Vink werkzaamheden af op de tekening; ze komen hier per team of ZZP te staan, met klaar gemeld tot jij akkoord geeft.
    </p>
@endforelse
