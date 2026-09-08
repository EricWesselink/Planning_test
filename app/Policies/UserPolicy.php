<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageUsers();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->canManageUsers();
    }

    public function create(User $actor): bool
    {
        return $actor->canManageUsers();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->canManageUsers();
    }

    public function deactivate(User $actor, User $user): bool
    {
        return $actor->canManageUsers()
            && ! $actor->is($user)
            && ! $user->isLastActiveAdmin();
    }

    public function demote(User $actor, User $user): bool
    {
        return $actor->canManageUsers() && ! $user->isLastActiveAdmin();
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->canManageUsers()
            && ! $actor->is($user)
            && ! $user->isLastActiveAdmin();
    }

    public function resetPassword(User $actor, User $user): bool
    {
        return $actor->canManageUsers();
    }
}
