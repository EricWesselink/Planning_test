<?php

namespace App\Models;

use App\Enums\AreaStatus;
use App\Enums\WorkPhase;
use App\Enums\WorkUnit;
use App\Services\RoomWorkSetup;
use App\Support\Format;
use App\Support\MaterialColor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['project_id', 'project_floor_id', 'area_number', 'name', 'square_meters', 'fill_color', 'status', 'sort_order', 'notes'])]
class ProjectArea extends Model
{
    protected function casts(): array
    {
        return [
            'square_meters' => 'decimal:2',
            'status' => AreaStatus::class,
        ];
    }

    public function materialColor(): string
    {
        $floorItem = $this->tasks
            ->map(fn (AreaTask $task) => $task->workItem)
            ->filter()
            ->first(fn (WorkItem $item) => ! in_array($item->packageKey(), ['ondergrond', 'plinten'], true));

        $name = $floorItem?->name ?? $this->name;
        $forbidden = $this->plintDisplayColors();
        $fill = $this->trustedFloorHex($this->fill_color, $forbidden);
        $workHex = $this->trustedFloorHex($floorItem?->display_color, $forbidden);

        if ($workHex === null && MaterialColor::fromProductName($name) !== null) {
            return MaterialColor::resolve(null, $name);
        }

        return MaterialColor::resolve($fill ?? $workHex, $name);
    }

    /**
     * Meest voorkomende plintkleur(en) op het project: de legendakleur, niet een
     * per ongeluk overgenomen tapijt- of vloerkleur op één plintregel.
     *
     * @return list<string>
     */
    public function plintDisplayColors(): array
    {
        $this->loadMissing('project.workItems');
        $counts = [];
        foreach ($this->project?->workItems ?? [] as $item) {
            if ($item->packageKey() !== 'plinten') {
                continue;
            }
            $hex = MaterialColor::normalizeHex($item->display_color);
            if ($hex === null) {
                continue;
            }
            $counts[$hex] = ($counts[$hex] ?? 0) + 1;
        }
        if ($counts === []) {
            return [];
        }
        $max = max($counts);

        return array_keys(array_filter($counts, fn (int $count): bool => $count === $max));
    }

    public function plintLegendColor(): ?string
    {
        return $this->plintDisplayColors()[0] ?? null;
    }

    /**
     * @param  list<string>  $forbidden
     */
    private function trustedFloorHex(?string $hex, array $forbidden): ?string
    {
        $normalized = MaterialColor::normalizeHex($hex);
        if ($normalized === null) {
            return null;
        }
        foreach ($forbidden as $plintHex) {
            if (MaterialColor::hexesMatch($normalized, $plintHex, 8)) {
                return null;
            }
        }

        return $normalized;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(ProjectFloor::class, 'project_floor_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AreaTask::class)->orderBy('id');
    }

    public function markers(): HasMany
    {
        return $this->hasMany(AreaDrawingMarker::class);
    }

    public function snags(): HasMany
    {
        return $this->hasMany(SnagItem::class);
    }

    public function progressCounts(): array
    {
        $groups = $this->groupedTasks();
        $total = $groups->count();
        $done = $groups->filter(function (array $group) {
            return collect($group['tasks'])->isNotEmpty()
                && collect($group['tasks'])->every(fn (AreaTask $task) => $task->isDone());
        })->count();

        return ['done' => $done, 'total' => $total];
    }

    public function groupIsDone(string $group): bool
    {
        $tasks = $this->tasks->filter(fn (AreaTask $task) => $task->phase()->group() === $group);

        return $tasks->isNotEmpty() && $tasks->every(fn (AreaTask $task) => $task->isDone());
    }

    public function groupedTasks(): Collection
    {
        return $this->tasks
            ->sortBy(fn (AreaTask $task) => [$task->phase()->sort(), $task->workItem?->cardLabel() ?? '', $task->id])
            ->groupBy(fn (AreaTask $task) => $this->cardGroupKey($task))
            ->map(function (Collection $tasks, string $key) {
                return [
                    'key' => $key,
                    'label' => $this->cardGroupLabel($tasks, $key),
                    'tasks' => $tasks->values(),
                ];
            })
            ->values();
    }

    private function cardGroupKey(AreaTask $task): string
    {
        $group = $task->phase()->group();
        if ($group === 'ondergrond') {
            return 'ondergrond';
        }

        $item = $task->workItem;

        return $group.'|'.($item?->id ?? 'task-'.$task->id);
    }

    private function cardGroupLabel(Collection $tasks, string $key): string
    {
        if ($key === 'ondergrond') {
            return RoomWorkSetup::PRIMEN_EGALISEREN;
        }

        $items = $tasks->map(fn (AreaTask $task) => $task->workItem)->filter();
        $labels = $items->map(fn (WorkItem $item) => $item->cardLabel())->unique()->values();
        if ($labels->count() === 1) {
            return $labels->first();
        }

        $types = $items->map(fn (WorkItem $item) => $item->typeLabel())->unique()->values();
        if ($types->count() === 1) {
            return $types->first();
        }

        return $tasks->first()?->phase()->groupLabel() ?? $key;
    }

    public function label(): string
    {
        $number = $this->displayNumber();
        $name = $this->displayName();

        return $number !== null && $number !== '' ? $number.' '.$name : $name;
    }

    public function squareMetersLabel(): string
    {
        if ((float) $this->square_meters > 0.0001) {
            return Format::qty($this->square_meters, 2).' m²';
        }

        $this->loadMissing('tasks');
        $pieces = (float) $this->tasks
            ->filter(fn (AreaTask $task) => $task->unit === WorkUnit::Pieces)
            ->sum('ordered_quantity');
        if ($pieces > 0.0001) {
            return Format::qty($pieces).' st';
        }

        if ($this->square_meters === null || $this->square_meters === '') {
            return '—';
        }

        return Format::qty($this->square_meters, 2).' m²';
    }

    public function refreshStatusFromTasks(): void
    {
        $tasks = $this->tasks()->get();
        if ($tasks->isEmpty()) {
            return;
        }

        $allDone = $tasks->every(fn (AreaTask $task) => $task->status === AreaStatus::Gereed);
        $status = match (true) {
            $allDone && $tasks->every(fn (AreaTask $task) => $task->isApproved()) => AreaStatus::Gereed,
            $allDone => AreaStatus::VoorlopigGereed,
            $tasks->contains(fn (AreaTask $task) => $task->status !== AreaStatus::NietGestart) => AreaStatus::InUitvoering,
            default => AreaStatus::NietGestart,
        };
        if ($this->status === $status) {
            return;
        }

        $this->status = $status;
        $this->save();
    }

    /** @return Collection<int, AreaTask> */
    public function tasksForPhase(WorkPhase $phase): Collection
    {
        return $this->tasks->filter(fn (AreaTask $task) => $task->phase() === $phase)->values();
    }

    public function phaseIsDone(WorkPhase $phase): bool
    {
        $tasks = $this->tasksForPhase($phase);

        return $tasks->isNotEmpty() && $tasks->every(fn (AreaTask $task) => $task->isDone());
    }

    public function showsEgaliseren(): bool
    {
        return $this->tasksForPhase(WorkPhase::Egaliseren)->isNotEmpty()
            || $this->tasksForPhase(WorkPhase::Vloer)->isNotEmpty()
            || (float) $this->square_meters > 0;
    }

    public function showsVloer(): bool
    {
        return $this->tasksForPhase(WorkPhase::Vloer)->isNotEmpty()
            || (float) $this->square_meters > 0;
    }

    public function showsPlinten(): bool
    {
        return $this->tasksForPhase(WorkPhase::Plinten)->isNotEmpty();
    }

    public function vloerLabel(): string
    {
        $names = $this->tasksForPhase(WorkPhase::Vloer)
            ->map(fn (AreaTask $task) => $task->workItem?->name)
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return 'Vloer';
        }

        $short = $names
            ->reject(fn (string $name) => (bool) preg_match('/^\d+[.\-]\d+/', $name))
            ->map(function (string $name) {
                $name = preg_replace('/,.*$/', '', $name) ?? $name;

                return trim($name);
            })
            ->filter();

        return $short->unique()->join(', ') ?: 'Vloer';
    }

