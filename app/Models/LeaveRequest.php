<?php

namespace App\Models;

use App\Enums\LeaveRequestStatus;
use Carbon\CarbonInterface;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'worker_id',
    'crew_member_id',
    'starts_on',
    'ends_on',
    'note',
    'status',
    'submitted_at',
    'reviewed_by',
    'reviewed_at',
    'rejection_reason',
    'worker_availability_id',
])]
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'in_behandeling',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => LeaveRequestStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function crewMember(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(WorkerAvailability::class, 'worker_availability_id');
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', LeaveRequestStatus::Pending);
    }

    public function isPending(): bool
    {
        return $this->status === LeaveRequestStatus::Pending;
    }

    public function workerName(): string
    {
        return $this->user?->name
            ?: $this->crewMember?->label()
            ?: $this->worker?->planName()
            ?: 'Vakman';
    }

    public function workdayCount(): int
    {
        $days = 0;
        $day = $this->starts_on->copy()->startOfDay();
        $last = $this->ends_on->copy()->startOfDay();
        while ($day->lte($last)) {
            if ((int) $day->dayOfWeekIso <= 5) {
                $days++;
            }
            $day->addDay();
        }

        return $days;
    }

    public function periodLabel(): string
    {
        if ($this->starts_on->isSameDay($this->ends_on)) {
            return $this->starts_on->translatedFormat('j F Y');
        }

        return $this->starts_on->translatedFormat('j F Y').' t/m '.$this->ends_on->translatedFormat('j F Y');
    }

    public function shortPeriodLabel(): string
    {
        if ($this->starts_on->isSameDay($this->ends_on)) {
            return $this->starts_on->format('d-m-Y');
        }

        return $this->starts_on->format('d-m-Y').' t/m '.$this->ends_on->format('d-m-Y');
    }

    public function mailSubjectPeriod(): string
    {
        return $this->starts_on->translatedFormat('j F Y');
    }

    public function covers(CarbonInterface $day): bool
    {
        return $day->betweenIncluded($this->starts_on, $this->ends_on);
    }
}
