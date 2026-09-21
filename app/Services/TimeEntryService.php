<?php

namespace App\Services;

use App\Enums\TimeEntryStatus;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimeEntryService
{
    /**
     * @param  array{
     *     date: string,
     *     hours: float|int|string,
     *     note?: string|null,
     *     worker_assignment_id?: int|null,
     *     project_id?: int|null,
     *     work_item_id?: int|null
     * }  $data
     */
    public function submit(User $user, array $data): TimeEntry
    {
        $worker = $this->registrantWorker($user);
        $crewMemberId = $this->resolvedCrewMemberId($user, $worker);
        $date = (string) $data['date'];
        $hours = round((float) $data['hours'], 2);
        $note = $this->nullableNote($data['note'] ?? null);
        $assignmentId = isset($data['worker_assignment_id']) ? (int) $data['worker_assignment_id'] : 0;
        $workItemId = isset($data['work_item_id']) ? (int) $data['work_item_id'] : 0;

        $assignment = $assignmentId > 0 ? $this->plannedAssignment($worker, $crewMemberId, $assignmentId, $date) : null;
        $isUnplanned = $assignment === null;
        $project = $assignment?->project ?? $this->project((int) ($data['project_id'] ?? 0));
        $workItem = $this->resolveWorkItem($assignment, $project, $workItemId);
        $plannedHours = $assignment === null
            ? 0.0
            : $this->plannedHoursOnDate($assignment, $crewMemberId, $date, $workItem);

        $identity = TimeEntry::identityKey(
            (int) $worker->id,
            $crewMemberId,
            $date,
            $assignment?->id,
            $workItem?->id,
        );

        return DB::transaction(function () use (
            $user, $worker, $crewMemberId, $date, $hours, $note, $assignment, $isUnplanned,
            $project, $workItem, $plannedHours, $identity,
        ): TimeEntry {
            $existing = TimeEntry::query()
                ->where('identity_key', $identity)
                ->lockForUpdate()
                ->first();

            if ($existing?->isApproved()) {
                throw ValidationException::withMessages([
                    'hours' => 'Goedgekeurde uren kun je niet meer zelf wijzigen.',
                ]);
            }

            $payload = [
                'worker_id' => $worker->id,
                'crew_member_id' => $crewMemberId,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'work_item_id' => $workItem?->id,
                'worker_assignment_id' => $assignment?->id,
                'date' => $date,
                'planned_hours' => $plannedHours,
                'hours' => $hours,
                'approved_hours' => null,
                'note' => $note,
                'status' => TimeEntryStatus::Submitted,
                'is_unplanned' => $isUnplanned,
                'identity_key' => $identity,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_note' => null,
            ];

            if ($existing === null) {
                return TimeEntry::query()->create($payload);
            }

            $existing->fill($payload)->save();

            return $existing->fresh() ?? $existing;
        });
    }

    /**
     * @param  array{hours: float|int|string, note?: string|null}  $data
     */
    public function updateOpen(TimeEntry $entry, User $user, array $data): TimeEntry
    {
        return DB::transaction(function () use ($entry, $user, $data): TimeEntry {
            $locked = $this->lock($entry);
            $this->assertOpen($locked);

            $locked->fill([
                'hours' => round((float) $data['hours'], 2),
                'approved_hours' => null,
                'note' => array_key_exists('note', $data) ? $this->nullableNote($data['note'] ?? null) : $locked->note,
                'status' => TimeEntryStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_note' => null,
            ])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    public function approveAdjusted(TimeEntry $entry, User $reviewer, float $approvedHours, ?string $reason = null): TimeEntry
    {
        return DB::transaction(function () use ($entry, $reviewer, $approvedHours, $reason): TimeEntry {
            $locked = $this->lock($entry);
            $this->assertSubmitted($locked);

            $approvedHours = round($approvedHours, 2);
            $note = $this->nullableNote($reason);
            if (abs($approvedHours - $locked->submittedHoursValue()) > 0.01 && $note === null) {
                throw ValidationException::withMessages([
                    'review_note' => 'Vul een reden in als je de uren aanpast.',
                ]);
            }

            $locked->approved_hours = $approvedHours;
            $locked->review_note = $note;
            $this->applyApproved($locked, $reviewer);

            return $locked->fresh(['project', 'workItem', 'worker', 'crewMember', 'assignment', 'reviewer']) ?? $locked;
        });
    }

    public function approve(TimeEntry $entry, User $reviewer): TimeEntry
    {
        return DB::transaction(function () use ($entry, $reviewer): TimeEntry {
            $locked = $this->lock($entry);

            if ($locked->isApproved() && $locked->isProcessed()) {
                return $locked;
            }

            if ($locked->isRejected()) {
                throw ValidationException::withMessages([
                    'status' => 'Wijs afgewezen uren eerst opnieuw in.',
                ]);
            }

            $this->applyApproved($locked, $reviewer);

            return $locked->fresh(['project', 'workItem', 'worker', 'crewMember', 'assignment', 'progressEntry', 'actualAssignment'])
                ?? $locked;
        });
    }

    public function reject(TimeEntry $entry, User $reviewer, ?string $reason = null): TimeEntry
    {
        return DB::transaction(function () use ($entry, $reviewer, $reason): TimeEntry {
            $locked = $this->lock($entry);

            if ($locked->isApproved()) {
                throw ValidationException::withMessages([
                    'status' => 'Goedgekeurde uren kun je niet meer afwijzen.',
                ]);
            }

            $note = $this->nullableNote($reason);
            if ($note === null) {
                throw ValidationException::withMessages([
                    'review_note' => 'Vul een reden in om af te wijzen.',
                ]);
            }

            $locked->update([
                'status' => TimeEntryStatus::Rejected,
                'approved_hours' => null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @return list<TimeEntry>
     */
    public function approveWeek(User $reviewer, Worker $worker, ?int $crewMemberId, CarbonInterface $weekStart): array
    {
        $from = $weekStart->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $to = $weekStart->copy()->startOfWeek(Carbon::MONDAY)->addDays(5)->toDateString();

        return DB::transaction(function () use ($reviewer, $worker, $crewMemberId, $from, $to): array {
            $query = TimeEntry::query()
                ->where('worker_id', $worker->id)
                ->where('status', TimeEntryStatus::Submitted)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->orderBy('date')
                ->orderBy('id')
                ->lockForUpdate();

            if ($crewMemberId !== null) {
                $query->where('crew_member_id', $crewMemberId);
            }

            $approved = [];
            foreach ($query->get() as $entry) {
                $this->applyApproved($entry, $reviewer);
                $approved[] = $entry->fresh() ?? $entry;
            }

            return $approved;
        });
    }

    private function applyApproved(TimeEntry $entry, User $reviewer): void
    {
        if ($entry->approved_hours === null) {
            $entry->approved_hours = $entry->hours;
        }

        $entry->status = TimeEntryStatus::Approved;
        $entry->reviewed_by = $reviewer->id;
        $entry->reviewed_at = now();
        $entry->save();

        $this->syncProgress($entry, $reviewer);
        $this->syncHistoricalPlanning($entry);
        $entry->processed_at = now();
        $entry->save();
    }

    private function syncProgress(TimeEntry $entry, User $reviewer): void
    {
        $workItem = $entry->workItem ?? ($entry->work_item_id ? WorkItem::query()->find($entry->work_item_id) : null);
        if ($workItem === null) {
            return;
        }

        $progress = $entry->work_progress_entry_id
            ? WorkProgressEntry::query()->find($entry->work_progress_entry_id)
            : null;

        $payload = [
            'project_id' => $entry->project_id,
            'work_item_id' => $workItem->id,
            'worker_id' => $entry->worker_id,
            'crew_member_id' => $entry->crew_member_id,
            'date' => $entry->date->toDateString(),
            'completed_quantity' => $progress?->completed_quantity ?? 0,
            'unit' => $workItem->unit,
            'worked_hours' => $entry->accountedHoursValue(),
            'note' => $entry->note,
            'created_by' => $progress?->created_by ?? $reviewer->id,
        ];

        if ($progress === null) {
            $progress = WorkProgressEntry::query()->create($payload);
            $entry->work_progress_entry_id = $progress->id;
            $entry->save();
        } else {
            $progress->fill($payload)->save();
        }

        $workItem->syncStatusFromProgress();
    }

    private function syncHistoricalPlanning(TimeEntry $entry): void
    {
        if (! $entry->is_unplanned) {
            return;
        }

        $assignment = $entry->actual_assignment_id
            ? WorkerAssignment::query()->find($entry->actual_assignment_id)
            : null;

        if ($assignment === null) {
            $assignment = new WorkerAssignment([
                'worker_id' => $entry->worker_id,
                'project_id' => $entry->project_id,
                'work_item_id' => $entry->work_item_id,
                'people_count' => 1,
                'origin' => 'hours',
                'notes' => 'Werkelijke uren (niet gepland)',
            ]);
        }

        $day = $entry->date->copy()->startOfDay();
        $minutes = (int) round($entry->accountedHoursValue() * 60);
        $start = PlanningHours::DAY_START.':00';
        $end = Carbon::parse('2000-01-01 '.$start)->addMinutes(max(15, $minutes))->format('H:i:s');
        $assignment->applySchedule($day, $day, $start, $end, false, false, false);
        $assignment->origin = 'hours';
        $assignment->hours_per_day = $entry->accountedHoursValue();
        $assignment->planned_hours = 0;
        $assignment->save();

        if ($entry->work_item_id) {
            $assignment->syncLinkedWorkItems([(int) $entry->work_item_id]);
        }

        if ($entry->crew_member_id) {
            $assignment->syncPresentCrew([(int) $entry->crew_member_id]);
        }

        $entry->actual_assignment_id = $assignment->id;
        $entry->save();
    }

    private function plannedAssignment(Worker $worker, ?int $crewMemberId, int $assignmentId, string $date): WorkerAssignment
    {
        $assignment = WorkerAssignment::query()
            ->with(['project', 'workItem', 'workItems', 'crewMembers'])
            ->whereKey($assignmentId)
            ->where('worker_id', $worker->id)
            ->first();

        if ($assignment === null || $assignment->origin === 'hours') {
            throw ValidationException::withMessages([
                'worker_assignment_id' => 'Deze planningregel is niet gevonden.',
            ]);
        }

        $day = Carbon::parse($date)->startOfDay();
        if (! $assignment->coversDate($day)) {
            throw ValidationException::withMessages([
                'date' => 'Je stond deze dag niet op deze planning.',
            ]);
        }

        if ($crewMemberId !== null && ! $assignment->includesCrewMember($crewMemberId)) {
            throw ValidationException::withMessages([
                'worker_assignment_id' => 'Je stond niet op deze planning.',
            ]);
        }

        return $assignment;
    }

    private function resolveWorkItem(?WorkerAssignment $assignment, Project $project, int $workItemId): ?WorkItem
    {
        if ($workItemId > 0) {
            $item = WorkItem::query()->whereKey($workItemId)->where('project_id', $project->id)->first();
            if ($item === null) {
                throw ValidationException::withMessages([
                    'work_item_id' => 'Kies een werkzaamheid van dit project.',
                ]);
            }

            return $item;
        }

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'work_item_id' => 'Kies een werkzaamheid.',
            ]);
        }

        $resolvedId = $assignment->resolvedWorkItemId($assignment->project?->workOrders);
        if ($resolvedId) {
            return WorkItem::query()->find($resolvedId);
        }

        return $assignment->workItems->first();
    }

    private function plannedHoursOnDate(
        WorkerAssignment $assignment,
        ?int $crewMemberId,
        string $date,
        ?WorkItem $workItem,
    ): float {
        $day = Carbon::parse($date)->startOfDay();
        $member = $crewMemberId ? $assignment->crewMembers->firstWhere('id', $crewMemberId) : null;
        $hours = $member instanceof CrewMember
            ? PlanningHours::hoursBetween(
                PlanningHours::normalizeTime($member->pivot?->start_time, $assignment->startTimeValue()),
                PlanningHours::normalizeTime($member->pivot?->end_time, $assignment->endTimeValue()),
            )
            : $assignment->hoursOnDate($day);

        if ($workItem === null) {
            return round($hours, 2);
        }

        $linked = $assignment->linkedWorkItemIds($assignment->project?->workOrders);
        if (count($linked) <= 1) {
            return round($hours, 2);
        }

        return round($hours / count($linked), 2);
    }

    private function registrantWorker(User $user): Worker
    {
        $workerId = $user->scheduledWorkerId();
        $worker = $workerId ? Worker::query()->with('crewPeople')->find($workerId) : null;
        if ($worker === null) {
            throw ValidationException::withMessages([
                'hours' => 'Er is geen vakman gekoppeld aan dit account.',
            ]);
        }

        $member = $user->scheduledCrewMemberId()
            ? $worker->crewPeople->firstWhere('id', $user->scheduledCrewMemberId())
            : null;
        if ($member instanceof CrewMember) {
            $member->setRelation('worker', $worker);
            if (! $member->registersHours()) {
                throw ValidationException::withMessages([
                    'hours' => 'Voor jou staan uren registreren uit.',
                ]);
            }

            return $worker;
        }

        if (! $worker->registersHours()) {
            throw ValidationException::withMessages([
                'hours' => 'Voor jou staan uren registreren uit.',
            ]);
        }

        return $worker;
    }

    public function resolvedCrewMemberId(User $user, ?Worker $worker = null): ?int
    {
        $crewMemberId = $user->scheduledCrewMemberId();
        if ($crewMemberId !== null) {
            return $crewMemberId;
        }

        $worker ??= $user->worker;
        $worker?->loadMissing('crewPeople');
        if ($worker !== null && $worker->crewPeople->count() === 1) {
            return (int) $worker->crewPeople->first()->id;
        }

        return null;
    }

    private function project(int $projectId): Project
    {
        $project = $projectId > 0 ? Project::query()->find($projectId) : null;
        if ($project === null) {
            throw ValidationException::withMessages([
                'project_id' => 'Kies een project.',
            ]);
        }

        return $project;
    }

    private function lock(TimeEntry $entry): TimeEntry
    {
        return TimeEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
    }

    private function assertOpen(TimeEntry $entry): void
    {
        if ($entry->isApproved()) {
            throw ValidationException::withMessages([
                'hours' => 'Goedgekeurde uren kun je niet meer zelf wijzigen.',
            ]);
        }
    }

    private function assertSubmitted(TimeEntry $entry): void
    {
        if ($entry->isSubmitted()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => $entry->isRejected()
                ? 'Wijs afgewezen uren eerst opnieuw in.'
                : 'Deze uren zijn al beoordeeld.',
        ]);
    }

    private function nullableNote(mixed $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }
}
