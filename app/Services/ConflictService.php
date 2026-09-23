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
        ?int $projectId = null,
        ?int $workItemId = null,
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
            $peak = max($peak, $this->peakPeople($existing, $day, $window[0], $window[1], $addingIds, [
                'people' => $adding,
                'project_id' => $projectId,
                'work_item_id' => $workItemId,
                'member_ids' => $addingIds,
                'start' => $window[0],
                'end' => $window[1],
            ]));
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
                    'project_id' => (int) ($assignment->project_id ?? 0),
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
    /**
     * @param  list<int>  $excludeCrewIds
     * @param  array{people: int, project_id: ?int, work_item_id: ?int, member_ids: list<int>, start: CarbonInterface, end: CarbonInterface}|null  $extra
     */
    private function peakPeople(
        Collection $assignments,
        CarbonInterface $day,
        ?CarbonInterface $windowStart = null,
        ?CarbonInterface $windowEnd = null,
        array $excludeCrewIds = [],
        ?array $extra = null,
    ): int {
        return $this->sweepCoverage(
            $this->coverageIntervals($assignments, $day, $excludeCrewIds, $windowStart, $windowEnd, $extra),
            PHP_INT_MAX,
        )['peak'];
    }

    /**
     * Assignments that are on the clock while the team is over capacity.
     *
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return list<int>
     */
    private function overCapacityAssignmentIds(Collection $assignments, CarbonInterface $day, int $capacity): array
    {
        return $this->sweepCoverage(
            $this->coverageIntervals($assignments, $day),
            $capacity,
        )['over_ids'];
    }

    /**
     * Named people count once per project. Unnamed places on different
     * activities add up, so one person cannot cover two overlapping activities.
     * A second project still adds people. Adjacent times do not overlap.
     *
     * @param  list<array{start: int, end: int, people: int, group: string, assignment: int, member: int}>  $intervals
     * @return array{peak: int, over_ids: list<int>}
     */
    private function sweepCoverage(array $intervals, int $capacity): array
    {
        if ($intervals === []) {
            return ['peak' => 0, 'over_ids' => []];
        }

        $points = [];
        foreach ($intervals as $interval) {
            $points[$interval['start']] = true;
            $points[$interval['end']] = true;
        }
        $points = array_map('intval', array_keys($points));
        sort($points);

        $peak = 0;
        $over = [];
        $last = count($points) - 1;
        for ($index = 0; $index < $last; $index++) {
            $at = $points[$index];
            if ($points[$index + 1] <= $at) {
                continue;
            }
            $byGroup = [];
            foreach ($intervals as $interval) {
                if ($interval['start'] > $at || $interval['end'] <= $at) {
                    continue;
                }
                $group = $interval['group'];
                $item = $interval['work_item'];
                $byGroup[$group]['assignments'][$interval['assignment']] = true;
                if ($interval['member'] > 0) {
                    $byGroup[$group]['items'][$item]['members'][$interval['member']] = true;
                } else {
                    $byGroup[$group]['items'][$item]['unnamed'] = ($byGroup[$group]['items'][$item]['unnamed'] ?? 0) + $interval['people'];
                }
            }
            $total = 0;
            $active = [];
            foreach ($byGroup as $bucket) {
                $onThisWork = 0;
                foreach ($bucket['items'] ?? [] as $itemBucket) {
                    $onThisWork += max(count($itemBucket['members'] ?? []), (int) ($itemBucket['unnamed'] ?? 0));
                }
                $total += $onThisWork;
                foreach (array_keys($bucket['assignments']) as $assignmentId) {
                    if ((int) $assignmentId > 0) {
                        $active[(int) $assignmentId] = true;
                    }
                }
            }
            $peak = max($peak, $total);
            if ($total <= $capacity) {
                continue;
            }
            foreach (array_keys($active) as $assignmentId) {
                $over[$assignmentId] = true;
            }
        }

        return [
            'peak' => $peak,
            'over_ids' => array_map('intval', array_keys($over)),
        ];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  list<int>  $excludeCrewIds
     * @param  array{people: int, project_id: ?int, member_ids: list<int>, start: CarbonInterface, end: CarbonInterface}|null  $extra
     * @return list<array{start: int, end: int, people: int, group: string, assignment: int, member: int}>
     */
    private function coverageIntervals(
        Collection $assignments,
        CarbonInterface $day,
        array $excludeCrewIds = [],
        ?CarbonInterface $windowStart = null,
        ?CarbonInterface $windowEnd = null,
        ?array $extra = null,
    ): array {
        $intervals = [];
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
                    $this->pushCoverageInterval($intervals, $interval[0], $interval[1], 1, $windowStart, $windowEnd, (int) $assignment->id, (int) $member->id, (int) ($assignment->project_id ?? 0), (int) ($assignment->work_item_id ?? 0));
                }

                continue;
            }

            $interval = $assignment->intervalOnDate($day);
            if ($interval === null) {
                continue;
            }
            $this->pushCoverageInterval($intervals, $interval[0], $interval[1], $assignment->peopleCount(), $windowStart, $windowEnd, (int) $assignment->id, 0, (int) ($assignment->project_id ?? 0), (int) ($assignment->work_item_id ?? 0));
        }

        if ($extra !== null) {
            $members = $extra['member_ids'];
            $workItemId = (int) ($extra['work_item_id'] ?? 0);
            if ($members === []) {
                $this->pushCoverageInterval($intervals, $extra['start'], $extra['end'], $extra['people'], $windowStart, $windowEnd, 0, 0, (int) ($extra['project_id'] ?? 0), $workItemId);
            } else {
                foreach ($members as $memberId) {
                    $this->pushCoverageInterval($intervals, $extra['start'], $extra['end'], 1, $windowStart, $windowEnd, 0, (int) $memberId, (int) ($extra['project_id'] ?? 0), $workItemId);
                }
            }
        }

        return $intervals;
    }

    /**
     * @param  list<array{start: int, end: int, people: int, group: string, work_item: int, assignment: int, member: int}>  $intervals
     */
    private function pushCoverageInterval(
        array &$intervals,
        CarbonInterface $start,
        CarbonInterface $end,
        int $people,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
        int $assignmentId,
        int $memberId,
        int $projectId,
        int $workItemId,
    ): void {
        $from = $windowStart ? $start->max($windowStart) : $start;
        $to = $windowEnd ? $end->min($windowEnd) : $end;
        if (! $from->lt($to)) {
            return;
        }

        $intervals[] = [
            'start' => $from->timestamp,
            'end' => $to->timestamp,
            'people' => $people,
            'group' => $projectId > 0 ? 'p'.$projectId : 'a'.$assignmentId,
            'work_item' => $workItemId > 0 ? $workItemId : $assignmentId,
            'assignment' => $assignmentId,
            'member' => $memberId,
        ];
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
