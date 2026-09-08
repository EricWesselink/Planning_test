<?php

namespace App\Models;

use App\Enums\WorkUnit;
use App\Support\Format;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['project_id', 'work_activity_id', 'notes', 'quantity', 'unit', 'sort_order'])]
class ProjectWorkActivity extends Pivot
{
    protected $table = 'project_work_activities';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit' => WorkUnit::class,
            'sort_order' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workActivity(): BelongsTo
    {
        return $this->belongsTo(WorkActivity::class);
    }

    public function quantityLabel(): ?string
    {
        if ($this->quantity === null || (float) $this->quantity <= 0) {
            return null;
        }

        $decimals = fmod((float) $this->quantity, 1.0) === 0.0 ? 0 : 2;
        $unit = $this->unit instanceof WorkUnit ? $this->unit : WorkUnit::tryFrom((string) $this->unit);

        return trim(Format::qty($this->quantity, $decimals).' '.($unit?->label() ?? ''));
    }
}
