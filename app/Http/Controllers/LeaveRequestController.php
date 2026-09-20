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

    public function show(Request $request, LeaveRequest $leaveRequest, LeaveRequestService $leaveRequests): View|RedirectResponse
    {
        abort_if($request->user()?->isVakman() && (int) $leaveRequest->user_id !== (int) $request->user()->id, 404);
        if ($request->user()?->isVakman()) {
            return redirect()->route('vakman.leave-requests.show', $leaveRequest);
        }
        Gate::authorize('view', $leaveRequest);
        $leaveRequest->load(['user', 'worker', 'reviewer', 'availability', 'periodAdjuster', 'messages.user']);

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

    public function message(Request $request, LeaveRequest $leaveRequest, LeaveRequestService $leaveRequests): RedirectResponse
    {
        Gate::authorize('message', $leaveRequest);

        $body = trim($request->string('body')->toString());
        $request->merge(['body' => $body]);
        $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => 'Schrijf een bericht.',
        ]);

        $leaveRequests->postMessage($leaveRequest, $request->user(), $body);

        return redirect()
            ->route('leave-requests.show', $leaveRequest)
            ->with('status', 'Je bericht is verstuurd.');
    }

    public function adjustPeriod(Request $request, LeaveRequest $leaveRequest, LeaveRequestService $leaveRequests): RedirectResponse
    {
        Gate::authorize('adjustPeriod', $leaveRequest);

        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ], [
            'starts_on.required' => 'Kies een begindatum.',
            'ends_on.required' => 'Kies een einddatum.',
            'ends_on.after_or_equal' => 'De einddatum mag niet voor de begindatum liggen.',
        ]);

        $leaveRequests->adjustPeriod($leaveRequest, $request->user(), (string) $data['starts_on'], (string) $data['ends_on']);

        return redirect()
            ->route('leave-requests.show', $leaveRequest)
            ->with('status', 'De periode is aangepast. De vakman is per e-mail op de hoogte gebracht.');
    }

    public function destroy(LeaveRequest $leaveRequest): RedirectResponse
    {
        Gate::authorize('delete', $leaveRequest);

        $leaveRequest->delete();

        return redirect()
            ->route('leave-requests.index')
            ->with('status', 'De aanvraag is verwijderd.');
    }
}
