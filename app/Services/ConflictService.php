<?php

namespace App\Services;

use App\Models\CrewMember;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Support\PlanningHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ConflictService
{
    public function overlapsForWorker(int $workerId, CarbonInterface $date, ?int $ignoreAssignmentId = null): Collection
    {
        return $this->overlapsInRange($workerId, $date, $date, $ignoreAssignmentId);
    }

    /**
     * @return array<int, array<string, array{used: int, person_names: list<string>}>>
     */
    public function doubleBookedMap(Collection $assignments, Collection $days): array
    {
        $map = [];

        foreach ($days as $day) {
            $key = $day->toDateString();
            $byWorker = $assignments
                ->filter(fn (WorkerAssignment $assignment) => $assignment->coversDate($day))
                ->groupBy(fn (WorkerAssignment $assignment) => (int) $assignment->worker_id);

            foreach ($byWorker as $workerId => $rows) {
                $capacity = $rows->first()?->worker?->peopleCount() ?? 1;
                $doubled = $this->overlappingPersonNames($rows, $day);
                $used = $this->peakPeople($rows, $day);

                if ($doubled === [] && $used <= $capacity) {
                    continue;
                }

                $map[(int) $workerId][$key] = [
                    'used' => $used,
                    'person_names' => $doubled,
                ];
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $addingCrewMemberIds
     * @return array{worker: Worker, overlaps: Collection<int, WorkerAssignment>, used: int, capacity: int, person?: string}|null
     */
    public function capacityConflict(
        int $workerId,
        CarbonInterface $start,
        CarbonInterface $end,
        int $addingPeople,
        ?int $ignoreAssignmentId = null,
        array $addingCrewMemberIds = [],
        ?string $startTime = null,
        ?string $endTime = null,
        bool $includeSaturday = false,
        bool $includeSunday = false,
    ): ?array {
        $worker = Worker::query()->findOrFail($workerId);
        $capacity = $worker->peopleCount();
        $addingIds = $this->uniquePositiveIds($addingCrewMemberIds);
        $adding = $addingIds !== [] ? count($addingIds) : max(1, $addingPeople);
        $existing = $this->overlapsInRange($workerId, $start, $end, $ignoreAssignmentId);
        $from = PlanningHours::normalizeTime($startTime, PlanningHours::DAY_START);
        $to = PlanningHours::normalizeTime($endTime, PlanningHours::DAY_END);

        $personConflict = $this->firstPersonConflict($existing, $start, $end, $from, $to, $addingIds, $worker, $includeSaturday, $includeSunday);
        if ($personConflict !== null) {
            return $personConflict;
        }

        $peak = $adding;
        $day = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($day->lte($last)) {
            $window = PlanningHours::intervalOnDate($day, $start, $end, $from, $to, $includeSaturday, $includeSunday);
            if ($window === null) {
                $day->addDay();

                continue;
            }
            $used = $this->peakPeople($existing, $day, $window[0], $window[1], $addingIds);
            $peak = max($peak, $used + $adding);
            $day->addDay();
        }

        if ($peak <= $capacity) {
            return null;
        }

        return [
            'worker' => $worker,
            'overlaps' => $existing,
            'used' => $peak,
            'capacity' => $capacity,
        ];
    }

    public function overlapsInRange(int $workerId, CarbonInterface $start, CarbonInterface $end, ?int $ignoreAssignmentId = null): Collection
    {
        return WorkerAssignment::query()
            ->with(['worker', 'project', 'crewMembers'])
            ->where('worker_id', $workerId)
            ->when($ignoreAssignmentId, fn ($query) => $query->where('id', '!=', $ignoreAssignmentId))
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get()
            ->values();
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $existing
     * @param  list<int>  $addingIds
     * @return array{worker: Worker, overlaps: Collection<int, WorkerAssignment>, used: int, capacity: int, person: string}|null
     */
    private function firstPersonConflict(
        Collection $existing,
        CarbonInterface $start,
        CarbonInterface $end,
        string $startTime,
        string $endTime,
        array $addingIds,
        Worker $worker,
        bool $includeSaturday = false,
        bool $includeSunday = false,
    ): ?array {
        if ($addingIds === []) {
            return null;
        }

        $day = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($day->lte($last)) {
            $addingInterval = PlanningHours::intervalOnDate($day, $start, $end, $startTime, $endTime, $includeSaturday, $includeSunday);
            if ($addingInterval === null) {
                $day->addDay();

                continue;
            }

            foreach ($existing as $assignment) {
                foreach ($assignment->crewMembers as $member) {
                    if (! in_array((int) $member->id, $addingIds, true)) {
                        continue;
                    }
                    $interval = $assignment->intervalOnDateForMember($day, $member);
                    if ($interval === null || ! PlanningHours::intervalsOverlap(
                        $interval[0],
                        $interval[1],
                        $addingInterval[0],
                        $addingInterval[1],
                    )) {
                        continue;
                    }

                    return [
                        'worker' => $worker,
                        'overlaps' => collect([$assignment]),
                        'used' => $worker->peopleCount() + 1,
                        'capacity' => $worker->peopleCount(),
                        'person' => $member->label() ?: (CrewMember::query()->find($member->id)?->label() ?? 'Iemand'),
                    ];
                }
            }

            $day->addDay();
        }

        return null;
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return list<string>
     */
    private function overlappingPersonNames(Collection $assignments, CarbonInterface $day): array
    {
        $byPerson = [];
        foreach ($assignments as $assignment) {
            foreach ($assignment->crewMembers as $member) {
                $interval = $assignment->intervalOnDateForMember($day, $member);
                if ($interval === null) {
                    continue;
                }
                $byPerson[(int) $member->id][] = [
                    'label' => $member->label(),
                    'start' => $interval[0],
                    'end' => $interval[1],
                ];
            }
        }

        $names = [];
        foreach ($byPerson as $slots) {
            $count = count($slots);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (PlanningHours::intervalsOverlap($slots[$i]['start'], $slots[$i]['end'], $slots[$j]['start'], $slots[$j]['end'])) {
                        $names[] = $slots[$i]['label'];
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  list<int>  $excludeCrewIds
     */
    private function peakPeople(
        Collection $assignments,
        CarbonInterface $day,
        ?CarbonInterface $windowStart = null,
        ?CarbonInterface $windowEnd = null,
        array $excludeCrewIds = [],
    ): int {
        $events = [];
        foreach ($assignments as $assignment) {
            $crew = $assignment->relationLoaded('crewMembers') ? $assignment->crewMembers : collect();
            if ($crew->isNotEmpty()) {
                foreach ($crew as $member) {
                    if (in_array((int) $member->id, $excludeCrewIds, true)) {
                        continue;
                    }
                    $interval = $assignment->intervalOnDateForMember($day, $member);
                    if ($interval === null) {
                        continue;
                    }
                    $this->pushClippedEvent($events, $interval[0], $interval[1], 1, $windowStart, $windowEnd);
                }

                continue;
            }

            $interval = $assignment->intervalOnDate($day);
            if ($interval === null) {
                continue;
            }
            $this->pushClippedEvent($events, $interval[0], $interval[1], $assignment->peopleCount(), $windowStart, $windowEnd);
        }

        if ($events === []) {
            return 0;
        }

        usort($events, function (array $left, array $right): int {
            if ($left[0] === $right[0]) {
                return $left[1] <=> $right[1];
            }

            return $left[0] <=> $right[0];
        });

        $current = 0;
        $peak = 0;
        foreach ($events as $event) {
            $current += $event[1];
            $peak = max($peak, $current);
        }

        return $peak;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $events
     */
    private function pushClippedEvent(
        array &$events,
        CarbonInterface $start,
        CarbonInterface $end,
        int $people,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
    ): void {
        $from = $windowStart ? $start->max($windowStart) : $start;
        $to = $windowEnd ? $end->min($windowEnd) : $end;
        if (! $from->lt($to)) {
            return;
        }

        $events[] = [$from->timestamp, $people];
        $events[] = [$to->timestamp, -$people];
    }

    /**
     * @param  list<mixed>  $ids
     * @return list<int>
     */
    private function uniquePositiveIds(array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0 && ! in_array($value, $unique, true)) {
                $unique[] = $value;
            }
        }

        return $unique;
    }
}
