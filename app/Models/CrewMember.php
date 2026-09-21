<?php

namespace App\Models;

use App\Enums\FlooringSpecialty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['worker_id', 'name', 'phone', 'sort_order', 'specialty', 'friday_off', 'unavailable', 'active', 'work_days', 'registers_hours'])]
class CrewMember extends Model
{
    public const WEEKDAY_LABELS = [
        1 => 'Ma',
        2 => 'Di',
        3 => 'Wo',
        4 => 'Do',
        5 => 'Vr',
        6 => 'Za',
    ];

    protected $attributes = [
        'friday_off' => false,
        'unavailable' => false,
        'active' => true,
        'registers_hours' => true,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'friday_off' => 'boolean',
            'unavailable' => 'boolean',
            'active' => 'boolean',
            'work_days' => 'array',
            'registers_hours' => 'boolean',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(WorkerAssignment::class, 'crew_member_worker_assignment')
            ->withTimestamps();
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(WorkerAvailability::class)
            ->orderBy('start_date')
            ->orderBy('id');
    }

    public function label(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : 'Persoon '.((int) $this->sort_order + 1);
    }

    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $this->label() : ($this->worker?->planName() ?? $this->label());
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    public function registersHours(): bool
    {
        return (bool) $this->registers_hours;
    }

    /**
     * @return list<int>
     */
    public function workDays(): array
    {
        if (is_array($this->work_days)) {
            $days = [];
            foreach ($this->work_days as $day) {
                $day = (int) $day;
                if ($day >= 1 && $day <= 6 && ! in_array($day, $days, true)) {
                    $days[] = $day;
                }
            }
            sort($days);

            return $days;
        }

        $days = [1, 2, 3, 4];
        if (! $this->hasFridayOff()) {
            $days[] = 5;
        }

        return $days;
    }

    public function worksOn(int $isoDay): bool
    {
        return in_array($isoDay, $this->workDays(), true);
    }

    public function hasFridayOff(): bool
    {
        if (is_array($this->work_days)) {
            return ! in_array(5, $this->workDays(), true);
        }

        return $this->friday_off || (bool) $this->worker?->friday_off;
    }

    public function setWorkDay(int $isoDay, bool $works): void
    {
        $days = $this->workDays();
        if ($works) {
            if (! in_array($isoDay, $days, true)) {
                $days[] = $isoDay;
            }
        } else {
            $days = array_values(array_filter($days, fn (int $day): bool => $day !== $isoDay));
        }
        sort($days);

        $this->work_days = $days;
        $this->friday_off = ! in_array(5, $days, true);
    }

    public function hasSpecialty(string $value): bool
    {
        $own = trim((string) $this->specialty);
        if ($own !== '') {
            return FlooringSpecialty::storedHas($own, $value);
        }

        return $this->worker?->hasSpecialty($value) ?? false;
    }
}
