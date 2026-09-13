<?php

namespace App\Services;

use App\Enums\VoucherPriceKind;
use App\Enums\VoucherPriceSource;
use App\Enums\VoucherType;
use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Models\Worker;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use App\Support\Format;
use App\Support\VoucherActivityGroups;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherDraftService
{
    public function __construct(
        private ProductionOverviewService $overview,
        private VoucherPriceResolver $prices,
    ) {}

    /**
     * @return array{
     *     worker: Worker,
     *     project: Project,
     *     type: VoucherType,
     *     opdracht: ?Voucher,
     *     lines: list<array<string, mixed>>,
     *     existing: Collection<int, Voucher>,
     *     filters: array{from: ?string, to: ?string},
     *     warnings: list<string>,
     *     billing: array<string, mixed>,
     *     canAddLines: bool
     * }
     */
    public function draft(
        Worker $worker,
        Project $project,
        VoucherType $type,
        ?string $from = null,
        ?string $to = null,
        ?User $user = null,
        ?int $exceptVoucherId = null,
        ?array $selectedKeys = null,
    ): array {
        $worker->loadMissing('rates');
        $opdracht = Voucher::latestOpdracht($worker->id, $project->id);
        $orders = WorkOrder::query()
            ->where('worker_id', $worker->id)
            ->where('project_id', $project->id)
            ->whereNotNull('unit_price')
            ->orderByDesc('id')
            ->get();

        $invoicedState = $this->invoicedState($worker->id, $project->id, $exceptVoucherId);
        $wanted = $selectedKeys === null ? null : array_flip($selectedKeys);

        $lines = $type === VoucherType::Facturatie
            ? ($opdracht ? $this->facturatieLinesFromOpdracht($opdracht, $invoicedState, $wanted) : [])
            : $this->opdrachtLinesFromProduction($worker, $project, $from, $to, $user, $opdracht, $orders, $wanted);

        return [
            'worker' => $worker,
            'project' => $project,
            'type' => $type,
            'opdracht' => $opdracht,
            'lines' => $lines,
            'existing' => Voucher::query()
                ->where('worker_id', $worker->id)
                ->where('project_id', $project->id)
                ->orderByDesc('id')
                ->get(),
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
            'warnings' => [],
            'billing' => $this->billingFromOpdrachtAndInvoiced($opdracht, $invoicedState),
            'canAddLines' => $type === VoucherType::Opdracht,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $workedDates
     */
    public function store(
        Worker $worker,
        Project $project,
        VoucherType $type,
        array $lines,
        ?User $user,
        ?string $notes,
        ?string $from = null,
        ?string $to = null,
        array $workedDates = [],
    ): Voucher {
        return DB::transaction(function () use ($worker, $project, $type, $lines, $user, $notes, $from, $to, $workedDates) {
            Voucher::query()
                ->where('worker_id', $worker->id)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->get();

            $draft = $this->draft($worker, $project, $type, $from, $to, $user);
            if ($type === VoucherType::Facturatie && $draft['opdracht'] === null) {
                throw ValidationException::withMessages([
                    'type' => 'Maak eerst een opdrachtbon voordat je een bon kunt opmaken.',
                ]);
            }
            $invoicedState = $this->invoicedState($worker->id, $project->id);
            $rows = $this->rowsFromInput(
                $lines,
                $type,
                $draft['lines'],
                $invoicedState,
            );
            $total = collect($rows)->sum('amount');

            $voucher = Voucher::query()->create([
                'number' => Voucher::nextNumber(),
                'type' => $type,
                'worker_id' => $worker->id,
                'project_id' => $project->id,
                'parent_id' => $type === VoucherType::Facturatie ? $draft['opdracht']?->id : null,
                'created_by' => $user?->id,
                'issued_on' => now()->toDateString(),
                'worked_dates' => $workedDates === [] ? null : $workedDates,
                'total_amount' => round((float) $total, 2),
                'notes' => $notes,
            ]);

            foreach ($rows as $row) {
                $voucher->lines()->create($row);
            }

            return $voucher->load(['worker', 'project.customer', 'lines']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $workedDates
     */
    public function replaceLines(Voucher $voucher, array $lines, ?string $notes, ?User $user, array $workedDates = []): Voucher
    {
        return DB::transaction(function () use ($voucher, $lines, $notes, $user, $workedDates) {
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            Voucher::query()
                ->where('worker_id', $voucher->worker_id)
                ->where('project_id', $voucher->project_id)
                ->lockForUpdate()
                ->get();

            $voucher->loadMissing(['worker', 'project']);
            $draft = $this->draft(
                $voucher->worker,
                $voucher->project,
                $voucher->type,
                null,
                null,
                $user,
                $voucher->id,
            );
            $rows = $this->rowsFromInput(
                $lines,
                $voucher->type,
                $draft['lines'],
                $this->invoicedState($voucher->worker_id, $voucher->project_id, $voucher->id),
            );
            $total = collect($rows)->sum('amount');

            $voucher->lines()->delete();
            foreach ($rows as $row) {
                $voucher->lines()->create($row);
            }

            $voucher->forceFill([
                'total_amount' => round((float) $total, 2),
                'notes' => $notes,
                'worked_dates' => $workedDates === [] ? null : $workedDates,
            ])->save();

            return $voucher->fresh(['worker', 'project.customer', 'lines', 'parent']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $draftLines
     * @param  array{map?: array<string, float>, amount_map?: array<string, float>, amount?: float, m2?: float}  $invoiced
     * @return list<array<string, mixed>>
     */
    private function rowsFromInput(array $lines, VoucherType $type, array $draftLines, array $invoiced = []): array
    {
        if ($type === VoucherType::Opdracht) {
            $lines = $this->unifyOpdrachtActivityPrices($lines);
        }

        $allowed = collect($draftLines)->keyBy(
            fn (array $line): string => VoucherActivityGroups::activityKeyFromLine($line)
        );
        $invoicedMap = $invoiced['map'] ?? [];
        $invoicedAmounts = $invoiced['amount_map'] ?? [];
        $usedQty = [];
        $usedAmount = [];

        $rows = [];

        foreach ($lines as $index => $input) {
            $quantity = round((float) ($input['quantity'] ?? 0), 2);
            $areaId = isset($input['project_area_id']) && $input['project_area_id'] !== '' ? (int) $input['project_area_id'] : null;
            $itemId = isset($input['work_item_id']) && $input['work_item_id'] !== '' ? (int) $input['work_item_id'] : null;
            $key = VoucherActivityGroups::activityKeyFromLine($input);
            $source = $allowed->get($key);
            $kind = $this->priceKindFrom($input, $source, $type);

            if ($type === VoucherType::Facturatie) {
                if ($source === null) {
                    throw ValidationException::withMessages([
                        'lines.'.$index.'.description' => 'Dit onderdeel staat niet op de opdrachtbon of is al volledig afgerekend.',
                    ]);
                }

                $maxQty = round((float) ($source['remaining'] ?? $source['quantity'] ?? 0) - ($usedQty[$key] ?? 0), 2);
                $maxAmount = round((float) ($source['remaining_amount'] ?? 0) - ($usedAmount[$key] ?? 0), 2);

                if ($quantity - $maxQty > 0.001) {
                    throw ValidationException::withMessages([
                        'lines.'.$index.'.quantity' => 'Er is nog maar '.Format::qty($maxQty, 2).' over om te factureren.',
                    ]);
                }

                if ($kind->isFixed()) {
                    $amount = round((float) ($input['amount'] ?? 0), 2);
                    if ($amount <= 0 && $quantity > 0.001) {
                        $orderedQty = (float) ($source['ordered'] ?? 0);
                        $orderedAmount = (float) ($source['ordered_amount'] ?? 0);
                        $amount = $orderedQty > 0.001
                            ? round($quantity / $orderedQty * $orderedAmount, 2)
                            : 0.0;
                        $amount = min($amount, $maxAmount);
                    }
                    if ($amount <= 0 && $quantity <= 0.001) {
                        continue;
                    }
                    if ($amount - $maxAmount > 0.001) {
                        throw ValidationException::withMessages([
                            'lines.'.$index.'.amount' => 'Er is nog maar '.Format::money($maxAmount).' over om te factureren.',
                        ]);
                    }
                    $price = $quantity > 0.001 ? round($amount / $quantity, 2) : 0.0;
                } else {
                    if ($quantity <= 0) {
                        continue;
                    }
                    $price = round((float) ($source['unit_price'] ?? $input['unit_price'] ?? 0), 2);
                    $amount = round($quantity * $price, 2);
                }
            } else {
                $isHoursSpecRoom = $quantity <= 0.001
                    && $areaId !== null
                    && (string) ($input['unit'] ?? '') === WorkUnit::Hours->value;

                if ($kind->isFixed()) {
                    $amount = round((float) ($input['amount'] ?? 0), 2);
                    if ($isHoursSpecRoom && $amount <= 0.001) {
                        $price = round((float) ($input['unit_price'] ?? 0), 2);
                        $amount = 0.0;
                    } elseif ($amount <= 0) {
                        throw ValidationException::withMessages([
                            'lines.'.$index.'.amount' => 'Vul het afgesproken vaste bedrag in.',
                        ]);
                    } elseif ($quantity <= 0) {
                        throw ValidationException::withMessages([
                            'lines.'.$index.'.quantity' => 'Vul het maximale opdrachtaantal in.',
                        ]);
                    } else {
                        $price = round($amount / $quantity, 2);
                    }
                } else {
                    if ($quantity <= 0) {
                        if (! $isHoursSpecRoom) {
                            continue;
                        }
                        $price = round((float) ($input['unit_price'] ?? 0), 2);
                        $amount = 0.0;
                    } else {
                        if (! array_key_exists('unit_price', $input) || $input['unit_price'] === '' || $input['unit_price'] === null) {
                            throw ValidationException::withMessages([
                                'lines.'.$index.'.unit_price' => 'Vul een prijs in, of zet eerst een afgesproken prijs bij de vakman.',
                            ]);
                        }
                        $price = round((float) $input['unit_price'], 2);
                        $amount = round($quantity * $price, 2);
                    }
                }

            }

            $item = $itemId ? WorkItem::query()->find($itemId) : null;
            $unit = WorkUnit::from((string) $input['unit']);
            $sourceEnum = $source['price_source'] ?? VoucherPriceSource::Manual;
            if ($source === null || $source['unit_price'] === null || abs(((float) $source['unit_price']) - $price) > 0.001) {
                $sourceEnum = VoucherPriceSource::Manual;
            }

            $description = trim((string) $input['description']);
            $roomLabel = trim((string) ($input['room_label'] ?? ''));
            if ($roomLabel !== '' && ! str_starts_with($description, $roomLabel)) {
                $description = $roomLabel.' · '.$description;
            }

            $rows[] = [
                'project_area_id' => $areaId ?: null,
                'work_item_id' => $itemId ?: null,
                'specialty_key' => $item?->specialtyKey() ?? ($source['specialty_key'] ?? null),
                'description' => $description,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $price,
                'amount' => $amount,
                'price_source' => $sourceEnum,
                'price_kind' => $kind,
            ];

            if ($type === VoucherType::Facturatie) {
                $usedQty[$key] = ($usedQty[$key] ?? 0) + $quantity;
                $usedAmount[$key] = ($usedAmount[$key] ?? 0) + $amount;
            }
        }

        if ($type === VoucherType::Opdracht) {
            $this->assertOpdrachtCoversInvoiced($rows, $invoicedMap, $invoicedAmounts);
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'lines' => 'Kies minstens één regel met een hoeveelheid.',
            ]);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, float>  $invoicedMap
     * @param  array<string, float>  $invoicedAmounts
     */
    private function assertOpdrachtCoversInvoiced(array $rows, array $invoicedMap, array $invoicedAmounts): void
    {
        $qtyByKey = [];
        $amountByKey = [];
        $firstIndex = [];

        foreach ($rows as $index => $row) {
            $key = VoucherActivityGroups::activityKeyFromLine($row);
            $qtyByKey[$key] = ($qtyByKey[$key] ?? 0) + (float) $row['quantity'];
            $amountByKey[$key] = ($amountByKey[$key] ?? 0) + (float) $row['amount'];
            $firstIndex[$key] ??= $index;
        }

        foreach ($qtyByKey as $key => $quantity) {
            $alreadyQty = (float) ($invoicedMap[$key] ?? 0);
            $alreadyAmount = (float) ($invoicedAmounts[$key] ?? 0);
            $index = $firstIndex[$key];

            if ($alreadyQty > 0.001 && ($alreadyQty - $quantity) > 0.001) {
                throw ValidationException::withMessages([
                    'lines.'.$index.'.quantity' => 'Er is al '.Format::qty($alreadyQty, 2).' gefactureerd op dit onderdeel.',
                ]);
            }
            if ($alreadyAmount > 0.001 && ($alreadyAmount - $amountByKey[$key]) > 0.001) {
                throw ValidationException::withMessages([
                    'lines.'.$index.'.amount' => 'Er is al '.Format::money($alreadyAmount).' gefactureerd op dit onderdeel.',
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function unifyOpdrachtActivityPrices(array $lines): array
    {
        $indexes = [];
        foreach ($lines as $index => $line) {
            $itemId = isset($line['work_item_id']) && $line['work_item_id'] !== '' ? (int) $line['work_item_id'] : null;
            if ($itemId === null) {
                continue;
            }
            $unit = (string) ($line['unit'] ?? '');
            $indexes[$itemId.'|'.$unit][] = $index;
        }

        foreach ($indexes as $groupIndexes) {
            if (count($groupIndexes) < 2) {
                continue;
            }
            $first = $lines[$groupIndexes[0]];
            $kind = $this->priceKindFrom($first, null, VoucherType::Opdracht);
            $price = $first['unit_price'] ?? null;
            foreach ($groupIndexes as $index) {
                $lines[$index]['price_kind'] = $kind->value;
                if (! $kind->isFixed() && $price !== null && $price !== '') {
                    $lines[$index]['unit_price'] = $price;
                }
            }
        }

        return $lines;
    }

    private function priceKindFrom(array $input, ?array $source, VoucherType $type): VoucherPriceKind
    {
        if ($type === VoucherType::Facturatie && is_array($source)) {
            $kind = $source['price_kind'] ?? VoucherPriceKind::Unit;

            return $kind instanceof VoucherPriceKind
                ? $kind
                : (VoucherPriceKind::tryFrom((string) $kind) ?? VoucherPriceKind::Unit);
        }

        $raw = $input['price_kind'] ?? VoucherPriceKind::Unit->value;

        return VoucherPriceKind::tryFrom((string) $raw) ?? VoucherPriceKind::Unit;
    }

    /**
     * @return array<string, float>
     */
    public function invoicedMap(int $workerId, int $projectId, ?int $exceptVoucherId = null): array
    {
        return $this->invoicedState($workerId, $projectId, $exceptVoucherId)['map'];
    }

    /**
     * @param  Collection<int, Voucher>  $vouchers
     * @return Collection<string, array<string, mixed>>
     */
    public function billingByWorkerProject(Collection $vouchers): Collection
    {
        return $vouchers
            ->groupBy(fn (Voucher $voucher): string => $voucher->worker_id.'.'.$voucher->project_id)
            ->map(fn (Collection $group): array => $this->billingSummary($group));
    }

    /**
     * @param  Collection<int, Voucher>  $vouchers
     * @return Collection<string, array<string, mixed>>
     */
    public function sheetsByWorkerProject(Collection $vouchers): Collection
    {
        return $vouchers
            ->groupBy(fn (Voucher $voucher): string => $voucher->worker_id.'.'.$voucher->project_id)
            ->map(fn (Collection $group): ?array => $this->sheetFromVouchers($group))
            ->filter();
    }

    /**
     * @param  Collection<int, Voucher>  $vouchers
     * @return array<string, mixed>|null
     */
    public function sheetFromVouchers(Collection $vouchers): ?array
    {
        $billing = $this->billingSummary($vouchers);
        $opdracht = $billing['opdracht'];
        if ($opdracht === null) {
            return null;
        }

        $bons = $vouchers
            ->filter(fn (Voucher $voucher): bool => $voucher->type === VoucherType::Facturatie)
            ->sortBy('id')
            ->values();
        $rows = [];

        foreach ($this->groupedOpdrachtLines($opdracht) as $key => $line) {
            $cells = [];
            foreach ($bons as $bon) {
                $matched = $bon->lines->filter(
                    fn (VoucherLine $voucherLine): bool => $voucherLine->activityKey() === $key
                );
                $cells[] = [
                    'quantity' => round((float) $matched->sum('quantity'), 2),
                    'amount' => round((float) $matched->sum('amount'), 2),
                ];
            }

            $ordered = (float) $line['quantity'];
            $orderedAmount = (float) $line['amount'];
            $price = (float) $line['unit_price'];
            $kind = $line['price_kind'];
            $received = round((float) collect($cells)->sum('quantity'), 2);
            $receivedAmount = round((float) collect($cells)->sum('amount'), 2);
            $remaining = max(0, round($ordered - $received, 2));
            $remainingAmount = max(0, round($orderedAmount - $receivedAmount, 2));

            $rows[] = [
                'key' => $key,
                'description' => $line['description'],
                'quantity' => $ordered,
                'unit' => $line['unit'],
                'unit_price' => $price,
                'amount' => $orderedAmount,
                'price_kind' => $kind,
                'project_area_id' => $line['project_area_id'],
                'work_item_id' => $line['work_item_id'],
                'specialty_key' => $line['specialty_key'],
                'rooms' => $line['rooms'] ?? [],
                'room_keys' => $line['room_keys'] ?? [],
                'cells' => $cells,
                'received_quantity' => $received,
                'received_amount' => $receivedAmount,
                'remaining_quantity' => $remaining,
                'remaining_amount' => $remainingAmount,
                'settled' => $remaining <= 0.001 && $remainingAmount <= 0.001,
            ];
        }

        $unitValues = collect($rows)
            ->map(fn (array $row): string => $row['unit'] instanceof WorkUnit ? $row['unit']->value : (string) $row['unit'])
            ->unique()
            ->values();
        $priceValues = collect($rows)
            ->map(fn (array $row): string => number_format((float) $row['unit_price'], 2, '.', ''))
            ->unique()
            ->values();
        $agreedUnit = $rows[0]['unit'] ?? null;

        return [
            'opdracht' => $opdracht,
            'bons' => $bons,
            'rows' => $rows,
            'billing' => $billing,
            'column_totals' => $bons->map(fn (Voucher $bon): array => [
                'quantity' => round((float) $bon->lines->sum('quantity'), 2),
                'amount' => (float) $bon->total_amount,
            ])->all(),
            'next_label' => Format::bonOrdinal($bons->count() + 1),
            'agreed_price' => ($unitValues->count() === 1 && $priceValues->count() === 1)
                ? (float) $priceValues->first()
                : null,
            'agreed_unit' => ($unitValues->count() === 1 && $priceValues->count() === 1)
                ? $agreedUnit
                : null,
        ];
    }

    /**
     * @param  Collection<int, Voucher>  $vouchers
     * @return array{
     *     opdracht: ?Voucher,
     *     opdracht_amount: float,
     *     invoiced_amount: float,
     *     remaining_amount: float,
     *     over_amount: float,
     *     opdracht_m2: float,
     *     invoiced_m2: float,
     *     remaining_m2: float,
     *     over_m2: float,
     *     invoiced_map: array<string, float>,
     *     opdracht_lines: array<string, array<string, mixed>>,
     *     fully_settled: bool,
     *     warnings: list<string>
     * }
     */
    public function billingSummary(Collection $vouchers): array
    {
        $opdracht = $vouchers
            ->filter(fn (Voucher $voucher): bool => $voucher->type === VoucherType::Opdracht)
            ->sortByDesc('id')
            ->first();
        $invoicedLines = $vouchers
            ->filter(fn (Voucher $voucher): bool => $voucher->type === VoucherType::Facturatie)
            ->flatMap(fn (Voucher $voucher) => $voucher->lines);

        return $this->billingFromOpdrachtAndInvoiced($opdracht, $this->stateFromLines($invoicedLines));
    }

    /**
     * @param  array{map: array<string, float>, amount_map: array<string, float>, amount: float, m2: float}  $invoiced
     * @return array{
     *     opdracht: ?Voucher,
     *     opdracht_amount: float,
     *     invoiced_amount: float,
     *     remaining_amount: float,
     *     over_amount: float,
     *     opdracht_m2: float,
     *     invoiced_m2: float,
     *     remaining_m2: float,
     *     over_m2: float,
     *     invoiced_map: array<string, float>,
     *     opdracht_lines: array<string, array<string, mixed>>,
     *     fully_settled: bool,
     *     warnings: list<string>
     * }
     */
    private function billingFromOpdrachtAndInvoiced(?Voucher $opdracht, array $invoiced): array
    {
        $opdrachtAmount = $opdracht ? (float) $opdracht->total_amount : 0.0;
        $opdrachtLines = $opdracht ? $this->groupedOpdrachtLines($opdracht) : [];
        $opdrachtM2 = $opdracht
            ? (float) collect($opdrachtLines)
                ->filter(fn (array $line): bool => $line['unit'] === WorkUnit::SquareMeter)
                ->sum('quantity')
            : 0.0;
        $overAmount = $opdracht ? max(0, round($invoiced['amount'] - $opdrachtAmount, 2)) : 0.0;
        $overM2 = $opdracht ? max(0, round($invoiced['m2'] - $opdrachtM2, 2)) : 0.0;
        $remainingAmount = $opdracht ? max(0, round($opdrachtAmount - $invoiced['amount'], 2)) : 0.0;
        $remainingM2 = $opdracht ? max(0, round($opdrachtM2 - $invoiced['m2'], 2)) : 0.0;
        $fullySettled = false;
        if ($opdracht !== null) {
            $fullySettled = true;
            foreach ($opdrachtLines as $key => $line) {
                $alreadyQty = (float) ($invoiced['map'][$key] ?? 0);
                $alreadyAmount = (float) ($invoiced['amount_map'][$key] ?? 0);
                $orderedAmount = (float) $line['amount'];
                if ((float) $line['quantity'] - $alreadyQty > 0.001 || $orderedAmount - $alreadyAmount > 0.001) {
                    $fullySettled = false;
                    break;
                }
            }
        }

        return [
            'opdracht' => $opdracht,
            'opdracht_amount' => $opdrachtAmount,
            'invoiced_amount' => $invoiced['amount'],
            'remaining_amount' => $remainingAmount,
            'over_amount' => $overAmount,
            'opdracht_m2' => $opdrachtM2,
            'invoiced_m2' => $invoiced['m2'],
            'remaining_m2' => $remainingM2,
            'over_m2' => $overM2,
            'invoiced_map' => $invoiced['map'],
            'opdracht_lines' => $opdrachtLines,
            'fully_settled' => $fullySettled,
            'warnings' => $this->overageMessages($opdracht, $invoiced['amount'], $invoiced['m2']),
        ];
    }

    /**
     * @return list<string>
     */
    private function overageMessages(?Voucher $opdracht, float $invoicedAmount, float $invoicedM2): array
    {
        if ($opdracht === null) {
            return [];
        }

        $opdrachtAmount = (float) $opdracht->total_amount;
        $opdrachtM2 = (float) $opdracht->lines
            ->filter(fn (VoucherLine $line): bool => $line->unit === WorkUnit::SquareMeter)
            ->sum('quantity');
        $overAmount = round($invoicedAmount - $opdrachtAmount, 2);
        $overM2 = round($invoicedM2 - $opdrachtM2, 2);
        $parts = [];

        if ($overM2 > 0.001) {
            $parts[] = Format::qty($overM2, 2).' m²';
        }
        if ($overAmount > 0.001) {
            $parts[] = Format::money($overAmount);
        }

        if ($parts === []) {
            return [];
        }

        return [
            'Let op: dit overschrijdt opdrachtbon '.$opdracht->number.' met '.implode(' en ', $parts).'.',
        ];
    }

    /**
     * @param  array{map: array<string, float>, amount_map: array<string, float>, amount: float, m2: float}  $invoiced
     * @param  array<string, int>|null  $wanted
     * @return list<array<string, mixed>>
     */
    private function facturatieLinesFromOpdracht(Voucher $opdracht, array $invoiced, ?array $wanted): array
    {
        $lines = [];
        $qtyMap = $invoiced['map'];
        $amountMap = $invoiced['amount_map'];

        foreach ($this->groupedOpdrachtLines($opdracht) as $key => $line) {
            $ordered = (float) $line['quantity'];
            $orderedAmount = (float) $line['amount'];
            $already = (float) ($qtyMap[$key] ?? 0);
            $alreadyAmount = (float) ($amountMap[$key] ?? 0);
            $remaining = max(0, round($ordered - $already, 2));
            $remainingAmount = max(0, round($orderedAmount - $alreadyAmount, 2));

            if ($remaining <= 0.001 && $remainingAmount <= 0.001) {
                continue;
            }

            if ($wanted !== null && ! isset($wanted[$key])) {
                continue;
            }

            $price = (float) $line['unit_price'];
            $kind = $line['price_kind'];

            $lines[] = [
                'project_area_id' => $line['project_area_id'],
                'work_item_id' => $line['work_item_id'],
                'specialty_key' => $line['specialty_key'],
                'room_label' => '',
                'description' => $line['description'],
                'quantity' => null,
                'ordered' => $ordered,
                'ordered_amount' => $orderedAmount,
                'invoiced' => $already,
                'remaining' => $remaining,
                'remaining_amount' => $remainingAmount,
                'unit' => $line['unit'],
                'unit_price' => $price,
                'amount' => null,
                'price_source' => VoucherPriceSource::Voucher,
                'price_kind' => $kind,
                'rooms' => $line['rooms'] ?? [],
            ];
        }

        return $lines;
    }

    /**
     * @param  Collection<int, WorkOrder>  $orders
     * @param  array<string, int>|null  $wanted
     * @return list<array<string, mixed>>
     */
    private function opdrachtLinesFromProduction(
        Worker $worker,
        Project $project,
        ?string $from,
        ?string $to,
        ?User $user,
        ?Voucher $opdracht,
        Collection $orders,
        ?array $wanted,
    ): array {
        $production = $this->overview->build($worker->id, $project->id, $from, $to, $user);
        $lines = [];

        foreach ($production['groups'] as $group) {
            foreach ($group['projects'] as $projectGroup) {
                foreach ($projectGroup['rooms'] as $room) {
                    foreach ($room['materials'] as $material) {
                        $item = $material['work_item'] ?? null;
                        $unit = $material['unit'] instanceof WorkUnit
                            ? $material['unit']
                            : WorkUnit::tryFrom((string) $material['unit']) ?? WorkUnit::SquareMeter;
                        $areaId = $room['area']?->id;
                        $itemId = $item?->id;
                        $key = VoucherLine::lineKey($areaId, $itemId);

                        if ($wanted !== null && ! isset($wanted[$key])) {
                            continue;
                        }

                        $quantity = (float) $material['quantity'];
                        $resolved = $this->prices->resolve($worker, $item, $unit, $opdracht, $orders);
                        $price = $resolved['price'];

                        $lines[] = [
                            'project_area_id' => $areaId,
                            'work_item_id' => $itemId,
                            'specialty_key' => $item?->specialtyKey(),
                            'room_label' => $room['label'],
                            'description' => $material['label'],
                            'quantity' => $quantity,
                            'unit' => $unit,
                            'unit_price' => $price,
                            'amount' => $price === null ? 0.0 : round($quantity * $price, 2),
                            'price_source' => $resolved['source'],
                            'price_kind' => VoucherPriceKind::Unit,
                            'spec_m2' => $room['area']?->square_meters,
                        ];
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * @return array<string, array{
     *     quantity: float,
     *     amount: float,
     *     description: string,
     *     unit: WorkUnit,
     *     project_area_id: ?int,
     *     work_item_id: ?int,
     *     specialty_key: ?string,
     *     unit_price: float,
     *     price_kind: VoucherPriceKind,
     *     rooms: list<array{key: string, project_area_id: ?int, label: string, quantity: float}>,
     *     room_keys: list<string>
     * }>
     */
    private function groupedOpdrachtLines(Voucher $opdracht): array
    {
        $opdracht->loadMissing('lines.area');

        return $opdracht->lines
            ->groupBy(fn (VoucherLine $line): string => $line->activityKey())
            ->map(function (Collection $group): array {
                /** @var VoucherLine $first */
                $first = $group->first();
                $kind = $first->price_kind instanceof VoucherPriceKind
                    ? $first->price_kind
                    : VoucherPriceKind::Unit;
                $quantity = round((float) $group->sum('quantity'), 2);
                $amount = $kind->isFixed()
                    ? round((float) $group->sum('amount'), 2)
                    : round($quantity * (float) $first->unit_price, 2);
                $payload = [
                    'project_area_id' => $first->project_area_id,
                    'work_item_id' => $first->work_item_id,
                    'room_label' => $first->area?->label() ?? '',
                    'description' => $first->description,
                ];
                $rooms = $group
                    ->map(function (VoucherLine $line): ?array {
                        $label = $line->area?->label()
                            ?? VoucherActivityGroups::roomLabel([
                                'project_area_id' => $line->project_area_id,
                                'room_label' => $line->area?->label() ?? '',
                                'description' => $line->description,
                            ]);
                        if ($label === '' && $line->project_area_id === null) {
                            return null;
                        }

                        return [
                            'key' => $line->key(),
                            'project_area_id' => $line->project_area_id ? (int) $line->project_area_id : null,
                            'label' => $label,
                            'quantity' => $line->unit === WorkUnit::Hours
                                ? round((float) ($line->area?->square_meters ?? $line->quantity), 2)
                                : round((float) $line->quantity, 2),
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'quantity' => $quantity,
                    'amount' => $amount,
                    'description' => VoucherActivityGroups::activityName($payload),
                    'unit' => $first->unit,
                    'project_area_id' => count($rooms) === 1 ? ($rooms[0]['project_area_id'] ?? null) : null,
                    'work_item_id' => $first->work_item_id ? (int) $first->work_item_id : null,
                    'specialty_key' => $first->specialty_key,
                    'unit_price' => (float) $first->unit_price,
                    'price_kind' => $kind,
                    'rooms' => $rooms,
                    'room_keys' => collect($rooms)->pluck('key')->filter()->values()->all(),
                ];
            })
            ->all();
    }

    /**
     * @return array{map: array<string, float>, amount_map: array<string, float>, amount: float, m2: float}
     */
    private function invoicedState(int $workerId, int $projectId, ?int $exceptVoucherId = null): array
    {
        $lines = VoucherLine::query()
            ->whereHas('voucher', function ($query) use ($workerId, $projectId, $exceptVoucherId) {
                $query->where('worker_id', $workerId)
                    ->where('project_id', $projectId)
                    ->where('type', VoucherType::Facturatie)
                    ->when($exceptVoucherId, fn ($vouchers) => $vouchers->whereKeyNot($exceptVoucherId));
            })
            ->get();

        return $this->stateFromLines($lines);
    }

    /**
     * @param  Collection<int, VoucherLine>  $lines
     * @return array{map: array<string, float>, amount_map: array<string, float>, amount: float, m2: float}
     */
    private function stateFromLines(Collection $lines): array
    {
        return [
            'map' => $lines
                ->groupBy(fn (VoucherLine $line): string => $line->activityKey())
                ->map(fn (Collection $group): float => (float) $group->sum('quantity'))
                ->all(),
            'amount_map' => $lines
                ->groupBy(fn (VoucherLine $line): string => $line->activityKey())
                ->map(fn (Collection $group): float => (float) $group->sum('amount'))
                ->all(),
            'amount' => round((float) $lines->sum('amount'), 2),
            'm2' => round((float) $lines
                ->filter(fn (VoucherLine $line): bool => $line->unit === WorkUnit::SquareMeter)
                ->sum('quantity'), 2),
        ];
    }
}
