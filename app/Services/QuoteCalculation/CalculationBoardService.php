<?php

namespace App\Services\QuoteCalculation;

use App\Enums\CheckStatus;
use App\Enums\FinishRole;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Support\Format;
use App\Support\MaterialColor;

class CalculationBoardService
{
    public function __construct(private CalculationRoomRows $rooms = new CalculationRoomRows) {}

    /**
     * @return array{
     *     csrf: string,
     *     selected_key: ?string,
     *     can_update: bool,
     *     routes: array<string, string>,
     *     drawings: list<array<string, mixed>>,
     *     rooms: list<array<string, mixed>>,
     *     materials: list<array<string, mixed>>
     * }
     */
    public function payload(Calculation $calculation, ?string $selectedKey = null, bool $canUpdate = false): array
    {
        $calculation->load(['lines.drawing', 'lines.workbook', 'drawings', 'workbooks']);
        $table = $this->rooms->table($calculation->lines);
        $rooms = [];
        foreach ($table['rows'] as $row) {
            $rooms[] = $this->roomFromRow($calculation, $row);
        }

        return [
            'csrf' => csrf_token(),
            'selected_key' => $selectedKey,
            'can_update' => $canUpdate,
            'routes' => [
                'drawing' => route('calculations.drawings.show', [$calculation, '__DRAWING__']),
                'update_room' => route('calculations.board.rooms.update', [$calculation, '__LINE__']),
                'confirm_room' => route('calculations.board.rooms.confirm', [$calculation, '__LINE__']),
            ],
            'drawings' => $calculation->drawings
                ->map(fn (CalculationDrawing $drawing) => $this->drawingSummary($calculation, $drawing))
                ->values()
                ->all(),
            'rooms' => $rooms,
            'materials' => $this->materials($rooms),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function roomFromRow(Calculation $calculation, array $row): array
    {
        $floor = $row['floor'] instanceof CalculationLine ? $row['floor'] : null;
        $plinth = $row['plinth'] instanceof CalculationLine ? $row['plinth'] : null;
        $floors = [];
        foreach ($row['floors'] ?? [] as $line) {
            if ($line instanceof CalculationLine) {
                $floors[] = $line;
            }
        }
        if ($floors === [] && $floor instanceof CalculationLine) {
            $floors = [$floor];
        }
        $anchor = $floor ?? ($floors[0] ?? $plinth);
        $drawing = $anchor?->drawing ?? $floor?->drawing ?? $plinth?->drawing;
        $status = $row['status'] instanceof CheckStatus ? $row['status'] : CheckStatus::Review;
        $finishes = [];
        foreach ($floors as $line) {
            $code = trim((string) ($line->product_code ?? ''));
            $product = trim((string) ($line->product ?? ''));
            $color = MaterialColor::fromCode($code !== '' ? $code : null, $product !== '' ? $product : null);
            $qty = $line->quantity !== null ? (float) $line->quantity : null;
            $finishes[] = [
                'id' => $line->id,
                'code' => $line->product_code,
                'product' => $line->product,
                'quantity' => $qty,
                'quantity_label' => $qty === null ? '—' : Format::qty($qty, 2).' m²',
                'role' => $line->finish_role?->value ?? FinishRole::Main->value,
                'material_key' => mb_strtolower($code),
                'material_color' => $color,
                'material_color_soft' => MaterialColor::softBackground($color, 0.18),
                'overlay' => $this->overlayFromTrace($line->calculation_trace),
            ];
        }
        $code = trim((string) ($floor?->product_code ?? ''));
        $product = trim((string) ($floor?->product ?? ''));
        $color = $finishes[0]['material_color'] ?? MaterialColor::fromCode($code !== '' ? $code : null, $product !== '' ? $product : null);
        $plinthBreakdown = PlinthLengthCalculator::breakdownFrom($plinth?->calculation_trace);
        $roomArea = is_numeric($row['room_area'] ?? null) ? (float) $row['room_area'] : ($floor?->quantity !== null ? (float) $floor->quantity : null);
        $m2 = $roomArea;

        return [
            'key' => (string) $row['key'],
            'id' => $anchor?->id,
            'floor_id' => $floor?->id,
            'plinth_id' => $plinth?->id,
            'drawing_id' => $drawing?->id,
            'group' => $this->drawingLabel($drawing),
            'number' => $row['room_number'],
            'name' => $row['room_name'],
            'm2' => $m2,
            'm2_label' => $m2 === null ? '—' : Format::qty($m2, 2).' m²',
            'floor_code' => $floor?->product_code,
            'floor_product' => $floor?->product,
            'floor_quantity' => $floor?->quantity !== null ? (float) $floor->quantity : null,
            'floors' => $finishes,
            'floor_codes_label' => implode(' + ', array_values(array_filter(array_map(
                fn (array $finish) => trim((string) ($finish['code'] ?? '')),
                $finishes,
            )))),
            'material_keys' => array_values(array_filter(array_map(
                fn (array $finish) => (string) $finish['material_key'],
                $finishes,
            ))),
            'plinth_code' => $plinth?->product_code,
            'plinth_product' => $plinth?->product,
            'plinth_quantity' => $plinth?->quantity !== null ? (float) $plinth->quantity : null,
            'plinth_quantity_label' => $plinth?->quantity !== null ? Format::qty((float) $plinth->quantity, 2).' m¹' : '—',
            'plinth_status' => $plinth === null ? null : $plinthBreakdown['status'],
            'plinth_status_label' => $plinth === null ? '—' : $this->plinthStatusLabel($plinthBreakdown['status']),
            'plinth_trace' => $row['trace'] ?? $plinthBreakdown['label'],
            'material_key' => mb_strtolower($code),
            'material_color' => $color,
            'material_color_soft' => MaterialColor::softBackground($color, 0.18),
            'needs_review' => (bool) ($row['needs_review'] ?? false),
            'can_confirm' => (bool) ($row['can_confirm'] ?? false),
            'status' => $status->value,
            'status_label' => $status->label(),
            'tone' => $status->isBlocking() ? ($status === CheckStatus::Missing ? 'missing' : 'review') : 'ok',
            'has_floor' => $floors !== [],
            'has_plinth' => $plinth instanceof CalculationLine,
            'search' => $row['search'] ?? '',
            'pdf_source' => $drawing?->original_filename,
            'excel_source' => $floor?->workbook?->original_filename,
            'excel_quantity' => $row['excel_quantity'] ?? null,
            'excel_quantity_label' => isset($row['excel_quantity']) && $row['excel_quantity'] !== null
                ? Format::qty((float) $row['excel_quantity'], 2).' m²'
                : null,
            'excel_conflict' => (bool) ($row['excel_conflict'] ?? false),
            'excel_product_code' => $row['excel_product_code'] ?? null,
            'excel_code_conflict' => (bool) ($row['excel_code_conflict'] ?? false),
            'note' => $floor?->note ?: $plinth?->note,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function roomByKey(Calculation $calculation, string $key): ?array
    {
        $calculation->load(['lines.drawing', 'lines.workbook', 'drawings', 'workbooks']);
        foreach ($this->rooms->table($calculation->lines)['rows'] as $row) {
            if ((string) $row['key'] === $key) {
                return $this->roomFromRow($calculation, $row);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function roomByLine(Calculation $calculation, CalculationLine $line): ?array
    {
        $calculation->loadMissing(['lines.drawing', 'lines.workbook', 'drawings', 'workbooks']);
        foreach ($this->rooms->table($calculation->lines)['rows'] as $row) {
            $ids = [];
            foreach ($row['floors'] ?? [] as $floor) {
                if ($floor instanceof CalculationLine) {
                    $ids[] = (int) $floor->id;
                }
            }
            $ids[] = $row['floor'] instanceof CalculationLine ? (int) $row['floor']->id : null;
            $ids[] = $row['plinth'] instanceof CalculationLine ? (int) $row['plinth']->id : null;
            if (in_array((int) $line->id, array_values(array_filter($ids)), true)) {
                return $this->roomFromRow($calculation, $row);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array<string, mixed>>
     */
    public function materials(array $rooms): array
    {
        $groups = [];
        foreach ($rooms as $room) {
            $finishes = $room['floors'] ?? [];
            if ($finishes === [] && filled($room['floor_code'] ?? null)) {
                $finishes = [[
                    'code' => $room['floor_code'],
                    'product' => $room['floor_product'] ?? '',
                    'quantity' => $room['floor_quantity'] ?? $room['m2'] ?? null,
                    'material_color' => $room['material_color'],
                ]];
            }
            foreach ($finishes as $finish) {
                $code = trim((string) ($finish['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $key = mb_strtolower($code);
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'key' => $key,
                        'code' => $code,
                        'product' => trim((string) ($finish['product'] ?? '')),
                        'color' => $finish['material_color'],
                        'm2' => 0.0,
                        'count' => 0,
                    ];
                }
                $groups[$key]['count']++;
                if (is_numeric($finish['quantity'] ?? null)) {
                    $groups[$key]['m2'] += (float) $finish['quantity'];
                }
                if ($groups[$key]['product'] === '' && filled($finish['product'] ?? null)) {
                    $groups[$key]['product'] = (string) $finish['product'];
                }
            }
        }
        ksort($groups);

        return array_values(array_map(function (array $group): array {
            $group['m2'] = round($group['m2'], 3);
            $group['m2_label'] = Format::qty($group['m2'], 2).' m²';
            $group['label'] = trim($group['code'].($group['product'] !== '' ? ' – '.$group['product'] : ''));

            return $group;
        }, $groups));
    }

    /**
     * @return array<string, mixed>
     */
    private function drawingSummary(Calculation $calculation, CalculationDrawing $drawing): array
    {
        return [
            'id' => $drawing->id,
            'name' => $drawing->original_filename,
            'label' => $this->drawingLabel($drawing),
            'url' => route('calculations.drawings.show', [$calculation, $drawing]),
            'pdf' => true,
        ];
    }

    private function overlayFromTrace(mixed $trace): ?array
    {
        if (is_string($trace) && $trace !== '') {
            $decoded = json_decode($trace, true);
            $trace = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($trace) || ($trace['role'] ?? '') !== 'local_floor') {
            return null;
        }

        $width = (float) ($trace['page_width'] ?? 0);
        $height = (float) ($trace['page_height'] ?? 0);
        if ($width < 2 || $height < 2) {
            return null;
        }

        $x = ((float) ($trace['x'] ?? 0) / $width) - 0.012;
        $y = ((float) ($trace['y'] ?? 0) / $height) - 0.008;

        return [
            'page' => max(1, (int) ($trace['page'] ?? 1)),
            'x' => max(0.0, $x),
            'y' => max(0.0, $y),
            'w' => 0.024,
            'h' => 0.016,
        ];
    }

    private function drawingLabel(?CalculationDrawing $drawing): string
    {
        if (! $drawing instanceof CalculationDrawing) {
            return 'Zonder tekening';
        }

        $name = trim((string) $drawing->original_filename);
        $stem = pathinfo($name, PATHINFO_FILENAME);

        return $stem !== '' ? $stem : $name;
    }

    private function plinthStatusLabel(string $status): string
    {
        return match ($status) {
            PlinthLengthCalculator::STATUS_NET => 'Exact',
            PlinthLengthCalculator::STATUS_GENEROUS => 'Berekend ruim',
            PlinthLengthCalculator::STATUS_ESTIMATED => 'Geschat ruim',
            default => '—',
        };
    }
}
