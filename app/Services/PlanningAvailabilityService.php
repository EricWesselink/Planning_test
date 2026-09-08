<?php

namespace App\Services;

use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Support\PlanningHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PlanningAvailabilityService
{
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
        $days = $days
            ->filter(fn (CarbonInterface $day): bool => $day->isWeekday())
            ->values();

        if ($days->isEmpty()) {
            return [];
        }

        $workers = Worker::query()
            ->where('active', true)
            ->withLogin()
            ->with(['crewPeople', 'availabilities'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($workers->isEmpty()) {
            return [];
        }

        $assignments = WorkerAssignment::query()
            ->with('crewMembers')
            ->whereIn('worker_id', $workers->modelKeys())
            ->whereDate('end_date', '>=', $days->first())
            ->whereDate('start_date', '<=', $days->last())
            ->get()
            ->groupBy(fn (WorkerAssignment $assignment): int => (int) $assignment->worker_id);

        $rows = [];
        foreach ($workers as $worker) {
            $workDays = $days->filter(
                fn (CarbonInterface $day): bool => ! $this->availability->isAwayOn($worker, $day)
            );
            $available = (float) ($worker->peopleCount() * $workDays->count());
            if ($available < 0.0001) {
                continue;
            }

            $planned = 0.0;
            foreach ($days as $day) {
                $planned += $this->plannedManDaysOnDate(
                    $worker,
                    $assignments->get($worker->id, collect()),
                    $day,
                );
            }
            $planned = round($planned, 2);
            $remaining = round($available - $planned, 2);

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
            ];
        }

        return $rows;
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
