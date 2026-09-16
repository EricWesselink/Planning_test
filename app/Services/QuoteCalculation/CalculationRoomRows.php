<?php

namespace App\Services\QuoteCalculation;

use App\Enums\CheckStatus;
use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\CalculationLine;
use Illuminate\Support\Collection;

class CalculationRoomRows
{
    /**
     * @param  Collection<int, CalculationLine>  $lines
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     room_count: int,
     *     certain_count: int,
     *     generous_count: int,
     *     estimated_count: int,
     *     review_count: int,
     *     missing_count: int,
     *     not_applicable_count: int,
     *     blocking_count: int,
     *     linked_count: int,
     *     floor_linked_count: int,
     *     plinth_linked_count: int,
     *     plinth_meters_count: int,
     *     plinth_net_count: int,
     *     plinth_generous_count: int,
     *     plinth_estimated_count: int,
     *     plinth_missing_meters_count: int,
     *     excel_confirmed_count: int,
     *     square_meters: float,
     *     ready_for_excel: bool
     * }
     */
    public function table(Collection $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $key = $this->groupKey($line);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'room_number' => $line->room_number,
                    'room_name' => $line->room_name,
                    'floor' => null,
                    'floors' => [],
                    'plinth' => null,
                ];
            }
            if ($line->unit === WorkUnit::SquareMeter) {
                $groups[$key]['floors'][] = $line;
                if ($this->isMainFloor($line, $groups[$key]['floor'])) {
                    $groups[$key]['floor'] = $line;
                    if (filled($line->room_name)) {
                        $groups[$key]['room_name'] = $line->room_name;
                    }
                }

                continue;
            }
            if ($line->unit === WorkUnit::LinearMeter && $groups[$key]['plinth'] === null) {
                $groups[$key]['plinth'] = $line;

                continue;
            }
            $extraKey = 'line-'.$line->id;
            $groups[$extraKey] = [
                'key' => $extraKey,
                'room_number' => $line->room_number,
                'room_name' => $line->room_name,
                'floor' => $line->unit === WorkUnit::LinearMeter ? null : $line,
                'floors' => $line->unit === WorkUnit::LinearMeter ? [] : [$line],
                'plinth' => $line->unit === WorkUnit::LinearMeter ? $line : null,
            ];
        }

        $rows = [];
        $squareMeters = 0.0;
        foreach ($groups as $group) {
            if ($this->isEmptyGroup($group)) {
                continue;
            }
            $floors = $group['floors'] ?? array_values(array_filter([$group['floor']]));
            $floor = $group['floor'];
            $plinth = $group['plinth'];
            $issues = $this->issues($floors, $plinth);
            $status = $this->status($floors, $plinth, $issues);
            $statusLabel = $issues === [] ? $status->label() : implode(' · ', $issues);
            if ($status === CheckStatus::NotApplicable) {
                $statusLabel = $status->label();
            }
            $roomArea = $this->roomArea($floors, $floor);
            if ($roomArea !== null && $status !== CheckStatus::NotApplicable) {
                $squareMeters += $roomArea;
            }
            $searchBits = [$group['room_number'], $group['room_name']];
            foreach ($floors as $finish) {
                $searchBits[] = $finish->product_code;
                $searchBits[] = $finish->product;
            }
            $rows[] = [
                'key' => $group['key'],
                'room_number' => $group['room_number'],
                'room_name' => $group['room_name'],
                'floor' => $floor,
                'floors' => $floors,
                'plinth' => $plinth,
                'status' => $status,
                'status_label' => $statusLabel,
                'issues' => $issues,
                'needs_review' => $status->isBlocking(),
                'can_confirm' => $this->canConfirm($floors, $plinth, $status, $issues),
                'has_floor' => $floors !== [],
                'has_plinth' => $plinth instanceof CalculationLine,
                'room_area' => $roomArea,
                'trace' => $this->plinthTraceLabel($plinth),
                'excel_quantity' => $floor?->excel_quantity,
                'excel_conflict' => $this->anyExcelConflict($floors),
                'excel_area_mismatch' => $this->anyExcelAreaMismatch($floors),
                'excel_product_code' => $floor?->excel_product_code,
                'excel_code_conflict' => $this->anyExcelCodeConflict($floors),
                'search' => mb_strtolower(trim(implode(' ', array_filter([
                    ...$searchBits,
                    $plinth?->product_code,
                    $plinth?->product,
                ])))),
            ];
        }

        $certain = count(array_filter($rows, fn (array $row) => $row['status'] === CheckStatus::Certain || $row['status'] === CheckStatus::Confirmed));
        $generous = count(array_filter($rows, fn (array $row) => $row['status'] === CheckStatus::Generous));
        $estimated = count(array_filter($rows, fn (array $row) => $row['status'] === CheckStatus::Estimated));
        $review = count(array_filter($rows, fn (array $row) => $row['status'] === CheckStatus::Review));
        $missing = count(array_filter($rows, fn (array $row) => $row['status'] === CheckStatus::Missing));
        $notApplicable = count(array_filter($rows, fn (array $row) => $row['status'] === CheckStatus::NotApplicable));
        $floorLinked = count(array_filter($rows, fn (array $row) => $this->floorsLinked($row['floors'] ?? [])));
        $plinthLinked = count(array_filter($rows, fn (array $row) => $this->plinthLinked($row['plinth'] ?? null)));
        $plinthMeters = 0;
        $plinthNet = 0;
        $plinthGenerous = 0;
        $plinthEstimated = 0;
        $plinthMissingMeters = 0;
        foreach ($rows as $row) {
            $plinth = $row['plinth'] ?? null;
            if (! $plinth instanceof CalculationLine) {
                continue;
            }
            if ($plinth->quantity === null) {
                $plinthMissingMeters++;

                continue;
            }
            $plinthMeters++;
            $status = PlinthLengthCalculator::breakdownFrom($plinth->calculation_trace)['status'];
            if ($status === PlinthLengthCalculator::STATUS_ESTIMATED) {
                $plinthEstimated++;
            } elseif ($status === PlinthLengthCalculator::STATUS_GENEROUS) {
                $plinthGenerous++;
            } else {
                $plinthNet++;
            }
        }
        $excelConfirmed = count(array_filter($rows, fn (array $row) => $this->excelConfirmed($row)));

        return [
            'rows' => array_values($rows),
            'room_count' => count($rows),
            'certain_count' => $certain,
            'generous_count' => $generous,
            'estimated_count' => $estimated,
            'review_count' => $review,
            'missing_count' => $missing,
            'not_applicable_count' => $notApplicable,
            'blocking_count' => $review + $missing,
            'linked_count' => $floorLinked,
            'floor_linked_count' => $floorLinked,
            'plinth_linked_count' => $plinthLinked,
            'plinth_meters_count' => $plinthMeters,
            'plinth_net_count' => $plinthNet,
            'plinth_generous_count' => $plinthGenerous,
            'plinth_estimated_count' => $plinthEstimated,
            'plinth_missing_meters_count' => $plinthMissingMeters,
            'excel_confirmed_count' => $excelConfirmed,
            'square_meters' => $squareMeters,
            'ready_for_excel' => ($review + $missing) === 0 && ($certain + $generous + $estimated) > 0,
        ];
    }

    private function groupKey(CalculationLine $line): string
    {
        $number = mb_strtolower(trim((string) $line->room_number));
        if ($number === '') {
            return 'line-'.$line->id;
        }

        return (string) ($line->calculation_drawing_id ?? '0').'|'.$number;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function isEmptyGroup(array $group): bool
    {
        if (trim((string) ($group['room_number'] ?? '')) !== '' || trim((string) ($group['room_name'] ?? '')) !== '') {
            return false;
        }
        foreach ($group['floors'] ?? [] as $floor) {
            if (! $floor instanceof CalculationLine) {
                continue;
            }
            if (filled($floor->product_code) || filled($floor->product) || $floor->quantity !== null) {
                return false;
            }
        }
        $plinth = $group['plinth'] ?? null;
        if ($plinth instanceof CalculationLine && (filled($plinth->product_code) || filled($plinth->product) || $plinth->quantity !== null)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<CalculationLine>  $floors
     * @param  list<string>  $issues
     */
    private function status(array $floors, ?CalculationLine $plinth, array $issues): CheckStatus
    {
        if ($this->notApplicable($floors, $plinth)) {
            return CheckStatus::NotApplicable;
        }
        if ($this->isConfirmed($floors, $plinth)) {
            return CheckStatus::Confirmed;
        }
        if ($issues !== []) {
            return CheckStatus::Review;
        }
        if ($this->isEstimated($plinth)) {
            return CheckStatus::Estimated;
        }
        if ($this->isGenerous($plinth)) {
            return CheckStatus::Generous;
        }
        if ($this->isCertain($floors, $plinth)) {
            return CheckStatus::Certain;
        }

        return CheckStatus::Review;
    }

    /**
     * @param  list<CalculationLine>  $floors
     * @return list<string>
     */
    private function issues(array $floors, ?CalculationLine $plinth): array
    {
        if ($this->notApplicable($floors, $plinth)) {
            return [];
        }
        $issues = [];
        $floor = $this->mainFloor($floors);
        if ($floors === [] || ! $floor instanceof CalculationLine) {
            return ['Vloercode ontbreekt'];
        }
        foreach ($floors as $finish) {
            if ($finish->finish_role === FinishRole::Local) {
                if (! filled($finish->product_code)) {
                    $issues[] = 'Vloercode ontbreekt';
                }

                continue;
            }
            if (! filled($finish->product_code)) {
                $issues[] = 'Vloercode ontbreekt';
            } elseif ($this->missingFloorVariant($finish)) {
                $issues[] = 'Exacte '.mb_strtolower(trim((string) $finish->product_code)).'-variant ontbreekt';
            }
            if ($finish->quantity === null) {
                $issues[] = 'm² ontbreekt';
            }
        }
        if ($floor instanceof CalculationLine && $this->plinthRequired($floor, $plinth)) {
            if (! $plinth instanceof CalculationLine || ! filled($plinth->product_code)) {
                $issues[] = 'Plintcode ontbreekt';
            } elseif ($plinth->quantity === null) {
                $issues[] = 'Plint m¹ ontbreekt';
            }
        }

        return array_values(array_unique($issues));
    }

    private function missingFloorVariant(CalculationLine $finish): bool
    {
        $code = mb_strtolower(trim((string) $finish->product_code));
        if ($code === '' || FinishPairingRules::isSpecific($code)) {
            return false;
        }
        if (is_string($finish->note) && str_contains($finish->note, 'variant ontbreekt')) {
            return true;
        }

        return ! filled($finish->product) || $this->productLooksLikeCode($finish);
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function notApplicable(array $floors, ?CalculationLine $plinth): bool
    {
        return ! $this->hasIndicatedFloorWork($floors, $plinth);
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function hasIndicatedFloorWork(array $floors, ?CalculationLine $plinth): bool
    {
        foreach ($floors as $floor) {
            if (filled($floor->product_code) || filled($floor->product) || filled($floor->excel_product_code)) {
                return true;
            }
        }

        return $plinth instanceof CalculationLine
            && (filled($plinth->product_code) || filled($plinth->product));
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function hasMissingRequired(array $floors, ?CalculationLine $plinth): bool
    {
        return $this->issues($floors, $plinth) !== [];
    }

    private function plinthRequired(CalculationLine $floor, ?CalculationLine $plinth): bool
    {
        return mb_strtolower((string) $floor->product_code) === FinishPairingRules::GIETVLOER;
    }

    private function isEstimated(?CalculationLine $plinth): bool
    {
        if (! $plinth instanceof CalculationLine || $plinth->quantity === null) {
            return false;
        }

        return PlinthLengthCalculator::breakdownFrom($plinth->calculation_trace)['status']
            === PlinthLengthCalculator::STATUS_ESTIMATED;
    }

    private function isGenerous(?CalculationLine $plinth): bool
    {
        if (! $plinth instanceof CalculationLine || $plinth->quantity === null) {
            return false;
        }
        $status = PlinthLengthCalculator::breakdownFrom($plinth->calculation_trace)['status'];
        if ($status === PlinthLengthCalculator::STATUS_GENEROUS) {
            return true;
        }

        return is_string($plinth->note) && str_contains($plinth->note, 'deuropening niet afgetrokken');
    }

    private function plinthTraceLabel(?CalculationLine $plinth): ?string
    {
        if (! $plinth instanceof CalculationLine) {
            return null;
        }
        $label = PlinthLengthCalculator::breakdownFrom($plinth->calculation_trace)['label'];

        return $label ?: $plinth->note ?: $plinth->calculation_trace;
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function floorsLinked(array $floors): bool
    {
        if ($floors === []) {
            return false;
        }
        foreach ($floors as $floor) {
            if ($floor->finish_role === FinishRole::Local) {
                if (! filled($floor->product_code)) {
                    return false;
                }

                continue;
            }
            if (! $this->floorLinked($floor)) {
                return false;
            }
        }

        return true;
    }

    private function floorLinked(?CalculationLine $floor): bool
    {
        return $floor instanceof CalculationLine
            && filled($floor->product_code)
            && filled($floor->product)
            && filled($floor->room_name)
            && $floor->quantity !== null;
    }

    private function plinthLinked(?CalculationLine $plinth): bool
    {
        return $plinth instanceof CalculationLine && filled($plinth->product_code);
    }

    private function productLooksLikeCode(CalculationLine $floor): bool
    {
        return mb_strtolower(trim((string) $floor->product)) === mb_strtolower(trim((string) $floor->product_code));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function excelConfirmed(array $row): bool
    {
        $floor = $row['floor'] ?? null;
        if (! $floor instanceof CalculationLine || ! filled($floor->excel_product_code) || ! filled($floor->product_code)) {
            return false;
        }
        if (mb_strtolower((string) $floor->product_code) !== mb_strtolower((string) $floor->excel_product_code)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function isCertain(array $floors, ?CalculationLine $plinth): bool
    {
        if ($this->hasMissingRequired($floors, $plinth)) {
            return false;
        }
        foreach ($floors as $floor) {
            if ($floor->finish_role === FinishRole::Local) {
                continue;
            }
            if (! in_array($floor->source, [QuantitySource::FromDrawing, QuantitySource::Calculated], true)) {
                return false;
            }
        }
        $floor = $this->mainFloor($floors);
        if ($floor instanceof CalculationLine && $this->plinthRequired($floor, $plinth)) {
            if (! $plinth instanceof CalculationLine) {
                return false;
            }
            if (! in_array($plinth->source, [QuantitySource::FromDrawing, QuantitySource::Calculated], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function isConfirmed(array $floors, ?CalculationLine $plinth): bool
    {
        $lines = array_values(array_filter([...$floors, $plinth]));
        if ($lines === []) {
            return false;
        }
        foreach ($lines as $line) {
            if ($line->confirmed_at === null) {
                return false;
            }
        }

        return ! $this->hasMissingRequired($floors, $plinth);
    }

    /**
     * @param  list<CalculationLine>  $floors
     * @param  list<string>  $issues
     */
    private function canConfirm(array $floors, ?CalculationLine $plinth, CheckStatus $status, array $issues): bool
    {
        return $status->isBlocking() && $issues === [];
    }

    private function isMainFloor(CalculationLine $candidate, mixed $current): bool
    {
        if (! $current instanceof CalculationLine) {
            return true;
        }
        if ($candidate->finish_role === FinishRole::Main) {
            return true;
        }
        if ($candidate->finish_role === FinishRole::Local) {
            return false;
        }

        return false;
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function mainFloor(array $floors): ?CalculationLine
    {
        foreach ($floors as $floor) {
            if ($floor->finish_role === FinishRole::Main) {
                return $floor;
            }
        }

        return $floors[0] ?? null;
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function roomArea(array $floors, ?CalculationLine $floor): ?float
    {
        foreach ($floors as $finish) {
            if ($finish->room_area !== null) {
                return (float) $finish->room_area;
            }
        }
        if ($floor instanceof CalculationLine && $floor->quantity !== null && ($floors === [] || count($floors) === 1)) {
            return (float) $floor->quantity;
        }
        $sum = 0.0;
        $any = false;
        foreach ($floors as $finish) {
            if ($finish->quantity === null) {
                continue;
            }
            $any = true;
            $sum += (float) $finish->quantity;
        }

        return $any ? $sum : null;
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function anyExcelConflict(array $floors): bool
    {
        foreach ($floors as $floor) {
            if (
                $floor->excel_quantity !== null && $floor->quantity !== null
                && abs((float) $floor->quantity - (float) $floor->excel_quantity) > 0.05
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function anyExcelAreaMismatch(array $floors): bool
    {
        foreach ($floors as $floor) {
            if (
                $floor->excel_quantity !== null && $floor->quantity !== null
                && WorkbookMergeService::areaLooksLikeWrongRoom((float) $floor->quantity, (float) $floor->excel_quantity)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<CalculationLine>  $floors
     */
    private function anyExcelCodeConflict(array $floors): bool
    {
        foreach ($floors as $floor) {
            if (
                filled($floor->excel_product_code) && filled($floor->product_code)
                && mb_strtolower((string) $floor->product_code) !== mb_strtolower((string) $floor->excel_product_code)
            ) {
                return true;
            }
        }

        return false;
    }
}
