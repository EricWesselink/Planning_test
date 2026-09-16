<?php

namespace App\Services\QuoteCalculation;

use App\Enums\WorkUnit;
use App\Models\CalculationLine;
use Illuminate\Support\Collection;

class CalculationTotals
{
    /**
     * @param  Collection<int, CalculationLine|array<string, mixed>>  $lines
     * @return list<array{product_code: ?string, product: ?string, quantity: float, unit: string, unit_label: string}>
     */
    public function grouped(Collection $lines): array
    {
        $skipPlinthRooms = [];
        foreach ($lines as $line) {
            if (! $this->boolean($line, 'plinth_not_applicable') || $this->unit($line) !== WorkUnit::SquareMeter->value) {
                continue;
            }
            $number = mb_strtolower(trim((string) $this->value($line, 'room_number')));
            if ($number !== '') {
                $skipPlinthRooms[$number] = true;
            }
        }

        $groups = [];
        foreach ($lines as $line) {
            if ($this->unit($line) === WorkUnit::LinearMeter->value) {
                $number = mb_strtolower(trim((string) $this->value($line, 'room_number')));
                if ($number !== '' && isset($skipPlinthRooms[$number])) {
                    continue;
                }
            }
            $code = trim((string) $this->value($line, 'product_code'));
            $product = trim((string) $this->value($line, 'product'));
            $unit = $this->unit($line);
            $quantity = $this->quantity($line);
            if ($quantity === null) {
                continue;
            }

            $key = mb_strtolower($code !== '' ? $code : $product).'|'.$unit;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'product_code' => $code !== '' ? $code : null,
                    'product' => $product !== '' ? $product : null,
                    'quantity' => 0.0,
                    'unit' => $unit,
                    'unit_label' => WorkUnit::tryFrom($unit)?->label() ?? $unit,
                ];
            }

            $groups[$key]['quantity'] += $quantity;
            if ($groups[$key]['product'] === null && $product !== '') {
                $groups[$key]['product'] = $product;
            }
        }

        ksort($groups);

        return array_values(array_map(function (array $group): array {
            $group['quantity'] = round($group['quantity'], 3);

            return $group;
        }, $groups));
    }

    private function value(CalculationLine|array $line, string $key): mixed
    {
        if ($line instanceof CalculationLine) {
            return $line->{$key};
        }

        return $line[$key] ?? null;
    }

    private function unit(CalculationLine|array $line): string
    {
        $unit = $this->value($line, 'unit');
        if ($unit instanceof WorkUnit) {
            return $unit->value;
        }

        return is_string($unit) && $unit !== '' ? $unit : WorkUnit::SquareMeter->value;
    }

    private function boolean(CalculationLine|array $line, string $key): bool
    {
        return (bool) $this->value($line, $key);
    }

    private function quantity(CalculationLine|array $line): ?float
    {
        $quantity = $this->value($line, 'quantity');
        if ($quantity === null || $quantity === '') {
            return null;
        }

        return (float) $quantity;
    }
}
