<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'active', 'can_access_all_projects', 'worker_id', 'crew_member_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
        'can_access_all_projects' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'can_access_all_projects' => 'boolean',
            'role' => UserRole::class,
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }

    public function supervisedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'supervisor_user_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    public function isVakman(): bool
    {
        return $this->role === UserRole::Vakman;
    }

    public function scheduledWorkerId(): ?int
    {
        if (! $this->isVakman() || $this->worker_id === null) {
            return null;
        }

        return (int) $this->worker_id;
    }

    public function canAccessProject(Project $project): bool
    {
        $workerId = $this->scheduledWorkerId();
        if ($this->isVakman()) {
            if ($workerId === null || $project->id === null) {
                return false;
            }

            return WorkerAssignment::query()
                ->where('worker_id', $workerId)
                ->where('project_id', $project->id)
                ->exists();
        }

        if ($this->can_access_all_projects) {
            return true;
        }

        if ($this->relationLoaded('projects')) {
            return $this->projects->contains(
                fn (Project $assigned): bool => (int) $assigned->id === (int) $project->id
            );
        }

        if (! $this->exists || $project->id === null) {
            return false;
        }

        return $this->projects()->whereKey($project->id)->exists();
    }

    public function isLastActiveAdmin(): bool
    {
        if ($this->role !== UserRole::Admin || ! $this->active) {
            return false;
        }

        return static::query()
            ->activeAdmins()
            ->whereKeyNot($this->id)
            ->doesntExist();
    }

    public function scopeActiveAdmins(Builder $query): void
    {
        $query->where('role', UserRole::Admin)->where('active', true);
    }

    public function canManageUsers(): bool
    {
        return $this->role?->canManageUsers() ?? false;
    }

    public function canManageCatalog(): bool
    {
        return $this->role?->canManageCatalog() ?? false;
    }

    public function canDeleteSnags(): bool
    {
        return $this->role?->canDeleteSnags() ?? false;
    }

    public function canPurgeProjects(): bool
    {
        return $this->role?->canPurgeProjects() ?? false;
    }

    public function canArchiveProjects(): bool
    {
        return $this->role?->canArchiveProjects() ?? false;
    }

    public function canManageProjects(): bool
    {
        return $this->role?->canManageProjects() ?? false;
    }

    public function canManagePlanning(): bool
    {
        return $this->role?->canManagePlanning() ?? false;
    }

    public function canManageWorkers(): bool
    {
        return $this->role?->canManageWorkers() ?? false;
    }

    public function canEnterProgress(): bool
    {
        return $this->role?->canEnterProgress() ?? false;
    }

    public function canApproveProgress(): bool
    {
        return $this->role?->canApproveProgress() ?? false;
    }

    public function progressNeedsApproval(): bool
    {
        return $this->isVakman();
    }

    public function canCreateSnags(): bool
    {
        return $this->role?->canCreateSnags() ?? false;
    }

    public function canUpdateSnags(): bool
    {
        return $this->role?->canUpdateSnags() ?? false;
    }

    public function canAdvanceSnagStatus(): bool
    {
        return $this->role?->canAdvanceSnagStatus() ?? false;
    }

    public function canReportSnags(): bool
    {
        return $this->role?->canReportSnags() ?? false;
    }

    public function canCloseSnags(): bool
    {
        return $this->role?->canCloseSnags() ?? false;
    }

    public function canManuallyLinkRooms(): bool
    {
        return $this->role?->canManuallyLinkRooms() ?? false;
    }

    public function canRejectSnags(): bool
    {
        return $this->role?->canRejectSnags() ?? false;
    }
}
