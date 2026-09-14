<?php

namespace App\Services;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\SmallWorkType;
use App\Enums\WorkPhase;
use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Support\Format;
use App\Support\PlanningHours;
use App\Support\PlanningLaborForecast;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlanningBoardService
{
    public const WEEK_OPTIONS = [1, 2, 3, 4, 6, 8];

    public function __construct(
        private ConflictService $conflicts,
        private PlanningAvailabilityService $availability,
        private ProjectLaborCalculator $labor,
    ) {}

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
        $weekBands = $this->weekBands($days, $weeks);
        $kindFilter = $this->kindFilter($request);
        $staffingFilter = $this->staffingFilter($request);
        $scheduledWorkerId = $request->user()?->scheduledWorkerId();
        $workerId = $scheduledWorkerId ?? ($request->filled('worker_id') ? $request->integer('worker_id') : null);
        $canViewLabor = $request->user()?->canViewLaborCosts() ?? false;
        $windowStart = $days->first();
        $windowEnd = $days->last();

        $relations = [
            'customer',
            'workActivities.category',
            'workItems.progressEntries',
            'workItems.workOrders.worker',
            'workOrders.worker',
            'workOrders.workItem',
        ];
        if ($canViewLabor) {
            $relations[] = 'assignments.worker';
            $relations[] = 'assignments.crewMembers';
        }

        $projectQuery = Project::query()
            ->accessibleBy($request->user())
            ->active()
            ->with($relations)
            ->when($kindFilter !== null, fn ($q) => $this->constrainKind($q, $kindFilter))
            ->when($request->filled('project_id'), fn ($q) => $q->where('id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($staffingFilter === 'open' && $scheduledWorkerId === null, fn ($q) => $q->whereDoesntHave('assignments'))
            ->when($staffingFilter === 'planned', fn ($q) => $this->constrainAssignedInWindow($q, $windowStart, $windowEnd));

        $projects = $projectQuery
            ->orderBy('planned_start_date')
            ->get()
            ->sortBy([
                fn (Project $project): int => $project->isWinkel() ? 1 : 0,
                fn (Project $project): int => $project->planned_start_date?->timestamp ?? PHP_INT_MAX,
                fn (Project $project): int => $project->id,
            ])
            ->values();

        $assignments = WorkerAssignment::query()
            ->with(['worker', 'workItem', 'team', 'crewMembers'])
            ->whereHas('project', function ($q) use ($request, $kindFilter): void {
                $q->active()->accessibleBy($request->user());
                $this->constrainKind($q, $kindFilter);
            })
            ->where('end_date', '>=', $windowStart->toDateString())
            ->where('start_date', '<=', $windowEnd->toDateString())
            ->when($workerId, fn ($q) => $q->where('worker_id', $workerId))
            ->get();

        if ($workerId) {
            $projects = $projects->whereIn('id', $assignments->pluck('project_id'))->values();
        }

        $doubleBooked = $this->conflicts->doubleBookedMap($assignments, $days);
        $warnings = [];
        $rows = [];

        foreach ($projects as $project) {
            $projectAssignments = $assignments->where('project_id', $project->id)->sortBy(fn ($a) => $a->worker?->name);
            $usedIds = [];
            $workRows = [];
            $projectWarnings = [];
            $labor = $canViewLabor ? $this->labor->for($project) : [
                'groups' => [],
                'items_by_id' => [],
                'overrun_label' => null,
            ];

            if ($project->isSmallWork()) {
                $rows[] = $this->smallProjectRow($project, $projectAssignments, $days, $doubleBooked, $labor, $canViewLabor);

                continue;
            }

            $extraItems = $project->workItems
                ->filter(fn (WorkItem $item): bool => $item->isExtraWork())
                ->values();

            if ($project->isWinkel()) {
                [$workRows, $usedIds] = $this->winkelWorkRows($project, $projectAssignments, $days, $doubleBooked, $usedIds);
            } else {
                foreach ($project->workItems
                    ->reject(fn (WorkItem $item): bool => $item->isExtraWork())
                    ->groupBy(fn (WorkItem $item) => $item->typeKey())
                    ->sortBy(
                        fn (Collection $items) => $items->min(fn (WorkItem $item) => $item->phase()->sort())
                    ) as $packageKey => $items) {
                    $isOndergrond = $packageKey === 'ondergrond';
                    $primary = $isOndergrond
                        ? $this->primaryWorkItem($items)
                        : $items->sortByDesc(fn (WorkItem $item) => (float) $item->ordered_quantity)->first();
                    $ids = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $ordered = (float) $items->sum(fn (WorkItem $item) => (float) $item->ordered_quantity);
                    if ($ordered <= 0.0001) {
                        continue;
                    }
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
                        $workItemId = $assignment->resolvedWorkItemId($project->workOrders);

                        if (! in_array((int) $workItemId, $ids, true)) {
                            continue;
                        }

                        $bar = $this->assignmentBar($assignment, $days);
                        if (! $bar) {
                            continue;
                        }

                        $usedIds[] = $assignment->id;
                        $workName = $assignment->workItem?->typeLabel() ?? $primary->typeLabel();
                        $personBars[] = $this->personBar($assignment, $bar, $doubleBooked, $days, $workName, $project->name);
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

                    $starts = $items->pluck('planned_start_date')->filter();
                    $ends = $items->pluck('planned_end_date')->filter();
                    $title = $isOndergrond ? $primary->packageLabel() : $primary->planningTitle();
                    $steps = $isOndergrond && $items->count() > 1
                        ? $items->sortBy(fn (WorkItem $item) => $item->phase()->sort())->map(fn (WorkItem $item) => $item->name)->values()->all()
                        : [];
                    $itemLabor = $labor['groups'][$packageKey] ?? null;

                    $workRows[] = [
                        'type' => 'work',
                        'id' => $primary->id,
                        'project_id' => $project->id,
                        'title' => $title,
                        'steps' => $steps,
                        'unit' => $primary->unit->label(),
                        'ordered' => $ordered,
                        'completed' => $done,
                        'remaining' => $rest,
                        'percent' => $this->progressPercent($done, $ordered),
                        'who' => $who,
                        'bar' => $this->bar($starts->min(), $ends->max(), $days),
                        'person_bars' => $personBars,
                        'bar_count' => count($personBars),
                        'warnings' => array_values(array_unique($itemWarnings)),
                        'status' => $primary->status,
                        'labor' => $itemLabor,
                    ];
                }
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
                $bar = $this->assignmentBar($assignment, $days);
                if (! $bar) {
                    continue;
                }
                $workName = $assignment->workItem?->typeLabel() ?? 'inzet';
                $leftoverBars[] = $this->personBar($assignment, $bar, $doubleBooked, $days, $workName, $project->name);
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
                    'bar' => $period['bar'],
                    'start_marker' => $period['start_marker'],
                    'end_marker' => $period['end_marker'],
                    'werk_start' => $period['werk_start'],
                    'missing_craftsman' => $period['missing_craftsman'],
                    'start_week' => $startWeek,
                    'person_bars' => $leftoverBars,
                    'bar_count' => count($leftoverBars),
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

        $rows = $this->groupRowsByKind($rows, $kindFilter);

        foreach ($doubleBooked as $workerId => $dates) {
            $name = $assignments->firstWhere('worker_id', $workerId)?->worker?->displayName();
            if (! $name) {
                continue;
            }
            $personNames = collect($dates)
                ->flatMap(fn (array $row): array => $row['person_names'] ?? [])
                ->unique()
                ->values();
            $message = $personNames->isNotEmpty()
                ? $personNames->implode(', ').' van '.$name.' staat op meerdere werken.'
                : $name.' heeft meer personen ingepland dan het team.';
            $warnings[] = [
                'worker_id' => (int) $workerId,
                'message' => $message,
                'date' => (string) array_key_first($dates),
            ];
        }

        $teamManDays = $scheduledWorkerId ? [] : $this->availability->forDays($days);

        return [
            'weekStart' => $weekStart,
            'weeks' => $weeks,
            'weekBands' => $weekBands,
            'weekRangeLabel' => $this->periodRangeLabel($printPeriod, $requestedStart, $weekBands),
            'prevWeek' => $requestedStart->copy()->subWeeks($this->weeks($request))->toDateString(),
            'nextWeek' => $requestedStart->copy()->addWeeks($this->weeks($request))->toDateString(),
            'thisWeek' => now()->startOfWeek(Carbon::MONDAY)->startOfDay()->toDateString(),
            'days' => $days,
            'dayCount' => $days->count(),
            'dayMin' => $weeks === 1 ? 180 : ($weeks <= 3 ? 120 : ($weeks <= 8 ? 96 : 56)),
            'rows' => $rows,
            'projects' => $this->filterProjects($request, $kindFilter, $staffingFilter, $scheduledWorkerId, $windowStart, $windowEnd),
            'warnings' => array_values($warnings),
            'period' => $printPeriod,
            'periodFallback' => $request->input('period') === 'work' && $printPeriod !== 'work',
            'filters' => [
                'week' => $requestedStart->toDateString(),
                'weeks' => $printPeriod === '' ? $weeks : $this->weeks($request),
                'period' => $printPeriod,
                'kind' => $kindFilter instanceof ProjectKind ? $kindFilter->value : (string) ($kindFilter ?? ''),
                'project_id' => $request->input('project_id'),
                'worker_id' => $workerId,
                'status' => $request->input('status'),
                'staffing' => $scheduledWorkerId === null ? ($staffingFilter ?? '') : '',
            ],
            'weekOptions' => self::WEEK_OPTIONS,
            'teamManDays' => $teamManDays,
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
        ?string $staffingFilter,
        ?int $scheduledWorkerId,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): Collection {
        return Project::query()
            ->accessibleBy($request->user())
            ->active()
            ->with('workItems')
            ->when($kindFilter !== null, fn (Builder $query) => $this->constrainKind($query, $kindFilter))
            ->when($staffingFilter === 'open' && $scheduledWorkerId === null, fn (Builder $query) => $query->whereDoesntHave('assignments'))
            ->when($staffingFilter === 'planned', fn (Builder $query) => $this->constrainAssignedInWindow($query, $windowStart, $windowEnd))
            ->orderBy('project_number')
            ->get();
    }

    private function constrainAssignedInWindow(Builder $query, Carbon $windowStart, Carbon $windowEnd): Builder
    {
        return $query->whereHas('assignments', function (Builder $assignments) use ($windowStart, $windowEnd): void {
            $assignments
                ->where('end_date', '>=', $windowStart->toDateString())
                ->where('start_date', '<=', $windowEnd->toDateString());
        });
    }

    private function staffingFilter(Request $request): ?string
    {
        $value = (string) $request->input('staffing', '');

        return in_array($value, ['open', 'planned'], true) ? $value : null;
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
            fn (array $row): bool => $row['ordered'] !== null && $row['completed'] !== null
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
        $source = $squareMeters->isNotEmpty() ? $squareMeters : $rows;
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

    private function personBar(WorkerAssignment $assignment, array $bar, array $doubleBooked, Collection $days, string $workName, string $projectName = ''): array
    {
        $label = $assignment->planningLabel();
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
            'people_count' => $assignment->peopleCount(),
            'crew_ids' => $crewIds,
            'label' => $label,
            'title' => $assignment->detailTitle($workName, $projectName),
            'start_date' => $assignment->start_date->toDateString(),
            'end_date' => $assignment->end_date->toDateString(),
            'start_time' => PlanningHours::formatTime($assignment->startTimeValue()),
            'end_time' => PlanningHours::formatTime($assignment->endTimeValue()),
            'include_weekends' => $assignment->includesWeekends(),
            'hours_per_day' => (float) $assignment->hours_per_day,
            'planned_hours' => $assignment->plannedHoursValue(),
            'color' => $assignment->worker?->planColor() ?? Format::planColor((int) $assignment->worker_id),
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

    private function assignmentBar(WorkerAssignment $assignment, Collection $days): ?array
    {
        $box = $this->bar($assignment->start_date, $assignment->end_date, $days);
        if (! $box) {
            return null;
        }

        $rangeStart = $days->first()->copy()->startOfDay();
        $rangeEnd = $days->last()->copy()->startOfDay();
        $box['start_offset'] = $assignment->start_date->copy()->startOfDay()->gte($rangeStart)
            ? PlanningHours::fractionFromTime($assignment->startTimeValue())
            : 0.0;
        $box['end_offset'] = $assignment->end_date->copy()->startOfDay()->lte($rangeEnd)
            ? PlanningHours::fractionFromTime($assignment->endTimeValue())
            : 1.0;

        return $box;
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
        $item = $project->workItems->first();
        $personBars = [];
        foreach ($projectAssignments as $assignment) {
            $bar = $this->assignmentBar($assignment, $days);
            if (! $bar) {
                continue;
            }
            $personBars[] = $this->personBar(
                $assignment,
                $bar,
                $doubleBooked,
                $days,
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

        return $this->compactBoardRow(
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
                if ((int) $assignment->work_item_id !== (int) $item->id) {
                    continue;
                }
                $bar = $this->assignmentBar($assignment, $days);
                if (! $bar) {
                    continue;
                }
                $usedIds[] = $assignment->id;
                $personBars[] = $this->personBar(
                    $assignment,
                    $bar,
                    $doubleBooked,
                    $days,
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
                : round((float) collect($personBars)->sum('planned_hours'), 2);
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
            'naw_line' => null,
            'maps_url' => $project->googleMapsUrl(),
            'who' => collect($personBars)->pluck('label')->filter()->unique()->values(),
            'status' => $project->status->label(),
            'bar' => $this->bar($start, $end, $days),
            'start_marker' => $this->dateMarker($start, $days),
            'end_marker' => $endMarker,
            'werk_start' => $this->offgridStartLabel($start, $days),
            'missing_craftsman' => $personBars === [],
            'start_week' => $startWeek,
            'person_bars' => $personBars,
            'bar_count' => count($personBars),
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
     * @param  Collection<int, WorkerAssignment>  $projectAssignments
     * @param  Collection<int, Carbon>  $days
     * @param  array<int, array<string, mixed>>  $doubleBooked
     * @param  list<int>  $usedIds
     * @return array{0: list<array<string, mixed>>, 1: list<int>}
     */
    private function winkelWorkRows(Project $project, Collection $projectAssignments, Collection $days, array $doubleBooked, array $usedIds): array
    {
        $workRows = [];

        foreach ($project->workItems->sortBy('sort_order') as $item) {
            if ($item->isExtraWork()) {
                continue;
            }
            $personBars = [];
            foreach ($projectAssignments as $assignment) {
                if ((int) $assignment->work_item_id !== (int) $item->id) {
                    continue;
                }

                $bar = $this->assignmentBar($assignment, $days);
                if (! $bar) {
                    continue;
                }

                $usedIds[] = $assignment->id;
                $personBars[] = $this->personBar($assignment, $bar, $doubleBooked, $days, $item->name, $project->name);
            }

            $budgetHours = $item->begrote_uren === null ? 0.0 : round((float) $item->begrote_uren, 2);
            $personBars = $this->decorateBarsWithBudget(
                $personBars,
                $projectAssignments,
                [(int) $item->id],
                $budgetHours,
                $project->workOrders,
            );

            $note = trim((string) $item->notes);
            $ordered = (float) $item->ordered_quantity;
            $hasQuantity = $ordered > 0;

            $workRows[] = [
                'type' => 'work',
                'id' => $item->id,
                'project_id' => $project->id,
                'title' => $item->name,
                'steps' => $note === '' ? [] : [$note],
                'unit' => $hasQuantity ? ($item->unit?->label() ?? '') : '',
                'ordered' => $hasQuantity ? $ordered : null,
                'ordered_decimals' => $hasQuantity && fmod($ordered, 1.0) !== 0.0 ? 2 : 0,
                'completed' => null,
                'remaining' => null,
                'percent' => null,
                'who' => collect(),
                'bar' => $this->bar($item->planned_start_date, $item->planned_end_date, $days),
                'person_bars' => $personBars,
                'bar_count' => count($personBars),
                'warnings' => [],
                'status' => $item->status,
            ];
        }

        return [$workRows, $usedIds];
    }
}
