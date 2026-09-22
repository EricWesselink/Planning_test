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
     *     start_time?: string|null,
     *     end_time?: string|null,
     *     break_minutes?: int|null,
     *     note?: string|null,
     *     worker_assignment_id?: int|null,
     *     project_id?: int|null,
     *     work_item_id?: int|null
     * }  $data
     */
    public function submit(User $user, array $data): TimeEntry
    {
        $assignmentId = isset($data['worker_assignment_id']) ? (int) $data['worker_assignment_id'] : 0;
        $preview = $assignmentId > 0
            ? WorkerAssignment::query()->with('workTickets')->find($assignmentId)
            : null;
        $hourlyOpdracht = $preview?->isHourlyOpdracht() ?? false;
        $worker = $this->registrantWorker($user, $hourlyOpdracht);
        $crewMemberId = $this->resolvedCrewMemberId($user, $worker);
        $date = (string) $data['date'];
        $clock = $this->submittedClock($data);
        $hours = $clock === null ? round((float) $data['hours'], 2) : $clock['hours'];
        $startTime = $clock === null ? null : $clock['start_time'];
        $endTime = $clock === null ? null : $clock['end_time'];
        $breakMinutes = $clock === null ? null : $clock['break_minutes'];
        $note = $this->nullableNote($data['note'] ?? null);
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
            $user, $worker, $crewMemberId, $date, $hours, $clock, $startTime, $endTime, $breakMinutes, $note, $assignment, $isUnplanned,
            $project, $workItem, $plannedHours, $identity,
        ): TimeEntry {
            $existing = TimeEntry::query()
                ->where('identity_key', $identity)
                ->lockForUpdate()
                ->first();

            if ($existing === null && $assignment !== null) {
                $sameVisit = TimeEntry::query()
                    ->where('worker_id', $worker->id)
                    ->whereDate('date', $date)
                    ->where('worker_assignment_id', $assignment->id)
                    ->when(
                        $crewMemberId !== null,
                        fn ($query) => $query->where(function ($query) use ($crewMemberId): void {
                            $query->where('crew_member_id', $crewMemberId)
                                ->orWhereNull('crew_member_id');
                        }),
                        fn ($query) => $query->whereNull('crew_member_id'),
                    )
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get();

                if ($sameVisit->count() === 1) {
                    $existing = $sameVisit->first();
                } elseif ($sameVisit->count() > 1) {
                    throw ValidationException::withMessages([
                        'hours' => 'Voor deze inzet staan al meerdere urenregels. Pas die aan in de urenregistratie.',
                    ]);
                }
            }

            if ($existing?->isApproved()) {
                throw ValidationException::withMessages([
                    'hours' => 'Goedgekeurde uren kun je niet meer zelf wijzigen.',
                ]);
            }

            if ($clock !== null) {
                $this->assertNoTimeOverlap(
                    (int) $worker->id,
                    $crewMemberId,
                    $date,
                    $startTime,
                    $endTime,
                    $existing?->id,
                    'start_time',
                );
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
                'approved_start_time' => null,
                'approved_end_time' => null,
                'approved_break_minutes' => null,
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
            if ($clock !== null) {
                $payload['start_time'] = $startTime;
                $payload['end_time'] = $endTime;
                $payload['break_minutes'] = $breakMinutes;
            }

            if ($existing === null) {
                return TimeEntry::query()->create($payload);
            }

            $existing->fill($payload)->save();

            return $existing->fresh() ?? $existing;
        });
    }

    /**
     * @param  array{hours: float|int|string, note?: string|null, start_time?: string, end_time?: string, break_minutes?: int}  $data
     */
    public function updateOpen(TimeEntry $entry, User $user, array $data): TimeEntry
    {
        $clock = $this->submittedClock($data);
        $hours = $clock === null ? round((float) $data['hours'], 2) : $clock['hours'];

        return DB::transaction(function () use ($entry, $user, $data, $clock, $hours): TimeEntry {
            $locked = $this->lock($entry);
            $this->assertOpen($locked);

            if ($clock !== null) {
                $this->assertNoTimeOverlap(
                    (int) $locked->worker_id,
                    $locked->crew_member_id === null ? null : (int) $locked->crew_member_id,
                    $locked->date->toDateString(),
                    $clock['start_time'],
                    $clock['end_time'],
                    $locked->id,
                    'start_time',
                );
            }

            $fill = [
                'hours' => $hours,
                'approved_hours' => null,
                'approved_start_time' => null,
                'approved_end_time' => null,
                'approved_break_minutes' => null,
                'note' => array_key_exists('note', $data) ? $this->nullableNote($data['note'] ?? null) : $locked->note,
                'status' => TimeEntryStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_note' => null,
            ];
            if ($clock !== null) {
                $fill['start_time'] = $clock['start_time'];
                $fill['end_time'] = $clock['end_time'];
                $fill['break_minutes'] = $clock['break_minutes'];
            }
            $locked->fill($fill)->save();

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  array{start_time: string, end_time: string, break_minutes: int}|null  $approvedClock
     */
    public function approveAdjusted(TimeEntry $entry, User $reviewer, float $approvedHours, ?string $reason = null, ?array $approvedClock = null): TimeEntry
    {
        return DB::transaction(function () use ($entry, $reviewer, $approvedHours, $reason, $approvedClock): TimeEntry {
            $locked = $this->lock($entry);
            if (! $locked->isSubmitted() && ! $locked->isApproved()) {
                throw ValidationException::withMessages([
                    'status' => 'Wijs afgewezen uren eerst opnieuw in.',
                ]);
            }

            $approvedHours = round($approvedHours, 2);
            $note = $this->nullableNote($reason);
            if (abs($approvedHours - $locked->submittedHoursValue()) > 0.01 && $note === null) {
                throw ValidationException::withMessages([
                    'review_note' => 'Vul een reden in als je de uren aanpast.',
                ]);
            }

            if ($approvedClock !== null && $this->approvedClockChanged($locked, $approvedClock)) {
                $this->assertNoTimeOverlap(
                    (int) $locked->worker_id,
                    $locked->crew_member_id === null ? null : (int) $locked->crew_member_id,
                    $locked->date->toDateString(),
                    $approvedClock['start_time'],
                    $approvedClock['end_time'],
                    $locked->id,
                    'approved_start_time',
                );
                $locked->approved_start_time = $approvedClock['start_time'];
                $locked->approved_end_time = $approvedClock['end_time'];
                $locked->approved_break_minutes = $approvedClock['break_minutes'];
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

        if ($entry->approved_start_time === null && $entry->start_time !== null) {
            $entry->approved_start_time = $entry->start_time;
            $entry->approved_end_time = $entry->end_time;
            $entry->approved_break_minutes = $entry->break_minutes;
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

    private function registrantWorker(User $user, bool $hourlyOpdracht = false): Worker
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
            if (! $member->registersHours() && ! $hourlyOpdracht) {
                throw ValidationException::withMessages([
                    'hours' => 'Voor jou staan uren registreren uit.',
                ]);
            }

            return $worker;
        }

        if (! $worker->registersHours() && ! $hourlyOpdracht) {
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

    /**
     * @param  array{start_time: string, end_time: string, break_minutes: int}  $clock
     */
    public function approvedClockChanged(TimeEntry $entry, array $clock): bool
    {
        $currentStart = $entry->approved_start_time ?? $entry->start_time;
        $currentEnd = $entry->approved_end_time ?? $entry->end_time;
        if ($currentStart === null || $currentEnd === null) {
            return true;
        }

        $currentBreak = $entry->approved_break_minutes ?? $entry->break_minutes;

        return PlanningHours::normalizeTime((string) $currentStart) !== $clock['start_time']
            || PlanningHours::normalizeTime((string) $currentEnd) !== $clock['end_time']
            || (int) $currentBreak !== (int) $clock['break_minutes'];
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

    private function nullableNote(mixed $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{start_time: string, end_time: string, break_minutes: int, hours: float}|null
     */
    private function submittedClock(array $data): ?array
    {
        if (! array_key_exists('start_time', $data) && ! array_key_exists('end_time', $data)) {
            return null;
        }

        $start = PlanningHours::normalizeTime((string) ($data['start_time'] ?? ''));
        $end = PlanningHours::normalizeTime((string) ($data['end_time'] ?? ''));
        $breakMinutes = (int) ($data['break_minutes'] ?? 0);

        return [
            'start_time' => $start,
            'end_time' => $end,
            'break_minutes' => $breakMinutes,
            'hours' => $this->assertUsableClock($start, $end, $breakMinutes, false, 'end_time', 'break_minutes'),
        ];
    }

    public function clockNet(string $start, string $end, int $breakMinutes, bool $allowZero, string $endField, string $breakField): float
    {
        return $this->assertUsableClock($start, $end, $breakMinutes, $allowZero, $endField, $breakField);
    }

    private function assertUsableClock(
        string $start,
        string $end,
        int $breakMinutes,
        bool $allowZero,
        string $endField,
        string $breakField,
    ): float {
        $span = PlanningHours::minutesFromMidnight($end) - PlanningHours::minutesFromMidnight($start);
        if ($span < 0 || (! $allowZero && $span === 0)) {
            throw ValidationException::withMessages([
                $endField => 'Tot moet later zijn dan Van.',
            ]);
        }

        if ($breakMinutes > $span) {
            throw ValidationException::withMessages([
                $breakField => 'Pauze mag niet langer zijn dan de tijd tussen Van en Tot.',
            ]);
        }

        $net = PlanningHours::netHours($start, $end, $breakMinutes);
        if (! $allowZero && $net < 0.01) {
            throw ValidationException::withMessages([
                $breakField => 'Netto uren moeten meer dan 0 zijn.',
            ]);
        }

        if ($net > 24) {
            throw ValidationException::withMessages([
                $endField => 'Een dag kan maximaal 24 uur zijn.',
            ]);
        }

        return $net;
    }

    private function assertNoTimeOverlap(
        int $workerId,
        ?int $crewMemberId,
        string $date,
        string $start,
        string $end,
        ?int $ignoreId,
        string $errorField,
    ): void {
        $from = PlanningHours::minutesFromMidnight($start);
        $to = PlanningHours::minutesFromMidnight($end);
        $rows = TimeEntry::query()
            ->where('worker_id', $workerId)
            ->whereDate('date', $date)
            ->where('status', '!=', TimeEntryStatus::Rejected)
            ->when(
                $crewMemberId !== null,
                fn ($query) => $query->where('crew_member_id', $crewMemberId),
                fn ($query) => $query->whereNull('crew_member_id'),
            )
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            if (! $row->hasSubmittedTimes() && $row->approved_start_time === null) {
                continue;
            }

            $otherStart = (string) ($row->approved_start_time ?: $row->start_time);
            $otherEnd = (string) ($row->approved_end_time ?: $row->end_time);
            $otherFrom = PlanningHours::minutesFromMidnight($otherStart);
            $otherTo = PlanningHours::minutesFromMidnight($otherEnd);
            if ($from < $otherTo && $otherFrom < $to) {
                throw ValidationException::withMessages([
                    $errorField => 'Deze tijden overlappen met een andere urenregel op deze dag.',
                ]);
            }
        }
    }
}
