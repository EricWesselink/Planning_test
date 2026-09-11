<?php

namespace App\Models;

use App\Enums\WorkUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['work_activity_category_id', 'name', 'slug', 'sort_order', 'is_active'])]
class WorkActivity extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WorkActivityCategory::class, 'work_activity_category_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_work_activities')
            ->using(ProjectWorkActivity::class)
            ->withPivot(['notes', 'quantity', 'unit', 'sort_order'])
            ->withTimestamps();
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function defaultShopUnit(?WorkActivityCategory $category = null): WorkUnit
    {
        if ($this->slug === 'plinten' || str_contains(mb_strtolower($this->name), 'plint')) {
            return WorkUnit::LinearMeter;
        }

        $slug = ($category ?? $this->category)?->slug;

        return $slug === 'vloeren' ? WorkUnit::SquareMeter : WorkUnit::Pieces;
    }
}
