<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class VakmanLeaveRequestController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->vakman($request);
        Gate::authorize('create', LeaveRequest::class);

        $requests = $user->leaveRequests()
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get();

        return view('vakman.leave-requests', [
            'worker' => $user->worker,
            'requests' => $requests,
        ]);
    }

    public function store(Request $request, LeaveRequestService $leaveRequests): RedirectResponse
    {
        $user = $this->vakman($request);
        Gate::authorize('create', LeaveRequest::class);
        abort_if($user->scheduledWorkerId() === null, 403);

        $span = $request->string('span')->toString() === 'range' ? 'range' : 'single';
        $start = $span === 'range' ? 'starts_on' : 'date';
        $end = $span === 'range' ? 'ends_on' : 'date';

        $data = $request->validate([
            'span' => ['required', 'in:single,range'],
            'date' => ['required_if:span,single', 'nullable', 'date'],
            'starts_on' => ['required_if:span,range', 'nullable', 'date'],
            'ends_on' => ['required_if:span,range', 'nullable', 'date', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'date.required_if' => 'Kies een vrije dag.',
            'starts_on.required_if' => 'Kies een begindatum.',
            'ends_on.required_if' => 'Kies een einddatum.',
            'ends_on.after_or_equal' => 'De einddatum mag niet voor de begindatum liggen.',
        ]);

        $startsOn = (string) $data[$start];
        $endsOn = (string) $data[$end];
        $note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;

        $leaveRequests->submit($user, $startsOn, $endsOn, $note);

        return redirect()
            ->route('vakman.leave-requests.index')
            ->with('status', 'Je aanvraag is verstuurd en wacht op goedkeuring.');
    }

    public function withdraw(Request $request, LeaveRequest $leaveRequest, LeaveRequestService $leaveRequests): RedirectResponse
    {
        $user = $this->vakman($request);
        abort_if((int) $leaveRequest->user_id !== (int) $user->id, 404);
        Gate::authorize('withdraw', $leaveRequest);

        $leaveRequests->withdraw($leaveRequest);

        return redirect()
            ->route('vakman.leave-requests.index')
            ->with('status', 'De aanvraag is ingetrokken.');
    }

    private function vakman(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->isVakman(), 403);
        $user->loadMissing('worker');

        return $user;
    }
}
