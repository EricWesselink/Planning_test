<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Models\CrewMember;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Support\PlanningHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PersonnelWeekService
{
    public function __construct(
        private WorkerAvailabilityService $availability,
    ) {}

    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @return list<array{
     *     worker: Worker,
     *     member: CrewMember,
     *     cells: list<array{date: string, hours: float, hours_label: string, status: ?string, status_label: ?string}>,
     *     worked_hours: float,
     *     worked_label: string,
     *     absence_hours: float,
     *     absence_label: string
     * }>
     */
    public function forDays(Collection $days): array
    {
        $boardDays = $days->values();
        $workers = Worker::query()
            ->ownStaff()
            ->with(['crewPeople', 'availabilities'])
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $ids = $workers->modelKeys();
        $assignments = $ids === []
            ? collect()
            : WorkerAssignment::query()
                ->with('crewMembers')
                ->whereIn('worker_id', $ids)
                ->when(
                    $boardDays->isNotEmpty(),
                    fn ($query) => $query
                        ->whereDate('end_date', '>=', $boardDays->first())
                        ->whereDate('start_date', '<=', $boardDays->last())
                )
                ->get()
                ->groupBy(fn (WorkerAssignment $assignment): int => (int) $assignment->worker_id);

        $people = [];
        foreach ($workers as $worker) {
            $members = $worker->crewPeople->isNotEmpty()
                ? $worker->crewPeople
                : collect([$worker->crewPeople()->make(['name' => $worker->name, 'sort_order' => 0])]);

            foreach ($members as $member) {
                $member->setRelation('worker', $worker);
                $people[] = $this->personWeek(
                    $worker,
                    $member,
                    $boardDays,
                    $assignments->get($worker->id, collect()),
                );
            }
        }

        usort(
            $people,
            fn (array $left, array $right): int => mb_strtolower($left['member']->displayName())
                <=> mb_strtolower($right['member']->displayName())
        );

        return $people;
    }

    /**
     * @param  Collection<int, CarbonInterface>  $days
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return array{
     *     worker: Worker,
     *     member: CrewMember,
     *     cells: list<array{date: string, hours: float, hours_label: string, status: ?string, status_label: ?string}>,
     *     worked_hours: float,
     *     worked_label: string,
     *     absence_hours: float,
     *     absence_label: string
     * }
     */
    private function personWeek(Worker $worker, CrewMember $member, Collection $days, Collection $assignments): array
    {
        $cells = [];
        $worked = 0.0;
        $absence = 0.0;

        foreach ($days as $day) {
            $status = $this->statusOn($worker, $member, $day);
            $hours = $status === null
                ? $this->plannedHours($worker, $member, $assignments, $day)
                : 0.0;
            if ($status !== null) {
                $absence += PlanningHours::WORKDAY_HOURS;
            } else {
                $worked += $hours;
            }

            $cells[] = [
                'date' => $day->toDateString(),
                'hours' => $hours,
                'hours_label' => $hours > 0.01 ? PlanningHours::hoursLabel($hours) : '',
                'status' => $status['key'] ?? null,
                'status_label' => $status['label'] ?? null,
            ];
        }

        return [
            'worker' => $worker,
            'member' => $member,
            'cells' => $cells,
            'worked_hours' => round($worked, 2),
            'worked_label' => PlanningHours::hoursLabel($worked),
            'absence_hours' => round($absence, 2),
            'absence_label' => PlanningHours::hoursLabel($absence),
        ];
    }

    /**
     * @return array{key: string, label: string}|null
     */
    private function statusOn(Worker $worker, CrewMember $member, CarbonInterface $day): ?array
    {
        $label = $this->availability->awayLabelOn($worker, $day, $member);
        if ($label === null) {
            return null;
        }

        return match ($label) {
            'Vrij op vrijdag', 'Vrije dag' => ['key' => 'vrij', 'label' => 'Vrij'],
            'Vakantie' => ['key' => 'vakantie', 'label' => 'Vakantie'],
            'Ziek' => ['key' => 'ziek', 'label' => 'Ziek'],
            'Verlof' => ['key' => 'verlof', 'label' => 'Verlof'],
            'ADV' => ['key' => 'adv', 'label' => 'ADV'],
            'Cursus' => ['key' => 'cursus', 'label' => 'Cursus'],
            'Overig' => ['key' => 'overig', 'label' => 'Overig'],
            'Niet beschikbaar' => ['key' => 'overig', 'label' => 'Niet beschikbaar'],
            default => ['key' => 'overig', 'label' => $label],
        };
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     */
    private function plannedHours(
        Worker $worker,
        CrewMember $member,
        Collection $assignments,
        CarbonInterface $day,
    ): float {
        if (! $member->exists) {
            return 0.0;
        }

        $intervals = [];
        foreach ($assignments as $assignment) {
            $crew = $assignment->crewMembers;
            if ($crew->isNotEmpty()) {
                if (! $crew->contains(fn (CrewMember $person): bool => (int) $person->id === (int) $member->id)) {
                    continue;
                }
                $interval = $assignment->intervalOnDateForMember($day, $member);
                if ($interval !== null) {
                    $intervals[] = $interval;
                }

                continue;
            }

            if ($worker->employment_type !== EmploymentType::Eigen || $worker->crewPeople->count() > 1) {
                continue;
            }

            $interval = $assignment->intervalOnDate($day);
            if ($interval !== null) {
                $intervals[] = $interval;
            }
        }

        return PlanningHours::uniqueHours($intervals);
    }
}
