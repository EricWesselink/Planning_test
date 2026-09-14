<?php

namespace App\Models;

use App\Enums\WorkTicketBilling;
use App\Enums\WorkTicketKind;
use App\Support\Format;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number', 'kind', 'worker_assignment_id', 'project_id', 'worker_id', 'team_id',
    'created_by', 'billing_method', 'hourly_rate', 'fixed_price', 'worked_hours',
    'notes', 'start_date', 'end_date',
])]
class WorkTicket extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => WorkTicketKind::class,
            'billing_method' => WorkTicketBilling::class,
            'hourly_rate' => 'decimal:2',
            'fixed_price' => 'decimal:2',
            'worked_hours' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(WorkerAssignment::class, 'worker_assignment_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WorkTicketLine::class)->orderBy('id');
    }

    public function floors(): BelongsToMany
    {
        return $this->belongsToMany(ProjectFloor::class, 'work_ticket_floors')
            ->withPivot('entire_floor')
            ->withTimestamps()
            ->orderBy('sort_order');
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(ProjectArea::class, 'work_ticket_areas')
            ->withTimestamps()
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(ProjectDocument::class, 'work_ticket_documents')
            ->withTimestamps()
            ->orderBy('id');
    }

    public function isOpdrachtbon(): bool
    {
        return $this->kind === WorkTicketKind::Opdrachtbon;
    }

    public function dateRangeLabel(): string
    {
        if ($this->start_date->isSameDay($this->end_date)) {
            return $this->start_date->translatedFormat('j-m-Y');
        }

        return $this->start_date->translatedFormat('j-m').' t/m '.$this->end_date->translatedFormat('j-m-Y');
    }

    public function floorsLabel(): string
    {
        $this->loadMissing('floors', 'areas.floor');

        $names = $this->floors
            ->map(function (ProjectFloor $floor): string {
                $name = trim((string) $floor->name);
                if ($name === '') {
                    return 'Verdieping';
                }
                $entire = (bool) $floor->pivot?->entire_floor;

                return $entire ? 'hele '.$name : $name;
            })
            ->values();

        $loose = $this->areas
            ->filter(fn (ProjectArea $area): bool => $area->project_floor_id === null)
            ->isNotEmpty();
        if ($loose) {
            $names->push('Overige ruimtes');
        }

        return $names->unique()->implode(', ');
    }

    public function roomsLabel(): string
    {
        $this->loadMissing(['floors', 'areas']);

        $entireFloorIds = $this->floors
            ->filter(fn (ProjectFloor $floor): bool => (bool) $floor->pivot?->entire_floor)
            ->pluck('id')
            ->all();

        $rooms = $this->areas
            ->reject(fn (ProjectArea $area): bool => in_array((int) $area->project_floor_id, $entireFloorIds, true))
            ->map(fn (ProjectArea $area): string => $area->label())
            ->filter()
            ->values();

        if ($entireFloorIds !== [] && $rooms->isEmpty()) {
            return 'Hele verdieping';
        }

        return $rooms->implode(', ');
    }

    public function totalAmount(): ?float
    {
        if (! $this->isOpdrachtbon()) {
            return null;
        }

        return match ($this->billing_method) {
            WorkTicketBilling::Fixed => round((float) $this->fixed_price, 2),
            WorkTicketBilling::Hourly => $this->worked_hours === null
                ? null
                : round((float) $this->hourly_rate * (float) $this->worked_hours, 2),
            WorkTicketBilling::Unit => round((float) $this->lines->sum('amount'), 2),
            default => null,
        };
    }

    public static function nextNumber(WorkTicketKind $kind): string
    {
        $year = (int) now()->year;
        $prefix = ($kind === WorkTicketKind::Opdrachtbon ? 'OB-' : 'WB-').$year.'-';
        $latest = static::query()
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('number');

        $seq = 1;
        if (is_string($latest) && preg_match('/^(?:WB|OB)-\d+-(\d+)$/', $latest, $matches) === 1) {
            $seq = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function billingLabel(): ?string
    {
        if (! $this->isOpdrachtbon() || $this->billing_method === null) {
            return null;
        }

        return match ($this->billing_method) {
            WorkTicketBilling::Hourly => $this->billing_method->label().' · '.Format::money($this->hourly_rate).'/uur',
            WorkTicketBilling::Fixed => $this->billing_method->label().' · '.Format::money($this->fixed_price),
            WorkTicketBilling::Unit => $this->billing_method->label(),
        };
    }
}
