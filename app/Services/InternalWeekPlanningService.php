<?php

namespace App\Services;

use App\Enums\AssignmentKind;
use App\Models\CrewMember;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InternalWeekPlanningService
{
    /**
     * @return Collection<int, Carbon>
     */
    public function weekDays(CarbonInterface $week): Collection
    {
        $monday = $week->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        return collect(range(0, 5))->map(
            fn (int $offset): Carbon => $monday->copy()->addDays($offset),
        );
    }

    /**
     * @return list<string>
     */
    public function coveredDates(Worker $worker, CarbonInterface $week): array
    {
        return $this->coveredDatesByWorker($week)[(int) $worker->id] ?? [];
    }

    /**
     * @return array<int, list<string>>
     */
    public function coveredDatesByWorker(CarbonInterface $week): array
    {
        $days = $this->weekDays($week);
        $assignments = WorkerAssignment::query()
            ->where('kind', AssignmentKind::Internal)
            ->coveringDates($days->first(), $days->last())
            ->get();

        $covered = [];
        foreach ($assignments as $assignment) {
            foreach ($days as $day) {
                if ($assignment->coversDate($day)) {
                    $covered[(int) $assignment->worker_id][$day->toDateString()] = true;
                }
            }
        }

        $dates = [];
        foreach ($covered as $workerId => $daysByDate) {
            $keys = array_keys($daysByDate);
            sort($keys);
            $dates[$workerId] = $keys;
        }

        return $dates;
    }

    /**
     * @param  list<string>  $checkedDates
     */
    public function sync(Worker $worker, CarbonInterface $week, array $checkedDates): void
    {
        $days = $this->weekDays($week);
        $allowed = $days->map(fn (Carbon $day): string => $day->toDateString())->all();
        $checked = collect($checkedDates)
            ->map(fn (string $date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->intersect($allowed)
            ->values();

        DB::transaction(function () use ($worker, $days, $checked): void {
            foreach ($days as $day) {
                if ($checked->contains($day->toDateString())) {
                    continue;
                }

                $covering = $this->internalAssignments($worker, $days->first(), $days->last())
                    ->filter(fn (WorkerAssignment $assignment): bool => ! $assignment->isHoursOrigin() && $assignment->coversDate($day));
                foreach ($covering as $assignment) {
                    if (! $assignment->exists || ! $assignment->coversDate($day)) {
                        continue;
                    }

                    $this->removeDay($assignment, $day);
                }
            }

            $assignments = $this->internalAssignments($worker, $days->first(), $days->last());
            foreach ($checked as $date) {
                $day = Carbon::parse($date)->startOfDay();
                $already = $assignments->contains(
                    fn (WorkerAssignment $assignment): bool => $assignment->coversDate($day),
                );
                if ($already) {
                    continue;
                }

                $created = $this->createFullDay($worker, $day);
                $assignments->push($created);
            }
        });
    }

    /**
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public function futureRuns(Worker $worker, CarbonInterface $from): array
    {
        $start = $from->copy()->startOfDay();
        if ($start->isSunday()) {
            $start->addDay();
        }
        $until = Carbon::create($start->year + 1, 12, 31)->startOfDay();
        $existing = $this->internalAssignments($worker, $start, $until);
        $runs = [];
        $runStart = null;
        $runEnd = null;
        $cursor = $start->copy();
        while ($cursor->lte($until)) {
            if ($cursor->isSunday()) {
                $cursor->addDay();

                continue;
            }
            $open = ! $existing->contains(
                fn (WorkerAssignment $assignment): bool => $assignment->coversDate($cursor),
            );
            if ($open) {
                $runStart ??= $cursor->copy();
                $runEnd = $cursor->copy();
            } elseif ($runStart !== null && $runEnd !== null) {
                $runs[] = [$runStart, $runEnd];
                $runStart = null;
                $runEnd = null;
            }
            $cursor->addDay();
        }
        if ($runStart !== null && $runEnd !== null) {
            $runs[] = [$runStart, $runEnd];
        }

        return $runs;
    }

    /**
     * @param  list<array{0: Carbon, 1: Carbon}>  $runs
     */
    public function createRuns(Worker $worker, array $runs): void
    {
        DB::transaction(function () use ($worker, $runs): void {
            foreach ($runs as [$start, $end]) {
                $this->createRange($worker, $start, $end);
            }
        });
    }

    /**
     * @return Collection<int, WorkerAssignment>
     */
    private function internalAssignments(Worker $worker, CarbonInterface $start, CarbonInterface $end): Collection
    {
        return WorkerAssignment::query()
            ->with('crewMembers')
            ->where('worker_id', $worker->id)
            ->where('kind', AssignmentKind::Internal)
            ->coveringDates($start, $end)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    private function createFullDay(Worker $worker, CarbonInterface $day): WorkerAssignment
    {
        $hours = (float) ($worker->default_hours_per_day ?: PlanningHours::WORKDAY_HOURS);
        [$startTime, $endTime] = PlanningHours::timesFromHours($hours);
        $worker->loadMissing('crewPeople');
        $crewIds = $worker->activeCrewPeople()
            ->map(fn (CrewMember $member): int => (int) $member->id)
            ->values()
            ->all();

        $assignment = new WorkerAssignment;
        $assignment->worker_id = $worker->id;
        $assignment->project_id = null;
        $assignment->work_item_id = null;
        $assignment->team_id = null;
        $assignment->kind = AssignmentKind::Internal;
        $assignment->people_count = max(1, count($crewIds) ?: $worker->peopleCount());
        $assignment->origin = 'planned';
        $assignment->applySchedule(
            $day,
            $day,
            $startTime,
            $endTime,
            $day->isSaturday(),
            false,
        );
        $assignment->save();
        if ($crewIds !== []) {
            $assignment->syncPresentCrew($crewIds);
        }

        return $assignment;
    }

    private function createRange(Worker $worker, CarbonInterface $start, CarbonInterface $end): WorkerAssignment
    {
        $assignment = $this->createFullDay($worker, $start);
        if (! $start->isSameDay($end)) {
            $assignment->applySchedule(
                $start,
                $end,
                $assignment->startTimeValue(),
                $assignment->endTimeValue(),
                true,
                false,
            );
            $assignment->save();
        }

        return $assignment;
    }

    private function removeDay(WorkerAssignment $assignment, CarbonInterface $day): void
    {
        $remaining = [];
        $cursor = $assignment->start_date->copy()->startOfDay();
        $last = $assignment->end_date->copy()->startOfDay();
        while ($cursor->lte($last)) {
            if (! $cursor->isSameDay($day) && $assignment->coversDate($cursor)) {
                $remaining[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        if ($remaining === []) {
            $assignment->delete();

            return;
        }

        $runs = $this->contiguousRuns($remaining);
        $first = array_shift($runs);
        $copies = [];
        foreach ($runs as $run) {
            $copies[] = [$assignment->replicate(), $run];
        }

        $this->resize($assignment, $first[0], $first[array_key_last($first)]);
        foreach ($copies as [$copy, $run]) {
            $copy->save();
            $this->resize($copy, $run[0], $run[array_key_last($run)]);
            $copy->copyPresentCrewFrom($assignment);
        }
    }

    /**
     * @param  list<CarbonInterface>  $dates
     * @return list<list<CarbonInterface>>
     */
    private function contiguousRuns(array $dates): array
    {
        $runs = [];
        $current = [];
        $previous = null;
        foreach ($dates as $date) {
            if ($previous !== null && ! $previous->copy()->addDay()->isSameDay($date)) {
                $runs[] = $current;
                $current = [];
            }
            $current[] = $date;
            $previous = $date;
        }
        if ($current !== []) {
            $runs[] = $current;
        }

        return $runs;
    }

    private function resize(WorkerAssignment $assignment, CarbonInterface $start, CarbonInterface $end): void
    {
        $assignment->applySchedule(
            $start,
            $end,
            $assignment->startTimeValue(),
            $assignment->endTimeValue(),
            $assignment->includesSaturday() || $start->isSaturday() || $end->isSaturday(),
            $assignment->includesSunday() || $start->isSunday() || $end->isSunday(),
        );
        $assignment->save();
    }
}
