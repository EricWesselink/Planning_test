<?php

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\User;

class TimeEntryPolicy
{
    public function create(User $user): bool
    {
        return $user->isVakman() && $user->canRegisterHours();
    }

    public function update(User $user, TimeEntry $timeEntry): bool
    {
        if ($user->canReviewHours()) {
            return true;
        }

        return $this->ownsOpen($user, $timeEntry);
    }

    public function review(User $user, TimeEntry $timeEntry): bool
    {
        return $user->canReviewHours() && ! $timeEntry->isApproved();
    }

    public function approve(User $user, TimeEntry $timeEntry): bool
    {
        return $user->canReviewHours();
    }

    public function view(User $user, TimeEntry $timeEntry): bool
    {
        return $user->canReviewHours() || $timeEntry->belongsToVakman($user);
    }

    private function ownsOpen(User $user, TimeEntry $timeEntry): bool
    {
        return $user->isVakman()
            && $timeEntry->belongsToVakman($user)
            && ! $timeEntry->isApproved();
    }
}
