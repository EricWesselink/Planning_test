<?php

namespace App\Services;

use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkProgressEntry;
use App\Models\WorkTicket;
use App\Support\Format;
use App\Support\MaterialColor;
use Illuminate\Support\Collection;

class ProductionOverviewService
{
    /**
     * @return array{
     *     groups: Collection<int, array<string, mixed>>,
     *     totals: array{m2: float, m1: float, rooms: int, workers: int, pending: int, hours_pending: int},
     *     workers: Collection<int, Worker>,
     *     projects: Collection<int, Project>
     * }
     */
    public function build(?int $workerId = null, ?int $projectId = null, ?string $from = null, ?string $to = null, ?User $user = null): array
    {
        $scheduledWorkerId = $user?->scheduledWorkerId();
        if ($scheduledWorkerId !== null) {
            $workerId = $scheduledWorkerId;
        }

        $entries = WorkProgressEntry::query()
            ->with(['worker', 'project', 'area.tasks', 'workItem'])
            ->when($workerId, fn ($query) => $query->where('worker_id', $workerId))
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->when(! $projectId, fn ($query) => $query->whereHas('project', fn ($q) => $q->active()))
            ->when($user, fn ($query) => $query->whereHas('project', fn ($q) => $q->accessibleBy($user)))
            ->when($from, fn ($query) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('date', '<=', $to))
            ->orderBy('date')
            ->get()
            ->filter(fn (WorkProgressEntry $entry) => $entry->worker && $entry->project);

        $groups = $entries
            ->groupBy('worker_id')
            ->map(fn (Collection $workerEntries) => $this->workerGroup($workerEntries))
            ->values();

        $groups = $this->mergeTickets($groups, $workerId, $projectId, $from, $to, $user)
            ->sortBy(fn (array $group) => mb_strtolower($group['worker']->displayName()))
            ->values();

        return [
            'groups' => $groups,
            'totals' => [
                'm2' => (float) $groups->sum('total_m2'),
                'm1' => (float) $groups->sum('total_m1'),
                'rooms' => (int) $groups->sum('room_count'),
                'workers' => $groups->count(),
                'pending' => (int) $groups->sum('provisional_count'),
                'hours_pending' => (int) $groups->sum('hours_pending'),
            ],
            'workers' => Worker::query()
                ->when($scheduledWorkerId, fn ($query) => $query->whereKey($scheduledWorkerId))
                ->orderBy('name')
                ->get(),
            'projects' => Project::query()
                ->when($user, fn ($query) => $query->accessibleBy($user))
                ->where(function ($query) use ($projectId) {
                    $query->active();
                    if ($projectId) {
                        $query->orWhere('id', $projectId);
                    }
                })
                ->orderBy('name')
                ->get(),
        ];
    }

    /** @param Collection<int, WorkProgressEntry> $entries */
    private function workerGroup(Collection $entries): array
    {
        $projects = $entries
            ->groupBy('project_id')
            ->map(fn (Collection $projectEntries) => $this->projectGroup($projectEntries))
            ->sortBy(fn (array $group) => mb_strtolower($group['project']->name))
            ->values();

        return [
            'worker' => $entries->first()->worker,
            'projects' => $projects,
            'total_m2' => (float) $projects->sum('total_m2'),
            'total_m1' => (float) $projects->sum('total_m1'),
            'room_count' => (int) $projects->sum('room_count'),
            'provisional_count' => (int) $projects->sum('provisional_count'),
            'hours_pending' => (int) $projects->sum('hours_pending'),
        ];
    }

