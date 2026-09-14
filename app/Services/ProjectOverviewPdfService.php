<?php

namespace App\Services;

use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\WorkerAssignment;
use App\Support\Format;
use App\Support\PlanningHours;
use App\Support\PlanningWeek;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ProjectOverviewPdfService
{
    /**
     * @return array{search: string, week: ?int, weekYear: ?int}
     */
    public function filters(Request $request): array
    {
        $week = $this->weekNumber($request->input('week'));
        $year = PlanningWeek::optionalInt($request->input('year'));
        if ($year !== null && ($year < PlanningWeek::MIN_YEAR || $year > PlanningWeek::MAX_YEAR)) {
            $year = null;
        }

        return [
            'search' => mb_substr(trim($request->string('q')->toString()), 0, 80),
            'week' => $week,
            'weekYear' => $week !== null ? ($year ?? (int) now()->isoWeekYear()) : $year,
        ];
    }

    /**
     * @return Collection<int, Project>
     */
    public function projects(Request $request): Collection
    {
        $filters = $this->filters($request);

        return Project::query()
            ->accessibleBy($request->user())
            ->active()
            ->matchingSearch($filters['search'])
            ->startingInIsoWeek($filters['week'], $filters['weekYear'])
            ->with(['customer', 'workActivities.category', 'workItems.progressEntries', 'assignments.worker', 'assignments.crewMembers'])
            ->orderByRaw('planned_start_date is null')
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *     filename: string,
     *     heading: string,
     *     generatedOn: string,
     *     logo: ?string,
     *     companyName: string,
     *     filterLabel: ?string,
     *     rows: list<array{
     *         project_number: string,
     *         work_number: string,
     *         name: string,
     *         address: string,
     *         start_date: string,
     *         start_week: string,
     *         end: string,
     *         status: string,
     *         progress: string,
     *         team: string
     *     }>,
     *     projectCount: int,
     *     squareMeters: string,
     *     scheduledCount: int
     * }
     */
    public function build(Request $request): array
    {
        $filters = $this->filters($request);
        $projects = $this->projects($request);
        $generatedOn = now()->format('d-m-Y H:i');

        return [
            'filename' => $this->filename($filters, $generatedOn),
            'heading' => 'Projectenoverzicht',
            'generatedOn' => $generatedOn,
            'logo' => $this->imagePath((string) config('company.logo')),
            'companyName' => (string) config('company.name'),
            'filterLabel' => $this->filterLabel($filters),
            'rows' => $projects->map(fn (Project $project): array => $this->row($project))->all(),
            'projectCount' => $projects->count(),
            'squareMeters' => Format::qty($this->totalSquareMeters($projects)),
            'scheduledCount' => $projects->filter(fn (Project $project): bool => $this->isScheduled($project))->count(),
        ];
    }

    /**
     * @param  array{search: string, week: ?int, weekYear: ?int}  $filters
     */
    public function filename(array $filters, ?string $generatedOn = null): string
    {
        $parts = ['Projectenoverzicht'];
        if ($filters['week'] !== null) {
            $parts[] = 'week-'.$filters['week'];
            if ($filters['weekYear'] !== null) {
                $parts[] = (string) $filters['weekYear'];
            }
        }
        $stamp = $generatedOn ?? now()->format('d-m-Y H:i');
        $parts[] = str_replace([' ', ':'], ['-', ''], $stamp);

        return implode('_', $parts).'.pdf';
    }

    /**
     * @return array{
     *     project_number: string,
     *     work_number: string,
     *     name: string,
     *     address: string,
     *     start_date: string,
     *     start_week: string,
     *     end: string,
     *     status: string,
     *     progress: string,
     *     team: string
     * }
     */
    private function row(Project $project): array
    {
        return [
            'project_number' => $this->projectNumber($project),
            'work_number' => $this->workNumber($project),
            'name' => $project->displayTitle(),
            'address' => $project->nawLine() ?? '—',
            'start_date' => $project->planned_start_date?->format('d-m-Y') ?? '—',
            'start_week' => $project->planningStartWeek() !== null ? (string) $project->planningStartWeek() : '—',
            'end' => $this->endLabel($project),
            'status' => $project->status->label(),
            'progress' => $this->progressLabel($project),
            'team' => $this->teamLabel($project),
        ];
    }

    private function projectNumber(Project $project): string
    {
        if ($project->isSmallWork()) {
            return $project->kind?->badge() ?? 'KLEIN';
        }
        if ($project->isWinkel()) {
            return 'WINKEL';
        }

        $code = $project->workCode();

        return $code !== null && $code !== '' ? $code : '—';
    }

    private function workNumber(Project $project): string
    {
        $number = $project->workNumber();

        return $number !== '' ? $number : '—';
    }

    private function endLabel(Project $project): string
    {
        if ($project->planned_end_date === null) {
            return '—';
        }

        $week = $project->planningEndWeek();
        $date = $project->planned_end_date->format('d-m-Y');

        return $week !== null ? $date.' · wk '.$week : $date;
    }

    private function progressLabel(Project $project): string
    {
        if ($project->isWinkel()) {
            return $project->shopWorkLine() ?? '—';
        }
        if ($project->isSmallWork()) {
            return PlanningHours::hoursLabel((float) ($project->workItems->first()?->begrote_uren ?? 0));
        }

        $head = $project->headlineWorkItem();
        if ($head === null) {
            return '—';
        }

        return Format::qty($head->completedQuantity()).' / '.Format::qty($head->ordered_quantity).' '.$head->unit->label();
    }

    private function teamLabel(Project $project): string
    {
        $label = $project->assignments
            ->groupBy(fn (WorkerAssignment $assignment): string => (string) ($assignment->worker_id ?: $assignment->id))
            ->map(function (Collection $rows): string {
                $assignment = $rows->first();
                $worker = $assignment?->worker;
                $team = $worker?->planName() ?? 'Onbekend';
                if ($worker?->employment_type?->isExternal() && filled($worker->company)) {
                    $team = trim((string) $worker->company);
                }
                $present = $rows
                    ->flatMap(fn (WorkerAssignment $row): array => $row->presentNames())
                    ->filter()
                    ->unique()
                    ->implode(', ');

                return $present !== '' ? $team.' ('.$present.')' : $team;
            })
            ->unique()
            ->filter()
            ->implode(', ');

        return $label !== '' ? $label : '—';
    }

    /**
     * @param  Collection<int, Project>  $projects
     */
    private function totalSquareMeters(Collection $projects): float
    {
        return (float) $projects->sum(function (Project $project): float {
            $head = $project->headlineWorkItem();
            if ($head === null || $head->unit !== WorkUnit::SquareMeter) {
                return 0.0;
            }

            return (float) $head->ordered_quantity;
        });
    }

    private function isScheduled(Project $project): bool
    {
        return $project->planned_start_date !== null || $project->assignments->isNotEmpty();
    }

    /**
     * @param  array{search: string, week: ?int, weekYear: ?int}  $filters
     */
    private function filterLabel(array $filters): ?string
    {
        $parts = [];
        if ($filters['search'] !== '') {
            $parts[] = 'zoekopdracht “'.$filters['search'].'”';
        }
        if ($filters['week'] !== null) {
            $year = $filters['weekYear'] !== null ? ' · '.$filters['weekYear'] : '';
            $parts[] = 'week '.$filters['week'].$year;
        }

        if ($parts === []) {
            return null;
        }

        return 'Selectie: '.implode(' · ', $parts);
    }

    private function weekNumber(mixed $value): ?int
    {
        $week = PlanningWeek::optionalInt($value);
        if ($week === null || $week < 1 || $week > 53) {
            return null;
        }

        return $week;
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
}
