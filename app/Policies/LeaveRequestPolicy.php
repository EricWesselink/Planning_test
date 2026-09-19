<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewLeaveRequests();
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->canViewLeaveRequests() || $this->owns($user, $leaveRequest);
    }

    public function create(User $user): bool
    {
        return $user->canRequestLeave();
    }

    public function withdraw(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->owns($user, $leaveRequest) && $leaveRequest->isPending();
    }

    public function review(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->canReviewLeaveRequests() && $leaveRequest->isPending();
    }

    public function message(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $leaveRequest->isPending()) {
            return false;
        }

        return $this->owns($user, $leaveRequest) || $user->canReviewLeaveRequests();
    }

    public function adjustPeriod(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->canReviewLeaveRequests() && $leaveRequest->isPending();
    }

    private function owns(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->isVakman() && (int) $leaveRequest->user_id === (int) $user->id;
    }
}