    /** @param Collection<int, WorkProgressEntry> $entries */
    private function projectGroup(Collection $entries): array
    {
        $rooms = $entries
            ->groupBy(fn (WorkProgressEntry $entry) => $entry->project_area_id ?: 'none')
            ->map(fn (Collection $roomEntries) => $this->roomGroup($roomEntries))
            ->sortBy(function (array $room) {
                $area = $room['area'];

                return $area
                    ? ProjectArea::numberSortKey($area->displayNumber()).'-'.mb_strtolower($area->displayName())
                    : 'zzz';
            })
            ->values();

        return [
            'project' => $entries->first()->project,
            'rooms' => $rooms,
            'room_count' => $rooms->count(),
            'total_m2' => (float) $entries
                ->filter(fn (WorkProgressEntry $entry) => $entry->unit === WorkUnit::SquareMeter)
                ->sum('completed_quantity'),
            'total_m1' => (float) $entries
                ->filter(fn (WorkProgressEntry $entry) => $entry->unit === WorkUnit::LinearMeter)
                ->sum('completed_quantity'),
            'provisional_task_ids' => $rooms
                ->flatMap(fn (array $room) => collect($room['materials'])
                    ->filter(fn (array $material) => $material['provisional'] && $material['task_id'])
                    ->pluck('task_id'))
                ->unique()
                ->values()
                ->all(),
            'provisional_count' => $rooms
                ->sum(fn (array $room) => collect($room['materials'])->where('provisional', true)->count()),
            'hours_pending' => 0,
            'tickets' => [],
            'rooms_from_ticket' => false,
        ];
    }

