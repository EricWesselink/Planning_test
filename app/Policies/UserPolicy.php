<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canViewUsers();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->canViewUsers();
    }

    public function create(User $actor): bool
    {
        return $actor->canManageUsers();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->canManageUsers();
    }

    public function updatePermissions(User $actor, User $user): bool
    {
        return $actor->canManageUsers() && ! $actor->is($user);
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

    public function impersonate(User $actor, User $user): bool
    {
        return $actor->canManageUsers()
            && ! $actor->is($user)
            && $user->active;
    }
}
