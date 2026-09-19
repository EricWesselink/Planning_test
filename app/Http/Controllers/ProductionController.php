<?php

namespace App\Http\Controllers;

use App\Models\AreaTask;
use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use App\Services\ProductionOverviewService;
use App\Services\VoucherDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProductionController extends Controller
{
    public function index(Request $request, ProductionOverviewService $overview, VoucherDraftService $drafts): View
    {
        Gate::authorize('view-production');
        $workerId = $request->integer('worker_id') ?: null;
        $projectId = $request->integer('project_id') ?: null;
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;
        $scheduledWorkerId = $request->user()?->scheduledWorkerId();
        if ($scheduledWorkerId !== null) {
            $workerId = $scheduledWorkerId;
        }

        if ($projectId) {
            Gate::authorize('view', Project::query()->findOrFail($projectId));
        }

        $data = $overview->build($workerId, $projectId, $from, $to, $request->user());
        $filters = [
            'worker_id' => $workerId,
            'project_id' => $projectId,
            'from' => $from,
            'to' => $to,
        ];
        $vouchersByKey = $this->vouchersByKey($request->user(), $workerId, $projectId);
        $allVouchers = $vouchersByKey->collapse();
        $sheetsByKey = $drafts->sheetsByWorkerProject($allVouchers);
        $downloadVoucher = $sheetsByKey->count() === 1
            ? ($sheetsByKey->first()['opdracht'] ?? null)
            : null;

        return view('production.index', [
            'groups' => $data['groups'],
            'totals' => $data['totals'],
            'workers' => $data['workers'],
            'projects' => $data['projects'],
            'filters' => $filters,
            'canCreateVouchers' => $request->user()?->canManageProjects() ?? false,
            'vouchersByKey' => $vouchersByKey,
            'billingByKey' => $drafts->billingByWorkerProject($allVouchers),
            'sheetsByKey' => $sheetsByKey,
            'downloadVoucher' => $downloadVoucher,
            'canApproveProgress' => $request->user()?->canApproveProgress() ?? false,
            'ownWorker' => $workerId
                ? $data['workers']->firstWhere('id', $workerId)
                : null,
            'ownPage' => $scheduledWorkerId !== null || $workerId !== null,
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
        ]);

        $project = Project::query()->findOrFail($data['project_id']);
        Gate::authorize('view', $project);
        Gate::authorize('approve-progress');

        $wanted = collect($data['task_ids'])->map(fn ($id) => (int) $id)->unique()->filter()->values();
        $tasks = AreaTask::query()
            ->with('area')
            ->whereIn('id', $wanted->all())
            ->whereHas('area', fn ($query) => $query->where('project_id', $project->id))
            ->get();

        if ($tasks->count() !== $wanted->count()) {
            return back()->withErrors(['task_ids' => 'Een of meer werkzaamheden horen niet bij dit project.']);
        }

        $provisional = $tasks->filter(fn (AreaTask $task) => $task->isProvisional())->values();
        if ($provisional->isEmpty()) {
            return back()->with('warning', 'Er staat geen voorlopig klaar werk klaar voor akkoord.');
        }

        DB::transaction(function () use ($provisional, $request) {
            foreach ($provisional as $task) {
                $task->approve($request->user());
            }
        });

        $count = $provisional->count();

        return back()->with(
            'status',
            $count === 1
                ? 'Akkoord gegeven. Dit werk is nu definitief.'
                : $count.' onderdelen akkoord. Het werk is nu definitief.'
        );
    }

    /**
     * @return Collection<string, Collection<int, Voucher>>
     */
    private function vouchersByKey(?User $user, ?int $workerId, ?int $projectId): Collection
    {
        return Voucher::query()
            ->with(['worker', 'project', 'lines'])
            ->when($workerId, fn ($query) => $query->where('worker_id', $workerId))
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->when($user, fn ($query) => $query->whereHas('project', fn ($projects) => $projects->accessibleBy($user)))
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (Voucher $voucher): string => $voucher->worker_id.'.'.$voucher->project_id);
    }
}
