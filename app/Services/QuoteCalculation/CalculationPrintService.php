<?php

namespace App\Services\QuoteCalculation;

use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Support\Format;

class CalculationPrintService
{
    public const INCLUDES = [
        'colored',
        'rooms',
        'codes',
        'legend',
        'room_list',
        'area_totals',
        'plinth_totals',
        'details',
    ];

    public const DRAWING_INCLUDES = [
        'colored',
        'rooms',
        'codes',
        'legend',
    ];

    public const DEFAULT_INCLUDES = [
        'colored',
        'rooms',
        'codes',
        'legend',
    ];

    public function __construct(
        private CalculationBoardService $board,
        private CalculationTotals $totals,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     print_url: string,
     *     drawings: list<array{id: int, label: string}>,
     *     materials: list<array<string, mixed>>,
     *     defaults: array<string, mixed>
     * }
     */
    public function options(Calculation $calculation): array
    {
        $payload = $this->board->payload($calculation);
        $drawings = array_map(fn (array $drawing): array => [
            'id' => (int) $drawing['id'],
            'label' => (string) $drawing['label'],
        ], $payload['drawings']);

        return [
            'name' => $calculation->name,
            'print_url' => route('calculations.print', $calculation),
            'drawings' => $drawings,
            'materials' => array_map(fn (array $material): array => [
                'key' => $material['key'],
                'label' => $material['label'],
                'code' => $material['code'],
                'product' => $material['product'],
                'color' => $material['color'],
                'm2_label' => $material['m2_label'],
            ], $payload['materials']),
            'defaults' => [
                'include' => self::DEFAULT_INCLUDES,
                'drawing_ids' => array_column($drawings, 'id'),
                'material_mode' => 'all',
                'material_keys' => [],
            ],
        ];
    }