    public function phaseWho(WorkPhase $phase): string
    {
        return $this->tasksForPhase($phase)
            ->map(fn (AreaTask $task) => $task->completedByWorker?->shortName())
            ->filter()
            ->unique()
            ->join(', ');
    }

    public function plintenQuantity(): float
    {
        return (float) $this->tasksForPhase(WorkPhase::Plinten)->sum('ordered_quantity');
    }

    public function doneByNames(): string
    {
        return $this->tasks
            ->map(fn (AreaTask $task) => $task->completedByWorker?->shortName())
            ->filter()
            ->unique()
            ->join(', ');
    }

    public static function numberSortKey(?string $number): string
    {
        $number = mb_strtolower(trim((string) $number));
        if ($number === '') {
            return 'zzz';
        }
        if (preg_match('/^(\d+)[.\-](\d+)([a-z])?$/u', $number, $match)) {
            return sprintf('%05d.%05d.%s', (int) $match[1], (int) $match[2], $match[3] ?? '');
        }

        return 'yyy.'.$number;
    }

    public function displayNumber(): ?string
    {
        $parsed = $this->splitGluedNumber();

        return $parsed['number'];
    }

    public function displayName(): string
    {
        $parsed = $this->splitGluedNumber();

        return $parsed['name'];
    }

    /** @return array{number: ?string, name: string} */
    private function splitGluedNumber(): array
    {
        $number = trim((string) $this->area_number);
        $name = trim((string) $this->name);

        if ($number !== '' && preg_match('/^(\d+[.\-]\d+)([a-zA-Z])?(.*)$/u', $number, $match)) {
            $base = $match[1];
            $letter = $match[2] ?? '';
            $rawRest = $match[3];
            $glued = $letter !== '' && $rawRest !== '' && ! preg_match('/^\s/u', $rawRest);

            if ($glued) {
                $fromNumber = $letter.trim($rawRest);
                $brokenName = $name === '' || mb_strlen($name) <= 1 || strcasecmp($name, $number) === 0;

                return [
                    'number' => $base,
                    'name' => $brokenName ? $fromNumber : $name,
                ];
            }

            $displayNumber = $base.$letter;

            return [
                'number' => $displayNumber,
                'name' => $name !== '' ? $name : $displayNumber,
            ];
        }

        return ['number' => $number !== '' ? $number : null, 'name' => $name];
    }
}
