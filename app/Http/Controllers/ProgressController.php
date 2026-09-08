<?php

namespace App\Http\Controllers;

use App\Models\WorkItem;
use App\Models\WorkProgressEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProgressController extends Controller
{
    public function create(Request $request): RedirectResponse
    {
        return redirect()->route('production.index', array_filter([
            'project_id' => $request->integer('project_id') ?: null,
            'worker_id' => $request->integer('worker_id') ?: null,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('enter-progress');
        $data = $request->validate([
            'date' => ['required', 'date'],
            'work_item_id' => ['required', 'exists:work_items,id'],
            'worker_id' => [
                $request->user()?->scheduledWorkerId() === null ? 'required' : 'nullable',
                'exists:workers,id',
            ],
            'completed_quantity' => ['required', 'numeric', 'min:0'],
            'worked_hours' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $item = WorkItem::query()->with('project')->findOrFail($data['work_item_id']);
        Gate::authorize('view', $item->project);

        $posted = isset($data['worker_id']) && is_numeric($data['worker_id']) ? (int) $data['worker_id'] : null;
        $workerId = $request->user()?->scheduledWorkerId() ?? $posted;
        abort_unless($workerId !== null && $workerId > 0, 422, 'Kies een vakman.');

        WorkProgressEntry::query()->create([
            'project_id' => $item->project_id,
            'work_item_id' => $item->id,
            'worker_id' => $workerId,
            'date' => $data['date'],
            'completed_quantity' => $data['completed_quantity'],
            'unit' => $item->unit,
            'worked_hours' => $data['worked_hours'] ?? 0,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $item->syncStatusFromProgress();

        return redirect()
            ->route('production.index', [
                'project_id' => $item->project_id,
                'worker_id' => $workerId,
            ])
            ->with('status', 'Productie opgeslagen.');
    }
}
