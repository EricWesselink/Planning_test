<?php

namespace App\Models;

use App\Enums\FlooringSpecialty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['worker_id', 'name', 'phone', 'sort_order', 'specialty'])]
class CrewMember extends Model
{
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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

    public function label(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : 'Persoon '.((int) $this->sort_order + 1);
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
