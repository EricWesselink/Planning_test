<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\WorkerAssignment;
use App\Services\DashboardOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardOverviewService $overview): View
    {
        Gate::authorize('view-dashboard');
        $today = now()->startOfDay();
        $user = $request->user();

        $todayAssignments = WorkerAssignment::query()
            ->with(['project.workItems', 'project.workActivities.category', 'project.customer', 'worker', 'crewMembers'])
            ->whereHas('project', fn ($query) => $query->active()->accessibleBy($user))
            ->when($user->scheduledWorkerId(), fn ($query, $workerId) => $query->where('worker_id', $workerId))
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->get()
            ->groupBy('project_id');

        $todayBlocks = $todayAssignments->map(function ($assignments) {
            $project = $assignments->first()->project;

            return [
                'project' => $project,
                'people' => $assignments->map(function ($row) {
                    $team = $row->worker?->planName();
                    $present = $row->presentNamesLabel();

                    return $present ? $team.': '.$present : $row->worker?->displayName();
                })->unique()->values(),
                'work' => $project?->workItems->where('status', 'in_uitvoering')->pluck('name') ?? collect(),
            ];
        })->values();

        $running = Project::query()
            ->accessibleBy($user)
            ->active()
            ->with(['workItems.progressEntries', 'assignments', 'workActivities.category', 'customer'])
            ->where('status', ProjectStatus::InUitvoering)
            ->orderBy('planned_end_date')
            ->get();

        $upcoming = Project::query()
            ->accessibleBy($user)
            ->active()
            ->with(['assignments', 'workOrders', 'workItems', 'workActivities.category', 'customer'])
            ->whereIn('status', [ProjectStatus::Gepland, ProjectStatus::NietGestart])
            ->orderBy('planned_start_date')
            ->get();

        return view('dashboard.index', [
            'today' => $today,
            'todayBlocks' => $todayBlocks,
            'running' => $running,
            'upcoming' => $upcoming,
            'overview' => $overview->summarize($today, $todayBlocks, $running, $upcoming),
        ]);
    }
}
