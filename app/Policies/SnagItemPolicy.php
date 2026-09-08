<?php

namespace App\Policies;

use App\Models\SnagItem;
use App\Models\User;

class SnagItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SnagItem $snagItem): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canCreateSnags();
    }

    public function update(User $user, SnagItem $snagItem): bool
    {
        return $user->canUpdateSnags();
    }

    public function report(User $user, SnagItem $snagItem): bool
    {
        return $user->canReportSnags();
    }

    public function close(User $user, SnagItem $snagItem): bool
    {
        return $user->canCloseSnags();
    }

    public function reject(User $user, SnagItem $snagItem): bool
    {
        return $user->canRejectSnags();
    }

    public function delete(User $user, SnagItem $snagItem): bool
    {
        return $user->canDeleteSnags();
    }
}
