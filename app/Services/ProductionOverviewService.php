<?php

namespace App\Services;

use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkProgressEntry;
use App\Support\MaterialColor;
use Illuminate\Support\Collection;

class ProductionOverviewService
{
    /**
     * @return array{
     *     groups: Collection<int, array<string, mixed>>,
     *     totals: array{m2: float, m1: float, rooms: int, workers: int, pending: int},
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
}
