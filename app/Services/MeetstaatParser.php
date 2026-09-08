<?php

namespace App\Services;

use App\Enums\WorkUnit;
use App\Support\DutchNumber;

class MeetstaatParser
{
    /** @var list<string> */
    private array $skipHeaders = [
        'id', 'opmerking', 'opmerkingen', 'notes', 'note', 'prijs', 'bedrag',
        'tarief', 'totaalprijs', 'leeg', 'extra', 'foto',
    ];

    /**
     * @param  list<list<string>>  $rows
     * @return array{floors: list<array{name: string, areas: list<array{area_number: ?string, name: string, square_meters: float, tasks: list<array{work_name: string, quantity: float, unit: string}>}>}>, warnings: list<string>}
     */
    public function parse(array $rows): array
    {
        [$headerIndex, $columns] = $this->detectHeader($rows);
        if ($columns === []) {
            return ['floors' => [], 'warnings' => ['Geen kopregel gevonden in de meetstaat.']];
        }

        $grouped = [];
        $warnings = [];
        $body = array_slice($rows, $headerIndex + 1);

        foreach ($body as $row) {
            $area = $this->parseRow($row, $columns);
            if ($area === null) {
                continue;
            }

            $floorName = $area['floor'];
            unset($area['floor']);

            if ($area['tasks'] === [] && $area['square_meters'] <= 0 && $area['name'] === '') {
                continue;
            }

            if ($this->isTotalRow($area)) {
                continue;
            }

            if ($area['tasks'] === [] && $area['square_meters'] > 0) {
                $area['tasks'][] = [
                    'work_name' => 'Vloerwerk',
                    'quantity' => $area['square_meters'],
                    'unit' => WorkUnit::SquareMeter->value,
                ];
            }

            if ($area['square_meters'] <= 0) {
                $area['square_meters'] = collect($area['tasks'])
                    ->where('unit', WorkUnit::SquareMeter->value)
                    ->sum('quantity');
            }

            if ($area['name'] === '') {
                $existingCount = count($grouped[$floorName] ?? []);
                $area['name'] = $area['area_number']
                    ? 'Ruimte '.$area['area_number']
                    : 'Ruimte '.($existingCount + 1);
            }

            $key = mb_strtolower($floorName).'|'.mb_strtolower((string) $area['area_number']).'|'.mb_strtolower($area['name']);
            if (! isset($grouped[$floorName][$key])) {
                $grouped[$floorName][$key] = $area;

                continue;
            }

            $grouped[$floorName][$key]['square_meters'] = max(
                $grouped[$floorName][$key]['square_meters'],
                $area['square_meters']
            );
            $grouped[$floorName][$key]['tasks'] = $this->mergeTasks(
                $grouped[$floorName][$key]['tasks'],
                $area['tasks']
            );
        }

        $result = [];
        foreach ($grouped as $name => $areas) {
            $result[] = ['name' => $name, 'areas' => array_values($areas)];
        }

        if ($result === []) {
            $warnings[] = 'De meetstaat is gelezen, maar er zijn geen ruimtes gevonden.';
        }

        return ['floors' => $result, 'warnings' => $warnings];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{0: int, 1: array<int, array{raw: string, key: string, role: string}>}
     */
    private function detectHeader(array $rows): array
    {
        $limit = min(20, count($rows));
        $best = [0, []];
        $bestScore = -1;

        for ($i = 0; $i < $limit; $i++) {
            $columns = $this->mapColumns($rows[$i] ?? []);
            $roles = collect($columns)->pluck('role');
            $score = 0;
            if ($roles->contains('area_number')) {
                $score += 3;
            }
            if ($roles->contains('name')) {
                $score += 3;
            }
            if ($roles->contains('floor')) {
                $score += 2;
            }
            if ($roles->contains('square_meters')) {
                $score += 2;
            }
            if ($roles->contains('work_name')) {
                $score += 2;
            }
            $score += $roles->filter(fn ($role) => $role === 'work_qty')->count();

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$i, $columns];
            }
        }

