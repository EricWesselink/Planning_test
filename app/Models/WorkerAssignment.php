<?php

namespace App\Models;

use App\Enums\AssignmentKind;
use App\Enums\InternalBusinessUnit;
use App\Enums\WorkTicketKind;
use App\Support\PlanningHours;
use App\Support\PlanningWeek;
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
    'kind', 'business_unit', 'description',
    'start_date', 'end_date', 'start_time', 'end_time',
    'include_saturday', 'include_sunday',
    'people_count', 'hours_per_day', 'planned_hours', 'is_provisional', 'origin', 'notes',
    'foreman_crew_member_id', 'work_ticket_crew_member_id',
])]
class WorkerAssignment extends Model
{
    protected $attributes = [
        'people_count' => 1,
        'hours_per_day' => 8,
        'planned_hours' => 8,
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'include_saturday' => false,
        'include_sunday' => false,
        'is_provisional' => false,
        'origin' => 'planned',
        'kind' => 'project',
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
            'is_provisional' => 'boolean',
            'kind' => AssignmentKind::class,
            'business_unit' => InternalBusinessUnit::class,
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

    public function workItems(): BelongsToMany
    {
        return $this->belongsToMany(WorkItem::class, 'work_item_worker_assignment')
            ->withTimestamps()
            ->orderBy('work_items.sort_order')
            ->orderBy('work_items.id');
    }

    /**
     * @return list<int>
     */
    public function linkedWorkItemIds(?Collection $workOrders = null): array
    {
        $ids = [];
        if ($this->relationLoaded('workItems')) {
            $ids = $this->workItems
                ->map(fn (WorkItem $item): int => (int) $item->id)
                ->all();
        } elseif ($this->exists) {
            $ids = $this->workItems()
                ->pluck('work_items.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
        }
        $primary = $this->resolvedWorkItemId($workOrders);
        if ($primary) {
            array_unshift($ids, $primary);
        }

        return array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param  list<int>  $ids
     */
    public function coversWorkIds(array $ids, ?Collection $workOrders = null): bool
    {
        $wanted = array_flip(array_map(static fn (mixed $id): int => (int) $id, $ids));
        foreach ($this->linkedWorkItemIds($workOrders) as $id) {
            if (isset($wanted[$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $ids
     */
    public function syncLinkedWorkItems(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
        if ($this->work_item_id && ! in_array((int) $this->work_item_id, $ids, true)) {
            array_unshift($ids, (int) $this->work_item_id);
        }

        $this->workItems()->sync($ids);
        $this->unsetRelation('workItems');
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

    public function foreman(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class, 'foreman_crew_member_id');
    }

    public function workTicketHolder(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class, 'work_ticket_crew_member_id');
    }

    public function includesCrewMember(?int $crewMemberId): bool
    {
        if ($crewMemberId === null) {
            return true;
        }

        $this->loadMissing('crewMembers');
        if ($this->crewMembers->isEmpty()) {
            return true;
        }

        return $this->crewMembers->contains(
            fn (CrewMember $member): bool => (int) $member->id === $crewMemberId
        );
    }

    public function includesVakman(User $user): bool
    {
        $workerId = $user->scheduledWorkerId();
        if ($workerId === null || (int) $this->worker_id !== $workerId) {
            return false;
        }

        return $this->includesCrewMember($user->scheduledCrewMemberId());
    }

    public function isWorkTicketResponsible(User $user): bool
    {
        if (! $this->includesVakman($user)) {
            return false;
        }

        $holderId = $this->work_ticket_crew_member_id;
        if ($holderId === null) {
            return true;
        }

        return $user->scheduledCrewMemberId() === (int) $holderId;
    }

    public function applyRoles(?int $foremanCrewMemberId, ?int $workTicketCrewMemberId): void
    {
        $this->loadMissing('crewMembers');
        $ids = $this->crewMembers
            ->map(fn (CrewMember $member): int => (int) $member->id)
            ->all();
        $this->foreman_crew_member_id = self::roleIdInCrew($foremanCrewMemberId, $ids);
        $this->work_ticket_crew_member_id = self::roleIdInCrew($workTicketCrewMemberId, $ids);
        $this->save();
    }

    /**
     * @param  list<int>  $crewIds
     */
    public static function roleIdInCrew(?int $id, array $crewIds): ?int
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        return in_array($id, $crewIds, true) ? $id : null;
    }

    public function includesSaturday(): bool
    {
        return (bool) $this->include_saturday;
    }

    public function includesSunday(): bool
    {
        return (bool) $this->include_sunday;
    }

    public function isProvisional(): bool
    {
        return (bool) $this->is_provisional;
    }

    public function isHoursOrigin(): bool
    {
        return $this->origin === 'hours';
    }

    public function isInternal(): bool
    {
        return $this->kind === AssignmentKind::Internal;
    }

    public function internalTitle(): string
    {
        $parts = ['Interne inzet'];
        if ($this->business_unit instanceof InternalBusinessUnit) {
            $parts[] = $this->business_unit->label();
        }
        $description = trim((string) $this->description);
        if ($description !== '') {
            $parts[] = $description;
        }

        return implode(' – ', $parts);
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
        if ($this->isProvisional() || $this->isHoursOrigin()) {
            return 0.0;
        }

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
        if ($this->isProvisional()) {
            return null;
        }

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
        if ($this->isProvisional()) {
            return null;
        }

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
        if ($this->isProvisional()) {
            return 0.0;
        }

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
        bool $isProvisional = false,
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
        $this->is_provisional = $isProvisional;
        if ($isProvisional) {
            $this->hours_per_day = 0;
            $this->planned_hours = 0;

            return;
        }

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
        $names = [];
        $seen = [];
        foreach ($this->crewMembers->sortBy('sort_order') as $member) {
            $label = $member->label();
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $label;
        }

        return $names;
    }

    public function presentNamesLabel(): ?string
    {
        $names = $this->presentNames();

        return $names === [] ? null : implode(', ', $names);
    }

    public function hoursLabel(): string
    {
        if ($this->isHoursOrigin()) {
            return PlanningHours::hoursLabel((float) $this->hours_per_day);
        }

        return PlanningHours::hoursLabel($this->plannedHoursValue());
    }

    public function planningTicketLabel(): ?string
    {
        $this->loadMissing('workTickets');
        if ($this->workTickets->isEmpty()) {
            return null;
        }

        return $this->workTickets
            ->map(fn (WorkTicket $ticket): string => $ticket->kind->label().' '.$ticket->number)
            ->implode(', ');
    }

    public function planningTicketMark(): ?string
    {
        $this->loadMissing('workTickets');
        $ticket = $this->workTickets->first();

        return match ($ticket?->kind) {
            WorkTicketKind::Opdrachtbon => 'OB',
            WorkTicketKind::Werkbon => 'WB',
            default => null,
        };
    }

    public function planningLabel(): string
    {
        if ($this->isInternal()) {
            $unit = $this->business_unit instanceof InternalBusinessUnit
                ? $this->business_unit->label()
                : 'Ander bedrijfsonderdeel';
            $names = $this->presentNamesLabel();

            return $names !== null
                ? 'Interne inzet · '.$unit.' · '.$names
                : 'Interne inzet · '.$unit;
        }

        $this->loadMissing(['workItem.workActivity', 'project', 'worker']);
        if ($this->workItem?->isIntakeTask() && ! $this->isProvisional()) {
            $time = PlanningHours::formatTime($this->startTimeValue());
            $work = trim((string) $this->workItem->name);
            $who = $this->worker?->planName() ?? 'Onbekend';
            $project = trim((string) ($this->project?->name ?? ''));

            return implode(' – ', array_filter(
                [$time, $work !== '' ? $work : null, $project !== '' ? $project : $who],
                fn (?string $part): bool => $part !== null && $part !== '',
            ));
        }

        $team = $this->worker?->planName() ?? 'Onbekend';
        if ($this->isProvisional()) {
            $weeks = $this->weekPeriodLabel();
            $names = $this->presentNamesLabel();

            if ($names !== null) {
                return $team.' · '.$names.' · voorlopig · '.$weeks;
            }

            if ($this->peopleCount() > 1) {
                return $team.' · '.$this->peopleCountLabel().' · voorlopig · '.$weeks;
            }

            return $team.' · voorlopig · '.$weeks;
        }

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
        if ($this->isInternal()) {
            $note = trim((string) $this->notes);

            return implode(' · ', array_values(array_filter([
                $this->internalTitle(),
                $this->dateRangeLabel(),
                $note !== '' ? $note : null,
            ])));
        }

        if ($this->isProvisional()) {
            $lines = array_values(array_filter([
                $this->planningLabel(),
                $projectName !== '' ? $projectName : null,
                $workName !== '' ? $workName : null,
                $this->dateRangeLabel(),
            ]));

            return implode(' · ', $lines);
        }

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

    public function weekPeriodLabel(): string
    {
        $from = PlanningWeek::number($this->start_date);
        $to = PlanningWeek::number($this->end_date);
        if ($from === null || $to === null || $from === $to) {
            return 'week '.($from ?? $to ?? '');
        }

        return 'week '.$from.'–'.$to;
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
        $this->applyRoles($this->foreman_crew_member_id, $this->work_ticket_crew_member_id);
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
        $this->applyRoles($source->foreman_crew_member_id, $source->work_ticket_crew_member_id);
    }
}
