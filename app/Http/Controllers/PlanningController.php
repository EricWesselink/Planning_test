<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Worker;
use App\Services\InternalPlanningExcelService;
use App\Services\PlanningBoardService;
use App\Services\WeekplanningPdfService;
use App\Support\PlanningWeek;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PlanningController extends Controller
{
    public function index(Request $request, PlanningBoardService $board, WeekplanningPdfService $weekplanning): View
    {
        $this->authorizeRequestedProject($request);
        $data = $board->build($request);
        $scheduledWorkerId = $request->user()?->scheduledWorkerId();

        return view('planning.index', array_merge($data, [
            'workers' => Worker::query()
                ->where('active', true)
                ->when($scheduledWorkerId, fn ($q) => $q->whereKey($scheduledWorkerId))
                ->with('crewPeople')
                ->orderBy('name')
                ->get(),
            'canManagePlanning' => $request->user()?->canManagePlanning() ?? false,
            'canViewLaborCosts' => $request->user()?->canViewLaborCosts() ?? false,
            'weekplanningTeams' => $weekplanning->scheduledGroups($request),
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

    public function weekplanning(Request $request, WeekplanningPdfService $weekplanning): Response
    {
        $data = $weekplanning->build($request);
        $pdf = Pdf::loadView('planning.weekplanning', $data)
            ->setPaper('a4', 'landscape')
            ->setOption('defaultFont', 'DejaVu Sans');
        $pdf->addInfo([
            'Title' => $data['heading'].' · Week '.$data['weekNumber'].' · '.$data['weekYear'],
        ]);
        $pdf->render();

        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        if ($font) {
            $muted = [0.35, 0.35, 0.38];
            $pdf->getCanvas()->page_text(
                28,
                18,
                'NICON VLOEREN | Weekplanning | Gegenereerd op '.$data['generatedOn'],
                $font,
                8,
                $muted,
            );
            $pdf->getCanvas()->page_text(700, 18, 'Pagina {PAGE_NUM} van {PAGE_COUNT}', $font, 8, $muted);
        }

        return $pdf->stream($data['filename']);
    }

    public function excel(Request $request, InternalPlanningExcelService $excel): BinaryFileResponse
    {
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
}
