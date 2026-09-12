<?php

namespace App\Http\Controllers;

use App\Models\CrewMember;
use App\Models\Project;
use App\Models\Team;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\ConflictService;
use App\Services\PlanningFitService;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PlanningActionController extends Controller
{
    public function shift(Request $request): JsonResponse
    {
        Gate::authorize('manage-planning');
        $data = $request->validate([
            'type' => ['required', 'in:project,work,assignment'],
            'id' => ['required', 'integer'],
            'days' => ['required', 'integer'],
            'move_children' => ['sometimes', 'boolean'],
        ]);

        $days = (int) $data['days'];
        if ($days === 0) {
            return response()->json(['ok' => true]);
        }

        if ($data['type'] === 'project') {
            $project = Project::query()->with('workItems')->findOrFail($data['id']);
            Gate::authorize('view', $project);
            $project->planned_start_date = $project->planned_start_date?->addDays($days);
            $project->planned_end_date = $project->planned_end_date?->addDays($days);
            $project->save();

            if ($request->boolean('move_children')) {
                foreach ($project->workItems as $item) {
                    $this->shiftWorkItem($item, $days);
                }
            }

            return response()->json(['ok' => true]);
        }

        if ($data['type'] === 'assignment') {
            $assignment = WorkerAssignment::query()->with('project')->findOrFail($data['id']);
            Gate::authorize('view', $assignment->project);
            $assignment->start_date = $assignment->start_date->addDays($days);
            $assignment->end_date = $assignment->end_date->addDays($days);
            $assignment->save();

            return response()->json(['ok' => true]);
        }

        $item = WorkItem::query()->with('project')->findOrFail($data['id']);
        Gate::authorize('view', $item->project);
        $this->shiftWorkItem($item, $days);

        return response()->json(['ok' => true]);
    }

    public function moveAssignment(Request $request, ConflictService $conflicts): JsonResponse
    {
        Gate::authorize('manage-planning');
        $data = $request->validate([
            'assignment_id' => ['required', 'integer'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            'to_project_id' => ['nullable', 'integer'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ]);

        $assignment = WorkerAssignment::query()->with(['worker', 'project', 'crewMembers'])->findOrFail($data['assignment_id']);
        Gate::authorize('view', $assignment->project);
        $from = Carbon::parse($data['from_date']);
        $to = Carbon::parse($data['to_date']);
        $targetProjectId = $data['to_project_id'] ?? $assignment->project_id;
        if ((int) $targetProjectId !== (int) $assignment->project_id) {
            Gate::authorize('view', Project::query()->findOrFail($targetProjectId));
        }

        $crewIds = $assignment->crewMembers->modelKeys();
        $dayInterval = $assignment->intervalOnDate($from);
        $dayStart = $dayInterval ? $dayInterval[0]->format('H:i:s') : $assignment->startTimeValue();
        $dayEnd = $dayInterval ? $dayInterval[1]->format('H:i:s') : $assignment->endTimeValue();
        $conflict = $conflicts->capacityConflict(
            $assignment->worker_id,
            $to,
            $to,
            $assignment->peopleCount(),
            $assignment->id,
            $crewIds,
            $dayStart,
            $dayEnd,
        );
        if ($conflict && ! $request->boolean('confirm_conflict')) {
            return $this->conflictJson($conflict);
        }

        $this->extractDay($assignment, $from);

        $moved = WorkerAssignment::query()->create([
            'worker_id' => $assignment->worker_id,
            'project_id' => $targetProjectId,
            'work_item_id' => $assignment->work_item_id,
            'team_id' => $assignment->team_id,
            'start_date' => $to->toDateString(),
            'end_date' => $to->toDateString(),
            'people_count' => $assignment->people_count ?: 1,
        ]);
        $moved->applySchedule($to, $to, $dayStart, $dayEnd);
        $moved->save();
        $moved->copyPresentCrewFrom($assignment);

        return response()->json(['ok' => true]);
    }

    public function storeAssignment(Request $request, ConflictService $conflicts, PlanningFitService $fit): JsonResponse
    {
        Gate::authorize('manage-planning');
        $data = $request->validate([
            'worker_id' => ['nullable', 'integer', 'exists:workers,id', 'required_without:team_id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id', 'required_without:worker_id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'work_item_id' => ['required', 'integer', 'exists:work_items,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:50', 'required_without:crew_member_ids'],
            'crew_member_ids' => ['nullable', 'array', 'max:50'],
            'crew_member_ids.*' => ['integer', 'distinct', 'exists:crew_members,id'],
            'crew_hours' => ['nullable', 'array'],
            'crew_hours.*' => ['numeric', 'min:2', 'max:8'],
            'hours' => ['nullable', 'numeric', 'min:2', 'max:8'],
            'slot' => ['nullable', 'in:morning,afternoon,full'],
            'start_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ]);

        $item = WorkItem::query()
            ->with('project')
            ->where('project_id', $data['project_id'])
            ->findOrFail($data['work_item_id']);
        Gate::authorize('view', $item->project);

        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $teamId = $data['team_id'] ?? null;
        $workers = $this->workersForRequest($data);
        if ($workers->isEmpty()) {
            return response()->json(['message' => 'Deze ploeg heeft geen vakmensen.'], 422);
        }

        $crewIds = $teamId ? [] : $this->crewIdsForWorker($workers->first(), $data['crew_member_ids'] ?? []);
        if ($crewIds === null) {
            return response()->json(['message' => 'Deze personen horen niet bij dit team.'], 422);
        }

        if ($teamId) {
            $workers = $workers
                ->filter(fn (Worker $worker): bool => $fit->skillRejection($worker, $item) === null)
                ->values();
            if ($workers->isEmpty()) {
                return response()->json([
                    'message' => 'Deze ploeg heeft niemand met vakkennis voor '.$item->requiredSpecialty()['label'].'.',
                ], 422);
            }
        } else {
            $message = $fit->skillRejection($workers->first(), $item, $crewIds);
            if ($message) {
                return response()->json(['message' => $message], 422);
            }
        }

        foreach ($workers as $worker) {
            $message = $fit->awayRejection($worker, $start, $end);
            if ($message) {
                return response()->json(['message' => $message], 422);
            }
        }

        $groups = $this->scheduleGroups($data, $crewIds, $teamId ? 1 : (int) ($data['people_count'] ?? 1));
        foreach ($groups as $group) {
            $blocked = $this->firstConflict(
                $conflicts,
                $workers,
                $start,
                $end,
                $group['people_count'],
                $request->boolean('confirm_conflict'),
                $group['crew_ids'],
                $group['start_time'],
                $group['end_time'],
            );
            if ($blocked) {
                return $blocked;
            }
        }

        foreach ($workers as $worker) {
            foreach ($groups as $group) {
                $ids = $teamId || $workers->count() > 1 ? [] : $group['crew_ids'];
                $this->createScheduledAssignment(
                    $worker->id,
                    $item->project_id,
                    $item->id,
                    $teamId,
                    $start,
                    $end,
                    $group['start_time'],
                    $group['end_time'],
                    $teamId ? 1 : $group['people_count'],
                    $ids,
                );
            }
        }

        return response()->json(['ok' => true]);
    }

    public function updateAssignment(Request $request, WorkerAssignment $assignment, ConflictService $conflicts, PlanningFitService $fit): JsonResponse
    {
        Gate::authorize('manage-planning');
        Gate::authorize('view', $assignment->project);
        $data = $request->validate([
            'worker_id' => ['sometimes', 'integer', 'exists:workers,id'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'work_item_id' => ['sometimes', 'nullable', 'integer', 'exists:work_items,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'people_count' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'crew_member_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'crew_member_ids.*' => ['integer', 'distinct', 'exists:crew_members,id'],
            'crew_hours' => ['nullable', 'array'],
            'crew_hours.*' => ['numeric', 'min:2', 'max:8'],
            'hours' => ['nullable', 'numeric', 'min:2', 'max:8'],
            'slot' => ['nullable', 'in:morning,afternoon,full'],
            'start_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ]);

        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $workerId = (int) ($data['worker_id'] ?? $assignment->worker_id);
        $assignment->loadMissing(['crewMembers', 'workItem.workActivity', 'project']);

        $worker = Worker::query()->with(['crewPeople', 'availabilities'])->findOrFail($workerId);
        $originalCrewIds = $assignment->crewMembers
            ->map(fn (CrewMember $member): int => (int) $member->id)
            ->values()
            ->all();
        $crewIds = array_key_exists('crew_member_ids', $data)
            ? $this->crewIdsForWorker($worker, $data['crew_member_ids'] ?? [])
            : $originalCrewIds;
        if ($crewIds === null) {
            return response()->json(['message' => 'Deze personen horen niet bij dit team.'], 422);
        }

        $targetItem = $this->targetWorkItem($assignment, $data);
        if ($targetItem) {
            Gate::authorize('view', $targetItem->project);
            $message = $fit->skillRejection($worker, $targetItem, $crewIds);
            if ($message) {
                return response()->json(['message' => $message], 422);
            }
        }

        $isMove = $targetItem !== null && (
            (int) $targetItem->id !== (int) $assignment->work_item_id
            || (int) $targetItem->project_id !== (int) $assignment->project_id
        );
        $stayingIds = ($isMove && array_key_exists('crew_member_ids', $data))
            ? array_values(array_diff($originalCrewIds, $crewIds))
            : [];
        if ($isMove && array_key_exists('crew_member_ids', $data) && $crewIds === [] && $originalCrewIds !== []) {
            return response()->json(['message' => 'Vink aan wie er naar dit werk gaat.'], 422);
        }

        $away = $fit->awayRejection($worker, $start, $end);
        if ($away) {
            return response()->json(['message' => $away], 422);
        }

        $keepTimes = ! array_key_exists('hours', $data)
            && ! array_key_exists('slot', $data)
            && ! array_key_exists('start_time', $data)
            && ! array_key_exists('end_time', $data)
            && empty($data['crew_hours']);
        $defaultTimes = $keepTimes
            ? [
                'start_time' => $assignment->startTimeValue(),
                'end_time' => $assignment->endTimeValue(),
                'hours' => (int) round((float) $assignment->hours_per_day ?: PlanningHours::WORKDAY_HOURS),
            ]
            : PlanningHours::resolve(
                $data['hours'] ?? null,
                $data['slot'] ?? null,
                $data['start_time'] ?? null,
                $data['end_time'] ?? null,
            );
        $data['hours'] = $data['hours'] ?? $defaultTimes['hours'];
        $data['start_time'] = $data['start_time'] ?? PlanningHours::formatTime($defaultTimes['start_time']);
        $data['end_time'] = $data['end_time'] ?? PlanningHours::formatTime($defaultTimes['end_time']);

        $groups = $this->scheduleGroups(
            $data,
            $crewIds,
            $crewIds !== []
                ? count($crewIds)
                : (array_key_exists('people_count', $data) ? (int) $data['people_count'] : $assignment->peopleCount()),
        );
        $first = array_shift($groups) ?? [
            'start_time' => $defaultTimes['start_time'],
            'end_time' => $defaultTimes['end_time'],
            'people_count' => $crewIds !== [] ? count($crewIds) : (array_key_exists('people_count', $data) ? (int) $data['people_count'] : $assignment->peopleCount()),
            'crew_ids' => $crewIds,
        ];

        foreach (array_merge([$first], $groups) as $index => $group) {
            $ignoreId = $index === 0 ? $assignment->id : null;
            $conflict = $conflicts->capacityConflict(
                $workerId,
                $start,
                $end,
                $group['people_count'],
                $ignoreId,
                $group['crew_ids'],
                $group['start_time'],
                $group['end_time'],
            );
            if ($conflict && ! $request->boolean('confirm_conflict')) {
                return $this->conflictJson($conflict);
            }
        }

        $staySnapshot = $stayingIds === [] ? null : [
            'worker_id' => (int) $assignment->worker_id,
            'project_id' => (int) $assignment->project_id,
            'work_item_id' => $assignment->work_item_id,
            'team_id' => $assignment->team_id,
            'start' => $assignment->start_date->copy(),
            'end' => $assignment->end_date->copy(),
            'start_time' => $assignment->startTimeValue(),
            'end_time' => $assignment->endTimeValue(),
            'hours_by_id' => $this->crewScheduleSnapshot($assignment, $stayingIds),
        ];

        $originalWorkerId = (int) $assignment->worker_id;
        $targetProjectId = $targetItem ? (int) $targetItem->project_id : (int) $assignment->project_id;
        $targetWorkItemId = $targetItem?->id ?? $assignment->work_item_id;

        DB::transaction(function () use (
            $assignment,
            $data,
            $first,
            $groups,
            $start,
            $end,
            $workerId,
            $originalWorkerId,
            $targetProjectId,
            $targetWorkItemId,
            $staySnapshot,
            $stayingIds,
        ): void {
            $assignment->project_id = $targetProjectId;
            $assignment->work_item_id = $targetWorkItemId;
            $assignment->worker_id = $workerId;
            $assignment->people_count = $first['people_count'];
            $assignment->applySchedule($start, $end, $first['start_time'], $first['end_time']);
            $assignment->save();
            if (array_key_exists('crew_member_ids', $data) || $first['crew_ids'] !== [] || $stayingIds !== []) {
                $assignment->syncPresentCrew($first['crew_ids']);
            } elseif ($originalWorkerId !== $workerId) {
                $assignment->crewMembers()->sync([]);
            } else {
                $assignment->syncPresentCrew($assignment->crewMembers->modelKeys());
            }

            foreach ($groups as $group) {
                $this->createScheduledAssignment(
                    $workerId,
                    $assignment->project_id,
                    $assignment->work_item_id,
                    $assignment->team_id,
                    $start,
                    $end,
                    $group['start_time'],
                    $group['end_time'],
                    $group['people_count'],
                    $group['crew_ids'],
                );
            }

            if ($staySnapshot !== null) {
                $staying = $this->createScheduledAssignment(
                    $staySnapshot['worker_id'],
                    $staySnapshot['project_id'],
                    $staySnapshot['work_item_id'],
                    $staySnapshot['team_id'],
                    $staySnapshot['start'],
                    $staySnapshot['end'],
                    $staySnapshot['start_time'],
                    $staySnapshot['end_time'],
                    count($stayingIds),
                    $stayingIds,
                );
                if ($staySnapshot['hours_by_id'] !== []) {
                    $staying->syncPresentCrew($stayingIds, $staySnapshot['hours_by_id']);
                }
            }
        });

        return response()->json(['ok' => true]);
    }

    public function candidates(Request $request, PlanningFitService $fit): JsonResponse
    {
        Gate::authorize('manage-planning');
        $data = $request->validate([
            'work_item_id' => ['required', 'integer', 'exists:work_items,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'hours' => ['nullable', 'numeric', 'min:2', 'max:8'],
            'slot' => ['nullable', 'in:morning,afternoon,full'],
            'assignment_id' => ['nullable', 'integer', 'exists:worker_assignments,id'],
        ]);

        $item = WorkItem::query()->with(['project', 'workActivity'])->findOrFail($data['work_item_id']);
        Gate::authorize('view', $item->project);

        $times = PlanningHours::resolve(
            $data['hours'] ?? null,
            $data['slot'] ?? null,
            $data['start_time'] ?? null,
            $data['end_time'] ?? null,
        );

        return response()->json($fit->candidates(
            $item,
            Carbon::parse($data['start_date']),
            Carbon::parse($data['end_date']),
            $data['start_time'] ?? $times['start_time'],
            $data['end_time'] ?? $times['end_time'],
            isset($data['assignment_id']) ? (int) $data['assignment_id'] : null,
        ));
    }

    public function destroyAssignment(WorkerAssignment $assignment): JsonResponse
    {
        Gate::authorize('manage-planning');
        Gate::authorize('view', $assignment->project);
        $assignment->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function targetWorkItem(WorkerAssignment $assignment, array $data): ?WorkItem
    {
        if (! array_key_exists('work_item_id', $data) || ! $data['work_item_id']) {
            return $assignment->workItem;
        }

        $query = WorkItem::query()->with(['project', 'workActivity']);
        if (! empty($data['project_id'])) {
            $query->where('project_id', $data['project_id']);
        }

        return $query->findOrFail($data['work_item_id']);
    }

    /**
     * @param  list<int>  $crewIds
     * @return array<int, array{start_time: string, end_time: string, planned_hours: float}>
     */
    private function crewScheduleSnapshot(WorkerAssignment $assignment, array $crewIds): array
    {
        $wanted = array_flip($crewIds);
        $hours = [];
        foreach ($assignment->crewMembers as $member) {
            $id = (int) $member->id;
            if (! isset($wanted[$id])) {
                continue;
            }
            $hours[$id] = [
                'start_time' => PlanningHours::normalizeTime($member->pivot?->start_time, $assignment->startTimeValue()),
                'end_time' => PlanningHours::normalizeTime($member->pivot?->end_time, $assignment->endTimeValue()),
                'planned_hours' => (float) ($member->pivot?->planned_hours ?: $assignment->plannedHoursValue()),
            ];
        }

        return $hours;
    }

    private function workersForRequest(array $data): Collection
    {
        if (! empty($data['team_id'])) {
            return Team::query()->with(['workers.availabilities', 'workers.crewPeople'])->findOrFail($data['team_id'])->workers;
        }

        return collect([Worker::query()->with(['availabilities', 'crewPeople'])->findOrFail($data['worker_id'])]);
    }

    /**
     * @param  list<mixed>  $requested
     * @return list<int>|null
     */
    private function crewIdsForWorker(Worker $worker, mixed $requested): ?array
    {
        $ids = [];
        foreach ((array) $requested as $id) {
            $value = (int) $id;
            if ($value > 0 && ! in_array($value, $ids, true)) {
                $ids[] = $value;
            }
        }
        if ($ids === []) {
            return [];
        }

        $owned = $worker->crewPeople()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        if (count($owned) !== count($ids)) {
            return null;
        }

        return $ids;
    }

    /**
     * @param  Collection<int, Worker>  $workers
     * @param  list<int>  $crewIds
     */
    private function firstConflict(
        ConflictService $conflicts,
        Collection $workers,
        Carbon $start,
        Carbon $end,
        int $peopleCount,
        bool $confirm,
        array $crewIds = [],
        ?string $startTime = null,
        ?string $endTime = null,
    ): ?JsonResponse {
        if ($confirm) {
            return null;
        }

        foreach ($workers as $worker) {
            $ids = $crewIds !== [] && $workers->count() === 1 ? $crewIds : [];
            $conflict = $conflicts->capacityConflict(
                $worker->id,
                $start,
                $end,
                $peopleCount,
                null,
                $ids,
                $startTime,
                $endTime,
            );
            if ($conflict) {
                return $this->conflictJson($conflict);
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $crewIds
     * @return list<array{start_time: string, end_time: string, people_count: int, crew_ids: list<int>}>
     */
    private function scheduleGroups(array $data, array $crewIds, int $peopleCount): array
    {
        $default = PlanningHours::resolve(
            $data['hours'] ?? null,
            $data['slot'] ?? null,
            $data['start_time'] ?? null,
            $data['end_time'] ?? null,
        );
        $hoursById = [];
        foreach ($data['crew_hours'] ?? [] as $id => $hours) {
            $hoursById[(int) $id] = (float) $hours;
        }

        if ($crewIds === [] || $hoursById === []) {
            return [[
                'start_time' => $default['start_time'],
                'end_time' => $default['end_time'],
                'people_count' => $crewIds !== [] ? count($crewIds) : max(1, $peopleCount),
                'crew_ids' => $crewIds,
            ]];
        }

        $groups = [];
        foreach ($crewIds as $id) {
            $personHours = $hoursById[$id] ?? $default['hours'];
            $resolved = (int) round($personHours) === (int) $default['hours']
                ? $default
                : PlanningHours::resolve($personHours, $data['slot'] ?? null, null, null);
            $key = $resolved['start_time'].'|'.$resolved['end_time'];
            $groups[$key] ??= [
                'start_time' => $resolved['start_time'],
                'end_time' => $resolved['end_time'],
                'crew_ids' => [],
            ];
            $groups[$key]['crew_ids'][] = $id;
        }

        return array_values(array_map(function (array $group): array {
            $group['people_count'] = count($group['crew_ids']);

            return $group;
        }, $groups));
    }

    /**
     * @param  list<int>  $crewIds
     */
    private function createScheduledAssignment(
        int $workerId,
        int $projectId,
        ?int $workItemId,
        ?int $teamId,
        Carbon $start,
        Carbon $end,
        string $startTime,
        string $endTime,
        int $peopleCount,
        array $crewIds,
    ): WorkerAssignment {
        $startTime = PlanningHours::normalizeTime($startTime, PlanningHours::DAY_START);
        $endTime = PlanningHours::normalizeTime($endTime, PlanningHours::DAY_END);
        $fingerprint = implode(':', [
            $workerId,
            $projectId,
            $workItemId ?? 0,
            $start->toDateString(),
            $end->toDateString(),
            $startTime,
            $endTime,
        ]);

        return Cache::lock('planning-assignment:'.$fingerprint, 15)->block(10, function () use (
            $workerId,
            $projectId,
            $workItemId,
            $teamId,
            $start,
            $end,
            $startTime,
            $endTime,
            $peopleCount,
            $crewIds,
        ): WorkerAssignment {
            return DB::transaction(function () use (
                $workerId,
                $projectId,
                $workItemId,
                $teamId,
                $start,
                $end,
                $startTime,
                $endTime,
                $peopleCount,
                $crewIds,
            ): WorkerAssignment {
                $existing = WorkerAssignment::query()
                    ->where('worker_id', $workerId)
                    ->where('project_id', $projectId)
                    ->when(
                        $workItemId === null,
                        fn ($query) => $query->whereNull('work_item_id'),
                        fn ($query) => $query->where('work_item_id', $workItemId),
                    )
                    ->whereDate('start_date', $start->toDateString())
                    ->whereDate('end_date', $end->toDateString())
                    ->where('start_time', $startTime)
                    ->where('end_time', $endTime)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $assignment = new WorkerAssignment([
                    'worker_id' => $workerId,
                    'project_id' => $projectId,
                    'work_item_id' => $workItemId,
                    'team_id' => $teamId,
                    'people_count' => max(1, $peopleCount),
                ]);
                $assignment->applySchedule($start, $end, $startTime, $endTime);
                $assignment->save();
                if ($crewIds !== []) {
                    $assignment->syncPresentCrew($crewIds);
                }

                return $assignment;
            });
        });
    }

    /**
     * @param  array{worker: Worker, overlaps: Collection<int, WorkerAssignment>, used: int, capacity: int, person?: string}  $conflict
     */
    private function conflictJson(array $conflict): JsonResponse
    {
        $names = $conflict['overlaps']->map(fn (WorkerAssignment $row) => $row->project?->name)->filter()->unique()->implode(', ');
        if (! empty($conflict['person'])) {
            $message = $names !== ''
                ? $conflict['person'].' staat die dag al op '.$names.'. Toch doorgaan?'
                : $conflict['person'].' staat die dag al ergens anders ingepland. Toch doorgaan?';
        } else {
            $who = $conflict['worker']->displayName();
            $used = $conflict['used'];
            $capacity = $conflict['capacity'];
            $message = $names !== ''
                ? $who.' heeft die dag '.$used.' van '.$capacity.' personen ingepland op '.$names.'. Toch doorgaan?'
                : $who.' heeft '.$used.' personen, maar het team is '.$capacity.' personen. Toch doorgaan?';
        }

        return response()->json([
            'ok' => false,
            'conflict' => true,
            'message' => $message,
        ], 409);
    }

    private function shiftWorkItem(WorkItem $item, int $days): void
    {
        $item->planned_start_date = $item->planned_start_date?->addDays($days);
        $item->planned_end_date = $item->planned_end_date?->addDays($days);
        $item->save();
    }

    private function extractDay(WorkerAssignment $assignment, Carbon $date): void
    {
        $assignment->loadMissing('crewMembers');
        if ($assignment->start_date->isSameDay($assignment->end_date) && $assignment->start_date->isSameDay($date)) {
            $assignment->delete();

            return;
        }

        $crewIds = $assignment->crewMembers->modelKeys();
        $startTime = $assignment->startTimeValue();
        $endTime = $assignment->endTimeValue();

        if ($assignment->start_date->isSameDay($date)) {
            $assignment->applySchedule(
                $assignment->start_date->copy()->addDay(),
                $assignment->end_date,
                PlanningHours::DAY_START.':00',
                $endTime,
            );
            $assignment->save();
            $assignment->syncPresentCrew($crewIds);

            return;
        }

        if ($assignment->end_date->isSameDay($date)) {
            $assignment->applySchedule(
                $assignment->start_date,
                $assignment->end_date->copy()->subDay(),
                $startTime,
                PlanningHours::DAY_END.':00',
            );
            $assignment->save();
            $assignment->syncPresentCrew($crewIds);

            return;
        }

        $end = $assignment->end_date->copy();
        $assignment->applySchedule(
            $assignment->start_date,
            $date->copy()->subDay(),
            $startTime,
            PlanningHours::DAY_END.':00',
        );
        $assignment->save();
        $assignment->syncPresentCrew($crewIds);

        $this->createScheduledAssignment(
            (int) $assignment->worker_id,
            (int) $assignment->project_id,
            $assignment->work_item_id,
            $assignment->team_id,
            $date->copy()->addDay(),
            $end,
            PlanningHours::DAY_START.':00',
            $endTime,
            $assignment->people_count ?: 1,
            $crewIds,
        );
    }
}
