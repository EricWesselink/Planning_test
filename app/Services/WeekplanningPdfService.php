<?php

namespace App\Services;

use App\Enums\AvailabilitySlot;
use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WeekplanningPdfService
{
    /**
     * @var list<string>
     */
    private const PROJECT_COLORS = [
        '#dcefe4',
        '#d7e6f5',
        '#e8e0f4',
        '#f3edd4',
        '#d4e8ea',
        '#e5f0d8',
    ];

    /**
     * @var list<string>
     */
    private const SERVICE_COLORS = [
        '#f8d4d4',
        '#f3c9c0',
    ];

    /**
     * @var list<string>
     */
    private const EXTRA_COLORS = [
        '#fde4cc',
        '#f8d9b8',
    ];

    /**
     * @var array<string, string>
     */
    private const AWAY_COLORS = [
        'vakantie' => '#dbe4ee',
        'ziek' => '#efe4dc',
        'verlof' => '#e8e4f0',
        'vrij' => '#ececec',
        'adv' => '#ececec',
        'cursus' => '#e8e4f0',
        'overig' => '#ececec',
    ];

    /**
     * @var array<string, string>
     */
    private array $projectColors = [];

    private ?string $niconLogo = null;

    private ?string $shopLogo = null;

    private string $companyName = '';

    private string $shopName = '';

    public function __construct(
        private PlanningBoardService $board,
        private WorkerAvailabilityService $availability,
    ) {}

    /**
     * @return array{
     *     filename: string,
     *     weekNumber: int,
     *     weekYear: int,
     *     weekLabel: string,
     *     generatedOn: string,
     *     logo: ?string,
     *     shopLogo: ?string,
     *     companyName: string,
     *     shopName: string,
     *     heading: string,
     *     days: list<array{key: string, name: string, date: string}>,
     *     people: list<array<string, mixed>>
     * }
     */
    public function build(Request $request): array
    {
        $this->projectColors = [];
        $this->companyName = (string) config('company.name');
        $this->shopName = (string) config('company.shop_name');
        $this->niconLogo = $this->imagePath((string) config('company.logo'));
        $this->shopLogo = $this->imagePath((string) config('company.shop_logo'));
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

        $assignments = WorkerAssignment::query()
            ->with([
                'worker.crewPeople',
                'worker.availabilities',
                'workItem',
                'project.customer',
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

        $selectedKeys = $this->selectedGroupKeys($request);
        $people = $this->people($assignments, $days, $selectedKeys);
        $singleTeam = is_array($selectedKeys) && count($selectedKeys) === 1
            ? ($people[0]['name'] ?? null)
            : null;
        $heading = is_string($singleTeam) && $singleTeam !== ''
            ? 'Weekplanning – '.$singleTeam
            : 'Weekplanning vakmannen';

        return [
            'filename' => $this->filename($weekNumber, $weekYear, $singleTeam),
            'weekNumber' => $weekNumber,
            'weekYear' => $weekYear,
            'weekLabel' => 'Week '.$weekNumber.' | '.$monday->translatedFormat('j F').' – '.$saturday->translatedFormat('j F Y'),
            'generatedOn' => now()->format('d-m-Y'),
            'heading' => $heading,
            'logo' => $this->niconLogo,
            'shopLogo' => $this->shopLogo,
            'companyName' => $this->companyName,
            'shopName' => $this->shopName,
            'days' => $days->map(fn (Carbon $day): array => [
                'key' => $day->toDateString(),
                'name' => Str::ucfirst($day->translatedFormat('l')),
                'date' => $day->translatedFormat('j F'),
            ])->values()->all(),
            'people' => $people,
        ];
    }

    /**
     * @return list<array{key: string, name: string}>
     */
    public function scheduledGroups(Request $request): array
    {
        $weekStart = $this->board->weekStart(
            $request->string('week')->toString() ?: null,
            $this->optionalInt($request->input('week_nr')),
            $this->optionalInt($request->input('year')),
        );
        $days = $this->board->weekDays($weekStart, 1);
        $assignments = WorkerAssignment::query()
            ->with(['worker'])
            ->whereHas('project', function ($query) use ($request): void {
                $query->active()->accessibleBy($request->user());
            })
            ->whereDate('end_date', '>=', $days->first())
            ->whereDate('start_date', '<=', $days->last())
            ->when(
                $request->user()?->scheduledWorkerId(),
                fn ($query, int $workerId) => $query->where('worker_id', $workerId),
            )
            ->get();

        $groups = [];
        foreach ($assignments as $assignment) {
            if ($assignment->worker === null) {
                continue;
            }
            $key = $this->groupKey($assignment);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'name' => $this->groupLabel($assignment),
                ];
            }
        }

        $list = array_values($groups);
        usort($list, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $list;
    }

    public function filename(int $weekNumber, int $weekYear, ?string $teamName = null): string
    {
        $safe = preg_replace('/[^A-Za-z0-9]+/', '-', trim((string) $teamName)) ?? '';
        $safe = trim($safe, '-');
        if ($safe !== '') {
            return 'weekplanning-'.$safe.'-week-'.$weekNumber.'-'.$weekYear.'.pdf';
        }

        return 'weekplanning-week-'.$weekNumber.'-'.$weekYear.'.pdf';
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, Carbon>  $days
     * @param  list<string>|null  $selectedKeys
     * @return list<array<string, mixed>>
     */
    private function people(Collection $assignments, Collection $days, ?array $selectedKeys): array
    {
        $rows = [];

        foreach ($assignments as $assignment) {
            if ($assignment->worker === null || $assignment->project === null) {
                continue;
            }

            $key = $this->groupKey($assignment);
            if ($selectedKeys !== null && ! in_array($key, $selectedKeys, true)) {
                continue;
            }

            $members = $assignment->crewMembers;
            if ($members->isEmpty()) {
                $this->addBlocks($rows, $assignment, null, $days, $key);

                continue;
            }

            foreach ($members as $member) {
                $this->addBlocks($rows, $assignment, $member, $days, $key);
            }
        }

        $this->addAbsences($rows, $days);

        $people = [];
        foreach ($rows as $row) {
            if ($row['has_work'] !== true) {
                continue;
            }

            $people[] = $this->finalizeGroup($row);
        }

        usort($people, function (array $left, array $right): int {
            $name = strcasecmp((string) $left['name'], (string) $right['name']);
            if ($name !== 0) {
                return $name;
            }

            return strcmp((string) $left['key'], (string) $right['key']);
        });

        return array_values($people);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  Collection<int, Carbon>  $days
     */
    private function addBlocks(array &$rows, WorkerAssignment $assignment, ?CrewMember $member, Collection $days, string $key): void
    {
        $worker = $assignment->worker;
        $personName = $member?->label() ?? $worker->planName();

        if (! isset($rows[$key])) {
            $rows[$key] = [
                'key' => $key,
                'name' => $this->groupLabel($assignment),
                'worker' => $worker,
                'names' => [],
                'days' => $days->mapWithKeys(fn (Carbon $day): array => [$day->toDateString() => []])->all(),
                'has_work' => false,
            ];
        }

        if (! in_array($personName, $rows[$key]['names'], true)) {
            $rows[$key]['names'][] = $personName;
        }

        foreach ($days as $day) {
            $interval = $assignment->intervalOnDateForMember($day, $member);
            if ($interval === null) {
                continue;
            }

            $hours = max(0.0, ($interval[1]->timestamp - $interval[0]->timestamp) / 3600);
            if ($hours < 0.05) {
                continue;
            }

            $block = $this->block($assignment, $interval[0], $interval[1], $hours, $personName);
            $date = $day->toDateString();
            $rows[$key]['days'][$date][] = $block;
            $rows[$key]['has_work'] = true;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  Collection<int, Carbon>  $days
     */
    private function addAbsences(array &$rows, Collection $days): void
    {
        foreach ($rows as &$row) {
            $worker = $row['worker'] ?? null;
            if (! $worker instanceof Worker) {
                continue;
            }

            $crew = $worker->activeCrewPeople();
            if ($crew->isEmpty()) {
                $this->addPersonAbsences($row, $worker, null, $worker->planName(), $days);

                continue;
            }

            foreach ($crew as $member) {
                $member->setRelation('worker', $worker);
                $name = trim((string) $member->name) !== ''
                    ? $member->label()
                    : $worker->planName();
                $this->addPersonAbsences($row, $worker, $member, $name, $days);
            }
        }
        unset($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Carbon>  $days
     */
    private function addPersonAbsences(array &$row, Worker $worker, ?CrewMember $member, string $personName, Collection $days): void
    {
        foreach ($days as $day) {
            $absence = $this->availability->absenceOn($worker, $day, $member);
            if ($absence === null) {
                continue;
            }
            if ($absence['structural'] && (int) $day->dayOfWeekIso >= 6) {
                continue;
            }

            if (! in_array($personName, $row['names'], true)) {
                $row['names'][] = $personName;
            }

            $row['days'][$day->toDateString()][] = $this->absenceBlock($worker, $absence, $day, $personName);
        }
    }

    /**
     * @param  array{key: string, label: string, short: string, hours: float, full: bool, slot: AvailabilitySlot, hint: ?string, structural: bool}  $absence
     * @return array<string, mixed>
     */
    private function absenceBlock(Worker $worker, array $absence, CarbonInterface $day, string $personName): array
    {
        [$start, $end] = $this->absenceInterval($absence, $day);
        $hours = max(0.0, ($end->timestamp - $start->timestamp) / 3600);
        $title = $this->awayTitle($absence);
        $hoursCaption = $this->hoursCaption($hours, $start, $end);

        return [
            'title' => $title,
            'city' => null,
            'numbers' => null,
            'activity' => $absence['full'] ? null : $absence['hint'],
            'badge' => null,
            'source_label' => $worker->planName(),
            'source_logo' => null,
            'hours' => $hoursCaption,
            'start' => $start->format('H:i'),
            'color' => self::AWAY_COLORS[$absence['key']] ?? self::AWAY_COLORS['overig'],
            'who' => [$personName],
            'away' => true,
            'merge_key' => implode('|', [
                'away',
                $title,
                $start->format('H:i'),
                $end->format('H:i'),
                $hoursCaption,
            ]),
        ];
    }

    /**
     * @param  array{key: string, label: string, short: string, hours: float, full: bool, slot: AvailabilitySlot, hint: ?string, structural: bool}  $absence
     */
    private function awayTitle(array $absence): string
    {
        return $absence['label'] === 'Vrij op vrijdag' ? 'Vrije dag' : $absence['label'];
    }

    /**
     * @param  array{key: string, label: string, short: string, hours: float, full: bool, slot: AvailabilitySlot, hint: ?string, structural: bool}  $absence
     * @return array{0: Carbon, 1: Carbon}
     */
    private function absenceInterval(array $absence, CarbonInterface $day): array
    {
        $date = $day->toDateString();
        $minutes = (int) round(((float) $absence['hours']) * 60);
        $slot = $absence['slot'] instanceof AvailabilitySlot
            ? $absence['slot']
            : AvailabilitySlot::Full;

        if ($absence['full'] || $slot === AvailabilitySlot::Full) {
            return [
                Carbon::parse($date.' '.PlanningHours::DAY_START.':00'),
                Carbon::parse($date.' '.PlanningHours::DAY_END.':00'),
            ];
        }

        if ($slot === AvailabilitySlot::Afternoon) {
            $end = Carbon::parse($date.' '.PlanningHours::DAY_END.':00');

            return [$end->copy()->subMinutes($minutes), $end];
        }

        $start = Carbon::parse($date.' '.PlanningHours::DAY_START.':00');

        return [$start, $start->copy()->addMinutes($minutes)];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function finalizeGroup(array $row): array
    {
        $names = $row['names'];
        usort($names, static fn (string $left, string $right): int => strcasecmp($left, $right));
        $showWho = count($names) > 1;
        $days = [];
        foreach ($row['days'] as $date => $blocks) {
            $clean = [];
            foreach ($this->mergeBlocks($blocks) as $block) {
                $who = $block['who'] ?? [];
                usort($who, static fn (string $left, string $right): int => strcasecmp($left, $right));
                $block['who'] = $showWho
                    ? implode(' · ', array_map(fn (string $name): string => $this->shortPersonName($name), $who))
                    : null;
                unset($block['merge_key']);
                $clean[] = $block;
            }
            $days[$date] = $clean;
        }

        $peopleLabel = implode(' · ', array_map(fn (string $name): string => $this->shortPersonName($name), $names));
        if ($peopleLabel === '' || strcasecmp($peopleLabel, $row['name']) === 0) {
            $peopleLabel = null;
        }

        return $this->finalizeRow(
            $row['key'],
            $row['name'],
            $names,
            $peopleLabel,
            $this->sortDayBlocks($days),
        );
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, list<array<string, mixed>>>  $days
     * @return array<string, mixed>
     */
    private function finalizeRow(string $key, string $name, array $names, ?string $peopleLabel, array $days): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'names' => $names,
            'people_label' => $peopleLabel,
            'team' => $peopleLabel,
            'days' => $days,
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $days
     * @return array<string, list<array<string, mixed>>>
     */
    private function sortDayBlocks(array $days): array
    {
        foreach ($days as $date => $blocks) {
            usort($blocks, function (array $left, array $right): int {
                $rank = $this->blockSortRank($left) <=> $this->blockSortRank($right);
                if ($rank !== 0) {
                    return $rank;
                }

                $time = strcmp((string) ($left['start'] ?? ''), (string) ($right['start'] ?? ''));
                if ($time !== 0) {
                    return $time;
                }

                return strcasecmp((string) $left['title'], (string) $right['title']);
            });
            $days[$date] = array_values($blocks);
        }

        return $days;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function blockSortRank(array $block): int
    {
        return ($block['title'] ?? '') === 'Vrije dag' ? 0 : 1;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function mergeBlocks(array $blocks): array
    {
        $merged = [];
        foreach ($blocks as $block) {
            $key = (string) $block['merge_key'];
            if (! isset($merged[$key])) {
                $merged[$key] = $block;

                continue;
            }

            $merged[$key]['who'] = array_values(array_unique([
                ...$merged[$key]['who'],
                ...$block['who'],
            ]));
        }

        return array_values($merged);
    }

    /**
     * @return array<string, mixed>
     */
    private function block(WorkerAssignment $assignment, CarbonInterface $start, CarbonInterface $end, float $hours, string $personName): array
    {
        $project = $assignment->project;
        $item = $assignment->workItem;
        $title = $project->displayTitle();
        $address = $project->nawLine();
        if ($address !== null && Str::contains(mb_strtolower($title), mb_strtolower($address))) {
            $address = null;
        }

        $activity = $this->activityLine($project, $item, $title);
        $badge = $this->badge($project, $item);
        $hoursCaption = $this->hoursCaption($hours, $start, $end);
        $winkel = $project->isWinkel();

        return [
            'title' => $title,
            'city' => $address,
            'numbers' => $this->numbersLine($project),
            'activity' => $activity,
            'badge' => $badge,
            'source_label' => $assignment->worker->planName(),
            'source_logo' => $winkel ? $this->shopLogo : $this->niconLogo,
            'hours' => $hoursCaption,
            'start' => $start->format('H:i'),
            'color' => $this->colorFor($project, $badge),
            'who' => [$personName],
            'merge_key' => implode('|', [
                (string) $project->id,
                (string) ($item?->id ?? 0),
                $start->format('H:i'),
                $end->format('H:i'),
                $hoursCaption,
            ]),
        ];
    }

    private function groupKey(WorkerAssignment $assignment): string
    {
        return 'worker-'.$assignment->worker_id;
    }

    private function groupLabel(WorkerAssignment $assignment): string
    {
        return $assignment->worker->planName();
    }

    /**
     * @return list<string>|null
     */
    private function selectedGroupKeys(Request $request): ?array
    {
        if ($request->boolean('all')) {
            return null;
        }

        $teams = $request->input('teams', []);
        if (! is_array($teams) || $teams === []) {
            return null;
        }

        $keys = [];
        foreach ($teams as $team) {
            if (is_string($team) && preg_match('/^(team|worker)-\d+$/', $team) === 1) {
                $keys[] = $team;
            }
        }

        return $keys === [] ? null : array_values(array_unique($keys));
    }

    private function shortPersonName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 2) {
            return implode(' ', $parts) ?: $name;
        }

        $last = $parts[array_key_last($parts)];

        return $parts[0].' '.mb_strtoupper(mb_substr($last, 0, 1)).'.';
    }

    private function activityLine(Project $project, ?WorkItem $item, string $title): ?string
    {
        if ($item === null) {
            return $project->isWinkel() ? $project->shopWorkLine() : null;
        }

        $label = $item->isExtraWork()
            ? trim((string) $item->name)
            : $item->planningTitle();

        $product = $item->productLabel();
        if ($product !== null && $product !== '' && ! Str::contains(mb_strtolower($label), mb_strtolower($product))) {
            $label = $label.' / '.$product;
        }

        if ($label === '' || strcasecmp($label, $title) === 0) {
            return null;
        }

        return $label;
    }

    private function badge(Project $project, ?WorkItem $item): ?string
    {
        if ($item?->isExtraWork() || $item?->small_work_type === SmallWorkType::Extra) {
            return SmallWorkType::Extra->badge();
        }

        if ($project->kind === ProjectKind::Service || $item?->small_work_type === SmallWorkType::Service) {
            return SmallWorkType::Service->badge();
        }

        return null;
    }

    private function numbersLine(Project $project): ?string
    {
        $code = $project->workCode();
        if ($code !== null && $code !== '') {
            return $code;
        }

        $number = $project->workNumber();

        return $number !== '' ? $number : null;
    }

    private function hoursCaption(float $hours, CarbonInterface $start, CarbonInterface $end): string
    {
        if ($hours >= PlanningHours::WORKDAY_HOURS - 0.25) {
            return 'Hele dag';
        }

        $label = fmod($hours, 1.0) === 0.0
            ? (string) (int) $hours
            : rtrim(rtrim(number_format($hours, 1, ',', ''), '0'), ',');

        return $label.' uur · '.$start->format('H:i').'–'.$end->format('H:i');
    }

    private function colorFor(Project $project, ?string $badge): string
    {
        $key = (string) $project->id;

        if (isset($this->projectColors[$key])) {
            return $this->projectColors[$key];
        }

        $palette = match ($badge) {
            SmallWorkType::Service->badge() => self::SERVICE_COLORS,
            SmallWorkType::Extra->badge() => self::EXTRA_COLORS,
            default => self::PROJECT_COLORS,
        };
        $index = count(array_filter(
            $this->projectColors,
            fn (string $color): bool => in_array($color, $palette, true),
        ));

        return $this->projectColors[$key] = $palette[$index % count($palette)];
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
        return is_numeric($value) ? (int) $value : null;
    }
}
