<?php

namespace App\Policies;

use App\Models\Calculation;
use App\Models\User;

class CalculationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewCalculations();
    }

    public function view(User $user, Calculation $calculation): bool
    {
        return $user->canViewCalculations();
    }

    public function create(User $user): bool
    {
        return $user->canCreateCalculations();
    }

    public function update(User $user, Calculation $calculation): bool
    {
        return $user->canUpdateCalculations();
    }

    public function delete(User $user, Calculation $calculation): bool
    {
        return $user->canDeleteCalculations();
    }
}
