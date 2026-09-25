<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentKind;
use App\Enums\InternalBusinessUnit;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkTicketLine;
use App\Services\ConflictService;
use App\Services\InternalWeekPlanningService;
use App\Services\PlanningFitService;
use App\Support\DutchNumber;
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
            'work_hours' => ['nullable', 'array'],
            'work_hours.*' => ['numeric', 'min:0', 'max:8'],
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

        $workHours = $this->workHoursForItems($data, $workItemIds, $workers->first());
        if ($workHours instanceof JsonResponse) {
            return $workHours;
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
                    $workHours,
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

    public function syncInternalWeek(
        Request $request,
        InternalWeekPlanningService $weeks,
        ConflictService $conflicts,
        PlanningFitService $fit,
    ): JsonResponse {
        Gate::authorize('planning-assign');
        $data = $request->validate([
            'worker_id' => ['required', 'integer', 'exists:workers,id'],
            'week' => ['required_without:ongoing_from', 'nullable', 'date'],
            'dates' => ['nullable', 'array'],
            'dates.*' => ['date'],
            'ongoing_from' => ['nullable', 'date'],
            'confirm_conflict' => ['sometimes', 'boolean'],
        ], [
            'worker_id.required' => 'Kies een vakman.',
            'week.required_without' => 'De planningweek ontbreekt.',
            'dates.required_without' => 'Geef de dagen van deze week door.',
            'ongoing_from.required_without' => 'Kies de datum vanaf wanneer deze vakman niet beschikbaar is.',
        ]);

        if (! $request->filled('ongoing_from') && ! $request->has('dates')) {
            return response()->json([
                'message' => 'Geef de dagen van deze week door.',
                'errors' => [
                    'dates' => ['Geef de dagen van deze week door.'],
                    'ongoing_from' => ['Kies de datum vanaf wanneer deze vakman niet beschikbaar is.'],
                ],
            ], 422);
        }

        $worker = Worker::query()->with(['availabilities', 'crewPeople'])->findOrFail($data['worker_id']);
        if ($request->filled('ongoing_from')) {
            return $this->storeOngoingInternal($request, $worker, $weeks, $conflicts, $fit);
        }

        $monday = Carbon::parse($data['week'])->startOfWeek(Carbon::MONDAY)->startOfDay();
        $allowed = $weeks->weekDays($monday)->map(fn (Carbon $day): string => $day->toDateString());
        $checked = collect($data['dates'] ?? [])
            ->map(fn (string $date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->values();
        if ($checked->diff($allowed)->isNotEmpty()) {
            return response()->json(['message' => 'Kies alleen dagen van deze week.'], 422);
        }

        $covered = collect($weeks->coveredDates($worker, $monday));
        $crewIds = $worker->activeCrewPeople()
            ->map(fn (CrewMember $member): int => (int) $member->id)
            ->all();
        foreach ($checked->diff($covered) as $date) {
            $day = Carbon::parse($date)->startOfDay();
            $hours = (float) ($worker->default_hours_per_day ?: PlanningHours::WORKDAY_HOURS);
            [$startTime, $endTime] = PlanningHours::timesFromHours($hours);
            $away = $fit->awayRejection(
                $worker,
                $day,
                $day,
                $day->isSaturday(),
                false,
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
                $day,
                $day,
                max(1, count($crewIds) ?: $worker->peopleCount()),
                $request->boolean('confirm_conflict'),
                $crewIds,
                $startTime,
                $endTime,
                $day->isSaturday(),
                false,
            );
            if ($blocked) {
                return $blocked;
            }
        }

        $weeks->sync($worker, $monday, $checked->all());

        return response()->json(['ok' => true]);
    }

    private function storeOngoingInternal(
        Request $request,
        Worker $worker,
        InternalWeekPlanningService $weeks,
        ConflictService $conflicts,
        PlanningFitService $fit,
    ): JsonResponse {
        $from = Carbon::parse($request->string('ongoing_from')->toString())->startOfDay();
        $runs = $weeks->futureRuns($worker, $from);
        $crewIds = $worker->activeCrewPeople()
            ->map(fn (CrewMember $member): int => (int) $member->id)
            ->all();
        $hours = (float) ($worker->default_hours_per_day ?: PlanningHours::WORKDAY_HOURS);
        [$startTime, $endTime] = PlanningHours::timesFromHours($hours);
        foreach ($runs as [$start, $end]) {
            $away = $fit->awayRejection(
                $worker,
                $start,
                $end,
                true,
                false,
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
                max(1, count($crewIds) ?: $worker->peopleCount()),
                $request->boolean('confirm_conflict'),
                $crewIds,
                $startTime,
                $endTime,
                true,
                false,
            );
            if ($blocked) {
                return $blocked;
            }
        }

        $weeks->createRuns($worker, $runs);

        return response()->json(['ok' => true]);
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
            'work_hours' => ['nullable', 'array'],
            'work_hours.*' => ['numeric', 'min:0', 'max:8'],
            'hours' => ['nullable', 'numeric', 'min:2', 'max:8'],
            'slot' => ['nullable', 'in:morning,afternoon,full'],
            'start_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'include_saturday' => ['sometimes', 'boolean'],
            'include_sunday' => ['sometimes', 'boolean'],
            'confirm_conflict' => ['sometimes', 'boolean'],
            'linked_assignment' => ['nullable', 'array'],
            'linked_assignment.id' => ['required_with:linked_assignment', 'integer', 'exists:worker_assignments,id'],
            'linked_assignment.work_item_id' => ['nullable', 'integer', 'exists:work_items,id'],
            'linked_assignment.start_time' => ['required_with:linked_assignment', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'linked_assignment.end_time' => ['required_with:linked_assignment', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
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
        $shareHours = $this->absorbLinkedResize($assignment, $first, $targetItem?->id, $data, $start, $end);
        if ($shareHours instanceof JsonResponse) {
            return $shareHours;
        }
        $neighborUpdate = is_array($shareHours) ? null : $this->contiguousNeighborResize($assignment, $first, $data, $start, $end);
        if ($neighborUpdate instanceof JsonResponse) {
            return $neighborUpdate;
        }
        $conflictStart = $first['start_time'];
        $conflictEnd = $first['end_time'];
        $alsoIgnore = [];
        if (is_array($neighborUpdate)) {
            $conflictStart = $neighborUpdate['union_start'];
            $conflictEnd = $neighborUpdate['union_end'];
            $alsoIgnore = [(int) $neighborUpdate['id']];
        }

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
                $index === 0 ? $conflictStart : $group['start_time'],
                $index === 0 ? $conflictEnd : $group['end_time'],
                $includeSaturday,
                $includeSunday,
                $targetItem ? (int) $targetItem->project_id : (int) $assignment->project_id,
                $targetItem ? (int) $targetItem->id : ($assignment->work_item_id ? (int) $assignment->work_item_id : null),
                $alsoIgnore,
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
        if ($sharedLine === 'fork' && ! is_array($shareHours) && ! is_array($neighborUpdate)) {
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
            $shareHours,
            $neighborUpdate,
        ): void {
            $assignment->project_id = $targetProjectId;
            $assignment->work_item_id = $targetWorkItemId;
            $assignment->worker_id = $workerId;
            $assignment->people_count = $first['people_count'];
            $assignment->applySchedule($start, $end, $first['start_time'], $first['end_time'], $includeSaturday, $includeSunday, $isProvisional);
            $assignment->save();
            $assignment->syncLinkedWorkItems($linkedWorkItemIds, is_array($shareHours) ? $shareHours : []);
            if (is_array($neighborUpdate)) {
                $neighbor = WorkerAssignment::query()->find($neighborUpdate['id']);
                if ($neighbor instanceof WorkerAssignment) {
                    $neighbor->applySchedule(
                        $neighbor->start_date->copy(),
                        $neighbor->end_date->copy(),
                        $neighborUpdate['start_time'],
                        $neighborUpdate['end_time'],
                        $neighbor->includesSaturday(),
                        $neighbor->includesSunday(),
                        $neighbor->isProvisional(),
                    );
                    $neighbor->save();
                }
            }
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

    public function updateWorkLine(Request $request, WorkItem $workItem): RedirectResponse
    {
        Gate::authorize('manage-planning');
        $workItem->loadMissing('project');
        Gate::authorize('view', $workItem->project);

        $data = $request->validate([
            'included' => ['required', 'boolean'],
            'quantity' => ['required', 'string', 'max:32'],
        ], [
            'quantity.required' => 'Vul een hoeveelheid in.',
        ]);
        $quantity = DutchNumber::parse($data['quantity']);
        if ($quantity === null || $quantity < 0 || $quantity > 1000000) {
            return back()->withErrors(['work_line' => 'Vul een hoeveelheid in.']);
        }

        $items = $this->planningLineItems($workItem);
        if ($this->lineShowsQuantity($items)) {
            return back()->withErrors(['work_line' => 'Een regel met m² pas je hier niet aan.']);
        }

        $included = $quantity > 0.0001 || $request->boolean('included');
        foreach ($items as $item) {
            $item->forceFill([
                'planning_included' => $included,
                'ordered_quantity' => (int) $item->id === (int) $workItem->id ? $quantity : 0,
            ])->save();
        }

        return back();
    }

    public function destroyWorkLine(WorkItem $workItem): RedirectResponse
    {
        Gate::authorize('manage-planning');
        $workItem->loadMissing('project');
        Gate::authorize('view', $workItem->project);

        $items = $this->planningLineItems($workItem);
        if ($this->lineShowsQuantity($items)) {
            return back()->withErrors(['work_line' => 'Een regel met m² kan hier niet worden verwijderd.']);
        }
        if ($this->lineIsInUse($items)) {
            return back()->withErrors(['work_line' => 'Deze regel heeft al inzet of uren en kan niet worden verwijderd.']);
        }

        WorkItem::query()->whereIn('id', $items->pluck('id'))->delete();

        return back();
    }

    public function finishPlanning(Project $project): RedirectResponse
    {
        Gate::authorize('manage-planning');
        Gate::authorize('view', $project);
        abort_if($project->isArchived(), 404);

        $project->finishPlanning();

        return back()->with('status', $project->name.' is afgerond. Je vindt het onder Afgerond, met alle planning en gegevens.');
    }

    public function reactivatePlanning(Project $project): RedirectResponse
    {
        Gate::authorize('manage-planning');
        Gate::authorize('view', $project);
        abort_if($project->isArchived(), 404);

        $project->reactivatePlanning();

        return back()->with('status', $project->name.' staat weer onder Actief.');
    }

    /**
     * @return Collection<int, WorkItem>
     */
    private function planningLineItems(WorkItem $workItem): Collection
    {
        $workItem->loadMissing('project.workItems');
        $ids = $this->draggedLineIds($workItem->project, $workItem);

        return WorkItem::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     */
    private function lineShowsQuantity(Collection $items): bool
    {
        $ordered = (float) $items->sum(fn (WorkItem $item): float => (float) $item->ordered_quantity);
        if ($ordered > 0.0001) {
            return true;
        }

        $linked = (float) $items->sum(
            fn (WorkItem $item): float => $item->begrote_hoeveelheid === null ? 0.0 : (float) $item->begrote_hoeveelheid
        );

        return $linked > 0.0001;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     */
    private function lineIsInUse(Collection $items): bool
    {
        $ids = $items->pluck('id')->all();

        return WorkerAssignment::query()->whereIn('work_item_id', $ids)->exists()
            || WorkerAssignment::query()->whereHas('workItems', fn ($query) => $query->whereIn('work_items.id', $ids))->exists()
            || TimeEntry::query()->whereIn('work_item_id', $ids)->exists()
            || WorkTicketLine::query()->whereIn('work_item_id', $ids)->exists()
            || $items->contains(fn (WorkItem $item): bool => $item->progressEntries()->exists() || $item->workOrders()->exists());
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
            if ($conflict === null) {
                continue;
            }
            if (! empty($conflict['message']) || ! $confirm) {
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
        array $workHours = [],
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
            $workHours,
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
                $workHours,
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
                    $existing->syncLinkedWorkItems($linkedWorkItemIds, $workHours);

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
                $assignment->syncLinkedWorkItems($linkedWorkItemIds, $workHours);
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
        if (! empty($conflict['message'])) {
            return response()->json([
                'ok' => false,
                'conflict' => true,
                'message' => $conflict['message'],
            ], 409);
        }

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
     * A resize of one activity in a shared day keeps the day total.
     * The neighbor activity absorbs the difference before validation.
     *
     * @param  array{start_time: string, end_time: string, people_count: int, crew_ids: list<int>}  $first
     * @param  array<string, mixed>  $data
     * @return array<int, float>|JsonResponse|null
     */
    private function absorbLinkedResize(WorkerAssignment $assignment, array &$first, ?int $targetWorkItemId, array $data, Carbon $start, Carbon $end): array|JsonResponse|null
    {
        if ($assignment->isInternal() || ! $this->sameDayResize($assignment, $start, $end)) {
            return null;
        }
        $assignment->loadMissing(['workItems', 'worker']);
        $hours = [];
        foreach ($assignment->workItems as $item) {
            if ($item->pivot?->planned_hours === null) {
                continue;
            }
            $hours[(int) $item->id] = (float) $item->pivot->planned_hours;
        }
        if (count($hours) < 2) {
            return null;
        }
        $targetId = $targetWorkItemId && isset($hours[$targetWorkItemId])
            ? $targetWorkItemId
            : (int) array_key_first($hours);
        $raw = is_array($data['work_hours'] ?? null) ? $data['work_hours'] : [];
        $proposed = $hours;
        if ($raw !== []) {
            foreach (array_keys($hours) as $id) {
                $proposed[$id] = round((float) ($raw[$id] ?? $raw[(string) $id] ?? $hours[$id]), 1);
            }
        } else {
            $newDuration = PlanningHours::hoursBetween($first['start_time'], $first['end_time']);
            $delta = round($newDuration - $hours[$targetId], 2);
            if (abs($delta) < 0.05) {
                return null;
            }
            $adjacentId = (int) array_key_first(array_diff_key($hours, [$targetId => true]));
            $proposed[$targetId] = round($newDuration, 1);
            $proposed[$adjacentId] = round($hours[$adjacentId] - $delta, 1);
        }
        if ($proposed == $hours) {
            return null;
        }
        $sum = round(array_sum($proposed), 2);
        $budget = (float) ($assignment->worker?->default_hours_per_day ?: PlanningHours::WORKDAY_HOURS);
        if (min($proposed) < -0.01 || $sum > $budget + 0.01) {
            $name = $assignment->worker?->displayName() ?: 'Deze vakman';

            return response()->json([
                'message' => $name.' is voor '.PlanningHours::hourText($sum).' uur ingepland terwijl '.PlanningHours::hourText($budget).' uur beschikbaar is.',
            ], 422);
        }
        $first['start_time'] = $assignment->startTimeValue();
        $first['end_time'] = $assignment->endTimeValue();

        return $proposed;
    }

    /**
     * @param  array{start_time: string, end_time: string, people_count: int, crew_ids: list<int>}  $first
     * @param  array<string, mixed>  $data
     * @return array{id: int, start_time: string, end_time: string, union_start: string, union_end: string}|JsonResponse|null
     */
    private function contiguousNeighborResize(WorkerAssignment $assignment, array $first, array $data, Carbon $start, Carbon $end): array|JsonResponse|null
    {
        if ($assignment->isInternal() || ! $this->sameDayResize($assignment, $start, $end)) {
            return null;
        }
        $posted = is_array($data['linked_assignment'] ?? null) ? $data['linked_assignment'] : null;
        $neighbor = $posted
            ? WorkerAssignment::query()->find((int) $posted['id'])
            : $this->touchingAssignment($assignment);
        if (! $neighbor instanceof WorkerAssignment || ! $this->sharesDayDeployment($assignment, $neighbor)) {
            return null;
        }
        $oldHours = PlanningHours::hoursBetween($assignment->startTimeValue(), $assignment->endTimeValue());
        $newHours = PlanningHours::hoursBetween($first['start_time'], $first['end_time']);
        $delta = round($newHours - $oldHours, 2);
        if (abs($delta) < 0.05 && $posted === null) {
            return null;
        }
        $neighborStart = $posted
            ? PlanningHours::normalizeTime((string) $posted['start_time'], PlanningHours::DAY_START)
            : $neighbor->startTimeValue();
        $neighborEnd = $posted
            ? PlanningHours::normalizeTime((string) $posted['end_time'], PlanningHours::DAY_END)
            : $neighbor->endTimeValue();
        if ($posted === null) {
            if ($neighbor->startTimeValue() === $assignment->endTimeValue()) {
                $neighborStart = PlanningHours::normalizeTime($first['end_time'], PlanningHours::DAY_START);
            } elseif ($neighbor->endTimeValue() === $assignment->startTimeValue()) {
                $neighborEnd = PlanningHours::normalizeTime($first['start_time'], PlanningHours::DAY_END);
            } else {
                return null;
            }
        }
        $neighborHours = PlanningHours::hoursBetween($neighborStart, $neighborEnd);
        $sum = round($newHours + $neighborHours, 2);
        $budget = (float) ($assignment->worker?->default_hours_per_day ?: PlanningHours::WORKDAY_HOURS);
        $others = WorkerAssignment::query()
            ->where('worker_id', $assignment->worker_id)
            ->whereNotIn('id', [$assignment->id, $neighbor->id])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get()
            ->sum(fn (WorkerAssignment $row): float => $row->hoursOnDate($start));
        if ($neighborHours < -0.01 || $sum + $others > $budget + 0.01) {
            $name = $assignment->worker?->displayName() ?: 'Deze vakman';

            return response()->json([
                'message' => $name.' is voor '.PlanningHours::hourText($sum + $others).' uur ingepland terwijl '.PlanningHours::hourText($budget).' uur beschikbaar is.',
            ], 422);
        }
        $unionStart = strcmp($first['start_time'], $neighborStart) <= 0 ? $first['start_time'] : $neighborStart;
        $unionEnd = strcmp($first['end_time'], $neighborEnd) >= 0 ? $first['end_time'] : $neighborEnd;

        return [
            'id' => (int) $neighbor->id,
            'start_time' => $neighborStart,
            'end_time' => $neighborEnd,
            'union_start' => $unionStart,
            'union_end' => $unionEnd,
        ];
    }

    private function sameDayResize(WorkerAssignment $assignment, Carbon $start, Carbon $end): bool
    {
        return $assignment->start_date?->isSameDay($assignment->end_date)
            && $start->isSameDay($assignment->start_date)
            && $end->isSameDay($assignment->end_date);
    }

    private function sharesDayDeployment(WorkerAssignment $assignment, WorkerAssignment $neighbor): bool
    {
        return ! $neighbor->isInternal()
            && (int) $neighbor->id !== (int) $assignment->id
            && (int) $neighbor->worker_id === (int) $assignment->worker_id
            && (int) $neighbor->project_id === (int) $assignment->project_id
            && $neighbor->start_date?->isSameDay($assignment->start_date)
            && $neighbor->end_date?->isSameDay($assignment->end_date);
    }

    private function touchingAssignment(WorkerAssignment $assignment): ?WorkerAssignment
    {
        return WorkerAssignment::query()
            ->where('worker_id', $assignment->worker_id)
            ->where('project_id', $assignment->project_id)
            ->where('id', '!=', $assignment->id)
            ->whereDate('start_date', $assignment->start_date)
            ->whereDate('end_date', $assignment->end_date)
            ->get()
            ->first(function (WorkerAssignment $neighbor) use ($assignment): bool {
                if ($neighbor->isInternal()) {
                    return false;
                }

                return $neighbor->startTimeValue() === $assignment->endTimeValue()
                    || $neighbor->endTimeValue() === $assignment->startTimeValue();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $workItemIds
     * @return array<int, float>|JsonResponse
     */
    private function workHoursForItems(array $data, array $workItemIds, Worker $worker): array|JsonResponse
    {
        if (count($workItemIds) < 2) {
            return [];
        }

        $budget = (float) ($worker->default_hours_per_day ?: PlanningHours::WORKDAY_HOURS);
        $raw = is_array($data['work_hours'] ?? null) ? $data['work_hours'] : [];
        $map = [];
        if ($raw === []) {
            $share = round($budget / count($workItemIds), 1);
            foreach ($workItemIds as $id) {
                $map[$id] = $share;
            }

            return $map;
        }

        $sum = 0.0;
        foreach ($workItemIds as $id) {
            $value = round((float) ($raw[$id] ?? $raw[(string) $id] ?? 0), 1);
            $map[$id] = $value;
            $sum += $value;
        }
        if ($sum > $budget + 0.01) {
            $name = $worker->displayName();

            return response()->json([
                'message' => $name.' is voor '.PlanningHours::hourText($sum).' uur ingepland terwijl '.PlanningHours::hourText($budget).' uur beschikbaar is.',
            ], 422);
        }

        return $map;
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
        $original->syncLinkedWorkItems($remaining, array_fill_keys($remaining, null));
        $created->syncLinkedWorkItems($remove, array_fill_keys($remove, null));
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
