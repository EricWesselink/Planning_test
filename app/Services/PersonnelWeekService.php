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
    /**
     * @var array<string, string>
     */
    private const KIND_LABELS = [
        'vrij' => 'Vrij',
        'vakantie' => 'Vakantie',
        'ziek' => 'Ziek',
        'verlof' => 'Verlof',
        'adv' => 'ADV',
        'cursus' => 'Cursus',
        'overig' => 'Overig',
    ];

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
     *     absence_label: string,
     *     week_summary: string
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
     *     absence_label: string,
     *     week_summary: string
     * }
     */
    private function personWeek(Worker $worker, CrewMember $member, Collection $days, Collection $assignments): array
    {
        $cells = [];
        $worked = 0.0;
        $absence = 0.0;
        $kindHours = array_fill_keys(array_keys(self::KIND_LABELS), 0.0);

        foreach ($days as $day) {
            $state = $this->availability->absenceOn($worker, $day, $member);
            $planned = $this->plannedHours($worker, $member, $assignments, $day);
            $absenceHours = (float) ($state['hours'] ?? 0.0);
            $workedHours = max(0.0, min($planned, PlanningHours::WORKDAY_HOURS - $absenceHours));
            $incidental = $state !== null && ! ($state['structural'] ?? false);
            $statusLabel = null;
            if ($state !== null) {
                if ($incidental) {
                    $kindHours[$state['key']] = ($kindHours[$state['key']] ?? 0.0) + $absenceHours;
                    $absence += $absenceHours;
                }
                if ($state['full'] || $workedHours < 0.01) {
                    $statusLabel = $state['full']
                        ? $state['short']
                        : PlanningHours::hoursLabel($absenceHours).' '.$state['short'];
                }
            }
            $worked += $workedHours;

            $cells[] = [
                'date' => $day->toDateString(),
                'hours' => $workedHours,
                'hours_label' => $workedHours > 0.01 ? PlanningHours::hoursLabel($workedHours) : '',
                'status' => $state['key'] ?? null,
                'status_label' => $statusLabel,
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
            'week_summary' => $this->weekSummary($worked, $absence, $kindHours),
        ];
    }

    /**
     * @param  array<string, float>  $kindHours
     */
    private function weekSummary(float $worked, float $absence, array $kindHours): string
    {
        $parts = ['Gewerkt '.PlanningHours::hoursLabel($worked)];
        foreach (self::KIND_LABELS as $key => $label) {
            if (($kindHours[$key] ?? 0.0) > 0.01) {
                $parts[] = $label.' '.PlanningHours::hoursLabel($kindHours[$key]);
            }
        }
        $parts[] = 'Totaal afwezig '.PlanningHours::hoursLabel($absence);

        return implode(' | ', $parts);
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
