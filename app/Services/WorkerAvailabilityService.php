<?php

namespace App\Services;

use App\Enums\AvailabilityKind;
use App\Enums\EmploymentType;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Support\PlanningHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class WorkerAvailabilityService
{
    public function isAwayOn(Worker $worker, CarbonInterface $day): bool
    {
        return $this->awayLabelOn($worker, $day) !== null;
    }

    public function awayLabelOn(Worker $worker, CarbonInterface $day): ?string
    {
        if ($worker->unavailable) {
            return 'Niet beschikbaar';
        }

        $date = $day->copy()->startOfDay();

        foreach ($this->windows($worker) as $window) {
            if ($window->kind === AvailabilityKind::Unavailable && $window->covers($date)) {
                return 'Niet beschikbaar';
            }
        }

        if ($this->hasFridayOff($worker) && $date->isFriday()) {
            return 'Vrij op vrijdag';
        }

        return null;
    }

    public function awayLabelInRange(Worker $worker, CarbonInterface $start, CarbonInterface $end, bool $includeSaturday = false, bool $includeSunday = false): ?string
    {
        $day = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($day->lte($last)) {
            if (! PlanningHours::countsOnDate($day, $includeSaturday, $includeSunday)) {
                $day->addDay();

                continue;
            }

            $label = $this->awayLabelOn($worker, $day);
            if ($label !== null) {
                return $label;
            }
            $day->addDay();
        }

        return null;
    }

    public function rejection(Worker $worker, CarbonInterface $start, CarbonInterface $end, bool $includeSaturday = false, bool $includeSunday = false): ?string
    {
        $label = $this->awayLabelInRange($worker, $start, $end, $includeSaturday, $includeSunday);
        if ($label === null) {
            return null;
        }

        return $worker->planName().' is '.mb_strtolower($label).'.';
    }

    /**
     * @return list<string>
     */
    public function summaryLines(Worker $worker): array
    {
        $lines = [];
        if ($worker->unavailable) {
            $lines[] = 'Niet beschikbaar';
        }
        if ($this->hasFridayOff($worker)) {
            $lines[] = 'Vrij op vrijdag';
        }

        foreach ($this->windows($worker) as $window) {
            $lines[] = $window->summaryLabel();
        }

        return $lines;
    }

    private function hasFridayOff(Worker $worker): bool
    {
        return $worker->employment_type === EmploymentType::Eigen && $worker->friday_off;
    }

    /**
     * @return Collection<int, WorkerAvailability>
     */
    private function windows(Worker $worker): Collection
    {
        if ($worker->relationLoaded('availabilities')) {
            return $worker->availabilities;
        }

        return $worker->availabilities()->get();
    }
}
