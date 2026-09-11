<?php

namespace App\Services;

use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Enums\WorkUnit;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\Team;
use App\Models\Worker;
use App\Models\WorkerAssignment;
use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use App\Support\PlanningWeek;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InternalPlanningExcelService
{
    /**
     * @var list<string>
     */
    private const DAY_LABELS = ['ma', 'di', 'wo', 'do', 'vr'];

    private const FONT = 'Aptos Narrow';

    private const RED = 'C00000';

    private const GRAY = 'D8D8D8';

    private const WHITE = 'FFFFFF';

    private const LEFT_COLUMNS = 10;

    private const WEEK_BLOCK = 6;

    private const TEAM_TOTAL_FILL = 'BDD7EE';

    private const WORK_TOTAL_FILL = 'F8CBAD';

    private const UNDER_GREEN = '548235';

    private const HOURS_FORMAT = '0.##;;';

    private const DIFF_FORMAT = '+0.##;-0.##;0';

    private const DASH = '—';

    public function __construct(private PlanningBoardService $board) {}

    public function download(Request $request): BinaryFileResponse
    {
        [$filename, $spreadsheet] = $this->make($request);

        $path = tempnam(sys_get_temp_dir(), 'nicon-planning-');
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array{0: string, 1: Spreadsheet}
     */
    public function make(Request $request): array
    {
        $year = $this->selectedYear($request);
        $weeks = $this->yearWeeks($year);
        $assignments = $this->assignments($request, $weeks);
        $progress = $this->progress($request, $weeks);
        $rows = $this->sheetRows($assignments, $progress, $weeks);

        return ['Nicon-planning-'.$year.'.xlsx', $this->workbook($year, $weeks, $rows)];
    }

    public function selectedYear(Request $request): int
    {
        $year = $this->optionalInt($request->input('year'));
        if ($year !== null && $year >= PlanningWeek::MIN_YEAR && $year <= PlanningWeek::MAX_YEAR) {
            return $year;
        }

        return (int) $this->board->weekStart(
            $request->string('week')->toString() ?: null,
            $this->optionalInt($request->input('week_nr')),
        )->isoWeekYear();
    }

    /**
     * @return list<array{number: int, year: int, days: list<Carbon>}>
     */
    private function yearWeeks(int $year): array
    {
        $weeks = [];
        for ($number = 1; $number <= $this->weeksInIsoYear($year); $number++) {
            $monday = Carbon::now()->setISODate($year, $number, Carbon::MONDAY)->startOfDay();
            $days = [];
            for ($offset = 0; $offset < 5; $offset++) {
                $days[] = $monday->copy()->addDays($offset);
            }
            $weeks[] = [
                'number' => (int) $monday->isoWeek(),
                'year' => (int) $monday->isoWeekYear(),
                'days' => $days,
            ];
        }

        return $weeks;
    }

    private function weeksInIsoYear(int $year): int
    {
        $week53 = Carbon::now()->setISODate($year, 53, Carbon::MONDAY);

        return (int) $week53->isoWeekYear() === $year ? 53 : 52;
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @return Collection<int, WorkerAssignment>
     */
    private function assignments(Request $request, array $weeks): Collection
    {
        [$first, $last] = $this->range($weeks);

        return WorkerAssignment::query()
            ->with([
                'worker.teams',
                'team',
                'workItem',
                'crewMembers',
                'project.customer',
                'project.workItems',
                'project.workActivities.category',
            ])
            ->whereHas('project', function ($query) use ($request): void {
                $query->active()->accessibleBy($request->user());
            })
            ->whereDate('end_date', '>=', $first)
            ->whereDate('start_date', '<=', $last)
            ->when(
                $request->user()?->scheduledWorkerId(),
                fn ($query, int $workerId) => $query->where('worker_id', $workerId),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @return Collection<int, WorkProgressEntry>
     */
    private function progress(Request $request, array $weeks): Collection
    {
        [$first, $last] = $this->range($weeks);

        return WorkProgressEntry::query()
            ->with([
                'worker.teams',
                'project.customer',
                'project.workItems',
                'project.workActivities.category',
            ])
            ->where('worked_hours', '>', 0)
            ->whereHas('project', function ($query) use ($request): void {
                $query->active()->accessibleBy($request->user());
            })
            ->whereDate('date', '>=', $first)
            ->whereDate('date', '<=', $last)
            ->when(
                $request->user()?->scheduledWorkerId(),
                fn ($query, int $workerId) => $query->where('worker_id', $workerId),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(array $weeks): array
    {
        return [$weeks[0]['days'][0], $weeks[array_key_last($weeks)]['days'][4]];
    }

    /**
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, WorkProgressEntry>  $progress
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @return list<array<string, mixed>>
     */
    private function sheetRows(Collection $assignments, Collection $progress, array $weeks): array
    {
        $projects = [];

        foreach ($assignments as $assignment) {
            $project = $assignment->project;
            if ($project === null || $assignment->worker === null) {
                continue;
            }

            $projectId = (int) $project->id;
            $this->ensureProject($projects, $project);
            $group = $this->groupFor($assignment);
            $teamKey = $group['key'];

            foreach ($this->peopleOnAssignment($assignment) as $person) {
                $this->ensurePerson($projects, $projectId, $teamKey, $group['name'], $person);
                foreach ($weeks as $week) {
                    foreach ($week['days'] as $day) {
                        if (! $assignment->coversDate($day)) {
                            continue;
                        }
                        $hours = $person['hours_for']($day);
                        if ($hours < 0.05) {
                            continue;
                        }
                        $date = $day->toDateString();
                        $projects[$projectId]['teams'][$teamKey]['people'][$person['key']]['days'][$date] =
                            ($projects[$projectId]['teams'][$teamKey]['people'][$person['key']]['days'][$date] ?? 0.0) + $hours;
                    }
                }
            }
        }

        $this->applyActualHours($projects, $progress);
        $this->pruneEmptyPeople($projects);

        $rows = [];
        foreach ($this->sortedProjects($projects) as $entry) {
            $teams = $this->sortedTeams($entry['teams']);
            $namedTeams = array_values(array_filter(
                $teams,
                fn (array $team): bool => $team['name'] !== null && $team['name'] !== '',
            ));
            $showTeamTotals = count($namedTeams) > 1;
            $source = $this->sourceLabel($entry['project']);
            $rows[] = $this->projectHeader($entry['project'], '', $source);

            foreach ($teams as $team) {
                $named = $team['name'] !== null && $team['name'] !== '';
                $people = $this->sortedPeople($team['people']);
                foreach ($people as $person) {
                    $rows[] = $this->hourRow(
                        'person',
                        $this->personLabel($named ? (string) $team['name'] : null, $person['name']),
                        $person['days'],
                        source: $source,
                    );
                }

                if ($named && $showTeamTotals) {
                    $rows[] = $this->hourRow('team_total', 'Totaal '.$team['name'], [], source: $source);
                }
            }

            $rows[] = $this->hourRow(
                'work_total',
                'Totaal werk',
                [],
                $this->budgetHours($entry['project']),
                $this->plannedHoursForTeams($teams),
                $source,
            );
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     */
    private function ensureProject(array &$projects, Project $project): void
    {
        $id = (int) $project->id;
        if (! isset($projects[$id])) {
            $projects[$id] = [
                'project' => $project,
                'teams' => [],
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @param  array{key: string, name: string, worker_id: int, hours_for?: callable}  $person
     */
    private function ensurePerson(array &$projects, int $projectId, string $teamKey, ?string $groupName, array $person): void
    {
        if (! isset($projects[$projectId]['teams'][$teamKey])) {
            $projects[$projectId]['teams'][$teamKey] = [
                'name' => $groupName,
                'sort' => mb_strtolower(trim((string) ($groupName ?? 'ÿ'))),
                'people' => [],
            ];
        }

        if (! isset($projects[$projectId]['teams'][$teamKey]['people'][$person['key']])) {
            $projects[$projectId]['teams'][$teamKey]['people'][$person['key']] = [
                'name' => $person['name'],
                'worker_id' => $person['worker_id'],
                'sort' => mb_strtolower($person['name']),
                'days' => [],
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @param  Collection<int, WorkProgressEntry>  $progress
     */
    private function applyActualHours(array &$projects, Collection $progress): void
    {
        $today = now()->startOfDay();
        $actual = [];
        foreach ($progress as $entry) {
            if ($entry->project === null || $entry->date === null || $entry->worker_id === null) {
                continue;
            }
            if (! $entry->date->copy()->startOfDay()->lt($today)) {
                continue;
            }

            $this->ensureProject($projects, $entry->project);
            $projectId = (int) $entry->project->id;
            $workerId = (int) $entry->worker_id;
            $date = $entry->date->toDateString();
            $actual[$projectId][$workerId][$date] = ($actual[$projectId][$workerId][$date] ?? 0.0) + (float) $entry->worked_hours;
        }

        foreach ($actual as $projectId => $workers) {
            foreach ($workers as $workerId => $days) {
                $existingKey = $this->teamKeyForWorker($projects[$projectId]['teams'] ?? [], $workerId);
                if ($existingKey !== null) {
                    $teamKey = $existingKey;
                    $groupName = $projects[$projectId]['teams'][$teamKey]['name'] ?? null;
                } else {
                    $group = $this->groupFromWorker($this->progressWorker($progress, $projectId, $workerId));
                    $teamKey = $group['key'];
                    $groupName = $group['name'];
                }
                $person = [
                    'key' => 'worker-'.$workerId,
                    'name' => $this->workerName($projects, $projectId, $workerId, $progress),
                    'worker_id' => $workerId,
                ];
                $this->ensurePerson($projects, $projectId, $teamKey, $groupName, $person);

                foreach ($days as $date => $hours) {
                    foreach ($projects[$projectId]['teams'] as $key => $group) {
                        foreach ($group['people'] as $personKey => $member) {
                            if ((int) $member['worker_id'] === $workerId) {
                                unset($projects[$projectId]['teams'][$key]['people'][$personKey]['days'][$date]);
                            }
                        }
                    }
                    $projects[$projectId]['teams'][$teamKey]['people'][$person['key']]['days'][$date] = $hours;
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     */
    private function pruneEmptyPeople(array &$projects): void
    {
        foreach ($projects as $projectId => $entry) {
            foreach ($entry['teams'] as $teamKey => $team) {
                foreach ($team['people'] as $personKey => $person) {
                    if ($person['days'] === []) {
                        unset($projects[$projectId]['teams'][$teamKey]['people'][$personKey]);
                    }
                }
                if ($projects[$projectId]['teams'][$teamKey]['people'] === []) {
                    unset($projects[$projectId]['teams'][$teamKey]);
                }
            }
            if ($projects[$projectId]['teams'] === []) {
                unset($projects[$projectId]);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $teams
     */
    private function teamKeyForWorker(array $teams, int $workerId): ?string
    {
        foreach ($teams as $key => $group) {
            foreach ($group['people'] as $member) {
                if ((int) $member['worker_id'] === $workerId) {
                    return (string) $key;
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, WorkProgressEntry>  $progress
     */
    private function progressWorker(Collection $progress, int $projectId, int $workerId): ?Worker
    {
        $entry = $progress->first(
            fn (WorkProgressEntry $row): bool => (int) $row->project_id === $projectId && (int) $row->worker_id === $workerId
        );

        return $entry?->worker;
    }

    /**
     * @return array{key: string, name: ?string}
     */
    private function groupFromWorker(?Worker $worker): array
    {
        $team = $worker?->teams
            ->sortBy(fn (Team $team): string => mb_strtolower((string) $team->name))
            ->first(fn (Team $team): bool => trim((string) $team->name) !== '');
        if ($team instanceof Team) {
            return ['key' => $this->teamKey($team), 'name' => trim((string) $team->name)];
        }

        $company = trim((string) ($worker?->company ?? ''));
        if ($worker?->employment_type?->isExternal() && $company !== '') {
            return ['key' => 'company-'.mb_strtolower($company), 'name' => $company];
        }

        return ['key' => 'none', 'name' => null];
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @param  Collection<int, WorkProgressEntry>  $progress
     */
    private function workerName(array $projects, int $projectId, int $workerId, Collection $progress): string
    {
        foreach ($projects[$projectId]['teams'] as $group) {
            $person = $group['people']['worker-'.$workerId] ?? null;
            if (is_array($person)) {
                return (string) $person['name'];
            }
        }

        $entry = $progress->first(
            fn (WorkProgressEntry $row): bool => (int) $row->worker_id === $workerId
        );

        return $this->workerPersonName($entry?->worker);
    }

    /**
     * @return array{key: string, name: ?string}
     */
    private function groupFor(WorkerAssignment $assignment): array
    {
        $team = $this->teamFor($assignment);
        if ($team instanceof Team) {
            return ['key' => $this->teamKey($team), 'name' => trim((string) $team->name)];
        }

        $fromWorker = $this->groupFromWorker($assignment->worker);
        if ($fromWorker['name'] !== null) {
            return $fromWorker;
        }

        if ($assignment->crewMembers->isNotEmpty()) {
            $name = trim((string) ($assignment->worker?->planName() ?? ''));
            if ($name !== '') {
                return [
                    'key' => 'worker-'.(int) $assignment->worker_id,
                    'name' => $name,
                ];
            }
        }

        return ['key' => 'none', 'name' => null];
    }

    private function teamFor(WorkerAssignment $assignment): ?Team
    {
        if ($assignment->team instanceof Team && trim((string) $assignment->team->name) !== '') {
            return $assignment->team;
        }

        return $assignment->worker?->teams
            ->sortBy(fn (Team $team): string => mb_strtolower((string) $team->name))
            ->first(fn (Team $team): bool => trim((string) $team->name) !== '');
    }

    private function teamKey(?Team $team): string
    {
        if ($team === null || (int) ($team->id ?? 0) < 1) {
            $name = trim((string) ($team?->name ?? ''));

            return $name !== '' ? 'name-'.mb_strtolower($name) : 'none';
        }

        return 'team-'.$team->id;
    }

    private function workerPersonName(?Worker $worker): string
    {
        if ($worker === null) {
            return 'Onbekend';
        }

        $name = $worker->planName();
        $company = trim((string) $worker->company);
        $contact = trim((string) $worker->contact_name);
        if ($company !== '' && $contact !== '' && mb_strtolower($name) === mb_strtolower($company)) {
            return $contact;
        }

        return $name;
    }

    private function personLabel(?string $group, string $personName): string
    {
        $personName = trim($personName);
        if ($personName === '') {
            $personName = 'Onbekend';
        }

        $group = trim((string) $group);
        if ($group === '') {
            return $personName;
        }

        return $group.' ('.$this->shortPersonName($personName).')';
    }

    private function shortPersonName(string $name): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($normalized === '') {
            return 'Onbekend';
        }

        $parts = explode(' ', $normalized);
        $first = $parts[0];
        if (count($parts) < 2) {
            return $first;
        }

        $last = ltrim($parts[array_key_last($parts)], '.');
        $letter = mb_strtoupper(mb_substr($last, 0, 1));
        if ($letter === '') {
            return $first;
        }

        return $first.' '.$letter.'.';
    }

    /**
     * @return list<array{key: string, name: string, worker_id: int, hours_for: callable}>
     */
    private function peopleOnAssignment(WorkerAssignment $assignment): array
    {
        $workerId = (int) $assignment->worker_id;
        $members = $assignment->crewMembers;
        if ($members->isNotEmpty()) {
            $people = [];
            foreach ($members as $member) {
                $people[] = [
                    'key' => 'crew-'.$member->id,
                    'name' => $member->label(),
                    'worker_id' => $workerId,
                    'hours_for' => fn (CarbonInterface $day): float => $this->memberHoursOnDate($assignment, $day, $member),
                ];
            }

            return $people;
        }

        return [[
            'key' => 'worker-'.$workerId,
            'name' => $this->workerPersonName($assignment->worker),
            'worker_id' => $workerId,
            'hours_for' => function (CarbonInterface $day) use ($assignment): float {
                $hours = $assignment->hoursOnDate($day);
                if ($hours < 0.05) {
                    return 0.0;
                }

                return $hours * $assignment->peopleCount();
            },
        ]];
    }

    private function memberHoursOnDate(WorkerAssignment $assignment, CarbonInterface $day, CrewMember $member): float
    {
        $interval = $assignment->intervalOnDateForMember($day, $member);
        if ($interval === null) {
            return 0.0;
        }

        return max(0.0, ($interval[1]->timestamp - $interval[0]->timestamp) / 3600);
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     * @return list<array<string, mixed>>
     */
    private function sortedProjects(array $projects): array
    {
        $rows = array_values($projects);
        usort($rows, function (array $left, array $right): int {
            $customer = strcasecmp(
                (string) ($left['project']->customer?->name ?? ''),
                (string) ($right['project']->customer?->name ?? ''),
            );
            if ($customer !== 0) {
                return $customer;
            }

            $name = strcasecmp($this->projectName($left['project']), $this->projectName($right['project']));
            if ($name !== 0) {
                return $name;
            }

            return (int) $left['project']->id <=> (int) $right['project']->id;
        });

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $teams
     * @return list<array<string, mixed>>
     */
    private function sortedTeams(array $teams): array
    {
        $rows = array_values($teams);
        usort($rows, fn (array $left, array $right): int => strcasecmp((string) $left['sort'], (string) $right['sort']));

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private function sortedPeople(array $people): array
    {
        $rows = array_values($people);
        usort($rows, fn (array $left, array $right): int => strcasecmp((string) $left['sort'], (string) $right['sort']));

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectHeader(Project $project, string $label, string $source): array
    {
        return [
            'kind' => 'project',
            'source' => $source,
            'customer' => trim((string) ($project->customer?->name ?? '')),
            'name' => $this->projectName($project),
            'city' => trim((string) $project->city),
            'work_type' => $this->workTypeLabel($project),
            'm2' => $this->orderedSquareMeters($project),
            'label' => $label,
            'days' => [],
        ];
    }

    /**
     * @param  array<string, float>  $days
     * @return array<string, mixed>
     */
    private function hourRow(
        string $kind,
        string $label,
        array $days,
        int|float|null $budgetHours = null,
        float $plannedHours = 0.0,
        string $source = '',
    ): array {
        return [
            'kind' => $kind,
            'source' => $source,
            'customer' => '',
            'name' => '',
            'city' => '',
            'work_type' => '',
            'm2' => null,
            'label' => $label,
            'days' => $days,
            'budget_hours' => $budgetHours,
            'planned_hours' => $plannedHours,
        ];
    }

    private function sourceLabel(Project $project): string
    {
        return $project->isWinkel()
            ? (string) config('company.shop_name')
            : (string) config('company.name');
    }

    private function budgetHours(Project $project): int|float|null
    {
        $items = $project->workItems;
        $hasBudget = $items->contains(
            fn (WorkItem $item): bool => $item->begrote_uren !== null
        );
        if (! $hasBudget) {
            return null;
        }

        $hours = round((float) $items->sum(
            fn (WorkItem $item): float => (float) ($item->begrote_uren ?? 0)
        ), 2);
        if (abs($hours - round($hours)) < 0.001) {
            return (int) round($hours);
        }

        return $hours;
    }

    /**
     * @param  list<array<string, mixed>>  $teams
     */
    private function plannedHoursForTeams(array $teams): float
    {
        $hours = 0.0;
        foreach ($teams as $team) {
            foreach ($team['people'] as $person) {
                foreach ($person['days'] as $dayHours) {
                    $hours += (float) $dayHours;
                }
            }
        }

        return $hours;
    }

    private function hoursValue(float $hours): int|float
    {
        $rounded = round($hours, 2);
        if (abs($rounded - round($rounded)) < 0.001) {
            return (int) round($rounded);
        }

        return round($rounded, 1);
    }

    private function projectName(Project $project): string
    {
        if ($project->isSmallWork()) {
            $name = trim((string) $project->name);

            return $name !== '' ? $name : $project->displayTitle();
        }

        return $project->displayTitle();
    }

    private function workTypeLabel(Project $project): string
    {
        $badge = match ($project->kind) {
            ProjectKind::Service => SmallWorkType::Service->badge(),
            ProjectKind::Klein => SmallWorkType::Klein->badge(),
            default => null,
        };

        $items = $project->workItems;
        $regular = $items->reject(fn (WorkItem $item): bool => $item->isExtraWork());
        $floorItems = $regular->reject(fn (WorkItem $item): bool => $item->packageKey() === 'ondergrond');
        $source = $floorItems->isNotEmpty() ? $floorItems : $regular;
        $types = $source
            ->map(function (WorkItem $item): string {
                $product = trim((string) $item->productLabel());

                return $product !== '' ? $product : trim($item->planningTitle());
            })
            ->filter(fn (string $label): bool => $label !== '')
            ->unique()
            ->values();

        if ($types->isEmpty()) {
            $fallback = $this->fallbackWorkType($project, $items);
            $types = $fallback['types'];
            $badge = $badge ?? $fallback['badge'];
        }

        $body = $types->implode(', ');
        if ($badge !== null && $body !== '' && ! str_contains($body, $badge)) {
            return $badge.' · '.$body;
        }

        return $badge ?? $body;
    }

    /**
     * @param  Collection<int, WorkItem>  $items
     * @return array{types: Collection<int, string>, badge: ?string}
     */
    private function fallbackWorkType(Project $project, Collection $items): array
    {
        if ($project->isWinkel()) {
            $line = trim((string) $project->shopWorkLine());
            if ($line !== '') {
                return ['types' => collect([$line]), 'badge' => null];
            }
        }

        $description = trim((string) $project->work_description);
        if ($description !== '') {
            return ['types' => collect([$description]), 'badge' => null];
        }

        if ($project->isSmallWork()) {
            $name = trim((string) $project->name);
            if ($name !== '') {
                return ['types' => collect([$name]), 'badge' => null];
            }
        }

        $extra = $items
            ->filter(fn (WorkItem $item): bool => $item->isExtraWork())
            ->map(fn (WorkItem $item): string => trim((string) $item->name))
            ->filter()
            ->unique()
            ->values();

        if ($extra->isNotEmpty()) {
            return ['types' => $extra, 'badge' => SmallWorkType::Extra->badge()];
        }

        return ['types' => collect(), 'badge' => null];
    }

    private function orderedSquareMeters(Project $project): int|float|null
    {
        $total = (float) $project->workItems
            ->filter(fn (WorkItem $item): bool => $item->unit === WorkUnit::SquareMeter && ! $item->isExtraWork())
            ->sum(fn (WorkItem $item): float => (float) $item->ordered_quantity);

        if ($total <= 0.0001) {
            return null;
        }

        return $this->hoursValue($total);
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @param  list<array<string, mixed>>  $rows
     */
    private function workbook(int $year, array $weeks, array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator((string) config('company.name'))
            ->setTitle('Planning Nicon '.$year);
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(11);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Planning Nicon');
        $this->writeSheet($sheet, $weeks, $rows);

        return $spreadsheet;
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeSheet(Worksheet $sheet, array $weeks, array $rows): void
    {
        $weekCount = count($weeks);
        $lastCol = self::LEFT_COLUMNS + ($weekCount * self::WEEK_BLOCK);
        $lastColRef = Coordinate::stringFromColumnIndex($lastCol);
        $lastRow = 2 + max(1, count($rows));

        $sheet->setCellValue('F1', 'Weeknummer');
        $sheet->setCellValue('A2', 'Bron');
        $sheet->setCellValue('B2', 'Aannemer/ klant');
        $sheet->setCellValue('C2', 'Naam project');
        $sheet->setCellValue('D2', 'Plaats');
        $sheet->setCellValue('E2', 'Soort stoffering');
        $sheet->setCellValue('F2', 'm2');
        $sheet->setCellValue('G2', 'Team / vakman');
        $sheet->setCellValue('H2', 'Begroot uren');
        $sheet->setCellValue('I2', 'Gepland uren');
        $sheet->setCellValue('J2', 'Verschil');

        $column = self::LEFT_COLUMNS + 1;
        foreach ($weeks as $week) {
            $startRef = Coordinate::stringFromColumnIndex($column);
            $endRef = Coordinate::stringFromColumnIndex($column + 5);
            $sheet->setCellValue($startRef.'1', $week['number']);
            $sheet->mergeCells($startRef.'1:'.$endRef.'1');
            foreach (self::DAY_LABELS as $index => $label) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($column + $index).'2', $label);
            }
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($column + 5).'2', 'wk');
            $column += self::WEEK_BLOCK;
        }

        $blocks = [];
        $current = $this->emptyBlock();
        $currentTeamPeople = [];

        foreach ($rows as $index => $row) {
            $excelRow = $index + 3;
            if ($row['kind'] === 'project') {
                if ($current['work_total'] !== null || $current['people'] !== []) {
                    $blocks[] = $current;
                }
                $current = $this->emptyBlock();
                $currentTeamPeople = [];
                $sheet->setCellValue('B'.$excelRow, $row['customer']);
                $sheet->setCellValue('C'.$excelRow, $row['name']);
                $sheet->setCellValue('D'.$excelRow, $row['city']);
                $sheet->setCellValue('E'.$excelRow, $row['work_type']);
                if ($row['m2'] !== null) {
                    $sheet->setCellValue('F'.$excelRow, $row['m2']);
                }
            }

            $sheet->setCellValue('A'.$excelRow, $row['source']);

            $sheet->setCellValue('G'.$excelRow, $row['label']);

            if (in_array($row['kind'], ['person', 'team_total'], true)) {
                $sheet->setCellValue('H'.$excelRow, self::DASH);
                $sheet->setCellValue('J'.$excelRow, self::DASH);
            }

            if ($row['kind'] === 'person') {
                $this->writePersonDays($sheet, $excelRow, $weeks, $row['days']);
                $this->writeWeekFormulas($sheet, $excelRow, $weekCount);
                $sheet->setCellValue('I'.$excelRow, $this->weekTotalsSumFormula($excelRow, $weekCount));
                $current['people'][] = $excelRow;
                $currentTeamPeople[] = $excelRow;
            }

            if ($row['kind'] === 'team_total') {
                $current['team_totals'][] = [
                    'row' => $excelRow,
                    'people' => $currentTeamPeople,
                ];
                $currentTeamPeople = [];
            }

            if ($row['kind'] === 'work_total') {
                $this->writeWorkBudget($sheet, $excelRow, $row['budget_hours']);
                $current['loose'] = $currentTeamPeople;
                $current['work_total'] = $excelRow;
            }
        }

        if ($current['work_total'] !== null || $current['people'] !== []) {
            $blocks[] = $current;
        }

        foreach ($blocks as $block) {
            foreach ($block['team_totals'] as $team) {
                $this->writeRolledUpHours($sheet, $team['row'], $team['people'], $weeks, $weekCount);
                $sheet->setCellValue('I'.$team['row'], $this->columnSum('I', $team['people']));
            }

            $workSources = $block['team_totals'] !== []
                ? array_values(array_merge(
                    array_map(fn (array $team): int => $team['row'], $block['team_totals']),
                    $block['loose'],
                ))
                : $block['people'];
            if ($block['work_total'] !== null) {
                $this->writeRolledUpHours($sheet, $block['work_total'], $workSources, $weeks, $weekCount);
                $sheet->setCellValue('I'.$block['work_total'], $this->columnSum('I', $block['people']));
            }
        }

        $this->addCompanyLogo($sheet);
        $this->styleSheet($sheet, $lastColRef, $lastRow, $weeks, $rows);
    }

    private function writeWorkBudget(Worksheet $sheet, int $row, int|float|null $budgetHours): void
    {
        if ($budgetHours === null) {
            $sheet->setCellValue('H'.$row, self::DASH);
            $sheet->setCellValue('J'.$row, self::DASH);

            return;
        }

        $sheet->setCellValue('H'.$row, $budgetHours);
        $sheet->setCellValue('J'.$row, '=I'.$row.'-H'.$row);
    }

    /**
     * @return array{people: list<int>, loose: list<int>, team_totals: list<array{row: int, people: list<int>}>, work_total: ?int}
     */
    private function emptyBlock(): array
    {
        return [
            'people' => [],
            'loose' => [],
            'team_totals' => [],
            'work_total' => null,
        ];
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @param  array<string, float>  $days
     */
    private function writePersonDays(Worksheet $sheet, int $row, array $weeks, array $days): void
    {
        $column = self::LEFT_COLUMNS + 1;
        foreach ($weeks as $week) {
            foreach ($week['days'] as $dayIndex => $day) {
                $hours = (float) ($days[$day->toDateString()] ?? 0);
                if ($hours >= 0.05) {
                    $sheet->setCellValue(
                        Coordinate::stringFromColumnIndex($column + $dayIndex).$row,
                        $this->hoursValue($hours),
                    );
                }
            }
            $column += self::WEEK_BLOCK;
        }
    }

    private function writeWeekFormulas(Worksheet $sheet, int $row, int $weekCount): void
    {
        for ($index = 0; $index < $weekCount; $index++) {
            $monday = self::LEFT_COLUMNS + 1 + ($index * self::WEEK_BLOCK);
            $friday = $monday + 4;
            $total = $monday + 5;
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex($total).$row,
                '=SUM('
                    .Coordinate::stringFromColumnIndex($monday).$row.':'
                    .Coordinate::stringFromColumnIndex($friday).$row
                    .')',
            );
        }
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @param  list<int>  $sourceRows
     */
    private function writeRolledUpHours(
        Worksheet $sheet,
        int $row,
        array $sourceRows,
        array $weeks,
        int $weekCount,
    ): void {
        $column = self::LEFT_COLUMNS + 1;
        foreach ($weeks as $week) {
            foreach (array_keys($week['days']) as $dayIndex) {
                $sheet->setCellValue(
                    Coordinate::stringFromColumnIndex($column + $dayIndex).$row,
                    $this->columnSum(Coordinate::stringFromColumnIndex($column + $dayIndex), $sourceRows),
                );
            }
            $column += self::WEEK_BLOCK;
        }
        $this->writeWeekFormulas($sheet, $row, $weekCount);
    }

    /**
     * @param  list<int>  $rows
     */
    private function columnSum(string $column, array $rows): string
    {
        if ($rows === []) {
            return '=0';
        }

        sort($rows);
        $contiguous = true;
        for ($index = 1, $count = count($rows); $index < $count; $index++) {
            if ($rows[$index] !== $rows[$index - 1] + 1) {
                $contiguous = false;
                break;
            }
        }

        if ($contiguous) {
            return '=SUM('.$column.$rows[0].':'.$column.$rows[array_key_last($rows)].')';
        }

        return '=SUM('.implode(',', array_map(fn (int $row): string => $column.$row, $rows)).')';
    }

    private function weekTotalsSumFormula(int $row, int $weekCount): string
    {
        $cells = [];
        for ($index = 0; $index < $weekCount; $index++) {
            $column = self::LEFT_COLUMNS + 1 + ($index * self::WEEK_BLOCK) + 5;
            $cells[] = Coordinate::stringFromColumnIndex($column).$row;
        }

        return '=SUM('.implode(',', $cells).')';
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @param  list<array<string, mixed>>  $rows
     */
    private function styleSheet(
        Worksheet $sheet,
        string $lastColRef,
        int $lastRow,
        array $weeks,
        array $rows,
    ): void {
        $sheet->freezePane(Coordinate::stringFromColumnIndex(self::LEFT_COLUMNS + 1).'3');
        $sheet->getSheetView()->setZoomScale(140);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(1, 2);
        $sheet->getPageMargins()
            ->setLeft(0.5)
            ->setRight(0.5)
            ->setTop(0.6)
            ->setBottom(0.6)
            ->setHeader(0.3)
            ->setFooter(0.3);

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(28);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(22);
        $sheet->getColumnDimension('F')->setWidth(10);
        $sheet->getColumnDimension('G')->setWidth(26);
        $sheet->getColumnDimension('H')->setWidth(12);
        $sheet->getColumnDimension('I')->setWidth(12);
        $sheet->getColumnDimension('J')->setWidth(12);

        $column = self::LEFT_COLUMNS + 1;
        foreach ($weeks as $week) {
            foreach (array_keys($week['days']) as $dayIndex) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column + $dayIndex))->setWidth(4);
            }
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column + 5))->setWidth(5);
            $column += self::WEEK_BLOCK;
        }

        $medium = [
            'borderStyle' => Border::BORDER_MEDIUM,
            'color' => ['rgb' => '000000'],
        ];
        $sheet->getStyle('A1:'.$lastColRef.$lastRow)->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 9],
            'borders' => ['allBorders' => $medium],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(42);
        $sheet->getStyle('A1:'.$lastColRef.'1')->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 12, 'bold' => true, 'color' => ['rgb' => '000000']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::GRAY],
            ],
        ]);
        $sheet->getStyle('F1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getRowDimension(2)->setRowHeight(34);
        $sheet->getStyle('A2:'.$lastColRef.'2')->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 11, 'bold' => true, 'color' => ['rgb' => self::WHITE]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::RED],
            ],
        ]);
        $sheet->getStyle('A2:G2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('A3:'.$lastColRef.$lastRow)->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::GRAY],
            ],
        ]);
        $firstWeekCol = Coordinate::stringFromColumnIndex(self::LEFT_COLUMNS + 1);
        $sheet->getStyle('H3:'.$lastColRef.$lastRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle('H3:I'.$lastRow)->applyFromArray([
            'numberFormat' => ['formatCode' => self::HOURS_FORMAT],
        ]);
        $sheet->getStyle('J3:J'.$lastRow)->applyFromArray([
            'numberFormat' => ['formatCode' => self::DIFF_FORMAT],
        ]);
        $sheet->getStyle($firstWeekCol.'3:'.$lastColRef.$lastRow)->applyFromArray([
            'numberFormat' => ['formatCode' => self::HOURS_FORMAT],
        ]);
        $sheet->getStyle('F3:F'.$lastRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            'numberFormat' => ['formatCode' => '0" m2"'],
        ]);

        foreach ($rows as $index => $row) {
            $excelRow = $index + 3;
            $sheet->getRowDimension($excelRow)->setRowHeight(16);
            if (in_array($row['kind'], ['project', 'team', 'team_total', 'work_total'], true)) {
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->getFont()->setBold(true);
            }
            if ($row['kind'] === 'team_total') {
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB(self::TEAM_TOTAL_FILL);
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THICK)
                    ->getColor()->setRGB('000000');
            }
            if ($row['kind'] === 'work_total') {
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB(self::WORK_TOTAL_FILL);
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THICK)
                    ->getColor()->setRGB('000000');
                $this->styleWorkDifference($sheet, $excelRow, $row);
            }
        }

        $column = self::LEFT_COLUMNS + 1;
        foreach ($weeks as $week) {
            $startRef = Coordinate::stringFromColumnIndex($column);
            $endRef = Coordinate::stringFromColumnIndex($column + 5);
            $sheet->getStyle($startRef.'1:'.$endRef.$lastRow)->applyFromArray([
                'borders' => ['outline' => $medium],
            ]);
            $column += self::WEEK_BLOCK;
        }
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    private function styleWorkDifference(Worksheet $sheet, int $row, array $rowData): void
    {
        if ($rowData['budget_hours'] === null) {
            return;
        }

        $difference = (float) $rowData['planned_hours'] - (float) $rowData['budget_hours'];
        if ($difference > 0.001) {
            $sheet->getStyle('J'.$row)->getFont()->getColor()->setRGB(self::RED);
        } elseif ($difference < -0.001) {
            $sheet->getStyle('J'.$row)->getFont()->getColor()->setRGB(self::UNDER_GREEN);
        }
    }

    private function addCompanyLogo(Worksheet $sheet): void
    {
        $path = $this->publicImagePath((string) config('company.logo'));
        if ($path === null) {
            return;
        }

        $drawing = new Drawing;
        $drawing->setName((string) config('company.name'));
        $drawing->setDescription((string) config('company.name'));
        $drawing->setPath($path);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $drawing->setHeight(34);
        $drawing->setWorksheet($sheet);
    }

    private function publicImagePath(string $relative): ?string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return null;
        }

        $absolute = public_path($relative);

        return is_file($absolute) ? $absolute : null;
    }

    private function optionalInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
