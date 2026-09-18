<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Models\CrewMember;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PlanningFitService
{
    public function __construct(
        private WorkerAvailabilityService $availability,
    ) {}

    /**
     * @return array{specialty: array{key: string, label: string}, workers: list<array<string, mixed>>}
     */
    public function candidates(
        WorkItem $item,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $startTime,
        ?string $endTime,
        ?int $ignoreAssignmentId = null,
        bool $includeSaturday = false,
        bool $includeSunday = false,
    ): array {
        $item->loadMissing(['workActivity', 'project']);
        $specialty = $item->requiredSpecialty();
        $skipSkill = $item->skipsSkillMatch();
        $from = PlanningHours::normalizeTime($startTime, PlanningHours::DAY_START);
        $to = PlanningHours::normalizeTime($endTime, PlanningHours::DAY_END);
        $workers = $this->activeWorkers();
        if ($item->isIntakeTask()) {
            $workers = $workers
                ->filter(fn (Worker $worker): bool => $worker->employment_type === EmploymentType::Eigen)
                ->values();
        }
        $assignments = $this->assignmentsFor($workers, $start, $end, $ignoreAssignmentId);

        return [
            'specialty' => $specialty,
            'workers' => $workers
                ->map(fn (Worker $worker): array => $this->presentWorker(
                    $worker,
                    $specialty,
                    $assignments->get($worker->id, collect()),
                    $start,
                    $end,
                    $from,
                    $to,
                    $skipSkill,
                    $includeSaturday,
                    $includeSunday,
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array{id: int, name: string, selectable: bool, status: string, status_label: string}>
     */
    public function shopCandidates(
        ?CarbonInterface $start = null,
        ?CarbonInterface $end = null,
        ?int $ignoreProjectId = null,
    ): array {
        $workers = $this->activeWorkers();
        $specialty = ['key' => 'winkel', 'label' => 'Winkelwerk'];

        if ($start === null || $end === null) {
            return $workers
                ->map(fn (Worker $worker): array => [
                    'id' => (int) $worker->id,
                    'name' => $worker->planName(),
                    'selectable' => true,
                    'status' => 'available',
                    'status_label' => '',
                ])
                ->values()
                ->all();
        }

        $from = PlanningHours::DAY_START.':00';
        $to = PlanningHours::DAY_END.':00';
        $assignments = $this->assignmentsFor($workers, $start, $end, null, $ignoreProjectId);

        return $workers
            ->map(fn (Worker $worker): array => $this->presentWorker(
                $worker,
                $specialty,
                $assignments->get($worker->id, collect()),
                $start,
                $end,
                $from,
                $to,
                true,
            ))
            ->map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'selectable' => (bool) $row['selectable'],
                'status' => (string) $row['status'],
                'status_label' => (string) $row['status_label'],
            ])
            ->values()
            ->all();
    }

    public function shopRejection(
        Worker $worker,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $ignoreProjectId = null,
    ): ?string {
        $row = collect($this->shopCandidates($start, $end, $ignoreProjectId))
            ->firstWhere('id', (int) $worker->id);

        if ($row === null) {
            return 'Deze vakman is niet beschikbaar.';
        }

        if ($row['selectable']) {
            return null;
        }

        $label = trim($row['status_label']);
        if ($label !== '') {
            return $worker->planName().' is niet vrij ('.$label.').';
        }

        return $worker->planName().' is in deze periode niet vrij.';
    }

    /**
     * @param  list<int>  $crewIds
     */
    public function skillRejection(
        Worker $worker,
        WorkItem $item,
        array $crewIds = [],
    ): ?string {
        $item->loadMissing(['workActivity', 'project']);
        if ($item->isIntakeTask() && $worker->employment_type !== EmploymentType::Eigen) {
            return $worker->planName().' is geen eigen medewerker voor '.$item->requiredSpecialty()['label'].'.';
        }

        if ($item->skipsSkillMatch()) {
            return null;
        }

        $worker->loadMissing('crewPeople');
        $specialty = $item->requiredSpecialty();
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $crewIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids !== []) {
            foreach ($ids as $id) {
                $member = $worker->crewPeople->firstWhere('id', $id);
                if (! $member instanceof CrewMember) {
                    continue;
                }
                $member->setRelation('worker', $worker);
                if (! $this->personHasSkill($member, $worker, $specialty)) {
                    return $member->label().' heeft geen vakkennis voor '.$specialty['label'].'.';
                }
            }

            return null;
        }

        if (! $this->workerHasSkill($worker, $specialty)) {
            return $worker->planName().' heeft geen vakkennis voor '.$specialty['label'].'.';
        }

        return null;
    }

    /**
     * @param  Collection<int, Worker>  $workers
     * @return Collection<int, Collection<int, WorkerAssignment>>
     */
    /**
     * @return Collection<int, Worker>
     */
    private function activeWorkers(): Collection
    {
        return Worker::query()
            ->where('active', true)
            ->with(['crewPeople', 'availabilities'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Worker>  $workers
     * @return Collection<int, Collection<int, WorkerAssignment>>
     */
    private function assignmentsFor(
        Collection $workers,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $ignoreAssignmentId,
        ?int $ignoreProjectId = null,
    ): Collection {
        if ($workers->isEmpty()) {
            return collect();
        }

        return WorkerAssignment::query()
            ->with('crewMembers')
            ->whereIn('worker_id', $workers->modelKeys())
            ->when($ignoreAssignmentId, fn ($query) => $query->where('id', '!=', $ignoreAssignmentId))
            ->when($ignoreProjectId, fn ($query) => $query->where('project_id', '!=', $ignoreProjectId))
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (WorkerAssignment $assignment): int => (int) $assignment->worker_id);
    }

    /**
     * @param  array{key: string, label: string}  $specialty
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return array<string, mixed>
     */
    private function presentWorker(
        Worker $worker,
        array $specialty,
        Collection $assignments,
        CarbonInterface $start,
        CarbonInterface $end,
        string $from,
        string $to,
        bool $skipSkill,
        bool $includeSaturday = false,
        bool $includeSunday = false,
    ): array {
        $people = $worker->crewPeople;
        if ($people->count() >= 2) {
            return $this->presentTeam($worker, $people, $specialty, $assignments, $start, $end, $from, $to, $skipSkill, $includeSaturday, $includeSunday);
        }

        $member = $people->first();
        if ($member instanceof CrewMember) {
            $member->setRelation('worker', $worker);
        }
        $person = $this->presentPerson(
            $skipSkill || $this->personHasSkill($member, $worker, $specialty),
            $this->busyInterval($assignments, $worker, $member?->id, $start, $end, $from, $to, $includeSaturday, $includeSunday)
                ?? $this->availability->awayLabelInRange($worker, $start, $end, $includeSaturday, $includeSunday),
            $specialty['label'],
        );

        return [
            'id' => $worker->id,
            'name' => $worker->planName(),
            'people_count' => $worker->peopleCount(),
            'selectable' => $person['selectable'],
            'status' => $person['status'],
            'status_label' => $person['status_label'],
            'crew' => $member instanceof CrewMember
                ? [array_merge($person, ['id' => $member->id, 'name' => $member->label()])]
                : [],
        ];
    }

    /**
     * @param  Collection<int, CrewMember>  $people
     * @param  array{key: string, label: string}  $specialty
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return array<string, mixed>
     */
    private function presentTeam(
        Worker $worker,
        Collection $people,
        array $specialty,
        Collection $assignments,
        CarbonInterface $start,
        CarbonInterface $end,
        string $from,
        string $to,
        bool $skipSkill,
        bool $includeSaturday = false,
        bool $includeSunday = false,
    ): array {
        $crew = [];
        $suitable = 0;
        foreach ($people as $member) {
            $member->setRelation('worker', $worker);
            $person = $this->presentPerson(
                $skipSkill || $this->personHasSkill($member, $worker, $specialty),
                $this->busyInterval($assignments, $worker, $member->id, $start, $end, $from, $to, $includeSaturday, $includeSunday)
                    ?? $this->availability->awayLabelInRange($worker, $start, $end, $includeSaturday, $includeSunday),
                $specialty['label'],
            );
            $crew[] = array_merge($person, [
                'id' => $member->id,
                'name' => $member->label(),
            ]);
            if ($person['selectable']) {
                $suitable++;
            }
        }

        $total = $people->count();

        return [
            'id' => $worker->id,
            'name' => $worker->planName(),
            'people_count' => $worker->peopleCount(),
            'selectable' => $suitable > 0,
            'status' => $suitable > 0 ? 'partial' : 'unavailable',
            'status_label' => $suitable.'/'.$total.' geschikt en beschikbaar',
            'suitable_count' => $suitable,
            'total_count' => $total,
            'crew' => $crew,
        ];
    }

    /**
     * @return array{selectable: bool, status: string, status_label: string}
     */
    private function presentPerson(bool $hasSkill, ?string $busyLabel, string $specialtyLabel): array
    {
        if (! $hasSkill) {
            return [
                'selectable' => false,
                'status' => 'no_skill',
                'status_label' => 'Geen vakkennis: '.$specialtyLabel,
            ];
        }

        if ($busyLabel !== null) {
            return [
                'selectable' => false,
                'status' => 'busy',
                'status_label' => $busyLabel,
            ];
        }

        return [
            'selectable' => true,
            'status' => 'available',
            'status_label' => 'Beschikbaar',
        ];
    }

    /**
     * @param  array{key: string, label: string}  $specialty
     */
    private function personHasSkill(?CrewMember $member, Worker $worker, array $specialty): bool
    {
        if ($member instanceof CrewMember) {
            return $member->hasSpecialty($specialty['key']) || $member->hasSpecialty($specialty['label']);
        }

        return $this->workerHasSkill($worker, $specialty);
    }

    public function awayRejection(Worker $worker, CarbonInterface $start, CarbonInterface $end, bool $includeSaturday = false, bool $includeSunday = false): ?string
    {
        $worker->loadMissing('availabilities');

        return $this->availability->rejection($worker, $start, $end, $includeSaturday, $includeSunday);
    }

    /**
     * @param  array{key: string, label: string}  $specialty
     */
    private function workerHasSkill(Worker $worker, array $specialty): bool
    {
        return $worker->hasSpecialty($specialty['key']) || $worker->hasSpecialty($specialty['label']);
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     */
    private function busyInterval(
        Collection $assignments,
        Worker $worker,
        ?int $crewMemberId,
        CarbonInterface $start,
        CarbonInterface $end,
        string $from,
        string $to,
        bool $includeSaturday = false,
        bool $includeSunday = false,
    ): ?string {
        $day = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($day->lte($last)) {
            $window = PlanningHours::intervalOnDate($day, $start, $end, $from, $to, $includeSaturday, $includeSunday);
            if ($window === null) {
                $day->addDay();

                continue;
            }

            foreach ($assignments as $assignment) {
                $interval = $this->assignmentIntervalOnDate($assignment, $worker, $crewMemberId, $day);
                if ($interval === null || ! PlanningHours::intervalsOverlap(
                    $interval[0],
                    $interval[1],
                    $window[0],
                    $window[1],
                )) {
                    continue;
                }

                return 'Bezet '.PlanningHours::formatTime($interval[0]->format('H:i:s')).'-'.PlanningHours::formatTime($interval[1]->format('H:i:s'));
            }

            $day->addDay();
        }

        return null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function assignmentIntervalOnDate(
        WorkerAssignment $assignment,
        Worker $worker,
        ?int $crewMemberId,
        CarbonInterface $day,
    ): ?array {
        $crew = $assignment->crewMembers;
        if ($crew->isNotEmpty()) {
            if ($crewMemberId === null) {
                return $assignment->intervalOnDate($day);
            }
            $member = $crew->firstWhere('id', $crewMemberId);

            return $member instanceof CrewMember
                ? $assignment->intervalOnDateForMember($day, $member)
                : null;
        }

        if ($crewMemberId !== null && $assignment->peopleCount() < $worker->peopleCount()) {
            return null;
        }

        return $assignment->intervalOnDate($day);
    }
}
