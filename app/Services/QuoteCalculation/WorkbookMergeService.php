<?php

namespace App\Services\QuoteCalculation;

use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationLine;
use App\Models\CalculationWorkbook;
use App\Services\SpreadsheetReader;
use App\Support\Format;
use Illuminate\Support\Facades\Storage;

class WorkbookMergeService
{
    public const AREA_MISMATCH_FACTOR = 10.0;

    public function __construct(
        private SpreadsheetReader $reader = new SpreadsheetReader,
        private WorkbookRowParser $parser = new WorkbookRowParser,
        private FinishPairingRules $pairing = new FinishPairingRules,
        private RoomNumberRange $roomNumbers = new RoomNumberRange,
    ) {}

    /**
     * @param  array<string, mixed>  $mapping
     */
    public function apply(Calculation $calculation, CalculationWorkbook $workbook, array $mapping): int
    {
        if (($mapping['skip'] ?? false) === true) {
            $workbook->update([
                'status' => 'skipped',
                'mapping' => $mapping,
            ]);

            return 0;
        }

        $path = Storage::disk('local')->path($workbook->file_path);
        $sheets = $this->reader->sheets($path, $workbook->original_filename);
        $byName = [];
        foreach ($sheets as $sheet) {
            $byName[$sheet['name']] = $sheet['rows'];
        }

        $incoming = [];
        foreach ($mapping['sheets'] ?? [] as $sheetMapping) {
            $name = (string) ($sheetMapping['name'] ?? '');
            if ($name === '' || ($sheetMapping['skip'] ?? false) === true) {
                continue;
            }
            $rows = $byName[$name] ?? [];
            foreach ($this->parser->parse($rows, $sheetMapping) as $room) {
                $incoming[] = $room;
            }
        }

        $incoming = $this->pairing->apply($incoming, $this->legendFromRooms($incoming));
        $incoming = $this->collapseRooms($incoming);
        $count = 0;
        $sort = (int) $calculation->lines()->max('sort_order');
        $warnings = $calculation->warnings ?? [];
        [$floors, $byCode, $plinths] = $this->lineMaps($calculation);

        foreach ($incoming as $room) {
            $count += $this->mergeRoom($calculation, $workbook, $room, $sort, $warnings, $floors, $byCode, $plinths);
        }

        $calculation->update(['warnings' => array_values(array_unique($warnings))]);
        $workbook->update([
            'status' => 'applied',
            'mapping' => $mapping,
        ]);

        return $count;
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  list<string>  $warnings
     * @param  array<string, CalculationLine>  $floors
     * @param  array<string, CalculationLine>  $byCode
     * @param  array<string, CalculationLine>  $plinths
     */
    private function mergeRoom(
        Calculation $calculation,
        CalculationWorkbook $workbook,
        array $room,
        int &$sort,
        array &$warnings,
        array &$floors,
        array &$byCode,
        array &$plinths,
    ): int {
        $key = $this->roomKey((string) ($room['room_number'] ?? ''));
        if ($key === '') {
            return 0;
        }

        $expanded = $this->roomNumbers->expand($room['room_number'] ?? '');
        if (count($expanded) > 1) {
            $matched = 0;
            foreach ($expanded as $number) {
                $childKey = $this->roomKey($number);
                if ($childKey === '' || ! $this->hasExistingRoom($floors, $byCode, $plinths, $childKey)) {
                    continue;
                }
                $child = $room;
                $child['room_number'] = $number;
                unset($child['quantity']);
                $matched += $this->mergeRoom($calculation, $workbook, $child, $sort, $warnings, $floors, $byCode, $plinths);
            }
            if ($matched > 0) {
                return $matched;
            }
        }

        $changed = 0;
        $floorCode = mb_strtolower(trim((string) ($room['floor_code'] ?? '')));
        $floor = $this->existingFloor($floors, $byCode, $key, $floorCode);
        $plinth = $plinths[$key] ?? null;
        $plinthOnly = filled($room['plinth_code'] ?? null) && ! filled($room['floor_code'] ?? null);
        $floorQuantity = $this->excelQuantity($room, WorkUnit::SquareMeter);

        if ($floor instanceof CalculationLine) {
            $changed += $this->overlayExcel($floor, $workbook, $room, WorkUnit::SquareMeter, $warnings);
        } elseif (! $plinthOnly && ($floorQuantity !== null || filled($room['floor_code'] ?? null))) {
            $sort++;
            $role = isset($floors[$key]) ? FinishRole::Local : FinishRole::Main;
            $created = $this->createLine($calculation, $workbook, $room, WorkUnit::SquareMeter, $sort, $role, $floors[$key] ?? null);
            if ($floorCode !== '') {
                $byCode[$key.'|'.$floorCode] = $created;
            }
            if (! isset($floors[$key])) {
                $floors[$key] = $created;
            }
            $changed++;
        }

        $skipPlinth = $floor instanceof CalculationLine && $floor->plinth_not_applicable;
        $needsPlinth = filled($room['plinth_code'] ?? null)
            || mb_strtolower((string) ($room['floor_code'] ?? '')) === FinishPairingRules::GIETVLOER;
        $plinthQuantity = $this->excelQuantity($room, WorkUnit::LinearMeter);
        if (! $skipPlinth && $plinth instanceof CalculationLine && $plinthQuantity !== null) {
            $changed += $this->overlayExcel($plinth, $workbook, $room, WorkUnit::LinearMeter, $warnings);
        } elseif (! $skipPlinth && $needsPlinth && $plinth === null && $plinthQuantity !== null) {
            $sort++;
            $plinths[$key] = $this->createLine($calculation, $workbook, $room, WorkUnit::LinearMeter, $sort);
            $changed++;
        }

        return $changed > 0 ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  list<string>  $warnings
     */
    private function overlayExcel(
        CalculationLine $line,
        CalculationWorkbook $workbook,
        array $room,
        WorkUnit $unit,
        array &$warnings,
    ): int {
        $code = $unit === WorkUnit::LinearMeter ? ($room['plinth_code'] ?? null) : ($room['floor_code'] ?? null);
        $product = $unit === WorkUnit::LinearMeter ? ($room['plinth_product'] ?? null) : ($room['floor_product'] ?? null);
        $quantity = $this->excelQuantity($room, $unit);
        if ($unit === WorkUnit::LinearMeter && ($room['plinth_inferred'] ?? false) && filled($line->product_code)) {
            $code = null;
            $product = null;
        }
        if (
            $unit === WorkUnit::LinearMeter && mb_strtolower((string) $line->product_code) === FinishPairingRules::PLAKPLINT
            && is_string($product) && preg_match('/holplint/iu', $product)
        ) {
            $product = null;
        }

        $line->calculation_workbook_id = $workbook->id;
        $line->excel_product_code = $code;
        $line->excel_product = $product;
        if ($quantity !== null) {
            $line->excel_quantity = $quantity;
        }

        $label = $line->room_number ?: 'ruimte';
        $pdfQuantity = $line->quantity !== null ? (float) $line->quantity : null;
        if ($unit === WorkUnit::SquareMeter && $pdfQuantity !== null && $quantity !== null && abs($pdfQuantity - (float) $quantity) > 0.05) {
            $this->rememberExcelTakeoff($line, (float) $quantity, $unit, $room['quantity_source'] ?? null);
            if (self::areaLooksLikeWrongRoom($pdfQuantity, (float) $quantity)) {
                $excelBit = Format::qty((float) $quantity, 2).' '.$unit->label();
                if (filled($room['quantity_source'] ?? null)) {
                    $excelBit .= ' — '.$room['quantity_source'];
                }
                $warnings[] = $label.': Afwijking Excel — PDF '.Format::qty($pdfQuantity, 2).' '.$unit->label().', Excel '.$excelBit.' — mogelijk verkeerde ruimte.';
            }
        }

        $codeConflict = filled($code) && filled($line->product_code) && mb_strtolower((string) $line->product_code) !== mb_strtolower((string) $code);
        if ($codeConflict) {
            $line->source = QuantitySource::Review;
            $line->confirmed_at = null;
            $line->confirmed_manually = false;
            $conflict = $label.': PDF zegt '.$line->product_code.', Excel zegt '.$code;
            $line->note = trim(($line->note ?? '').' '.$conflict);
            $warnings[] = $conflict;
        } else {
            $keepSource = in_array($line->source, [QuantitySource::FromDrawing, QuantitySource::Calculated], true);
            if (! filled($line->product_code) && filled($code)) {
                $line->product_code = $code;
                if (! $keepSource) {
                    $line->source = QuantitySource::Review;
                }
            }
            if (! filled($line->product) && filled($product)) {
                $line->product = $product;
            }
            if ($line->quantity === null && $quantity !== null) {
                $line->quantity = $quantity;
                if (! $keepSource) {
                    $line->source = QuantitySource::FromExcel;
                }
            }
        }

        $line->save();

        return 1;
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function createLine(
        Calculation $calculation,
        CalculationWorkbook $workbook,
        array $room,
        WorkUnit $unit,
        int $sort,
        ?FinishRole $role = null,
        ?CalculationLine $existingFloor = null,
    ): CalculationLine {
        $code = $unit === WorkUnit::LinearMeter ? ($room['plinth_code'] ?? null) : ($room['floor_code'] ?? null);
        $product = $unit === WorkUnit::LinearMeter ? ($room['plinth_product'] ?? null) : ($room['floor_product'] ?? null);
        $quantity = $this->excelQuantity($room, $unit);
        $roomArea = $existingFloor?->room_area !== null
            ? (float) $existingFloor->room_area
            : ($existingFloor?->quantity !== null ? (float) $existingFloor->quantity : $quantity);
        if ($role === FinishRole::Local && $roomArea !== null && $quantity !== null && $quantity >= $roomArea * 0.9) {
            $quantity = null;
        }

        return CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'calculation_workbook_id' => $workbook->id,
            'sort_order' => $sort,
            'room_number' => $room['room_number'] ?? null,
            'room_name' => $room['room_name'] ?? null,
            'product_code' => $code,
            'product' => $product,
            'original_product_code' => $code,
            'original_product' => $product,
            'quantity' => $quantity,
            'original_quantity' => $quantity,
            'excel_quantity' => $this->excelQuantity($room, $unit),
            'excel_product_code' => $code,
            'excel_product' => $product,
            'unit' => $unit->value,
            'finish_role' => $unit === WorkUnit::SquareMeter ? ($role ?? FinishRole::Main)->value : null,
            'room_area' => $unit === WorkUnit::SquareMeter ? $roomArea : null,
            'source' => QuantitySource::FromExcel->value,
            'found_source' => QuantitySource::FromExcel->value,
            'note' => $room['note'] ?? 'Alleen in Excel gevonden; controleer tegen de tekening.',
        ]);
    }

    /**
     * @return array{0: array<string, CalculationLine>, 1: array<string, CalculationLine>, 2: array<string, CalculationLine>}
     */
    private function lineMaps(Calculation $calculation): array
    {
        $floors = [];
        $byCode = [];
        $plinths = [];
        foreach ($calculation->lines()->orderBy('sort_order')->orderBy('id')->get() as $line) {
            $key = $this->roomKey((string) $line->room_number);
            if ($key === '') {
                continue;
            }
            if ($line->unit === WorkUnit::SquareMeter) {
                $code = mb_strtolower(trim((string) $line->product_code));
                if ($code !== '') {
                    $byCode[$key.'|'.$code] = $line;
                }
                if (! isset($floors[$key]) || $line->finish_role === FinishRole::Main) {
                    $floors[$key] = $line;
                }
            }
            if ($line->unit === WorkUnit::LinearMeter && ! isset($plinths[$key])) {
                $plinths[$key] = $line;
            }
        }

        return [$floors, $byCode, $plinths];
    }

    /**
     * @param  array<string, CalculationLine>  $floors
     * @param  array<string, CalculationLine>  $byCode
     * @param  array<string, CalculationLine>  $plinths
     */
    private function hasExistingRoom(array $floors, array $byCode, array $plinths, string $key): bool
    {
        if (isset($floors[$key]) || isset($plinths[$key])) {
            return true;
        }

        foreach (array_keys($byCode) as $codeKey) {
            if (str_starts_with($codeKey, $key.'|')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, CalculationLine>  $floors
     * @param  array<string, CalculationLine>  $byCode
     */
    private function existingFloor(array $floors, array $byCode, string $key, string $code): ?CalculationLine
    {
        if ($code !== '' && isset($byCode[$key.'|'.$code])) {
            return $byCode[$key.'|'.$code];
        }
        if ($code !== '') {
            return null;
        }

        return $floors[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function excelQuantity(array $room, WorkUnit $unit): ?float
    {
        $quantity = $room['quantity'] ?? null;
        if ($quantity === null || $quantity === '') {
            return null;
        }
        $rowUnit = $room['unit'] ?? null;
        if ($rowUnit instanceof WorkUnit) {
            return $rowUnit === $unit ? (float) $quantity : null;
        }

        return $unit === WorkUnit::SquareMeter ? (float) $quantity : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array{code: string, product: string, kind: string}>
     */
    private function legendFromRooms(array $rooms): array
    {
        $legend = [];
        foreach ($rooms as $room) {
            foreach (['floor' => 'floor', 'plinth' => 'plinth'] as $prefix => $kind) {
                $code = $room[$prefix.'_code'] ?? null;
                $product = $room[$prefix.'_product'] ?? null;
                if (! is_string($code) || $code === '') {
                    continue;
                }
                $legend[$code] = [
                    'code' => $code,
                    'product' => is_string($product) && $product !== '' ? $product : $code,
                    'kind' => $kind,
                ];
            }
        }

        return array_values($legend);
    }

    public function roomKey(string $number): string
    {
        $number = mb_strtolower(trim($number));

        return str_replace(['.', ' '], '-', $number);
    }

    /**
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function collapseRooms(array $incoming): array
    {
        $grouped = [];
        foreach ($incoming as $room) {
            $number = (string) ($room['room_number'] ?? '');
            $key = $this->roomKey($number);
            if ($key === '') {
                continue;
            }
            $code = mb_strtolower(trim((string) ($room['floor_code'] ?? '')));
            $groupKey = $key.'|'.$code;
            if (! isset($grouped[$groupKey])) {
                $grouped[$groupKey] = $room;

                continue;
            }

            $current = $grouped[$groupKey];
            if (is_numeric($room['quantity'] ?? null)) {
                $incomingQty = (float) $room['quantity'];
                $existingQty = $current['quantity'] ?? null;
                if (! is_numeric($existingQty)) {
                    $current['quantity'] = $incomingQty;
                } elseif (abs((float) $existingQty - $incomingQty) > 0.05) {
                    $current['quantity'] = (float) $existingQty + $incomingQty;
                    if (filled($room['quantity_source'] ?? null)) {
                        $existing = (string) ($current['quantity_source'] ?? '');
                        $current['quantity_source'] = $existing === ''
                            ? $room['quantity_source']
                            : $existing.'; '.$room['quantity_source'];
                    }
                }
            }
            if (! filled($current['room_name'] ?? null) && filled($room['room_name'] ?? null)) {
                $current['room_name'] = $room['room_name'];
            }
            $grouped[$groupKey] = $current;
        }

        return array_values($grouped);
    }

    public static function areaLooksLikeWrongRoom(float $left, float $right): bool
    {
        if ($left <= 0 || $right <= 0) {
            return false;
        }

        return max($left, $right) / min($left, $right) >= self::AREA_MISMATCH_FACTOR;
    }

    private function rememberExcelTakeoff(CalculationLine $line, float $quantity, WorkUnit $unit, mixed $source): void
    {
        $bit = 'Excel takeoff: '.Format::qty($quantity, 2).' '.$unit->label();
        if (filled($source)) {
            $bit .= ' — '.$source;
        }
        $note = preg_replace('/\s*Excel takeoff:[^\n]*/u', '', (string) ($line->note ?? '')) ?? '';
        $line->note = trim($note.' '.$bit);
    }
}
