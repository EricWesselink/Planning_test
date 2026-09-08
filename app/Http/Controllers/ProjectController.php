<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderType;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\WorkActivityCategory;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Services\ProjectBoardService;
use App\Services\ProjectIntakeService;
use App\Services\RoomWorkSetup;
use App\Support\PlanningWeek;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $projects = Project::query()
            ->accessibleBy($request->user())
            ->active()
            ->with(['customer', 'workActivities.category', 'workItems.progressEntries', 'assignments.worker', 'assignments.crewMembers'])
            ->orderByDesc('planned_start_date')
            ->get();

        return view('projects.index', compact('projects'));
    }

    public function archived(Request $request): View
    {
        $projects = Project::query()
            ->accessibleBy($request->user())
            ->archived()
            ->with(['customer', 'workActivities.category', 'workItems.progressEntries', 'assignments.worker', 'assignments.crewMembers'])
            ->orderByDesc('archived_at')
            ->get();

        return view('projects.archive', compact('projects'));
    }

    public function archive(Project $project): RedirectResponse
    {
        Gate::authorize('archive', $project);
        $project->archive();

        return redirect()
            ->route('projects.archived')
            ->with('status', $project->name.' staat in het archief.');
    }

    public function restore(Project $project): RedirectResponse
    {
        Gate::authorize('restore', $project);
        $project->restoreFromArchive();

        return redirect()
            ->route('projects.index')
            ->with('status', $project->name.' is teruggezet naar projecten.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);
        $name = $project->name;
        $target = $project->isArchived() ? 'projects.archived' : 'projects.index';
        $project->purge();

        return redirect()
            ->route($target)
            ->with('status', $name.' is verwijderd.');
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $validator = Validator::make($request->all(), [
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:255'],
            ...PlanningWeek::rules(),
        ], PlanningWeek::messages());
        $validator->after(fn ($weekValidator) => PlanningWeek::validateOrder(
            $weekValidator,
            $project->planned_start_date,
            $project->planned_end_date,
        ));
        $data = $validator->validate();

        $project->update(collect($data)->only(['address', 'postal_code', 'city'])->all());

        if ($request->hasAny(['start_year', 'start_week', 'klaar_year', 'klaar_week', 'start_date', 'klaar_date'])) {
            $project->applyPlanningWindow($data);
        }

        return back()->with('status', 'Project opgeslagen.');
    }

    public function create(): View
    {
        Gate::authorize('create', Project::class);

        return view('projects.create', [
            'maxFileMegabytes' => (int) (config('filesystems.project_file_max_kilobytes') / 1024),
        ]);
    }

    public function store(Request $request, ProjectIntakeService $intake): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $validator = Validator::make($request->all(), [
            'project_number' => ['nullable', 'string', 'max:32', 'unique:projects,project_number'],
            'name' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string'],
            'plattegrond' => ['nullable', 'file', 'max:'.(int) config('filesystems.project_file_max_kilobytes'), 'mimes:pdf,jpg,jpeg,png,webp', 'extensions:pdf,jpg,jpeg,png,webp'],
            'meetstaat' => ['nullable', 'file', 'max:'.(int) config('filesystems.project_file_max_kilobytes'), 'mimes:csv,txt,xlsx,xlsm,xls,pdf', 'extensions:csv,txt,xlsx,xlsm,xls,pdf'],
            'excel' => ['nullable', 'file', 'max:'.(int) config('filesystems.project_file_max_kilobytes'), 'mimes:csv,txt,xlsx,xlsm', 'extensions:csv,txt,xlsx,xlsm'],
            ...PlanningWeek::rules(),
        ], PlanningWeek::messages());
        $validator->after(fn ($weekValidator) => PlanningWeek::validateOrder($weekValidator));
        $data = PlanningWeek::applyTo($validator->validate());

        $result = $intake->create(
            $data,
            $request->file('plattegrond'),
            $request->file('meetstaat'),
            $request->user(),
            $request->file('excel'),
        );

        $status = 'Project aangemaakt.';
        if ($result['screen_summary']) {
            $status .= ' '.$result['screen_summary'];
            if ($result['screen_ready']) {
                $status .= '. Klaar voor planning.';
            }
        } elseif ($result['rooms'] > 0) {
            $status .= ' '.$result['rooms'].' ruimtes en '.$result['works'].' werkzaamheden ingelezen.';
        }

        return redirect()
            ->route('projects.show', $result['project'])
            ->with('status', $status)
            ->with('warnings', $result['warnings']);
    }

    public function show(Request $request, Project $project, ProjectBoardService $board, RoomWorkSetup $setup): View
    {
        Gate::authorize('view', $project);

        if ($project->isWinkel()) {
            $project->load(['customer', 'workActivities.category', 'documents', 'assignments.worker']);

            return view('projects.winkel', [
                'project' => $project,
                'categories' => WorkActivityCategory::formCatalog(
                    $project->workActivities->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()
                ),
                'maxFileMegabytes' => (int) (config('filesystems.project_file_max_kilobytes') / 1024),
            ]);
        }

        $setup->ensureProject($project);
        $workers = Worker::query()->where('active', true)->orderBy('name')->get();
        $scheduledWorkerId = $request->user()?->scheduledWorkerId();
        if ($scheduledWorkerId !== null) {
            $workers = $workers->where('id', $scheduledWorkerId)->values();
        }
        $payload = $board->payload($project);
        $payload['workers'] = $workers->map(fn (Worker $worker) => [
            'id' => $worker->id,
            'name' => $worker->planName(),
            'full' => $worker->displayName(),
            'email' => $worker->email,
        ])->values()->all();
        $payload['canDeleteSnags'] = $request->user()?->canDeleteSnags() ?? false;
        $payload['canCloseSnags'] = $request->user()?->canCloseSnags() ?? false;
        $payload['canCreateSnags'] = $request->user()?->canCreateSnags() ?? false;
        $payload['canEnterProgress'] = $request->user()?->canEnterProgress() ?? false;
        $payload['canApproveProgress'] = $request->user()?->canApproveProgress() ?? false;
        $payload['lockedWorkerId'] = $scheduledWorkerId;
        $payload['canAdvanceSnagStatus'] = $request->user()?->canAdvanceSnagStatus() ?? false;
        $payload['canManuallyLinkRooms'] = $request->user()?->canManuallyLinkRooms() ?? false;
        $payload['routes'] = [
            'area' => route('projects.areas.show', [$project, '__AREA__']),
            'complete' => route('projects.tasks.complete', [$project, '__TASK__']),
            'group' => route('projects.areas.group', [$project, '__AREA__']),
            'process' => route('projects.areas.process', [$project, '__AREA__']),
            'reopen' => route('projects.areas.reopen', [$project, '__AREA__']),
            'approve' => route('projects.areas.approve', [$project, '__AREA__']),
            'processMany' => route('projects.work.process', $project),
            'reopenMany' => route('projects.work.reopen', $project),
            'approveMany' => route('projects.work.approve', $project),
            'detect' => $project->plattegrond()
                ? route('projects.drawings.detect', [$project, $project->plattegrond()])
                : null,
            'place' => ($payload['canManuallyLinkRooms'] ?? false)
                ? route('projects.areas.marker', [$project, '__AREA__'])
                : null,
            'snagStore' => route('projects.snags.store', $project),
            'snagShow' => route('projects.snags.show', [$project, '__SNAG__']),
            'snagUpdate' => route('projects.snags.update', [$project, '__SNAG__']),
            'snagDestroy' => route('projects.snags.destroy', [$project, '__SNAG__']),
            'snagPhoto' => route('projects.snags.photos.store', [$project, '__SNAG__']),
            'snagReport' => route('projects.snags.report', [$project, '__SNAG__']),
            'snagApprove' => route('projects.snags.approve', [$project, '__SNAG__']),
            'snagReject' => route('projects.snags.reject', [$project, '__SNAG__']),
            'snagList' => route('projects.snags.index', $project),
            'snagExport' => route('projects.snags.export', $project),
        ];
        $payload['csrf'] = csrf_token();
        $firstArea = $project->areas->first();
        $firstDetail = $firstArea ? $board->areaDetail($firstArea) : null;
        $openSnagId = $request->integer('snag') ?: null;
        $project->loadMissing('workItems');

        $todayPresence = $project->assignments()
            ->with(['worker', 'crewMembers'])
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->orderBy('id')
            ->get()
            ->map(fn ($assignment) => [
                'worker' => $assignment->worker?->planName() ?? 'Onbekend',
                'present' => $assignment->presentNamesLabel() ?? $assignment->peopleCountLabel(),
            ])
            ->unique(fn (array $row): string => $row['worker'].'|'.$row['present'])
            ->values();

        return view('projects.show', [
            'project' => $project,
            'workers' => $workers,
            'orderTypes' => WorkOrderType::cases(),
            'board' => $payload,
            'selectedAreaId' => $firstArea?->id,
            'firstDetail' => $firstDetail,
            'openSnagId' => $openSnagId,
            'todayPresence' => $todayPresence,
        ]);
    }

    public function storeMeetstaat(Request $request, Project $project, ProjectIntakeService $intake): RedirectResponse
    {
        Gate::authorize('update', $project);
        $request->validate([
            'meetstaat' => ['required', 'file', 'max:'.(int) config('filesystems.project_file_max_kilobytes'), 'mimes:csv,txt,xlsx,xlsm,xls,pdf', 'extensions:csv,txt,xlsx,xlsm,xls,pdf'],
        ]);

        $result = $intake->importMeetstaat($project, $request->file('meetstaat'), $request->user());

        $status = $result['rooms'] > 0
            ? $result['rooms'].' ruimtes ingelezen.'
            : 'Meetstaat opgeslagen.';
        if ($result['screen_summary']) {
            $status = $result['screen_summary'];
            if ($result['screen_ready']) {
                $status .= '. Klaar voor planning.';
            }
        }

        return back()->with('status', $status)->with('warnings', $result['warnings']);
    }

    public function storePlattegrond(Request $request, Project $project, ProjectIntakeService $intake): RedirectResponse
    {
        Gate::authorize('update', $project);
        $request->validate([
            'plattegrond' => ['required', 'file', 'max:'.(int) config('filesystems.project_file_max_kilobytes'), 'mimes:pdf,jpg,jpeg,png,webp', 'extensions:pdf,jpg,jpeg,png,webp'],
        ]);

        $intake->storeDocument($project, $request->file('plattegrond'), 'plattegrond', $request->user());

        return back()->with('status', 'Plattegrond opgeslagen.');
    }

    public function document(Project $project, ProjectDocument $document): StreamedResponse
    {
        Gate::authorize('view', $project);
        abort_unless($document->project_id === $project->id, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->response(
            $document->file_path,
            $document->original_filename,
            ['Content-Type' => $document->mime_type ?: 'application/octet-stream']
        );
    }

    public function template(): StreamedResponse
    {
        Gate::authorize('create', Project::class);
        $csv = implode("\n", [
            'nummer,naam,verdieping,m2,egaliseren,pvc,plinten',
            '01,Showroom,Begane grond,860,860,860,45',
            '02,Magazijn,Begane grond,420,420,420,28',
            '03,Kantoor,Begane grond,48,48,48,22',
            '1.01,Vergaderzaal,1e verdieping,32,32,32,16',
        ])."\n";

        return response()->streamDownload(
            static fn () => print ($csv),
            'meetstaat-voorbeeld.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function storeOrder(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate([
            'worker_id' => ['required', 'exists:workers,id'],
            'assignment_type' => ['required', 'in:project,work_item,partial'],
            'work_item_id' => ['nullable', Rule::exists('work_items', 'id')->where('project_id', $project->id)],
            'assigned_quantity' => ['nullable', 'numeric'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $item = $project->workItems()->find($data['work_item_id'] ?? 0);

        WorkOrder::query()->create([
            'project_id' => $project->id,
            'work_item_id' => $item?->id,
            'worker_id' => $data['worker_id'],
            'assignment_type' => $data['assignment_type'],
            'assigned_quantity' => $data['assigned_quantity'] ?? null,
            'unit' => $item?->unit,
            'unit_price' => $data['unit_price'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => 'gepland',
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Opdracht gekoppeld.');
    }
}
