<?php

namespace App\Services\QuoteCalculation;

class RoomDraftFilter
{
    /**
     * @param  array<string, mixed>  $room
     */
    public function isEmpty(array $room): bool
    {
        return $this->text($room['room_number'] ?? null) === ''
            && $this->text($room['room_name'] ?? null) === ''
            && ! is_numeric($room['square_meters'] ?? null)
            && ! is_numeric($room['room_area'] ?? null)
            && $this->text($room['floor_code'] ?? null) === ''
            && $this->text($room['plinth_code'] ?? null) === ''
            && $this->floorCodes($room) === [];
    }

    /**
     * @param  array<string, mixed>  $room
     */
    public function notApplicable(array $room): bool
    {
        if ($this->isEmpty($room)) {
            return false;
        }
        if (($room['floor_work'] ?? null) !== false) {
            return false;
        }

        return $this->text($room['floor_code'] ?? null) === ''
            && $this->text($room['plinth_code'] ?? null) === ''
            && $this->floorCodes($room) === [];
    }

    /**
     * @param  array<string, mixed>  $room
     * @return list<string>
     */
    private function floorCodes(array $room): array
    {
        $codes = [];
        foreach ($room['floors'] ?? [] as $finish) {
            $code = $this->text($finish['code'] ?? $finish['floor_code'] ?? null);
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        foreach ($room['extra_floors'] ?? [] as $finish) {
            $code = $this->text($finish['floor_code'] ?? $finish['code'] ?? null);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function text(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
