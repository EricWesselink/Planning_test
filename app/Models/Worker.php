<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\FlooringSpecialty;
use App\Enums\WorkUnit;
use App\Support\Format;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'name', 'employment_type', 'company', 'contact_name', 'phone', 'email',
    'color', 'address', 'postal_code', 'city',
    'specialty', 'people_count', 'crew_names', 'crew_members', 'default_hours_per_day', 'active', 'friday_off', 'unavailable',
])]
class Worker extends Model
{
    protected $attributes = [
        'people_count' => 1,
        'friday_off' => false,
        'unavailable' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (Worker $worker): void {
            $taken = static::query()->pluck('color')->all();
            $current = Format::normalizeColor($worker->color);
            $worker->color = ($current !== null && ! Format::colorIsUsed($current, $taken))
                ? $current
                : Format::nextDistinctColor($taken);
        });

        static::saved(function (Worker $worker): void {
            $worker->syncCrewPeople();
        });
    }

    public static function reassignCollidingColors(): void
    {
        $taken = [];

        static::query()->orderBy('id')->each(function (Worker $worker) use (&$taken): void {
            $current = Format::normalizeColor($worker->color);
            if ($current !== null && ! in_array($current, $taken, true)) {
                $taken[] = $current;

                return;
            }

            $color = Format::nextDistinctColor($taken);
            $worker->forceFill(['color' => $color])->saveQuietly();
            $taken[] = $color;
        });
    }

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'people_count' => 'integer',
            'crew_members' => 'array',
            'default_hours_per_day' => 'decimal:2',
            'active' => 'boolean',
            'friday_off' => 'boolean',
            'unavailable' => 'boolean',
        ];
    }

    public function planName(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : 'Onbekend';
    }

    public function displayName(): string
    {
        if ($this->employment_type?->isExternal() && $this->company) {
            return $this->employment_type->label().' '.$this->company;
        }

        return $this->name;
    }

    public function shortName(): string
    {
        if ($this->employment_type?->isExternal() && $this->company) {
            return $this->company;
        }

        return explode(' ', trim($this->name), 2)[0];
    }

    public function planColor(): string
    {
        return Format::normalizeColor($this->color) ?? Format::planColor((int) ($this->id ?: 1));
    }

    public function peopleCount(): int
    {
        return max(1, (int) $this->people_count);
    }

    public function peopleCountLabel(): string
    {
        $count = $this->peopleCount();

        return $count === 1 ? '1 persoon' : $count.' personen';
    }

    /**
     * @return list<array{id?: int, name: string, phone: string}>
     */
    public function crewMembersForForm(): array
    {
        if ($this->exists && $this->crewPeople->isNotEmpty()) {
            return self::normalizeCrewMembers(
                $this->crewPeople
                    ->map(fn (CrewMember $member): array => [
                        'id' => $member->id,
                        'name' => (string) $member->name,
                        'phone' => (string) $member->phone,
                    ])
                    ->values()
                    ->all(),
                $this->peopleCount(),
            );
        }

        return $this->crewMembers();
    }

    public function crewNamesLabel(): ?string
    {
        return self::joinedCrewNames($this->crewMembers());
    }

    /**
     * @return list<array{name: string, phone: string}>
     */
    public function crewMembers(): array
    {
        if (is_array($this->crew_members)) {
            return array_map(
                static fn (array $member): array => [
                    'name' => $member['name'],
                    'phone' => $member['phone'],
                ],
                self::normalizeCrewMembers($this->crew_members, $this->peopleCount()),
            );
        }

        return self::legacyCrewMembers((string) $this->crew_names, (string) $this->phone, $this->peopleCount());
    }

    /**
     * @param  list<mixed>|array<int|string, mixed>  $members
     * @return list<array{id?: int, name: string, phone: string}>
     */
    public static function normalizeCrewMembers(array $members, int $count): array
    {
        $count = max(1, min(50, $count));
        $normalized = [];

        foreach ($members as $member) {
            if (! is_array($member)) {
                continue;
            }

            $row = [
                'name' => trim((string) ($member['name'] ?? '')),
                'phone' => trim((string) ($member['phone'] ?? '')),
            ];
            $id = (int) ($member['id'] ?? 0);
            if ($id > 0) {
                $row['id'] = $id;
            }
            $normalized[] = $row;
        }

        $namedBefore = 0;
        foreach ($normalized as $row) {
            if ($row['name'] !== '') {
                $namedBefore++;
            }
        }
        $normalized = self::collapseNamedCrewMembers($normalized);
        $namedAfter = 0;
        foreach ($normalized as $row) {
            if ($row['name'] !== '') {
                $namedAfter++;
            }
        }
        $count = max(1, min(50, $count - max(0, $namedBefore - $namedAfter)));

        while (count($normalized) < $count) {
            $normalized[] = ['name' => '', 'phone' => ''];
        }

        return array_values(array_slice($normalized, 0, $count));
    }

    /**
     * @param  list<array{id?: int, name: string, phone: string}>  $members
     * @return list<array{id?: int, name: string, phone: string}>
     */
    public static function collapseNamedCrewMembers(array $members): array
    {
        $collapsed = [];
        $indexByName = [];

        foreach ($members as $member) {
            $name = trim((string) ($member['name'] ?? ''));
            if ($name === '') {
                $collapsed[] = $member;

                continue;
            }

            $key = mb_strtolower($name);
            if (isset($indexByName[$key])) {
                $index = $indexByName[$key];
                if (($collapsed[$index]['phone'] ?? '') === '' && trim((string) ($member['phone'] ?? '')) !== '') {
                    $collapsed[$index]['phone'] = trim((string) $member['phone']);
                }

                continue;
            }

            $indexByName[$key] = count($collapsed);
            $collapsed[] = $member;
        }

        return $collapsed;
    }

    /**
     * @return list<array{name: string, phone: string}>
     */
    public static function legacyCrewMembers(string $crewNames, string $phone, int $count): array
    {
        $names = array_values(array_filter(
            array_map(
                static fn (string $name): string => trim($name),
                explode(',', $crewNames),
            ),
            static fn (string $name): bool => $name !== '',
        ));

        $members = [];
        foreach ($names as $index => $name) {
            $members[] = [
                'name' => $name,
                'phone' => $index === 0 ? trim($phone) : '',
            ];
        }

        $members = self::normalizeCrewMembers($members, $count);
        if ($members[0]['phone'] === '' && trim($phone) !== '') {
            $members[0]['phone'] = trim($phone);
        }

        return $members;
    }

    /**
     * @param  list<array{name: string, phone: string}>  $members
     */
    public static function joinedCrewNames(array $members): ?string
    {
        $names = [];
        $seen = [];
        foreach ($members as $member) {
            $name = trim((string) ($member['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $names[] = $name;
        }

        if ($names === []) {
            return null;
        }

        $joined = implode(', ', $names);

        return mb_strlen($joined) <= 255 ? $joined : mb_substr($joined, 0, 255);
    }

    /**
     * @param  list<array{name: string, phone: string}>  $members
     */
    public static function firstCrewPhone(array $members): ?string
    {
        foreach ($members as $member) {
            $phone = trim((string) ($member['phone'] ?? ''));
            if ($phone !== '') {
                return $phone;
            }
        }

        return null;
    }

    /** @return list<FlooringSpecialty> */
    public function specialtyCases(): array
    {
        return FlooringSpecialty::selectedFrom(FlooringSpecialty::parts((string) $this->specialty));
    }

    /** @return list<string> */
    public function specialtyValues(): array
    {
        return FlooringSpecialty::inputValues((string) $this->specialty);
    }

    public function specialtyLabel(): ?string
    {
        return FlooringSpecialty::storedLabels(FlooringSpecialty::parts((string) $this->specialty));
    }

    /**
     * @param  list<string>  $values
     */
    public function hasAnySpecialty(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->hasSpecialty((string) $value)) {
                return true;
            }
        }

        return false;
    }

    public function hasSpecialty(string $value): bool
    {
        return FlooringSpecialty::storedHas((string) $this->specialty, $value);
    }

    /**
     * Eigen staff who lay or finish floors, not intake-only people.
     */
    public function doesProductionFloorWork(): bool
    {
        if ($this->employment_type !== EmploymentType::Eigen) {
            return false;
        }

        $parts = FlooringSpecialty::inputValues((string) $this->specialty);
        if ($parts === []) {
            return true;
        }

        foreach ($parts as $part) {
            $case = FlooringSpecialty::caseFrom($part);
            if (! $case?->isIntakeWork()) {
                return true;
            }
        }

        return false;
    }

    public function nawLine(): ?string
    {
        $street = trim((string) $this->address);
        $place = trim(implode(' ', array_filter([$this->postal_code, $this->city])));
        $line = trim(implode(', ', array_filter([$street, $place])));

        return $line !== '' ? $line : null;
    }

    public function crewPeople(): HasMany
    {
        return $this->hasMany(CrewMember::class)->orderBy('sort_order')->orderBy('id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeWithLogin(Builder $query): void
    {
        $query->whereHas('users');
    }

    public function scopeOwnStaff(Builder $query): void
    {
        $query->where('employment_type', EmploymentType::Eigen);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class)->whereNull('crew_member_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkerAssignment::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(WorkerAvailability::class)
            ->orderBy('start_date')
            ->orderBy('id');
    }

    public function syncCrewPeople(): void
    {
        $members = is_array($this->crew_members)
            ? self::normalizeCrewMembers($this->crew_members, $this->peopleCount())
            : $this->crewMembers();
        $existing = CrewMember::query()
            ->where('worker_id', $this->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $keepIds = [];
        $usedIds = [];

        foreach ($members as $index => $member) {
            $requestedId = (int) ($member['id'] ?? 0);
            $row = $requestedId > 0
                ? $existing->firstWhere('id', $requestedId)
                : $existing->first(fn (CrewMember $person): bool => ! in_array($person->id, $usedIds, true));
            $values = [
                'name' => (string) ($member['name'] ?? ''),
                'phone' => (string) ($member['phone'] ?? ''),
                'sort_order' => $index,
            ];

            if ($row && (int) $row->worker_id === (int) $this->id) {
                $row->fill($values)->save();
                $keepIds[] = $row->id;
                $usedIds[] = $row->id;

                continue;
            }

            $created = $this->crewPeople()->create($values);
            $keepIds[] = $created->id;
            $usedIds[] = $created->id;
        }

        CrewMember::query()
            ->where('worker_id', $this->id)
            ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
            ->when($keepIds === [], fn ($query) => $query)
            ->whereDoesntHave('assignments')
            ->delete();

        $this->collapseDuplicateCrewPeople();
    }

    public function collapseDuplicateCrewPeople(): void
    {
        $people = CrewMember::query()
            ->where('worker_id', $this->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $keepers = [];
        $removed = false;

        foreach ($people as $person) {
            $name = trim((string) $person->name);
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (! isset($keepers[$key])) {
                $keepers[$key] = $person;

                continue;
            }

            $this->absorbCrewMember($keepers[$key], $person);
            $removed = true;
        }

        if (! $removed) {
            return;
        }

        $remaining = CrewMember::query()
            ->where('worker_id', $this->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $members = [];
        foreach ($remaining as $index => $person) {
            if ((int) $person->sort_order !== $index) {
                $person->sort_order = $index;
                $person->save();
            }
            $members[] = [
                'id' => $person->id,
                'name' => (string) $person->name,
                'phone' => (string) $person->phone,
            ];
        }

        $this->forceFill([
            'crew_members' => $members,
            'crew_names' => self::joinedCrewNames($members),
            'people_count' => max(1, count($members)),
            'phone' => self::firstCrewPhone($members) ?? $this->phone,
        ])->saveQuietly();
        $this->unsetRelation('crewPeople');
    }

    private function absorbCrewMember(CrewMember $keeper, CrewMember $duplicate): void
    {
        $changed = false;
        if (trim((string) $keeper->phone) === '' && trim((string) $duplicate->phone) !== '') {
            $keeper->phone = $duplicate->phone;
            $changed = true;
        }
        if (trim((string) $keeper->specialty) === '' && trim((string) $duplicate->specialty) !== '') {
            $keeper->specialty = $duplicate->specialty;
            $changed = true;
        }
        if ($changed) {
            $keeper->save();
        }

        $duplicateUser = User::query()->where('crew_member_id', $duplicate->id)->first();
        if ($duplicateUser !== null) {
            $keeperHasUser = User::query()->where('crew_member_id', $keeper->id)->exists();
            $duplicateUser->forceFill([
                'crew_member_id' => $keeperHasUser ? null : $keeper->id,
            ])->save();
        }

        $pivots = DB::table('crew_member_worker_assignment')
            ->where('crew_member_id', $duplicate->id)
            ->get();
        foreach ($pivots as $pivot) {
            $exists = DB::table('crew_member_worker_assignment')
                ->where('worker_assignment_id', $pivot->worker_assignment_id)
                ->where('crew_member_id', $keeper->id)
                ->exists();
            if ($exists) {
                DB::table('crew_member_worker_assignment')->where('id', $pivot->id)->delete();

                continue;
            }

            DB::table('crew_member_worker_assignment')->where('id', $pivot->id)->update([
                'crew_member_id' => $keeper->id,
                'updated_at' => now(),
            ]);
        }

        $duplicate->delete();
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function workTickets(): HasMany
    {
        return $this->hasMany(WorkTicket::class)->orderByDesc('id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(WorkerRate::class);
    }

    public function hourlyRate(): ?WorkerRate
    {
        return $this->rates->first(
            fn (WorkerRate $rate): bool => mb_strtolower((string) $rate->specialty) === WorkerRate::HOURLY_SPECIALTY
                && $rate->unit === WorkUnit::Hours
        );
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function progressEntries(): HasMany
    {
        return $this->hasMany(WorkProgressEntry::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->withPivot(['valid_from', 'valid_until'])
            ->withTimestamps();
    }
}
