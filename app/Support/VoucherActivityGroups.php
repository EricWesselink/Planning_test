<?php

namespace App\Support;

use App\Enums\VoucherPriceKind;
use App\Enums\WorkUnit;
use App\Models\Voucher;
use App\Models\VoucherLine;

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

            $group['quantity'] = $quantity;
            $group['amount'] = $amount;
            $group['has_rooms'] = $hasRooms;
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

        $lines = $voucher->lines->map(fn (VoucherLine $line): array => [
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
        ])->all();

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
     * @param  array<string, mixed>  $line
     */
    private static function groupKey(array $line, int $index): string
    {
        $itemId = self::intOrNull($line['work_item_id'] ?? null);
        if ($itemId === null) {
            return 'line:'.$index;
        }

        return 'item:'.$itemId.':'.self::unitValue($line);
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
