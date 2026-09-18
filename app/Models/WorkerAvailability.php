<?php

namespace App\Models;

use App\Enums\AvailabilityKind;
use App\Enums\AvailabilitySlot;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['worker_id', 'crew_member_id', 'start_date', 'end_date', 'kind', 'hours', 'slot'])]
class WorkerAvailability extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'hours' => 8,
        'slot' => 'full',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'kind' => AvailabilityKind::class,
            'slot' => AvailabilitySlot::class,
            'hours' => 'float',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }

    public function covers(CarbonInterface $day): bool
    {
        return $day->betweenIncluded($this->start_date, $this->end_date);
    }

    public function hoursPerDay(): float
    {
        return max(0.0, min(PlanningHours::WORKDAY_HOURS, (float) ($this->hours ?? PlanningHours::WORKDAY_HOURS)));
    }

    public function slotValue(): AvailabilitySlot
    {
        return $this->slot instanceof AvailabilitySlot
            ? $this->slot
            : AvailabilitySlot::tryFrom((string) $this->slot) ?? AvailabilitySlot::Full;
    }

    public function isFullDay(): bool
    {
        return $this->slotValue() === AvailabilitySlot::Full
            || $this->hoursPerDay() >= PlanningHours::WORKDAY_HOURS - 0.01;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function intervalOn(CarbonInterface $day): ?array
    {
        if (! $this->covers($day) || ! $this->kind->isAway()) {
            return null;
        }

        $date = $day->toDateString();
        $minutes = (int) round($this->hoursPerDay() * 60);
        if ($this->isFullDay()) {
            return [
                Carbon::parse($date.' '.PlanningHours::DAY_START.':00'),
                Carbon::parse($date.' '.PlanningHours::DAY_END.':00'),
            ];
        }

        if ($this->slotValue() === AvailabilitySlot::Afternoon) {
            $end = Carbon::parse($date.' '.PlanningHours::DAY_END.':00');

            return [$end->copy()->subMinutes($minutes), $end];
        }

        $start = Carbon::parse($date.' '.PlanningHours::DAY_START.':00');

        return [$start, $start->copy()->addMinutes($minutes)];
    }

    public function overlapsTimes(CarbonInterface $day, string $from, string $to): bool
    {
        $interval = $this->intervalOn($day);
        if ($interval === null) {
            return false;
        }

        $start = Carbon::parse($day->toDateString().' '.PlanningHours::normalizeTime($from, PlanningHours::DAY_START));
        $end = Carbon::parse($day->toDateString().' '.PlanningHours::normalizeTime($to, PlanningHours::DAY_END));

        return PlanningHours::intervalsOverlap($interval[0], $interval[1], $start, $end);
    }

    public function totalHoursFor(?CrewMember $member = null): float
    {
        $perDay = $this->hoursPerDay();
        $days = 0;
        $day = $this->start_date->copy()->startOfDay();
        $last = $this->end_date->copy()->startOfDay();
        while ($day->lte($last)) {
            $iso = (int) $day->dayOfWeekIso;
            if ($member instanceof CrewMember) {
                if ($member->worksOn($iso)) {
                    $days++;
                }
            } elseif ($iso >= 1 && $iso <= 5) {
                $days++;
            }
            $day->addDay();
        }

        return $days * $perDay;
    }

    public function rangeLabel(): string
    {
        if ($this->start_date->isSameDay($this->end_date)) {
            return $this->start_date->translatedFormat('j M');
        }

        return $this->start_date->translatedFormat('j M').' – '.$this->end_date->translatedFormat('j M');
    }

    public function compactRangeLabel(): string
    {
        if ($this->start_date->isSameDay($this->end_date)) {
            return $this->start_date->translatedFormat('j M');
        }

        if ($this->start_date->month === $this->end_date->month && $this->start_date->year === $this->end_date->year) {
            return $this->start_date->format('j').'–'.$this->end_date->translatedFormat('j M');
        }

        return $this->start_date->translatedFormat('j M').'–'.$this->end_date->translatedFormat('j M');
    }

    public function summaryLabel(): string
    {
        return $this->kind->shortLabel().' '.$this->rangeLabel();
    }

    public function chipLabel(?CrewMember $member = null): string
    {
        return $this->compactRangeLabel().' · '.$this->kind->shortLabel().' · '.PlanningHours::hoursLabel($this->totalHoursFor($member));
    }
}
