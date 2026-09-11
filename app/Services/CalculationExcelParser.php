<?php

namespace App\Services;

use App\Enums\WorkUnit;
use App\Support\DutchNumber;
use App\Support\WorkType;

class CalculationExcelParser
{
    /**
     * @param  list<list<string>>  $rows
     */
    public function looksLike(array $rows): bool
    {
        return $this->findHeader($rows) !== null;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{
     *     matched: bool,
     *     filename: string,
     *     work_number: ?string,
     *     lines: list<array<string, mixed>>,
     *     labor: list<array<string, mixed>>,
     *     total_hours: float,
     *     total_labor_cost: float
     * }
     */
    public function parse(array $rows, string $filename = ''): array
    {
        $header = $this->findHeader($rows);
        if ($header === null) {
            return [
                'matched' => false,
                'filename' => $filename,
                'work_number' => null,
                'lines' => [],
                'labor' => [],
                'total_hours' => 0.0,
                'total_labor_cost' => 0.0,
            ];
        }

        $lines = [];
        for ($index = $header['index'] + 1, $count = count($rows); $index < $count; $index++) {
            $row = $rows[$index];
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $mapped = $this->mapRow($row, $header['columns'], $index + 1, $filename);
            if ($mapped === null) {
                continue;
            }

            $lines[] = $mapped;
        }

        $lines = $this->attachLaborQuantities($lines);
        $labor = array_values(array_filter($lines, fn (array $line): bool => $line['is_labor']));
        $groups = array_values(array_unique(array_filter(array_column($lines, 'group_code'))));

        return [
            'matched' => true,
            'filename' => $filename,
            'work_number' => count($groups) === 1 ? $groups[0] : null,
            'lines' => $lines,
            'labor' => $labor,
            'total_hours' => round(array_sum(array_column($labor, 'hours')), 2),
            'total_labor_cost' => round(array_sum(array_column($labor, 'labor_cost')), 2),
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{index: int, columns: array<string, int>}|null
     */
    private function findHeader(array $rows): ?array
    {
        $limit = min(20, count($rows));
        for ($index = 0; $index < $limit; $index++) {
            $columns = $this->mapHeader($rows[$index]);
            if ($columns === null) {
                continue;
            }

            return ['index' => $index, 'columns' => $columns];
        }

        return null;
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, int>|null
     */
    private function mapHeader(array $cells): ?array
    {
        $columns = [];
        foreach ($cells as $index => $cell) {
            $key = $this->headerKey((string) $cell);
            if ($key === null || isset($columns[$key])) {
                continue;
            }

            $columns[$key] = $index;
        }

        $hasDescription = isset($columns['production_description']) || isset($columns['article_description']);
        if (! isset($columns['mu'], $columns['quantity'], $columns['unit_cost']) || ! $hasDescription) {
            return null;
        }

        return $columns;
    }

    private function headerKey(string $value): ?string
    {
        $header = $this->normalizeHeader($value);

        return match (true) {
            $header === 'km' => 'km',
            in_array($header, ['groep', 'group'], true) => 'group',
            in_array($header, ['m/u', 'mu', 'm u'], true) => 'mu',
            str_starts_with($header, 'artikelnr') || $header === 'artikelnummer' => 'article_number',
            str_contains($header, 'productie') && str_contains($header, 'omschrijving') => 'production_description',
            str_contains($header, 'artikel') && str_contains($header, 'omschrijving') => 'article_description',
            $header === 'omschrijving' => 'article_description',
            $header === 'aantal' => 'quantity',
            in_array($header, ['eh', 'eenheid', 'unit', 'eenh'], true) => 'unit',
            $header === 'kostprijs' => 'unit_cost',
            str_starts_with($header, 'kostprijs tot') => 'total_cost',
            str_starts_with($header, 'naca') => 'naca_code',
            $header === 'werksrt' => 'work_sort',
            default => null,
        };
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $columns
     * @return array<string, mixed>|null
     */
    private function mapRow(array $row, array $columns, int $rowNumber, string $filename): ?array
    {
        $production = $this->cell($row, $columns['production_description'] ?? null);
        $article = $this->cell($row, $columns['article_description'] ?? null);
        if ($production === '' && $article === '') {
            return null;
        }

        $unit = $this->cell($row, $columns['unit'] ?? null);
        $mu = mb_strtoupper($this->cell($row, $columns['mu'] ?? null));
        $quantity = $this->number($this->cell($row, $columns['quantity'] ?? null));
        $unitCost = $this->number($this->cell($row, $columns['unit_cost'] ?? null));
        $totalCost = $this->number($this->cell($row, $columns['total_cost'] ?? null));
        $isLabor = $this->isLaborRow($mu, $unit);
        $hours = $isLabor ? $quantity : null;
        $hourlyRate = $isLabor ? $unitCost : null;
        $laborCost = $isLabor
            ? ($totalCost ?? (($hours !== null && $hourlyRate !== null) ? round($hours * $hourlyRate, 2) : null))
            : null;

        return [
            'row_number' => $rowNumber,
            'source_filename' => $filename,
            'km' => $this->cell($row, $columns['km'] ?? null) ?: null,
            'group_code' => $this->cell($row, $columns['group'] ?? null) ?: null,
            'mu' => $mu !== '' ? $mu : null,
            'article_number' => $this->cell($row, $columns['article_number'] ?? null) ?: null,
            'production_description' => $production !== '' ? $production : null,
            'article_description' => $article !== '' ? $article : null,
            'unit' => $unit !== '' ? $unit : null,
            'quantity' => $quantity,
            'quantity_unit' => $unit !== '' ? $unit : null,
            'hours' => $hours,
            'hourly_rate' => $hourlyRate,
            'labor_cost' => $laborCost,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'is_labor' => $isLabor,
            'quantity_status' => $isLabor ? 'missing' : 'ignored',
            'naca_code' => $this->cell($row, $columns['naca_code'] ?? null) ?: null,
            'raw' => $this->rawRow($row, $columns),
        ];
    }

    private function isLaborRow(string $mu, string $unit): bool
    {
        return $mu === 'U' && $this->isHoursUnit($unit);
    }

    private function isHoursUnit(string $unit): bool
    {
        $unit = rtrim(mb_strtolower(trim($unit)), '.');

        return in_array($unit, ['uur', 'uren', 'u', 'hour', 'hours', 'hrs'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function attachLaborQuantities(array $lines): array
    {
        $materialsByKey = [];
        $laborIndexesByKey = [];

        foreach ($lines as $index => $line) {
            $key = $this->quantityGroupKey($line);
            if ($key === '') {
                continue;
            }

            if ($line['is_labor']) {
                $laborIndexesByKey[$key][] = $index;

                continue;
            }

            $unit = $this->floorUnit((string) ($line['unit'] ?? ''));
            if ($unit === null || $line['quantity'] === null || (float) $line['quantity'] <= 0) {
                continue;
            }

            $articleKey = $this->articleKey($line);
            if (! isset($materialsByKey[$key][$articleKey])) {
                $materialsByKey[$key][$articleKey] = [
                    'label' => $this->articleLabel($line),
                    'm2' => 0.0,
                    'm1' => 0.0,
                ];
            }
            $materialsByKey[$key][$articleKey][$unit] += (float) $line['quantity'];
        }

        foreach ($laborIndexesByKey as $key => $indexes) {
            $articles = array_values(array_filter(
                $materialsByKey[$key] ?? [],
                fn (array $article): bool => $article['m2'] > 0.0001 || $article['m1'] > 0.0001
            ));

            foreach ($indexes as $index) {
                $linked = $this->reliableQuantity($lines[$index], $articles);
                if ($linked === null) {
                    $lines[$index]['quantity_status'] = $articles === [] ? 'missing' : 'review';

                    continue;
                }

                $lines[$index]['quantity'] = $linked['quantity'];
                $lines[$index]['quantity_unit'] = $linked['unit'];
                $lines[$index]['quantity_status'] = 'linked';
            }
        }

        return $this->attachPreparationFloorQuantities($lines);
    }

    /**
     * Primen/egaliseren-arbeid heeft in Excel vaak alleen uren; de begrote m² staan
     * op de materiaalregels van harde vloerafwerkingen in dezelfde groep.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function attachPreparationFloorQuantities(array $lines): array
    {
        $floorM2ByGroup = [];
        foreach ($lines as $line) {
            if ($line['is_labor'] || $this->floorUnit((string) ($line['unit'] ?? '')) !== 'm2') {
                continue;
            }
            if ($line['quantity'] === null || (float) $line['quantity'] <= 0.0001) {
                continue;
            }
            if (! WorkType::requiresPrimingLeveling((string) ($line['production_description'] ?? ''))) {
                continue;
            }

            $group = mb_strtolower(trim((string) ($line['group_code'] ?? '')));
            $floorM2ByGroup[$group] = ($floorM2ByGroup[$group] ?? 0.0) + (float) $line['quantity'];
        }

        foreach ($lines as $index => $line) {
            if (! $line['is_labor'] || ($line['quantity_status'] ?? '') === 'linked') {
                continue;
            }
            if (! $this->isPreparationLabor($line)) {
                continue;
            }

            $group = mb_strtolower(trim((string) ($line['group_code'] ?? '')));
            $m2 = $floorM2ByGroup[$group] ?? 0.0;
            if ($m2 <= 0.0001) {
                continue;
            }

            $lines[$index]['quantity'] = round($m2, 4);
            $lines[$index]['quantity_unit'] = WorkUnit::SquareMeter->value;
            $lines[$index]['quantity_status'] = 'linked';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isPreparationLabor(array $line): bool
    {
        $flat = mb_strtolower(trim(
            (string) ($line['production_description'] ?? '').' '.(string) ($line['article_description'] ?? '')
        ));

        return (bool) preg_match('/schuur|primer|primen|egalis|voorber/u', $flat);
    }

    /**
     * @param  list<array{label: string, m2: float, m1: float}>  $articles
     * @return array{quantity: float, unit: string}|null
     */
    private function reliableQuantity(array $labor, array $articles): ?array
    {
        if ($articles === []) {
            return null;
        }

        if (count($articles) === 1) {
            return $this->floorAmount($articles[0]);
        }

        return $this->uniqueArticleMatch((string) ($labor['production_description'] ?? ''), $articles);
    }

    /**
     * @param  list<array{label: string, m2: float, m1: float}>  $articles
     * @return array{quantity: float, unit: string}|null
     */
    private function uniqueArticleMatch(string $production, array $articles): ?array
    {
        $haystack = mb_strtolower(trim($production));
        if ($haystack === '') {
            return null;
        }

        $hits = [];
        foreach ($articles as $article) {
            $needle = mb_strtolower(trim($article['label']));
            if (mb_strlen($needle) < 12) {
                continue;
            }
            if (! str_contains($haystack, $needle) && ! str_contains($needle, $haystack)) {
                continue;
            }
            $hits[] = $article;
        }

        if (count($hits) !== 1) {
            return null;
        }

        return $this->floorAmount($hits[0]);
    }

    /**
     * @param  array{m2: float, m1: float}  $article
     * @return array{quantity: float, unit: string}
     */
    private function floorAmount(array $article): array
    {
        if ($article['m2'] > 0.0001) {
            return [
                'quantity' => round($article['m2'], 4),
                'unit' => WorkUnit::SquareMeter->value,
            ];
        }

        return [
            'quantity' => round($article['m1'], 4),
            'unit' => WorkUnit::LinearMeter->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function quantityGroupKey(array $line): string
    {
        $production = $this->productionKey((string) ($line['production_description'] ?? ''));
        if ($production === '') {
            return '';
        }

        return mb_strtolower(trim((string) ($line['group_code'] ?? ''))).'#'.$production;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function articleKey(array $line): string
    {
        $number = mb_strtolower(trim((string) ($line['article_number'] ?? '')));
        if ($number !== '') {
            return 'nr:'.$number;
        }

        $article = mb_strtolower(trim((string) ($line['article_description'] ?? '')));

        return $article !== '' ? 'om:'.$article : 'row:'.(int) ($line['row_number'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function articleLabel(array $line): string
    {
        $article = trim((string) ($line['article_description'] ?? ''));

        return $article !== '' ? $article : trim((string) ($line['production_description'] ?? ''));
    }

    private function floorUnit(string $unit): ?string
    {
        $unit = rtrim(mb_strtolower(trim($unit)), '.');

        return match ($unit) {
            'm2', 'm²', 'm 2' => 'm2',
            'm1', 'm¹', 'lm' => 'm1',
            default => null,
        };
    }

    private function productionKey(string $description): string
    {
        return mb_strtolower(trim($description));
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $columns
     * @return array<string, string>
     */
    private function rawRow(array $row, array $columns): array
    {
        $raw = [];
        foreach ($columns as $key => $index) {
            $raw[$key] = $this->cell($row, $index);
        }

        return $raw;
    }

    /**
     * @param  list<string>  $row
     */
    private function cell(array $row, ?int $index): string
    {
        if ($index === null) {
            return '';
        }

        $value = str_replace(["\n", "\r", "\t"], ' ', trim((string) ($row[$index] ?? '')));

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * @param  list<string>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function number(?string $value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return DutchNumber::parse($raw);
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(["\n", "\r", "\t", '-', '_'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return rtrim(trim($value), '.');
    }
}
