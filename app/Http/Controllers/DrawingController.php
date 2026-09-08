<?php

namespace App\Http\Controllers;

use App\Enums\AreaStatus;
use App\Models\AreaDrawingMarker;
use App\Models\AreaTask;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\Worker;
use App\Services\ProjectBoardService;
use App\Services\RoomMarkerMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DrawingController extends Controller
{
    public function detect(Request $request, Project $project, ProjectDocument $document, RoomMarkerMatcher $matcher, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $document->project_id === (int) $project->id, 404);
        Gate::authorize('update', $project);

        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.page' => ['required', 'integer', 'min:1'],
            'items.*.text' => ['required', 'string', 'max:160'],
            'items.*.x' => ['required', 'numeric', 'between:0,1'],
            'items.*.y' => ['required', 'numeric', 'between:0,1'],
            'items.*.w' => ['nullable', 'numeric', 'between:0,1'],
            'items.*.h' => ['nullable', 'numeric', 'between:0,1'],
            'items.*.width' => ['nullable', 'numeric', 'between:0,1'],
            'items.*.height' => ['nullable', 'numeric', 'between:0,1'],
            'items.*.source' => ['nullable', 'string', 'in:text,ocr,auto'],
            'rooms' => ['nullable', 'array'],
            'rooms.*.number' => ['required', 'string', 'max:32'],
            'rooms.*.page' => ['required', 'integer', 'min:1'],
            'rooms.*.x' => ['required', 'numeric', 'between:0,1'],
            'rooms.*.y' => ['required', 'numeric', 'between:0,1'],
            'rooms.*.w' => ['nullable', 'numeric', 'between:0,1'],
            'rooms.*.h' => ['nullable', 'numeric', 'between:0,1'],
            'rooms.*.width' => ['nullable', 'numeric', 'between:0,1'],
            'rooms.*.height' => ['nullable', 'numeric', 'between:0,1'],
            'rooms.*.label_text' => ['nullable', 'string', 'max:160'],
            'rooms.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'rooms.*.source' => ['nullable', 'string', 'in:text,ocr,auto'],
        ]);

        if (empty($data['items']) && empty($data['rooms'])) {
            return response()->json(['message' => 'Geen ruimtes of tekstitems ontvangen.'], 422);
        }

        $result = $matcher->match($project, $document, $data['items'] ?? [], $data['rooms'] ?? []);
        $project->load(['areas.floor', 'areas.markers', 'areas.tasks.workItem', 'areas.tasks.completedByWorker']);

        return response()->json([
            ...$result,
            'areas' => $board->areaSummaries($project->areas, $document),
        ]);
    }

    public function area(Project $project, ProjectArea $area, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $area->project_id === (int) $project->id, 404);
        Gate::authorize('view', $project);

        return response()->json($board->areaDetail($area));
    }

    public function place(Request $request, Project $project, ProjectArea $area): JsonResponse
    {
        abort_unless((int) $area->project_id === (int) $project->id, 404);
        Gate::authorize('update', $project);

        $data = $request->validate([
            'document_id' => ['required', 'exists:project_documents,id'],
            'page' => ['required', 'integer', 'min:1'],
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
            'width' => ['nullable', 'numeric', 'between:0,1'],
            'height' => ['nullable', 'numeric', 'between:0,1'],
            'w' => ['nullable', 'numeric', 'between:0,1'],
            'h' => ['nullable', 'numeric', 'between:0,1'],
            'label_text' => ['nullable', 'string', 'max:160'],
            'area_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $document = ProjectDocument::query()->findOrFail($data['document_id']);
        abort_unless((int) $document->project_id === (int) $project->id, 404);

        if (array_key_exists('area_number', $data) || array_key_exists('name', $data)) {
            $number = array_key_exists('area_number', $data)
                ? trim((string) ($data['area_number'] ?? ''))
                : (string) $area->area_number;
            $name = array_key_exists('name', $data)
                ? trim((string) ($data['name'] ?? ''))
                : (string) $area->name;
            $area->area_number = $number !== '' ? $number : null;
            $area->name = $name !== '' ? $name : ($area->area_number ?: 'Ruimte');
            $area->save();
        }

        $width = (float) ($data['width'] ?? $data['w'] ?? 0.08);
        $height = (float) ($data['height'] ?? $data['h'] ?? 0.02);
        $x = max(0, min(1, $data['x'] - ($width / 2)));
        $y = max(0, min(1, $data['y'] - ($height / 2)));

        $payload = [
            'page' => $data['page'],
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'label_text' => $data['label_text'] ?? $area->label(),
            'polygon' => AreaDrawingMarker::sanitizePolygon([
                ['x' => $x, 'y' => $y],
                ['x' => min(1, $x + $width), 'y' => $y],
                ['x' => min(1, $x + $width), 'y' => min(1, $y + $height)],
                ['x' => $x, 'y' => min(1, $y + $height)],
            ]),
            'confidence' => 1,
            'source' => 'manual',
        ];

        $marker = AreaDrawingMarker::query()->updateOrCreate(
            [
                'project_area_id' => $area->id,
                'project_document_id' => $document->id,
            ],
            $payload
        );

        $area->load(['floor', 'markers', 'tasks.workItem', 'tasks.completedByWorker']);

        return response()->json([
            'area' => app(ProjectBoardService::class)->areaSummary($area, $document),
            'marker' => $marker->toBoardArray(),
        ]);
    }

    public function completeGroup(Request $request, Project $project, ProjectArea $area, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $area->project_id === (int) $project->id, 404);
        $this->authorizeProgress($project);

        $data = $request->validate([
            'group' => ['required', 'string', 'in:ondergrond,vloer,plinten,overige'],
            'worker_id' => $this->workerIdRules($request),
            'date' => ['required', 'date'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $area->load(['tasks.workItem', 'project.workItems']);
        $worker = $this->progressWorker($request, $data['worker_id'] ?? null);
        $tasks = $area->tasks->filter(fn ($task) => $task->phase()->group() === $data['group']);

        if ($tasks->isEmpty()) {
            return response()->json(['message' => 'Dit onderdeel staat niet op deze ruimte.'], 422);
        }

        $hours = (float) ($data['hours'] ?? 0);
        try {
            DB::transaction(function () use ($tasks, $worker, $data, $request, $hours) {
                foreach ($tasks as $task) {
                    if ($task->isDone()) {
                        continue;
                    }
                    $task->markDone(
                        $worker,
                        $data['date'],
                        $request->user(),
                        null,
                        $hours,
                        $data['note'] ?? null,
                    );
                    $hours = 0;
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $area->unsetRelation('tasks');
        $area->load(['tasks.workItem', 'tasks.completedByWorker', 'floor', 'markers', 'project.documents']);

        return response()->json($board->areaDetail($area));
    }

    public function process(Request $request, Project $project, ProjectArea $area, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $area->project_id === (int) $project->id, 404);
        $this->authorizeProgress($project);

        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
            'worker_id' => $this->workerIdRules($request),
            'date' => ['required', 'date'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $area->load(['tasks.workItem', 'project.workItems']);
        $ids = collect($data['task_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $tasks = $area->tasks->whereIn('id', $ids->all())->values();

        if ($tasks->count() !== $ids->count()) {
            return response()->json(['message' => 'Een of meer werkzaamheden horen niet bij deze ruimte.'], 422);
        }

        $open = $tasks->filter(fn (AreaTask $task) => ! $task->isDone() && $task->remainingQuantity() > 0)->values();
        if ($open->isEmpty()) {
            return response()->json(['message' => 'Selecteer minimaal één open werkzaamheid.'], 422);
        }

        $worker = $this->progressWorker($request, $data['worker_id'] ?? null);
        $sharedQuantity = $open->count() === 1 && array_key_exists('quantity', $data) && $data['quantity'] !== null && (float) $data['quantity'] > 0
            ? (float) $data['quantity']
            : null;
        $hours = (float) ($data['hours'] ?? 0);

        try {
            DB::transaction(function () use ($open, $worker, $data, $request, $sharedQuantity, $hours) {
                foreach ($open as $index => $task) {
                    $task->markDone(
                        $worker,
                        $data['date'],
                        $request->user(),
                        $sharedQuantity,
                        $index === 0 ? $hours : 0,
                        $data['note'] ?? null,
                    );
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $area->unsetRelation('tasks');
        $area->load(['tasks.workItem', 'tasks.completedByWorker', 'floor', 'markers', 'project.documents']);

        return response()->json($board->areaDetail($area));
    }

    public function reopen(Request $request, Project $project, ProjectArea $area, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $area->project_id === (int) $project->id, 404);
        $this->authorizeProgress($project);

        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
        ]);

        $area->load(['tasks.workItem']);
        $ids = collect($data['task_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $tasks = $area->tasks->whereIn('id', $ids->all())->values();

        if ($tasks->count() !== $ids->count()) {
            return response()->json(['message' => 'Een of meer werkzaamheden horen niet bij deze ruimte.'], 422);
        }

        $done = $tasks->filter(
            fn (AreaTask $task) => $task->isDone() || $task->status === AreaStatus::InUitvoering
        )->values();

        if ($done->isEmpty()) {
            return response()->json(['message' => 'Selecteer een gereed onderdeel om uit te zetten.'], 422);
        }

        if ($this->blocksApprovedReopen($request, $done)) {
            return response()->json(['message' => 'Goedgekeurd werk mag je niet uitzetten.'], 403);
        }

        DB::transaction(function () use ($done) {
            foreach ($done as $task) {
                $task->reopen();
            }
        });

        $area->unsetRelation('tasks');
        $area->load(['tasks.workItem', 'tasks.completedByWorker', 'floor', 'markers', 'project.documents']);

        return response()->json($board->areaDetail($area));
    }

    public function approve(Request $request, Project $project, ProjectArea $area, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $area->project_id === (int) $project->id, 404);
        $this->authorizeApproval($project);

        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
        ]);

        $area->load(['tasks.workItem']);
        $ids = collect($data['task_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $tasks = $area->tasks->whereIn('id', $ids->all())->values();

        if ($tasks->count() !== $ids->count()) {
            return response()->json(['message' => 'Een of meer werkzaamheden horen niet bij deze ruimte.'], 422);
        }

        $provisional = $tasks->filter(fn (AreaTask $task) => $task->isProvisional())->values();
        if ($provisional->isEmpty()) {
            return response()->json(['message' => 'Selecteer voorlopig klaar werk om akkoord te geven.'], 422);
        }

        DB::transaction(function () use ($provisional, $request) {
            foreach ($provisional as $task) {
                $task->approve($request->user());
            }
        });

        $area->unsetRelation('tasks');
        $area->load(['tasks.workItem', 'tasks.completedByWorker', 'floor', 'markers', 'project.documents']);

        return response()->json($board->areaDetail($area));
    }

    public function processMany(Request $request, Project $project, ProjectBoardService $board): JsonResponse
    {
        $this->authorizeProgress($project);
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
            'worker_id' => $this->workerIdRules($request),
            'date' => ['required', 'date'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $tasks = $this->projectTasks($project, $data['task_ids']);
        if ($tasks === null) {
            return response()->json(['message' => 'Een of meer werkzaamheden horen niet bij dit project.'], 422);
        }

        $open = $tasks->filter(fn (AreaTask $task) => ! $task->isDone() && $task->remainingQuantity() > 0)->values();
        if ($open->isEmpty()) {
            return response()->json(['message' => 'Selecteer minimaal één open werkzaamheid.'], 422);
        }

        $worker = $this->progressWorker($request, $data['worker_id'] ?? null);
        $sharedQuantity = $open->count() === 1 && array_key_exists('quantity', $data) && $data['quantity'] !== null && (float) $data['quantity'] > 0
            ? (float) $data['quantity']
            : null;
        $hours = (float) ($data['hours'] ?? 0);

        try {
            DB::transaction(function () use ($open, $worker, $data, $request, $sharedQuantity, $hours) {
                foreach ($open as $index => $task) {
                    $task->markDone(
                        $worker,
                        $data['date'],
                        $request->user(),
                        $sharedQuantity,
                        $index === 0 ? $hours : 0,
                        $data['note'] ?? null,
                    );
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'areas' => $this->detailsForTasks($project, $tasks, $board),
        ]);
    }

    public function reopenMany(Request $request, Project $project, ProjectBoardService $board): JsonResponse
    {
        $this->authorizeProgress($project);
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
        ]);

        $tasks = $this->projectTasks($project, $data['task_ids']);
        if ($tasks === null) {
            return response()->json(['message' => 'Een of meer werkzaamheden horen niet bij dit project.'], 422);
        }

        $done = $tasks->filter(
            fn (AreaTask $task) => $task->isDone() || $task->status === AreaStatus::InUitvoering
        )->values();

        if ($done->isEmpty()) {
            return response()->json(['message' => 'Selecteer een gereed onderdeel om uit te zetten.'], 422);
        }

        if ($this->blocksApprovedReopen($request, $done)) {
            return response()->json(['message' => 'Goedgekeurd werk mag je niet uitzetten.'], 403);
        }

        DB::transaction(function () use ($done) {
            foreach ($done as $task) {
                $task->reopen();
            }
        });

        return response()->json([
            'areas' => $this->detailsForTasks($project, $tasks, $board),
        ]);
    }

    public function approveMany(Request $request, Project $project, ProjectBoardService $board): JsonResponse
    {
        $this->authorizeApproval($project);
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
        ]);

        $tasks = $this->projectTasks($project, $data['task_ids']);
        if ($tasks === null) {
            return response()->json(['message' => 'Een of meer werkzaamheden horen niet bij dit project.'], 422);
        }

        $provisional = $tasks->filter(fn (AreaTask $task) => $task->isProvisional())->values();
        if ($provisional->isEmpty()) {
            return response()->json(['message' => 'Selecteer voorlopig klaar werk om akkoord te geven.'], 422);
        }

        DB::transaction(function () use ($provisional, $request) {
            foreach ($provisional as $task) {
                $task->approve($request->user());
            }
        });

        return response()->json([
            'areas' => $this->detailsForTasks($project, $tasks, $board),
        ]);
    }

    /**
     * @param  list<mixed>  $ids
     * @return Collection<int, AreaTask>|null
     */
    private function projectTasks(Project $project, array $ids): ?Collection
    {
        $wanted = collect($ids)->map(fn ($id) => (int) $id)->unique()->filter()->values();
        if ($wanted->isEmpty()) {
            return null;
        }

        $tasks = AreaTask::query()
            ->with(['area', 'workItem'])
            ->whereIn('id', $wanted->all())
            ->whereHas('area', fn ($query) => $query->where('project_id', $project->id))
            ->orderBy('id')
            ->get();

        if ($tasks->count() !== $wanted->count()) {
            return null;
        }

        return $tasks->values();
    }

    private function authorizeProgress(Project $project): void
    {
        Gate::authorize('view', $project);
        Gate::authorize('enter-progress');
    }

    private function authorizeApproval(Project $project): void
    {
        Gate::authorize('view', $project);
        Gate::authorize('approve-progress');
    }

    /**
     * @return list<string>
     */
    private function workerIdRules(Request $request): array
    {
        return [
            $request->user()?->scheduledWorkerId() === null ? 'required' : 'nullable',
            'exists:workers,id',
        ];
    }

    private function progressWorker(Request $request, mixed $postedWorkerId): Worker
    {
        $id = $request->user()?->scheduledWorkerId() ?? (is_numeric($postedWorkerId) ? (int) $postedWorkerId : null);
        abort_unless($id !== null && $id > 0, 422, 'Kies een vakman.');

        return Worker::query()->findOrFail($id);
    }

    /**
     * @param  Collection<int, AreaTask>  $tasks
     */
    private function blocksApprovedReopen(Request $request, Collection $tasks): bool
    {
        return $request->user()?->isVakman() === true
            && $tasks->contains(fn (AreaTask $task) => $task->isApproved());
    }

    /**
     * @param  Collection<int, AreaTask>  $tasks
     * @return list<array<string, mixed>>
     */
    private function detailsForTasks(Project $project, Collection $tasks, ProjectBoardService $board): array
    {
        $areaIds = $tasks->pluck('project_area_id')->unique()->values();

        return ProjectArea::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $areaIds)
            ->with(['tasks.workItem', 'tasks.completedByWorker', 'floor', 'markers', 'project.documents'])
            ->orderBy('id')
            ->get()
            ->map(fn (ProjectArea $area) => $board->areaDetail($area))
            ->values()
            ->all();
    }
}
