<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->canAccessProject($project);
    }

    public function create(User $user): bool
    {
        return $user->canManageProjects();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->canManageProjects() && $user->canAccessProject($project);
    }

    public function archive(User $user, Project $project): bool
    {
        return $user->canArchiveProjects() && $user->canAccessProject($project);
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->canArchiveProjects() && $user->canAccessProject($project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->canPurgeProjects() && $user->canAccessProject($project);
    }
}
