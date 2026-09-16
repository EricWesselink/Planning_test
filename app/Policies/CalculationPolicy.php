<?php

namespace App\Policies;

use App\Models\Calculation;
use App\Models\User;

class CalculationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageProjects();
    }

    public function view(User $user, Calculation $calculation): bool
    {
        return $user->canManageProjects();
    }

    public function create(User $user): bool
    {
        return $user->canManageProjects();
    }

    public function update(User $user, Calculation $calculation): bool
    {
        return $user->canManageProjects();
    }

    public function delete(User $user, Calculation $calculation): bool
    {
        return $user->canManageProjects();
    }
}
