<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentKind;
use App\Enums\InternalBusinessUnit;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\Team;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Services\ConflictService;
use App\Services\PlanningFitService;
use App\Support\PlanningHours;
use App\Support\PlanningWeek;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PlanningActionController extends Controller
{
    public function shift(Request $request): JsonResponse
    {
        Gate::authorize('planning-drag');
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
        Gate::authorize('planning-drag');
        $data = $request->validate([
            'assignment_id' => ['required', 'integer'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
            'to_project_id' => ['nullable', 'integer'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ]);

        $assignment = WorkerAssignment::query()->with(['worker', 'project', 'crewMembers'])->findOrFail($data['assignment_id']);
        if ($assignment->isInternal()) {
            return response()->json([
                'message' => 'Interne inzet blijft bij het bedrijfsonderdeel. Pas de periode aan via de balk of het formulier.',
            ], 422);
        }
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
            false,
            false,
            (int) $targetProjectId,
            $assignment->work_item_id ? (int) $assignment->work_item_id : null,
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
            'include_saturday' => $assignment->includesSaturday(),
            'include_sunday' => $assignment->includesSunday(),
        ]);
        $moved->applySchedule($to, $to, $dayStart, $dayEnd);
        $moved->save();
        $moved->copyPresentCrewFrom($assignment);

        return response()->json(['ok' => true]);
    }

    public function storeAssignment(Request $request, ConflictService $conflicts, PlanningFitService $fit): JsonResponse
    {
        Gate::authorize('planning-assign');
        $data = $request->validate([
            'worker_id' => ['nullable', 'integer', 'exists:workers,id', 'required_without:team_id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id', 'required_without:worker_id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'work_item_id' => ['nullable', 'integer', 'exists:work_items,id', 'required_without:work_item_ids'],
            'work_item_ids' => ['nullable', 'array', 'min:1', 'required_without:work_item_id'],
            'work_item_ids.*' => ['integer', 'distinct', 'exists:work_items,id'],
            'when' => ['sometimes', 'in:dates,weeks'],
            'start_week' => ['nullable', 'integer', 'min:1', 'max:53', 'required_if:when,weeks'],
            'end_week' => ['nullable', 'integer', 'min:1', 'max:53', 'required_if:when,weeks'],
            'year' => ['nullable', 'integer', 'min:'.PlanningWeek::MIN_YEAR, 'max:'.PlanningWeek::MAX_YEAR, 'required_if:when,weeks'],
            'start_date' => ['nullable', 'date', 'required_unless:when,weeks'],
            'end_date' => ['nullable', 'date', 'required_unless:when,weeks', 'after_or_equal:start_date'],
            'is_provisional' => ['sometimes', 'boolean'],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:50', 'required_without:crew_member_ids'],
            'crew_member_ids' => ['nullable', 'array', 'max:50'],
            'crew_member_ids.*' => ['integer', 'distinct', 'exists:crew_members,id'],
            'foreman_crew_member_id' => ['nullable', 'integer', 'exists:crew_members,id'],
            'work_ticket_crew_member_id' => ['nullable', 'integer', 'exists:crew_members,id'],
            'crew_hours' => ['nullable', 'array'],
            'crew_hours.*' => ['numeric', 'min:2', 'max:8'],
            'hours' => ['nullable', 'numeric', 'min:2', 'max:8'],
            'slot' => ['nullable', 'in:morning,afternoon,full'],
            'start_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'include_saturday' => ['sometimes', 'boolean'],
            'include_sunday' => ['sometimes', 'boolean'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ]);

        $workItemIds = $this->requestedWorkItemIds($data, (int) $data['project_id']);
        if ($workItemIds === []) {
            return response()->json(['message' => 'Kies minstens één werkzaamheid.'], 422);
        }

        $item = WorkItem::query()
            ->with('project')
            ->where('project_id', $data['project_id'])
            ->findOrFail($workItemIds[0]);
        Gate::authorize('view', $item->project);

        $period = $this->assignmentPeriod($data);
        if ($period instanceof JsonResponse) {
            return $period;
        }
        $start = $period['start'];
        $end = $period['end'];
        $isProvisional = $period['provisional'];
        [$includeSaturday, $includeSunday] = $this->weekendInclusion($request);
        if ($isProvisional) {
            $end = $this->provisionalPeriodEnd($end, $includeSaturday, $includeSunday);
        }
        $emptyRange = $this->emptyWorkdaysResponse($start, $end, $includeSaturday, $includeSunday);
        if ($emptyRange) {
            return $emptyRange;
        }
        $teamId = $data['team_id'] ?? null;
        $workers = $this->workersForRequest($data);
        if ($workers->isEmpty()) {
            return response()->json(['message' => 'Deze ploeg heeft geen vakmensen.'], 422);
        }

        $crewIds = $teamId ? [] : $this->crewIdsForWorker($workers->first(), $data['crew_member_ids'] ?? []);
        if ($crewIds === null) {
            return response()->json(['message' => 'Deze personen horen niet bij dit team.'], 422);
        }
        $roles = $this->assignmentRoles($data, $crewIds ?? [], $workers->first(), (bool) $teamId);
        if ($roles instanceof JsonResponse) {
            return $roles;
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

        $independentClocks = ! $isProvisional && ! $start->isSameDay($end);
        if (! $isProvisional) {
            $times = PlanningHours::resolve(
                $data['hours'] ?? null,
                $data['slot'] ?? null,
                $data['start_time'] ?? null,
                $data['end_time'] ?? null,
                $independentClocks,
            );
            foreach ($workers as $worker) {
                $message = $fit->awayRejection(
                    $worker,
                    $start,
                    $end,
                    $includeSaturday,
                    $includeSunday,
                    $times['start_time'],
                    $times['end_time'],
                    $crewIds ?? [],
                );
                if ($message) {
                    return response()->json(['message' => $message], 422);
                }
            }
        }

        $groups = $this->scheduleGroups($data, $crewIds, $teamId ? 1 : (int) ($data['people_count'] ?? 1), $independentClocks);
        if (! $isProvisional) {
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
                    $includeSaturday,
                    $includeSunday,
                    null,
                    (int) $item->project_id,
                    (int) $item->id,
                );
                if ($blocked) {
                    return $blocked;
                }
            }
        }

        foreach ($workers as $worker) {
            foreach ($groups as $group) {
                $ids = $teamId || $workers->count() > 1 ? [] : $group['crew_ids'];
                $created = $this->createScheduledAssignment(
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
                    $includeSaturday,
                    $includeSunday,
                    $workItemIds,
                    $isProvisional,
                );
                $created->applyRoles(
                    WorkerAssignment::roleIdInCrew($roles['foreman'], $ids),
                    WorkerAssignment::roleIdInCrew($roles['holder'], $ids),
                );
            }
        }

        return response()->json(['ok' => true]);
    }

    public function storeInternalAssignment(Request $request, ConflictService $conflicts, PlanningFitService $fit): JsonResponse
    {
        Gate::authorize('planning-assign');
        $data = $request->validate([
            'worker_id' => ['required', 'integer', 'exists:workers,id'],
            'business_unit' => ['required', Rule::enum(InternalBusinessUnit::class)],
            'contact_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'crew_member_ids' => ['nullable', 'array', 'max:50'],
            'crew_member_ids.*' => ['integer', 'distinct', 'exists:crew_members,id'],
            'include_saturday' => ['sometimes', 'boolean'],
            'include_sunday' => ['sometimes', 'boolean'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ], [
            'worker_id.required' => 'Kies een vakman of team.',
            'business_unit.required' => 'Kies een onderdeel.',
            'business_unit.enum' => 'Kies een onderdeel.',
            'contact_name.required' => 'Vul de contactpersoon in.',
            'description.required' => 'Vul een omschrijving in.',
            'start_date.required' => 'Vul een van-datum in.',
            'end_date.required' => 'Vul een t/m-datum in.',
            'end_date.after_or_equal' => 'Tot en met moet op of na de van-datum liggen.',
        ]);

        $worker = Worker::query()->with(['availabilities', 'crewPeople'])->findOrFail($data['worker_id']);
        $crewIds = $this->crewIdsForWorker($worker, $data['crew_member_ids'] ?? []);
        if ($crewIds === null) {
            return response()->json(['message' => 'Deze personen horen niet bij dit team.'], 422);
        }
        if ($worker->crewPeople->where('active', true)->isNotEmpty() && $crewIds === []) {
            return response()->json(['message' => 'Kies minstens één vakman.'], 422);
        }

        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();
        [$includeSaturday, $includeSunday] = $this->weekendInclusion($request);
        $emptyRange = $this->emptyWorkdaysResponse($start, $end, $includeSaturday, $includeSunday);
        if ($emptyRange) {
            return $emptyRange;
        }

        $away = $fit->awayRejection(
            $worker,
            $start,
            $end,
            $includeSaturday,
            $includeSunday,
            PlanningHours::DAY_START,
            PlanningHours::DAY_END,
            $crewIds,
        );
        if ($away) {
            return response()->json(['message' => $away], 422);
        }

        $blocked = $this->firstConflict(
            $conflicts,
            collect([$worker]),
            $start,
            $end,
            max(1, count($crewIds)),
            $request->boolean('confirm_conflict'),
            $crewIds,
            PlanningHours::DAY_START,
            PlanningHours::DAY_END,
            $includeSaturday,
            $includeSunday,
        );
        if ($blocked) {
            return $blocked;
        }

        $assignment = new WorkerAssignment;
        $assignment->worker_id = $worker->id;
        $assignment->project_id = null;
        $assignment->work_item_id = null;
        $assignment->team_id = null;
        $assignment->kind = AssignmentKind::Internal;
        $assignment->business_unit = InternalBusinessUnit::from($data['business_unit']);
        $assignment->contact_name = trim($data['contact_name']);
        $assignment->description = trim($data['description']);
        $assignment->notes = $this->optionalNote($data['notes'] ?? null);
        $assignment->people_count = max(1, count($crewIds));
        $assignment->origin = 'planned';
        $assignment->applySchedule(
            $start,
            $end,
            PlanningHours::DAY_START,
            PlanningHours::DAY_END,
            $includeSaturday,
            $includeSunday,
        );
        $assignment->save();
        if ($crewIds !== []) {
            $assignment->syncPresentCrew($crewIds);
        }

        return response()->json(['ok' => true, 'id' => $assignment->id]);
    }

    public function updateAssignment(Request $request, WorkerAssignment $assignment, ConflictService $conflicts, PlanningFitService $fit): JsonResponse
    {
        Gate::authorize('manage-planning');
        if ($assignment->isInternal()) {
            return $this->updateInternalAssignment($request, $assignment, $conflicts, $fit);
        }
        Gate::authorize('view', $assignment->project);
        $data = $request->validate([
            'worker_id' => ['sometimes', 'integer', 'exists:workers,id'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
            'work_item_id' => ['sometimes', 'nullable', 'integer', 'exists:work_items,id'],
            'work_item_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'work_item_ids.*' => ['integer', 'distinct', 'exists:work_items,id'],
            'when' => ['sometimes', 'in:dates,weeks'],
            'start_week' => ['nullable', 'integer', 'min:1', 'max:53', 'required_if:when,weeks'],
            'end_week' => ['nullable', 'integer', 'min:1', 'max:53', 'required_if:when,weeks'],
            'year' => ['nullable', 'integer', 'min:'.PlanningWeek::MIN_YEAR, 'max:'.PlanningWeek::MAX_YEAR, 'required_if:when,weeks'],
            'start_date' => ['nullable', 'date', 'required_unless:when,weeks'],
            'end_date' => ['nullable', 'date', 'required_unless:when,weeks', 'after_or_equal:start_date'],
            'is_provisional' => ['sometimes', 'boolean'],
            'people_count' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'crew_member_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'crew_member_ids.*' => ['integer', 'distinct', 'exists:crew_members,id'],
            'foreman_crew_member_id' => ['nullable', 'integer', 'exists:crew_members,id'],
            'work_ticket_crew_member_id' => ['nullable', 'integer', 'exists:crew_members,id'],
            'crew_hours' => ['nullable', 'array'],
            'crew_hours.*' => ['numeric', 'min:2', 'max:8'],
            'hours' => ['nullable', 'numeric', 'min:2', 'max:8'],
            'slot' => ['nullable', 'in:morning,afternoon,full'],
            'start_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'include_saturday' => ['sometimes', 'boolean'],
            'include_sunday' => ['sometimes', 'boolean'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ]);

        $period = $this->assignmentPeriod($data, $assignment);
        if ($period instanceof JsonResponse) {
            return $period;
        }
        $start = $period['start'];
        $end = $period['end'];
        $isProvisional = $period['provisional'];
        [$includeSaturday, $includeSunday] = $this->weekendInclusion($request, $assignment);
        if ($isProvisional) {
            $end = $this->provisionalPeriodEnd($end, $includeSaturday, $includeSunday);
        }
        $emptyRange = $this->emptyWorkdaysResponse($start, $end, $includeSaturday, $includeSunday);
        if ($emptyRange) {
            return $emptyRange;
        }
        $workerId = (int) ($data['worker_id'] ?? $assignment->worker_id);
        $assignment->loadMissing(['crewMembers', 'workItem.workActivity', 'project', 'workItems']);

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
        $roles = $this->assignmentRoles($data, $crewIds, $worker);
        if ($roles instanceof JsonResponse) {
            return $roles;
        }
        if (! ($worker->employment_type?->isExternal() ?? false)) {
            if (! array_key_exists('foreman_crew_member_id', $data)) {
                $roles['foreman'] = $assignment->foreman_crew_member_id
                    ? (int) $assignment->foreman_crew_member_id
                    : null;
            }
            if (! array_key_exists('work_ticket_crew_member_id', $data)) {
                $roles['holder'] = $assignment->work_ticket_crew_member_id
                    ? (int) $assignment->work_ticket_crew_member_id
                    : null;
            }
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

        $away = $isProvisional ? null : $fit->awayRejection(
            $worker,
            $start,
            $end,
            $includeSaturday,
            $includeSunday,
            $data['start_time'] ?? $assignment->startTimeValue(),
            $data['end_time'] ?? $assignment->endTimeValue(),
            $crewIds,
        );
        if ($away) {
            return response()->json(['message' => $away], 422);
        }

        $keepTimes = ! $isProvisional
            && ! array_key_exists('hours', $data)
            && ! array_key_exists('slot', $data)
            && ! array_key_exists('start_time', $data)
            && ! array_key_exists('end_time', $data)
            && empty($data['crew_hours']);
        $defaultTimes = $isProvisional
            ? [
                'start_time' => PlanningHours::DAY_START.':00',
                'end_time' => PlanningHours::DAY_END.':00',
                'hours' => 0,
            ]
            : ($keepTimes
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
            ));
        if ($isProvisional) {
            unset($data['hours'], $data['slot'], $data['crew_hours']);
            $data['start_time'] = PlanningHours::formatTime(PlanningHours::DAY_START);
            $data['end_time'] = PlanningHours::formatTime(PlanningHours::DAY_END);
        } else {
            $data['hours'] = $data['hours'] ?? $defaultTimes['hours'];
            $data['start_time'] = $data['start_time'] ?? PlanningHours::formatTime($defaultTimes['start_time']);
            $data['end_time'] = $data['end_time'] ?? PlanningHours::formatTime($defaultTimes['end_time']);
        }

        $independentClocks = ! $isProvisional && ! $start->isSameDay($end);
        $groups = $this->scheduleGroups(
            $data,
            $crewIds,
            $crewIds !== []
                ? count($crewIds)
                : (array_key_exists('people_count', $data) ? (int) $data['people_count'] : $assignment->peopleCount()),
            $independentClocks,
        );
        $first = array_shift($groups) ?? [
            'start_time' => $defaultTimes['start_time'],
            'end_time' => $defaultTimes['end_time'],
            'people_count' => $crewIds !== [] ? count($crewIds) : (array_key_exists('people_count', $data) ? (int) $data['people_count'] : $assignment->peopleCount()),
            'crew_ids' => $crewIds,
        ];

        foreach (array_merge([$first], $groups) as $index => $group) {
            if ($isProvisional) {
                break;
            }
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
                $includeSaturday,
                $includeSunday,
                $targetItem ? (int) $targetItem->project_id : (int) $assignment->project_id,
                $targetItem ? (int) $targetItem->id : ($assignment->work_item_id ? (int) $assignment->work_item_id : null),
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
            'include_saturday' => $assignment->includesSaturday(),
            'include_sunday' => $assignment->includesSunday(),
            'is_provisional' => $assignment->isProvisional(),
            'hours_by_id' => $this->crewScheduleSnapshot($assignment, $stayingIds),
            'foreman' => WorkerAssignment::roleIdInCrew(
                $assignment->foreman_crew_member_id ? (int) $assignment->foreman_crew_member_id : null,
                $stayingIds,
            ),
            'holder' => WorkerAssignment::roleIdInCrew(
                $assignment->work_ticket_crew_member_id ? (int) $assignment->work_ticket_crew_member_id : null,
                $stayingIds,
            ),
        ];

        $originalWorkerId = (int) $assignment->worker_id;
        $targetProjectId = $targetItem ? (int) $targetItem->project_id : (int) $assignment->project_id;
        $targetWorkItemId = $targetItem?->id ?? $assignment->work_item_id;
        $linkedWorkItemIds = $this->linkedWorkItemIdsForUpdate(
            $assignment,
            $data,
            $targetProjectId,
            (int) ($targetWorkItemId ?? 0),
        );
        if ($linkedWorkItemIds !== []) {
            $targetWorkItemId = $linkedWorkItemIds[0];
        }

        $sharedLine = $this->sharedSmallWorkLineAction($assignment, $targetItem, $data, $start, $end, $workerId, $crewIds);
        if ($sharedLine === 'keep') {
            return response()->json(['ok' => true]);
        }
        if ($sharedLine === 'fork') {
            $created = $this->forkSharedSmallWorkLine(
                $assignment,
                $targetItem,
                $start,
                $end,
                $workerId,
                $crewIds,
                $data,
                $includeSaturday,
                $includeSunday,
                $isProvisional,
                $roles,
            );
            $this->releaseForkedActivityLine($assignment, $created, $targetItem);

            return response()->json(['ok' => true]);
        }

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
            $linkedWorkItemIds,
            $staySnapshot,
            $stayingIds,
            $includeSaturday,
            $includeSunday,
            $isProvisional,
            $roles,
        ): void {
            $assignment->project_id = $targetProjectId;
            $assignment->work_item_id = $targetWorkItemId;
            $assignment->worker_id = $workerId;
            $assignment->people_count = $first['people_count'];
            $assignment->applySchedule($start, $end, $first['start_time'], $first['end_time'], $includeSaturday, $includeSunday, $isProvisional);
            $assignment->save();
            $assignment->syncLinkedWorkItems($linkedWorkItemIds);
            if (array_key_exists('crew_member_ids', $data) || $first['crew_ids'] !== [] || $stayingIds !== []) {
                $assignment->syncPresentCrew($first['crew_ids']);
            } elseif ($originalWorkerId !== $workerId) {
                $assignment->crewMembers()->sync([]);
            } else {
                $assignment->syncPresentCrew($assignment->crewMembers->modelKeys());
            }
            $assignment->applyRoles(
                WorkerAssignment::roleIdInCrew($roles['foreman'], $first['crew_ids']),
                WorkerAssignment::roleIdInCrew($roles['holder'], $first['crew_ids']),
            );

            foreach ($groups as $group) {
                $created = $this->createScheduledAssignment(
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
                    $includeSaturday,
                    $includeSunday,
                    $linkedWorkItemIds,
                    $isProvisional,
                );
                $created->applyRoles(
                    WorkerAssignment::roleIdInCrew($roles['foreman'], $group['crew_ids']),
                    WorkerAssignment::roleIdInCrew($roles['holder'], $group['crew_ids']),
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
                    $staySnapshot['include_saturday'],
                    $staySnapshot['include_sunday'],
                    [],
                    $staySnapshot['is_provisional'],
                );
                if ($staySnapshot['hours_by_id'] !== []) {
                    $staying->syncPresentCrew($stayingIds, $staySnapshot['hours_by_id']);
                }
                $staying->applyRoles($staySnapshot['foreman'], $staySnapshot['holder']);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function candidates(Request $request, PlanningFitService $fit): JsonResponse
    {
        Gate::authorize('planning-assign');
        $data = $request->validate([
            'work_item_id' => ['required', 'integer', 'exists:work_items,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'start_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'hours' => ['nullable', 'numeric', 'min:2', 'max:8'],
            'slot' => ['nullable', 'in:morning,afternoon,full'],
            'assignment_id' => ['nullable', 'integer', 'exists:worker_assignments,id'],
            'include_saturday' => ['sometimes', 'boolean'],
            'include_sunday' => ['sometimes', 'boolean'],
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
            ...$this->weekendInclusion($request),
        ))->header('Cache-Control', 'no-store');
    }

    public function destroyAssignment(WorkerAssignment $assignment): JsonResponse
    {
        Gate::authorize('planning-assign');
        if ($assignment->isInternal()) {
            $assignment->delete();

            return response()->json(['ok' => true]);
        }
        Gate::authorize('view', $assignment->project);
        $assignment->delete();

        return response()->json(['ok' => true]);
    }

    public function updateWorkLabel(Request $request, WorkItem $workItem): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-planning');
        $workItem->loadMissing('project');
        Gate::authorize('view', $workItem->project);

        $data = $request->validate([
            'work_activity_id' => [
                'nullable',
                'integer',
                Rule::exists('work_activities', 'id')->where(
                    fn ($query) => $query->where('is_active', true)
                ),
            ],
        ], [
            'work_activity_id.exists' => 'Kies een werkzaamheid uit beheer.',
        ]);

        WorkItem::query()
            ->whereIn('id', $this->draggedLineIds($workItem->project, $workItem))
            ->update([
                'planning_work_activity_id' => $data['work_activity_id'] ?? null,
            ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
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
            ->where('active', true)
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
     * @param  array<string, mixed>  $data
     * @param  list<int>  $crewIds
     * @return array{foreman: ?int, holder: ?int}|JsonResponse
     */
    private function assignmentRoles(array $data, array $crewIds, Worker $worker, bool $forTeam = false): array|JsonResponse
    {
        if ($forTeam || ($worker->employment_type?->isExternal() ?? false)) {
            return ['foreman' => null, 'holder' => null];
        }

        $foreman = array_key_exists('foreman_crew_member_id', $data) && $data['foreman_crew_member_id'] !== null
            ? (int) $data['foreman_crew_member_id']
            : null;
        $holder = array_key_exists('work_ticket_crew_member_id', $data) && $data['work_ticket_crew_member_id'] !== null
            ? (int) $data['work_ticket_crew_member_id']
            : null;

        if ($foreman !== null && ! in_array($foreman, $crewIds, true)) {
            return response()->json(['message' => 'Voorman moet een vakman van deze inzet zijn.'], 422);
        }
        if ($holder !== null && ! in_array($holder, $crewIds, true)) {
            return response()->json(['message' => 'Werkbon bij moet een vakman van deze inzet zijn.'], 422);
        }

        if (count($crewIds) === 1) {
            $foreman ??= $crewIds[0];
            $holder ??= $crewIds[0];
        }

        return ['foreman' => $foreman, 'holder' => $holder];
    }

    private function updateInternalAssignment(
        Request $request,
        WorkerAssignment $assignment,
        ConflictService $conflicts,
        PlanningFitService $fit,
    ): JsonResponse {
        $data = $request->validate([
            'worker_id' => ['sometimes', 'integer', 'exists:workers,id'],
            'business_unit' => ['sometimes', 'required', Rule::enum(InternalBusinessUnit::class)],
            'contact_name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'crew_member_ids' => ['sometimes', 'array', 'max:50'],
            'crew_member_ids.*' => ['integer', 'distinct', 'exists:crew_members,id'],
            'start_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'include_saturday' => ['sometimes', 'boolean'],
            'include_sunday' => ['sometimes', 'boolean'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ], [
            'business_unit.required' => 'Kies een onderdeel.',
            'business_unit.enum' => 'Kies een onderdeel.',
            'contact_name.required' => 'Vul de contactpersoon in.',
            'description.required' => 'Vul een omschrijving in.',
        ]);

        $workerId = (int) ($data['worker_id'] ?? $assignment->worker_id);
        $worker = Worker::query()->with(['availabilities', 'crewPeople'])->findOrFail($workerId);
        $assignment->loadMissing('crewMembers');
        $crewIds = array_key_exists('crew_member_ids', $data)
            ? $this->crewIdsForWorker($worker, $data['crew_member_ids'] ?? [])
            : $assignment->crewMembers->map(fn (CrewMember $member): int => (int) $member->id)->all();
        if ($crewIds === null) {
            return response()->json(['message' => 'Deze personen horen niet bij dit team.'], 422);
        }
        if ($worker->crewPeople->where('active', true)->isNotEmpty() && $crewIds === [] && array_key_exists('crew_member_ids', $data)) {
            return response()->json(['message' => 'Kies minstens één vakman.'], 422);
        }

        $start = Carbon::parse($data['start_date'] ?? $assignment->start_date)->startOfDay();
        $end = Carbon::parse($data['end_date'] ?? $assignment->end_date)->startOfDay();
        if ($end->lt($start)) {
            return response()->json(['message' => 'Tot en met moet op of na de van-datum liggen.'], 422);
        }
        [$includeSaturday, $includeSunday] = $this->weekendInclusion($request, $assignment);
        $emptyRange = $this->emptyWorkdaysResponse($start, $end, $includeSaturday, $includeSunday);
        if ($emptyRange) {
            return $emptyRange;
        }

        $startTime = $data['start_time'] ?? $assignment->startTimeValue();
        $endTime = $data['end_time'] ?? $assignment->endTimeValue();
        $away = $fit->awayRejection(
            $worker,
            $start,
            $end,
            $includeSaturday,
            $includeSunday,
            $startTime,
            $endTime,
            $crewIds,
        );
        if ($away) {
            return response()->json(['message' => $away], 422);
        }

        $blocked = $this->firstConflict(
            $conflicts,
            collect([$worker]),
            $start,
            $end,
            max(1, count($crewIds)),
            $request->boolean('confirm_conflict'),
            $crewIds,
            $startTime,
            $endTime,
            $includeSaturday,
            $includeSunday,
            $assignment->id,
        );
        if ($blocked) {
            return $blocked;
        }

        $assignment->worker_id = $worker->id;
        $assignment->project_id = null;
        $assignment->work_item_id = null;
        if (array_key_exists('business_unit', $data)) {
            $assignment->business_unit = InternalBusinessUnit::from($data['business_unit']);
        }
        if (array_key_exists('contact_name', $data)) {
            $assignment->contact_name = trim($data['contact_name']);
        }
        if (array_key_exists('description', $data)) {
            $assignment->description = trim($data['description']);
        }
        if (array_key_exists('notes', $data)) {
            $assignment->notes = $this->optionalNote($data['notes']);
        }
        $assignment->people_count = max(1, count($crewIds) ?: (int) $assignment->people_count);
        $assignment->applySchedule($start, $end, $startTime, $endTime, $includeSaturday, $includeSunday);
        $assignment->save();
        $assignment->syncPresentCrew($crewIds);

        return response()->json(['ok' => true]);
    }

    private function optionalNote(mixed $notes): ?string
    {
        $note = trim((string) $notes);

        return $note === '' ? null : $note;
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
        bool $includeSaturday = false,
        bool $includeSunday = false,
        ?int $ignoreAssignmentId = null,
        ?int $projectId = null,
        ?int $workItemId = null,
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
                $ignoreAssignmentId,
                $ids,
                $startTime,
                $endTime,
                $includeSaturday,
                $includeSunday,
                $projectId,
                $workItemId,
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
    private function scheduleGroups(array $data, array $crewIds, int $peopleCount, bool $independentClocks = false): array
    {
        $default = PlanningHours::resolve(
            $data['hours'] ?? null,
            $data['slot'] ?? null,
            $data['start_time'] ?? null,
            $data['end_time'] ?? null,
            $independentClocks,
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
        bool $includeSaturday = false,
        bool $includeSunday = false,
        array $linkedWorkItemIds = [],
        bool $isProvisional = false,
    ): WorkerAssignment {
        $startTime = PlanningHours::normalizeTime($startTime, PlanningHours::DAY_START);
        $endTime = PlanningHours::normalizeTime($endTime, PlanningHours::DAY_END);
        $linkedWorkItemIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $linkedWorkItemIds !== [] ? $linkedWorkItemIds : [(int) $workItemId]),
            static fn (int $id): bool => $id > 0,
        )));
        $fingerprint = implode(':', [
            $workerId,
            $projectId,
            implode(',', $linkedWorkItemIds) ?: (string) ($workItemId ?? 0),
            $start->toDateString(),
            $end->toDateString(),
            $startTime,
            $endTime,
            $includeSaturday ? '1' : '0',
            $includeSunday ? '1' : '0',
            $isProvisional ? 'p' : 'h',
        ]);

        return Cache::lock('planning-assignment:'.$fingerprint, 15)->block(10, function () use (
            $workerId,
            $projectId,
            $workItemId,
            $linkedWorkItemIds,
            $teamId,
            $start,
            $end,
            $startTime,
            $endTime,
            $peopleCount,
            $crewIds,
            $includeSaturday,
            $includeSunday,
            $isProvisional,
        ): WorkerAssignment {
            return DB::transaction(function () use (
                $workerId,
                $projectId,
                $workItemId,
                $linkedWorkItemIds,
                $teamId,
                $start,
                $end,
                $startTime,
                $endTime,
                $peopleCount,
                $crewIds,
                $includeSaturday,
                $includeSunday,
                $isProvisional,
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
                    ->where('include_saturday', $includeSaturday)
                    ->where('include_sunday', $includeSunday)
                    ->where('is_provisional', $isProvisional)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $existing->syncLinkedWorkItems($linkedWorkItemIds);

                    return $existing;
                }

                $assignment = new WorkerAssignment([
                    'worker_id' => $workerId,
                    'project_id' => $projectId,
                    'work_item_id' => $workItemId,
                    'team_id' => $teamId,
                    'people_count' => max(1, $peopleCount),
                ]);
                $assignment->applySchedule($start, $end, $startTime, $endTime, $includeSaturday, $includeSunday, $isProvisional);
                $assignment->save();
                $assignment->syncLinkedWorkItems($linkedWorkItemIds);
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
        $names = $conflict['overlaps']->map(function (WorkerAssignment $row): ?string {
            if ($row->isInternal()) {
                return $row->internalTitle();
            }

            $name = trim((string) ($row->project?->name ?? ''));

            return $name !== '' ? $name : null;
        })->filter()->unique()->implode(', ');
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
            $assignment->includesSaturday(),
            $assignment->includesSunday(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function requestedWorkItemIds(array $data, int $projectId): array
    {
        $ids = [];
        foreach ($data['work_item_ids'] ?? [] as $id) {
            $ids[] = (int) $id;
        }
        if (! empty($data['work_item_id'])) {
            array_unshift($ids, (int) $data['work_item_id']);
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $owned = WorkItem::query()
            ->where('project_id', $projectId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $ownedSet = array_flip($owned);
        $kept = [];
        foreach ($ids as $id) {
            if (isset($ownedSet[$id])) {
                $kept[] = $id;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function linkedWorkItemIdsForUpdate(
        WorkerAssignment $assignment,
        array $data,
        int $projectId,
        int $targetWorkItemId,
    ): array {
        if (array_key_exists('work_item_ids', $data)) {
            $ids = $this->requestedWorkItemIds($data, $projectId);
            if ($assignment->work_item_id && in_array((int) $assignment->work_item_id, $ids, true)) {
                array_unshift($ids, (int) $assignment->work_item_id);
                $ids = array_values(array_unique($ids));
            }

            return $ids !== [] ? $ids : array_values(array_filter([$targetWorkItemId]));
        }

        if ($targetWorkItemId > 0 && $targetWorkItemId !== (int) $assignment->work_item_id) {
            return [$targetWorkItemId];
        }

        $ids = $assignment->linkedWorkItemIds();

        return $ids !== [] ? $ids : array_values(array_filter([$targetWorkItemId]));
    }

    /**
     * A klein/service visit drawn on several activity lines forks the line that
     * moves. An unchanged drop stays on the shared visit. Saving the dialog with
     * an explicit work-item list still moves that whole visit.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $crewIds
     * @return 'fork'|'keep'|null
     */
    private function sharedSmallWorkLineAction(
        WorkerAssignment $assignment,
        ?WorkItem $target,
        array $data,
        Carbon $start,
        Carbon $end,
        int $workerId,
        array $crewIds,
    ): ?string {
        if ($target === null) {
            return null;
        }
        $assignment->loadMissing(['project.workItems', 'workItem']);
        $project = $assignment->project;
        if ($project === null || (int) $target->project_id !== (int) $project->id) {
            return null;
        }
        if (array_key_exists('work_item_ids', $data)) {
            return null;
        }
        if (! $project->isSmallWork()) {
            return $this->quantityLineAction($assignment, $target, $data, $start, $end, $workerId, $crewIds);
        }
        if ($target->work_activity_id === null) {
            return null;
        }
        if ($assignment->workItem?->work_activity_id !== null) {
            return $this->multiActivityLineAction($assignment, $target, $data, $start, $end, $workerId, $crewIds);
        }
        if ((int) $target->id === (int) $assignment->work_item_id) {
            return null;
        }

        $project->loadMissing(['workItems', 'assignments']);
        $groups = $this->smallWorkActivityGroups($project);
        $dedicatedIds = $project->assignments
            ->filter(fn (WorkerAssignment $row): bool => (int) $row->id !== (int) $assignment->id)
            ->map(fn (WorkerAssignment $row): int => (int) $row->work_item_id)
            ->all();
        $sharing = 0;
        $targetShares = false;
        foreach ($groups as $ids) {
            if (array_intersect($dedicatedIds, $ids) !== []) {
                continue;
            }
            $sharing++;
            if (in_array((int) $target->id, $ids, true)) {
                $targetShares = true;
            }
        }
        if ($sharing < 2 || ! $targetShares) {
            return null;
        }

        return $this->assignmentScheduleChanged($assignment, $data, $start, $end, $workerId, $crewIds)
            ? 'fork'
            : 'keep';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $crewIds
     */
    private function assignmentScheduleChanged(
        WorkerAssignment $assignment,
        array $data,
        Carbon $start,
        Carbon $end,
        int $workerId,
        array $crewIds,
    ): bool {
        if ((int) $assignment->worker_id !== $workerId) {
            return true;
        }
        if ($assignment->start_date?->toDateString() !== $start->toDateString() || $assignment->end_date?->toDateString() !== $end->toDateString()) {
            return true;
        }
        $startTime = PlanningHours::formatTime($data['start_time'] ?? $assignment->startTimeValue());
        $endTime = PlanningHours::formatTime($data['end_time'] ?? $assignment->endTimeValue());
        if ($startTime !== PlanningHours::formatTime($assignment->startTimeValue()) || $endTime !== PlanningHours::formatTime($assignment->endTimeValue())) {
            return true;
        }
        if (! array_key_exists('crew_member_ids', $data)) {
            return false;
        }
        $original = $assignment->crewMembers
            ->map(fn (CrewMember $member): int => (int) $member->id)
            ->sort()
            ->values()
            ->all();
        $next = collect($crewIds)->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

        return $original !== $next;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $crewIds
     * @return 'fork'|'keep'|null
     */
    private function multiActivityLineAction(
        WorkerAssignment $assignment,
        WorkItem $target,
        array $data,
        Carbon $start,
        Carbon $end,
        int $workerId,
        array $crewIds,
    ): ?string {
        $project = $assignment->project;
        if ($project === null) {
            return null;
        }
        $project->loadMissing('workItems');
        $hoursId = (int) ($project->workItems->first(
            fn (WorkItem $item): bool => $item->work_activity_id === null && ! $item->isExtraWork(),
        )?->id ?? 0);
        $specific = array_values(array_filter(
            $assignment->linkedWorkItemIds(),
            static fn (int $id): bool => $id > 0 && $id !== $hoursId,
        ));
        $covered = 0;
        $targetCovered = false;
        foreach ($this->smallWorkActivityGroups($project) as $ids) {
            if (array_intersect($specific, $ids) === []) {
                continue;
            }
            $covered++;
            if (in_array((int) $target->id, $ids, true)) {
                $targetCovered = true;
            }
        }
        if ($covered < 2 || ! $targetCovered) {
            return null;
        }

        return $this->assignmentScheduleChanged($assignment, $data, $start, $end, $workerId, $crewIds)
            ? 'fork'
            : 'keep';
    }

    /**
     * A normal project visit drawn on several board lines (ondergrond and PVC, for
     * example) forks the line that is dragged. The other lines keep the original days.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $crewIds
     * @return 'fork'|'keep'|null
     */
    private function quantityLineAction(
        WorkerAssignment $assignment,
        WorkItem $target,
        array $data,
        Carbon $start,
        Carbon $end,
        int $workerId,
        array $crewIds,
    ): ?string {
        $project = $assignment->project;
        if ($project === null) {
            return null;
        }
        $linked = array_flip($assignment->linkedWorkItemIds());
        $covered = 0;
        $targetCovered = false;
        foreach ($this->quantityLineGroups($project) as $ids) {
            $hits = false;
            foreach ($ids as $id) {
                if (isset($linked[$id])) {
                    $hits = true;
                    break;
                }
            }
            if (! $hits) {
                continue;
            }
            $covered++;
            if (in_array((int) $target->id, $ids, true)) {
                $targetCovered = true;
            }
        }
        if ($covered < 2 || ! $targetCovered) {
            return null;
        }

        return $this->assignmentScheduleChanged($assignment, $data, $start, $end, $workerId, $crewIds)
            ? 'fork'
            : 'keep';
    }

    /**
     * @return list<list<int>>
     */
    private function quantityLineGroups(Project $project): array
    {
        $groups = [];
        foreach ($project->workItems as $item) {
            if ($item->isExtraWork() || (float) $item->ordered_quantity <= 0.0001) {
                continue;
            }
            $groups[$item->typeKey()][] = (int) $item->id;
        }

        return array_values($groups);
    }

    /**
     * @return list<list<int>>
     */
    private function smallWorkActivityGroups(Project $project): array
    {
        $groups = [];
        foreach ($project->workItems as $item) {
            if ($item->work_activity_id === null || $item->isExtraWork()) {
                continue;
            }
            $key = $item->packageKey() === 'ondergrond' ? 'ondergrond' : 'item-'.$item->id;
            $groups[$key][] = (int) $item->id;
        }

        return array_values($groups);
    }

    /**
     * @return list<int>
     */
    private function activityLineIds(Project $project, WorkItem $target): array
    {
        $project->loadMissing('workItems');
        foreach ($this->smallWorkActivityGroups($project) as $ids) {
            if (in_array((int) $target->id, $ids, true)) {
                return $ids;
            }
        }

        return [(int) $target->id];
    }

    /**
     * @return list<int>
     */
    private function draggedLineIds(Project $project, WorkItem $target): array
    {
        if ($project->isSmallWork()) {
            return $this->activityLineIds($project, $target);
        }

        $key = $target->typeKey();
        $ids = [];
        foreach ($project->workItems as $item) {
            if ($item->isExtraWork() || $item->typeKey() !== $key) {
                continue;
            }
            $ids[] = (int) $item->id;
        }

        return $ids !== [] ? $ids : [(int) $target->id];
    }

    /**
     * The forked line leaves the original visit, which keeps the other activities.
     */
    private function releaseForkedActivityLine(WorkerAssignment $original, WorkerAssignment $created, WorkItem $target): void
    {
        $original->loadMissing(['workItem', 'project.workItems']);
        $project = $original->project;
        $workItem = $original->workItem;
        if ($project === null || $workItem === null) {
            return;
        }
        $quantityVisit = $workItem->work_activity_id === null
            && ! $workItem->isExtraWork()
            && (float) $workItem->ordered_quantity > 0.0001;
        if ($workItem->work_activity_id === null && ! $quantityVisit) {
            return;
        }
        $remove = $this->draggedLineIds($project, $target);
        $remaining = array_values(array_filter(
            $original->linkedWorkItemIds(),
            function (int $id) use ($project, $remove): bool {
                if (in_array($id, $remove, true)) {
                    return false;
                }
                $item = $project->workItems->firstWhere('id', $id);
                if ($item === null || $item->isExtraWork()) {
                    return false;
                }
                if ($item->work_activity_id !== null) {
                    return true;
                }

                return (float) $item->ordered_quantity > 0.0001;
            },
        ));
        if ($remaining === []) {
            return;
        }
        if (in_array((int) $original->work_item_id, $remove, true)) {
            $original->work_item_id = $remaining[0];
            $original->save();
        }
        $original->syncLinkedWorkItems($remaining);
        $created->syncLinkedWorkItems($remove);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $crewIds
     * @param  array{foreman: ?int, holder: ?int}  $roles
     */
    private function forkSharedSmallWorkLine(
        WorkerAssignment $assignment,
        WorkItem $target,
        Carbon $start,
        Carbon $end,
        int $workerId,
        array $crewIds,
        array $data,
        bool $includeSaturday,
        bool $includeSunday,
        bool $isProvisional,
        array $roles,
    ): WorkerAssignment {
        $assignment->loadMissing('project.workItems');
        $lineIds = $assignment->project === null
            ? [(int) $target->id]
            : $this->draggedLineIds($assignment->project, $target);
        $startTime = PlanningHours::normalizeTime($data['start_time'] ?? $assignment->startTimeValue(), PlanningHours::DAY_START);
        $endTime = PlanningHours::normalizeTime($data['end_time'] ?? $assignment->endTimeValue(), PlanningHours::DAY_END);
        $created = $this->createScheduledAssignment(
            $workerId,
            (int) $assignment->project_id,
            (int) $target->id,
            $assignment->team_id ? (int) $assignment->team_id : null,
            $start,
            $end,
            $startTime,
            $endTime,
            $crewIds !== [] ? count($crewIds) : $assignment->peopleCount(),
            array_key_exists('crew_member_ids', $data) ? $crewIds : [],
            $includeSaturday,
            $includeSunday,
            $lineIds,
            $isProvisional,
        );
        if (! array_key_exists('crew_member_ids', $data)) {
            $created->copyPresentCrewFrom($assignment);
        }
        $crew = $created->crewMembers->map(fn (CrewMember $member): int => (int) $member->id)->all();
        $created->applyRoles(
            WorkerAssignment::roleIdInCrew($roles['foreman'], $crew),
            WorkerAssignment::roleIdInCrew($roles['holder'], $crew),
        );

        return $created;
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function weekendInclusion(Request $request, ?WorkerAssignment $assignment = null): array
    {
        $includeSaturday = $request->exists('include_saturday')
            ? $request->boolean('include_saturday')
            : ($assignment?->includesSaturday() ?? false);
        $includeSunday = $request->exists('include_sunday')
            ? $request->boolean('include_sunday')
            : ($assignment?->includesSunday() ?? false);

        return [$includeSaturday, $includeSunday];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{start: Carbon, end: Carbon, provisional: bool}|JsonResponse
     */
    private function assignmentPeriod(array $data, ?WorkerAssignment $assignment = null): array|JsonResponse
    {
        $when = $data['when'] ?? null;
        if ($when === 'weeks') {
            $year = (int) ($data['year'] ?? 0);
            $fromWeek = (int) ($data['start_week'] ?? 0);
            $toWeek = (int) ($data['end_week'] ?? 0);
            $range = PlanningWeek::assignmentRange($year, $fromWeek, $toWeek);
            if ($range === null) {
                $field = $toWeek < $fromWeek ? 'end_week' : 'start_week';
                $message = $toWeek < $fromWeek
                    ? 'Tot week moet op of na Van week liggen.'
                    : 'Dit weeknummer bestaat niet in '.$year.'.';

                return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
            }

            return [
                'start' => $range['start'],
                'end' => $range['end'],
                'provisional' => true,
            ];
        }

        $start = Carbon::parse((string) $data['start_date']);
        $end = Carbon::parse((string) $data['end_date']);
        if ($when === 'dates') {
            return [
                'start' => $start,
                'end' => $end,
                'provisional' => false,
            ];
        }

        $provisional = array_key_exists('is_provisional', $data)
            ? (bool) $data['is_provisional']
            : ($assignment?->isProvisional() ?? false);

        return [
            'start' => $start,
            'end' => $end,
            'provisional' => $provisional,
        ];
    }

    private function provisionalPeriodEnd(Carbon $end, bool $includeSaturday, bool $includeSunday): Carbon
    {
        $monday = $end->copy()->startOfWeek(Carbon::MONDAY);
        if ($includeSunday) {
            return $monday->copy()->addDays(6);
        }
        if ($includeSaturday) {
            return $monday->copy()->addDays(5);
        }

        return $end;
    }

    private function emptyWorkdaysResponse(
        Carbon $start,
        Carbon $end,
        bool $includeSaturday,
        bool $includeSunday,
    ): ?JsonResponse {
        if (PlanningHours::workdayCount($start, $end, $includeSaturday, $includeSunday) > 0) {
            return null;
        }

        return response()->json([
            'message' => 'Deze periode heeft geen werkdagen. Vink zaterdag of zondag aan of kies andere datums.',
        ], 422);
    }
}