    /** @param Collection<int, WorkProgressEntry> $entries */
    private function roomGroup(Collection $entries): array
    {
        $area = $entries->first()->area;
        $materials = $entries
            ->groupBy('work_item_id')
            ->map(function (Collection $group) use ($area) {
                $item = $group->first()->workItem;
                $unit = $group->first()->unit ?? $item?->unit;
                $task = $area?->tasks->first(
                    fn (AreaTask $areaTask): bool => (int) $areaTask->work_item_id === (int) ($item?->id ?? 0)
                );
                $klaarOn = $task?->completed_at?->format('d-m-Y')
                    ?? $group->sortByDesc('date')->first()?->date?->format('d-m-Y');
                $provisional = $task?->isProvisional() ?? false;
                $approved = $task?->isApproved() ?? false;

                return [
                    'label' => $item?->cardLabel() ?? $item?->name ?? 'Werk',
                    'quantity' => (float) $group->sum('completed_quantity'),
                    'unit' => $unit,
                    'display_color' => $item?->displayColor() ?? MaterialColor::resolve(null, $item?->name),
                    'work_item' => $item,
                    'work_item_id' => $item?->id,
                    'task_id' => $task?->id,
                    'provisional' => $provisional,
                    'approved' => $approved,
                    'klaar_on' => $klaarOn,
                    'status_label' => $provisional ? 'Klaar gemeld' : ($approved ? 'Akkoord' : null),
                ];
            })
            ->sortBy('label')
            ->values();

        return [
            'area' => $area,
            'label' => $area?->label() ?: 'Zonder ruimte',
            'area_m2' => $area ? (float) $area->square_meters : 0.0,
            'materials' => $materials,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return Collection<int, array<string, mixed>>
     */
    private function mergeTickets(
        Collection $groups,
        ?int $workerId,
        ?int $projectId,
        ?string $from,
        ?string $to,
        ?User $user,
    ): Collection {
        $tickets = WorkTicket::query()
            ->with([
                'worker',
                'project',
                'lines.workItem',
                'areas.floor',
                'areas.tasks.workItem',
            ])
            ->when($workerId, fn ($query) => $query->where('worker_id', $workerId))
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->when(! $projectId, fn ($query) => $query->whereHas('project', fn ($projects) => $projects->active()))
            ->when($user, fn ($query) => $query->whereHas('project', fn ($projects) => $projects->accessibleBy($user)))
            ->when($from, fn ($query) => $query->whereDate('end_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('start_date', '<=', $to))
            ->orderByDesc('id')
            ->get()
            ->filter(fn (WorkTicket $ticket): bool => $ticket->worker !== null && $ticket->project !== null);

        if ($tickets->isEmpty()) {
            return $groups;
        }

        $byWorker = $groups->keyBy(fn (array $group): int => (int) $group['worker']->id);

        foreach ($tickets as $ticket) {
            $workerKey = (int) $ticket->worker_id;
            if (! $byWorker->has($workerKey)) {
                $byWorker[$workerKey] = [
                    'worker' => $ticket->worker,
                    'projects' => collect(),
                    'total_m2' => 0.0,
                    'total_m1' => 0.0,
                    'room_count' => 0,
                    'provisional_count' => 0,
                    'hours_pending' => 0,
                ];
            }

            $workerGroup = $byWorker[$workerKey];
            $projects = collect($workerGroup['projects'])->keyBy(fn (array $projectGroup): int => (int) $projectGroup['project']->id);
            $projectKey = (int) $ticket->project_id;
            if (! $projects->has($projectKey)) {
                $projects[$projectKey] = [
                    'project' => $ticket->project,
                    'rooms' => collect(),
                    'room_count' => 0,
                    'total_m2' => 0.0,
                    'total_m1' => 0.0,
                    'provisional_task_ids' => [],
                    'provisional_count' => 0,
                    'hours_pending' => 0,
                    'tickets' => [],
                    'rooms_from_ticket' => false,
                ];
            }

            $projectGroup = $projects[$projectKey];
            if (collect($projectGroup['rooms'])->isEmpty()) {
                $rooms = $this->roomsFromTicket($ticket);
                $projectGroup['rooms'] = $rooms;
                $projectGroup['rooms_from_ticket'] = true;
                $projectGroup['room_count'] = $rooms->count();
                $projectGroup['total_m2'] = (float) $ticket->lines
                    ->filter(fn ($line): bool => $line->unit === WorkUnit::SquareMeter)
                    ->sum('quantity');
                $projectGroup['total_m1'] = (float) $ticket->lines
                    ->filter(fn ($line): bool => $line->unit === WorkUnit::LinearMeter)
                    ->sum('quantity');
            }

            $summary = $this->ticketSummary($ticket);
            $projectGroup['tickets'][] = $summary;
            if ($summary['hours_submitted']) {
                $projectGroup['hours_pending']++;
            }
            $projects[$projectKey] = $projectGroup;

            $workerGroup['projects'] = $projects->sortBy(fn (array $row): string => mb_strtolower($row['project']->name))->values();
            $workerGroup['total_m2'] = (float) $workerGroup['projects']->sum('total_m2');
            $workerGroup['total_m1'] = (float) $workerGroup['projects']->sum('total_m1');
            $workerGroup['room_count'] = (int) $workerGroup['projects']->sum('room_count');
            $workerGroup['provisional_count'] = (int) $workerGroup['projects']->sum('provisional_count');
            $workerGroup['hours_pending'] = (int) $workerGroup['projects']->sum('hours_pending');
            $byWorker[$workerKey] = $workerGroup;
        }

        return $byWorker->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function roomsFromTicket(WorkTicket $ticket): Collection
    {
        $itemIds = $ticket->lines->pluck('work_item_id')->map(fn ($id): int => (int) $id)->all();

        if ($ticket->areas->isEmpty()) {
            return collect([$this->ticketLinesAsRoom($ticket, null, $ticket->floorsLabel() ?: 'Opdracht')]);
        }

        return $ticket->areas
            ->map(fn (ProjectArea $area): array => $this->ticketAreaRoom($ticket, $area, $itemIds))
            ->values();
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<string, mixed>
     */
    private function ticketAreaRoom(WorkTicket $ticket, ProjectArea $area, array $itemIds): array
    {
        $materials = $area->tasks
            ->filter(fn (AreaTask $task): bool => in_array((int) $task->work_item_id, $itemIds, true))
            ->map(fn (AreaTask $task): array => [
                'label' => $task->workItem?->cardLabel() ?? $task->workItem?->name ?? 'Werk',
                'quantity' => (float) $task->ordered_quantity,
                'unit' => $task->unit,
                'display_color' => $task->workItem?->displayColor() ?? MaterialColor::resolve(null, $task->workItem?->name),
                'work_item' => $task->workItem,
                'work_item_id' => $task->work_item_id,
                'task_id' => $task->id,
                'provisional' => false,
                'approved' => false,
                'klaar_on' => null,
                'status_label' => $ticket->worked_hours !== null ? 'Uren teruggestuurd' : 'Op opdrachtbon',
            ])
            ->values();

        if ($materials->isEmpty()) {
            return $this->ticketLinesAsRoom($ticket, $area, $area->label());
        }

        return [
            'area' => $area,
            'label' => $area->label(),
            'area_m2' => (float) $area->square_meters,
            'materials' => $materials,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketLinesAsRoom(WorkTicket $ticket, ?ProjectArea $area, string $label): array
    {
        return [
            'area' => $area,
            'label' => $label !== '' ? $label : 'Opdracht',
            'area_m2' => $area ? (float) $area->square_meters : 0.0,
            'materials' => $ticket->lines
                ->map(fn ($line): array => [
                    'label' => $line->workItem?->cardLabel() ?? $line->workItem?->name ?? 'Werk',
                    'quantity' => (float) $line->quantity,
                    'unit' => $line->unit,
                    'display_color' => $line->workItem?->displayColor() ?? MaterialColor::resolve(null, $line->workItem?->name),
                    'work_item' => $line->workItem,
                    'work_item_id' => $line->work_item_id,
                    'task_id' => null,
                    'provisional' => false,
                    'approved' => false,
                    'klaar_on' => null,
                    'status_label' => $ticket->worked_hours !== null ? 'Uren teruggestuurd' : 'Op opdrachtbon',
                ])
                ->values(),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     number: string,
     *     kind_label: string,
     *     url: string,
     *     period: string,
     *     hours: ?float,
     *     hours_submitted: bool,
     *     summary: string,
     *     destroy_url: string,
     *     voucher_query: array{worker_id: int, project_id: int, from: string, to: string}
     * }
     */
    private function ticketSummary(WorkTicket $ticket): array
    {
        $names = $ticket->lines
            ->map(fn ($line): string => trim((string) ($line->workItem?->cardLabel() ?? $line->workItem?->name ?? '')))
            ->filter()
            ->unique()
            ->values();
        $works = $names->take(2)->implode(', ');
        if ($names->count() > 2) {
            $works .= ' e.a.';
        }

        $roomCount = $ticket->areas->count();
        $squareMeters = (float) $ticket->lines
            ->filter(fn ($line): bool => $line->unit === WorkUnit::SquareMeter)
            ->sum('quantity');
        $bits = array_values(array_filter([
            $works !== '' ? $works : null,
            $roomCount > 0 ? $roomCount.' '.($roomCount === 1 ? 'ruimte' : 'ruimtes') : null,
            $squareMeters > 0.0001 ? Format::qty($squareMeters, 2).' m²' : null,
        ]));

        return [
            'id' => (int) $ticket->id,
            'number' => $ticket->number,
            'kind_label' => $ticket->kind->label(),
            'url' => route('work-tickets.show', $ticket),
            'destroy_url' => route('work-tickets.destroy', $ticket),
            'period' => $ticket->dateRangeLabel(),
            'hours' => $ticket->worked_hours !== null ? (float) $ticket->worked_hours : null,
            'hours_submitted' => $ticket->worked_hours !== null,
            'summary' => implode(' · ', $bits),
            'voucher_query' => [
                'worker_id' => (int) $ticket->worker_id,
                'project_id' => (int) $ticket->project_id,
                'from' => $ticket->start_date->toDateString(),
                'to' => $ticket->end_date->toDateString(),
            ],
        ];
    }
}
