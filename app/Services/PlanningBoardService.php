<?php

namespace App\Services;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\WorkPhase;
use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Support\Format;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlanningBoardService
{
    public const WEEK_OPTIONS = [1, 2, 3, 4, 6, 8];

    public function __construct(
        private ConflictService $conflicts,
        private PlanningAvailabilityService $availability,
    ) {}

    public function weekStart(?string $week, ?int $weekNr = null, ?int $year = null): Carbon
    {
        if ($weekNr !== null && $weekNr >= 1 && $weekNr <= 53) {
            $isoYear = ($year !== null && $year >= 2000 && $year <= 2100)
                ? $year
                : ($week ? Carbon::parse($week)->isoWeekYear : 2026);
            $maxWeek = (int) Carbon::now()->setISODate($isoYear, 1)->isoWeeksInYear();
            $weekNr = min($weekNr, max(1, $maxWeek));

            return Carbon::now()->setISODate($isoYear, $weekNr, Carbon::MONDAY)->startOfDay();
        }

        $date = $week ? Carbon::parse($week) : Carbon::parse('2026-09-07');

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

        $projects = Project::query()
            ->accessibleBy($request->user())
            ->active()
            ->with([
                'customer',
                'workActivities.category',
                'workItems.progressEntries',
                'workItems.workOrders.worker',
                'assignments.worker',
                'assignments.crewMembers',
                'workOrders.worker',
                'workOrders.workItem',
            ])
            ->when($kindFilter !== null, fn ($q) => $q->where('kind', $kindFilter))
            ->when($request->filled('project_id'), fn ($q) => $q->where('id', $request->integer('project_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($staffingFilter === 'open' && $scheduledWorkerId === null, fn ($q) => $q->whereDoesntHave('assignments'))
            ->when($staffingFilter === 'planned', fn ($q) => $q->whereHas('assignments'))
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
                if ($kindFilter !== null) {
                    $q->where('kind', $kindFilter);
                }
            })
            ->whereDate('end_date', '>=', $days->first())
            ->whereDate('start_date', '<=', $days->last())
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

            if ($project->isWinkel()) {
                [$workRows, $usedIds] = $this->winkelWorkRows($project, $projectAssignments, $days, $doubleBooked, $usedIds);
            } else {
                foreach ($project->workItems->groupBy(fn (WorkItem $item) => $item->typeKey())->sortBy(
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
                        $workItemId = $assignment->work_item_id
                            ?? $project->workOrders->firstWhere('worker_id', $assignment->worker_id)?->work_item_id;

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

                    $starts = $items->pluck('planned_start_date')->filter();
                    $ends = $items->pluck('planned_end_date')->filter();
                    $title = $isOndergrond ? $primary->packageLabel() : $primary->planningTitle();
                    $steps = $isOndergrond && $items->count() > 1
                        ? $items->sortBy(fn (WorkItem $item) => $item->phase()->sort())->map(fn (WorkItem $item) => $item->name)->values()->all()
                        : [];

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
                    ];
                }
            }

            if ($scheduledWorkerId !== null) {
                $workRows = array_values(array_filter(
                    $workRows,
                    fn (array $row): bool => ($row['bar_count'] ?? 0) > 0
                ));
            }

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

            $rows[] = [
                'type' => 'project',
                'id' => $project->id,
                'kind' => $project->kind?->value,
                'badge' => $project->isWinkel() ? $project->kind?->badge() : null,
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
                'missing_craftsman' => $period['missing_craftsman'],
                'start_week' => $startWeek,
                'person_bars' => $leftoverBars,
                'bar_count' => count($leftoverBars),
                'warnings' => array_unique($projectWarnings),
                'ordered' => $quantities['ordered'],
                'completed' => $quantities['completed'],
                'remaining' => $quantities['remaining'],
                'percent' => $quantities['percent'],
                'unit' => $quantities['unit'],
                'children' => $workRows,
            ];
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
            'days' => $days,
            'dayCount' => $days->count(),
            'dayMin' => $weeks === 1 ? 180 : ($weeks <= 3 ? 120 : ($weeks <= 8 ? 96 : 56)),
            'rows' => $rows,
            'warnings' => array_values($warnings),
            'period' => $printPeriod,
            'periodFallback' => $request->input('period') === 'work' && $printPeriod !== 'work',
            'filters' => [
                'week' => $requestedStart->toDateString(),
                'weeks' => $printPeriod === '' ? $weeks : $this->weeks($request),
                'period' => $printPeriod,
                'kind' => $kindFilter?->value ?? '',
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

    private function kindFilter(Request $request): ?ProjectKind
    {
        return ProjectKind::tryFrom((string) $request->input('kind', ''));
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
    private function groupRowsByKind(array $rows, ?ProjectKind $kindFilter): array
    {
        if ($kindFilter !== null || $rows === []) {
            return $rows;
        }

        $projects = [];
        $winkel = [];
        foreach ($rows as $row) {
            if (($row['kind'] ?? ProjectKind::Project->value) === ProjectKind::Winkel->value) {
                $winkel[] = $row;
            } else {
                $projects[] = $row;
            }
        }

        if ($projects === [] || $winkel === []) {
            return array_values([...$projects, ...$winkel]);
        }

        return [
            ...$projects,
            [
                'type' => 'section',
                'title' => 'WINKELWERK',
            ],
            ...$winkel,
        ];
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
            'hours_per_day' => (float) $assignment->hours_per_day,
            'planned_hours' => $assignment->plannedHoursValue(),
            'color' => $assignment->worker?->planColor() ?? Format::planColor((int) $assignment->worker_id),
            'bar' => $bar,
            'double' => $double,
        ];
    }

    /**
     * Projectperiode op de projectregel: exacte start-/einddatum, geen personeelsbalk.
     *
     * @param  Collection<int, Carbon>  $days
     * @return array{bar: ?array{start: int, span: int}, start_marker: ?array{index: int, date: string}, end_marker: ?array{index: int, date: string, done: bool}, missing_craftsman: bool}
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
            'missing_craftsman' => $project->assignments->isEmpty(),
        ];
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
     * @param  list<int>  $usedIds
     * @return array{0: list<array<string, mixed>>, 1: list<int>}
     */
    private function winkelWorkRows(Project $project, Collection $projectAssignments, Collection $days, array $doubleBooked, array $usedIds): array
    {
        $workRows = [];

        foreach ($project->workItems->sortBy('sort_order') as $item) {
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
