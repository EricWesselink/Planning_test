<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['name', 'slug', 'sort_order', 'is_active'])]
class WorkActivityCategory extends Model
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

    public function activities(): HasMany
    {
        return $this->hasMany(WorkActivity::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  list<int>  $keepIds
     * @return Collection<int, self>
     */
    public static function formCatalog(array $keepIds = []): Collection
    {
        $keep = $keepIds === [] ? [0] : $keepIds;

        return static::query()
            ->ordered()
            ->with(['activities' => function ($query) use ($keep): void {
                $query->ordered()->where(function ($inner) use ($keep): void {
                    $inner->where('is_active', true)->orWhereIn('id', $keep);
                });
            }])
            ->where(function ($query) use ($keep): void {
                $query->where('is_active', true)
                    ->orWhereHas('activities', fn ($activities) => $activities->whereIn('id', $keep));
            })
            ->get()
            ->filter(fn (self $category): bool => $category->activities->isNotEmpty())
            ->values();
    }

    public function isMisc(): bool
    {
        return $this->slug === 'overig';
    }
}
