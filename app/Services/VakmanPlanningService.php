<?php

namespace App\Services;

use App\Enums\VoucherPriceKind;
use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkTicket;
use App\Support\Format;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class VakmanPlanningService
{
    public function __construct(private VoucherPriceResolver $prices) {}

    /**
     * @return array{
     *     view: string,
     *     weekStart: Carbon,
     *     monthStart: Carbon,
     *     periodLabel: string,
     *     prevUrl: string,
     *     nextUrl: string,
     *     todayUrl: string,
     *     hasJobs: bool,
     *     days: Collection<int, array<string, mixed>>
     * }
     */
    public function agenda(User $user, string $view, ?string $week, ?string $month): array
    {
        $view = $view === 'month' ? 'month' : 'week';
        $weekStart = $this->weekStart($week);
        $monthStart = $this->monthStart($month, $week);

        if ($view === 'month') {
            $from = $monthStart->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
            $to = $monthStart->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)->startOfDay();
            $periodLabel = $monthStart->translatedFormat('F Y');
            $prev = $monthStart->copy()->subMonthNoOverflow()->startOfMonth();
            $next = $monthStart->copy()->addMonthNoOverflow()->startOfMonth();
            $prevUrl = route('vakman.planning', ['view' => 'month', 'month' => $prev->format('Y-m')]);
            $nextUrl = route('vakman.planning', ['view' => 'month', 'month' => $next->format('Y-m')]);
            $todayUrl = route('vakman.planning', ['view' => 'month', 'month' => now()->format('Y-m')]);
        } else {
            $from = $weekStart->copy();
            $to = $weekStart->copy()->addDays(5);
            $periodLabel = 'Week '.$weekStart->isoWeek().' · '.$weekStart->translatedFormat('j M').' – '.$to->translatedFormat('j M Y');
            $prevUrl = route('vakman.planning', ['view' => 'week', 'week' => $weekStart->copy()->subWeek()->toDateString()]);
            $nextUrl = route('vakman.planning', ['view' => 'week', 'week' => $weekStart->copy()->addWeek()->toDateString()]);
            $todayUrl = route('vakman.planning', ['view' => 'week', 'week' => now()->startOfWeek(Carbon::MONDAY)->toDateString()]);
        }

        $assignments = $this->assignments($user, $from, $to);
        $colleagues = $this->colleagueAssignmentsForWindow($user, $assignments, $from, $to);
        $days = $this->calendarDays($from, $to, $monthStart, $user, $assignments, $colleagues);

        return [
            'view' => $view,
            'weekStart' => $weekStart,
            'monthStart' => $monthStart,
            'periodLabel' => $periodLabel,
            'prevUrl' => $prevUrl,
            'nextUrl' => $nextUrl,
            'todayUrl' => $todayUrl,
            'hasJobs' => $days->contains(fn (array $day): bool => $day['jobs'] !== []),
            'days' => $days,
        ];
    }

    /**
     * @return array{
     *     date: Carbon,
     *     heading: string,
     *     jobs: list<array<string, mixed>>,
     *     isExternal: bool
     * }
     */
    public function day(User $user, CarbonInterface $date): array
    {
        $day = $date->copy()->startOfDay();
        $assignments = $this->assignments($user, $day, $day);
        $colleagues = $this->colleagueAssignmentsForWindow($user, $assignments, $day, $day);
        $jobs = $this->jobsOnDate($user, $assignments, $colleagues, $day, detailed: true);

        return [
            'date' => $day,
            'heading' => $this->dayHeading($day),
            'jobs' => $jobs,
            'isExternal' => $user->worker?->employment_type?->isExternal() ?? false,
        ];
    }

    public function weekStart(?string $week): Carbon
    {
        $date = $week ? Carbon::parse($week) : now();

        return $date->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    public function monthStart(?string $month, ?string $week = null): Carbon
    {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth()->startOfDay();
        }

        $date = $week ? Carbon::parse($week) : now();

        return $date->copy()->startOfMonth()->startOfDay();
    }

    /**
     * @return Collection<int, WorkerAssignment>
     */
    private function assignments(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $workerId = $user->scheduledWorkerId();
        if ($workerId === null) {
            return collect();
        }

        return WorkerAssignment::query()
            ->with([
                'project.customer',
                'project.workItems.areaTasks.area.floor',
                'project.workActivities.category',
                'project.documents',
                'project.workOrders',
                'worker.rates',
                'workItem.areaTasks.area.floor',
                'crewMembers',
                'workTickets.lines.workItem',
                'workTickets.areas.floor',
                'workTickets.floors',
                'workTickets.documents',
            ])
            ->where('worker_id', $workerId)
            ->whereDate('end_date', '>=', $from)
            ->whereDate('start_date', '<=', $to)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, WorkerAssignment>  $colleagues
     * @return Collection<int, array<string, mixed>>
     */
    private function calendarDays(
        CarbonInterface $from,
        CarbonInterface $to,
        CarbonInterface $monthStart,
        User $user,
        Collection $assignments,
        Collection $colleagues,
    ): Collection {
        $days = collect();
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($cursor->lte($last)) {
            $jobs = $this->jobsOnDate($user, $assignments, $colleagues, $cursor, detailed: false);
            $days->push([
                'date' => $cursor->copy(),
                'key' => $cursor->toDateString(),
                'heading' => $this->dayHeading($cursor),
                'short' => $cursor->translatedFormat('j'),
                'weekday' => ucfirst($cursor->translatedFormat('D')),
                'is_today' => $cursor->isSameDay(now()),
                'in_month' => $cursor->isSameMonth($monthStart),
                'url' => $jobs !== [] ? route('vakman.planning.day', $cursor->toDateString()) : null,
                'jobs' => $jobs,
            ]);
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, WorkerAssignment>  $colleagues
     * @return list<array<string, mixed>>
     */
    private function jobsOnDate(User $user, Collection $assignments, Collection $colleagues, CarbonInterface $date, bool $detailed): array
    {
        $own = $assignments
            ->filter(fn (WorkerAssignment $assignment): bool => $assignment->project !== null && $assignment->coversDate($date))
            ->values();

        $others = $colleagues;

        return $own->map(function (WorkerAssignment $assignment) use ($user, $others, $date, $detailed): array {
            $project = $assignment->project;
            $tickets = $this->ticketsOnDate($assignment, $date);
            $card = [
                'assignment' => $assignment,
                'project' => $project,
                'project_name' => $project->displayTitle(),
                'city' => trim((string) $project->city),
                'time_label' => $this->timeLabel($assignment),
                'headline' => $this->headlineWork($assignment, $tickets),
                'colleagues' => $this->colleagueNames($user, $assignment, $others, $date),
                'url' => route('vakman.planning.day', $date->toDateString()),
                'tickets' => $tickets,
            ];

            if (! $detailed) {
                return $card;
            }

            $works = $this->works($assignment, $tickets);
            $rooms = $this->rooms($assignment, $works, $tickets);
            $isExternal = $assignment->worker?->employment_type?->isExternal()
                ?? $user->worker?->employment_type?->isExternal()
                ?? false;

            return [
                ...$card,
                'numbers' => $project->labeledNumbersLine(),
                'address' => $project->nawLine(),
                'maps_url' => $project->googleMapsUrl(),
                'floors' => $rooms['floors'],
                'rooms' => $rooms['rooms'],
                'works' => $works,
                'notes' => $this->notes($assignment, $tickets),
                'drawings' => $this->drawings($project, $tickets),
                'project_url' => route('projects.show', $project),
                'werkbon_url' => $isExternal ? null : route('vakman.planning.werkbon', $date->toDateString()),
                'opdrachtbon_url' => $isExternal
                    ? route('vakman.planning.opdrachtbon', [$date->toDateString(), $project])
                    : null,
                'opdracht' => $isExternal
                    ? Voucher::latestOpdracht((int) $assignment->worker_id, (int) $project->id)
                    : null,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $own
     * @return Collection<int, WorkerAssignment>
     */
    private function colleagueAssignmentsForWindow(User $user, Collection $own, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $workerId = $user->scheduledWorkerId();
        $projectIds = $own->pluck('project_id')->unique()->filter()->values();
        if ($workerId === null || $projectIds->isEmpty()) {
            return collect();
        }

        return WorkerAssignment::query()
            ->with(['worker', 'crewMembers'])
            ->whereIn('project_id', $projectIds)
            ->where('worker_id', '!=', $workerId)
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $others
     * @return list<string>
     */
    private function colleagueNames(User $user, WorkerAssignment $assignment, Collection $others, CarbonInterface $date): array
    {
        $ownLabels = array_values(array_filter([
            trim((string) $user->name),
            $assignment->worker?->planName(),
        ], fn (string $name): bool => $name !== ''));

        $overlapping = $others->filter(function (WorkerAssignment $other) use ($assignment, $date): bool {
            return (int) $other->project_id === (int) $assignment->project_id
                && $other->coversDate($date);
        });

        return collect($assignment->presentNames())
            ->concat($overlapping->flatMap(function (WorkerAssignment $row): array {
                $names = $row->presentNames();
                if ($names !== []) {
                    return $names;
                }

                $team = trim((string) ($row->worker?->planName() ?? ''));

                return $team !== '' ? [$team] : [];
            }))
            ->filter(fn (string $name): bool => $name !== '' && ! in_array($name, $ownLabels, true))
            ->unique()
            ->values()
            ->all();
    }

    private function headlineWork(WorkerAssignment $assignment, Collection $tickets): string
    {
        if ($tickets->isNotEmpty()) {
            return $tickets
                ->flatMap(fn (WorkTicket $ticket) => $ticket->lines)
                ->map(fn ($line): string => $line->workItem?->planningTitle() ?? '')
                ->filter()
                ->unique()
                ->values()
                ->implode(' & ');
        }

        $item = $this->resolvedWorkItem($assignment);
        if ($item !== null) {
            return $item->planningTitle();
        }

        $project = $assignment->project;
        if ($project === null) {
            return '';
        }

        if ($project->isWinkel()) {
            return (string) ($project->shopWorkLine() ?? $project->shopHeadline());
        }

        return $this->workItems($assignment)
            ->map(fn (WorkItem $item): string => $item->planningTitle())
            ->filter()
            ->unique()
            ->values()
            ->implode(' & ');
    }

    /**
     * @return list<array{title: string, quantity: string, unit: string, unit_enum: WorkUnit, item: ?WorkItem, amount: ?float, price_label: ?string}>
     */
    public function pricedWorks(WorkerAssignment $assignment): array
    {
        $worker = $assignment->worker;
        $project = $assignment->project;
        $tickets = $assignment->relationLoaded('workTickets') ? $assignment->workTickets : collect();
        $opdracht = $worker !== null && $project !== null
            ? Voucher::latestOpdracht((int) $worker->id, (int) $project->id)
            : null;
        $orders = $project?->workOrders
            ? $project->workOrders->where('worker_id', $assignment->worker_id)->values()
            : collect();

        if ($worker !== null) {
            $worker->loadMissing('rates');
        }

        return array_map(function (array $work) use ($worker, $opdracht, $orders): array {
            $item = $work['item'];
            $unit = $work['unit_enum'];
            $resolved = $worker !== null
                ? $this->prices->resolve($worker, $item, $unit, $opdracht, $orders)
                : ['price' => null, 'source' => null];
            $price = $resolved['price'];
            $quantity = (float) ($item?->ordered_quantity ?? 0);
            $order = $item !== null
                ? $orders->first(fn ($row): bool => (int) $row->work_item_id === (int) $item->id)
                : null;
            if ($order !== null && (float) $order->assigned_quantity > 0.0001) {
                $quantity = (float) $order->assigned_quantity;
            }
            if (isset($work['quantity_value'])) {
                $quantity = (float) $work['quantity_value'];
            }

            $kind = $opdracht?->lines
                ->first(fn ($line): bool => $item !== null && (int) $line->work_item_id === (int) $item->id)
                ?->price_kind;
            $isFixed = $kind === VoucherPriceKind::Fixed;
            $amount = $price !== null
                ? ($isFixed
                    ? (float) ($opdracht?->lines
                        ->first(fn ($line): bool => $item !== null && (int) $line->work_item_id === (int) $item->id)
                        ?->amount ?? 0)
                    : round($quantity * $price, 2))
                : null;

            $work['price_label'] = $price === null
                ? null
                : ($isFixed
                    ? 'Vaste prijs'
                    : Format::money($price).' / '.$unit->label());
            $work['amount'] = $amount;

            return $work;
        }, $this->works($assignment, $tickets));
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     * @return list<array{title: string, quantity: string, unit: string, unit_enum: WorkUnit, item: ?WorkItem, quantity_value: float}>
     */
    private function works(WorkerAssignment $assignment, Collection $tickets): array
    {
        if ($tickets->isNotEmpty()) {
            return $tickets
                ->flatMap(fn (WorkTicket $ticket) => $ticket->lines)
                ->groupBy('work_item_id')
                ->map(function (Collection $lines) {
                    $first = $lines->first();
                    $item = $first?->workItem;
                    $quantity = (float) $lines->sum('quantity');
                    $unit = $first?->unit ?? $item?->unit ?? WorkUnit::SquareMeter;

                    return [
                        'title' => $item?->planningTitle() ?? 'Werkzaamheid',
                        'quantity' => Format::qty($quantity, abs($quantity - round($quantity)) < 0.001 ? 0 : 2),
                        'quantity_value' => $quantity,
                        'unit' => $unit->label(),
                        'unit_enum' => $unit,
                        'item' => $item,
                    ];
                })
                ->values()
                ->all();
        }

        $orders = $assignment->project?->workOrders
            ? $assignment->project->workOrders->where('worker_id', $assignment->worker_id)
            : collect();

        return $this->workItems($assignment)->map(function (WorkItem $item) use ($orders): array {
            $order = $orders->first(fn ($row): bool => (int) $row->work_item_id === (int) $item->id);
            $quantity = $order !== null && (float) $order->assigned_quantity > 0.0001
                ? (float) $order->assigned_quantity
                : (float) $item->ordered_quantity;
            $unit = $order?->unit ?? $item->unit;

            return [
                'title' => $item->planningTitle(),
                'quantity' => Format::qty($quantity, abs($quantity - round($quantity)) < 0.001 ? 0 : 2),
                'quantity_value' => $quantity,
                'unit' => $unit->label(),
                'unit_enum' => $unit,
                'item' => $item,
            ];
        })->values()->all();
    }

    /**
     * @return Collection<int, WorkItem>
     */
    private function workItems(WorkerAssignment $assignment): Collection
    {
        $resolved = $this->resolvedWorkItem($assignment);
        if ($resolved !== null) {
            return collect([$resolved]);
        }

        $project = $assignment->project;
        if ($project === null) {
            return collect();
        }

        $orderedIds = $project->workOrders
            ->where('worker_id', $assignment->worker_id)
            ->pluck('work_item_id')
            ->filter()
            ->unique()
            ->values();
        if ($orderedIds->isNotEmpty()) {
            return $project->workItems->whereIn('id', $orderedIds)->values();
        }

        return collect();
    }

    private function resolvedWorkItem(WorkerAssignment $assignment): ?WorkItem
    {
        if ($assignment->workItem !== null) {
            return $assignment->workItem;
        }

        $id = $assignment->resolvedWorkItemId();
        if ($id === null) {
            return null;
        }

        return $assignment->project?->workItems->firstWhere('id', $id);
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     * @param  list<array{item: ?WorkItem}>  $works
     * @return array{floors: list<string>, rooms: list<string>}
     */
    private function rooms(WorkerAssignment $assignment, array $works, Collection $tickets): array
    {
        if ($tickets->isNotEmpty()) {
            $floors = $tickets
                ->map(fn (WorkTicket $ticket): string => $ticket->floorsLabel())
                ->filter()
                ->unique()
                ->values()
                ->all();
            $rooms = $tickets
                ->map(fn (WorkTicket $ticket): string => $ticket->roomsLabel())
                ->filter(fn (string $label): bool => $label !== '' && $label !== 'Hele verdieping')
                ->flatMap(fn (string $label): array => array_map('trim', explode(',', $label)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            return ['floors' => $floors, 'rooms' => $rooms];
        }

        $floors = [];
        $rooms = [];
        foreach (collect($works)->pluck('item')->filter() as $item) {
            foreach ($item->areaTasks as $task) {
                $area = $task->area;
                if ($area === null) {
                    continue;
                }
                $room = trim($area->label());
                if ($room !== '') {
                    $rooms[] = $room;
                }
                $floor = trim((string) ($area->floor?->name ?? ''));
                if ($floor !== '') {
                    $floors[] = $floor;
                }
            }
        }

        return [
            'floors' => array_values(array_unique($floors)),
            'rooms' => array_values(array_unique($rooms)),
        ];
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     * @return list<string>
     */
    private function notes(WorkerAssignment $assignment, Collection $tickets): array
    {
        $notes = [];
        $own = trim((string) ($assignment->notes ?? ''));
        if ($own !== '') {
            $notes[] = $own;
        }

        foreach ($tickets as $ticket) {
            $text = trim((string) ($ticket->notes ?? ''));
            if ($text !== '') {
                $notes[] = $text;
            }
        }

        return array_values(array_unique($notes));
    }

    /**
     * @param  Collection<int, WorkTicket>  $tickets
     * @return list<ProjectDocument>
     */
    private function drawings(Project $project, Collection $tickets): array
    {
        if ($tickets->isNotEmpty()) {
            return $tickets
                ->flatMap(fn (WorkTicket $ticket) => $ticket->documents)
                ->unique('id')
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @return Collection<int, WorkTicket>
     */
    private function ticketsOnDate(WorkerAssignment $assignment, CarbonInterface $date): Collection
    {
        if (! $assignment->relationLoaded('workTickets')) {
            return collect();
        }

        return $assignment->workTickets
            ->filter(fn (WorkTicket $ticket): bool => $date->betweenIncluded($ticket->start_date, $ticket->end_date))
            ->values();
    }

    private function timeLabel(WorkerAssignment $assignment): string
    {
        return PlanningHours::formatTime($assignment->startTimeValue())
            .' – '
            .PlanningHours::formatTime($assignment->endTimeValue());
    }

    private function dayHeading(CarbonInterface $date): string
    {
        return ucfirst($date->translatedFormat('l j F'));
    }
}
