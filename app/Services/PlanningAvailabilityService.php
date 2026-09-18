<?php

namespace App\Services;

use App\Enums\EmploymentType;
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
            $this->build($days, false)['teams'],
        );
    }

    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @return array{days: list<array{date: string, label: string}>, teams: list<array<string, mixed>>}
     */
    public function overview(Collection $days): array
    {
        return $this->build($days, true);
    }

    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @return array{days: list<array{date: string, label: string}>, teams: list<array<string, mixed>>}
     */
    private function build(Collection $days, bool $ownProductionOnly): array
    {
        $boardDays = $days->values();
        $capacityDays = $boardDays
            ->filter(fn (CarbonInterface $day): bool => $this->countsTowardCapacity($day))
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

        $query = Worker::query()
            ->where('active', true)
            ->with(['crewPeople', 'availabilities'])
            ->orderBy('name')
            ->orderBy('id');
        if ($ownProductionOnly) {
            $query->ownStaff();
        }

        $workers = $query->get();
        if ($ownProductionOnly) {
            $workers = $workers->filter->doesProductionFloorWork()->values();
        }

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
        $initialsByKey = $this->initialsForWorkers($workers);

        $rows = [];
        foreach ($workers as $worker) {
            $available = 0.0;
            foreach ($capacityDays as $day) {
                if (! $this->countsTowardCapacityFor($worker, $day)) {
                    continue;
                }
                $crew = $worker->crewPeople;
                if ($crew->isNotEmpty()) {
                    foreach ($crew as $member) {
                        if (! $this->availability->isAwayOn($worker, $day, $member)) {
                            $available += 1;
                        }
                    }

                    continue;
                }

                if (! $this->availability->isAwayOn($worker, $day)) {
                    $available += $worker->peopleCount();
                }
            }
            $available = round($available, 2);
            $planned = 0.0;
            foreach ($capacityDays as $day) {
                if (! $this->countsTowardCapacityFor($worker, $day)) {
                    continue;
                }
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
                    $initialsByKey,
                );
            }

            $rows[] = [
                'worker_id' => (int) $worker->id,
                'label' => $this->teamLabel($worker),
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
     * @param  array<string, string>  $initialsByKey
     * @return array{date: string, tone: string, free_count: int, label: string, people: list<array<string, mixed>>}
     */
    private function dayCell(Worker $worker, Collection $assignments, CarbonInterface $day, array $initialsByKey): array
    {
        $away = $this->awayDetail($worker, $day);
        $people = $this->peopleOnTeam($worker);
        $crew = $worker->crewPeople;
        $namedToday = $assignments->contains(
            fn (WorkerAssignment $assignment): bool => $assignment->crewMembers->isNotEmpty()
                && $assignment->intervalOnDate($day) !== null
        );
        $rows = $crew->isNotEmpty() && $namedToday
            ? $this->namedPeopleForDay($worker, $crew, $assignments, $day, $initialsByKey)
            : $this->unnamedPeopleForDay($worker, $people, $assignments, $day, $away, $initialsByKey);

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
            'label' => $this->cellLabel($worker, $freeCount, $away, $rows),
            'people' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function cellLabel(Worker $worker, int $freeCount, ?string $away, array $rows): string
    {
        if ($worker->peopleCount() > 1) {
            return implode(' | ', array_column($rows, 'chip'));
        }

        if ($away !== null) {
            return $away;
        }

        $person = $rows[0] ?? [];
        $status = (string) ($person['status'] ?? 'busy');
        if ($status === 'free') {
            return 'Beschikbaar';
        }
        if ($status === 'partial') {
            return 'Deels vrij · '.PlanningHours::hoursLabel((float) ($person['remaining_hours'] ?? 0));
        }

        return 'Bezet';
    }

    /**
     * @param  Collection<int, CrewMember>  $crew
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  array<string, string>  $initialsByKey
     * @return list<array<string, mixed>>
     */
    private function namedPeopleForDay(
        Worker $worker,
        Collection $crew,
        Collection $assignments,
        CarbonInterface $day,
        array $initialsByKey,
    ): array {
        $rows = [];
        foreach ($crew->values() as $index => $member) {
            $away = $this->awayDetail($worker, $day, $member);
            $plannedHours = $away === null
                ? $this->memberPlannedHours($assignments, $day, $member)
                : PlanningHours::WORKDAY_HOURS;
            $name = trim((string) $member->name);
            $label = $name !== ''
                ? $member->label()
                : ($crew->count() === 1 ? $worker->planName() : $member->label());
            $person = ['id' => (int) $member->id, 'name' => $label];
            $rows[] = $this->personRow(
                (int) $member->id,
                $label,
                $plannedHours,
                $away,
                $initialsByKey[$this->personKey($worker, $person, $index)] ?? $this->personInitials($label),
            );
        }

        return $rows;
    }

    /**
     * @param  list<array{id: int, name: string}>  $people
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  array<string, string>  $initialsByKey
     * @return list<array<string, mixed>>
     */
    private function unnamedPeopleForDay(
        Worker $worker,
        array $people,
        Collection $assignments,
        CarbonInterface $day,
        ?string $away,
        array $initialsByKey,
    ): array {
        $capacityHours = count($people) * PlanningHours::WORKDAY_HOURS;
        $plannedHours = $away === null
            ? round($this->plannedManDaysOnDate($worker, $assignments, $day) * PlanningHours::WORKDAY_HOURS, 2)
            : $capacityHours;
        $remainingPool = max(0.0, $capacityHours - $plannedHours);
        $rows = [];
        foreach ($people as $index => $person) {
            $remaining = min(PlanningHours::WORKDAY_HOURS, $remainingPool);
            $remainingPool = round($remainingPool - $remaining, 2);
            $name = (string) $person['name'];
            $rows[] = $this->personRow(
                (int) $person['id'],
                $name,
                PlanningHours::WORKDAY_HOURS - $remaining,
                $away,
                $initialsByKey[$this->personKey($worker, $person, $index)] ?? $this->personInitials($name),
            );
        }

        return $rows;
    }

    /**
     * @return array{id: int, name: string, status: string, mark: string, detail: string, remaining_hours: float, selectable: bool, given: string, chip: string, tone: string}
     */
    private function personRow(int $id, string $name, float $plannedHours, ?string $away, string $given): array
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
                'given' => $given,
                'chip' => $given.' '.$this->awayChip($away),
                'tone' => $this->personTone('away', $away),
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
                'given' => $given,
                'chip' => $given.' bezet',
                'tone' => 'none',
            ];
        }

        $full = $remaining >= PlanningHours::WORKDAY_HOURS - 0.01;

        return [
            'id' => $id,
            'name' => $name,
            'status' => $full ? 'free' : 'partial',
            'mark' => '✓',
            'detail' => $full ? PlanningHours::hoursLabel($remaining).' vrij' : 'nog '.PlanningHours::hoursLabel($remaining).' vrij',
            'remaining_hours' => $remaining,
            'selectable' => true,
            'given' => $given,
            'chip' => $full
                ? $given.' vrij'
                : $given.' '.PlanningHours::hoursLabel($remaining).' vrij',
            'tone' => $full ? 'ok' : 'partial',
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

    private function awayDetail(Worker $worker, CarbonInterface $day, ?CrewMember $member = null): ?string
    {
        $label = $this->availability->awayLabelOn($worker, $day, $member);
        if ($label === 'Vrij op vrijdag') {
            return 'Vrije dag';
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

    private function teamLabel(Worker $worker): string
    {
        $people = $this->peopleOnTeam($worker);
        if (count($people) < 2) {
            return $worker->planName();
        }

        $plan = mb_strtolower(trim($worker->planName()));
        $planFirst = mb_strtolower($this->firstName($worker->planName()));
        $matchesPerson = false;
        foreach ($people as $person) {
            $name = mb_strtolower($person['name']);
            $first = mb_strtolower($this->firstName($person['name']));
            if ($name === $plan || ($planFirst !== '' && $first === $planFirst)) {
                $matchesPerson = true;
                break;
            }
        }
        if (! $matchesPerson) {
            return $worker->planName();
        }

        return collect($people)
            ->map(fn (array $person): string => $this->firstName($person['name']))
            ->implode(' / ');
    }

    /**
     * @param  Collection<int, Worker>  $workers
     * @return array<string, string>
     */
    private function initialsForWorkers(Collection $workers): array
    {
        $keys = [];
        $names = [];
        foreach ($workers as $worker) {
            foreach ($this->peopleOnTeam($worker) as $index => $person) {
                $keys[] = $this->personKey($worker, $person, $index);
                $names[] = $person['name'];
            }
        }

        $map = [];
        foreach ($this->uniqueInitials($names) as $index => $initials) {
            $map[$keys[$index]] = $initials;
        }

        return $map;
    }

    /**
     * @param  array{id: int, name: string}  $person
     */
    private function personKey(Worker $worker, array $person, int $index): string
    {
        return (int) $worker->id.'-'.(int) $person['id'].'-'.$index;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function uniqueInitials(array $names): array
    {
        $used = [];
        $result = [];
        foreach ($names as $name) {
            $base = $this->personInitials($name);
            $candidate = $base;
            $n = 2;
            $first = $this->firstName($name);
            $last = $this->lastName($name);
            while (isset($used[$candidate])) {
                if ($last !== '' && $n <= mb_strlen($last)) {
                    $candidate = mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, $n));
                } else {
                    $candidate = $base.$n;
                }
                $n++;
            }
            $used[$candidate] = true;
            $result[] = $candidate;
        }

        return $result;
    }

    private function personInitials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '?';
        }
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
    }

    private function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $parts[0] ?? $name;
    }

    private function lastName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 2) {
            return '';
        }

        return $parts[count($parts) - 1];
    }

    private function awayChip(string $away): string
    {
        return match ($away) {
            'Vrije dag' => 'vrije dag',
            'Vrij op vrijdag' => 'vrije dag',
            'Vakantie' => 'vakantie',
            'Ziek' => 'ziek',
            'Verlof' => 'verlof',
            'ADV' => 'adv',
            'Cursus' => 'cursus',
            'Overig' => 'overig',
            'Niet beschikbaar' => 'afwezig',
            default => mb_strtolower($away),
        };
    }

    private function personTone(string $status, ?string $away = null): string
    {
        if ($status !== 'away') {
            return match ($status) {
                'free' => 'ok',
                'partial' => 'partial',
                default => 'none',
            };
        }

        return match ($away) {
            'Vakantie' => 'vacation',
            'Ziek' => 'sick',
            'Verlof' => 'leave',
            default => 'away',
        };
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

    private function countsTowardCapacity(CarbonInterface $day): bool
    {
        $isoDay = (int) $day->dayOfWeekIso;

        return $isoDay >= 1 && $isoDay <= 6;
    }

    private function countsTowardCapacityFor(Worker $worker, CarbonInterface $day): bool
    {
        if (! $this->countsTowardCapacity($day)) {
            return false;
        }

        if (! $day->isSaturday()) {
            return true;
        }

        return $worker->employment_type === EmploymentType::Eigen
            && $worker->crewPeople->isNotEmpty();
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
