<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Support\PermissionCatalog;
use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'permissions', 'active', 'can_access_all_projects', 'worker_id', 'crew_member_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, HasFactory, Notifiable;

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function hasDeliverableEmail(): bool
    {
        return ! str_ends_with(strtolower($this->email), '@telefoon.niconvloeren.nl');
    }

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
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'can_access_all_projects' => 'boolean',
            'role' => UserRole::class,
            'permissions' => 'array',
        ];
    }

    public function recordSuccessfulLogin(): void
    {
        $this->last_login_at = now();
        $this->save();
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

    public function isOfficeUser(): bool
    {
        return ! $this->isVakman();
    }

    public function scopeOffice(Builder $query): void
    {
        $query->where('role', '!=', UserRole::Vakman);
    }

    public function scheduledWorkerId(): ?int
    {
        if (! $this->isVakman() || $this->worker_id === null) {
            return null;
        }

        return (int) $this->worker_id;
    }

    public function scheduledCrewMemberId(): ?int
    {
        if (! $this->isVakman() || $this->crew_member_id === null) {
            return null;
        }

        return (int) $this->crew_member_id;
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

    public function usesPermissionMatrix(): bool
    {
        return $this->role?->usesPermissionMatrix() ?? false;
    }

    public function isReadOnlyOfficeUser(): bool
    {
        if ($this->role === UserRole::AlleenLezen) {
            return true;
        }

        if ($this->role === UserRole::Aangepast) {
            return ! PermissionCatalog::hasWrite($this->permissions);
        }

        return false;
    }

    public function canMutate(): bool
    {
        return ! $this->isReadOnlyOfficeUser();
    }

    public function officeHomeRouteName(): string
    {
        if ($this->canViewDashboard()) {
            return 'dashboard';
        }
        if ($this->canViewPlanning()) {
            return 'planning';
        }
        if ($this->canViewProjects()) {
            return 'projects.index';
        }
        if ($this->canViewCalculations()) {
            return 'calculations.index';
        }
        if ($this->canViewProduction()) {
            return 'production.index';
        }
        if ($this->canViewWorkers()) {
            return 'workers.index';
        }

        return 'dashboard';
    }

    public function hasPermission(Permission $permission): bool
    {
        if ($this->role === UserRole::Admin) {
            return true;
        }

        if ($this->role === UserRole::AlleenLezen) {
            return $permission->grantedToReadOnly();
        }

        if ($this->role !== UserRole::Aangepast) {
            return false;
        }

        $granted = $this->permissions ?? [];
        if (in_array($permission->value, $granted, true)) {
            return true;
        }

        if (! $permission->isRead()) {
            return false;
        }

        foreach ($granted as $value) {
            $stored = Permission::tryFrom((string) $value);
            if ($stored !== null && ! $stored->isRead() && $stored->viewPermission() === $permission) {
                return true;
            }
        }

        return false;
    }

    public function canManageUsers(): bool
    {
        return $this->allows(Permission::UsersManage, fn (): bool => $this->role?->canManageUsers() ?? false);
    }

    public function canViewUsers(): bool
    {
        return $this->allows(Permission::UsersView, fn (): bool => $this->canManageUsers());
    }

    public function canManageCatalog(): bool
    {
        return $this->allows(Permission::CatalogManage, fn (): bool => $this->role?->canManageCatalog() ?? false);
    }

    public function canViewCatalog(): bool
    {
        return $this->allows(Permission::CatalogView, fn (): bool => $this->canManageCatalog());
    }

    public function canDeleteSnags(): bool
    {
        return $this->allows(Permission::SnagsDelete, fn (): bool => $this->role?->canDeleteSnags() ?? false);
    }

    public function canPurgeProjects(): bool
    {
        return $this->allows(Permission::ProjectsDelete, fn (): bool => $this->role?->canPurgeProjects() ?? false);
    }

    public function canArchiveProjects(): bool
    {
        return $this->allows(Permission::ProjectsArchive, fn (): bool => $this->role?->canArchiveProjects() ?? false);
    }

    public function canCreateProjects(): bool
    {
        return $this->allows(Permission::ProjectsCreate, fn (): bool => $this->role?->canManageProjects() ?? false);
    }

    public function canManageProjects(): bool
    {
        return $this->allows(Permission::ProjectsUpdate, fn (): bool => $this->role?->canManageProjects() ?? false);
    }

    public function canViewProjects(): bool
    {
        return $this->allows(Permission::ProjectsView, fn (): bool => true);
    }

    public function canUploadProjectFiles(): bool
    {
        return $this->allows(Permission::FilesUpload, fn (): bool => $this->role?->canManageProjects() ?? false);
    }

    public function canProcessRevisions(): bool
    {
        return $this->allows(Permission::RevisionsProcess, fn (): bool => $this->role?->canManageProjects() ?? false);
    }

    public function canViewFiles(): bool
    {
        return $this->allows(Permission::FilesView, fn (): bool => true);
    }

    public function canAssignPlanning(): bool
    {
        return $this->allowsAny(
            [Permission::PlanningAssign, Permission::PlanningUpdate],
            fn (): bool => $this->role?->canManagePlanning() ?? false,
        );
    }

    public function canDragPlanning(): bool
    {
        return $this->allowsAny(
            [Permission::PlanningDrag, Permission::PlanningUpdate],
            fn (): bool => $this->role?->canManagePlanning() ?? false,
        );
    }

    public function canAdjustPlanningHours(): bool
    {
        return $this->allowsAny(
            [Permission::PlanningHours, Permission::PlanningUpdate],
            fn (): bool => $this->role?->canManagePlanning() ?? false,
        );
    }

    public function canAdjustAbsence(): bool
    {
        return $this->allows(Permission::PlanningAbsence, fn (): bool => $this->role?->canManageWorkers() ?? false);
    }

    public function canViewLeaveRequests(): bool
    {
        return $this->canManageUsers() || $this->canAdjustAbsence();
    }

    public function canReviewLeaveRequests(): bool
    {
        return $this->canManageUsers();
    }

    public function canRequestLeave(): bool
    {
        if (! $this->isVakman()) {
            return false;
        }

        $this->loadMissing('worker');

        return $this->worker !== null
            && $this->worker->employment_type === EmploymentType::Eigen;
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function canDownloadPlanningWeekPdf(): bool
    {
        return $this->allows(Permission::PlanningWeekPdf, fn (): bool => true);
    }

    public function canManagePlanning(): bool
    {
        if ($this->usesPermissionMatrix()) {
            return $this->canAssignPlanning()
                || $this->canDragPlanning()
                || $this->canAdjustPlanningHours()
                || $this->hasPermission(Permission::PlanningUpdate);
        }

        return $this->role?->canManagePlanning() ?? false;
    }

    public function canViewPlanning(): bool
    {
        return $this->allows(Permission::PlanningView, fn (): bool => true);
    }

    public function canViewPersonnelWeek(): bool
    {
        return $this->allows(Permission::PersonnelWeekView, fn (): bool => ! $this->isVakman());
    }

    public function canViewLaborCosts(): bool
    {
        return $this->allows(Permission::LaborCostsView, fn (): bool => $this->role?->canViewLaborCosts() ?? false);
    }

    public function canCreateWorkers(): bool
    {
        return $this->allows(Permission::WorkersCreate, fn (): bool => $this->role?->canManageWorkers() ?? false);
    }

    public function canDeleteWorkers(): bool
    {
        return $this->allows(Permission::WorkersDelete, fn (): bool => $this->role?->canManageWorkers() ?? false);
    }

    public function canManageWorkers(): bool
    {
        return $this->allows(Permission::WorkersUpdate, fn (): bool => $this->role?->canManageWorkers() ?? false);
    }

    public function canViewWorkers(): bool
    {
        return $this->allows(Permission::WorkersView, fn (): bool => ! $this->isVakman());
    }

    public function canViewTeams(): bool
    {
        return $this->allows(Permission::TeamsView, fn (): bool => ! $this->isVakman());
    }

    public function canManageTeams(): bool
    {
        return $this->allows(Permission::TeamsUpdate, fn (): bool => $this->role?->canManageWorkers() ?? false);
    }

    public function canViewDashboard(): bool
    {
        return $this->allows(Permission::DashboardView, fn (): bool => true);
    }

    public function canViewDrawings(): bool
    {
        return $this->allows(Permission::DrawingsView, fn (): bool => true);
    }

    public function canViewMeetstaat(): bool
    {
        return $this->allows(Permission::MeetstaatView, fn (): bool => true);
    }

    public function canViewMaterials(): bool
    {
        return $this->allows(Permission::MaterialsView, fn (): bool => true);
    }

    public function canUpdateMaterials(): bool
    {
        return $this->allows(Permission::MaterialsUpdate, fn (): bool => $this->role?->canManageProjects() ?? false);
    }

    public function canViewReports(): bool
    {
        return $this->allows(Permission::ReportsView, fn (): bool => true);
    }

    public function canViewCalculations(): bool
    {
        return $this->allows(Permission::CalculationsView, fn (): bool => $this->role?->canManageProjects() ?? false);
    }

    public function canCreateCalculations(): bool
    {
        return $this->allows(Permission::CalculationsCreate, fn (): bool => $this->role?->canManageProjects() ?? false);
    }

    public function canUpdateCalculations(): bool
    {
        if ($this->usesPermissionMatrix()) {
            return $this->hasPermission(Permission::CalculationsUpdate)
                || $this->hasPermission(Permission::CalculationsUpload)
                || $this->hasPermission(Permission::CalculationsStatus)
                || $this->hasPermission(Permission::CalculationsMaterials);
        }

        return $this->role?->canManageProjects() ?? false;
    }

    public function canDeleteCalculations(): bool
    {
        return $this->allows(Permission::CalculationsDelete, fn (): bool => $this->role?->canManageProjects() ?? false);
    }

    public function canPrintCalculations(): bool
    {
        return $this->allows(Permission::CalculationsPrint, fn (): bool => $this->canViewCalculations());
    }

    public function canViewSnags(): bool
    {
        return $this->allows(Permission::SnagsView, fn (): bool => true);
    }

    public function canViewWorkTickets(): bool
    {
        return $this->allows(Permission::WorkTicketsView, fn (): bool => true);
    }

    public function canCreateWorkTickets(): bool
    {
        return $this->allows(Permission::WorkTicketsCreate, fn (): bool => $this->role?->canManagePlanning() ?? false);
    }

    public function canUpdateWorkTickets(): bool
    {
        return $this->allowsAny(
            [Permission::WorkTicketsUpdate, Permission::WorkTicketsComplete],
            fn (): bool => $this->role?->canManagePlanning() ?? false,
        );
    }

    public function canViewVouchers(): bool
    {
        return $this->allows(Permission::VouchersView, fn (): bool => true);
    }

    public function canCreateVouchers(): bool
    {
        return $this->allows(Permission::VouchersCreate, fn (): bool => $this->role?->canManageProjects() ?? false);
    }

    public function canUpdateVouchers(): bool
    {
        return $this->allowsAny(
            [Permission::VouchersUpdate, Permission::VouchersApprove],
            fn (): bool => $this->role?->canManageProjects() ?? false,
        );
    }

    public function canViewProduction(): bool
    {
        if ($this->usesPermissionMatrix()) {
            return $this->canViewVouchers()
                || $this->hasPermission(Permission::ProgressUpdate)
                || $this->canViewProjects();
        }

        return true;
    }

    public function canEnterProgress(): bool
    {
        return $this->allows(Permission::ProgressUpdate, fn (): bool => $this->role?->canEnterProgress() ?? false);
    }

    public function canApproveProgress(): bool
    {
        return $this->allows(Permission::ProgressApprove, fn (): bool => $this->role?->canApproveProgress() ?? false);
    }

    public function canReviewHours(): bool
    {
        return $this->allows(Permission::HoursApprove, fn (): bool => $this->role?->canReviewHours() ?? false);
    }

    public function canViewHours(): bool
    {
        return $this->allows(Permission::HoursView, fn (): bool => $this->canViewPersonnelWeek());
    }

    public function canRegisterHours(): bool
    {
        if (! $this->isVakman()) {
            return false;
        }

        $this->loadMissing(['worker', 'crewMember']);
        if ($this->crewMember !== null) {
            return $this->crewMember->registersHours();
        }

        return $this->worker?->registersHours() ?? false;
    }

    public function progressNeedsApproval(): bool
    {
        return $this->isVakman();
    }

    public function canCreateSnags(): bool
    {
        return $this->allows(Permission::SnagsCreate, fn (): bool => $this->role?->canCreateSnags() ?? false);
    }

    public function canUpdateSnags(): bool
    {
        return $this->allows(Permission::SnagsUpdate, fn (): bool => $this->role?->canUpdateSnags() ?? false);
    }

    public function canAdvanceSnagStatus(): bool
    {
        return $this->allows(Permission::SnagsUpdate, fn (): bool => $this->role?->canAdvanceSnagStatus() ?? false);
    }

    public function canReportSnags(): bool
    {
        return $this->allows(Permission::SnagsReport, fn (): bool => $this->role?->canReportSnags() ?? false);
    }

    public function canCloseSnags(): bool
    {
        return $this->allows(Permission::SnagsClose, fn (): bool => $this->role?->canCloseSnags() ?? false);
    }

    public function canManuallyLinkRooms(): bool
    {
        return $this->role?->canManuallyLinkRooms() ?? false;
    }

    public function canRejectSnags(): bool
    {
        return $this->allows(Permission::SnagsReject, fn (): bool => $this->role?->canRejectSnags() ?? false);
    }

    private function allows(Permission $permission, callable $standard): bool
    {
        return match ($this->role) {
            UserRole::Admin => true,
            UserRole::AlleenLezen => $permission->grantedToReadOnly(),
            UserRole::Aangepast => $this->hasPermission($permission),
            default => $standard(),
        };
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function allowsAny(array $permissions, callable $standard): bool
    {
        if ($this->role === UserRole::Admin) {
            return true;
        }

        if ($this->role === UserRole::AlleenLezen) {
            return false;
        }

        if ($this->role === UserRole::Aangepast) {
            foreach ($permissions as $permission) {
                if ($this->hasPermission($permission)) {
                    return true;
                }
            }

            return false;
        }

        return $standard();
    }
}
