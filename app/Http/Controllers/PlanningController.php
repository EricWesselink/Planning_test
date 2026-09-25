<?php

namespace App\Http\Controllers;

use App\Models\CrewMember;
use App\Models\Project;
use App\Models\WorkActivityCategory;
use App\Models\Worker;
use App\Services\DocumentMailService;
use App\Services\InternalPlanningExcelService;
use App\Services\InternalWeekPlanningService;
use App\Services\PersonnelWeekOverviewService;
use App\Services\PlanningBoardService;
use App\Services\WeekplanningPdfService;
use App\Support\PlanningWeek;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PlanningController extends Controller
{
    public function index(Request $request, PlanningBoardService $board, WeekplanningPdfService $weekplanning, DocumentMailService $mailer, InternalWeekPlanningService $internalWeeks): View|RedirectResponse
    {
        Gate::authorize('view-planning');
        $this->authorizeRequestedProject($request);
        $data = $board->build($request);
        if ($request->boolean('doubles') && (int) ($data['doubleFilter']['count'] ?? 0) === 0) {
            $query = array_filter(
                $data['filters'] ?? [],
                fn (mixed $value): bool => $value !== null && $value !== '',
            );
            unset($query['doubles'], $query['double_crew'], $query['double_worker']);

            return redirect()->route('planning', $query);
        }
        $scheduledWorkerId = $request->user()?->scheduledWorkerId();
        $workers = Worker::query()
            ->where('active', true)
            ->when($scheduledWorkerId, fn ($q) => $q->whereKey($scheduledWorkerId))
            ->with('crewPeople')
            ->orderBy('name')
            ->get();

        return view('planning.index', array_merge($data, [
            'workers' => $workers,
            'filterPeople' => $workers
                ->flatMap(function (Worker $worker) {
                    return $worker->crewPeople
                        ->filter(fn (CrewMember $member): bool => $member->isActive() && trim((string) $member->name) !== '')
                        ->map(fn (CrewMember $member): array => [
                            'id' => $member->id,
                            'label' => $member->label(),
                            'team' => $worker->planName(),
                        ]);
                })
                ->sortBy(fn (array $row): string => mb_strtolower($row['label'].' '.$row['team']), SORT_NATURAL)
                ->values(),
            'canManagePlanning' => $request->user()?->canManagePlanning() ?? false,
            'workActivityChoices' => $this->workActivityChoices($request->user()?->canManagePlanning() ?? false),
            'canDragPlanning' => $request->user()?->canDragPlanning() ?? false,
            'canAssignPlanning' => $request->user()?->canAssignPlanning() ?? false,
            'internalWeekDays' => $internalWeeks->coveredDatesByWorker($data['weekStart']),
            'canViewLaborCosts' => $request->user()?->canViewLaborCosts() ?? false,
            'weekplanningTeams' => $weekplanning->scheduledGroups($request),
            'weekplanningMail' => ($request->user()?->canDownloadPlanningWeekPdf() ?? false)
                ? $mailer->weekplanningDraft($request->user(), $data['weekStart']->isoWeek())
                : null,
            'workItemsByProject' => $data['projects']->mapWithKeys(
                fn (Project $project): array => [$project->id => $board->plannableWorkChoices($project)]
            ),
        ]));
    }

    public function export(Request $request, PlanningBoardService $board): View
    {
        Gate::authorize('view-planning');
        $this->authorizeRequestedProject($request);
        $data = $board->build($request);
        $clientProject = $this->clientProject($request, $data['rows']);

        return view('planning.pdf', array_merge($data, [
            'clientProject' => $clientProject,
            'showNames' => $request->boolean('intern'),
            'autoPrint' => $request->boolean('print'),
            'canViewLaborCosts' => ($request->user()?->canViewLaborCosts() ?? false) && $request->boolean('intern'),
        ]));
    }

    public function weekplanning(Request $request, WeekplanningPdfService $weekplanning): Response
    {
        Gate::authorize('view-planning');
        abort_unless($request->user()?->canDownloadPlanningWeekPdf() ?? false, 403);
        $document = $weekplanning->makePdf($request);

        return $document['pdf']->stream($document['filename']);
    }

    public function personnelWeek(Request $request, PersonnelWeekOverviewService $overview, DocumentMailService $mailer): View
    {
        Gate::authorize('view-personnel');
        $this->authorizeRequestedProject($request);
        $data = $overview->build($request);
        $data['mailDraft'] = ($request->user()?->canDownloadPlanningWeekPdf() ?? false)
            ? $mailer->personnelWeekDraft($request->user(), $data['weekNumber'])
            : null;

        return view('planning.personnel-week', $data);
    }

    public function personnelWeekPdf(Request $request, PersonnelWeekOverviewService $overview): Response
    {
        Gate::authorize('view-personnel');
        abort_unless($request->user()?->canDownloadPlanningWeekPdf() ?? false, 403);
        $this->authorizeRequestedProject($request);
        $document = $overview->makePdf($request);

        return $document['pdf']->stream($document['filename']);
    }

    public function excel(Request $request, InternalPlanningExcelService $excel): BinaryFileResponse
    {
        Gate::authorize('view-planning');
        $request->validate([
            'year' => ['nullable', 'integer', 'min:'.PlanningWeek::MIN_YEAR, 'max:'.PlanningWeek::MAX_YEAR],
        ]);

        return $excel->download($request);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function clientProject(Request $request, array $rows): ?Project
    {
        $id = $request->integer('project_id') ?: 0;
        if ($id < 1) {
            $projectRows = array_values(array_filter(
                $rows,
                fn (array $row): bool => ($row['type'] ?? '') === 'project'
            ));
            $id = count($projectRows) === 1 ? (int) $projectRows[0]['id'] : 0;
        }
        if ($id < 1) {
            return null;
        }

        $project = Project::query()->with('customer')->find($id);
        if ($project) {
            Gate::authorize('view', $project);
        }

        return $project;
    }

    private function authorizeRequestedProject(Request $request): void
    {
        if (! $request->filled('project_id')) {
            return;
        }

        Gate::authorize('view', Project::query()->findOrFail($request->integer('project_id')));
    }

    /**
     * @return Collection<int, WorkActivityCategory>
     */
    private function workActivityChoices(bool $canManagePlanning): Collection
    {
        if (! $canManagePlanning) {
            return collect();
        }

        return WorkActivityCategory::query()
            ->active()
            ->ordered()
            ->with(['activities' => function ($query): void {
                $query->active()->ordered();
            }])
            ->get()
            ->filter(fn (WorkActivityCategory $category): bool => $category->activities->isNotEmpty())
            ->values();
    }
}
