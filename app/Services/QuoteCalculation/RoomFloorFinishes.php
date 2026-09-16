<?php

namespace App\Services\QuoteCalculation;

use App\Enums\FinishRole;

class RoomFloorFinishes
{
    /**
     * Split a room's floor codes into a main finish plus local patches.
     *
     * The first code is the room-finish symbol. Extra codes never receive the
     * full room area. When every local patch has its own m², the main finish
     * gets the remainder so the sum matches the room total.
     *
     * @param  list<string>  $codes  Closest-first unique floor codes.
     * @param  list<float>  $localAreas  Extra m² values, not the room total.
     * @return list<array{code: string, quantity: ?float, role: string}>
     */
    public function split(array $codes, ?float $roomArea, array $localAreas = []): array
    {
        $normalized = [];
        foreach ($codes as $code) {
            $code = mb_strtolower(trim((string) $code));
            if ($code === '' || isset($normalized[$code])) {
                continue;
            }
            $normalized[$code] = $code;
        }
        $codes = array_values($normalized);
        if ($codes === []) {
            return [];
        }

        if (count($codes) === 1) {
            return [[
                'code' => $codes[0],
                'quantity' => $roomArea,
                'role' => FinishRole::Main->value,
            ]];
        }

        $used = 0.0;
        $locals = [];
        foreach (array_values(array_slice($codes, 1)) as $index => $code) {
            $quantity = $localAreas[$index] ?? null;
            if ($quantity !== null && $roomArea !== null && $quantity >= $roomArea * 0.9) {
                $quantity = null;
            }
            if ($quantity !== null && $quantity <= 0) {
                $quantity = null;
            }
            if ($quantity !== null) {
                $used += $quantity;
            }
            $locals[] = [
                'code' => $code,
                'quantity' => $quantity,
                'role' => FinishRole::Local->value,
            ];
        }

        $mainQuantity = $roomArea;
        $localsKnown = true;
        foreach ($locals as $finish) {
            if ($finish['quantity'] === null) {
                $localsKnown = false;
                break;
            }
        }
        if ($localsKnown && $roomArea !== null && $used > 0) {
            $mainQuantity = round($roomArea - $used, 3);
            if ($mainQuantity <= 0) {
                $mainQuantity = null;
            }
        }

        return array_merge([[
            'code' => $codes[0],
            'quantity' => $mainQuantity,
            'role' => FinishRole::Main->value,
        ]], $locals);
    }

    /**
     * @param  list<array{code?: string, quantity?: ?float, role?: string}>  $floors
     */
    public function matchesRoomArea(array $floors, ?float $roomArea): bool
    {
        if ($roomArea === null || $floors === []) {
            return $floors === [] || $roomArea === null;
        }

        $sum = 0.0;
        foreach ($floors as $finish) {
            if (! is_numeric($finish['quantity'] ?? null)) {
                return false;
            }
            $sum += (float) $finish['quantity'];
        }

        return abs($sum - $roomArea) <= 0.05;
    }
}
