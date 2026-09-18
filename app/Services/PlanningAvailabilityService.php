<?php

namespace App\Services;

use App\Models\CrewMember;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Support\PlanningHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PlanningAvailabilityService
{
    private const DAY_NAMES = [
        1 => 'Ma',
        2 => 'Di',
        3 => 'Wo',
        4 => 'Do',
        5 => 'Vr',
        6 => 'Za',
        7 => 'Zo',
    ];

    public function __construct(
        private WorkerAvailabilityService $availability,
    ) {}

    /**
     * @param  Collection<int, CarbonInterface>  $days
     */
    public function remainingManDays(Collection $days): float
    {
        return round((float) collect($this->forDays($days))->sum('remaining'), 2);
    }

    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @return list<array{worker_id: int, label: string, color: string, planned: float, available: float, remaining: float, people_count: int, external: bool, summary: string}>
     */
    public function forDays(Collection $days): array
    {
        return array_map(
            function (array $team): array {
                unset($team['days']);

                return $team;
            },
            $this->overview($days)['teams'],
        );
    }

    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @return array{days: list<array{date: string, label: string}>, teams: list<array<string, mixed>>}
     */
    public function overview(Collection $days): array
    {
        $boardDays = $days->values();
        $weekDays = $boardDays
            ->filter(fn (CarbonInterface $day): bool => $day->isWeekday())
            ->values();

        $headings = $boardDays
            ->map(fn (CarbonInterface $day): array => [
                'date' => $day->toDateString(),
                'label' => $this->dayHeading($day, $boardDays->count() > 6),
            ])
            ->all();

        if ($boardDays->isEmpty()) {
            return ['days' => [], 'teams' => []];
        }

        $workers = Worker::query()
            ->where('active', true)
            ->with(['crewPeople', 'availabilities'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($workers->isEmpty()) {
            return ['days' => $headings, 'teams' => []];
        }

        $assignments = WorkerAssignment::query()
            ->with('crewMembers')
            ->whereIn('worker_id', $workers->modelKeys())
            ->whereDate('end_date', '>=', $boardDays->first())
            ->whereDate('start_date', '<=', $boardDays->last())
            ->get()
            ->groupBy(fn (WorkerAssignment $assignment): int => (int) $assignment->worker_id);

        $rows = [];
        foreach ($workers as $worker) {
            $workDays = $weekDays->filter(
                fn (CarbonInterface $day): bool => ! $this->availability->isAwayOn($worker, $day)
            );
            $available = (float) ($worker->peopleCount() * $workDays->count());
            $planned = 0.0;
            foreach ($weekDays as $day) {
                $planned += $this->plannedManDaysOnDate(
                    $worker,
                    $assignments->get($worker->id, collect()),
                    $day,
                );
            }
            $planned = round($planned, 2);
            $remaining = $available < 0.0001 ? 0.0 : round($available - $planned, 2);
            if ($remaining < 0) {
                $remaining = 0.0;
            }
            $dayCells = [];
            foreach ($boardDays as $day) {
                $dayCells[$day->toDateString()] = $this->dayCell(
                    $worker,
                    $assignments->get($worker->id, collect()),
                    $day,
                );
            }

            $rows[] = [
                'worker_id' => (int) $worker->id,
                'label' => $worker->planName(),
                'color' => $worker->planColor(),
                'planned' => $planned,
                'available' => $available,
                'remaining' => $remaining,
                'people_count' => $worker->peopleCount(),
                'external' => $worker->employment_type?->isExternal() ?? false,
                'summary' => $this->summary($worker->planName(), $planned, $available, $remaining),
                'days' => $dayCells,
            ];
        }

        return [
            'days' => $headings,
            'teams' => $rows,
        ];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return array{date: string, tone: string, free_count: int, label: string, people: list<array<string, mixed>>}
     */
    private function dayCell(Worker $worker, Collection $assignments, CarbonInterface $day): array
    {
        $away = $this->awayDetail($worker, $day);
        $people = $this->peopleOnTeam($worker);
        $crew = $worker->crewPeople;
        $namedToday = $assignments->contains(
            fn (WorkerAssignment $assignment): bool => $assignment->crewMembers->isNotEmpty()
                && $assignment->intervalOnDate($day) !== null
        );
        $rows = $crew->isNotEmpty() && $namedToday
            ? $this->namedPeopleForDay($worker, $crew, $assignments, $day, $away)
            : $this->unnamedPeopleForDay($worker, $people, $assignments, $day, $away);

        $freeCount = collect($rows)->where('selectable', true)->count();
        $awayCount = collect($rows)->where('status', 'away')->count();
        $fullFreeCount = collect($rows)
            ->filter(fn (array $row): bool => $row['status'] === 'free')
            ->count();
        $total = max(1, count($rows));

        return [
            'date' => $day->toDateString(),
            'tone' => $this->tone($awayCount, $freeCount, $fullFreeCount, $total),
            'free_count' => $freeCount,
            'label' => $freeCount.' vrij',
            'people' => $rows,
        ];
    }

    /**
     * @param  Collection<int, CrewMember>  $crew
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return list<array<string, mixed>>
     */
    private function namedPeopleForDay(
        Worker $worker,
        Collection $crew,
        Collection $assignments,
        CarbonInterface $day,
        ?string $away,
    ): array {
        $rows = [];
        foreach ($crew as $member) {
            $plannedHours = $away === null
                ? $this->memberPlannedHours($assignments, $day, $member)
                : PlanningHours::WORKDAY_HOURS;
            $name = trim((string) $member->name);
            $label = $name !== ''
                ? $member->label()
                : ($crew->count() === 1 ? $worker->planName() : $member->label());
            $rows[] = $this->personRow((int) $member->id, $label, $plannedHours, $away);
        }

        return $rows;
    }

    /**
     * @param  list<array{id: int, name: string}>  $people
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return list<array<string, mixed>>
     */
    private function unnamedPeopleForDay(
        Worker $worker,
        array $people,
        Collection $assignments,
        CarbonInterface $day,
        ?string $away,
    ): array {
        $capacityHours = count($people) * PlanningHours::WORKDAY_HOURS;
        $plannedHours = $away === null
            ? round($this->plannedManDaysOnDate($worker, $assignments, $day) * PlanningHours::WORKDAY_HOURS, 2)
            : $capacityHours;
        $remainingPool = max(0.0, $capacityHours - $plannedHours);
        $rows = [];
        foreach ($people as $person) {
            $remaining = min(PlanningHours::WORKDAY_HOURS, $remainingPool);
            $remainingPool = round($remainingPool - $remaining, 2);
            $rows[] = $this->personRow(
                (int) $person['id'],
                (string) $person['name'],
                PlanningHours::WORKDAY_HOURS - $remaining,
                $away,
            );
        }

        return $rows;
    }

    /**
     * @return array{id: int, name: string, status: string, mark: string, detail: string, remaining_hours: float, selectable: bool}
     */
    private function personRow(int $id, string $name, float $plannedHours, ?string $away): array
    {
        $plannedHours = max(0.0, min(PlanningHours::WORKDAY_HOURS, round($plannedHours, 2)));
        $remaining = round(PlanningHours::WORKDAY_HOURS - $plannedHours, 2);
        if ($away !== null) {
            return [
                'id' => $id,
                'name' => $name,
                'status' => 'away',
                'mark' => '✕',
                'detail' => $away,
                'remaining_hours' => 0.0,
                'selectable' => false,
            ];
        }

        if ($remaining <= 0.01) {
            return [
                'id' => $id,
                'name' => $name,
                'status' => 'busy',
                'mark' => '✕',
                'detail' => PlanningHours::hoursLabel($plannedHours).' ingepland',
                'remaining_hours' => 0.0,
                'selectable' => false,
            ];
        }

        $full = $remaining >= PlanningHours::WORKDAY_HOURS - 0.01;

        return [
            'id' => $id,
            'name' => $name,
            'status' => $full ? 'free' : 'partial',
            'mark' => '✓',
            'detail' => $full ? 'vrij' : 'nog '.PlanningHours::hoursLabel($remaining).' vrij',
            'remaining_hours' => $remaining,
            'selectable' => true,
        ];
    }

    private function tone(int $awayCount, int $freeCount, int $fullFreeCount, int $total): string
    {
        if ($awayCount >= $total) {
            return 'away';
        }
        if ($freeCount === 0) {
            return 'none';
        }
        if ($fullFreeCount >= $total) {
            return 'ok';
        }

        return 'partial';
    }

    private function awayDetail(Worker $worker, CarbonInterface $day): ?string
    {
        $label = $this->availability->awayLabelOn($worker, $day);
        if ($label === 'Vrij op vrijdag') {
            return 'Vrij';
        }

        return $label;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function peopleOnTeam(Worker $worker): array
    {
        $crew = $worker->crewPeople;
        if ($crew->count() === 1) {
            $member = $crew->first();
            $name = trim((string) $member->name);

            return [[
                'id' => (int) $member->id,
                'name' => $name !== '' ? $member->label() : $worker->planName(),
            ]];
        }

        if ($crew->isNotEmpty()) {
            return $crew
                ->map(fn (CrewMember $member): array => [
                    'id' => (int) $member->id,
                    'name' => $member->label(),
                ])
                ->values()
                ->all();
        }

        $names = $worker->crewMembers();
        if ($names !== []) {
            $people = [];
            foreach (array_values($names) as $index => $member) {
                $name = trim((string) ($member['name'] ?? ''));
                $people[] = [
                    'id' => 0,
                    'name' => $name !== '' ? $name : 'Persoon '.($index + 1),
                ];
            }

            return $people;
        }

        $count = $worker->peopleCount();
        if ($count === 1) {
            return [['id' => 0, 'name' => $worker->planName()]];
        }

        $people = [];
        for ($index = 1; $index <= $count; $index++) {
            $people[] = ['id' => 0, 'name' => 'Persoon '.$index];
        }

        return $people;
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     */
    private function memberPlannedHours(Collection $assignments, CarbonInterface $day, CrewMember $member): float
    {
        $intervals = [];
        foreach ($assignments as $assignment) {
            if ($assignment->crewMembers->isEmpty()) {
                continue;
            }
            if (! $assignment->crewMembers->contains(fn (CrewMember $person): bool => (int) $person->id === (int) $member->id)) {
                continue;
            }

            $interval = $assignment->intervalOnDateForMember($day, $member);
            if ($interval === null) {
                continue;
            }

            $intervals[] = $interval;
        }

        return PlanningHours::uniqueHours($intervals);
    }

    private function dayHeading(CarbonInterface $day, bool $withDate): string
    {
        $label = self::DAY_NAMES[(int) $day->dayOfWeekIso] ?? $day->isoFormat('dd');

        return $withDate ? $label.' '.$day->format('j') : $label;
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     */
    private function plannedManDaysOnDate(Worker $worker, Collection $assignments, CarbonInterface $day): float
    {
        $memberIntervals = [];
        $unnamedManDays = 0.0;

        foreach ($assignments as $assignment) {
            $crew = $assignment->crewMembers;
            if ($crew->isNotEmpty()) {
                foreach ($crew as $member) {
                    $interval = $assignment->intervalOnDateForMember($day, $member);
                    if ($interval === null) {
                        continue;
                    }

                    $memberIntervals[(int) $member->id][] = $interval;
                }

                continue;
            }

            $interval = $assignment->intervalOnDate($day);
            if ($interval === null) {
                continue;
            }

            $hours = max(0.0, ($interval[1]->timestamp - $interval[0]->timestamp) / 3600);
            $unnamedManDays += $assignment->peopleCount() * PlanningHours::manDaysFromHours($hours);
        }

        $namedManDays = 0.0;
        foreach ($memberIntervals as $intervals) {
            $namedManDays += PlanningHours::manDaysFromHours(PlanningHours::uniqueHours($intervals));
        }

        $capacity = (float) $worker->peopleCount();

        return min($capacity, round($namedManDays + $unnamedManDays, 4));
    }

    private function summary(string $label, float $planned, float $available, float $remaining): string
    {
        return $label
            .' — '
            .PlanningHours::manDaysLabel($planned)
            .' / '
            .PlanningHours::manDaysLabel($available)
            .' mandagen gepland · '
            .PlanningHours::manDaysLabel($remaining)
            .' vrij';
    }
}
