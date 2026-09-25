<?php

namespace App\Services;

use App\Enums\AssignmentKind;
use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\SmallWorkType;
use App\Enums\TimeEntryStatus;
use App\Enums\WorkPhase;
use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\WorkActivity;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use App\Support\Format;
use App\Support\PlanningHours;
use App\Support\PlanningLaborForecast;
use App\Support\WorkType;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlanningBoardService
{
    public const WEEK_OPTIONS = [1, 2, 3, 4, 6, 8];

    /** @var array<int, string> */
    public const DAY_OPTIONS = [
        1 => 'Maandag',
        2 => 'Dinsdag',
        3 => 'Woensdag',
        4 => 'Donderdag',
        5 => 'Vrijdag',
        6 => 'Zaterdag',
    ];

    public function __construct(
        private ConflictService $conflicts,
        private PlanningAvailabilityService $availability,
        private ProjectLaborCalculator $labor,
    ) {}

    /** @var array<int, Collection<string, Collection<int, WorkItem>>> */
    private array $quantityGroupsByProject = [];

    /** @var array<int, list<array{id: int, name: string, group: string, notes: string, type_key: string, project_id: int, project: string, member_ids: list<int>}>> */
    private array $plannableChoicesByProject = [];

    public function weekStart(?string $week, ?int $weekNr = null, ?int $year = null): Carbon
    {
        if ($weekNr !== null && $weekNr >= 1 && $weekNr <= 53) {
            $isoYear = ($year !== null && $year >= 2000 && $year <= 2100)
                ? $year
                : ($week ? Carbon::parse($week)->isoWeekYear : now()->isoWeekYear);
            $maxWeek = (int) Carbon::now()->setISODate($isoYear, 1)->isoWeeksInYear();
            $weekNr = min($weekNr, max(1, $maxWeek));

            return Carbon::now()->setISODate($isoYear, $weekNr, Carbon::MONDAY)->startOfDay();
        }

        $date = $week ? Carbon::parse($week) : now();

        return $date->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    public function weeks(Request $request): int
    {
        $weeks = (int) $request->integer('weeks', 1);

        return in_array($weeks, self::WEEK_OPTIONS, true) ? $weeks : 1;
    }

    public function weekDays(Carbon $start, int $weeks = 1): Collection
    {
        $days = collect();
        for ($week = 0; $week < $weeks; $week++) {
            // Ma–za: zaterdag zichtbaar, zondag blijft buiten het planbord.
            for ($day = 0; $day < 6; $day++) {
                $days->push($start->copy()->addWeeks($week)->addDays($day));
            }
        }

        return $days;
    }

    public function build(Request $request): array
    {
        $requestedStart = $this->weekStart(
            $request->string('week')->toString() ?: null,
            $this->optionalInt($request->input('week_nr')),
            $this->optionalInt($request->input('year')),
        );
        [$weekStart, $weeks, $printPeriod] = $this->periodWindow($request, $requestedStart);
        $days = $this->weekDays($weekStart, $weeks);
        $dayOfWeek = $this->dayOfWeekFilter($request);
        if ($dayOfWeek !== null) {
            $filteredDays = $days
                ->filter(fn (Carbon $day): bool => $day->dayOfWeekIso === $dayOfWeek)
                ->values();
            if ($filteredDays->isNotEmpty()) {
                $days = $filteredDays;
            } else {
                $dayOfWeek = null;
            }
        }
        $weekBands = $this->weekBands($days, $weeks);
        $kindFilter = $this->kindFilter($request);
        $planningStand = $this->planningStand($request);
        $scheduledWorkerId = $request->user()?->scheduledWorkerId();
        [$filterWorkerId, $filterCrewMemberId, $whoValue] = $this->whoFilter($request);
        $workerId = $scheduledWorkerId ?? $filterWorkerId;
        $crewMemberId = $scheduledWorkerId ? null : $filterCrewMemberId;
        $canViewLabor = $request->user()?->canViewLaborCosts() ?? false;
        $windowStart = $days->first();
        $windowEnd = $days->last();

        $relations = [
            'customer',
            'workActivities.category',
            'workItems.planningActivity',
            'workItems.progressEntries.crewMember',
            'workItems.progressEntries.worker',
            'workItems.workOrders.worker',
            'workOrders.worker',
            'workOrders.workItem',
        ];
        if ($canViewLabor) {
            $relations[] = 'assignments.worker';
            $relations[] = 'assignments.crewMembers';
        } else {
            $relations[] = 'assignments';
        }

        $projectQuery = Project::query()
            ->accessibleBy($request->user())
            ->active()
            ->planningStand($planningStand)
            ->with($relations)
            ->when($kindFilter !== null, fn ($q) => $this->constrainKind($q, $kindFilter))
            ->when($request->filled('project_id'), fn ($q) => $q->where('id', $request->integer('project_id')));

        $projects = $projectQuery
            ->orderBy('planned_start_date')
            ->get()
            ->sortBy([
                fn (Project $project): int => $project->isWinkel() ? 1 : 0,
                fn (Project $project): int => $project->planned_start_date?->timestamp ?? PHP_INT_MAX,
                fn (Project $project): int => $project->id,
            ])
            ->values();

        $hoursView = $this->hoursView($request, $windowEnd);
        $assignments = WorkerAssignment::query()
            ->with(['worker', 'workItem', 'workItems', 'team', 'crewMembers', 'workTickets', 'foreman', 'workTicketHolder'])
            ->whereHas('project', function ($q) use ($request, $kindFilter, $planningStand): void {
                $q->active()->accessibleBy($request->user())->planningStand($planningStand);
                $this->constrainKind($q, $kindFilter);
            })
            ->coveringDates($windowStart, $windowEnd)
            ->when($workerId, fn ($q) => $q->where('worker_id', $workerId))
            ->when($crewMemberId, fn ($q) => $this->constrainCrewMember($q, $crewMemberId))
            ->when(
                $hoursView !== 'actual',
                fn ($q) => $q->where(function ($query): void {
                    $query->whereNull('origin')->orWhere('origin', '!=', 'hours');
                }),
            )
            ->get();

        $internalAssignments = WorkerAssignment::query()
            ->with(['worker', 'team', 'crewMembers'])
            ->where('kind', AssignmentKind::Internal)
            ->coveringDates($windowStart, $windowEnd)
            ->when($workerId, fn ($q) => $q->where('worker_id', $workerId))
            ->when($crewMemberId, fn ($q) => $this->constrainCrewMember($q, $crewMemberId))
            ->get();

        $this->applyApprovedHours($assignments, $hoursView !== 'actual');
        $this->applyUniqueBarHours($assignments->concat($internalAssignments));

        if ($workerId || $crewMemberId) {
            $projects = $projects->whereIn('id', $assignments->pluck('project_id'))->values();
        }

        $occupancy = $assignments->concat($internalAssignments);
        $doubleBooked = $this->conflicts->doubleBookedMap($occupancy, $days);
        $rows = [];

        foreach ($projects as $project) {
            $projectAssignments = $assignments->where('project_id', $project->id)->sortBy(fn ($a) => $a->worker?->name);
            $usedIds = [];
            $workRows = [];
            $projectWarnings = [];
            $workOrdersByItem = $this->loadedWorkOrdersByItem($project);
            $labor = $canViewLabor ? $this->labor->for($project) : [
                'groups' => [],
                'items_by_id' => [],
                'overrun_label' => null,
            ];
            $this->restoreWorkOrders($project, $workOrdersByItem);

            if ($project->isSmallWork()) {
                $rows[] = $this->smallProjectRow($project, $projectAssignments, $days, $doubleBooked, $labor, $canViewLabor);

                continue;
            }

            $extraItems = $project->workItems
                ->filter(fn (WorkItem $item): bool => $item->isExtraWork())
                ->values();

            if ($project->isWinkel()) {
                [$workRows, $usedIds] = $this->winkelWorkRows($project, $projectAssignments, $days, $doubleBooked, $usedIds, $labor);
            } else {
                $groupChoices = [];
                foreach ($this->quantityWorkGroups($project) as $packageKey => $items) {
                    $ordered = (float) $items->sum(fn (WorkItem $item): float => (float) $item->ordered_quantity);
                    $linkedQty = (float) $items->sum(
                        fn (WorkItem $item): float => $item->begrote_hoeveelheid === null ? 0.0 : (float) $item->begrote_hoeveelheid
                    );
                    $budgetHours = (float) $items->sum(
                        fn (WorkItem $item): float => $item->begrote_uren === null ? 0.0 : (float) $item->begrote_uren
                    );
                    if (! $this->groupIsOnTheBoard($items)) {
                        continue;
                    }
                    $shownQty = $ordered > 0.0001 ? $ordered : $linkedQty;
                    $isOndergrond = $packageKey === 'ondergrond';
                    ['primary' => $primary, 'title' => $title, 'default_title' => $defaultTitle, 'planning_work_activity_id' => $planningActivityId] = $this->boardGroupHeading($isOndergrond, $items);
                    $groupChoices[] = $this->workChoice($project, $primary, $title, $items);
                    $ids = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $done = (float) $items->sum(fn (WorkItem $item) => $item->completedQuantity());
                    $rest = (float) $items->sum(fn (WorkItem $item) => $item->remainingQuantity());
                    $itemWarnings = [];
                    foreach ($items as $item) {
                        if ($item->planned_end_date && $item->remainingQuantity() > 0 && $item->planned_end_date->lte(now()->addDays(2))) {
                            $itemWarnings[] = 'Achter';
                            $projectWarnings[] = $item->name.' loopt achter';
                        }
                    }

                    $who = $items->flatMap(fn (WorkItem $item) => $item->workOrders->map(fn ($order) => $order->worker?->displayName()))->filter()->unique()->values();
                    $personBars = [];

                    foreach ($projectAssignments as $assignment) {
                        if (! $assignment->coversWorkIds($ids, $project->workOrders)) {
                            continue;
                        }

                        $workName = $assignment->workItem?->typeLabel() ?? $primary->typeLabel();
                        $before = count($personBars);
                        [$personBars, $usedIds] = $this->appendAssignmentPersonBars(
                            $personBars,
                            $usedIds,
                            $assignment,
                            $days,
                            $doubleBooked,
                            $workName,
                            $project->name,
                        );
                        if ($this->coversSeveralBoardLines($assignment, $project)) {
                            $share = null;
                            foreach ($items as $item) {
                                $hours = $assignment->hoursForWorkItem((int) $item->id);
                                if ($hours !== null) {
                                    $share = $hours;
                                    break;
                                }
                            }
                            for ($index = $before; $index < count($personBars); $index++) {
                                $personBars[$index]['work_item_id'] = (int) $primary->id;
                                if ($share === null) {
                                    continue;
                                }
                                $personBars[$index]['label'] = $assignment->planningShareLabel($share);
                                $personBars[$index]['label_short'] = $assignment->planningShareLabel($share, false);
                                $slice = $this->shareSlice($assignment, (int) $primary->id);
                                if ($slice === null) {
                                    continue;
                                }
                                $personBars[$index]['bar']['start_offset'] = $slice['start'];
                                $personBars[$index]['bar']['end_offset'] = $slice['end'];
                                $personBars[$index]['start_time'] = $slice['start_time'];
                                $personBars[$index]['end_time'] = $slice['end_time'];
                            }
                        }
                    }

                    $budgetHours = round($budgetHours, 2);
                    $personBars = $this->decorateBarsWithBudget(
                        $personBars,
                        $projectAssignments,
                        $ids,
                        $budgetHours,
                        $project->workOrders,
                    );

                    $starts = $items->pluck('planned_start_date')->filter();
                    $ends = $items->pluck('planned_end_date')->filter();
                    $steps = $isOndergrond && $items->count() > 1
                        ? $items->sortBy(fn (WorkItem $item) => $item->phase()->sort())->map(fn (WorkItem $item) => $item->name)->values()->all()
                        : [];
                    $itemLabor = $labor['groups'][$packageKey] ?? null;

                    $workRows[] = [
                        'type' => 'work',
                        'id' => $primary->id,
                        'project_id' => $project->id,
                        'title' => $title,
                        'default_title' => $defaultTitle,
                        'planning_work_activity_id' => $planningActivityId,
                        'steps' => $steps,
                        'is_ondergrond' => $isOndergrond,
                        'unit' => $primary->unit->label(),
                        'ordered' => $shownQty,
                        'ordered_decimals' => $ordered <= 0.0001 && fmod($shownQty, 1.0) !== 0.0 ? 2 : 0,
                        'empty_quantity' => $shownQty <= 0.0001,
                        'planning_included' => $this->planningIncluded($items),
                        'completed' => $done,
                        'remaining' => $rest,
                        'percent' => $this->progressPercent($done, $ordered),
                        'who' => $who,
                        'bar' => $this->bar($starts->min(), $ends->max(), $days),
                        'person_bars' => $personBars,
                        'bar_count' => $this->stackedBarCount($personBars),
                        'warnings' => array_values(array_unique($itemWarnings)),
                        'status' => $primary->status,
                        'labor' => $itemLabor,
                    ];
                }
                $this->plannableChoicesByProject[(int) $project->id] = array_merge(
                    $groupChoices,
                    $this->extraWorkChoices($project),
                );
            }

            foreach ($workRows as $index => $row) {
                if (array_key_exists('labor', $row)) {
                    continue;
                }
                $itemLabor = $labor['items_by_id'][$row['id'] ?? 0] ?? null;
                $workRows[$index]['labor'] = $itemLabor;
            }

            if ($scheduledWorkerId !== null) {
                $workRows = array_values(array_filter(
                    $workRows,
                    fn (array $row): bool => ($row['bar_count'] ?? 0) > 0
                ));
            }

            [$extraRows, $usedIds] = $this->extraCompactRows(
                $project,
                $extraItems,
                $projectAssignments,
                $days,
                $doubleBooked,
                $usedIds,
                $labor,
                $canViewLabor,
            );

            $leftoverBars = [];
            foreach ($projectAssignments as $assignment) {
                if (in_array($assignment->id, $usedIds, true)) {
                    continue;
                }
                $workName = $assignment->workItem?->typeLabel() ?? 'inzet';
                [$leftoverBars] = $this->appendAssignmentPersonBars(
                    $leftoverBars,
                    $usedIds,
                    $assignment,
                    $days,
                    $doubleBooked,
                    $workName,
                    $project->name,
                );
            }
            $leftoverBars = $this->decorateLeftoverBars($leftoverBars, $projectAssignments, $project);

            $executors = $project->workOrders
                ->merge($projectAssignments)
                ->map(fn ($row) => $row->worker?->displayName())
                ->filter()
                ->unique()
                ->values();

            $period = $this->projectPeriod($project, $days);
            $startWeek = $project->planned_start_date
                ? $project->planned_start_date->copy()->startOfWeek(Carbon::MONDAY)->toDateString()
                : null;
            $quantities = $this->projectQuantities($workRows);

            if ($kindFilter !== ProjectKind::KLEINE_FILTER) {
                $rows[] = [
                    'type' => 'project',
                    'id' => $project->id,
                    'kind' => $project->kind?->value,
                    'badge' => $project->isWinkel() ? (string) config('company.shop_name') : null,
                    'compact' => false,
                    'sort_bucket' => $this->sortBucket($project->planned_start_date, $project->planned_end_date, $days),
                    'sort_date' => $project->planned_start_date?->toDateString() ?? '',
                    'number' => $project->project_number,
                    'work_code' => $project->isWinkel() ? null : $project->workCode(),
                    'numbers_label' => $project->isWinkel() ? '' : $project->labeledNumbersLine(),
                    'numbers_short' => $project->isWinkel() ? '' : implode(' · ', array_values(array_filter([
                        $project->workCode(),
                        $project->workNumber() !== '' ? $project->workNumber() : null,
                    ]))),
                    'title' => $project->displayTitle(),
                    'subtitle' => $project->isWinkel() ? $project->shopWorkLine() : null,
                    'customer' => $project->customer?->name,
                    'address' => $project->address,
                    'postal_code' => $project->postal_code,
                    'city' => $project->city,
                    'naw_line' => $project->nawLine(),
                    'maps_url' => $project->googleMapsUrl(),
                    'who' => $executors,
                    'status' => $project->status->label(),
                    'done' => in_array($project->status, [ProjectStatus::Gereed, ProjectStatus::Opgeleverd], true),
                    'planning_afgerond' => $project->isPlanningFinished(),
                    'bar' => $period['bar'],
                    'start_marker' => $period['start_marker'],
                    'end_marker' => $period['end_marker'],
                    'werk_start' => $period['werk_start'],
                    'missing_craftsman' => $period['missing_craftsman'],
                    'start_week' => $startWeek,
                    'person_bars' => $leftoverBars,
                    'bar_count' => $this->stackedBarCount($leftoverBars),
                    'warnings' => array_values(array_unique(array_filter([
                        ...$projectWarnings,
                        $labor['overrun_label'] ?? null,
                    ]))),
                    'ordered' => $quantities['ordered'],
                    'completed' => $quantities['completed'],
                    'remaining' => $quantities['remaining'],
                    'percent' => $quantities['percent'],
                    'unit' => $quantities['unit'],
                    'labor' => $canViewLabor ? $labor : null,
                    'children' => $workRows,
                ];
            }

            foreach ($extraRows as $extraRow) {
                $rows[] = $extraRow;
            }
        }

        $internalRows = $this->internalRows($internalAssignments, $days, $doubleBooked);

        $doubleFilter = $this->doubleBookingPresentation($request, $doubleBooked, $occupancy);
        if ($doubleFilter['active']) {
            $rows = $this->filterRowsToAssignments($rows, $doubleFilter['assignment_ids']);
        }

        $rows = $this->groupRowsByKind($rows, $kindFilter);
        if ($doubleFilter['active']) {
            $internalRows = $this->filterRowsToAssignments($internalRows, $doubleFilter['assignment_ids']);
        }
        $rows = $this->withProjectDayCrew([...$internalRows, ...$rows], $days);
        $warnings = $doubleFilter['warnings'];

        $availabilityOverview = $scheduledWorkerId
            ? ['days' => [], 'teams' => []]
            : $this->availability->overview($days, $workerId);
        $teamManDays = $availabilityOverview['teams'];

        return [
            'weekStart' => $weekStart,
            'weeks' => $weeks,
            'weekBands' => $weekBands,
            'weekRangeLabel' => $this->periodRangeLabel($printPeriod, $requestedStart, $weekBands),
            'prevWeek' => $requestedStart->copy()->subWeeks($this->weeks($request))->toDateString(),
            'nextWeek' => $requestedStart->copy()->addWeeks($this->weeks($request))->toDateString(),
            'thisWeek' => now()->startOfWeek(Carbon::MONDAY)->startOfDay()->toDateString(),
            'days' => $days,
            'dayCrewTotals' => $this->dayCrewTotals($rows, $days),
            'dayCount' => $days->count(),
            'dayMin' => $days->count() === 1 ? 0 : ($weeks === 1 ? 180 : ($weeks <= 3 ? 120 : ($weeks <= 8 ? 96 : 56))),
            'rows' => $rows,
            'projects' => $this->filterProjects($request, $kindFilter, $planningStand),
            'planningStand' => $planningStand,
            'warnings' => $warnings,
            'doubleFilter' => [
                'active' => $doubleFilter['active'],
                'count' => $doubleFilter['count'],
                'label' => $doubleFilter['label'],
            ],
            'period' => $printPeriod,
            'periodFallback' => $request->input('period') === 'work' && $printPeriod !== 'work',
            'filters' => [
                'week' => $requestedStart->toDateString(),
                'weeks' => $printPeriod === '' ? $weeks : $this->weeks($request),
                'period' => $printPeriod,
                'kind' => $kindFilter instanceof ProjectKind ? $kindFilter->value : (string) ($kindFilter ?? ''),
                'project_id' => $request->input('project_id'),
                'who' => $scheduledWorkerId ? '' : $whoValue,
                'worker_id' => $scheduledWorkerId ?? ($whoValue !== '' ? null : $filterWorkerId),
                'day' => $dayOfWeek === null ? '' : (string) $dayOfWeek,
                'hours_view' => $hoursView,
                'stand' => $planningStand === 'actief' ? '' : $planningStand,
                'doubles' => $doubleFilter['active'] ? '1' : '',
                'double_crew' => $doubleFilter['crew_id'] ?? '',
                'double_worker' => $doubleFilter['worker_id'] ?? '',
            ],
            'hoursView' => $hoursView,
            'weekOptions' => self::WEEK_OPTIONS,
            'teamManDays' => $teamManDays,
            'availabilityDays' => $availabilityOverview['days'],
            'availableManDays' => round((float) collect($teamManDays)->sum('remaining'), 2),
        ];
    }

    /**
     * @return array{0: Carbon, 1: int, 2: string}
     */
    private function periodWindow(Request $request, Carbon $weekStart): array
    {
        $period = $this->period($request);

        if ($period === 'month') {
            $start = $weekStart->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY)->startOfDay();
            $end = $weekStart->copy()->endOfMonth()->startOfWeek(Carbon::MONDAY)->startOfDay();

            return [$start, $this->countWeeks($start, $end), $period];
        }

        if ($period === 'work') {
            $range = $this->workDateRange($request);
            if ($range !== null) {
                $start = $range[0]->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
                $end = $range[1]->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

                return [$start, $this->countWeeks($start, $end), $period];
            }

            return [$weekStart, 1, 'week'];
        }

        if ($period === 'week') {
            return [$weekStart, 1, $period];
        }

        return [$weekStart, $this->weeks($request), ''];
    }

    private function period(Request $request): string
    {
        $value = (string) $request->input('period', '');

        return in_array($value, ['week', 'month', 'work'], true) ? $value : '';
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function workDateRange(Request $request): ?array
    {
        if (! $request->filled('project_id')) {
            return null;
        }

        $project = Project::query()
            ->accessibleBy($request->user())
            ->with(['workItems', 'assignments'])
            ->find($request->integer('project_id'));

        if ($project === null) {
            return null;
        }

        $dates = collect([
            $project->planned_start_date,
            $project->planned_end_date,
        ])
            ->merge($project->workItems->pluck('planned_start_date'))
            ->merge($project->workItems->pluck('planned_end_date'))
            ->merge($project->assignments->pluck('start_date'))
            ->merge($project->assignments->pluck('end_date'))
            ->filter();

        if ($dates->isEmpty()) {
            return null;
        }

        /** @var CarbonInterface $min */
        $min = $dates->min();
        /** @var CarbonInterface $max */
        $max = $dates->max();

        return [$min->copy()->startOfDay(), $max->copy()->startOfDay()];
    }

    private function countWeeks(Carbon $start, Carbon $end): int
    {
        $weeks = 1;
        $cursor = $start->copy()->addWeek();
        while ($cursor->lte($end) && $weeks < 26) {
            $weeks++;
            $cursor->addWeek();
        }

        return $weeks;
    }

    private function periodRangeLabel(string $period, Carbon $anchor, Collection $bands): string
    {
        $weeks = $this->weekRangeLabel($bands);

        return match ($period) {
            'month' => $anchor->translatedFormat('F Y'),
            'work' => trim('Gehele werk · '.$weeks),
            default => $weeks,
        };
    }

    private function planningStand(Request $request): string
    {
        $value = (string) $request->input('stand', 'actief');

        return in_array($value, ['actief', 'afgerond', 'alles'], true) ? $value : 'actief';
    }

    private function kindFilter(Request $request): ProjectKind|string|null
    {
        $value = (string) $request->input('kind', '');
        if ($value === ProjectKind::KLEINE_FILTER) {
            return $value;
        }

        return ProjectKind::tryFrom($value);
    }

    private function constrainKind(Builder $query, ProjectKind|string|null $kindFilter): void
    {
        if ($kindFilter instanceof ProjectKind) {
            $query->where('kind', $kindFilter);

            return;
        }

        if ($kindFilter === ProjectKind::KLEINE_FILTER) {
            $query->where(function (Builder $inner): void {
                $inner->whereIn('kind', ProjectKind::smallWorkCases())
                    ->orWhereHas('workItems', fn (Builder $items) => $items->where('is_extra_work', true));
            });
        }
    }

    /**
     * @return Collection<int, Project>
     */
    private function filterProjects(
        Request $request,
        ProjectKind|string|null $kindFilter,
        string $planningStand,
    ): Collection {
        return Project::query()
            ->accessibleBy($request->user())
            ->active()
            ->planningStand($planningStand)
            ->with('customer:id,name')
            ->when($kindFilter !== null, fn (Builder $query) => $this->constrainKind($query, $kindFilter))
            ->get([
                'id',
                'customer_id',
                'project_number',
                'name',
                'address',
                'postal_code',
                'city',
                'work_address',
                'kind',
                'notes',
                'work_description',
                'planning_afgerond',
            ])
            ->sortBy(fn (Project $project): array => [
                mb_strtolower($project->workCode() ?: $project->workNumber()),
                mb_strtolower($project->workNumber()),
                $project->id,
            ])
            ->values();
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: string}
     */
    private function whoFilter(Request $request): array
    {
        $who = trim((string) $request->input('who', ''));
        if (preg_match('/^worker:([0-9]+)$/', $who, $match) === 1) {
            return [(int) $match[1], null, $who];
        }
        if (preg_match('/^member:([0-9]+)$/', $who, $match) === 1) {
            return [null, (int) $match[1], $who];
        }

        $workerId = $request->filled('worker_id') ? $request->integer('worker_id') : null;
        $memberId = $request->filled('crew_member_id') ? $request->integer('crew_member_id') : null;
        $value = $memberId ? 'member:'.$memberId : ($workerId ? 'worker:'.$workerId : '');

        return [$workerId, $memberId, $value];
    }

    private function constrainCrewMember(Builder $query, int $crewMemberId): Builder
    {
        return $query->where(function (Builder $inner) use ($crewMemberId): void {
            $inner->whereHas('crewMembers', function (Builder $members) use ($crewMemberId): void {
                $members->where('crew_members.id', $crewMemberId);
            })->orWhere(function (Builder $team) use ($crewMemberId): void {
                $team->whereDoesntHave('crewMembers')
                    ->whereIn('worker_id', CrewMember::query()->whereKey($crewMemberId)->select('worker_id'));
            });
        });
    }

    private function dayOfWeekFilter(Request $request): ?int
    {
        $value = (int) $request->input('day');

        return array_key_exists($value, self::DAY_OPTIONS) ? $value : null;
    }

    /**
     * @param  array<int, array<string, array<string, mixed>>>  $doubleBooked
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return array{active: bool, count: int, label: ?string, crew_id: ?int, worker_id: ?int, assignment_ids: list<int>, warnings: list<array<string, mixed>>}
     */
    private function doubleBookingPresentation(Request $request, array $doubleBooked, Collection $assignments): array
    {
        $warnings = $this->doubleBookingWarnings($doubleBooked, $assignments);
        $active = $request->boolean('doubles');
        $crewMemberId = $request->filled('double_crew') ? $request->integer('double_crew') : 0;
        $workerOnlyId = $request->filled('double_worker') ? $request->integer('double_worker') : 0;
        if ($crewMemberId > 0) {
            $workerOnlyId = 0;
        }

        $assignmentIds = $active
            ? $this->conflictingAssignmentIds(
                $doubleBooked,
                $crewMemberId > 0 ? $crewMemberId : null,
                $workerOnlyId > 0 ? $workerOnlyId : null,
            )
            : [];

        return [
            'active' => $active,
            'count' => count($assignmentIds),
            'label' => $this->doubleBookingFilterLabel($warnings, $crewMemberId, $workerOnlyId, $active),
            'crew_id' => $active && $crewMemberId > 0 ? $crewMemberId : null,
            'worker_id' => $active && $workerOnlyId > 0 ? $workerOnlyId : null,
            'assignment_ids' => $assignmentIds,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     */
    private function doubleBookingFilterLabel(array $warnings, int $crewMemberId, int $workerOnlyId, bool $active): ?string
    {
        if (! $active) {
            return null;
        }

        if ($crewMemberId > 0) {
            foreach ($warnings as $warning) {
                foreach ($warning['people'] as $person) {
                    if ((int) $person['id'] === $crewMemberId) {
                        return (string) $person['name'];
                    }
                }
            }

            return null;
        }

        if ($workerOnlyId > 0) {
            foreach ($warnings as $warning) {
                if ((int) $warning['worker_id'] === $workerOnlyId) {
                    return (string) $warning['team'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, array<string, mixed>>>  $doubleBooked
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return list<array{worker_id: int, team: string, people: list<array{id: int, name: string}>, message: string, date: string}>
     */
    private function doubleBookingWarnings(array $doubleBooked, Collection $assignments): array
    {
        $warnings = [];

        foreach ($doubleBooked as $workerId => $dates) {
            $name = $assignments->firstWhere('worker_id', $workerId)?->worker?->displayName();
            if (! $name) {
                continue;
            }

            $people = [];
            foreach ($dates as $row) {
                foreach ($row['people'] ?? [] as $person) {
                    $personId = (int) ($person['id'] ?? 0);
                    if ($personId < 1 || trim((string) ($person['name'] ?? '')) === '') {
                        continue;
                    }
                    $people[$personId] = [
                        'id' => $personId,
                        'name' => (string) $person['name'],
                    ];
                }
            }

            $people = array_values($people);
            $message = $people !== []
                ? collect($people)->pluck('name')->implode(', ').' van '.$name.' staat op meerdere werken.'
                : $name.' heeft meer personen ingepland dan het team.';

            $warnings[] = [
                'worker_id' => (int) $workerId,
                'team' => $name,
                'people' => $people,
                'message' => $message,
                'date' => (string) array_key_first($dates),
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<int, array<string, array<string, mixed>>>  $doubleBooked
     * @return list<int>
     */
    private function conflictingAssignmentIds(array $doubleBooked, ?int $crewMemberId, ?int $workerId): array
    {
        $ids = [];

        foreach ($doubleBooked as $bookedWorkerId => $dates) {
            if ($workerId !== null && (int) $bookedWorkerId !== $workerId) {
                continue;
            }

            foreach ($dates as $row) {
                if ($crewMemberId !== null) {
                    foreach ($row['people'] ?? [] as $person) {
                        if ((int) ($person['id'] ?? 0) !== $crewMemberId) {
                            continue;
                        }
                        foreach ($person['assignment_ids'] ?? [] as $assignmentId) {
                            $ids[(int) $assignmentId] = true;
                        }
                    }

                    continue;
                }

                foreach ($row['assignment_ids'] ?? [] as $assignmentId) {
                    $ids[(int) $assignmentId] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $assignmentIds
     * @return list<array<string, mixed>>
     */
    private function filterRowsToAssignments(array $rows, array $assignmentIds): array
    {
        $allowed = array_fill_keys($assignmentIds, true);
        $kept = [];

        foreach ($rows as $row) {
            if (($row['type'] ?? '') === 'section') {
                continue;
            }

            $limited = $this->rowLimitedToAssignments($row, $allowed);
            if ($limited !== null) {
                $kept[] = $limited;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, true>  $allowed
     * @return array<string, mixed>|null
     */
    private function rowLimitedToAssignments(array $row, array $allowed): ?array
    {
        $row['person_bars'] = $this->barsLimitedToAssignments($row['person_bars'] ?? [], $allowed);
        $row['bar_count'] = $this->stackedBarCount($row['person_bars']);

        if (isset($row['children']) && is_array($row['children'])) {
            $children = [];
            foreach ($row['children'] as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $limited = $this->rowLimitedToAssignments($child, $allowed);
                if ($limited !== null) {
                    $children[] = $limited;
                }
            }
            $row['children'] = $children;
        }

        $hasBars = ($row['person_bars'] ?? []) !== [];
        $hasChildren = ($row['children'] ?? []) !== [];
        if (! $hasBars && ! $hasChildren) {
            return null;
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $personBars
     * @param  array<int, true>  $allowed
     * @return list<array<string, mixed>>
     */
    private function barsLimitedToAssignments(array $personBars, array $allowed): array
    {
        $bars = array_values(array_filter(
            $personBars,
            fn (array $bar): bool => isset($allowed[(int) ($bar['assignment_id'] ?? 0)]),
        ));

        $stacks = [];
        foreach ($bars as $index => $bar) {
            $stack = (int) ($bar['stack'] ?? 0);
            if (! array_key_exists($stack, $stacks)) {
                $stacks[$stack] = count($stacks);
            }
            $bars[$index]['stack'] = $stacks[$stack];
        }

        return $bars;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function groupRowsByKind(array $rows, ProjectKind|string|null $kindFilter): array
    {
        $main = [];
        $winkel = [];
        foreach ($rows as $row) {
            if (($row['kind'] ?? ProjectKind::Project->value) === ProjectKind::Winkel->value) {
                $winkel[] = $row;
            } else {
                $main[] = $row;
            }
        }

        $main = $this->sortRowsByDate($main);
        $winkel = $this->sortRowsByDate($winkel);

        if ($kindFilter !== null || $main === [] || $winkel === []) {
            return array_values([...$main, ...$winkel]);
        }

        return [
            [
                'type' => 'section',
                'title' => (string) config('company.name'),
            ],
            ...$main,
            [
                'type' => 'section',
                'title' => (string) config('company.shop_name'),
            ],
            ...$winkel,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRowsByDate(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            $bucket = ((int) ($left['sort_bucket'] ?? 3)) <=> ((int) ($right['sort_bucket'] ?? 3));
            if ($bucket !== 0) {
                return $bucket;
            }

            $date = strcmp($this->sortDateKey($left), $this->sortDateKey($right));
            if ($date !== 0) {
                return $date;
            }

            $kind = strcmp((string) ($left['kind'] ?? ''), (string) ($right['kind'] ?? ''));
            if ($kind !== 0) {
                return $kind;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $rows;
    }

    /**
     * 0 = loopt of start in beeld, 1 = start later, 2 = al klaar, 3 = geen datum.
     *
     * @param  Collection<int, Carbon>  $days
     */
    private function sortBucket(?CarbonInterface $start, ?CarbonInterface $end, Collection $days): int
    {
        $from = $days->first()?->copy()->startOfDay();
        $to = $days->last()?->copy()->startOfDay();
        if ($start === null || $from === null || $to === null) {
            return 3;
        }

        $startDay = $start->copy()->startOfDay();
        if ($startDay->gt($to)) {
            return 1;
        }

        $endDay = $end?->copy()->startOfDay();
        if ($endDay !== null && $endDay->lt($from)) {
            return 2;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sortDateKey(array $row): string
    {
        $date = (string) ($row['sort_date'] ?? '');

        return $date === '' ? '9999-12-31' : $date;
    }

    private function optionalInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function weekBands(Collection $days, int $weeks = 1): Collection
    {
        $monthFormat = $weeks === 1 ? 'F' : 'M';

        return $days
            ->groupBy(fn (Carbon $day) => $day->isoWeekYear.'-'.$day->isoWeek())
            ->map(function (Collection $group) use ($monthFormat) {
                $first = $group->first();
                $months = $group
                    ->map(fn (Carbon $day) => rtrim($day->translatedFormat($monthFormat), '.'))
                    ->unique()
                    ->values();
                $month = $months->implode('–');

                return [
                    'number' => (int) $first->isoWeek(),
                    'year' => (int) $first->isoWeekYear(),
                    'span' => $group->count(),
                    'month' => $month,
                    'label' => 'Week '.$first->isoWeek().' · '.$month,
                ];
            })
            ->values();
    }

    private function weekRangeLabel(Collection $bands): string
    {
        if ($bands->isEmpty()) {
            return '';
        }

        $first = $bands->first()['number'];
        $last = $bands->last()['number'];

        return $first === $last ? 'Week '.$first : 'Week '.$first.'–'.$last;
    }

    /**
     * @param  list<array<string, mixed>>  $workRows
     * @return array{ordered: ?float, completed: ?float, remaining: ?float, percent: ?int, unit: string}
     */
    private function projectQuantities(array $workRows): array
    {
        $rows = collect($workRows)->filter(
            fn (array $row): bool => ($row['planning_included'] ?? true)
                && $row['ordered'] !== null
                && $row['completed'] !== null
        );

        if ($rows->isEmpty()) {
            return [
                'ordered' => null,
                'completed' => null,
                'remaining' => null,
                'percent' => null,
                'unit' => '',
            ];
        }

        $squareMeters = $rows->filter(
            fn (array $row): bool => ($row['unit'] ?? '') === WorkUnit::SquareMeter->label()
        );
        $priming = $squareMeters->first(
            fn (array $row): bool => ($row['is_ondergrond'] ?? false) === true
        );
        $source = $priming !== null
            ? collect([$priming])
            : ($squareMeters->isNotEmpty() ? $squareMeters : $rows);
        $unit = (string) ($source->first()['unit'] ?? '');
        $source = $source->filter(fn (array $row): bool => ($row['unit'] ?? '') === $unit);

        $ordered = (float) $source->sum('ordered');
        $completed = (float) $source->sum('completed');
        $remaining = (float) $source->sum(fn (array $row): float => (float) ($row['remaining'] ?? 0));

        return [
            'ordered' => $ordered,
            'completed' => $completed,
            'remaining' => $remaining,
            'percent' => $this->progressPercent($completed, $ordered),
            'unit' => $unit,
        ];
    }

    private function progressPercent(?float $completed, ?float $ordered): ?int
    {
        if ($completed === null || $ordered === null || $ordered <= 0.0001) {
            return null;
        }

        return (int) round(min(100, max(0, $completed / $ordered * 100)));
    }

    /**
     * Werkzaamheden die bij “Wat gaan ze doen” horen: dezelfde groepen als het planbord van dit project.
     *
     * @return list<array{id: int, name: string, group: string, notes: string, type_key: string, project_id: int, project: string, member_ids: list<int>}>
     */
    public function plannableWorkChoices(Project $project): array
    {
        $id = (int) $project->id;
        if (array_key_exists($id, $this->plannableChoicesByProject)) {
            return $this->plannableChoicesByProject[$id];
        }

        $project->loadMissing('workItems.planningActivity');

        if ($project->isSmallWork() || $project->isWinkel()) {
            return $this->plannableChoicesByProject[$id] = $this->individualWorkChoices($project);
        }

        $choices = [];
        foreach ($this->quantityWorkGroups($project) as $packageKey => $items) {
            if (! $this->groupIsOnTheBoard($items)) {
                continue;
            }
            $isOndergrond = $packageKey === 'ondergrond';
            ['primary' => $primary, 'title' => $title] = $this->boardGroupHeading($isOndergrond, $items);
            $choices[] = $this->workChoice($project, $primary, $title, $items);
        }

        return $this->plannableChoicesByProject[$id] = array_merge($choices, $this->extraWorkChoices($project));
    }

    /**
     * @return list<array{id: int, name: string, group: string, notes: string, type_key: string, project_id: int, project: string, member_ids: list<int>}>
     */
    private function extraWorkChoices(Project $project): array
    {
        $choices = [];
        foreach ($project->workItems->filter(fn (WorkItem $item): bool => $item->isExtraWork()) as $item) {
            if ((float) $item->ordered_quantity <= 0.0001 && $item->planned_start_date === null) {
                continue;
            }
            $label = trim((string) $item->name);
            $choices[] = $this->workChoice($project, $item, $label !== '' ? $label : 'Extra werk', collect([$item]));
        }

        return $choices;
    }

    /**
     * @return Collection<string, Collection<int, WorkItem>>
     */
    private function quantityWorkGroups(Project $project): Collection
    {
        $id = (int) $project->id;
        if (array_key_exists($id, $this->quantityGroupsByProject)) {
            return $this->quantityGroupsByProject[$id];
        }

        return $this->quantityGroupsByProject[$id] = $project->workItems
            ->reject(fn (WorkItem $item): bool => $item->isExtraWork())
            ->groupBy(fn (WorkItem $item): string => $item->typeKey())
            ->sortBy(
                fn (Collection $items): int => (int) $items->min(fn (WorkItem $item): int => $item->phase()->sort())
            );
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @return array{primary: WorkItem, title: string, default_title: string, planning_work_activity_id: ?int}
     */
    private function boardGroupHeading(bool $isOndergrond, Collection $items): array
    {
        $primary = $isOndergrond
            ? $this->primaryWorkItem($items)
            : $items->sortByDesc(fn (WorkItem $item): float => (float) $item->ordered_quantity)->first();
        $defaultTitle = $isOndergrond ? $primary->packageLabel() : $primary->planningTitle();
        $chosen = $this->chosenActivityLabel($items, $primary);

        return [
            'primary' => $primary,
            'title' => $chosen['title'] ?? $defaultTitle,
            'default_title' => $defaultTitle,
            'planning_work_activity_id' => $chosen['id'],
        ];
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     */
    private function groupIsOnTheBoard(Collection $items): bool
    {
        $ordered = (float) $items->sum(fn (WorkItem $item): float => (float) $item->ordered_quantity);
        $budgetHours = (float) $items->sum(
            fn (WorkItem $item): float => $item->begrote_uren === null ? 0.0 : (float) $item->begrote_uren
        );

        return $ordered > 0.0001 || $budgetHours > 0.0001;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     */
    private function planningIncluded(Collection $items): bool
    {
        return $items->every(fn (WorkItem $item): bool => (bool) $item->planning_included);
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @return array{title: ?string, id: ?int}
     */
    private function chosenActivityLabel(Collection $items, WorkItem $primary): array
    {
        $activity = $primary->planningActivity;
        if (! $activity instanceof WorkActivity) {
            $activity = $items
                ->map(fn (WorkItem $item): ?WorkActivity => $item->planningActivity)
                ->first(fn (?WorkActivity $candidate): bool => $candidate instanceof WorkActivity);
        }
        if (! $activity instanceof WorkActivity || trim($activity->name) === '') {
            return ['title' => null, 'id' => null];
        }

        return [
            'title' => $activity->name,
            'id' => (int) $activity->id,
        ];
    }

    /**
     * @return list<array{id: int, name: string, group: string, notes: string, type_key: string, project_id: int, project: string, member_ids: list<int>}>
     */
    private function individualWorkChoices(Project $project): array
    {
        $items = $project->workItems
            ->filter(fn (WorkItem $item): bool => (float) $item->ordered_quantity > 0.0001 || $item->work_activity_id !== null);
        if ($project->isSmallWork() && $items->contains(fn (WorkItem $item): bool => $item->work_activity_id !== null)) {
            $items = $items->reject(fn (WorkItem $item): bool => $item->work_activity_id === null && ! $item->isExtraWork());
        }

        return $items
            ->map(function (WorkItem $item) use ($project): array {
                $name = $item->productLabel() ?: (WorkType::looksLikeRoom($item->name) ? $item->typeLabel() : $item->name);

                return $this->workChoice(
                    $project,
                    $item,
                    $name !== '' ? $name : $item->planningTitle(),
                    collect([$item]),
                    $this->boardStep((string) $item->notes) ?? '',
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @return array{id: int, name: string, group: string, notes: string, type_key: string, project_id: int, project: string, member_ids: list<int>}
     */
    private function workChoice(Project $project, WorkItem $primary, string $title, Collection $items, string $notes = ''): array
    {
        $ids = $items
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if (! in_array((int) $primary->id, $ids, true)) {
            array_unshift($ids, (int) $primary->id);
        }

        return [
            'id' => (int) $primary->id,
            'name' => $title,
            'group' => $title,
            'notes' => $notes,
            'type_key' => $primary->typeKey(),
            'project_id' => $project->id,
            'project' => $project->displayTitle(),
            'member_ids' => $ids,
        ];
    }

    /**
     * True when one visit is drawn on more than one board line, so a drag can move just that line.
     */
    private function coversSeveralBoardLines(WorkerAssignment $assignment, Project $project): bool
    {
        $linked = array_flip($assignment->linkedWorkItemIds($project->relationLoaded('workOrders') ? $project->workOrders : null));
        $keys = [];
        foreach ($project->workItems as $item) {
            if (! isset($linked[(int) $item->id]) || $item->isExtraWork() || (float) $item->ordered_quantity <= 0.0001) {
                continue;
            }
            $keys[$item->typeKey()] = true;
        }

        return count($keys) >= 2;
    }

    private function primaryWorkItem(Collection $items): WorkItem
    {
        $preferred = [
            'ondergrond' => WorkPhase::Egaliseren,
            'vloer' => WorkPhase::Vloer,
            'plinten' => WorkPhase::Plinten,
            'overige' => WorkPhase::Overige,
        ];
        $group = $items->first()->packageKey();
        $want = $preferred[$group] ?? null;

        return $items->first(fn (WorkItem $item) => $item->phase() === $want)
            ?? $items->sortBy(fn (WorkItem $item) => $item->phase()->sort())->first();
    }

    /**
     * Per projectregel: unieke vakmannen per kalenderdag, op basis van de zichtbare balken.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Carbon>  $days
     * @return list<array<string, mixed>>
     */
    private function withProjectDayCrew(array $rows, Collection $days): array
    {
        foreach ($rows as $index => $row) {
            if (! in_array($row['type'] ?? '', ['project', 'small'], true)) {
                continue;
            }

            $rows[$index]['day_crew'] = $this->dayCrewTotals([$row], $days);
        }

        return $rows;
    }

    /**
     * Unique vakmannen per day. The same person on two jobs that day counts once.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Carbon>  $days
     * @return array<string, int>
     */
    private function dayCrewTotals(array $rows, Collection $days): array
    {
        $keys = [];
        foreach ($days as $day) {
            $keys[$day->toDateString()] = [];
        }
        $this->collectDayCrew($rows, $days->values(), $keys);

        return array_map(fn (array $people): int => count($people), $keys);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Carbon>  $days
     * @param  array<string, array<string, true>>  $keys
     */
    private function collectDayCrew(array $rows, Collection $days, array &$keys): void
    {
        foreach ($rows as $row) {
            foreach ($row['person_bars'] ?? [] as $bar) {
                if (($bar['is_internal'] ?? false) === true) {
                    continue;
                }
                $segment = $bar['bar'] ?? [];
                $start = (int) ($segment['start'] ?? 0);
                $span = max(1, (int) ($segment['span'] ?? 1));
                $people = $this->barPeopleKeys($bar);
                for ($index = $start; $index < $start + $span; $index++) {
                    $day = $days->get($index);
                    if ($day === null) {
                        continue;
                    }
                    foreach ($people as $key) {
                        $keys[$day->toDateString()][$key] = true;
                    }
                }
            }
            if (($row['children'] ?? []) !== []) {
                $this->collectDayCrew($row['children'], $days, $keys);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $bar
     * @return list<string>
     */
    private function barPeopleKeys(array $bar): array
    {
        $crew = array_values(array_filter(
            $bar['crew_ids'] ?? [],
            fn (mixed $id): bool => (int) $id > 0,
        ));
        if ($crew !== []) {
            return array_map(fn (mixed $id): string => 'c'.(int) $id, $crew);
        }

        $count = max(1, (int) ($bar['people_count'] ?? 1));
        $worker = (int) ($bar['worker_id'] ?? 0);
        $keys = [];
        for ($slot = 0; $slot < $count; $slot++) {
            $keys[] = 'w'.$worker.'#'.$slot;
        }

        return $keys;
    }

    /**
     * @return array{start: float, end: float, start_time: string, end_time: string}|null
     */
    private function shareSlice(WorkerAssignment $assignment, int $workItemId): ?array
    {
        $assignment->loadMissing('workItems');
        $budget = PlanningHours::hoursBetween($assignment->startTimeValue(), $assignment->endTimeValue());
        if ($budget <= 0) {
            $budget = (float) PlanningHours::WORKDAY_HOURS;
        }
        $cursor = 0.0;
        foreach ($assignment->workItems as $item) {
            if ($item->pivot?->planned_hours === null) {
                continue;
            }
            $hours = (float) $item->pivot->planned_hours;
            $start = $cursor / $budget;
            $cursor += $hours;
            if ((int) $item->id !== $workItemId) {
                continue;
            }

            return [
                'start' => round($start, 4),
                'end' => round(min(1, $cursor / $budget), 4),
                'start_time' => substr(PlanningHours::timeFromFraction($start), 0, 5),
                'end_time' => substr(PlanningHours::timeFromFraction(min(1, $cursor / $budget)), 0, 5),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $bar
     */
    private function paintShareSlice(array &$bar, WorkerAssignment $assignment, int $workItemId): void
    {
        $hours = $assignment->hoursForWorkItem($workItemId);
        if ($hours === null) {
            return;
        }
        $bar['share_hours'] = $hours;
        $bar['label'] = $assignment->planningShareLabel($hours);
        $bar['label_short'] = $assignment->planningShareLabel($hours, false);
        $slice = $this->shareSlice($assignment, $workItemId);
        if ($slice === null) {
            return;
        }
        $bar['bar']['start_offset'] = $slice['start'];
        $bar['bar']['end_offset'] = $slice['end'];
        $bar['start_time'] = $slice['start_time'];
        $bar['end_time'] = $slice['end_time'];
    }

    private function personBar(WorkerAssignment $assignment, array $bar, array $doubleBooked, Collection $days, string $workName, string $projectName = ''): array
    {
        $approvedHours = $assignment->getAttribute('approved_hours');
        $uniqueHours = $assignment->getAttribute('unique_hours');
        if ($approvedHours !== null) {
            $hoursOverride = (float) $approvedHours;
        } elseif ($uniqueHours !== null && abs((float) $uniqueHours - $assignment->plannedHoursValue()) >= 0.01) {
            $hoursOverride = (float) $uniqueHours;
        } else {
            $hoursOverride = null;
        }
        $label = $assignment->planningBarLabel();
        $shortLabel = $assignment->planningBarLabel(false);
        $ticketLabel = $assignment->planningTicketLabel();
        $ticket = $assignment->workTickets->first();
        $double = $days->contains(
            fn ($day) => isset($doubleBooked[$assignment->worker_id][$day->toDateString()])
        );
        $crewIds = $assignment->crewMembers
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return [
            'assignment_id' => $assignment->id,
            'worker_id' => $assignment->worker_id,
            'project_id' => $assignment->project_id,
            'work_item_id' => $assignment->work_item_id,
            'work_item_ids' => $assignment->linkedWorkItemIds(),
            'people_count' => $assignment->peopleCount(),
            'crew_ids' => $crewIds,
            'foreman_id' => $assignment->foreman_crew_member_id,
            'work_ticket_holder_id' => $assignment->work_ticket_crew_member_id,
            'label' => $label,
            'label_short' => $shortLabel,
            'hours_label' => $assignment->isProvisional()
                ? null
                : PlanningHours::hoursLabel($assignment->plannedHoursValue()),
            'title' => filled($ticketLabel)
                ? $assignment->planningHoverTitle($hoursOverride)."\n".$ticketLabel
                : $assignment->planningHoverTitle($hoursOverride),
            'ticket' => $ticketLabel,
            'ticket_mark' => $assignment->planningTicketMark(),
            'ticket_url' => $ticket === null ? null : route('work-tickets.show', $ticket),
            'start_date' => $assignment->start_date->toDateString(),
            'end_date' => $assignment->end_date->toDateString(),
            'start_time' => PlanningHours::formatTime($assignment->startTimeValue()),
            'end_time' => PlanningHours::formatTime($assignment->endTimeValue()),
            'include_saturday' => $assignment->includesSaturday(),
            'include_sunday' => $assignment->includesSunday(),
            'is_provisional' => $assignment->isProvisional(),
            'locked' => $assignment->isHoursOrigin(),
            'hours_per_day' => (float) $assignment->hours_per_day,
            'planned_hours' => $assignment->plannedHoursValue(),
            'color' => $assignment->isInternal()
                ? '#1f4b63'
                : ($assignment->worker?->planColor() ?? Format::planColor((int) $assignment->worker_id)),
            'is_internal' => $assignment->isInternal(),
            'business_unit' => $assignment->business_unit?->value ?? '',
            'contact_name' => (string) ($assignment->contact_name ?? ''),
            'description' => (string) ($assignment->description ?? ''),
            'notes' => (string) ($assignment->notes ?? ''),
            'ticket_label' => $assignment->worker
                ? WorkTicketKind::forWorker($assignment->worker)->label().' maken'
                : 'Werkbon maken',
            'bar' => $bar,
            'double' => $double,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $personBars
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  list<int>  $workItemIds
     * @param  Collection<int, mixed>  $workOrders
     * @return list<array<string, mixed>>
     */
    private function decorateBarsWithBudget(
        array $personBars,
        Collection $assignments,
        array $workItemIds,
        float $budgetHours,
        Collection $workOrders,
    ): array {
        if ($personBars === [] || $budgetHours <= 0.0001) {
            return $personBars;
        }

        $queue = [];
        foreach ($assignments as $assignment) {
            $workItemId = $assignment->resolvedWorkItemId($workOrders) ?? $assignment->work_item_id;
            if (! in_array((int) $workItemId, $workItemIds, true)) {
                continue;
            }

            $queue[] = [
                'id' => (int) $assignment->id,
                'hours' => $assignment->plannedPersonHours(),
                'start_date' => $assignment->start_date->toDateString(),
                'start_time' => PlanningHours::formatTime($assignment->startTimeValue()),
            ];
        }

        $splits = PlanningLaborForecast::splitByBudget($budgetHours, $queue);

        foreach ($personBars as $index => $bar) {
            $split = $splits[(int) $bar['assignment_id']] ?? null;
            if ($split === null || $split['over_hours'] <= 0.0001) {
                continue;
            }

            $personBars[$index]['title'] = '⚠ +'.PlanningHours::hoursLabel($split['over_hours']).' boven begrote uren';
            $personBars[$index]['has_budget_overrun'] = true;
            $personBars[$index]['budget_ok_percent'] = $split['ok_percent'];
            $personBars[$index]['overrun_from'] = rtrim(rtrim(number_format($split['ok_percent'], 2, '.', ''), '0'), '.').'%';
        }

        return array_values($personBars);
    }

    /**
     * @param  list<array<string, mixed>>  $personBars
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return list<array<string, mixed>>
     */
    private function decorateLeftoverBars(array $personBars, Collection $assignments, Project $project): array
    {
        $groups = [];
        foreach ($personBars as $index => $bar) {
            $groups[(int) ($bar['work_item_id'] ?? 0)][] = $index;
        }

        foreach ($groups as $itemId => $indexes) {
            if ($itemId <= 0) {
                continue;
            }

            $item = $project->workItems->firstWhere('id', $itemId);
            $budgetHours = $item?->begrote_uren === null ? 0.0 : round((float) $item->begrote_uren, 2);
            $subset = [];
            foreach ($indexes as $index) {
                $subset[] = $personBars[$index];
            }

            $decorated = $this->decorateBarsWithBudget(
                $subset,
                $assignments,
                [$itemId],
                $budgetHours,
                $project->workOrders,
            );

            foreach ($indexes as $offset => $index) {
                $personBars[$index] = $decorated[$offset];
            }
        }

        return $personBars;
    }

    /**
     * Projectperiode op de projectregel: exacte start-/einddatum, geen personeelsbalk.
     *
     * @param  Collection<int, Carbon>  $days
     * @return array{bar: ?array{start: int, span: int}, start_marker: ?array{index: int, date: string}, end_marker: ?array{index: int, date: string, done: bool}, werk_start: ?string, missing_craftsman: bool}
     */
    private function projectPeriod(Project $project, Collection $days): array
    {
        $endMarker = $this->dateMarker($project->planned_end_date, $days);
        if ($endMarker !== null) {
            $endMarker['done'] = in_array($project->status, [ProjectStatus::Gereed, ProjectStatus::Opgeleverd], true);
        }

        return [
            'bar' => $this->bar($project->planned_start_date, $project->planned_end_date, $days),
            'start_marker' => $this->dateMarker($project->planned_start_date, $days),
            'end_marker' => $endMarker,
            'werk_start' => $this->offgridStartLabel($project->planned_start_date, $days),
            'missing_craftsman' => $project->assignments->isEmpty(),
        ];
    }

    /**
     * Start date for the WERK column when it falls after the visible days.
     *
     * @param  Collection<int, Carbon>  $days
     */
    private function offgridStartLabel(?CarbonInterface $start, Collection $days): ?string
    {
        if ($start === null || $days->isEmpty() || $this->dateMarker($start, $days) !== null) {
            return null;
        }

        $last = $days->last()->copy()->startOfDay();
        if ($start->copy()->startOfDay()->lte($last)) {
            return null;
        }

        return $start->format('d-m-Y').' · week '.(int) $start->isoWeek();
    }

    /**
     * @param  Collection<int, Carbon>  $days
     * @return array{index: int, date: string}|null
     */
    private function dateMarker(?CarbonInterface $date, Collection $days): ?array
    {
        if (! $date) {
            return null;
        }

        $index = $days->search(fn (Carbon $day) => $day->toDateString() === $date->toDateString());
        if ($index === false) {
            return null;
        }

        return [
            'index' => (int) $index,
            'date' => $date->format('d-m-Y'),
        ];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, Carbon>  $days
     * @param  array<int, array<string, mixed>>  $doubleBooked
     * @return list<array<string, mixed>>
     */
    private function internalRows(Collection $assignments, Collection $days, array $doubleBooked): array
    {
        $bars = $this->internalDayBars($assignments, $days, $doubleBooked);
        if ($bars === []) {
            return [];
        }

        return [[
            'type' => 'internal',
            'id' => 0,
            'kind' => AssignmentKind::Internal->value,
            'badge' => 'INTERN',
            'compact' => true,
            'sort_bucket' => 0,
            'sort_date' => '',
            'title' => 'Intern – inzet',
            'person_bars' => $bars,
            'bar_count' => $this->stackedBarCount($bars),
            'children' => [],
            'bar' => null,
            'start_marker' => null,
            'end_marker' => null,
            'missing_craftsman' => false,
        ]];
    }

    /**
     * One block per day. A stored range stays in the database and is only split on screen.
     *
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, Carbon>  $days
     * @param  array<int, array<string, mixed>>  $doubleBooked
     * @return list<array<string, mixed>>
     */
    private function internalDayBars(Collection $assignments, Collection $days, array $doubleBooked): array
    {
        $byDay = [];
        foreach ($assignments as $assignment) {
            foreach ($days->values() as $index => $day) {
                if (! $assignment->coversDate($day)) {
                    continue;
                }
                $byDay[$index][] = $assignment;
            }
        }
        ksort($byDay);

        $bars = [];
        foreach ($byDay as $index => $dayAssignments) {
            $day = $days->values()[$index];
            $short = [];
            $full = [];
            $hourLines = [];
            foreach ($dayAssignments as $assignment) {
                foreach ($this->internalPeople($assignment) as $person) {
                    $compact = WorkerAssignment::compactPersonName($person);
                    $key = mb_strtolower($compact !== '' ? $compact : $person);
                    if ($key === '' || isset($short[$key])) {
                        continue;
                    }
                    $short[$key] = $compact !== '' ? $compact : $person;
                    $full[$key] = $person;
                }
                $hours = $assignment->hoursOnDate($day);
                if ($hours > 0) {
                    $hourLines[] = PlanningHours::hourText($hours).' uur';
                }
            }
            $shortNames = array_values($short);
            $label = $shortNames === [] ? 'Intern' : implode(' · ', $shortNames);
            $shortLabel = count($shortNames) > 2
                ? $shortNames[0].' · '.$shortNames[1].' +'.(count($shortNames) - 2)
                : $label;
            $first = $dayAssignments[0];
            $bar = $this->personBar($first, [
                'start' => $index,
                'span' => 1,
                'start_offset' => $first->start_date->isSameDay($day)
                    ? PlanningHours::fractionFromTime($first->startTimeValue())
                    : 0.0,
                'end_offset' => $first->end_date->isSameDay($day)
                    ? PlanningHours::fractionFromTime($first->endTimeValue())
                    : 1.0,
            ], $doubleBooked, $days, 'Intern – inzet', '');
            $bar['label'] = $label;
            $bar['label_short'] = $shortLabel;
            $bar['title'] = $this->internalDayTitle(array_values($full), $day, $hourLines, $first->internalTitle());
            $bar['show_start_handle'] = false;
            $bar['show_end_handle'] = false;
            $bar['stack'] = 0;
            $bar['start_date'] = $day->toDateString();
            $bar['end_date'] = $day->toDateString();
            $bars[] = $bar;
        }

        return $bars;
    }

    /**
     * @return list<string>
     */
    private function internalPeople(WorkerAssignment $assignment): array
    {
        $names = $assignment->presentNames();
        if ($names !== []) {
            return $names;
        }
        $plan = trim((string) ($assignment->worker?->planName() ?? ''));
        if ($plan === '' || preg_match('/^team\b/iu', $plan) === 1) {
            return [];
        }

        return [$plan];
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $hourLines
     */
    private function internalDayTitle(array $names, CarbonInterface $day, array $hourLines, string $detail = ''): string
    {
        $lines = $names === [] ? [] : $names;
        $lines[] = 'Intern - inzet';
        $lines[] = ucfirst($day->translatedFormat('l d-m-Y'));
        foreach (array_values(array_unique($hourLines)) as $hours) {
            $lines[] = $hours;
        }
        if ($detail !== '') {
            $lines[] = $detail;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $personBars
     * @param  list<int>  $usedIds
     * @param  Collection<int, Carbon>  $days
     * @param  array<int, array<string, mixed>>  $doubleBooked
     * @return array{0: list<array<string, mixed>>, 1: list<int>}
     */
    private function appendAssignmentPersonBars(
        array $personBars,
        array $usedIds,
        WorkerAssignment $assignment,
        Collection $days,
        array $doubleBooked,
        string $workName,
        string $projectName = '',
    ): array {
        $segments = $this->assignmentBars($assignment, $days);
        if ($segments === []) {
            return [$personBars, $usedIds];
        }

        $stack = $personBars === []
            ? 0
            : (int) ($personBars[array_key_last($personBars)]['stack'] ?? 0) + 1;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $box) {
            $bar = $this->personBar($assignment, $box, $doubleBooked, $days, $workName, $projectName);
            $bar['stack'] = $stack;
            $bar['show_start_handle'] = $index === 0;
            $bar['show_end_handle'] = $index === $lastIndex;
            $personBars[] = $bar;
        }

        $usedIds[] = $assignment->id;

        return [$personBars, $usedIds];
    }

    /**
     * @param  list<array<string, mixed>>  $personBars
     */
    private function stackedBarCount(array $personBars): int
    {
        return count(array_unique(array_map(
            static fn (array $bar): int => (int) ($bar['stack'] ?? 0),
            $personBars,
        )));
    }

    /**
     * @param  Collection<int, Carbon>  $days
     * @return list<array{start: int, span: int, start_offset: float, end_offset: float}>
     */
    private function assignmentBars(WorkerAssignment $assignment, Collection $days): array
    {
        $visibleDays = $days->values();
        $segments = [];
        $runStart = null;
        $runEnd = null;

        foreach ($visibleDays as $index => $day) {
            if (! $assignment->coversDate($day)) {
                if ($runStart !== null) {
                    $segments[] = $this->assignmentBarSegment($assignment, $visibleDays, $runStart, $runEnd);
                    $runStart = null;
                    $runEnd = null;
                }

                continue;
            }

            if ($runStart === null) {
                $runStart = $index;
            }
            $runEnd = $index;
        }

        if ($runStart !== null) {
            $segments[] = $this->assignmentBarSegment($assignment, $visibleDays, $runStart, $runEnd);
        }

        return $segments;
    }

    /**
     * @param  Collection<int, Carbon>  $days
     * @return array{start: int, span: int, start_offset: float, end_offset: float}
     */
    private function assignmentBarSegment(
        WorkerAssignment $assignment,
        Collection $days,
        int $start,
        int $end,
    ): array {
        $firstDay = $days[$start];
        $lastDay = $days[$end];

        return [
            'start' => $start,
            'span' => $end - $start + 1,
            'start_offset' => $assignment->isProvisional() || $assignment->start_date->toDateString() !== $firstDay->toDateString()
                ? 0.0
                : PlanningHours::fractionFromTime($assignment->startTimeValue()),
            'end_offset' => $assignment->isProvisional() || $assignment->end_date->toDateString() !== $lastDay->toDateString()
                ? 1.0
                : PlanningHours::fractionFromTime($assignment->endTimeValue()),
        ];
    }

    private function bar(?CarbonInterface $start, ?CarbonInterface $end, Collection $days): ?array
    {
        if (! $start || ! $end) {
            return null;
        }

        $rangeStart = $days->first()->copy()->startOfDay();
        $rangeEnd = $days->last()->copy()->endOfDay();
        if ($end->lt($rangeStart) || $start->gt($rangeEnd)) {
            return null;
        }

        $barStart = $start->copy()->startOfDay()->max($rangeStart);
        $barEnd = $end->copy()->startOfDay()->min($days->last()->copy()->startOfDay());

        $startIndex = $days->search(fn ($day) => $day->toDateString() >= $barStart->toDateString());
        if ($startIndex === false) {
            return null;
        }

        $endIndex = $days->search(fn ($day) => $day->toDateString() > $barEnd->toDateString());
        if ($endIndex === false) {
            $endIndex = $days->count();
        }

        return [
            'start' => (int) $startIndex,
            'span' => max(1, (int) $endIndex - (int) $startIndex),
        ];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $projectAssignments
     * @param  Collection<int, Carbon>  $days
     * @param  array<int, array<string, mixed>>  $doubleBooked
     * @param  array<string, mixed>  $labor
     * @return array<string, mixed>
     */
    private function smallProjectRow(
        Project $project,
        Collection $projectAssignments,
        Collection $days,
        array $doubleBooked,
        array $labor,
        bool $canViewLabor,
    ): array {
        $item = $project->workItems->first(
            fn (WorkItem $workItem): bool => $workItem->work_activity_id === null
        ) ?? $project->workItems->first();
        $personBars = [];
        $usedIds = [];
        foreach ($projectAssignments as $assignment) {
            [$personBars, $usedIds] = $this->appendAssignmentPersonBars(
                $personBars,
                $usedIds,
                $assignment,
                $days,
                $doubleBooked,
                $item?->name ?? $project->name,
                $project->displayTitle(),
            );
        }
        $budgetHours = $item?->begrote_uren === null ? 0.0 : round((float) $item->begrote_uren, 2);
        $personBars = $this->decorateBarsWithBudget(
            $personBars,
            $projectAssignments,
            $item ? [(int) $item->id] : [],
            $budgetHours,
            $project->workOrders,
        );
        $hours = $budgetHours > 0.0001
            ? $budgetHours
            : round((float) $projectAssignments->sum(fn (WorkerAssignment $assignment): float => $assignment->plannedPersonHours()), 2);

        $row = $this->compactBoardRow(
            $project,
            $item,
            $project->kind?->badge() ?? 'KLEIN',
            $project->kind?->value ?? SmallWorkType::Klein->value,
            $project->displayTitle(),
            $hours,
            $personBars,
            $canViewLabor ? ($item ? ($labor['items_by_id'][$item->id] ?? $labor) : $labor) : null,
            $project->planned_start_date?->toDateString() ?? '',
            $days,
        );
        $row['children'] = $this->activityLinesWithOwnBars(
            $project,
            $this->smallWorkChildren($project),
            $projectAssignments,
            $days,
            $doubleBooked,
            $personBars,
            (int) ($item?->id ?? 0),
        );
        if ($row['children'] !== []) {
            $row['person_bars'] = [];
            $row['bar_count'] = 0;
        }

        return $row;
    }

    /**
     * @param  Collection<int, WorkItem>  $extraItems
     * @param  Collection<int, WorkerAssignment>  $projectAssignments
     * @param  Collection<int, Carbon>  $days
     * @param  array<int, array<string, mixed>>  $doubleBooked
     * @param  list<int>  $usedIds
     * @param  array<string, mixed>  $labor
     * @return array{0: list<array<string, mixed>>, 1: list<int>}
     */
    private function extraCompactRows(
        Project $project,
        Collection $extraItems,
        Collection $projectAssignments,
        Collection $days,
        array $doubleBooked,
        array $usedIds,
        array $labor,
        bool $canViewLabor,
    ): array {
        $rows = [];

        foreach ($extraItems as $item) {
            $personBars = [];
            foreach ($projectAssignments as $assignment) {
                if (! $assignment->coversWorkIds([(int) $item->id], $project->workOrders)) {
                    continue;
                }
                [$personBars, $usedIds] = $this->appendAssignmentPersonBars(
                    $personBars,
                    $usedIds,
                    $assignment,
                    $days,
                    $doubleBooked,
                    $item->name,
                    $project->displayTitle(),
                );
            }

            $budgetHours = $item->begrote_uren === null ? 0.0 : round((float) $item->begrote_uren, 2);
            $personBars = $this->decorateBarsWithBudget(
                $personBars,
                $projectAssignments,
                [(int) $item->id],
                $budgetHours,
                $project->workOrders,
            );

            $periodBar = $this->bar($item->planned_start_date, $item->planned_end_date, $days);
            if ($periodBar === null && $personBars === []) {
                continue;
            }

            $hours = $budgetHours > 0.0001
                ? $budgetHours
                : round((float) collect($personBars)->unique('assignment_id')->sum('planned_hours'), 2);
            $sortDate = $item->planned_start_date?->toDateString()
                ?? collect($personBars)->min('start_date')
                ?? '';

            $type = $item->small_work_type ?? SmallWorkType::Extra;
            $rows[] = $this->compactBoardRow(
                $project,
                $item,
                $type->badge(),
                $type->value,
                $project->displayTitle().' – '.$item->name,
                $hours,
                $personBars,
                $canViewLabor ? ($labor['items_by_id'][$item->id] ?? null) : null,
                (string) $sortDate,
                $days,
            );
        }

        return [$rows, $usedIds];
    }

    /**
     * @param  list<array<string, mixed>>  $personBars
     * @param  array<string, mixed>|null  $labor
     * @param  Collection<int, Carbon>  $days
     * @return array<string, mixed>
     */
    private function compactBoardRow(
        Project $project,
        ?WorkItem $item,
        string $badge,
        string $kind,
        string $title,
        float $hours,
        array $personBars,
        ?array $labor,
        string $sortDate,
        Collection $days,
    ): array {
        $start = $item?->planned_start_date ?? $project->planned_start_date;
        $end = $item?->planned_end_date ?? $project->planned_end_date ?? $start;
        $startWeek = $start?->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $itemDone = $item !== null && $item->status === 'gereed';
        $endMarker = null;
        $showEndMarker = $end !== null && (
            $start === null
            || $end->toDateString() !== $start->toDateString()
            || ($kind === SmallWorkType::Extra->value && $itemDone)
        );
        if ($showEndMarker) {
            $endMarker = $this->dateMarker($end, $days);
            if ($endMarker !== null) {
                $endMarker['done'] = $item !== null
                    ? $itemDone
                    : in_array($project->status, [ProjectStatus::Gereed, ProjectStatus::Opgeleverd], true);
            }
        }

        $ordered = $item !== null ? (float) $item->ordered_quantity : 0.0;
        $showMaterial = $item !== null
            && $item->unit !== WorkUnit::Hours
            && $ordered > 0.0001;
        $completed = $showMaterial ? $item->completedQuantity() : null;
        $remaining = $showMaterial ? $item->remainingQuantity() : null;

        return [
            'type' => 'small',
            'id' => $project->id,
            'work_item_id' => $item?->id,
            'kind' => $kind,
            'badge' => $badge,
            'compact' => true,
            'sort_bucket' => $this->sortBucket($start, $end, $days),
            'sort_date' => $sortDate,
            'number' => $project->project_number,
            'work_code' => null,
            'numbers_label' => '',
            'numbers_short' => '',
            'title' => $title,
            'hours_label' => $hours > 0.0001 ? PlanningHours::hoursLabel($hours) : null,
            'planned_hours' => $hours > 0.0001 ? $hours : null,
            'subtitle' => $item?->isExtraWork() ? $item->extraLinesSummary() : null,
            'customer' => $project->customer?->name,
            'address' => $project->address,
            'postal_code' => $project->postal_code,
            'city' => $project->city,
            'naw_line' => $project->nawLine(),
            'maps_url' => $project->googleMapsUrl(),
            'who' => collect($personBars)->pluck('label')->filter()->unique()->values(),
            'status' => $project->status->label(),
            'done' => $itemDone || in_array($project->status, [ProjectStatus::Gereed, ProjectStatus::Opgeleverd], true),
            'planning_afgerond' => $project->isPlanningFinished(),
            'bar' => $this->bar($start, $end, $days),
            'start_marker' => $this->dateMarker($start, $days),
            'end_marker' => $endMarker,
            'werk_start' => $this->offgridStartLabel($start, $days),
            'missing_craftsman' => $personBars === [],
            'start_week' => $startWeek,
            'person_bars' => $personBars,
            'bar_count' => $this->stackedBarCount($personBars),
            'warnings' => [],
            'ordered' => $showMaterial ? $ordered : null,
            'ordered_decimals' => $showMaterial && fmod($ordered, 1.0) !== 0.0 ? 2 : 0,
            'completed' => $completed,
            'remaining' => $remaining,
            'percent' => $showMaterial ? $this->progressPercent($completed, $ordered) : null,
            'unit' => $showMaterial ? ($item->unit?->label() ?? '') : '',
            'labor' => $labor,
            'children' => [],
        ];
    }

    /**
     * Each activity line shows an assignment that names that line, including a visit
     * checked on several activities. A visit that still belongs only to the whole job
     * is drawn on every line, but the bar points at that line so a move or another
     * craftsman splits only that line.
     *
     * @param  list<array<string, mixed>>  $children
     * @param  Collection<int, WorkerAssignment>  $projectAssignments
     * @param  Collection<int, Carbon>  $days
     * @param  array<int, array<string, mixed>>  $doubleBooked
     * @param  list<array<string, mixed>>  $sharedBars
     * @return list<array<string, mixed>>
     */
    private function activityLinesWithOwnBars(
        Project $project,
        array $children,
        Collection $projectAssignments,
        Collection $days,
        array $doubleBooked,
        array $sharedBars,
        int $hoursItemId,
    ): array {
        if ($children === []) {
            return [];
        }

        return array_map(function (array $child) use ($project, $projectAssignments, $days, $doubleBooked, $sharedBars, $hoursItemId): array {
            $ids = array_map(static fn (mixed $id): int => (int) $id, $child['work_item_ids'] ?? [(int) $child['id']]);
            $dedicated = $projectAssignments
                ->filter(function (WorkerAssignment $assignment) use ($ids, $hoursItemId): bool {
                    $specific = array_values(array_filter(
                        $assignment->linkedWorkItemIds(),
                        static fn (int $id): bool => $id > 0 && $id !== $hoursItemId,
                    ));

                    return array_intersect($specific, $ids) !== [];
                })
                ->values();
            if ($dedicated->isNotEmpty()) {
                $bars = [];
                $usedIds = [];
                foreach ($dedicated as $assignment) {
                    [$bars, $usedIds] = $this->appendAssignmentPersonBars(
                        $bars,
                        $usedIds,
                        $assignment,
                        $days,
                        $doubleBooked,
                        (string) $child['title'],
                        $project->displayTitle(),
                    );
                }
                foreach ($bars as $index => $bar) {
                    $bars[$index]['work_item_id'] = (int) $child['id'];
                    $assignment = $dedicated->first(fn (WorkerAssignment $row): bool => (int) $row->id === (int) ($bar['assignment_id'] ?? 0));
                    if (! $assignment instanceof WorkerAssignment) {
                        continue;
                    }
                    $matched = $assignment->workItems->first(function (WorkItem $item) use ($ids, $child): bool {
                        return in_array((int) $item->id, $ids, true)
                            || mb_strtolower((string) $item->name) === mb_strtolower((string) ($child['title'] ?? ''));
                    });
                    if ($assignment->workItems->filter(fn (WorkItem $item): bool => $item->pivot?->planned_hours !== null)->count() < 2) {
                        continue;
                    }
                    $this->paintShareSlice($bars[$index], $assignment, (int) ($matched?->id ?? $child['id']));
                }
                $child['person_bars'] = $bars;
                $child['bar_count'] = $this->stackedBarCount($bars);

                return $child;
            }

            $bars = array_values(array_filter(
                $sharedBars,
                fn (array $bar): bool => (int) ($bar['work_item_id'] ?? 0) === $hoursItemId,
            ));
            foreach ($bars as $index => $bar) {
                $bars[$index]['work_item_id'] = (int) $child['id'];
                $bars[$index]['work_item_ids'] = [(int) $child['id']];
                $assignment = $projectAssignments->first(
                    fn (WorkerAssignment $row): bool => (int) $row->id === (int) ($bar['assignment_id'] ?? 0),
                );
                if (! $assignment instanceof WorkerAssignment) {
                    continue;
                }
                $matched = $assignment->workItems->first(function (WorkItem $item) use ($ids, $child): bool {
                    return in_array((int) $item->id, $ids, true)
                        || mb_strtolower((string) $item->name) === mb_strtolower((string) ($child['title'] ?? ''));
                });
                if ($assignment->workItems->filter(fn (WorkItem $item): bool => $item->pivot?->planned_hours !== null)->count() < 2) {
                    continue;
                }
                $this->paintShareSlice($bars[$index], $assignment, (int) ($matched?->id ?? $child['id']));
            }
            $child['person_bars'] = $bars;
            $child['bar_count'] = $this->stackedBarCount($bars);

            return $child;
        }, $children);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function smallWorkChildren(Project $project): array
    {
        $items = $project->workItems
            ->filter(fn (WorkItem $item): bool => $item->work_activity_id !== null)
            ->reject(fn (WorkItem $item): bool => $item->isExtraWork())
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ]);

        if ($items->isEmpty()) {
            return [];
        }

        $ondergrond = $items
            ->filter(fn (WorkItem $item): bool => $item->packageKey() === 'ondergrond')
            ->values();
        $rest = $items
            ->reject(fn (WorkItem $item): bool => $item->packageKey() === 'ondergrond')
            ->values();

        $rows = [];
        if ($ondergrond->isNotEmpty()) {
            $rows[] = $this->smallWorkChildRow($project, $ondergrond, true);
        }
        foreach ($rest as $item) {
            $rows[] = $this->smallWorkChildRow($project, collect([$item]), false);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @return list<string>
     */
    private function activitySteps(Collection $items): array
    {
        return $items
            ->map(fn (WorkItem $item): ?string => $this->boardStep((string) $item->notes))
            ->filter(fn (?string $note): bool => $note !== null)
            ->unique()
            ->values()
            ->all();
    }

    private function boardStep(string $note): ?string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $note));
        if ($line === '' || mb_strlen($line) > 80) {
            return null;
        }

        return $line;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @return array<string, mixed>
     */
    private function smallWorkChildRow(Project $project, Collection $items, bool $grouped): array
    {
        $primary = $grouped ? $this->primaryWorkItem($items) : $items->first();
        $defaultTitle = $grouped ? $primary->packageLabel() : $primary->name;
        $chosen = $this->chosenActivityLabel($items, $primary);
        $ordered = $grouped
            ? (float) $items->max(fn (WorkItem $item): float => (float) $item->ordered_quantity)
            : (float) $primary->ordered_quantity;
        $hasQuantity = $ordered > 0.0001;
        $completed = $hasQuantity
            ? (float) $items->sum(fn (WorkItem $item): float => $item->completedQuantity())
            : null;
        $remaining = $hasQuantity
            ? (float) $items->sum(fn (WorkItem $item): float => $item->remainingQuantity())
            : null;

        return [
            'type' => 'work',
            'id' => $primary->id,
            'project_id' => $project->id,
            'title' => $chosen['title'] ?? $defaultTitle,
            'default_title' => $defaultTitle,
            'planning_work_activity_id' => $chosen['id'],
            'steps' => $this->activitySteps($items),
            'unit' => $primary->unit?->label() ?? '',
            'ordered' => $hasQuantity ? $ordered : null,
            'ordered_decimals' => $hasQuantity && fmod($ordered, 1.0) !== 0.0 ? 2 : 0,
            'empty_quantity' => ! $hasQuantity,
            'planning_included' => $this->planningIncluded($items),
            'completed' => $completed,
            'remaining' => $remaining,
            'percent' => $hasQuantity ? $this->progressPercent($completed, $ordered) : null,
            'work_item_ids' => $items->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'who' => collect(),
            'bar' => null,
            'person_bars' => [],
            'bar_count' => 0,
            'warnings' => [],
            'status' => $primary->status,
        ];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $projectAssignments
     * @param  Collection<int, Carbon>  $days
     * @param  array<int, array<string, mixed>>  $doubleBooked
     * @param  list<int>  $usedIds
     * @param  array<string, mixed>  $labor
     * @return array{0: list<array<string, mixed>>, 1: list<int>}
     */
    private function winkelWorkRows(Project $project, Collection $projectAssignments, Collection $days, array $doubleBooked, array $usedIds, array $labor): array
    {
        $workRows = [];
        $items = $project->workItems
            ->reject(fn (WorkItem $item): bool => $item->isExtraWork())
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ]);
        $ondergrond = $items
            ->filter(fn (WorkItem $item): bool => $item->packageKey() === 'ondergrond')
            ->values();
        $rest = $items
            ->reject(fn (WorkItem $item): bool => $item->packageKey() === 'ondergrond')
            ->values();

        if ($ondergrond->isNotEmpty()) {
            [$row, $usedIds] = $this->winkelWorkRow(
                $project,
                $ondergrond,
                true,
                $projectAssignments,
                $days,
                $doubleBooked,
                $usedIds,
                $labor,
            );
            $workRows[] = $row;
        }

        foreach ($rest as $item) {
            [$row, $usedIds] = $this->winkelWorkRow(
                $project,
                collect([$item]),
                false,
                $projectAssignments,
                $days,
                $doubleBooked,
                $usedIds,
                $labor,
            );
            $workRows[] = $row;
        }

        return [$workRows, $usedIds];
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @param  Collection<int, WorkerAssignment>  $projectAssignments
     * @param  Collection<int, Carbon>  $days
     * @param  array<int, array<string, mixed>>  $doubleBooked
     * @param  list<int>  $usedIds
     * @param  array<string, mixed>  $labor
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    private function winkelWorkRow(
        Project $project,
        Collection $items,
        bool $grouped,
        Collection $projectAssignments,
        Collection $days,
        array $doubleBooked,
        array $usedIds,
        array $labor,
    ): array {
        $primary = $grouped ? $this->primaryWorkItem($items) : $items->first();
        $ids = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $defaultTitle = $grouped ? $primary->packageLabel() : $primary->name;
        $chosen = $this->chosenActivityLabel($items, $primary);
        $title = $chosen['title'] ?? $defaultTitle;
        $ordered = $grouped
            ? (float) $items->max(fn (WorkItem $item): float => (float) $item->ordered_quantity)
            : (float) $primary->ordered_quantity;
        $hasQuantity = $ordered > 0 && ! $primary->isIntakeTask();
        $steps = $this->activitySteps($items);
        $personBars = [];

        foreach ($projectAssignments as $assignment) {
            if (! $assignment->coversWorkIds($ids, $project->workOrders)) {
                continue;
            }

            [$personBars, $usedIds] = $this->appendAssignmentPersonBars(
                $personBars,
                $usedIds,
                $assignment,
                $days,
                $doubleBooked,
                $title,
                $project->name,
            );
        }

        $budgetHours = round((float) $items->sum(
            fn (WorkItem $item): float => $item->begrote_uren === null ? 0.0 : (float) $item->begrote_uren
        ), 2);
        $personBars = $this->decorateBarsWithBudget(
            $personBars,
            $projectAssignments,
            $ids,
            $budgetHours,
            $project->workOrders,
        );

        $row = [
            'type' => 'work',
            'id' => $primary->id,
            'project_id' => $project->id,
            'title' => $title,
            'default_title' => $defaultTitle,
            'planning_work_activity_id' => $chosen['id'],
            'steps' => $steps,
            'unit' => $primary->isIntakeTask() ? '' : ($primary->unit?->label() ?? ''),
            'ordered' => $hasQuantity ? $ordered : null,
            'ordered_decimals' => $hasQuantity && fmod($ordered, 1.0) !== 0.0 ? 2 : 0,
            'empty_quantity' => ! $hasQuantity,
            'planning_included' => $this->planningIncluded($items),
            'completed' => null,
            'remaining' => null,
            'percent' => null,
            'who' => collect(),
            'bar' => $this->bar(
                $items->pluck('planned_start_date')->filter()->min(),
                $items->pluck('planned_end_date')->filter()->max(),
                $days,
            ),
            'person_bars' => $personBars,
            'bar_count' => $this->stackedBarCount($personBars),
            'warnings' => [],
            'status' => $primary->status,
        ];

        if ($grouped) {
            $row['labor'] = $labor['groups']['ondergrond'] ?? null;
        }

        return [$row, $usedIds];
    }

    /**
     * @return array<int, Collection<int, WorkOrder>>
     */
    private function loadedWorkOrdersByItem(Project $project): array
    {
        if (! $project->relationLoaded('workItems')) {
            return [];
        }

        $orders = [];
        foreach ($project->workItems as $item) {
            if ($item->relationLoaded('workOrders')) {
                $orders[(int) $item->id] = $item->getRelation('workOrders');
            }
        }

        return $orders;
    }

    /**
     * @param  array<int, Collection<int, WorkOrder>>  $ordersByItem
     */
    private function restoreWorkOrders(Project $project, array $ordersByItem): void
    {
        if ($ordersByItem === [] || ! $project->relationLoaded('workItems')) {
            return;
        }

        foreach ($project->workItems as $item) {
            $id = (int) $item->id;
            if (array_key_exists($id, $ordersByItem)) {
                $item->setRelation('workOrders', $ordersByItem[$id]);
            }
        }
    }

    private function hoursView(Request $request, CarbonInterface $windowEnd): string
    {
        $value = $request->string('hours_view')->toString();
        if (in_array($value, ['planned', 'actual'], true)) {
            return $value;
        }

        return $windowEnd->lt(now()->startOfDay()) ? 'actual' : 'planned';
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     */
    private function applyApprovedHours(Collection $assignments, bool $onlyWhenApproved = false): void
    {
        $ids = $assignments->modelKeys();
        if ($ids === []) {
            return;
        }

        $entries = TimeEntry::query()
            ->where(function ($query): void {
                $query->where('status', TimeEntryStatus::Approved)
                    ->orWhere('status', TimeEntryStatus::Submitted);
            })
            ->where(function ($query) use ($ids): void {
                $query->whereIn('worker_assignment_id', $ids)
                    ->orWhereIn('actual_assignment_id', $ids);
            })
            ->get();

        $byAssignment = $entries->groupBy(function (TimeEntry $entry): int {
            return (int) ($entry->worker_assignment_id ?? $entry->actual_assignment_id);
        });

        foreach ($assignments as $assignment) {
            $matched = $byAssignment->get($assignment->id);
            if ($matched === null || $matched->isEmpty()) {
                continue;
            }
            $approved = $matched->filter(
                fn (TimeEntry $entry): bool => $entry->isApproved() && $entry->approved_hours !== null,
            );
            if ($onlyWhenApproved && ($approved->isEmpty() || ! $this->approvedEntriesCoverPlan($assignment, $approved))) {
                continue;
            }
            $hours = (float) $approved->sum(
                fn (TimeEntry $entry): float => round((float) $entry->approved_hours, 2),
            );
            $assignment->setAttribute('approved_hours', round($hours, 2));
        }
    }

    /**
     * @param  Collection<int, TimeEntry>  $approved
     */
    private function approvedEntriesCoverPlan(WorkerAssignment $assignment, Collection $approved): bool
    {
        $covered = $approved
            ->map(fn (TimeEntry $entry): string => $entry->date->toDateString())
            ->unique();
        $day = $assignment->start_date->copy()->startOfDay();
        $last = $assignment->end_date->copy()->startOfDay();
        while ($day->lte($last)) {
            if (PlanningHours::countsOnDate($day, $assignment->includesSaturday(), $assignment->includesSunday())
                && ! $covered->contains($day->toDateString())) {
                return false;
            }
            $day->addDay();
        }

        return true;
    }

    private function applyUniqueBarHours(Collection $assignments): void
    {
        $workerIds = $assignments->pluck('worker_id')->filter()->unique()->values();
        if ($workerIds->isEmpty()) {
            return;
        }

        $start = $assignments->min(fn (WorkerAssignment $assignment) => $assignment->start_date);
        $end = $assignments->max(fn (WorkerAssignment $assignment) => $assignment->end_date);
        if ($start === null || $end === null) {
            return;
        }

        $related = WorkerAssignment::query()
            ->with('crewMembers')
            ->whereIn('worker_id', $workerIds)
            ->coveringDates($start, $end)
            ->get();
        $hoursById = $this->uniqueHoursByAssignment($related);
        foreach ($assignments as $assignment) {
            if (! array_key_exists($assignment->id, $hoursById)) {
                continue;
            }
            $assignment->setAttribute('unique_hours', $hoursById[$assignment->id]);
        }
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return array<int, float>
     */
    private function uniqueHoursByAssignment(Collection $assignments): array
    {
        $groups = [];
        foreach ($assignments as $assignment) {
            if ($assignment->isProvisional() || $assignment->isHoursOrigin()) {
                continue;
            }
            $assignment->loadMissing('crewMembers');
            if ($assignment->crewMembers->isEmpty()) {
                $groups['worker-'.$assignment->worker_id][] = [$assignment, null];

                continue;
            }
            foreach ($assignment->crewMembers as $member) {
                $groups['member-'.$member->id][] = [$assignment, $member];
            }
        }

        $perPerson = [];
        foreach ($groups as $entries) {
            foreach ($this->claimedHoursForPeople($entries) as $id => $hours) {
                $perPerson[$id][] = $hours;
            }
        }

        $totals = [];
        foreach ($perPerson as $id => $hoursList) {
            $totals[(int) $id] = round(max($hoursList), 2);
        }

        return $totals;
    }

    /**
     * @param  list<array{0: WorkerAssignment, 1: ?CrewMember}>  $entries
     * @return array<int, float>
     */
    private function claimedHoursForPeople(array $entries): array
    {
        $start = null;
        $end = null;
        foreach ($entries as [$assignment]) {
            $start = $start === null || $assignment->start_date->lt($start) ? $assignment->start_date->copy() : $start;
            $end = $end === null || $assignment->end_date->gt($end) ? $assignment->end_date->copy() : $end;
        }
        if ($start === null || $end === null) {
            return [];
        }

        $totals = [];
        $day = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($day->lte($last)) {
            $rows = [];
            foreach ($entries as [$assignment, $member]) {
                $interval = $member instanceof CrewMember
                    ? $assignment->intervalForPerson($day, $member)
                    : $assignment->intervalOnDate($day);
                if ($interval === null) {
                    continue;
                }
                $rows[] = [
                    'id' => $assignment->id,
                    'start' => $interval[0],
                    'end' => $interval[1],
                ];
            }
            foreach (PlanningHours::claimById($rows) as $id => $hours) {
                $totals[(int) $id] = ($totals[(int) $id] ?? 0) + $hours;
            }
            $day->addDay();
        }

        return $totals;
    }
}
