<?php

namespace App\Http\Controllers;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class LeaveRequestController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', LeaveRequest::class);

        $requests = LeaveRequest::query()
            ->with(['user', 'worker', 'reviewer'])
            ->orderByRaw('status = ? desc', [LeaveRequestStatus::Pending->value])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        return view('leave-requests.index', [
            'requests' => $requests,
            'pendingCount' => $requests->where('status', LeaveRequestStatus::Pending)->count(),
        ]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest, LeaveRequestService $leaveRequests): View
    {
        abort_if($request->user()?->isVakman() && (int) $leaveRequest->user_id !== (int) $request->user()->id, 404);
        Gate::authorize('view', $leaveRequest);
        $leaveRequest->load(['user', 'worker', 'reviewer', 'availability']);

        return view('leave-requests.show', [
            'leaveRequest' => $leaveRequest,
            'conflicts' => $leaveRequests->planningConflicts($leaveRequest),
        ]);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest, LeaveRequestService $leaveRequests): RedirectResponse
    {
        Gate::authorize('review', $leaveRequest);

        $approved = $leaveRequests->approve($leaveRequest, $request->user());
        $leaveRequests->notifyApproved($approved);

        return redirect()
            ->route('leave-requests.show', $approved)
            ->with('status', 'Aanvraag goedgekeurd. De afwezigheid staat nu in de planning.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest, LeaveRequestService $leaveRequests): RedirectResponse
    {
        Gate::authorize('review', $leaveRequest);

        $data = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $reason = filled($data['rejection_reason'] ?? null) ? trim((string) $data['rejection_reason']) : null;

        $rejected = $leaveRequests->reject($leaveRequest, $request->user(), $reason);
        $leaveRequests->notifyRejected($rejected);

        return redirect()
            ->route('leave-requests.show', $rejected)
            ->with('status', 'Aanvraag afgewezen. De planning is ongewijzigd.');
    }
}
