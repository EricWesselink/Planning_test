<?php

namespace App\Services\QuoteCalculation;

use App\Enums\CheckStatus;
use App\Enums\FinishRole;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Support\Format;
use App\Support\MaterialColor;
use App\Support\WorkColor;
use App\Support\WorkType;

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
                'create_room' => route('calculations.board.rooms.store', $calculation),
                'confirm_room' => route('calculations.board.rooms.confirm', [$calculation, '__LINE__']),
            ],
            'drawings' => $calculation->drawings
                ->map(fn (CalculationDrawing $drawing) => $this->drawingSummary($calculation, $drawing))
                ->values()
                ->all(),
            'rooms' => $rooms,
            'materials' => $this->materials($rooms),
            'legend' => $this->legendOptions($calculation),
        ];
    }

    /**
     * @return array{room: ?array<string, mixed>, materials: list<array<string, mixed>>}
     */
    public function roomUpdateResponse(Calculation $calculation, CalculationLine $line, bool $canUpdate = true): array
    {
        $payload = $this->payload($calculation, null, $canUpdate);

        return [
            'room' => $this->roomInPayload($payload['rooms'], $line),
            'materials' => $payload['materials'],
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
            $color = $code !== ''
                ? MaterialColor::fromCode($code, $product !== '' ? $product : null)
                : null;
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
                'material_color_soft' => $color === null ? null : MaterialColor::softBackground($color, 0.18),
                'overlay' => $this->overlayFromTrace($line->calculation_trace),
                'chip' => $this->chipFromTrace($line->calculation_trace),
            ];
        }
        $code = trim((string) ($floor?->product_code ?? ''));
        $product = trim((string) ($floor?->product ?? ''));
        $color = $finishes[0]['material_color'] ?? ($code !== '' ? MaterialColor::fromCode($code, $product !== '' ? $product : null) : null);
        $plinthBreakdown = PlinthLengthCalculator::breakdownFrom($plinth?->calculation_trace);
        $roomArea = is_numeric($row['room_area'] ?? null) ? (float) $row['room_area'] : ($floor?->quantity !== null ? (float) $floor->quantity : null);
        $m2 = $roomArea;
        $needsReview = (bool) ($row['needs_review'] ?? false);
        $issues = is_array($row['issues'] ?? null) ? $row['issues'] : [];
        $reviewKind = $this->reviewKind($floor, $issues);
        $groups = $this->roomMaterialGroups($finishes, $plinth, $status);
        $groupCount = count($groups);
        $groupsDone = $needsReview ? 0 : $groupCount;

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
            'material_color_soft' => $color === null ? null : MaterialColor::softBackground($color, 0.18),
            'groups' => $groups,
            'total' => $groupCount,
            'done' => $groupsDone,
            'progress' => $groupCount > 0 ? $groupsDone.'/'.$groupCount : '',
            'contour' => $this->roomContourFrom($floor?->calculation_trace),
            'needs_review' => $needsReview,
            'review_kind' => $reviewKind,
            'review_kind_label' => $this->reviewKindLabel($reviewKind),
            'can_confirm' => (bool) ($row['can_confirm'] ?? false),
            'status' => $status->value,
            'status_label' => $status->label(),
            'tone' => $reviewKind === 'manual' ? 'manual' : ($status->isBlocking() ? ($status === CheckStatus::Missing ? 'missing' : 'review') : 'ok'),
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
            'chip' => $this->chipFromTrace($floor?->calculation_trace),
            'original_floor_code' => $floor?->original_product_code,
            'original_floor_product' => $floor?->original_product,
            'original_floor_quantity' => $floor?->original_quantity !== null ? (float) $floor->original_quantity : null,
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
     * @return array<string, mixed>|null
     */
    private function roomInPayload(array $rooms, CalculationLine $line): ?array
    {
        $id = (int) $line->id;
        foreach ($rooms as $room) {
            if (
                (int) ($room['id'] ?? 0) === $id
                || (int) ($room['floor_id'] ?? 0) === $id
                || (int) ($room['plinth_id'] ?? 0) === $id
            ) {
                return $room;
            }
            foreach ($room['floors'] ?? [] as $finish) {
                if ((int) ($finish['id'] ?? 0) === $id) {
                    return $room;
                }
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
     * @return list<array{code: string, product: string, label: string, color: string}>
     */
    private function legendOptions(Calculation $calculation): array
    {
        $options = [];
        foreach ($calculation->drawings as $drawing) {
            foreach ($drawing->legend ?? [] as $entry) {
                $code = mb_strtolower(trim((string) ($entry['code'] ?? '')));
                $product = trim((string) ($entry['product'] ?? ''));
                if ($code === '' || isset($options[$code]) || ! str_starts_with($code, 'v')) {
                    continue;
                }
                $options[$code] = [
                    'code' => $code,
                    'product' => $product,
                    'label' => trim($code.($product !== '' ? ' – '.$product : '')),
                    'color' => MaterialColor::fromCode($code, $product !== '' ? $product : null),
                ];
            }
        }
        ksort($options, SORT_NATURAL);

        return array_values($options);
    }

    /**
     * @param  list<array<string, mixed>>  $finishes
     * @return list<array<string, mixed>>
     */
    private function roomMaterialGroups(array $finishes, ?CalculationLine $plinth, CheckStatus $status): array
    {
        $groups = [];
        foreach ($finishes as $finish) {
            $role = (string) ($finish['role'] ?? FinishRole::Main->value);
            $groups[] = $this->materialCard(
                key: 'floor:'.($finish['id'] ?? count($groups)),
                product: (string) ($finish['product'] ?? ''),
                code: (string) ($finish['code'] ?? ''),
                quantity: is_numeric($finish['quantity'] ?? null) ? (float) $finish['quantity'] : null,
                unit: WorkUnit::SquareMeter,
                color: is_string($finish['material_color'] ?? null) ? $finish['material_color'] : null,
                colorSoft: is_string($finish['material_color_soft'] ?? null) ? $finish['material_color_soft'] : null,
                groupKey: 'vloer',
                roleLabel: $role === FinishRole::Local->value ? FinishRole::Local->label() : null,
                statusLabel: $status->label(),
                finishId: isset($finish['id']) ? (int) $finish['id'] : null,
                role: $role,
            );
        }
        if ($plinth instanceof CalculationLine) {
            $code = trim((string) ($plinth->product_code ?? ''));
            $product = trim((string) ($plinth->product ?? ''));
            $color = $code !== ''
                ? MaterialColor::fromCode($code, $product !== '' ? $product : null)
                : ($product !== '' ? MaterialColor::resolve(null, $product) : null);
            $groups[] = $this->materialCard(
                key: 'plinth:'.$plinth->id,
                product: $product,
                code: $code,
                quantity: $plinth->quantity !== null ? (float) $plinth->quantity : null,
                unit: WorkUnit::LinearMeter,
                color: $color,
                colorSoft: $color === null ? null : MaterialColor::softBackground($color),
                groupKey: 'plinth',
                roleLabel: null,
                statusLabel: $status->label(),
                finishId: (int) $plinth->id,
                role: 'plinth',
            );
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function materialCard(
        string $key,
        string $product,
        string $code,
        ?float $quantity,
        WorkUnit $unit,
        ?string $color,
        ?string $colorSoft,
        string $groupKey,
        ?string $roleLabel,
        string $statusLabel,
        ?int $finishId = null,
        string $role = 'main',
    ): array {
        $product = trim($product);
        $code = trim($code);
        $typeLabel = $groupKey === 'plinth'
            ? (WorkType::knownType($product) ?? 'Plinten')
            : WorkType::knownType($product);
        $label = $product !== '' ? $product : ($code !== '' ? $code : ($groupKey === 'plinth' ? 'Plint' : 'Vloer'));
        if ($code !== '' && $product !== '' && ! str_contains(mb_strtolower($label), mb_strtolower($code))) {
            $label .= ' '.$code;
        }
        if (is_string($typeLabel) && $typeLabel !== '' && ! str_contains($label, $typeLabel)) {
            $label .= ', '.$typeLabel;
        }
        if (is_string($roleLabel) && $roleLabel !== '' && ! str_contains($label, $roleLabel)) {
            $label .= ' · '.$roleLabel;
        }
        $hex = $color ?: MaterialColor::UNKNOWN;
        $colorKey = WorkColor::key($groupKey, $typeLabel, $label);
        $isLocal = $role === FinishRole::Local->value;
        $qtyLabel = $quantity === null
            ? ($isLocal ? 'Geen m²' : '')
            : 'Calc. '.Format::qty($quantity, 2).' '.$unit->label();

        return [
            'key' => $key,
            'finish_id' => $finishId,
            'role' => $role,
            'is_local' => $isLocal,
            'needs_local_area' => $isLocal && $quantity === null,
            'label' => $label,
            'type_label' => $typeLabel,
            'color_key' => $colorKey,
            'color_label' => WorkColor::legendLabel($colorKey),
            'display_color' => $hex,
            'display_color_soft' => $colorSoft ?: MaterialColor::softBackground($hex),
            'status_label' => $statusLabel,
            'quantity' => $quantity,
            'quantity_label' => $qtyLabel,
            'progress_label' => $qtyLabel,
            'unit' => $unit->value,
        ];
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
        $decoded = $this->decodeTrace($trace);
        if ($decoded === null || ($decoded['reliable'] ?? false) !== true) {
            return null;
        }
        if (($decoded['role'] ?? '') === 'local_floor') {
            return $this->normalizedOverlay($decoded);
        }

        return null;
    }

    /**
     * @return array{x: float, y: float, page: int, manual: bool}|null
     */
    private function chipFromTrace(mixed $trace): ?array
    {
        $decoded = $this->decodeTrace($trace);
        $chip = is_array($decoded['chip'] ?? null) ? $decoded['chip'] : null;
        if ($chip === null || ! is_numeric($chip['x'] ?? null) || ! is_numeric($chip['y'] ?? null)) {
            return null;
        }

        return [
            'x' => max(0.0, min(1.0, (float) $chip['x'])),
            'y' => max(0.0, min(1.0, (float) $chip['y'])),
            'page' => max(1, (int) ($chip['page'] ?? 1)),
            'manual' => ($chip['manual'] ?? true) !== false,
        ];
    }

    private function roomContourFrom(mixed $trace): ?array
    {
        $decoded = $this->decodeTrace($trace);
        if ($decoded === null || ($decoded['role'] ?? '') !== 'room_floor' || ($decoded['reliable'] ?? false) !== true) {
            return null;
        }

        return $this->normalizedOverlay($decoded);
    }

    /**
     * @param  list<string>  $issues
     */
    private function reviewKind(?CalculationLine $floor, array $issues): string
    {
        if ($issues !== []) {
            return 'review';
        }
        if ($this->isManuallyAdjusted($floor)) {
            return 'manual';
        }

        return 'certain';
    }

    private function isManuallyAdjusted(?CalculationLine $floor): bool
    {
        if (! $floor instanceof CalculationLine) {
            return false;
        }
        $originalCode = mb_strtolower(trim((string) $floor->original_product_code));
        $currentCode = mb_strtolower(trim((string) $floor->product_code));
        if ($originalCode !== '' && $currentCode !== $originalCode) {
            return true;
        }
        if ($floor->original_quantity === null || $floor->quantity === null) {
            return false;
        }

        return abs((float) $floor->quantity - (float) $floor->original_quantity) > 0.001;
    }

    private function reviewKindLabel(string $kind): string
    {
        return match ($kind) {
            'manual' => 'Handmatig aangepast',
            'review' => 'Controleren',
            default => 'Zeker',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeTrace(mixed $trace): ?array
    {
        if (is_string($trace) && $trace !== '') {
            $decoded = json_decode($trace, true);
            $trace = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($trace)) {
            return null;
        }

        return $trace;
    }

    /**
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>|null
     */
    private function normalizedOverlay(array $trace): ?array
    {
        $rects = [];
        foreach ($trace['rects'] ?? [] as $rect) {
            if (! is_array($rect)) {
                continue;
            }
            $width = (float) ($rect['w'] ?? 0);
            $height = (float) ($rect['h'] ?? 0);
            if ($width < 0.002 || $height < 0.002) {
                continue;
            }
            $rects[] = [
                'x' => max(0.0, (float) ($rect['x'] ?? 0)),
                'y' => max(0.0, (float) ($rect['y'] ?? 0)),
                'w' => min(1.0, $width),
                'h' => min(1.0, $height),
            ];
        }
        if ($rects === []) {
            return null;
        }

        return [
            'role' => (string) ($trace['role'] ?? 'room_floor'),
            'reliable' => true,
            'page' => max(1, (int) ($trace['page'] ?? 1)),
            'rects' => $rects,
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
