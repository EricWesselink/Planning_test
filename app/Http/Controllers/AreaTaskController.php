<?php

namespace App\Http\Controllers;

use App\Enums\AreaStatus;
use App\Enums\WorkPhase;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\Worker;
use App\Models\WorkItem;
use App\Services\ProjectBoardService;
use App\Services\RoomWorkSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AreaTaskController extends Controller
{
    public function complete(Request $request, Project $project, AreaTask $areaTask, ProjectBoardService $board): RedirectResponse|JsonResponse
    {
        $areaTask->loadMissing(['area', 'workItem']);
        abort_unless((int) $areaTask->area?->project_id === (int) $project->id, 404);
        Gate::authorize('view', $project);
        Gate::authorize('enter-progress');

        $data = $request->validate([
            'worker_id' => [
                $request->user()?->scheduledWorkerId() === null ? 'required' : 'nullable',
                'exists:workers,id',
            ],
            'date' => ['required', 'date'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $posted = isset($data['worker_id']) && is_numeric($data['worker_id']) ? (int) $data['worker_id'] : null;
        $workerId = $request->user()?->scheduledWorkerId() ?? $posted;
        abort_unless($workerId !== null && $workerId > 0, 422, 'Kies een vakman.');
        $worker = Worker::query()->findOrFail($workerId);

        $quantity = isset($data['quantity']) && $data['quantity'] !== null && (float) $data['quantity'] > 0
            ? (float) $data['quantity']
            : null;

        try {
            $areaTask->markDone(
                $worker,
                $data['date'],
                $request->user(),
                $quantity,
                (float) ($data['hours'] ?? 0),
                $data['note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['worker_id' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            $areaTask->area->load(['tasks.workItem', 'tasks.completedByWorker', 'floor', 'markers', 'project.documents']);

            return response()->json($board->areaDetail($areaTask->area));
        }

        return back()->with(
            'status',
            $areaTask->area->label().' · '.$areaTask->workItem->name.' klaar gezet voor '.$worker->displayName().'.'
        );
    }

    public function tick(Request $request, Project $project, ProjectArea $area): RedirectResponse
    {
        abort_unless((int) $area->project_id === (int) $project->id, 404);
        Gate::authorize('view', $project);
        Gate::authorize('enter-progress');

        $data = $request->validate([
            'worker_id' => [
                $request->user()?->scheduledWorkerId() === null ? 'required' : 'nullable',
                'exists:workers,id',
            ],
            'date' => ['required', 'date'],
            'phase' => ['required', Rule::enum(WorkPhase::class)],
        ]);

        $phase = WorkPhase::from($data['phase']);
        $posted = isset($data['worker_id']) && is_numeric($data['worker_id']) ? (int) $data['worker_id'] : null;
        $workerId = $request->user()?->scheduledWorkerId() ?? $posted;
        abort_unless($workerId !== null && $workerId > 0, 422, 'Kies een vakman.');
        $worker = Worker::query()->findOrFail($workerId);
        $area->load(['tasks.workItem', 'project.workItems']);

        $tasks = $area->tasksForPhase($phase);
        if ($phase === WorkPhase::Egaliseren && $tasks->isEmpty()) {
            $tasks = collect([$this->createEgaliserenTask($area)]);
        }

        if ($tasks->isEmpty()) {
            return back()->withErrors(['phase' => $phase->label().' staat niet op deze ruimte.']);
        }

        $done = 0;
        foreach ($tasks as $task) {
            if ($task->isDone()) {
                continue;
            }

            try {
                $task->markDone($worker, $data['date'], $request->user());
                $done++;
            } catch (\RuntimeException $e) {
                return back()->withErrors(['worker_id' => $e->getMessage()]);
            }
        }

        $status = $done === 0
            ? $area->label().' · '.$phase->label().' was al klaar.'
            : $area->label().' · '.$phase->label().' afgevinkt voor '.$worker->displayName().'.';

        return back()->with('status', $status);
    }

    private function createEgaliserenTask(ProjectArea $area): AreaTask
    {
        app(RoomWorkSetup::class)->ensureArea($area->fresh(['tasks.workItem', 'project.workItems']));
        $area->unsetRelation('tasks');
        $area->load('tasks.workItem');

        $task = $area->tasks->first(fn (AreaTask $item) => $item->phase() === WorkPhase::Egaliseren);
        if ($task !== null) {
            return $task;
        }

        $project = $area->project;
        $item = $project->workItems->first(fn (WorkItem $work) => $work->phase() === WorkPhase::Egaliseren);

        if ($item === null) {
            $item = WorkItem::query()->create([
                'project_id' => $project->id,
                'name' => RoomWorkSetup::PRIMEN_EGALISEREN,
                'unit' => WorkUnit::SquareMeter,
                'ordered_quantity' => 0,
                'planned_start_date' => $project->planned_start_date,
                'planned_end_date' => $project->planned_end_date,
                'status' => 'gepland',
                'sort_order' => 0,
            ]);
        }

        $quantity = app(RoomWorkSetup::class)->primingMetersFromFlooring($area);
        if ($quantity <= 0) {
            $quantity = (float) $area->square_meters;
        }
        $item->ordered_quantity = round((float) $item->ordered_quantity + $quantity, 2);
        $item->save();

        return AreaTask::query()->create([
            'project_area_id' => $area->id,
            'work_item_id' => $item->id,
            'ordered_quantity' => $quantity,
            'quantity_source' => $quantity > 0 ? RoomWorkSetup::DERIVED_FLOORING_SOURCE : null,
            'unit' => WorkUnit::SquareMeter,
            'status' => AreaStatus::NietGestart,
        ]);
    }
}
