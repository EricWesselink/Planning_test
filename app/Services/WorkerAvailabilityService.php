<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Models\CrewMember;
use App\Models\Worker;
use App\Models\WorkerAvailability;
use App\Support\PlanningHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class WorkerAvailabilityService
{
    public function isAwayOn(Worker $worker, CarbonInterface $day, ?CrewMember $member = null): bool
    {
        return $this->awayLabelOn($worker, $day, $member) !== null;
    }

    public function awayLabelOn(Worker $worker, CarbonInterface $day, ?CrewMember $member = null): ?string
    {
        if ($member instanceof CrewMember) {
            return $this->awayLabelForMember($worker, $member, $day);
        }

        $crew = $this->crew($worker);
        if ($worker->employment_type === EmploymentType::Eigen && $crew->isNotEmpty()) {
            if ($crew->count() === 1) {
                return $this->awayLabelForMember($worker, $crew->first(), $day);
            }

            $labels = $crew->map(fn (CrewMember $person): ?string => $this->awayLabelForMember($worker, $person, $day));
            if ($labels->every(fn (?string $label): bool => $label !== null)) {
                return $labels->first();
            }

            return null;
        }

        if ($worker->unavailable) {
            return 'Niet beschikbaar';
        }

        $date = $day->copy()->startOfDay();

        foreach ($this->windows($worker) as $window) {
            if ($window->crew_member_id !== null) {
                continue;
            }
            if ($window->kind->isAway() && $window->covers($date)) {
                return $window->kind->awayLabel();
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
    public function summaryLines(Worker $worker, ?CrewMember $member = null): array
    {
        $lines = [];
        if ($member instanceof CrewMember) {
            if ($member->unavailable || $worker->unavailable) {
                $lines[] = 'Niet beschikbaar';
            }
            $offDays = $this->offDaySummary($member);
            if ($offDays !== null) {
                $lines[] = $offDays;
            }
            foreach ($this->windowsFor($worker, $member) as $window) {
                $lines[] = $window->summaryLabel();
            }

            return $lines;
        }

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

    private function awayLabelForMember(Worker $worker, CrewMember $member, CarbonInterface $day): ?string
    {
        $member->setRelation('worker', $worker);

        if ($worker->unavailable || $member->unavailable) {
            return 'Niet beschikbaar';
        }

        $date = $day->copy()->startOfDay();

        foreach ($this->windowsFor($worker, $member) as $window) {
            if ($window->kind->isAway() && $window->covers($date)) {
                return $window->kind->awayLabel();
            }
        }

        $isoDay = (int) $date->dayOfWeekIso;
        if ($worker->employment_type === EmploymentType::Eigen && ! $member->worksOn($isoDay)) {
            return $isoDay === 5 ? 'Vrij op vrijdag' : 'Vrije dag';
        }

        return null;
    }

    private function offDaySummary(CrewMember $member): ?string
    {
        $labels = [];
        foreach (CrewMember::WEEKDAY_LABELS as $isoDay => $label) {
            if (! $member->worksOn($isoDay)) {
                $labels[] = $label;
            }
        }

        return $labels === [] ? null : 'Vrij: '.implode(', ', $labels);
    }

    private function hasFridayOff(Worker $worker): bool
    {
        if ($worker->employment_type !== EmploymentType::Eigen) {
            return false;
        }

        if ($worker->friday_off) {
            return true;
        }

        $crew = $this->crew($worker);

        return $crew->isNotEmpty() && $crew->every(function (CrewMember $member) use ($worker): bool {
            $member->setRelation('worker', $worker);

            return $member->hasFridayOff();
        });
    }

    /**
     * @return Collection<int, CrewMember>
     */
    private function crew(Worker $worker): Collection
    {
        if ($worker->relationLoaded('crewPeople')) {
            return $worker->crewPeople;
        }

        return $worker->crewPeople()->get();
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

    /**
     * @return Collection<int, WorkerAvailability>
     */
    private function windowsFor(Worker $worker, CrewMember $member): Collection
    {
        return $this->windows($worker)
            ->filter(fn (WorkerAvailability $window): bool => $window->crew_member_id === null
                || (int) $window->crew_member_id === (int) $member->id)
            ->values();
    }
}
