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

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $unresolved = [];

    public function __construct(
        private PlanningBoardService $board,
        private WorkerAvailabilityService $availability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $this->unresolved = [];
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
                'crewMembers',
            ])
            ->where('is_provisional', false)
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

        $covering = $assignments->filter(
            fn (WorkerAssignment $assignment): bool => $this->coversWeek($assignment, $days),
        );
        $projects = $this->projects($covering, $days);
        $omittedWithoutInzet = $covering->pluck('project_id')->unique()->count() - count($projects);
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
            'omittedWithoutInzet' => max(0, $omittedWithoutInzet),
            'unresolved' => array_values($this->unresolved),
            'legend' => $this->legend($covering),
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

            $works = $this->workRows($rows, $assignments, $days);
            if ($works === []) {
                continue;
            }

            $start = $project->planned_start_date;
            $end = $project->planned_end_date;
            $height = array_sum(array_map(fn (array $work): int => (int) $work['height'], $works));

            $projects[] = [
                'id' => $project->id,
                'away' => false,
                'customer' => $project->customer?->name,
                'title' => $this->compactTitle($project),
                'city' => trim((string) $project->city),
                'address' => trim((string) $project->address) ?: null,
                'number' => $project->isWinkel()
                    ? $project->workNumber()
                    : ($project->labeledNumbersLine() !== '' ? $project->labeledNumbersLine() : $project->workNumber()),
                'start_marker' => $this->dateMarker($start, $days),
                'end_marker' => $this->dateMarker($end, $days),
                'works' => $works,
                'height' => $height,
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
     * @param  Collection<int, WorkerAssignment>  $itemAssignments
     * @param  Collection<int, WorkerAssignment>  $allAssignments
     * @param  Collection<int, Carbon>  $days
     * @return list<array<string, mixed>>
     */
    private function workRows(Collection $itemAssignments, Collection $allAssignments, Collection $days): array
    {
        $groups = $itemAssignments->groupBy(function (WorkerAssignment $assignment): string {
            return $assignment->work_item_id ? (string) $assignment->work_item_id : 'inzet';
        });

        $rows = [];
        foreach ($groups as $groupAssignments) {
            $first = $groupAssignments->first();
            $bars = [];
            foreach ($groupAssignments as $assignment) {
                foreach ($this->assignmentBars($assignment, $allAssignments, $days) as $bar) {
                    $bars[] = $bar;
                }
            }
            if ($bars === []) {
                continue;
            }

            $packed = $this->packBars($bars);
            $hasTime = collect($packed)->contains(
                fn (array $bar): bool => filled($bar['time_label'] ?? null),
            );
            $barHeight = $hasTime ? 26 : 16;
            $stack = 1 + (int) collect($packed)->max('stack');

            $rows[] = [
                'title' => $this->shortenOverviewText($first->workItem?->planningTitle() ?? 'Inzet'),
                'bars' => $packed,
                'cells' => [],
                'stack' => $stack,
                'bar_height' => $barHeight,
                'height' => 2 + ($stack * ($barHeight + 1)),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $allAssignments
     * @param  Collection<int, Carbon>  $days
     * @return list<array<string, mixed>>
     */
    private function assignmentBars(
        WorkerAssignment $assignment,
        Collection $allAssignments,
        Collection $days,
    ): array {
        $worker = $assignment->worker;
        if ($worker === null) {
            $this->rememberUnresolved($assignment, 'geen vakman gekoppeld');

            return [];
        }

        $groups = $this->nameGroups($assignment, $allAssignments);
        $bars = [];
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
                'stack' => 0,
                'bar' => $segment,
            ];
        }

        return $bars;
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $allAssignments
     * @return list<array{names: list<string>, start_time: string, end_time: string}>
     */
    private function nameGroups(WorkerAssignment $assignment, Collection $allAssignments): array
    {
        $worker = $assignment->worker;
        if ($worker === null) {
            $this->rememberUnresolved($assignment, 'geen vakman gekoppeld');

            return [];
        }

        if ($worker->employment_type?->isExternal()) {
            $name = $this->externalName($worker);
            if ($name === '') {
                $this->rememberUnresolved($assignment, 'geen ZZP-naam');

                return [];
            }

            return [[
                'names' => [$name],
                'start_time' => $assignment->startTimeValue(),
                'end_time' => $assignment->endTimeValue(),
            ]];
        }

        $members = $this->presentCrew($assignment, $allAssignments);
        if ($members === null) {
            $personal = $this->eigenPersonalName($worker);
            if ($personal !== null) {
                return [[
                    'names' => [$personal],
                    'start_time' => $assignment->startTimeValue(),
                    'end_time' => $assignment->endTimeValue(),
                ]];
            }

            $this->rememberUnresolved($assignment, 'geen gekoppelde vakman');

            return [];
        }

        if ($members->isEmpty()) {
            return [];
        }

        $groups = $this->groupsFromMembers($assignment, $members);
        if ($groups === []) {
            $this->rememberUnresolved($assignment, 'geen gekoppelde vakman');
        }

        return $groups;
    }

    /**
     * @param  Collection<int, CrewMember>  $members
     * @return list<array{names: list<string>, start_time: string, end_time: string}>
     */
    private function groupsFromMembers(WorkerAssignment $assignment, Collection $members): array
    {
        $groups = [];
        foreach ($members as $member) {
            $name = $this->personShortName($member->label());
            if ($name === null || trim((string) $member->name) === '') {
                continue;
            }

            $start = (string) ($member->pivot?->start_time ?: $assignment->startTimeValue());
            $end = (string) ($member->pivot?->end_time ?: $assignment->endTimeValue());
            $key = $start.'|'.$end;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'names' => [],
                    'start_time' => $start,
                    'end_time' => $end,
                ];
            }
            if (! in_array($name, $groups[$key]['names'], true)) {
                $groups[$key]['names'][] = $name;
            }
        }

        return array_values(array_filter(
            $groups,
            fn (array $group): bool => $group['names'] !== [],
        ));
    }

    /**
     * Named crew on this assignment, or remaining team members when nobody was synced.
     * Null means the names cannot be determined. An empty collection means nobody is present.
     *
     * @param  Collection<int, WorkerAssignment>  $allAssignments
     * @return Collection<int, CrewMember>|null
     */
    private function presentCrew(WorkerAssignment $assignment, Collection $allAssignments): ?Collection
    {
        $synced = $assignment->crewMembers
            ->filter(fn (CrewMember $member): bool => trim((string) $member->name) !== '')
            ->values();
        if ($synced->isNotEmpty()) {
            return $synced;
        }

        $worker = $assignment->worker;
        if ($worker === null) {
            return null;
        }

        $crew = $worker->activeCrewPeople()
            ->filter(fn (CrewMember $member): bool => trim((string) $member->name) !== '')
            ->values();
        if ($crew->isEmpty()) {
            return null;
        }

        $overlapping = $this->overlappingAssignments($assignment, $allAssignments);
        if ($overlapping->contains(fn (WorkerAssignment $other): bool => $other->crewMembers
            ->filter(fn (CrewMember $member): bool => trim((string) $member->name) !== '')
            ->isEmpty())) {
            return null;
        }

        $elsewhere = $overlapping
            ->flatMap(fn (WorkerAssignment $other): array => $other->crewMembers->modelKeys())
            ->unique()
            ->all();

        return $crew
            ->reject(fn (CrewMember $member): bool => in_array((int) $member->id, $elsewhere, true))
            ->values();
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $allAssignments
     * @return Collection<int, WorkerAssignment>
     */
    private function overlappingAssignments(WorkerAssignment $assignment, Collection $allAssignments): Collection
    {
        return $allAssignments->filter(function (WorkerAssignment $other) use ($assignment): bool {
            if ((int) $other->id === (int) $assignment->id) {
                return false;
            }
            if ((int) $other->worker_id !== (int) $assignment->worker_id) {
                return false;
            }
            if ($other->end_date->lt($assignment->start_date) || $other->start_date->gt($assignment->end_date)) {
                return false;
            }

            return $assignment->startTimeValue() < $other->endTimeValue()
                && $other->startTimeValue() < $assignment->endTimeValue();
        })->values();
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

        $cells = array_fill(0, $days->count(), []);
        foreach ($workers as $worker) {
            $people = $worker->activeCrewPeople()
                ->filter(fn (CrewMember $member): bool => trim((string) $member->name) !== '')
                ->values();
            if ($people->isEmpty()) {
                $name = $worker->employment_type?->isExternal()
                    ? $this->externalName($worker)
                    : $this->eigenPersonalName($worker);
                if ($name === null || $name === '') {
                    continue;
                }
                $this->collectAbsenceCells($cells, $worker, null, $name, $days);

                continue;
            }

            foreach ($people as $member) {
                $member->setRelation('worker', $worker);
                $name = $this->personShortName($member->label());
                if ($name === null) {
                    continue;
                }
                $this->collectAbsenceCells($cells, $worker, $member, $name, $days);
            }
        }

        $merged = [];
        $stack = 0;
        foreach ($cells as $index => $lines) {
            $merged[$index] = $this->mergeAbsenceLines($lines);
            $stack = max($stack, count($merged[$index]));
        }

        if ($stack === 0) {
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
            'start_marker' => null,
            'end_marker' => null,
            'works' => [[
                'title' => 'Vakantie / vrij / ziek / overig',
                'bars' => [],
                'cells' => $merged,
                'stack' => $stack,
                'bar_height' => 16,
                'height' => 2 + ($stack * 17),
            ]],
            'height' => 2 + ($stack * 17),
        ];
    }

    /**
     * @param  array<int, list<array{kind: string, name: string, color: string, hint: ?string}>>  $cells
     * @param  Collection<int, Carbon>  $days
     */
    private function collectAbsenceCells(
        array &$cells,
        Worker $worker,
        ?CrewMember $member,
        string $name,
        Collection $days,
    ): void {
        foreach ($days->values() as $index => $day) {
            $absence = $this->availability->absenceOn($worker, $day, $member);
            if ($absence === null || ! empty($absence['structural']) || ! is_array($absence)) {
                continue;
            }

            $label = $absence['label'] === 'Vrij op vrijdag' ? 'Vrij' : $absence['label'];
            $cells[$index][] = [
                'kind' => $label,
                'name' => $name,
                'color' => self::AWAY_COLORS[$absence['key']] ?? self::AWAY_COLORS['overig'],
                'hint' => $absence['full'] ? null : $absence['hint'],
            ];
        }
    }

    /**
     * @param  list<array{kind: string, name: string, color: string, hint: ?string}>  $lines
     * @return list<array{label: string, color: string, text: string, time_label: ?string}>
     */
    private function mergeAbsenceLines(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $key = mb_strtolower($line['kind']).'|'.(string) $line['hint'];
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'kind' => $line['kind'],
                    'names' => [],
                    'color' => $line['color'],
                    'hint' => $line['hint'],
                ];
            }
            if (! in_array($line['name'], $groups[$key]['names'], true)) {
                $groups[$key]['names'][] = $line['name'];
            }
        }

        $merged = [];
        foreach ($groups as $group) {
            $merged[] = [
                'label' => mb_strtoupper($group['kind']).' · '.implode(' · ', $group['names']),
                'color' => $group['color'],
                'text' => '#163a5f',
                'time_label' => $group['hint'],
            ];
        }

        return $merged;
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
     * @param  list<array<string, mixed>>  $bars
     * @return list<array<string, mixed>>
     */
    private function packBars(array $bars): array
    {
        usort($bars, function (array $left, array $right): int {
            $leftStart = $this->barStartKey($left);
            $rightStart = $this->barStartKey($right);
            if ($leftStart === $rightStart) {
                return $this->barEndKey($left) <=> $this->barEndKey($right);
            }

            return $leftStart <=> $rightStart;
        });

        $laneEnds = [];
        foreach ($bars as &$bar) {
            $start = $this->barStartKey($bar);
            $lane = count($laneEnds);
            foreach ($laneEnds as $index => $end) {
                if ($end <= $start + 0.001) {
                    $lane = $index;
                    break;
                }
            }
            $laneEnds[$lane] = $this->barEndKey($bar);
            $bar['stack'] = $lane;
        }
        unset($bar);

        return array_values($bars);
    }

    /**
     * @param  array<string, mixed>  $bar
     */
    private function barStartKey(array $bar): float
    {
        return (float) ($bar['bar']['start'] ?? 0) + (float) ($bar['bar']['start_offset'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $bar
     */
    private function barEndKey(array $bar): float
    {
        $start = (float) ($bar['bar']['start'] ?? 0);
        $span = (float) ($bar['bar']['span'] ?? 1);

        return ($start + $span - 1.0) + (float) ($bar['bar']['end_offset'] ?? 1);
    }

    /**
     * @param  Collection<int, Carbon>  $days
     */
    private function coversWeek(WorkerAssignment $assignment, Collection $days): bool
    {
        foreach ($days as $day) {
            if ($assignment->intervalOnDate($day) !== null) {
                return true;
            }
        }

        return false;
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

    private function rememberUnresolved(WorkerAssignment $assignment, string $reason): void
    {
        $id = (int) $assignment->id;
        if (isset($this->unresolved[$id])) {
            return;
        }

        $this->unresolved[$id] = [
            'assignment_id' => $id,
            'project_id' => $assignment->project_id,
            'project' => $assignment->project?->displayTitle(),
            'worker_id' => $assignment->worker_id,
            'worker' => $assignment->worker?->planName(),
            'start' => $assignment->start_date?->toDateString(),
            'end' => $assignment->end_date?->toDateString(),
            'reason' => $reason,
        ];
    }

    private function compactTitle(Project $project): string
    {
        if ($project->isSmallWork()) {
            return $this->shortenOverviewText((string) $project->name);
        }

        return $this->shortenOverviewText($project->displayTitle());
    }

    private function shortenOverviewText(string $text): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($clean === '') {
            return '';
        }

        return Str::limit($clean, 80);
    }

    private function externalName(Worker $worker): string
    {
        $company = trim((string) $worker->company);
        if ($company !== '') {
            return $company;
        }

        return $this->personShortName($worker->planName()) ?? trim($worker->planName());
    }

    private function eigenPersonalName(Worker $worker): ?string
    {
        return $this->personShortName($worker->displayName());
    }

    private function personShortName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || $this->looksLikeTeamLabel($name)) {
            return null;
        }

        $short = $this->shortName($name);
        if ($short === '' || $this->looksLikeTeamLabel($short)) {
            return null;
        }

        return $short;
    }

    private function looksLikeTeamLabel(string $name): bool
    {
        return preg_match('/^team(\s|\d|$)/iu', trim($name)) === 1;
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
