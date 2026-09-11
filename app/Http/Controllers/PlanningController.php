<?php

namespace App\Http\Controllers;

use App\Enums\ProjectKind;
use App\Models\Project;
use App\Models\Worker;
use App\Services\PlanningBoardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PlanningController extends Controller
{
    public function index(Request $request, PlanningBoardService $board): View
    {
        $this->authorizeRequestedProject($request);
        $data = $board->build($request);
        $kind = (string) $request->input('kind', '');
        $kindCase = ProjectKind::tryFrom($kind);
        $staffing = (string) $request->input('staffing', '');
        $scheduledWorkerId = $request->user()?->scheduledWorkerId();

        return view('planning.index', array_merge($data, [
            'projects' => Project::query()
                ->accessibleBy($request->user())
                ->active()
                ->when($kind === ProjectKind::KLEINE_FILTER, function ($q): void {
                    $q->where(function ($query): void {
                        $query->whereIn('kind', ProjectKind::smallWorkCases())
                            ->orWhereHas('workItems', fn ($items) => $items->where('is_extra_work', true));
                    });
                })
                ->when($kindCase !== null, fn ($q) => $q->where('kind', $kindCase))
                ->when($staffing === 'open' && $scheduledWorkerId === null, fn ($q) => $q->whereDoesntHave('assignments'))
                ->when($staffing === 'planned', fn ($q) => $q->whereHas('assignments'))
                ->with('workItems')
                ->orderBy('project_number')
                ->get(),
            'workers' => Worker::query()
                ->where('active', true)
                ->withLogin()
                ->when($scheduledWorkerId, fn ($q) => $q->whereKey($scheduledWorkerId))
                ->with('crewPeople')
                ->orderBy('name')
                ->get(),
            'canManagePlanning' => $request->user()?->canManagePlanning() ?? false,
            'canViewLaborCosts' => $request->user()?->canViewLaborCosts() ?? false,
        ]));
    }

    public function export(Request $request, PlanningBoardService $board): View
    {
        $this->authorizeRequestedProject($request);
        $data = $board->build($request);
        $clientProject = $this->clientProject($request, $data['rows']);

        return view('planning.pdf', array_merge($data, [
            'clientProject' => $clientProject,
            'showNames' => $request->boolean('intern'),
            'canViewLaborCosts' => ($request->user()?->canViewLaborCosts() ?? false) && $request->boolean('intern'),
        ]));
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
}
