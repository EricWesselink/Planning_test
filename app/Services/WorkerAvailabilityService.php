<?php

namespace App\Services;

use App\Enums\AvailabilityKind;
use App\Enums\AvailabilitySlot;
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
        $absence = $this->absenceOn($worker, $day, $member);

        return $absence !== null && $absence['full'];
    }

    public function awayLabelOn(Worker $worker, CarbonInterface $day, ?CrewMember $member = null): ?string
    {
        return $this->absenceOn($worker, $day, $member)['label'] ?? null;
    }

    /**
     * @return array{key: string, label: string, short: string, hours: float, full: bool, slot: AvailabilitySlot, hint: ?string, structural: bool}|null
     */
    public function absenceOn(Worker $worker, CarbonInterface $day, ?CrewMember $member = null): ?array
    {
        if ($member instanceof CrewMember) {
            return $this->absenceForMember($worker, $member, $day);
        }

        $crew = $this->crew($worker);
        if ($worker->employment_type === EmploymentType::Eigen && $crew->isNotEmpty()) {
            if ($crew->count() === 1) {
                return $this->absenceForMember($worker, $crew->first(), $day);
            }

            $states = $crew->map(fn (CrewMember $person): ?array => $this->absenceForMember($worker, $person, $day));
            if ($states->every(fn (?array $state): bool => $state !== null && $state['full'])) {
                return $states->first();
            }

            return null;
        }

        if ($worker->unavailable) {
            return $this->structuralAbsence('Niet beschikbaar');
        }

        $date = $day->copy()->startOfDay();
        foreach ($this->windows($worker) as $window) {
            if ($window->crew_member_id !== null) {
                continue;
            }
            if ($window->kind->isAway() && $window->covers($date)) {
                return $this->windowAbsence($window);
            }
        }

        if ($this->hasFridayOff($worker) && $date->isFriday()) {
            return $this->structuralAbsence('Vrij op vrijdag');
        }

        return null;
    }

    public function awayLabelInRange(
        Worker $worker,
        CarbonInterface $start,
        CarbonInterface $end,
        bool $includeSaturday = false,
        bool $includeSunday = false,
        ?string $from = null,
        ?string $to = null,
        ?CrewMember $member = null,
    ): ?string {
        $from = $from ?? PlanningHours::DAY_START;
        $to = $to ?? PlanningHours::DAY_END;
        $day = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($day->lte($last)) {
            if (! PlanningHours::countsOnDate($day, $includeSaturday, $includeSunday)) {
                $day->addDay();

                continue;
            }

            $absence = $this->absenceOn($worker, $day, $member);
            if ($absence === null) {
                $day->addDay();

                continue;
            }

            if ($absence['full'] || $this->absenceOverlapsTimes($worker, $day, $member, $from, $to)) {
                return $absence['label'];
            }
            $day->addDay();
        }

        return null;
    }

    public function rejection(
        Worker $worker,
        CarbonInterface $start,
        CarbonInterface $end,
        bool $includeSaturday = false,
        bool $includeSunday = false,
        ?string $from = null,
        ?string $to = null,
        ?CrewMember $member = null,
    ): ?string {
        $label = $this->awayLabelInRange($worker, $start, $end, $includeSaturday, $includeSunday, $from, $to, $member);
        if ($label === null) {
            return null;
        }

        $name = $member instanceof CrewMember ? $member->label() : $worker->planName();

        return $name.' is '.mb_strtolower($label).'.';
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

    /**
     * @return array{key: string, label: string, short: string, hours: float, full: bool, slot: AvailabilitySlot, hint: ?string, structural: bool}|null
     */
    private function absenceForMember(Worker $worker, CrewMember $member, CarbonInterface $day): ?array
    {
        $member->setRelation('worker', $worker);

        if ($worker->unavailable || $member->unavailable) {
            return $this->structuralAbsence('Niet beschikbaar');
        }

        $date = $day->copy()->startOfDay();
        foreach ($this->windowsFor($worker, $member) as $window) {
            if ($window->kind->isAway() && $window->covers($date)) {
                return $this->windowAbsence($window);
            }
        }

        $isoDay = (int) $date->dayOfWeekIso;
        if ($worker->employment_type === EmploymentType::Eigen && ! $member->worksOn($isoDay)) {
            return $this->structuralAbsence($isoDay === 5 ? 'Vrij op vrijdag' : 'Vrije dag');
        }

        return null;
    }

    /**
     * @return array{key: string, label: string, short: string, hours: float, full: bool, slot: AvailabilitySlot, hint: ?string, structural: bool}
     */
    private function windowAbsence(WorkerAvailability $window): array
    {
        $kind = $window->kind;
        $hours = $window->hoursPerDay();
        $slot = $window->slotValue();
        $full = $window->isFullDay();

        return [
            'key' => match ($kind) {
                AvailabilityKind::Vacation => 'vakantie',
                AvailabilityKind::Sick => 'ziek',
                AvailabilityKind::DayOff => 'vrij',
                AvailabilityKind::Leave => 'verlof',
                AvailabilityKind::Adv => 'adv',
                AvailabilityKind::Course => 'cursus',
                default => 'overig',
            },
            'label' => $kind->awayLabel(),
            'short' => $kind->shortLabel(),
            'hours' => $hours,
            'full' => $full,
            'slot' => $slot,
            'hint' => $full ? null : $slot->hint($hours),
            'structural' => false,
        ];
    }

    /**
     * @return array{key: string, label: string, short: string, hours: float, full: bool, slot: AvailabilitySlot, hint: ?string, structural: bool}
     */
    private function structuralAbsence(string $label): array
    {
        $vrij = $label === 'Vrij op vrijdag' || $label === 'Vrije dag';

        return [
            'key' => $vrij ? 'vrij' : 'overig',
            'label' => $label,
            'short' => $vrij ? 'Vrij' : $label,
            'hours' => (float) PlanningHours::WORKDAY_HOURS,
            'full' => true,
            'slot' => AvailabilitySlot::Full,
            'hint' => null,
            'structural' => true,
        ];
    }

    private function absenceOverlapsTimes(
        Worker $worker,
        CarbonInterface $day,
        ?CrewMember $member,
        string $from,
        string $to,
    ): bool {
        $windows = $member instanceof CrewMember
            ? $this->windowsFor($worker, $member)
            : $this->windows($worker)->filter(fn (WorkerAvailability $window): bool => $window->crew_member_id === null);

        foreach ($windows as $window) {
            if ($window->kind->isAway() && $window->overlapsTimes($day, $from, $to)) {
                return true;
            }
        }

        return false;
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