        return $bestScore >= 3 ? $best : [0, $this->mapColumns($rows[0] ?? [])];
    }

    /**
     * @param  list<string>  $header
     * @return array<int, array{raw: string, key: string, role: string}>
     */
    private function mapColumns(array $header): array
    {
        $columns = [];
        $usedRoles = [];

        foreach ($header as $index => $raw) {
            $key = $this->normalize($raw);
            if ($key === '' || in_array($key, $this->skipHeaders, true)) {
                continue;
            }

            $role = $this->roleFor($key, $usedRoles);
            if ($role === 'skip') {
                continue;
            }

            if (in_array($role, ['area_number', 'name', 'floor', 'square_meters', 'work_name', 'unit'], true)) {
                $usedRoles[] = $role;
            }

            $columns[$index] = ['raw' => trim((string) $raw), 'key' => $key, 'role' => $role];
        }

        return $columns;
    }

    /** @param list<string> $usedRoles */
    private function roleFor(string $key, array $usedRoles): string
    {
        $numberKeys = ['nummer', 'nr', 'no', 'ruimtenr', 'ruimtenummer', 'ruimteno', 'pos', 'positie', 'code', 'ruimtecode'];
        $nameKeys = ['naam', 'omschrijving', 'ruimte', 'ruimtenaam', 'ruimteomschrijving', 'lokaal', 'vertrek'];
        $floorKeys = ['verdieping', 'etage', 'bouwlaag', 'laag', 'vloer', 'verdiepingnr'];
        $m2Keys = ['m2', 'opp', 'oppervlakte', 'oppervlak', 'vierkantemeter', 'vloeropp'];
        $workKeys = ['werkzaamheid', 'werk', 'activiteit', 'post', 'bewerking'];
        $unitKeys = ['eenheid', 'unit', 'eh', 'eenh'];
        $qtyKeys = ['hoeveelheid', 'aantal', 'qty', 'kwantiteit'];

        if (in_array($key, $numberKeys, true) && ! in_array('area_number', $usedRoles, true)) {
            return 'area_number';
        }
        if (in_array($key, $nameKeys, true) && ! in_array('name', $usedRoles, true)) {
            return 'name';
        }
        if (in_array($key, $floorKeys, true)) {
            return 'floor';
        }
        if (in_array($key, $m2Keys, true) && ! in_array('square_meters', $usedRoles, true)) {
            return 'square_meters';
        }
        if (in_array($key, $workKeys, true)) {
            return 'work_name';
        }
        if (in_array($key, $unitKeys, true)) {
            return 'unit';
        }
        if (in_array($key, $qtyKeys, true)) {
            return 'quantity';
        }

        return 'work_qty';
    }

    /**
     * @param  list<string>  $row
     * @param  array<int, array{raw: string, key: string, role: string}>  $columns
     * @return array{area_number: ?string, name: string, floor: string, square_meters: float, tasks: list<array{work_name: string, quantity: float, unit: string}>}|null
     */
    private function parseRow(array $row, array $columns): ?array
    {
        $areaNumber = null;
        $name = '';
        $floor = 'Begane grond';
        $squareMeters = 0.0;
        $workName = null;
        $unit = WorkUnit::SquareMeter->value;
        $quantity = null;
        $tasks = [];
        $hasValue = false;

        foreach ($columns as $index => $column) {
            $value = trim((string) ($row[$index] ?? ''));
            if ($value !== '') {
                $hasValue = true;
            }

            match ($column['role']) {
                'area_number' => $areaNumber = $value !== '' ? $value : null,
                'name' => $name = $value,
                'floor' => $floor = $value !== '' ? $value : $floor,
                'square_meters' => $squareMeters = DutchNumber::parse($value) ?? 0,
                'work_name' => $workName = $value !== '' ? $value : null,
                'unit' => $unit = $this->unitFrom($value),
                'quantity' => $quantity = DutchNumber::parse($value),
                'work_qty' => $this->pushWorkColumn($tasks, $column['raw'], $value),
                default => null,
            };
        }

        if (! $hasValue) {
            return null;
        }

        if ($workName && $quantity !== null && $quantity > 0) {
            $tasks[] = [
                'work_name' => $workName,
                'quantity' => $quantity,
                'unit' => $unit,
            ];
        }

        return [
            'area_number' => $areaNumber,
            'name' => $name,
            'floor' => $floor,
            'square_meters' => $squareMeters,
            'tasks' => $tasks,
        ];
    }

    /** @param list<array{work_name: string, quantity: float, unit: string}> $tasks */
    private function pushWorkColumn(array &$tasks, string $header, string $value): void
    {
        $quantity = DutchNumber::parse($value);
        if ($quantity === null || $quantity <= 0) {
            return;
        }

        $tasks[] = [
            'work_name' => $this->prettyWorkName($header),
            'quantity' => $quantity,
            'unit' => $this->unitFromHeader($header),
        ];
    }

    /**
     * @param  list<array{work_name: string, quantity: float, unit: string}>  $current
     * @param  list<array{work_name: string, quantity: float, unit: string}>  $incoming
     * @return list<array{work_name: string, quantity: float, unit: string}>
     */
    private function mergeTasks(array $current, array $incoming): array
    {
        foreach ($incoming as $task) {
            $found = false;
            foreach ($current as $index => $existing) {
                if (mb_strtolower($existing['work_name']) === mb_strtolower($task['work_name'])
                    && $existing['unit'] === $task['unit']) {
                    $current[$index]['quantity'] += $task['quantity'];
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $current[] = $task;
            }
        }

        return $current;
    }

    /** @param array{area_number: ?string, name: string, square_meters: float, tasks: array} $area */
    private function isTotalRow(array $area): bool
    {
        $haystack = mb_strtolower(trim($area['name'].' '.($area['area_number'] ?? '')));

        return (bool) preg_match('/^(totaal|total|som|subtotal|eindtotaal|algemeen totaal)/', $haystack);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['²', '¹', '³'], ['2', '1', '3'], $value);

        return preg_replace('/[^a-z0-9]+/u', '', $value) ?? '';
    }

    private function prettyWorkName(string $header): string
    {
        $header = trim($header);
        $header = preg_replace('/\s*\(.*\)\s*$/', '', $header) ?? $header;

        return $header !== '' ? $header : 'Vloerwerk';
    }

    private function unitFromHeader(string $header): string
    {
        $key = $this->normalize($header);
        if (preg_match('/plint|m1|strekkend|omtrek|lengte/', $key)) {
            return WorkUnit::LinearMeter->value;
        }
        if (preg_match('/stuks|aantal|stuk/', $key)) {
            return WorkUnit::Pieces->value;
        }

        return WorkUnit::SquareMeter->value;
    }

    private function unitFrom(string $value): string
    {
        $key = $this->normalize($value);

        return match (true) {
            str_contains($key, 'm1') || str_contains($key, 'lm') || str_contains($key, 'strekkend') => WorkUnit::LinearMeter->value,
            str_contains($key, 'stuk') => WorkUnit::Pieces->value,
            str_contains($key, 'uur') => WorkUnit::Hours->value,
            default => WorkUnit::SquareMeter->value,
        };
    }
}
