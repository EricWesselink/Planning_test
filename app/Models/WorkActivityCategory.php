<?php

namespace App\Models;

use App\Enums\WorkPhase;
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

    /**
     * @param  list<int>  $keepIds
     * @return Collection<int, WorkActivity>
     */
    public static function floorFormActivities(array $keepIds = []): Collection
    {
        $category = static::formCatalog($keepIds)
            ->first(fn (self $category): bool => $category->slug === 'vloeren');

        return $category?->activities ?? collect();
    }

    /**
     * Floor activities for Klein/Service: Primen and Egaliseren as one checkbox.
     *
     * @param  list<int>  $keepIds
     * @return Collection<int, WorkActivity>
     */
    public static function kleinFormActivities(array $keepIds = []): Collection
    {
        $activities = static::floorFormActivities($keepIds);
        $insertAt = $activities->search(
            fn (WorkActivity $activity): bool => $activity->isOndergrondPrep()
        );
        $source = $activities->first(fn (WorkActivity $activity): bool => $activity->slug === 'egaliseren')
            ?? $activities->first(fn (WorkActivity $activity): bool => $activity->slug === 'primen');

        $rows = $activities
            ->reject(fn (WorkActivity $activity): bool => $activity->isOndergrondPrep())
            ->values();

        if (! $source instanceof WorkActivity) {
            return $rows;
        }

        $combined = clone $source;
        $combined->name = WorkPhase::Egaliseren->groupLabel();

        if ($insertAt === false) {
            return $rows->push($combined)->values();
        }

        return $rows
            ->slice(0, (int) $insertAt)
            ->push($combined)
            ->concat($rows->slice((int) $insertAt))
            ->values();
    }

    /**
     * Map stored primen/egaliseren IDs onto the combined Klein-work checkbox.
     *
     * @param  iterable<int|string>  $selectedIds
     * @param  array<int|string, mixed>  $quantities
     * @param  array<int|string, mixed>  $notes
     * @return array{0: list<int>, 1: array<int|string, mixed>, 2: array<int|string, mixed>}
     */
    public static function kleinFormSelection(iterable $selectedIds, array $quantities, array $notes = []): array
    {
        $ids = collect($selectedIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $prep = WorkActivity::query()
            ->whereIn('slug', WorkActivity::PREP_SLUGS)
            ->get()
            ->keyBy('slug');
        $primenId = (int) ($prep->get('primen')?->id ?? 0);
        $egaliserenId = (int) ($prep->get('egaliseren')?->id ?? 0);
        $formId = $egaliserenId > 0 ? $egaliserenId : $primenId;

        if ($formId > 0 && ($ids->contains($primenId) || $ids->contains($egaliserenId))) {
            $ids = $ids
                ->reject(fn (int $id): bool => in_array($id, [$primenId, $egaliserenId], true))
                ->push($formId)
                ->values();
            $quantities[$formId] = $quantities[$egaliserenId] ?? $quantities[$primenId] ?? ($quantities[$formId] ?? '');
            $note = '';
            foreach ([$egaliserenId, $primenId, $formId] as $id) {
                $candidate = trim((string) ($notes[$id] ?? ''));
                if ($id > 0 && $candidate !== '') {
                    $note = $candidate;
                    break;
                }
            }
            $notes[$formId] = $note;
        }

        return [$ids->all(), $quantities, $notes];
    }

    public function isMisc(): bool
    {
        return $this->slug === 'overig';
    }
}
