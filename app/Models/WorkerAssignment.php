<?php

namespace App\Models;

use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'worker_id', 'project_id', 'work_item_id', 'team_id',
    'start_date', 'end_date', 'start_time', 'end_time',
    'include_saturday', 'include_sunday',
    'people_count', 'hours_per_day', 'planned_hours', 'notes',
])]
class WorkerAssignment extends Model
{
    protected $attributes = [
        'people_count' => 1,
        'hours_per_day' => 8,
        'planned_hours' => 8,
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'include_saturday' => true,
        'include_sunday' => false,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'people_count' => 'integer',
            'hours_per_day' => 'decimal:2',
            'planned_hours' => 'decimal:2',
            'include_saturday' => 'boolean',
            'include_sunday' => 'boolean',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function resolvedWorkItemId(?Collection $workOrders = null): ?int
    {
        if ($this->work_item_id) {
            return (int) $this->work_item_id;
        }

        $orders = $workOrders ?? ($this->relationLoaded('project') ? $this->project->workOrders : null);
        if ($orders === null || $orders->isEmpty()) {
            return null;
        }

        $id = $orders->firstWhere('worker_id', $this->worker_id)?->work_item_id;

        return $id ? (int) $id : null;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function workTickets(): HasMany
    {
        return $this->hasMany(WorkTicket::class)->orderByDesc('id');
    }

    protected static function booted(): void
    {
        static::saved(function (self $assignment): void {
            if (! $assignment->wasChanged(['start_date', 'end_date', 'project_id', 'worker_id'])) {
                return;
            }

            $assignment->workTickets()->update([
                'start_date' => $assignment->start_date->toDateString(),
                'end_date' => $assignment->end_date->toDateString(),
                'project_id' => $assignment->project_id,
                'worker_id' => $assignment->worker_id,
            ]);
        });
    }

    public function crewMembers(): BelongsToMany
    {
        return $this->belongsToMany(CrewMember::class, 'crew_member_worker_assignment')
            ->withPivot(['start_time', 'end_time', 'planned_hours'])
            ->withTimestamps()
            ->orderBy('crew_members.sort_order')
            ->orderBy('crew_members.id');
    }

    public function includesSaturday(): bool
    {
        return (bool) $this->include_saturday;
    }

    public function includesSunday(): bool
    {
        return (bool) $this->include_sunday;
    }

    public function coversDate(CarbonInterface $date): bool
    {
        return $date->betweenIncluded($this->start_date, $this->end_date)
            && PlanningHours::countsOnDate($date, $this->includesSaturday(), $this->includesSunday());
    }

    public function startTimeValue(): string
    {
        return PlanningHours::normalizeTime($this->start_time, PlanningHours::DAY_START);
    }

    public function endTimeValue(): string
    {
        return PlanningHours::normalizeTime($this->end_time, PlanningHours::DAY_END);
    }

    public function plannedHoursValue(): float
    {
        return PlanningHours::totalHours(
            $this->start_date,
            $this->end_date,
            $this->startTimeValue(),
            $this->endTimeValue(),
            $this->includesSaturday(),
            $this->includesSunday(),
        );
    }

    public function plannedPersonHours(): float
    {
        if ($this->relationLoaded('crewMembers') && $this->crewMembers->isNotEmpty()) {
            return (float) $this->crewMembers->sum(function (CrewMember $member): float {
                return PlanningHours::totalHours(
                    $this->start_date,
                    $this->end_date,
                    PlanningHours::normalizeTime($member->pivot?->start_time, $this->startTimeValue()),
                    PlanningHours::normalizeTime($member->pivot?->end_time, $this->endTimeValue()),
                    $this->includesSaturday(),
                    $this->includesSunday(),
                );
            });
        }

        return $this->plannedHoursValue() * $this->peopleCount();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function intervalOnDate(CarbonInterface $date): ?array
    {
        return PlanningHours::intervalOnDate(
            $date,
            $this->start_date,
            $this->end_date,
            $this->startTimeValue(),
            $this->endTimeValue(),
            $this->includesSaturday(),
            $this->includesSunday(),
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function intervalOnDateForMember(CarbonInterface $date, ?CrewMember $member): ?array
    {
        if ($member === null || $member->pivot === null) {
            return $this->intervalOnDate($date);
        }

        $startTime = $member->pivot->start_time ?: $this->startTimeValue();
        $endTime = $member->pivot->end_time ?: $this->endTimeValue();

        return PlanningHours::intervalOnDate(
            $date,
            $this->start_date,
            $this->end_date,
            $startTime,
            $endTime,
            $this->includesSaturday(),
            $this->includesSunday(),
        );
    }

    public function hoursOnDate(CarbonInterface $date): float
    {
        return PlanningHours::hoursOnDate(
            $date,
            $this->start_date,
            $this->end_date,
            $this->startTimeValue(),
            $this->endTimeValue(),
            $this->includesSaturday(),
            $this->includesSunday(),
        );
    }

    public function overlapsInterval(CarbonInterface $start, CarbonInterface $end): bool
    {
        $day = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($day->lte($last)) {
            $interval = $this->intervalOnDate($day);
            $window = PlanningHours::intervalOnDate(
                $day,
                $start,
                $end,
                $start->format('H:i:s'),
                $end->format('H:i:s'),
                $this->includesSaturday(),
                $this->includesSunday(),
            );
            if ($interval !== null && $window !== null && PlanningHours::intervalsOverlap(
                $interval[0],
                $interval[1],
                $window[0],
                $window[1],
            )) {
                return true;
            }
            $day->addDay();
        }

        return false;
    }

    public function applySchedule(
        CarbonInterface $start,
        CarbonInterface $end,
        string $startTime,
        string $endTime,
        ?bool $includeSaturday = null,
        ?bool $includeSunday = null,
    ): void {
        if ($includeSaturday !== null) {
            $this->include_saturday = $includeSaturday;
        }
        if ($includeSunday !== null) {
            $this->include_sunday = $includeSunday;
        }

        $this->start_date = $start->toDateString();
        $this->end_date = $end->toDateString();
        $this->start_time = PlanningHours::normalizeTime($startTime, PlanningHours::DAY_START);
        $this->end_time = PlanningHours::normalizeTime($endTime, PlanningHours::DAY_END);
        $duration = PlanningHours::hoursBetween($this->start_time, $this->end_time);
        $this->hours_per_day = $start->isSameDay($end) ? $duration : PlanningHours::WORKDAY_HOURS;
        $this->planned_hours = PlanningHours::totalHours(
            $start,
            $end,
            $this->start_time,
            $this->end_time,
            $this->includesSaturday(),
            $this->includesSunday(),
        );
    }

    public function schedulePivot(): array
    {
        return [
            'start_time' => $this->startTimeValue(),
            'end_time' => $this->endTimeValue(),
            'planned_hours' => $this->plannedHoursValue(),
        ];
    }

    public function peopleCount(): int
    {
        if ($this->relationLoaded('crewMembers') && $this->crewMembers->isNotEmpty()) {
            return max(1, $this->crewMembers->count());
        }

        return max(1, (int) $this->people_count);
    }

    public function peopleCountLabel(): string
    {
        $count = $this->peopleCount();

        return $count === 1 ? '1 persoon' : $count.' personen';
    }

    /**
     * @return list<string>
     */
    public function presentNames(): array
    {
        return $this->crewMembers
            ->sortBy('sort_order')
            ->map(fn (CrewMember $member): string => $member->label())
            ->values()
            ->all();
    }

    public function presentNamesLabel(): ?string
    {
        $names = $this->presentNames();

        return $names === [] ? null : implode(', ', $names);
    }

    public function hoursLabel(): string
    {
        return PlanningHours::hoursLabel($this->plannedHoursValue());
    }

    public function planningLabel(): string
    {
        $team = $this->worker?->planName() ?? 'Onbekend';
        $hours = $this->hoursLabel();
        $names = $this->presentNamesLabel();

        if ($names !== null) {
            return $team.' · '.$names.' · '.$hours;
        }

        if ($this->peopleCount() > 1) {
            return $team.' · '.$this->peopleCountLabel().' · '.$hours;
        }

        return $team.' · '.$hours;
    }

    public function detailTitle(string $workName, string $projectName = ''): string
    {
        $lines = array_values(array_filter([
            $this->planningLabel(),
            $projectName !== '' ? $projectName : null,
            $workName !== '' ? $workName : null,
            $this->dateRangeLabel(),
            $this->timeRangeLabel(),
            $this->dailyHoursLabel(),
            'Totaal '.$this->hoursLabel(),
        ]));

        return implode(' · ', $lines);
    }

    public function dateRangeLabel(): string
    {
        if ($this->start_date->isSameDay($this->end_date)) {
            return $this->start_date->translatedFormat('D j M Y');
        }

        return $this->start_date->translatedFormat('D j M').' – '.$this->end_date->translatedFormat('D j M Y');
    }

    public function timeRangeLabel(): string
    {
        return PlanningHours::formatTime($this->startTimeValue()).'–'.PlanningHours::formatTime($this->endTimeValue());
    }

    public function dailyHoursLabel(): string
    {
        $parts = [];
        $day = $this->start_date->copy()->startOfDay();
        $last = $this->end_date->copy()->startOfDay();
        while ($day->lte($last)) {
            $hours = $this->hoursOnDate($day);
            if ($hours > 0) {
                $parts[] = $day->translatedFormat('D').' '.PlanningHours::hoursLabel($hours);
            }
            $day->addDay();
        }

        return implode(', ', $parts);
    }

    /**
     * @param  list<int>  $crewMemberIds
     * @param  array<int, array{start_time?: string, end_time?: string, planned_hours?: float|int}>  $hoursById
     */
    public function syncPresentCrew(array $crewMemberIds, array $hoursById = []): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $crewMemberIds),
            static fn (int $id): bool => $id > 0,
        )));
        $sync = [];
        foreach ($ids as $id) {
            $sync[$id] = array_merge($this->schedulePivot(), $hoursById[$id] ?? []);
        }
        $this->crewMembers()->sync($sync);
        $this->people_count = max(1, count($ids) ?: (int) $this->people_count);
        $this->save();
        $this->unsetRelation('crewMembers');
        $this->load('crewMembers');
    }

    public function copyPresentCrewFrom(WorkerAssignment $source): void
    {
        $members = $source->relationLoaded('crewMembers')
            ? $source->crewMembers
            : $source->crewMembers()->get();

        if ($members->isEmpty()) {
            return;
        }

        $sync = [];
        foreach ($members as $member) {
            $sync[(int) $member->id] = [
                'start_time' => $member->pivot?->start_time ?: $this->startTimeValue(),
                'end_time' => $member->pivot?->end_time ?: $this->endTimeValue(),
                'planned_hours' => $member->pivot?->planned_hours ?: $this->plannedHoursValue(),
            ];
        }

        $this->crewMembers()->sync($sync);
        $this->unsetRelation('crewMembers');
        $this->load('crewMembers');
    }
}