    /**
     * @param  array{
     *     include: list<string>,
     *     drawing_ids: list<int>,
     *     material_keys: list<string>,
     *     output: string
     * }  $options
     * @return array<string, mixed>
     */
    public function document(Calculation $calculation, array $options): array
    {
        $payload = $this->board->payload($calculation);
        $include = array_values(array_intersect(self::INCLUDES, $options['include']));
        $drawingIds = array_values(array_unique(array_map('intval', $options['drawing_ids'])));
        if ($drawingIds === []) {
            $drawingIds = array_map(fn (array $drawing): int => (int) $drawing['id'], $payload['drawings']);
        }
        $materialKeys = array_values(array_unique(array_map(
            fn (string $key): string => mb_strtolower($key),
            $options['material_keys'],
        )));

        $rooms = array_values(array_filter(
            $payload['rooms'],
            fn (array $room): bool => $this->roomMatches($room, $drawingIds, $materialKeys),
        ));
        $drawings = array_values(array_map(function (array $drawing) use ($rooms): array {
            $drawingRooms = array_values(array_filter(
                $rooms,
                fn (array $room): bool => (int) ($room['drawing_id'] ?? 0) === (int) $drawing['id'],
            ));
            $drawing['materials'] = $this->board->materials($drawingRooms);

            return $drawing;
        }, array_filter(
            $payload['drawings'],
            fn (array $drawing): bool => in_array((int) $drawing['id'], $drawingIds, true),
        )));
        $materials = $this->board->materials($rooms);

        $showDrawings = $this->wantsAny($include, self::DRAWING_INCLUDES);

        return [
            'calculation' => [
                'name' => $calculation->name,
                'client_name' => $calculation->client_name,
                'project_name' => $calculation->project_name,
                'dated_on' => $calculation->dated_on?->format('d-m-Y'),
            ],
            'include' => array_merge(
                array_fill_keys(self::INCLUDES, false),
                array_fill_keys($include, true),
            ),
            'output' => $options['output'] === 'print' ? 'print' : 'pdf',
            'auto_print' => true,
            'show_drawings' => $showDrawings,
            'drawings' => $showDrawings ? $drawings : [],
            'rooms' => $rooms,
            'materials' => $materials,
            'material_keys' => $materialKeys,
            'room_lists' => in_array('room_list', $include, true)
                ? $this->roomLists($drawings, $rooms)
                : [],
            'area_totals' => in_array('area_totals', $include, true)
                ? $this->areaTotals($rooms, $materialKeys)
                : [],
            'plinth_totals' => in_array('plinth_totals', $include, true)
                ? $this->plinthTotals($rooms)
                : [],
            'details' => in_array('details', $include, true)
                ? $this->detailRows($rooms)
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  list<int>  $drawingIds
     * @param  list<string>  $materialKeys
     */
    public function roomMatches(array $room, array $drawingIds, array $materialKeys): bool
    {
        if ($drawingIds !== [] && ! in_array((int) ($room['drawing_id'] ?? 0), $drawingIds, true)) {
            return false;
        }

        return $this->roomMatchesMaterials($room, $materialKeys);
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  list<string>  $keys
     */
    public function roomMatchesMaterials(array $room, array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        $roomKeys = $room['material_keys'] ?? [];
        if ($roomKeys === [] && filled($room['material_key'] ?? null)) {
            $roomKeys = [$room['material_key']];
        }

        foreach ($roomKeys as $key) {
            if (in_array(mb_strtolower((string) $key), $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $include
     * @param  list<string>  $flags
     */
    private function wantsAny(array $include, array $flags): bool
    {
        return array_intersect($include, $flags) !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $drawings
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array{id: int, label: string, rooms: list<array<string, mixed>>}>
     */
    private function roomLists(array $drawings, array $rooms): array
    {
        $grouped = [];
        foreach ($drawings as $drawing) {
            $id = (int) $drawing['id'];
            $grouped[$id] = [
                'id' => $id,
                'label' => (string) $drawing['label'],
                'rooms' => [],
            ];
        }
        foreach ($rooms as $room) {
            $id = (int) ($room['drawing_id'] ?? 0);
            if (! isset($grouped[$id])) {
                continue;
            }
            $grouped[$id]['rooms'][] = [
                'number' => $room['number'],
                'name' => $room['name'],
                'm2_label' => $room['m2_label'],
                'floor_codes_label' => $room['floor_codes_label'] ?: $room['floor_code'],
                'plinth_code' => $room['plinth_code'],
                'plinth_quantity_label' => $room['plinth_quantity_label'],
            ];
        }

        return array_values($grouped);
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @param  list<string>  $materialKeys
     * @return list<array<string, mixed>>
     */
    private function areaTotals(array $rooms, array $materialKeys): array
    {
        $lines = [];
        foreach ($rooms as $room) {
            foreach ($room['floors'] ?? [] as $finish) {
                $key = mb_strtolower((string) ($finish['code'] ?? ''));
                if ($materialKeys !== [] && ! in_array($key, $materialKeys, true)) {
                    continue;
                }
                if (! is_numeric($finish['quantity'] ?? null)) {
                    continue;
                }
                $lines[] = [
                    'product_code' => $finish['code'] ?? null,
                    'product' => $finish['product'] ?? null,
                    'quantity' => $finish['quantity'],
                    'unit' => WorkUnit::SquareMeter->value,
                ];
            }
        }

        return $this->labelTotals($this->totals->grouped(collect($lines)));
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array<string, mixed>>
     */
    private function plinthTotals(array $rooms): array
    {
        $lines = [];
        foreach ($rooms as $room) {
            if (! is_numeric($room['plinth_quantity'] ?? null)) {
                continue;
            }
            $lines[] = [
                'product_code' => $room['plinth_code'] ?? null,
                'product' => $room['plinth_product'] ?? null,
                'quantity' => $room['plinth_quantity'],
                'unit' => WorkUnit::LinearMeter->value,
            ];
        }

        return $this->labelTotals($this->totals->grouped(collect($lines)));
    }

    /**
     * @param  list<array<string, mixed>>  $rooms
     * @return list<array<string, mixed>>
     */
    private function detailRows(array $rooms): array
    {
        $rows = [];
        foreach ($rooms as $room) {
            $rows[] = [
                'group' => $room['group'],
                'number' => $room['number'],
                'name' => $room['name'],
                'm2_label' => $room['m2_label'],
                'floor_codes_label' => $room['floor_codes_label'] ?: $room['floor_code'],
                'floor_product' => $room['floor_product'],
                'plinth_code' => $room['plinth_code'],
                'plinth_product' => $room['plinth_product'],
                'plinth_quantity_label' => $room['plinth_quantity_label'],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $totals
     * @return list<array<string, mixed>>
     */
    private function labelTotals(array $totals): array
    {
        return array_map(function (array $total): array {
            $total['quantity_label'] = Format::qty($total['quantity'], 2);

            return $total;
        }, $totals);
    }
}
