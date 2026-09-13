<?php

namespace App\Support;

use App\Enums\VoucherPriceKind;
use App\Enums\WorkUnit;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Models\WorkProgressEntry;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class VoucherActivityGroups
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{
     *     key: string,
     *     work_item_id: ?int,
     *     description: string,
     *     quantity: float,
     *     unit: WorkUnit|string,
     *     unit_price: float|string|null,
     *     amount: float,
     *     price_kind: VoucherPriceKind|string,
     *     price_source: mixed,
     *     has_rooms: bool,
     *     entries: list<array{index: int, line: array<string, mixed>, room_label: string, quantity: float}>
     * }>
     */
    public static function fromFormLines(array $lines): array
    {
        $groups = [];
        $order = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $key = self::groupKey($line, (int) $index);
            if (! isset($groups[$key])) {
                $order[] = $key;
                $groups[$key] = [
                    'key' => $key,
                    'work_item_id' => self::intOrNull($line['work_item_id'] ?? null),
                    'description' => self::activityName($line),
                    'unit' => $line['unit'] ?? WorkUnit::SquareMeter,
                    'unit_price' => $line['unit_price'] ?? null,
                    'price_kind' => $line['price_kind'] ?? VoucherPriceKind::Unit,
                    'price_source' => $line['price_source'] ?? null,
                    'entries' => [],
                ];
            }

            $groups[$key]['entries'][] = [
                'index' => (int) $index,
                'line' => $line,
                'room_label' => self::roomLabel($line),
                'quantity' => round((float) ($line['quantity'] ?? 0), 2),
            ];
        }

        $result = [];
        foreach ($order as $key) {
            $group = $groups[$key];
            $quantity = round((float) collect($group['entries'])->sum('quantity'), 2);
            $kind = $group['price_kind'];
            $kindValue = $kind instanceof VoucherPriceKind ? $kind : (string) $kind;
            $isFixed = $kindValue === VoucherPriceKind::Fixed->value
                || $kind === VoucherPriceKind::Fixed;
            $amount = $isFixed
                ? round((float) collect($group['entries'])->sum(fn (array $entry): float => (float) ($entry['line']['amount'] ?? 0)), 2)
                : round($quantity * (float) ($group['unit_price'] ?? 0), 2);
            $hasRooms = collect($group['entries'])->contains(
                fn (array $entry): bool => $entry['room_label'] !== ''
                    || self::intOrNull($entry['line']['project_area_id'] ?? null) !== null
            );

            $worked = collect($group['entries'])
                ->flatMap(fn (array $entry): array => array_filter([
                    $entry['line']['worked_on'] ?? null,
                    $entry['line']['worked_to'] ?? null,
                ]))
                ->map(fn (mixed $date): CarbonInterface => $date instanceof CarbonInterface
                    ? $date->copy()->startOfDay()
                    : Carbon::parse((string) $date)->startOfDay())
                ->sortBy(fn (CarbonInterface $date): string => $date->toDateString())
                ->values();

            $group['quantity'] = $quantity;
            $group['amount'] = $amount;
            $group['has_rooms'] = $hasRooms;
            $group['worked_from'] = $worked->first();
            $group['worked_to'] = $worked->last();
            $result[] = $group;
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fromVoucher(Voucher $voucher): array
    {
        $voucher->loadMissing('lines.area');
        $workedOn = self::progressDatesByLine($voucher);

        $lines = $voucher->lines->map(function (VoucherLine $line) use ($workedOn): array {
            $key = $line->key();

            return [
                'project_area_id' => $line->project_area_id,
                'work_item_id' => $line->work_item_id,
                'room_label' => $line->area?->label() ?? '',
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'amount' => $line->amount,
                'price_kind' => $line->price_kind,
                'price_source' => $line->price_source,
                'spec_m2' => $line->area?->square_meters,
                'worked_on' => $workedOn[$key]['from'] ?? null,
                'worked_to' => $workedOn[$key]['to'] ?? null,
            ];
        })->all();

        return self::fromFormLines($lines);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function activityName(array $line): string
    {
        $description = trim((string) ($line['description'] ?? ''));
        $room = self::roomLabel($line);
        if ($room !== '' && $description !== '') {
            foreach ([' · ', ': ', ':'] as $separator) {
                $prefix = $room.$separator;
                if (str_starts_with($description, $prefix)) {
                    return trim(mb_substr($description, mb_strlen($prefix)));
                }
            }
        }

        if ($room === '' && str_contains($description, ' · ')) {
            return trim((string) mb_substr($description, mb_strpos($description, ' · ') + 3));
        }

        return $description;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function roomLabel(array $line): string
    {
        $explicit = trim((string) ($line['room_label'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $description = trim((string) ($line['description'] ?? ''));
        if (str_contains($description, ' · ')) {
            return trim((string) mb_substr($description, 0, mb_strpos($description, ' · ')));
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function unitValue(array $line): string
    {
        $unit = $line['unit'] ?? WorkUnit::SquareMeter;

        return $unit instanceof WorkUnit ? $unit->value : (string) $unit;
    }

    public static function unitLabel(mixed $unit): string
    {
        if ($unit instanceof WorkUnit) {
            return $unit->label();
        }

        return WorkUnit::tryFrom((string) $unit)?->label() ?? '';
    }

    public static function isHours(mixed $unit): bool
    {
        if ($unit instanceof WorkUnit) {
            return $unit === WorkUnit::Hours;
        }

        return (string) $unit === WorkUnit::Hours->value;
    }

    public static function roomSpecUnitLabel(mixed $activityUnit): string
    {
        return self::isHours($activityUnit)
            ? WorkUnit::SquareMeter->label()
            : self::unitLabel($activityUnit);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array{line?: array<string, mixed>, quantity?: float}  $entry
     */
    public static function roomQuantityLabel(array $group, array $entry): string
    {
        if (self::isHours($group['unit'] ?? null)) {
            $meters = $entry['line']['spec_m2'] ?? null;
            if ($meters === null || $meters === '') {
                return '';
            }

            return Format::qty($meters, 2).' '.WorkUnit::SquareMeter->label();
        }

        return self::quantityLabel([
            'quantity' => $entry['quantity'] ?? 0,
            'unit' => $group['unit'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $group
     */
    public static function priceLabel(array $group): string
    {
        $kind = $group['price_kind'] ?? VoucherPriceKind::Unit;
        $isFixed = $kind === VoucherPriceKind::Fixed || $kind === VoucherPriceKind::Fixed->value;
        $price = Format::money($group['unit_price'] ?? 0);
        if ($isFixed) {
            return $price;
        }

        $unitLabel = self::unitLabel($group['unit'] ?? null);

        return $unitLabel !== '' ? $price.'/'.$unitLabel : $price;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    public static function quantityLabel(array $group): string
    {
        $unitLabel = self::unitLabel($group['unit'] ?? null);
        $quantity = Format::qty($group['quantity'] ?? 0, 2);

        return $unitLabel !== '' ? $quantity.' '.$unitLabel : $quantity;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    public static function periodLabel(array $group): string
    {
        $from = $group['worked_from'] ?? null;
        if (! $from instanceof CarbonInterface) {
            return '';
        }

        $to = $group['worked_to'] ?? $from;

        return Format::dayAndWeek($from, $to instanceof CarbonInterface ? $to : $from);
    }

    /**
     * @param  array{line?: array<string, mixed>}  $entry
     */
    public static function roomPeriodLabel(array $entry): string
    {
        $from = $entry['line']['worked_on'] ?? null;
        if (! $from instanceof CarbonInterface && ! is_string($from)) {
            return '';
        }

        $fromDate = $from instanceof CarbonInterface ? $from : Carbon::parse($from);
        $to = $entry['line']['worked_to'] ?? $fromDate;
        $toDate = $to instanceof CarbonInterface || is_string($to) ? $to : $fromDate;

        return Format::dayAndWeek($fromDate, $toDate instanceof CarbonInterface ? $toDate : Carbon::parse((string) $toDate));
    }

    public static function activityKey(?int $workItemId, mixed $unit, string $description = ''): string
    {
        $unitValue = self::unitValue(['unit' => $unit]);
        if ($workItemId !== null && $workItemId > 0) {
            return 'item:'.$workItemId.':'.$unitValue;
        }

        $name = mb_strtolower(self::activityName(['description' => $description]));

        return $name !== ''
            ? 'custom:'.$name.':'.$unitValue
            : 'line:'.$unitValue;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function activityKeyFromLine(array $line): string
    {
        return self::activityKey(
            self::intOrNull($line['work_item_id'] ?? null),
            $line['unit'] ?? WorkUnit::SquareMeter,
            (string) ($line['description'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function groupKey(array $line, int $index): string
    {
        $itemId = self::intOrNull($line['work_item_id'] ?? null);
        if ($itemId === null) {
            return 'line:'.$index;
        }

        return self::activityKey($itemId, $line['unit'] ?? WorkUnit::SquareMeter);
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * @return array<string, array{from: CarbonInterface, to: CarbonInterface}>
     */
    private static function progressDatesByLine(Voucher $voucher): array
    {
        $areaIds = $voucher->lines
            ->pluck('project_area_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $itemIds = $voucher->lines
            ->pluck('work_item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($areaIds === [] || $itemIds === [] || ! $voucher->worker_id || ! $voucher->project_id) {
            return [];
        }

        $entries = WorkProgressEntry::query()
            ->where('worker_id', $voucher->worker_id)
            ->where('project_id', $voucher->project_id)
            ->whereIn('project_area_id', $areaIds)
            ->whereIn('work_item_id', $itemIds)
            ->orderBy('date')
            ->get(['project_area_id', 'work_item_id', 'date']);

        $dates = [];
        foreach ($entries as $entry) {
            if ($entry->date === null) {
                continue;
            }

            $key = VoucherLine::lineKey(
                $entry->project_area_id ? (int) $entry->project_area_id : null,
                $entry->work_item_id ? (int) $entry->work_item_id : null,
            );
            $day = $entry->date->copy()->startOfDay();
            if (! isset($dates[$key])) {
                $dates[$key] = ['from' => $day, 'to' => $day];

                continue;
            }

            if ($day->lt($dates[$key]['from'])) {
                $dates[$key]['from'] = $day;
            }
            if ($day->gt($dates[$key]['to'])) {
                $dates[$key]['to'] = $day;
            }
        }

        return $dates;
    }
}
