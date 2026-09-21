<?php

namespace App\Models;

use App\Enums\TimeEntryStatus;
use App\Support\PlanningHours;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_id', 'crew_member_id', 'user_id', 'project_id', 'work_item_id',
    'worker_assignment_id', 'date', 'planned_hours', 'hours', 'approved_hours', 'note', 'status',
    'is_unplanned', 'identity_key', 'submitted_at', 'submitted_by',
    'reviewed_at', 'reviewed_by', 'review_note', 'processed_at',
    'work_progress_entry_id', 'actual_assignment_id',
])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'ingediend',
        'is_unplanned' => false,
        'planned_hours' => 0,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'planned_hours' => 'decimal:2',
            'hours' => 'decimal:2',
            'approved_hours' => 'decimal:2',
            'status' => TimeEntryStatus::class,
            'is_unplanned' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'processed_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(WorkerAssignment::class, 'worker_assignment_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function progressEntry(): BelongsTo
    {
        return $this->belongsTo(WorkProgressEntry::class, 'work_progress_entry_id');
    }

    public function actualAssignment(): BelongsTo
    {
        return $this->belongsTo(WorkerAssignment::class, 'actual_assignment_id');
    }

    public function hoursValue(): float
    {
        return $this->submittedHoursValue();
    }

    public function submittedHoursValue(): float
    {
        return round((float) $this->hours, 2);
    }

    public function approvedHoursValue(): ?float
    {
        if ($this->approved_hours === null) {
            return null;
        }

        return round((float) $this->approved_hours, 2);
    }

    public function accountedHoursValue(): float
    {
        if ($this->isApproved()) {
            return $this->approvedHoursValue() ?? $this->submittedHoursValue();
        }

        return $this->submittedHoursValue();
    }

    public function plannedHoursValue(): float
    {
        return round((float) $this->planned_hours, 2);
    }

    public function differenceHours(): float
    {
        return round($this->hoursValue() - $this->plannedHoursValue(), 2);
    }

    public function hoursLabel(): string
    {
        return PlanningHours::hoursLabel($this->submittedHoursValue());
    }

    public function approvedHoursLabel(): string
    {
        $approved = $this->approvedHoursValue();

        return $approved === null ? '—' : PlanningHours::hoursLabel($approved);
    }

    public function isAdjusted(): bool
    {
        $approved = $this->approvedHoursValue();

        return $this->isApproved()
            && $approved !== null
            && abs($approved - $this->submittedHoursValue()) > 0.01;
    }

    public function reviewStatusLabel(): string
    {
        if ($this->isAdjusted()) {
            return 'Aangepast & goedgekeurd';
        }

        return $this->status->weekLabel();
    }

    public function personName(): string
    {
        return $this->crewMember?->displayName()
            ?? $this->worker?->planName()
            ?? 'Onbekend';
    }

    public function workName(): string
    {
        return $this->workItem?->planningTitle()
            ?? $this->assignment?->workItem?->planningTitle()
            ?? 'Werkzaamheid';
    }

    public function isApproved(): bool
    {
        return $this->status === TimeEntryStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === TimeEntryStatus::Rejected;
    }

    public function isSubmitted(): bool
    {
        return $this->status === TimeEntryStatus::Submitted;
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function belongsToVakman(User $user): bool
    {
        $workerId = $user->scheduledWorkerId();
        if ($workerId === null || (int) $this->worker_id !== $workerId) {
            return false;
        }

        $crewId = $user->scheduledCrewMemberId();
        if ($crewId === null) {
            return true;
        }

        return (int) $this->crew_member_id === $crewId;
    }

    public static function identityKey(
        int $workerId,
        ?int $crewMemberId,
        string $date,
        ?int $assignmentId,
        ?int $workItemId,
    ): string {
        return implode(':', [
            $workerId,
            $crewMemberId ?? 0,
            $date,
            $assignmentId ?? 0,
            $workItemId ?? 0,
        ]);
    }
}
