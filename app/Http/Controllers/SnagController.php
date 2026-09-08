<?php

namespace App\Http\Controllers;

use App\Enums\SnagPhotoType;
use App\Enums\SnagPriority;
use App\Enums\SnagStatus;
use App\Models\Project;
use App\Models\SnagItem;
use App\Models\SnagPhoto;
use App\Models\User;
use App\Models\Worker;
use App\Services\ProjectBoardService;
use App\Services\SnagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SnagController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);
        $query = $project->snags()->with(['area.floor', 'assignee', 'photos']);
        $this->applyFilters($query, $request);

        return view('snags.index', [
            'project' => $project->load(['floors', 'customer']),
            'snags' => $query->orderBy('number')->get(),
            'workers' => Worker::query()->where('active', true)->orderBy('name')->get(),
            'filters' => $request->only(['status', 'worker_id', 'floor_id']),
        ]);
    }

    public function store(Request $request, Project $project, SnagService $snags, ProjectBoardService $board): JsonResponse
    {
        Gate::authorize('create', SnagItem::class);
        $data = $request->validate([
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
            'drawing_page' => ['nullable', 'integer', 'min:1'],
            'document_id' => ['nullable', Rule::exists('project_documents', 'id')->where('project_id', $project->id)],
            'project_area_id' => ['nullable', Rule::exists('project_areas', 'id')->where('project_id', $project->id)],
            'description' => ['required', 'string', 'max:500'],
            'assigned_worker_id' => ['required', 'exists:workers,id'],
            'priority' => ['nullable', Rule::enum(SnagPriority::class)],
            'due_date' => ['nullable', 'date'],
            'logged_on' => ['nullable', 'date'],
            'photo' => $this->photoRules(),
            'photos' => ['nullable', 'array', 'max:8'],
            'photos.*' => $this->photoRules(required: true),
            'notify' => ['nullable', 'boolean'],
        ]);

        $project->load(['documents', 'areas.markers']);
        if (empty($data['project_area_id'])) {
            $near = $snags->nearestArea($project, (int) ($data['drawing_page'] ?? 1), (float) $data['x'], (float) $data['y']);
            $data['project_area_id'] = $near?->id;
        }

        $files = $this->uploadedPhotos($request);
        $snag = $snags->create($project, $data, $request->user(), $files);

        if ($request->boolean('notify') && $snag->assignee) {
            $snags->notifyAssignment($snag, $snag->assignee);
            $snag->refresh()->load(['area', 'assignee', 'photos']);
        }

        return response()->json([
            'snag' => $this->detail($project, $snag, $board),
        ], 201);
    }

    public function show(Project $project, SnagItem $snag, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $snag->project_id === (int) $project->id, 404);
        Gate::authorize('view', $snag);
        $snag->load(['area', 'assignee', 'photos', 'history.worker', 'creator']);

        return response()->json([
            'snag' => $this->detail($project, $snag, $board),
        ]);
    }

    public function update(Request $request, Project $project, SnagItem $snag, SnagService $snags, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $snag->project_id === (int) $project->id, 404);
        Gate::authorize('update', $snag);

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
            'assigned_worker_id' => ['nullable', 'exists:workers,id'],
            'priority' => ['nullable', Rule::enum(SnagPriority::class)],
            'due_date' => ['nullable', 'date'],
            'logged_on' => ['nullable', 'date'],
            'project_area_id' => ['nullable', Rule::exists('project_areas', 'id')->where('project_id', $project->id)],
            'status' => ['nullable', Rule::enum(SnagStatus::class)],
            'note' => ['nullable', 'string', 'max:2000'],
            'notify' => ['nullable', 'boolean'],
            'x' => ['nullable', 'numeric', 'between:0,1'],
            'y' => ['nullable', 'numeric', 'between:0,1'],
            'drawing_page' => ['nullable', 'integer', 'min:1'],
            'photo' => $this->photoRules(),
            'photos' => ['nullable', 'array', 'max:8'],
            'photos.*' => $this->photoRules(required: true),
            'photo_type' => ['nullable', Rule::enum(SnagPhotoType::class)],
        ]);
        $this->authorizeStatusChange($request->user(), $snag, $data['status'] ?? null);

        $snag = $snags->update($snag, $data, $request->user(), $this->uploadedPhotos($request));

        if ($request->boolean('notify') && $snag->assignee) {
            $snags->notifyAssignment($snag, $snag->assignee);
            $snag->refresh()->load(['area', 'assignee', 'photos']);
        }

        return response()->json(['snag' => $this->detail($project, $snag, $board)]);
    }

    public function photo(Request $request, Project $project, SnagItem $snag, SnagService $snags, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $snag->project_id === (int) $project->id, 404);
        Gate::authorize('update', $snag);
        $request->validate([
            'photo' => $this->photoRules(required: true),
            'photo_type' => ['nullable', Rule::enum(SnagPhotoType::class)],
        ]);

        $type = $request->input('photo_type') === 'completion' ? SnagPhotoType::Completion : SnagPhotoType::Issue;
        $snags->storePhoto($snag, $request->file('photo'), $type, $request->user());
        $snag->load(['area', 'assignee', 'photos']);

        return response()->json(['snag' => $this->detail($project, $snag, $board)]);
    }

    public function file(Project $project, SnagItem $snag, SnagPhoto $photo): StreamedResponse
    {
        abort_unless((int) $snag->project_id === (int) $project->id, 404);
        abort_unless((int) $photo->snag_item_id === (int) $snag->id, 404);
        Gate::authorize('view', $snag);
        abort_unless(Storage::disk('local')->exists($photo->file_path), 404);

        return Storage::disk('local')->response($photo->file_path, $photo->original_filename);
    }

    public function report(Request $request, Project $project, SnagItem $snag, SnagService $snags, ProjectBoardService $board): JsonResponse
    {
        abort_unless((int) $snag->project_id === (int) $project->id, 404);
        Gate::authorize('report', $snag);
        abort_if($snag->status->isFinished(), 422, 'Dit opleverpunt is al gereed gemeld of afgehandeld.');
        $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'photo' => $this->photoRules(),
            'photos' => ['nullable', 'array', 'max:8'],
            'photos.*' => $this->photoRules(required: true),
        ]);
        try {
            $snag = $snags->reportDone($snag, $request->user(), $request->input('note'), $this->uploadedPhotos($request));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['snag' => $this->detail($project, $snag, $board)]);
    }

    public function approve(Request $request, Project $project, SnagItem $snag, SnagService $snags, ProjectBoardService $board): JsonResponse|RedirectResponse
    {
        abort_unless((int) $snag->project_id === (int) $project->id, 404);
        Gate::authorize('close', $snag);
        $snag = $snags->approve($snag, $request->user());

        if ($request->expectsJson()) {
            return response()->json(['snag' => $this->detail($project, $snag, $board)]);
        }

        return back()->with('status', 'Opleverpunt goedgekeurd.');
    }

    public function reject(Request $request, Project $project, SnagItem $snag, SnagService $snags, ProjectBoardService $board): JsonResponse|RedirectResponse
    {
        abort_unless((int) $snag->project_id === (int) $project->id, 404);
        Gate::authorize('reject', $snag);
        $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'photo' => $this->photoRules(),
            'photos' => ['nullable', 'array', 'max:8'],
            'photos.*' => $this->photoRules(required: true),
        ]);
        $snag = $snags->reject($snag, $request->user(), $request->input('note'), $this->uploadedPhotos($request));

        if ($snag->assignee) {
            $snags->notifyRework($snag, $snag->assignee, $request->input('note'));
        }

        if ($request->expectsJson()) {
            return response()->json(['snag' => $this->detail($project, $snag, $board)]);
        }

        return back()->with('status', 'Opleverpunt teruggestuurd naar de vakman.');
    }

    public function destroy(Request $request, Project $project, SnagItem $snag, SnagService $snags): JsonResponse|RedirectResponse
    {
        abort_unless((int) $snag->project_id === (int) $project->id, 404);
        Gate::authorize('delete', $snag);
        $id = $snag->id;
        $snags->delete($snag);

        if ($request->expectsJson()) {
            return response()->json(['deleted' => $id]);
        }

        return back()->with('status', 'Opleverpunt verwijderd.');
    }

    public function export(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);
        $query = $project->snags()->with(['area.floor', 'assignee', 'photos', 'project.customer']);
        $this->applyFilters($query, $request);

        $snags = $query->orderBy('number')->get();
        $pages = $snags
            ->groupBy(fn (SnagItem $snag) => (int) $snag->drawing_page)
            ->map(fn ($group, $page) => [
                'page' => (int) $page,
                'snags' => $group->values(),
            ])
            ->sortBy('page')
            ->values();

        return view('snags.pdf', [
            'project' => $project->load(['customer', 'supervisor', 'floors']),
            'snags' => $snags,
            'pages' => $pages,
            'includePhotos' => $request->boolean('photos', true),
            'includeDrawing' => $request->boolean('drawing', true),
            'drawing' => $project->plattegrond(),
        ]);
    }

    private function applyFilters($query, Request $request): void
    {
        $statuses = SnagStatus::forFilter($request->string('status')->toString());
        if ($statuses) {
            $query->whereIn('status', $statuses);
        } elseif ($status = $request->string('status')->toString()) {
            if ($status === 'open_all') {
                $query->whereIn('status', SnagStatus::stillOpen());
            } elseif (in_array($status, array_column(SnagStatus::cases(), 'value'), true)) {
                $query->where('status', $status);
            }
        }
        if ($workerId = $request->integer('worker_id')) {
            $query->where('assigned_worker_id', $workerId);
        }
        if ($floorId = $request->integer('floor_id')) {
            $query->where(function ($inner) use ($floorId) {
                $inner->whereHas('area', fn ($q) => $q->where('project_floor_id', $floorId))
                    ->orWhereHas('area.floor', fn ($q) => $q->where('id', $floorId));
            });
        }
        if ($page = $request->integer('page')) {
            $query->where('drawing_page', $page);
        }
    }

    /** @return list<string> */
    private function photoRules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'image',
            'mimes:jpeg,jpg,png,webp,gif',
            'max:20480',
        ];
    }

    /** @return list<UploadedFile> */
    private function uploadedPhotos(Request $request): array
    {
        $files = [];
        if ($request->file('photo') instanceof UploadedFile) {
            $files[] = $request->file('photo');
        }
        foreach ((array) $request->file('photos', []) as $file) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function authorizeStatusChange(User $user, SnagItem $snag, mixed $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        $new = $status instanceof SnagStatus ? $status : SnagStatus::from((string) $status);
        if ($new === $snag->status) {
            return;
        }

        if ($new === SnagStatus::Closed) {
            Gate::authorize('close', $snag);

            return;
        }

        if ($new === SnagStatus::ReportedDone) {
            Gate::authorize('report', $snag);

            return;
        }

        abort_unless($user->canAdvanceSnagStatus(), 403);
    }

    private function detail(Project $project, SnagItem $snag, ProjectBoardService $board): array
    {
        $summary = $board->snagSummary($snag->loadMissing(['area', 'assignee', 'photos']));
        $summary['title'] = $snag->title();
        $summary['description'] = $snag->description;
        $summary['priority'] = $snag->priority->value;
        $summary['due_date'] = $snag->due_date?->toDateString();
        $summary['logged_on'] = $snag->logged_on?->toDateString() ?: $snag->created_at?->toDateString();
        $summary['assigned_worker_id'] = $snag->assigned_worker_id;
        $summary['status_label'] = $snag->status->boardLabel();
        $summary['photos'] = $snag->photos->map(fn (SnagPhoto $photo) => [
            'id' => $photo->id,
            'type' => $photo->photo_type->value,
            'type_label' => $photo->photo_type->label(),
            'url' => route('projects.snags.photo', [$project, $snag, $photo]),
        ])->values()->all();

        return $summary;
    }
}
