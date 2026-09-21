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
     * @return array<int, array<string, array{used: int, person_names: list<string>, people: list<array{id: int, name: string, assignment_ids: list<int>}>, assignment_ids: list<int>}>>
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
                $people = $this->overlappingPeople($rows, $day);
                $used = $this->peakPeople($rows, $day);

                if ($people === [] && $used <= $capacity) {
                    continue;
                }

                $assignmentIds = [];
                foreach ($people as $person) {
                    foreach ($person['assignment_ids'] as $assignmentId) {
                        $assignmentIds[$assignmentId] = true;
                    }
                }
                if ($used > $capacity) {
                    foreach ($this->overCapacityAssignmentIds($rows, $day, $capacity) as $assignmentId) {
                        $assignmentIds[$assignmentId] = true;
                    }
                }

                $map[(int) $workerId][$key] = [
                    'used' => $used,
                    'person_names' => array_values(array_unique(array_map(
                        fn (array $person): string => $person['name'],
                        $people,
                    ))),
                    'people' => $people,
                    'assignment_ids' => array_map('intval', array_keys($assignmentIds)),
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
     * @return list<array{id: int, name: string, assignment_ids: list<int>}>
     */
    private function overlappingPeople(Collection $assignments, CarbonInterface $day): array
    {
        $byPerson = [];
        foreach ($assignments as $assignment) {
            foreach ($assignment->crewMembers as $member) {
                $interval = $assignment->intervalOnDateForMember($day, $member);
                if ($interval === null) {
                    continue;
                }
                $personId = (int) $member->id;
                $byPerson[$personId]['id'] = $personId;
                $byPerson[$personId]['name'] = $member->label();
                $byPerson[$personId]['slots'][] = [
                    'assignment_id' => (int) $assignment->id,
                    'start' => $interval[0],
                    'end' => $interval[1],
                ];
            }
        }

        $people = [];
        foreach ($byPerson as $person) {
            $ids = [];
            $slots = $person['slots'];
            $count = count($slots);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (! PlanningHours::intervalsOverlap($slots[$i]['start'], $slots[$i]['end'], $slots[$j]['start'], $slots[$j]['end'])) {
                        continue;
                    }
                    $ids[$slots[$i]['assignment_id']] = true;
                    $ids[$slots[$j]['assignment_id']] = true;
                }
            }
            if ($ids === []) {
                continue;
            }
            $people[] = [
                'id' => $person['id'],
                'name' => $person['name'],
                'assignment_ids' => array_map('intval', array_keys($ids)),
            ];
        }

        return $people;
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
        $events = $this->assignmentEvents($assignments, $day, $excludeCrewIds, $windowStart, $windowEnd);
        if ($events === []) {
            return 0;
        }

        $this->sortEvents($events);

        $current = 0;
        $peak = 0;
        foreach ($events as $event) {
            $current += $event[1];
            $peak = max($peak, $current);
        }

        return $peak;
    }

    /**
     * Assignments that are on the clock while the team is over capacity.
     *
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return list<int>
     */
    private function overCapacityAssignmentIds(Collection $assignments, CarbonInterface $day, int $capacity): array
    {
        $events = $this->assignmentEvents($assignments, $day);
        if ($events === []) {
            return [];
        }

        $this->sortEvents($events);

        $active = [];
        $current = 0;
        $over = [];
        foreach ($events as $event) {
            $assignmentId = $event[2];
            $current += $event[1];
            $active[$assignmentId] = ($active[$assignmentId] ?? 0) + $event[1];
            if ($active[$assignmentId] === 0) {
                unset($active[$assignmentId]);
            }
            if ($current <= $capacity) {
                continue;
            }
            foreach (array_keys($active) as $id) {
                $over[(int) $id] = true;
            }
        }

        return array_map('intval', array_keys($over));
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  list<int>  $excludeCrewIds
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function assignmentEvents(
        Collection $assignments,
        CarbonInterface $day,
        array $excludeCrewIds = [],
        ?CarbonInterface $windowStart = null,
        ?CarbonInterface $windowEnd = null,
    ): array {
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
                    $this->pushClippedEvent($events, $interval[0], $interval[1], 1, $windowStart, $windowEnd, (int) $assignment->id);
                }

                continue;
            }

            $interval = $assignment->intervalOnDate($day);
            if ($interval === null) {
                continue;
            }
            $this->pushClippedEvent($events, $interval[0], $interval[1], $assignment->peopleCount(), $windowStart, $windowEnd, (int) $assignment->id);
        }

        return $events;
    }

    /**
     * @param  list<array{0: int, 1: int, 2?: int}>  $events
     */
    private function sortEvents(array &$events): void
    {
        usort($events, function (array $left, array $right): int {
            if ($left[0] === $right[0]) {
                return $left[1] <=> $right[1];
            }

            return $left[0] <=> $right[0];
        });
    }

    /**
     * @param  list<array{0: int, 1: int}>  $events
     */
    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $events
     */
    private function pushClippedEvent(
        array &$events,
        CarbonInterface $start,
        CarbonInterface $end,
        int $people,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
        int $assignmentId = 0,
    ): void {
        $from = $windowStart ? $start->max($windowStart) : $start;
        $to = $windowEnd ? $end->min($windowEnd) : $end;
        if (! $from->lt($to)) {
            return;
        }

        $events[] = [$from->timestamp, $people, $assignmentId];
        $events[] = [$to->timestamp, -$people, $assignmentId];
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
