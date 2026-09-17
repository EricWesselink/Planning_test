<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id', 'meter_user_id', 'ordered_at', 'installation_at',
])]
class MeasurementForm extends Model
{
    protected function casts(): array
    {
        return [
            'ordered_at' => 'date',
            'installation_at' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'meter_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(MeasurementFormRow::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isFilled(): bool
    {
        if ($this->meter_user_id !== null || $this->ordered_at !== null || $this->installation_at !== null) {
            return true;
        }

        if ($this->relationLoaded('rows')) {
            return $this->rows->isNotEmpty();
        }

        return $this->rows()->exists();
    }
}
