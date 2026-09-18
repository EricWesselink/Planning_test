<?php

namespace App\Services;

use App\Enums\FlooringSpecialty;
use App\Enums\MeasurementMaterialLocation;
use App\Enums\WorkUnit;
use App\Models\MeasurementForm;
use App\Models\MeasurementFormRow;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkActivity;
use App\Models\Worker;
use App\Support\Format;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

class MeasurementFormService
{
    public const BLANK_ROW_COUNT = 5;

    public function __construct(
        private WorkerAvailabilityService $availability,
    ) {}

    /**
     * @return list<WorkUnit>
     */
    public static function units(): array
    {
        return [WorkUnit::LinearMeter, WorkUnit::SquareMeter];
    }

    /**
     * @return list<string>
     */
    public static function unitValues(): array
    {
        return array_map(fn (WorkUnit $unit): string => $unit->value, self::units());
    }

    /**
     * @return Collection<int, User>
     */
    public function meterUsers(?int $keepId = null, ?CarbonInterface $on = null): Collection
    {
        $users = Worker::query()
            ->ownStaff()
            ->where('active', true)
            ->with(['user', 'users', 'availabilities'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (Worker $worker): bool => $worker->hasSpecialty(FlooringSpecialty::Inmeten->value))
            ->map(function (Worker $worker) use ($on, $keepId): ?User {
                $user = $worker->user ?? $worker->users->first();
                if (! $user instanceof User || ! $user->active) {
                    return null;
                }

                if ($on !== null && $this->availability->isAwayOn($worker, $on) && (int) $user->id !== (int) $keepId) {
                    return null;
                }

                return $user;
            })
            ->filter()
            ->values();

        if ($keepId !== null && $keepId > 0 && ! $users->contains(fn (User $user): bool => (int) $user->id === $keepId)) {
            $kept = User::query()->find($keepId);
            if ($kept instanceof User) {
                $users->push($kept);
            }
        }

        return $users
            ->sortBy([
                ['name', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function sync(Project $project, array $input): ?MeasurementForm
    {
        $header = $this->headerFrom($input);
        $rows = $this->filledRows($input['rows'] ?? []);

        if ($header === [] && $rows === []) {
            $project->measurementForm?->delete();

            return null;
        }

        $form = $project->measurementForm ?? new MeasurementForm(['project_id' => $project->id]);
        $form->fill($header + [
            'meter_user_id' => $header['meter_user_id'] ?? null,
            'ordered_at' => $header['ordered_at'] ?? null,
            'installation_at' => $header['installation_at'] ?? null,
        ]);
        $form->project_id = $project->id;
        $form->save();

        $form->rows()->delete();
        foreach ($rows as $index => $row) {
            $form->rows()->create($row + ['sort_order' => $index + 1]);
        }

        return $form->fresh(['meter', 'rows']) ?? $form;
    }

    public function isFilled(?MeasurementForm $form): bool
    {
        return $form instanceof MeasurementForm && $form->isFilled();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function formRows(?MeasurementForm $form, array $oldRows = []): array
    {
        if ($oldRows !== []) {
            $rows = [];
            foreach ($oldRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[] = $this->rowInput($row);
            }

            return $rows === [] ? $this->blankRows() : $rows;
        }

        if ($form instanceof MeasurementForm && $form->rows->isNotEmpty()) {
            return $form->rows
                ->map(fn (MeasurementFormRow $row): array => $this->rowInput([
                    'room' => $row->room,
                    'product' => $row->product,
                    'brand' => $row->brand,
                    'type' => $row->type,
                    'color_number' => $row->color_number,
                    'quantity' => $row->quantity,
                    'unit' => $row->unit?->value,
                    'underlay' => $row->underlay,
                    'skirting' => $row->skirting,
                    'steps' => $row->steps,
                    'profile' => $row->profile,
                    'available_on_site' => $row->available_on_site,
                    'available_location' => $row->available_location?->value,
                ]))
                ->values()
                ->all();
        }

        return $this->blankRows();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blankRows(int $count = self::BLANK_ROW_COUNT): array
    {
        return array_map(fn (): array => $this->rowInput([]), range(1, $count));
    }

    /**
     * @return array{
     *     form: MeasurementForm,
     *     filename: string,
     *     documentTitle: string,
     *     logo: ?string,
     *     logoUrl: string,
     *     companyName: string,
     *     companyAddress: string,
     *     companyPostalCode: string,
     *     companyCity: string,
     *     companyEmail: string,
     *     companyPhone: string,
     *     customerName: string,
     *     address: ?string,
     *     postalCode: ?string,
     *     city: ?string,
     *     phone: ?string,
     *     email: ?string,
     *     meterName: ?string,
     *     orderedOn: ?string,
     *     installationOn: ?string,
     *     rows: list<array<string, mixed>>
     * }|null
     */
    public function pdfData(Project $project): ?array
    {
        $form = $project->measurementForm;
        if (! $this->isFilled($form)) {
            return null;
        }

        $form->loadMissing(['meter', 'rows', 'project.customer']);
        $logoRelative = $project->issuerLogo();

        return [
            'form' => $form,
            'filename' => $this->filename($project),
            'documentTitle' => 'INMEETFORMULIER VLOEREN / PLINT / TRAP',
            'logo' => $this->publicImagePath($logoRelative),
            'logoUrl' => asset($logoRelative),
            'companyName' => $project->issuerName(),
            'companyAddress' => (string) config('company.address'),
            'companyPostalCode' => (string) config('company.postal_code'),
            'companyCity' => (string) config('company.city'),
            'companyEmail' => (string) config('company.email'),
            'companyPhone' => (string) config('company.phone'),
            'customerName' => (string) ($project->customer?->name ?? $project->name),
            'address' => $project->address,
            'postalCode' => $project->postal_code,
            'city' => $project->city,
            'phone' => $project->contact_phone ?: $project->customer?->phone,
            'email' => $project->contact_email ?: $project->customer?->email,
            'meterName' => $form->meter?->name,
            'orderedOn' => $form->ordered_at?->format('d-m-Y'),
            'installationOn' => $form->installation_at?->format('d-m-Y'),
            'rows' => $form->rows
                ->map(fn (MeasurementFormRow $row): array => [
                    'room' => (string) ($row->room ?? ''),
                    'product' => (string) ($row->product ?? ''),
                    'brand' => (string) ($row->brand ?? ''),
                    'type' => (string) ($row->type ?? ''),
                    'color_number' => (string) ($row->color_number ?? ''),
                    'quantity' => $row->quantityLabel(),
                    'underlay' => (string) ($row->underlay ?? ''),
                    'skirting' => (string) ($row->skirting ?? ''),
                    'steps' => (string) ($row->steps ?? ''),
                    'profile' => (string) ($row->profile ?? ''),
                    'available_on_site' => $row->availableLabel(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     meterUsers: Collection<int, User>,
     *     measurementRows: list<array<string, mixed>>,
     *     measurementMeterUserId: int|string|null,
     *     measurementOrderedAt: ?string,
     *     measurementInstallationAt: ?string,
     *     measurementFilled: bool,
     *     measurementOpen: bool
     * }
     */
    public function viewData(?Project $project = null): array
    {
        $form = $project?->measurementForm;
        $old = old('measurement');
        $oldRows = is_array($old['rows'] ?? null) ? $old['rows'] : [];
        $orderedAt = old('measurement.ordered_at', $form?->ordered_at?->toDateString());
        $on = is_string($orderedAt) && $orderedAt !== '' ? Carbon::parse($orderedAt) : null;

        return [
            'meterUsers' => $this->meterUsers($form?->meter_user_id, $on),
            'measurementRows' => $this->formRows($form, $oldRows),
            'measurementMeterUserId' => old('measurement.meter_user_id', $form?->meter_user_id),
            'measurementOrderedAt' => $orderedAt,
            'measurementInstallationAt' => old('measurement.installation_at', $form?->installation_at?->toDateString()),
            'measurementFilled' => $this->isFilled($form),
            'measurementOpen' => is_array($old),
        ];
    }

    public function filename(Project $project): string
    {
        $parts = [
            'Inmeetformulier',
            $project->customer?->name ?? $project->name,
            $project->city,
        ];
        $safe = collect($parts)
            ->map(function (mixed $part): string {
                $clean = preg_replace('/[^A-Za-z0-9]+/', '-', trim((string) $part)) ?? '';

                return trim($clean, '-');
            })
            ->filter()
            ->implode('_');

        return ($safe !== '' ? $safe : 'Inmeetformulier').'.pdf';
    }

    public function validateAgainstShopWork(Validator $validator, Request $request, ?Project $project = null): void
    {
        $this->validateMeterUser($validator, $request, $project);

        $rows = $request->input('measurement.rows');
        if (! is_array($rows)) {
            return;
        }

        $project?->loadMissing('measurementForm.rows');
        $selected = $this->selectedFloorProducts($request);
        $allowed = $selected->pluck('name')->filter()->values()->all();
        $legacy = collect($project?->measurementForm?->rows)
            ->pluck('product')
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->values()
            ->all();
        $allowed = array_values(array_unique([...$allowed, ...$legacy]));
        $byName = $selected->keyBy('name');
        $allocated = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $product = $this->nullableString($row['product'] ?? null);
            if ($product === null) {
                continue;
            }
            if ($allowed !== [] && ! in_array($product, $allowed, true)) {
                $validator->errors()->add(
                    'measurement.rows.'.$index.'.product',
                    'Kies een aangevinkt vloerproduct bij Werkzaamheden.',
                );

                continue;
            }
            if ($allowed === [] && $legacy === []) {
                $validator->errors()->add(
                    'measurement.rows.'.$index.'.product',
                    'Vink eerst een vloerproduct aan bij Werkzaamheden.',
                );

                continue;
            }

            $quantity = $this->parseQuantity($row['quantity'] ?? null);
            $unit = WorkUnit::tryFrom((string) ($row['unit'] ?? ''));
            $activity = $byName->get($product);
            $shopUnit = $activity instanceof WorkActivity
                ? WorkUnit::tryFrom((string) $request->input('activity_units.'.$activity->id, $activity->defaultShopUnit()->value))
                : null;
            if ($unit === null && in_array($shopUnit, [WorkUnit::SquareMeter, WorkUnit::LinearMeter], true)) {
                $unit = $shopUnit;
            }
            if ($quantity === null || ($unit !== WorkUnit::SquareMeter && $unit !== WorkUnit::LinearMeter)) {
                continue;
            }
            if ($shopUnit instanceof WorkUnit && $unit !== $shopUnit) {
                continue;
            }

            $allocated[$product][$unit->value] = ($allocated[$product][$unit->value] ?? 0) + $quantity;
        }

        foreach ($allocated as $name => $units) {
            $activity = $byName->get($name);
            if (! $activity instanceof WorkActivity) {
                continue;
            }
            $available = $this->parseQuantity($request->input('activity_quantities.'.$activity->id));
            if ($available === null) {
                continue;
            }
            $shopUnit = WorkUnit::tryFrom((string) $request->input('activity_units.'.$activity->id, $activity->defaultShopUnit()->value));
            if ($shopUnit !== WorkUnit::SquareMeter && $shopUnit !== WorkUnit::LinearMeter) {
                continue;
            }
            $used = (float) ($units[$shopUnit->value] ?? 0);
            if ($used <= $available + 0.001) {
                continue;
            }

            $message = 'Te veel ingevoerd. '.$name.': '.$this->qtyWithUnit($available, $shopUnit)
                .' beschikbaar, '.$this->qtyWithUnit($used, $shopUnit).' reeds verdeeld.';
            $validator->errors()->add('measurement.rows', $message);
        }
    }

    private function validateMeterUser(Validator $validator, Request $request, ?Project $project): void
    {
        $meterId = (int) $request->input('measurement.meter_user_id');
        if ($meterId <= 0) {
            return;
        }

        $keepId = $project?->measurementForm?->meter_user_id;
        $allowed = $this->meterUsers($keepId);

        if (! $allowed->contains(fn (User $user): bool => (int) $user->id === $meterId)) {
            $validator->errors()->add(
                'measurement.meter_user_id',
                'Kies een eigen medewerker met werkzaamheid Inmeten.',
            );

            return;
        }

        $orderedAt = $request->input('measurement.ordered_at');
        $on = is_string($orderedAt) && $orderedAt !== '' ? Carbon::parse($orderedAt) : null;
        if ($on === null) {
            return;
        }

        $user = User::query()->with('worker.availabilities')->find($meterId);
        $worker = $user?->worker;
        if ($worker instanceof Worker && $this->availability->isAwayOn($worker, $on)) {
            $validator->errors()->add(
                'measurement.meter_user_id',
                $worker->planName().' is die dag niet beschikbaar.',
            );
        }
    }

    /**
     * @return Collection<int, WorkActivity>
     */
    public function selectedFloorProducts(Request $request): Collection
    {
        $ids = collect($request->input('work_activity_ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return WorkActivity::query()
            ->with('category')
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (WorkActivity $activity): bool => $activity->isMeasurementProduct())
            ->values();
    }

    private function qtyWithUnit(float $quantity, WorkUnit $unit): string
    {
        $decimals = fmod($quantity, 1.0) === 0.0 ? 0 : 2;

        return Format::qty($quantity, $decimals).' '.$unit->label();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{meter_user_id?: ?int, ordered_at?: ?string, installation_at?: ?string}
     */
    private function headerFrom(array $input): array
    {
        $header = [];
        $meterId = (int) ($input['meter_user_id'] ?? 0);
        if ($meterId > 0) {
            $header['meter_user_id'] = $meterId;
        }
        $ordered = $this->nullableDate($input['ordered_at'] ?? null);
        if ($ordered !== null) {
            $header['ordered_at'] = $ordered;
        }
        $installation = $this->nullableDate($input['installation_at'] ?? null);
        if ($installation !== null) {
            $header['installation_at'] = $installation;
        }

        return $header;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filledRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $filled = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->persistableRow($row);
            if ($normalized !== null) {
                $filled[] = $normalized;
            }
        }

        return $filled;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function persistableRow(array $row): ?array
    {
        $strings = [];
        foreach (['room', 'product', 'brand', 'type', 'color_number', 'underlay', 'skirting', 'steps', 'profile'] as $field) {
            $strings[$field] = $this->nullableString($row[$field] ?? null);
        }

        $quantity = $this->parseQuantity($row['quantity'] ?? null);
        $unit = WorkUnit::tryFrom((string) ($row['unit'] ?? ''));
        if ($unit !== null && ! in_array($unit, self::units(), true)) {
            $unit = null;
        }
        $available = filter_var($row['available_on_site'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $location = $available
            ? MeasurementMaterialLocation::tryFrom((string) ($row['available_location'] ?? ''))
            : null;

        $empty = collect($strings)->every(fn (?string $value): bool => $value === null)
            && $quantity === null
            && $unit === null
            && ! $available;

        if ($empty) {
            return null;
        }

        return [
            ...$strings,
            'quantity' => $quantity,
            'unit' => $unit?->value,
            'available_on_site' => $available,
            'available_location' => $location?->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowInput(array $row): array
    {
        return [
            'room' => (string) ($row['room'] ?? ''),
            'product' => (string) ($row['product'] ?? ''),
            'brand' => (string) ($row['brand'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'color_number' => (string) ($row['color_number'] ?? ''),
            'quantity' => $row['quantity'] ?? '',
            'unit' => (string) ($row['unit'] ?? ''),
            'underlay' => (string) ($row['underlay'] ?? ''),
            'skirting' => (string) ($row['skirting'] ?? ''),
            'steps' => (string) ($row['steps'] ?? ''),
            'profile' => (string) ($row['profile'] ?? ''),
            'available_on_site' => filter_var($row['available_on_site'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'available_location' => (string) ($row['available_location'] ?? ''),
        ];
    }

    private function parseQuantity(mixed $value): ?float
    {
        $normalized = Format::decimalInput($value);
        if ($normalized === null || $normalized === '') {
            return null;
        }
        if (! is_numeric($normalized)) {
            return null;
        }

        $quantity = round((float) $normalized, 2);

        return $quantity > 0 ? $quantity : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function publicImagePath(string $relative): ?string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return null;
        }

        $absolute = public_path($relative);
        if (! is_file($absolute)) {
            return null;
        }

        return 'file://'.str_replace('\\', '/', $absolute);
    }
}
