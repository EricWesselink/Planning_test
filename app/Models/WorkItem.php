<?php

namespace App\Models;

use App\Enums\FlooringSpecialty;
use App\Enums\SmallWorkType;
use App\Enums\WorkPhase;
use App\Enums\WorkUnit;
use App\Services\RoomWorkSetup;
use App\Support\MaterialColor;
use App\Support\WorkType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'project_id', 'work_activity_id', 'name', 'display_color', 'unit', 'ordered_quantity',
    'begrote_uren', 'begrote_hoeveelheid', 'uurtarief',
    'planned_start_date', 'planned_end_date', 'status', 'sort_order', 'notes',
    'is_extra_work', 'small_work_type',
])]
class WorkItem extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_extra_work' => false,
    ];

    protected function casts(): array
    {
        return [
            'unit' => WorkUnit::class,
            'ordered_quantity' => 'decimal:2',
            'begrote_uren' => 'decimal:2',
            'begrote_hoeveelheid' => 'decimal:2',
            'uurtarief' => 'decimal:2',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'is_extra_work' => 'boolean',
            'small_work_type' => SmallWorkType::class,
        ];
    }

    public function isExtraWork(): bool
    {
        return (bool) $this->is_extra_work;
    }

    public function skipsSkillMatch(): bool
    {
        if ($this->isExtraWork()) {
            return true;
        }

        return $this->project?->isSmallWork() ?? false;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workActivity(): BelongsTo
    {
        return $this->belongsTo(WorkActivity::class);
    }

    public function progressEntries(): HasMany
    {
        return $this->hasMany(WorkProgressEntry::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkerAssignment::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function areaTasks(): HasMany
    {
        return $this->hasMany(AreaTask::class);
    }

    public function calculationLines(): HasMany
    {
        return $this->hasMany(ProjectCalculationLine::class);
    }

    public function phase(): WorkPhase
    {
        if ($this->isExtraWork()) {
            return WorkPhase::Overige;
        }

        $name = mb_strtolower($this->name);

        if (WorkType::isWindowCovering($this->name)) {
            return WorkPhase::Overige;
        }
        if (str_contains($name, 'plint') || $this->unit === WorkUnit::LinearMeter) {
            return WorkPhase::Plinten;
        }
        if (preg_match('/voorbereid|schuur/', $name)) {
            return WorkPhase::Voorbereiden;
        }
        if (str_contains($name, 'egal') || str_contains($name, 'primen') || str_contains($name, 'primer')) {
            return WorkPhase::Egaliseren;
        }
        if (preg_match('/profiel|lassen|\blas\b/', $name)) {
            return WorkPhase::Overige;
        }

        return WorkPhase::Vloer;
    }

    public function packageKey(): string
    {
        if ($this->isExtraWork()) {
            return 'extra';
        }

        return $this->phase()->group();
    }

    public function packageLabel(): string
    {
        return $this->phase()->groupLabel();
    }

    public function typeLabel(): string
    {
        if ($this->isExtraWork()) {
            return 'Extra werk';
        }

        if ($this->packageKey() === 'ondergrond') {
            return $this->packageLabel();
        }

        return WorkType::labelFromName($this->name, $this->packageLabel());
    }

    public function displayColor(): string
    {
        return MaterialColor::resolve($this->display_color, $this->name);
    }

    public function productLabel(): ?string
    {
        if ($this->packageKey() === 'ondergrond') {
            return null;
        }

        return WorkType::productFromName($this->name);
    }

    public function typeKey(): string
    {
        if ($this->isExtraWork()) {
            return 'extra';
        }

        if ($this->packageKey() === 'ondergrond') {
            return 'ondergrond';
        }

        return mb_strtolower($this->typeLabel()).'|'.$this->unit->value;
    }

    public function planningTitle(): string
    {
        if ($this->isExtraWork()) {
            return trim((string) $this->name);
        }

        if ($this->packageKey() === 'ondergrond') {
            return $this->packageLabel();
        }

        return $this->typeLabel();
    }

    public function specialtyKey(): string
    {
        foreach ([$this->name, $this->cardLabel(), $this->typeLabel(), $this->phase()->groupLabel()] as $label) {
            $case = FlooringSpecialty::tryFromLabel((string) $label);
            if ($case instanceof FlooringSpecialty) {
                return $case->value;
            }
        }

        return mb_strtolower($this->packageKey());
    }

    /**
     * @return array{key: string, label: string}
     */
    public function requiredSpecialty(): array
    {
        $labels = $this->specialtyLabels();
        $matched = FlooringSpecialty::matchFromLabels($labels);
        if ($matched !== null) {
            return $matched;
        }

        foreach (SpecialtyOption::names() as $name) {
            $needle = mb_strtolower(trim($name));
            if (mb_strlen($needle) < 3) {
                continue;
            }
            foreach ($labels as $label) {
                $hay = mb_strtolower($label);
                if ($hay === $needle || str_contains($hay, $needle)) {
                    return [
                        'key' => $name,
                        'label' => $name,
                    ];
                }
            }
        }

        $fallback = $this->planningTitle();

        return [
            'key' => mb_strtolower($fallback),
            'label' => $fallback,
        ];
    }

    /**
     * @return list<string>
     */
    private function specialtyLabels(): array
    {
        $labels = [
            (string) $this->name,
            $this->cardLabel(),
            $this->typeLabel(),
            $this->planningTitle(),
            (string) $this->productLabel(),
            $this->phase()->groupLabel(),
        ];
        if ($this->relationLoaded('workActivity') && $this->workActivity) {
            $labels[] = (string) $this->workActivity->name;
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (string $label): string => trim($label), $labels),
            static fn (string $label): bool => $label !== '',
        )));
    }

    public function cardLabel(): string
    {
        if ($this->packageKey() === 'ondergrond') {
            return RoomWorkSetup::PRIMEN_EGALISEREN;
        }

        $type = $this->typeLabel();
        $product = $this->productLabel();
        if ($product) {
            return $product.', '.$type;
        }

        $name = trim($this->name);
        if ($name !== '' && ! WorkType::looksLikeRoom($name)) {
            return $name;
        }

        return $type;
    }

    public function syncStatusFromProgress(): void
    {
        $this->unsetRelation('progressEntries');
        $this->load('progressEntries');

        if ((float) $this->ordered_quantity > 0 && $this->remainingQuantity() <= 0) {
            $this->status = 'gereed';
        } elseif ($this->completedQuantity() > 0) {
            $this->status = 'in_uitvoering';
        } else {
            $this->status = 'gepland';
        }

        $this->save();
    }

    public function completedQuantity(): float
    {
        return (float) $this->progressEntries->sum('completed_quantity');
    }

    public function remainingQuantity(): float
    {
        return max(0, (float) $this->ordered_quantity - $this->completedQuantity());
    }

    public function progressPercent(): int
    {
        $ordered = (float) $this->ordered_quantity;
        if ($ordered <= 0) {
            return 0;
        }

        return (int) round(min(100, $this->completedQuantity() / $ordered * 100));
    }
}
