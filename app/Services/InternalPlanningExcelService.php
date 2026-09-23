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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
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

    private const RED = 'C41623';

    private const RED_LIGHT = 'FDECEE';

    private const INK = '1A1A1A';

    private const MUTED = '6B7280';

    private const LINE = 'E5E5E5';

    private const WEEK_LINE = 'D4D4D8';

    private const PAPER = 'F6F6F7';

    private const OK = '3F6212';

    private const OK_LIGHT = 'E8F0DC';

    private const WARN = 'B45309';

    private const WARN_LIGHT = 'FEF4E6';

    /** A Project, B Plaats, C m², D Begroot, E Gepland, F Verschil. */
    private const LEFT_COLUMNS = 6;

    private const HEADER_ROWS = 3;

    private const WEEK_BLOCK = 6;

    private const PROJECT_FILL = 'F6F6F7';

    private const TEAM_TOTAL_FILL = 'F4F4F5';

    private const WORK_TOTAL_FILL = 'E8E8EA';

    private const HOURS_FORMAT = '0.##;;';

    private const DIFF_FORMAT = '+0.##;-0.##;0';

    private const M2_FORMAT = '0.##" m²"';

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
        $rows = $this->sheetRows($this->projects($request), $assignments, $progress, $weeks);

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
     * @return Collection<int, Project>
     */
    private function projects(Request $request): Collection
    {
        return Project::query()
            ->with(['customer', 'workItems', 'workActivities.category'])
            ->active()
            ->accessibleBy($request->user())
            ->orderBy('id')
            ->get();
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
     * @param  Collection<int, Project>  $allProjects
     * @param  Collection<int, WorkerAssignment>  $assignments
     * @param  Collection<int, WorkProgressEntry>  $progress
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @return list<array<string, mixed>>
     */
    private function sheetRows(Collection $allProjects, Collection $assignments, Collection $progress, array $weeks): array
    {
        $projects = [];

        foreach ($allProjects as $project) {
            $this->ensureProject($projects, $project);
        }

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
            $hasPeople = false;
            $source = $this->sourceLabel($entry['project']);
            $budgetHours = $this->budgetHours($entry['project']);
            $plannedHours = $this->plannedHoursForTeams($teams);
            $header = $this->projectHeader($entry['project'], '', $source);
            $header['budget_hours'] = $budgetHours;
            $header['planned_hours'] = $plannedHours;
            $rows[] = $header;
            $detail = [
                'customer' => $header['customer'],
                'name' => $header['name'],
                'city' => $header['city'],
                'work_type' => $header['work_type'],
                'project_number' => $header['project_number'],
                'work_number' => $header['work_number'],
                'm2' => $header['m2'],
            ];

            foreach ($teams as $team) {
                $named = $team['name'] !== null && $team['name'] !== '';
                $people = $this->sortedPeople($team['people']);
                foreach ($people as $person) {
                    $hasPeople = true;
                    $rows[] = $this->hourRow(
                        'person',
                        $this->personLabel($named ? (string) $team['name'] : null, $person['name']),
                        $person['days'],
                        source: $source,
                        detail: $detail,
                    );
                }

                if ($named && $showTeamTotals) {
                    $rows[] = $this->hourRow(
                        'team_total',
                        'Totaal '.$team['name'],
                        [],
                        source: $source,
                        detail: $detail,
                    );
                }
            }

            if ($hasPeople) {
                $rows[] = $this->hourRow(
                    'work_total',
                    'Totaal werk',
                    [],
                    $budgetHours,
                    $plannedHours,
                    $source,
                    $detail,
                );
            }
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
            'project_number' => (string) ($project->workCode() ?? ''),
            'work_number' => $project->workNumber(),
            'm2' => $this->orderedSquareMeters($project),
            'label' => $label,
            'days' => [],
        ];
    }

    /**
     * @param  array<string, float>  $days
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function hourRow(
        string $kind,
        string $label,
        array $days,
        int|float|null $budgetHours = null,
        float $plannedHours = 0.0,
        string $source = '',
        array $detail = [],
    ): array {
        return [
            'kind' => $kind,
            'source' => $source,
            'customer' => (string) ($detail['customer'] ?? ''),
            'name' => (string) ($detail['name'] ?? ''),
            'city' => (string) ($detail['city'] ?? ''),
            'work_type' => (string) ($detail['work_type'] ?? ''),
            'project_number' => (string) ($detail['project_number'] ?? ''),
            'work_number' => (string) ($detail['work_number'] ?? ''),
            'm2' => $detail['m2'] ?? null,
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
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(10);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Planning Nicon');
        $this->writeSheet($sheet, $year, $weeks, $rows);

        $detail = $spreadsheet->createSheet();
        $detail->setTitle('Detail');
        $this->writeDetailSheet($detail, $rows);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeSheet(Worksheet $sheet, int $year, array $weeks, array $rows): void
    {
        $weekCount = count($weeks);
        $lastCol = self::LEFT_COLUMNS + ($weekCount * self::WEEK_BLOCK);
        $lastColRef = Coordinate::stringFromColumnIndex($lastCol);
        $lastRow = self::HEADER_ROWS + max(1, count($rows));

        $sheet->setCellValue('A3', 'Project');
        $sheet->setCellValue('B3', 'Plaats');
        $sheet->setCellValue('C3', 'm²');
        $sheet->setCellValue('D3', "Begroot\nuren");
        $sheet->setCellValue('E3', "Gepland\nuren");
        $sheet->setCellValue('F3', 'Verschil');

        $column = self::LEFT_COLUMNS + 1;
        foreach ($weeks as $week) {
            $startRef = Coordinate::stringFromColumnIndex($column);
            $endRef = Coordinate::stringFromColumnIndex($column + 5);
            $sheet->setCellValue($startRef.'1', $week['number']);
            $sheet->mergeCells($startRef.'1:'.$endRef.'1');
            foreach ($week['days'] as $index => $day) {
                $dayRef = Coordinate::stringFromColumnIndex($column + $index);
                $sheet->setCellValue($dayRef.'2', Date::PHPToExcel($day));
                $sheet->setCellValue($dayRef.'3', self::DAY_LABELS[$index]);
            }
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($column + 5).'3', 'wk');
            $column += self::WEEK_BLOCK;
        }

        $blocks = [];
        $current = $this->emptyBlock();
        $currentTeamPeople = [];

        foreach ($rows as $index => $row) {
            $excelRow = $index + self::HEADER_ROWS + 1;
            if ($row['kind'] === 'project') {
                if ($current['project'] !== null) {
                    $blocks[] = $current;
                }
                $current = $this->emptyBlock();
                $currentTeamPeople = [];
                $current['project'] = $excelRow;
                $current['budget_hours'] = $row['budget_hours'] ?? null;
                $sheet->setCellValue('A'.$excelRow, $row['name']);
                $sheet->setCellValue('B'.$excelRow, $row['city']);
                if ($row['m2'] !== null) {
                    $sheet->setCellValue('C'.$excelRow, $row['m2']);
                }
            } else {
                $sheet->setCellValue('A'.$excelRow, $row['label']);
            }

            if (in_array($row['kind'], ['person', 'team_total'], true)) {
                $sheet->setCellValue('D'.$excelRow, self::DASH);
                $sheet->setCellValue('F'.$excelRow, self::DASH);
            }

            if ($row['kind'] === 'person') {
                $this->writePersonDays($sheet, $excelRow, $weeks, $row['days']);
                $this->writeWeekFormulas($sheet, $excelRow, $weekCount);
                $sheet->setCellValue('E'.$excelRow, $this->weekTotalsSumFormula($excelRow, $weekCount));
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
                $current['budget_hours'] = $row['budget_hours'];
            }
        }

        if ($current['project'] !== null) {
            $blocks[] = $current;
        }

        foreach ($blocks as $block) {
            foreach ($block['team_totals'] as $team) {
                $this->writeRolledUpHours($sheet, $team['row'], $team['people'], $weeks, $weekCount);
                $sheet->setCellValue('E'.$team['row'], $this->columnSum('E', $team['people']));
            }

            $workSources = $block['team_totals'] !== []
                ? array_values(array_merge(
                    array_map(fn (array $team): int => $team['row'], $block['team_totals']),
                    $block['loose'],
                ))
                : $block['people'];
            $plannedFormula = $this->columnSum('E', $block['people']);
            if ($block['work_total'] !== null) {
                $this->writeRolledUpHours($sheet, $block['work_total'], $workSources, $weeks, $weekCount);
                $sheet->setCellValue('E'.$block['work_total'], $plannedFormula);
            }
            if ($block['project'] !== null) {
                $this->writeRolledUpHours($sheet, $block['project'], $workSources, $weeks, $weekCount);
                $this->writeWorkBudget($sheet, $block['project'], $block['budget_hours']);
                $sheet->setCellValue('E'.$block['project'], $plannedFormula);
            }
        }

        $this->addCompanyLogo($sheet);
        $this->styleSheet($sheet, $year, $lastColRef, $lastRow, $weeks, $rows);
    }

    private function writeWorkBudget(Worksheet $sheet, int $row, int|float|null $budgetHours): void
    {
        if ($budgetHours === null) {
            $sheet->setCellValue('D'.$row, self::DASH);
            $sheet->setCellValue('F'.$row, self::DASH);

            return;
        }

        $sheet->setCellValue('D'.$row, $budgetHours);
        $sheet->setCellValue('F'.$row, '=E'.$row.'-D'.$row);
    }

    /**
     * @return array{people: list<int>, loose: list<int>, team_totals: list<array{row: int, people: list<int>}>, work_total: ?int, project: ?int, budget_hours: int|float|null}
     */
    private function emptyBlock(): array
    {
        return [
            'people' => [],
            'loose' => [],
            'team_totals' => [],
            'work_total' => null,
            'project' => null,
            'budget_hours' => null,
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
                    $coordinate = Coordinate::stringFromColumnIndex($column + $dayIndex).$row;
                    $sheet->setCellValue($coordinate, $this->hoursValue($hours));
                    $sheet->getStyle($coordinate)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB(self::RED_LIGHT);
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
        int $year,
        string $lastColRef,
        int $lastRow,
        array $weeks,
        array $rows,
    ): void {
        $firstWeekCol = Coordinate::stringFromColumnIndex(self::LEFT_COLUMNS + 1);
        $openingCell = $this->openingCell($year, $weeks);
        $sheet->freezePane($firstWeekCol.(self::HEADER_ROWS + 1), $openingCell);
        $sheet->setSelectedCells($openingCell);
        $sheet->getSheetView()->setZoomScale(100);
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToPage(false)
            ->setFitToWidth(0)
            ->setFitToHeight(0)
            ->setScale(80)
            ->setRowsToRepeatAtTopByStartAndEnd(1, self::HEADER_ROWS)
            ->setColumnsToRepeatAtLeftByStartAndEnd('A', 'F');
        $sheet->getPageMargins()
            ->setLeft(0.4)
            ->setRight(0.4)
            ->setTop(0.5)
            ->setBottom(0.5)
            ->setHeader(0.25)
            ->setFooter(0.25);
        $sheet->getHeaderFooter()->setOddFooter('&LPlanning Nicon&R&P / &N');

        $sheet->getColumnDimension('A')->setWidth(34);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(11);
        $sheet->getColumnDimension('E')->setWidth(11);
        $sheet->getColumnDimension('F')->setWidth(11);

        $column = self::LEFT_COLUMNS + 1;
        foreach ($weeks as $week) {
            foreach (array_keys($week['days']) as $dayIndex) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column + $dayIndex))->setWidth(3.8);
            }
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column + 5))->setWidth(6);
            $column += self::WEEK_BLOCK;
        }

        $thin = [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => self::LINE],
        ];
        $sheet->getStyle('A1:'.$lastColRef.$lastRow)->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 10, 'color' => ['rgb' => self::INK]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => $thin],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(16);
        $sheet->getRowDimension(3)->setRowHeight(32);
        $sheet->getStyle('A1:'.$lastColRef.'3')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::PAPER],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getStyle('A3:B3')->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 10, 'bold' => true, 'color' => ['rgb' => self::INK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getStyle('C3:'.$lastColRef.'3')->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 9, 'bold' => true, 'color' => ['rgb' => self::INK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle($firstWeekCol.'1:'.$lastColRef.'1')->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 12, 'bold' => true, 'color' => ['rgb' => self::RED]],
            'numberFormat' => ['formatCode' => '"WEEK "0'],
        ]);
        $sheet->getStyle($firstWeekCol.'2:'.$lastColRef.'2')->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 8, 'bold' => false, 'color' => ['rgb' => self::MUTED]],
            'numberFormat' => ['formatCode' => 'd'],
        ]);
        $sheet->getStyle('A3:'.$lastColRef.'3')->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM)
            ->getColor()->setRGB(self::RED);

        $firstDataRow = self::HEADER_ROWS + 1;
        $sheet->getStyle('C'.$firstDataRow.':E'.$lastRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'numberFormat' => ['formatCode' => self::HOURS_FORMAT],
        ]);
        $sheet->getStyle('C'.$firstDataRow.':C'.$lastRow)->applyFromArray([
            'numberFormat' => ['formatCode' => self::M2_FORMAT],
        ]);
        $sheet->getStyle('F'.$firstDataRow.':F'.$lastRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'numberFormat' => ['formatCode' => self::DIFF_FORMAT],
        ]);
        $sheet->getStyle($firstWeekCol.$firstDataRow.':'.$lastColRef.$lastRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'numberFormat' => ['formatCode' => self::HOURS_FORMAT],
        ]);
        $sheet->getStyle('A'.$firstDataRow.':B'.$lastRow)->getAlignment()->setWrapText(true);

        foreach ($rows as $index => $row) {
            $excelRow = $index + $firstDataRow;
            $sheet->getRowDimension($excelRow)->setRowHeight(18);
            if ($row['kind'] === 'project') {
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => self::INK]],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::PROJECT_FILL],
                    ],
                ]);
                $sheet->getStyle('A'.$excelRow)->getFont()->setSize(11)->getColor()->setRGB(self::RED);
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_MEDIUM)
                    ->getColor()->setRGB(self::RED);
                $this->styleWorkDifference($sheet, $excelRow, $row);
            }
            if ($row['kind'] === 'person') {
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->getFont()->setBold(false)->setSize(9);
                $sheet->getStyle('A'.$excelRow.':F'.$excelRow)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FFFFFF');
                $sheet->getStyle('A'.$excelRow)->getAlignment()->setIndent(1);
            }
            if ($row['kind'] === 'team_total') {
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => self::INK]],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::TEAM_TOTAL_FILL],
                    ],
                ]);
                $sheet->getStyle('A'.$excelRow)->getAlignment()->setIndent(1);
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB(self::INK);
            }
            if ($row['kind'] === 'work_total') {
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => self::INK]],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => self::WORK_TOTAL_FILL],
                    ],
                ]);
                $sheet->getStyle('A'.$excelRow)->getAlignment()->setIndent(1);
                $sheet->getStyle('A'.$excelRow.':'.$lastColRef.$excelRow)->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB(self::INK);
                $this->styleWorkDifference($sheet, $excelRow, $row);
            }
        }

        $column = self::LEFT_COLUMNS + 1;
        foreach ($weeks as $week) {
            $startRef = Coordinate::stringFromColumnIndex($column);
            $sheet->getStyle($startRef.'1:'.$startRef.$lastRow)->getBorders()->getLeft()
                ->setBorderStyle(Border::BORDER_MEDIUM)
                ->getColor()->setRGB(self::WEEK_LINE);
            $column += self::WEEK_BLOCK;
        }

        $this->highlightPlannedDays($sheet, $weeks, $lastRow);
        $sheet->setAutoFilter('A3:F'.$lastRow);
        $sheet->getTabColor()->setRGB(self::RED);
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     */
    private function openingCell(int $year, array $weeks): string
    {
        $today = now();
        $weekNumber = 1;
        if ((int) $today->isoWeekYear() === $year && $weeks !== []) {
            $weekNumber = max(1, (int) $today->isoWeek() - 4);
        }

        $lastWeek = (int) ($weeks === [] ? 1 : $weeks[array_key_last($weeks)]['number']);
        $weekNumber = min($weekNumber, max(1, $lastWeek));
        $column = self::LEFT_COLUMNS + 1 + (($weekNumber - 1) * self::WEEK_BLOCK);

        return Coordinate::stringFromColumnIndex($column).(self::HEADER_ROWS + 1);
    }

    /**
     * @param  list<array{number: int, year: int, days: list<Carbon>}>  $weeks
     */
    private function highlightPlannedDays(Worksheet $sheet, array $weeks, int $lastRow): void
    {
        $firstDataRow = self::HEADER_ROWS + 1;
        $column = self::LEFT_COLUMNS + 1;
        foreach ($weeks as $week) {
            $start = Coordinate::stringFromColumnIndex($column);
            $end = Coordinate::stringFromColumnIndex($column + 4);
            $conditional = new Conditional;
            $conditional->setConditionType(Conditional::CONDITION_CELLIS);
            $conditional->setOperatorType(Conditional::OPERATOR_GREATERTHAN);
            $conditional->addCondition('0');
            $conditional->getStyle()->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(self::RED_LIGHT);
            $range = $start.$firstDataRow.':'.$end.$lastRow;
            $styles = $sheet->getStyle($range)->getConditionalStyles();
            $styles[] = $conditional;
            $sheet->getStyle($range)->setConditionalStyles($styles);
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
        $fill = self::RED_LIGHT;
        $font = self::RED;
        if (abs($difference) <= 0.001) {
            $fill = self::OK_LIGHT;
            $font = self::OK;
        } elseif ($difference > 0.001) {
            $fill = self::WARN_LIGHT;
            $font = self::WARN;
        }

        $sheet->getStyle('F'.$row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($fill);
        $sheet->getStyle('F'.$row)->getFont()->getColor()->setRGB($font);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeDetailSheet(Worksheet $sheet, array $rows): void
    {
        $headers = [
            'A' => 'Projectnr.',
            'B' => 'Werknummer',
            'C' => 'Bron',
            'D' => 'Opdrachtgever',
            'E' => 'Project',
            'F' => 'Plaats',
            'G' => 'Soort stoffering',
            'H' => 'Team / vakman',
            'I' => 'm²',
            'J' => 'Begroot uren',
            'K' => 'Gepland uren',
            'L' => 'Verschil',
        ];
        foreach ($headers as $column => $label) {
            $sheet->setCellValue($column.'1', $label);
        }

        $lastRow = max(2, count($rows) + 1);
        $widths = ['A' => 16, 'B' => 16, 'C' => 22, 'D' => 24, 'E' => 32, 'F' => 16, 'G' => 42, 'H' => 28, 'I' => 10, 'J' => 14, 'K' => 14, 'L' => 12];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('A1:L'.$lastRow)->applyFromArray([
            'font' => ['name' => self::FONT, 'size' => 10, 'color' => ['rgb' => self::INK]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => self::LINE],
            ]],
        ]);
        $sheet->getStyle('A1:L1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::INK]],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::PAPER],
            ],
        ]);
        $sheet->getStyle('A1:L1')->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM)
            ->getColor()->setRGB(self::RED);
        $sheet->getRowDimension(1)->setRowHeight(22);

        foreach ($rows as $index => $row) {
            $detailRow = $index + 2;
            $planningRow = $index + self::HEADER_ROWS + 1;
            $sheet->setCellValueExplicit('A'.$detailRow, (string) ($row['project_number'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$detailRow, (string) ($row['work_number'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$detailRow, $row['source']);
            $sheet->setCellValue('D'.$detailRow, $row['customer']);
            $sheet->setCellValue('E'.$detailRow, $row['name']);
            $sheet->setCellValue('F'.$detailRow, $row['city']);
            $sheet->setCellValue('G'.$detailRow, $row['work_type']);
            $sheet->setCellValue('H'.$detailRow, $row['kind'] === 'project' ? '' : $row['label']);
            if ($row['m2'] !== null) {
                $sheet->setCellValue('I'.$detailRow, $row['m2']);
            }
            $sheet->setCellValue('J'.$detailRow, "='Planning Nicon'!D{$planningRow}");
            $sheet->setCellValue('K'.$detailRow, "='Planning Nicon'!E{$planningRow}");
            $sheet->setCellValue('L'.$detailRow, "='Planning Nicon'!F{$planningRow}");
            $sheet->getRowDimension($detailRow)->setRowHeight(30);
            if ($row['kind'] === 'project') {
                $sheet->getStyle('A'.$detailRow.':L'.$detailRow)->getFont()->setBold(true);
                $sheet->getStyle('E'.$detailRow)->getFont()->getColor()->setRGB(self::RED);
                $sheet->getStyle('A'.$detailRow.':L'.$detailRow)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB(self::PROJECT_FILL);
                $sheet->getStyle('A'.$detailRow.':L'.$detailRow)->getBorders()->getTop()
                    ->setBorderStyle(Border::BORDER_MEDIUM)
                    ->getColor()->setRGB(self::RED);
            } elseif (in_array($row['kind'], ['team_total', 'work_total'], true)) {
                $sheet->getStyle('A'.$detailRow.':L'.$detailRow)->getFont()->setBold(true);
                $sheet->getStyle('H'.$detailRow)->getAlignment()->setIndent(1);
            } else {
                $sheet->getStyle('H'.$detailRow)->getAlignment()->setIndent(1);
            }
            $sheet->getStyle('B'.$detailRow)->getFont()->getColor()->setRGB(self::RED);
        }

        $sheet->getStyle('G2:G'.$lastRow)->getAlignment()
            ->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('I2:K'.$lastRow)->getNumberFormat()->setFormatCode(self::HOURS_FORMAT);
        $sheet->getStyle('I2:I'.$lastRow)->getNumberFormat()->setFormatCode(self::M2_FORMAT);
        $sheet->getStyle('L2:L'.$lastRow)->getNumberFormat()->setFormatCode(self::DIFF_FORMAT);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:L'.$lastRow);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(1, 1);
        $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
        $sheet->setShowGridlines(false);
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
        $drawing->setHeight(18);
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
