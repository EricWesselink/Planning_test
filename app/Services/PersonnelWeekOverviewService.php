<?php

namespace App\Services;

use App\Models\CrewMember;
use App\Models\Project;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PersonnelWeekOverviewService
{
    /**
     * @var array<string, string>
     */
    private const AWAY_COLORS = [
        'vakantie' => '#dbe4ee',
        'ziek' => '#efe4dc',
        'verlof' => '#e8e4f0',
        'vrij' => '#ececec',
        'vrije_dag' => '#ececec',
        'adv' => '#ececec',
        'cursus' => '#e8e4f0',
        'overig' => '#ececec',
        'unavailable' => '#ececec',
    ];

    private const WORK_ROWS_PER_PAGE = 12;

    public function __construct(
        private PlanningBoardService $board,
        private WorkerAvailabilityService $availability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $weekStart = $this->board->weekStart(
            $request->string('week')->toString() ?: null,
            $this->optionalInt($request->input('week_nr')),
            $this->optionalInt($request->input('year')),
        );
        $days = $this->board->weekDays($weekStart, 1);
        $monday = $days->first();
        $saturday = $days->last();
        $weekNumber = (int) $monday->isoWeek();
        $weekYear = (int) $monday->isoWeekYear();
        $dayCount = $days->count();

        $assignments = WorkerAssignment::query()
            ->with([
                'worker.crewPeople',
                'worker.availabilities',
                'workItem',
                'project.customer',
                'project.workItems',
                'project.workActivities',
                'crewMembers',
            ])
            ->whereHas('project', function ($query) use ($request): void {
                $query->active()->accessibleBy($request->user());
            })
            ->whereDate('end_date', '>=', $monday)
            ->whereDate('start_date', '<=', $saturday)
            ->when(
                $request->user()?->scheduledWorkerId(),
                fn ($query, int $workerId) => $query->where('worker_id', $workerId),
            )
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $projects = $this->projects($assignments, $days);
        $absences = $this->absences($request, $days);
        if ($absences !== null) {
            $projects[] = $absences;
        }

        $logoRelative = (string) config('company.logo');

        return [
            'filename' => 'weekplanning-personeel-week-'.$weekNumber.'-'.$weekYear.'.pdf',
            'heading' => 'Weekplanning personeel',
            'weekNumber' => $weekNumber,
            'weekYear' => $weekYear,
            'weekLabel' => 'Week '.$weekNumber,
            'weekRange' => $monday->translatedFormat('j F').' – '.$saturday->translatedFormat('j F Y'),
            'generatedOn' => now()->format('d-m-Y'),
            'logo' => $this->imagePath($logoRelative),
            'logoUrl' => $logoRelative !== '' ? asset($logoRelative) : null,
            'companyName' => (string) config('company.name'),
            'weekStart' => $weekStart,
            'prevWeek' => $weekStart->copy()->subWeek()->toDateString(),
            'nextWeek' => $weekStart->copy()->addWeek()->toDateString(),
            'dayCount' => $dayCount,
            'days' => $days->map(fn (Carbon $day): array => [
                'key' => $day->toDateString(),
                'name' => Str::ucfirst($day->translatedFormat('l')),
                'date' => $day->translatedFormat('j F'),
                'short' => Str::ucfirst($day->translatedFormat('l')).' '.$day->translatedFormat('j F'),
                'weekday' => $day->isWeekday(),
            ])->values()->all(),
            'projects' => $projects,
            'pages' => $this->paginate($projects),
            'legend' => $this->legend($assignments),
        ];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, Carbon>  $days
     * @return list<array<string, mixed>>
     */
    private function projects(Collection $assignments, Collection $days): array
    {
        $grouped = $assignments
            ->filter(fn (WorkerAssignment $assignment): bool => $assignment->project !== null)
            ->groupBy(fn (WorkerAssignment $assignment): int => (int) $assignment->project_id);

        $projects = [];
        foreach ($grouped as $rows) {
            $project = $rows->first()->project;
            if (! $project instanceof Project) {
                continue;
            }

            $works = $this->workRows($rows, $days);
            if ($works === []) {
                continue;
            }

            $start = $project->planned_start_date;
            $end = $project->planned_end_date;

            $projects[] = [
                'id' => $project->id,
                'away' => false,
                'customer' => $project->customer?->name,
                'title' => $project->displayTitle(),
                'city' => trim((string) $project->city),
                'address' => $project->nawLine(),
                'number' => $project->isWinkel()
                    ? $project->workNumber()
                    : ($project->labeledNumbersLine() !== '' ? $project->labeledNumbersLine() : $project->workNumber()),
                'activities' => $this->activityNames($project, $rows),
                'start_marker' => $this->dateMarker($start, $days),
                'end_marker' => $this->dateMarker($end, $days),
                'works' => $works,
                'height' => array_sum(array_map(fn (array $work): int => (int) $work['height'], $works)),
            ];
        }

        usort($projects, function (array $left, array $right): int {
            $title = strcasecmp((string) $left['title'], (string) $right['title']);
            if ($title !== 0) {
                return $title;
            }

            return ((int) $left['id']) <=> ((int) $right['id']);
        });

        return array_values($projects);
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, Carbon>  $days
     * @return list<array<string, mixed>>
     */
    private function workRows(Collection $assignments, Collection $days): array
    {
        $groups = $assignments->groupBy(function (WorkerAssignment $assignment): string {
            $itemId = $assignment->work_item_id ? (string) $assignment->work_item_id : 'inzet';

            return $itemId;
        });

        $rows = [];
        foreach ($groups as $itemAssignments) {
            $first = $itemAssignments->first();
            $bars = [];
            foreach ($itemAssignments as $assignment) {
                foreach ($this->assignmentBars($assignment, $days) as $bar) {
                    $bars[] = $bar;
                }
            }
            if ($bars === []) {
                continue;
            }

            $stack = count($bars);
            $rows[] = [
                'title' => $first->workItem?->planningTitle() ?? 'Inzet',
                'bars' => $bars,
                'stack' => $stack,
                'height' => max(28, 8 + ($stack * 22)),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Carbon>  $days
     * @return list<array<string, mixed>>
     */
    private function assignmentBars(WorkerAssignment $assignment, Collection $days): array
    {
        $worker = $assignment->worker;
        if ($worker === null) {
            return [];
        }

        $groups = $this->nameGroups($assignment);
        $bars = [];
        $stack = 0;
        foreach ($groups as $group) {
            $segment = $this->barSegment($assignment, $days, $group['start_time'], $group['end_time']);
            if ($segment === null) {
                continue;
            }

            $fullDay = $this->isFullDay($group['start_time'], $group['end_time']);
            $color = $worker->planColor();
            $bars[] = [
                'label' => implode(' · ', $group['names']),
                'time_label' => $fullDay ? null : PlanningHours::formatTime($group['start_time']).' – '.PlanningHours::formatTime($group['end_time']),
                'color' => $color,
                'text' => $this->textColor($color),
                'away' => false,
                'stack' => $stack,
                'bar' => $segment,
            ];
            $stack++;
        }

        return $bars;
    }

    /**
     * @return list<array{names: list<string>, start_time: string, end_time: string}>
     */
    private function nameGroups(WorkerAssignment $assignment): array
    {
        $members = $assignment->crewMembers;
        if ($members->isEmpty()) {
            $name = $this->workerPersonName($assignment->worker);
            if ($name === '') {
                return [];
            }

            return [[
                'names' => [$name],
                'start_time' => $assignment->startTimeValue(),
                'end_time' => $assignment->endTimeValue(),
            ]];
        }

        $groups = [];
        foreach ($members as $member) {
            $start = (string) ($member->pivot->start_time ?: $assignment->startTimeValue());
            $end = (string) ($member->pivot->end_time ?: $assignment->endTimeValue());
            $key = $start.'|'.$end;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'names' => [],
                    'start_time' => $start,
                    'end_time' => $end,
                ];
            }
            $label = $this->shortName($member->label());
            if ($label !== '' && ! in_array($label, $groups[$key]['names'], true)) {
                $groups[$key]['names'][] = $label;
            }
        }

        return array_values(array_filter(
            $groups,
            fn (array $group): bool => $group['names'] !== [],
        ));
    }

    /**
     * @param  Collection<int, Carbon>  $days
     * @return array{start: int, span: int, start_offset: float, end_offset: float}|null
     */
    private function barSegment(
        WorkerAssignment $assignment,
        Collection $days,
        string $startTime,
        string $endTime,
    ): ?array {
        $visible = $days->values();
        $runStart = null;
        $runEnd = null;

        foreach ($visible as $index => $day) {
            $covers = $assignment->coversDate($day)
                && PlanningHours::intervalOnDate(
                    $day,
                    $assignment->start_date,
                    $assignment->end_date,
                    $startTime,
                    $endTime,
                    $assignment->includesSaturday(),
                    $assignment->includesSunday(),
                ) !== null;

            if (! $covers) {
                continue;
            }

            if ($runStart === null) {
                $runStart = $index;
            }
            $runEnd = $index;
        }

        if ($runStart === null || $runEnd === null) {
            return null;
        }

        $firstDay = $visible[$runStart];
        $lastDay = $visible[$runEnd];

        return [
            'start' => $runStart,
            'span' => $runEnd - $runStart + 1,
            'start_offset' => $assignment->start_date->toDateString() !== $firstDay->toDateString()
                ? 0.0
                : PlanningHours::fractionFromTime($startTime),
            'end_offset' => $assignment->end_date->toDateString() !== $lastDay->toDateString()
                ? 1.0
                : PlanningHours::fractionFromTime($endTime),
        ];
    }

    /**
     * @param  Collection<int, Carbon>  $days
     * @return array<string, mixed>|null
     */
    private function absences(Request $request, Collection $days): ?array
    {
        $workers = Worker::query()
            ->where('active', true)
            ->with(['crewPeople', 'availabilities'])
            ->when(
                $request->user()?->scheduledWorkerId(),
                fn ($query, int $workerId) => $query->whereKey($workerId),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $bars = [];
        $stack = 0;
        foreach ($workers as $worker) {
            $people = $worker->activeCrewPeople();
            if ($people->isEmpty()) {
                $people = collect([null]);
            }

            foreach ($people as $member) {
                if ($member instanceof CrewMember) {
                    $member->setRelation('worker', $worker);
                }
                $name = $member instanceof CrewMember
                    ? $this->shortName($member->label())
                    : $this->workerPersonName($worker);

                $run = null;
                foreach ($days->values() as $index => $day) {
                    $absence = $this->availability->absenceOn(
                        $worker,
                        $day,
                        $member instanceof CrewMember ? $member : null,
                    );
                    $usable = $absence !== null
                        && empty($absence['structural']);

                    if ($usable && is_array($absence)) {
                        $label = $absence['label'] === 'Vrij op vrijdag' ? 'Vrij' : $absence['label'];
                        $color = self::AWAY_COLORS[$absence['key']] ?? self::AWAY_COLORS['overig'];
                        if (
                            $run !== null
                            && $run['label'] === $label
                            && $run['color'] === $color
                            && ((int) $run['end'] + 1) === $index
                        ) {
                            $run['end'] = $index;
                        } else {
                            if ($run !== null) {
                                $bars[] = $this->absenceBar($run, $name, $stack);
                                $stack++;
                            }
                            $run = [
                                'label' => $label,
                                'color' => $color,
                                'start' => $index,
                                'end' => $index,
                                'hint' => $absence['full'] ? null : $absence['hint'],
                            ];
                        }
                    } elseif ($run !== null) {
                        $bars[] = $this->absenceBar($run, $name, $stack);
                        $stack++;
                        $run = null;
                    }
                }
                if ($run !== null) {
                    $bars[] = $this->absenceBar($run, $name, $stack);
                    $stack++;
                }
            }
        }

        if ($bars === []) {
            return null;
        }

        return [
            'id' => 0,
            'away' => true,
            'customer' => null,
            'title' => 'Afwezigheid',
            'city' => '',
            'address' => null,
            'number' => '',
            'activities' => [],
            'start_marker' => null,
            'end_marker' => null,
            'works' => [[
                'title' => 'Vakantie / vrij / ziek',
                'bars' => $bars,
                'stack' => max(1, count($bars)),
                'height' => max(28, 8 + (count($bars) * 22)),
            ]],
            'height' => max(28, 8 + (count($bars) * 22)),
        ];
    }

    /**
     * @param  array{label: string, color: string, start: int, end: int, hint: ?string}  $run
     * @return array<string, mixed>
     */
    private function absenceBar(array $run, string $name, int $stack): array
    {
        return [
            'label' => mb_strtoupper($run['label']).' · '.$name,
            'time_label' => $run['hint'],
            'color' => $run['color'],
            'text' => '#163a5f',
            'away' => true,
            'stack' => $stack,
            'bar' => [
                'start' => $run['start'],
                'span' => $run['end'] - $run['start'] + 1,
                'start_offset' => 0.0,
                'end_offset' => 1.0,
            ],
        ];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return list<string>
     */
    private function activityNames(Project $project, Collection $assignments): array
    {
        $fromAssignments = $assignments
            ->map(fn (WorkerAssignment $assignment): string => trim((string) ($assignment->workItem?->planningTitle() ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($fromAssignments->isNotEmpty()) {
            return $fromAssignments->all();
        }

        if ($project->isWinkel()) {
            return $project->workActivities
                ->sortBy(fn ($activity): int => (int) ($activity->pivot?->sort_order ?? 0))
                ->map(fn ($activity): string => (string) $activity->name)
                ->filter()
                ->values()
                ->all();
        }

        return $project->workItems
            ->sortBy('sort_order')
            ->map(fn ($item): string => $item->planningTitle())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @return list<array{label: string, color: string}>
     */
    private function legend(Collection $assignments): array
    {
        $legend = [];
        foreach ($assignments as $assignment) {
            $worker = $assignment->worker;
            if ($worker === null) {
                continue;
            }
            $id = (int) $worker->id;
            if (isset($legend[$id])) {
                continue;
            }
            $legend[$id] = [
                'label' => $worker->planName(),
                'color' => $worker->planColor(),
            ];
        }

        $list = array_values($legend);
        usort($list, fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));

        return $list;
    }

    /**
     * @param  list<array<string, mixed>>  $projects
     * @return list<list<array<string, mixed>>>
     */
    private function paginate(array $projects): array
    {
        $pages = [];
        $current = [];
        $weight = 0;

        foreach ($projects as $project) {
            $size = 1 + count($project['works'] ?? []);
            if ($current !== [] && ($weight + $size) > self::WORK_ROWS_PER_PAGE) {
                $pages[] = $current;
                $current = [];
                $weight = 0;
            }
            $current[] = $project;
            $weight += $size;
        }

        if ($current !== []) {
            $pages[] = $current;
        }

        return $pages === [] ? [[]] : $pages;
    }

    /**
     * @param  Collection<int, Carbon>  $days
     * @return array{index: int, date: string}|null
     */
    private function dateMarker(?CarbonInterface $date, Collection $days): ?array
    {
        if ($date === null) {
            return null;
        }

        $index = $days->search(fn (Carbon $day): bool => $day->toDateString() === $date->toDateString());
        if ($index === false) {
            return null;
        }

        return [
            'index' => (int) $index,
            'date' => $date->format('d-m-Y'),
        ];
    }

    private function workerPersonName(?Worker $worker): string
    {
        if ($worker === null) {
            return '';
        }

        if ($worker->employment_type?->isExternal()) {
            $company = trim((string) $worker->company);
            $name = $this->shortName($worker->planName());
            if ($company !== '' && $name !== '' && strcasecmp($company, $worker->planName()) !== 0) {
                return $company.' · '.$name;
            }

            return $company !== '' ? $company : $name;
        }

        return $this->shortName($worker->displayName());
    }

    private function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $parts[0] ?? trim($name);
    }

    private function isFullDay(string $start, string $end): bool
    {
        return PlanningHours::formatTime($start) === PlanningHours::DAY_START
            && PlanningHours::formatTime($end) === PlanningHours::DAY_END;
    }

    private function textColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '#ffffff';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luma = ((0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b)) / 255;

        return $luma > 0.62 ? '#163a5f' : '#ffffff';
    }

    private function imagePath(string $relative): ?string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return null;
        }

        $absolute = public_path($relative);
        if (! is_file($absolute)) {
            return null;
        }

        return 'file://'.str_replace('\\', '/', $absolute);
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
