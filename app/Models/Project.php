<?php

namespace App\Models;

use App\Enums\ProjectKind;
use App\Enums\ProjectStatus;
use App\Enums\WorkUnit;
use App\Support\PlanningWeek;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'project_number', 'customer_id', 'name', 'address', 'postal_code', 'city',
    'contact_name', 'contact_phone', 'contact_email', 'supervisor_user_id',
    'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date',
    'status', 'kind', 'notes', 'work_description', 'basis_uurtarief', 'archived_at',
    'import_warnings',
])]
class Project extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'project',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'kind' => ProjectKind::class,
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'basis_uurtarief' => 'decimal:2',
            'archived_at' => 'datetime',
            'import_warnings' => 'array',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function scopeMatchingSearch(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('project_number', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('notes', 'like', $like);
        });
    }

    public function scopeStartingInIsoWeek(Builder $query, ?int $week, ?int $year = null): void
    {
        if ($week === null) {
            return;
        }

        $year ??= (int) now()->isoWeekYear();
        $monday = PlanningWeek::monday($year, $week);
        if ($monday === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereDate('planned_start_date', '>=', $monday->toDateString())
            ->whereDate('planned_start_date', '<=', $monday->copy()->addDays(6)->toDateString());
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => now()])->save();
    }

    public function restoreFromArchive(): void
    {
        if (! $this->isArchived()) {
            return;
        }

        $this->forceFill(['archived_at' => null])->save();
    }

    public function purge(): void
    {
        Storage::disk('local')->deleteDirectory('projects/'.$this->id);
        $this->delete();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function scopeAccessibleBy(Builder $query, ?User $user): void
    {
        if ($user === null) {
            return;
        }

        if ($user->isVakman()) {
            $workerId = $user->scheduledWorkerId() ?? 0;
            $query->whereHas(
                'assignments',
                fn (Builder $assignments) => $assignments->where('worker_id', $workerId)
            );

            return;
        }

        if ($user->can_access_all_projects) {
            return;
        }

        $query->whereHas('users', fn (Builder $users) => $users->where('users.id', $user->id));
    }

    public function floors(): HasMany
    {
        return $this->hasMany(ProjectFloor::class)->orderBy('sort_order');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(ProjectArea::class)->orderBy('sort_order');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class)->orderBy('sort_order');
    }

    public function workActivities(): BelongsToMany
    {
        return $this->belongsToMany(WorkActivity::class, 'project_work_activities')
            ->using(ProjectWorkActivity::class)
            ->withPivot(['notes', 'quantity', 'unit', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function isWinkel(): bool
    {
        return $this->kind === ProjectKind::Winkel;
    }

    public function issuerName(): string
    {
        return $this->isWinkel()
            ? (string) config('company.shop_name')
            : (string) config('company.name');
    }

    public function issuerLogo(): string
    {
        return $this->isWinkel()
            ? (string) config('company.shop_logo')
            : (string) config('company.logo');
    }

    public function isSmallWork(): bool
    {
        return $this->kind?->isSmallWork() ?? false;
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkerAssignment::class);
    }

    public function workTickets(): HasMany
    {
        return $this->hasMany(WorkTicket::class)->orderByDesc('id');
    }

    /**
     * @return Collection<int, Worker>
     */
    public function plannedWorkers(): Collection
    {
        return Worker::query()
            ->where('active', true)
            ->whereHas('assignments', fn ($query) => $query->where('project_id', $this->id))
            ->orderBy('name')
            ->get();
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function progressEntries(): HasMany
    {
        return $this->hasMany(WorkProgressEntry::class);
    }

    public function projectNotes(): HasMany
    {
        return $this->hasMany(ProjectNote::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    public function calculationLines(): HasMany
    {
        return $this->hasMany(ProjectCalculationLine::class)->orderBy('row_number')->orderBy('id');
    }

    public function snags(): HasMany
    {
        return $this->hasMany(SnagItem::class)->orderBy('number');
    }

    public function plattegrond(): ?ProjectDocument
    {
        return $this->documents->firstWhere('document_type', 'plattegrond');
    }

    public function productionByWorker(): Collection
    {
        return $this->progressEntries
            ->filter(fn (WorkProgressEntry $entry) => $entry->worker)
            ->groupBy('worker_id')
            ->map(function ($entries) {
                $worker = $entries->first()->worker;
                $lines = $entries->groupBy('work_item_id')->map(function ($group) {
                    $item = $group->first()->workItem;
                    $rooms = $group
                        ->map(fn (WorkProgressEntry $entry) => $entry->area?->label())
                        ->filter()
                        ->unique()
                        ->values();

                    return [
                        'work' => $item?->name ?? 'Werk',
                        'quantity' => (float) $group->sum('completed_quantity'),
                        'unit' => $item?->unit,
                        'rooms' => $rooms,
                    ];
                })->values();

                return [
                    'worker' => $worker,
                    'lines' => $lines,
                    'total_m2' => (float) $entries
                        ->filter(fn (WorkProgressEntry $entry) => $entry->unit === WorkUnit::SquareMeter)
                        ->sum('completed_quantity'),
                    'total_m1' => (float) $entries
                        ->filter(fn (WorkProgressEntry $entry) => $entry->unit === WorkUnit::LinearMeter)
                        ->sum('completed_quantity'),
                    'total_hours' => (float) $entries->sum('worked_hours'),
                ];
            })
            ->sortBy(fn (array $row) => $row['worker']->name)
            ->values();
    }

    public function headlineWorkItem(): ?WorkItem
    {
        $items = $this->workItems->where('unit', WorkUnit::SquareMeter);
        $egaliseren = $items->first(fn (WorkItem $item) => str_contains(mb_strtolower($item->name), 'egaliseer'));

        return $egaliseren
            ?? $items->first(fn (WorkItem $item) => $item->status === 'in_uitvoering')
            ?? $items->sortByDesc('ordered_quantity')->first();
    }

    public function peopleActiveCount(): int
    {
        return $this->assignments->pluck('worker_id')->unique()->count();
    }

    /**
     * Werknummer uit de Materialenstaat/Meetstaat (Werknr), als tekst.
     */
    public function workNumber(): string
    {
        return trim((string) $this->project_number);
    }

    /**
     * Volledige referentie (bijv. "11P230988 TWC studentenhuisvesting Utrecht").
     * Nooit de 11P-code inkorten.
     */
    public function reference(): string
    {
        $notes = trim((string) $this->notes);
        if (preg_match('/^Referentie\s*:\s*(.+)$/isu', $notes, $match)) {
            $reference = trim($match[1]);
            if ($reference !== '') {
                return $reference;
            }
        }

        return trim((string) $this->name);
    }

    /**
     * Werkcode uit de referentie (bijv. "11P230988"), alleen voor weergave.
     */
    public function workCode(): ?string
    {
        // Alleen een voorloopcode met cijfers (11P230988), niet een gewone naam (TMZ …).
        if (preg_match('/^([A-Z0-9]*\d[A-Z0-9]*)\s+\S/iu', $this->reference(), $match)) {
            return $match[1];
        }

        return null;
    }

    /**
     * Zet of wist de 11P-projectcode in de referentie. Het werknummer blijft ongewijzigd.
     */
    public function applyWorkCode(?string $code): void
    {
        $code = strtoupper(preg_replace('/\s+/', '', trim((string) $code)) ?? '');
        $title = $this->displayTitle();
        if ($title === '') {
            $title = trim((string) $this->name);
        }

        $notes = trim((string) $this->notes);
        $isReferentieNotes = $notes === '' || preg_match('/^Referentie\s*:/iu', $notes) === 1;

        if ($code === '') {
            if ($isReferentieNotes && $this->workCode() !== null) {
                $this->notes = null;
            }

            return;
        }

        $referentie = 'Referentie: '.$code.($title !== '' ? ' '.$title : '');
        if (! $isReferentieNotes) {
            $this->notes = trim($notes."\n".$referentie);

            return;
        }

        $this->notes = $referentie;
    }

    /**
     * Projectnaam zonder werkcode (bijv. "TWC studentenhuisvesting Utrecht").
     */
    public function displayTitle(): string
    {
        if ($this->isSmallWork()) {
            return $this->smallWorkHeadline();
        }

        if ($this->isWinkel()) {
            return $this->shopHeadline();
        }

        $reference = $this->reference();
        if (preg_match('/^[A-Z0-9]*\d[A-Z0-9]*\s+(.+)$/iu', $reference, $match)) {
            return trim($match[1]);
        }

        return $reference !== '' ? $reference : trim((string) $this->name);
    }

    public function shopHeadline(): string
    {
        $customer = trim((string) ($this->customer?->name ?? ''));
        $city = trim((string) $this->city);
        $parts = array_filter([$customer, $city], fn (string $part): bool => $part !== '');

        return $parts === [] ? trim((string) $this->name) : implode(' - ', $parts);
    }

    public function smallWorkHeadline(): string
    {
        $description = trim((string) $this->name);
        $city = trim((string) $this->city);
        if ($city !== '' && $description !== '') {
            return $city.' – '.$description;
        }

        $customer = trim((string) ($this->customer?->name ?? ''));
        if ($customer !== '' && $description !== '') {
            return $customer.' – '.$description;
        }

        return $description !== '' ? $description : $this->shopHeadline();
    }

    public function shopWorkLine(): ?string
    {
        if ($this->relationLoaded('workActivities')) {
            $this->workActivities->loadMissing('category');
            $activities = $this->workActivities;
        } else {
            $activities = $this->workActivities()->with('category')->get();
        }

        if ($activities->isEmpty()) {
            return null;
        }

        $prefix = $activities
            ->map(fn (WorkActivity $activity): ?WorkActivityCategory => $activity->category)
            ->filter()
            ->unique('id')
            ->reject(fn (WorkActivityCategory $category): bool => $category->isMisc())
            ->values();

        if ($prefix->isEmpty()) {
            $prefix = $activities
                ->map(fn (WorkActivity $activity): ?WorkActivityCategory => $activity->category)
                ->filter()
                ->unique('id')
                ->values();
        }

        $categories = $prefix->pluck('name')->implode(' + ');
        $names = $activities->pluck('name')->implode(' + ');

        if ($categories === '') {
            return $names !== '' ? $names : null;
        }

        return $categories.' · '.$names;
    }

    public function shopSummary(): string
    {
        return collect([$this->shopHeadline(), $this->shopWorkLine()])
            ->filter()
            ->implode(' · ');
    }

    /**
     * "Projectnr. 11P230988 · Werk 250200015" — alleen labels, geen opslagwijziging.
     * Projectnr. = code uit de referentie; Werk = Werknr/project_number.
     * Non-breaking spaces houden label + nummer bij elkaar.
     */
    public function labeledNumbersLine(): string
    {
        $parts = [];
        $workCode = $this->workCode();
        if ($workCode !== null && $workCode !== '') {
            $parts[] = 'Projectnr.'."\u{00A0}".$workCode;
        }
        if ($this->workNumber() !== '') {
            $parts[] = 'Werk'."\u{00A0}".$this->workNumber();
        }

        return implode("\u{00A0}·\u{00A0}", $parts);
    }

    public function isBehind(): bool
    {
        $item = $this->headlineWorkItem();
        if (! $item || ! $item->planned_end_date || $item->remainingQuantity() <= 0) {
            return false;
        }

        return $item->planned_end_date->lte(now()->addDays(2)) && $item->remainingQuantity() > 0;
    }

    public function nawLine(): ?string
    {
        $street = trim((string) $this->address);
        $place = trim(implode(' ', array_filter([$this->postal_code, $this->city])));
        $line = trim(implode(', ', array_filter([$street, $place])));

        return $line !== '' ? $line : null;
    }

    public function googleMapsUrl(): ?string
    {
        $line = $this->nawLine();
        if ($line === null) {
            return null;
        }

        return 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($line).'&travelmode=driving';
    }

    /**
     * @return array<string, int|string>
     */
    public function planningBoardQuery(): array
    {
        return array_filter([
            'project_id' => $this->id,
            'week' => $this->planned_start_date?->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'period' => $this->planned_end_date ? 'work' : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function daysUntilKlaar(?CarbonInterface $from = null): ?int
    {
        if ($this->planned_end_date === null) {
            return null;
        }

        $fromDay = ($from ?? now())->copy()->startOfDay();
        $untilDay = $this->planned_end_date->copy()->startOfDay();

        return (int) round($fromDay->diffInDays($untilDay));
    }

    public function remainingDaysLabel(?CarbonInterface $from = null): ?string
    {
        $days = $this->daysUntilKlaar($from);
        if ($days === null) {
            return null;
        }
        if ($days === 0) {
            return 'Vandaag klaar';
        }
        if ($days > 0) {
            return $days === 1 ? 'Nog 1 dag' : 'Nog '.$days.' dagen';
        }

        $late = abs($days);

        return $late === 1 ? '1 dag te laat' : $late.' dagen te laat';
    }

    public function planningStartYear(): ?int
    {
        return PlanningWeek::year($this->planned_start_date);
    }

    public function planningStartWeek(): ?int
    {
        return PlanningWeek::number($this->planned_start_date);
    }

    public function planningEndYear(): ?int
    {
        return PlanningWeek::year($this->planned_end_date);
    }

    public function planningEndWeek(): ?int
    {
        return PlanningWeek::number($this->planned_end_date);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function applyPlanningWindow(array $input): void
    {
        $dates = PlanningWeek::resolve($input, $this->planned_start_date, $this->planned_end_date);
        $oldStart = $this->planned_start_date?->toDateString();
        $oldEnd = $this->planned_end_date?->toDateString();

        $this->forceFill([
            'planned_start_date' => $dates['start'],
            'planned_end_date' => $dates['end'],
        ])->save();

        $this->workItems()
            ->where(function (Builder $query) use ($oldStart): void {
                $query->whereNull('planned_start_date');
                if ($oldStart !== null) {
                    $query->orWhereDate('planned_start_date', $oldStart);
                }
            })
            ->update(['planned_start_date' => $dates['start']]);

        $this->workItems()
            ->where(function (Builder $query) use ($oldEnd): void {
                $query->whereNull('planned_end_date');
                if ($oldEnd !== null) {
                    $query->orWhereDate('planned_end_date', $oldEnd);
                }
            })
            ->update(['planned_end_date' => $dates['end']]);
    }

    public function applyPlanningWeeks(?int $startYear, ?int $startWeek, ?int $klaarYear, ?int $klaarWeek): void
    {
        $this->applyPlanningWindow([
            'start_year' => $startYear,
            'start_week' => $startWeek,
            'klaar_year' => $klaarYear,
            'klaar_week' => $klaarWeek,
        ]);
    }
}
