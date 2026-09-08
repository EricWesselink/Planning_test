<?php

namespace App\Services;

use App\Enums\WorkUnit;
use App\Support\DutchNumber;

class ScreenExcelParser
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
     *     lines: list<array{
     *         description: string,
     *         bnr: ?string,
     *         group: ?string,
     *         quantity: float,
     *         unit: string,
     *         source_rows: int
     *     }>,
     *     unrecognized: list<array{row: int, description: string, reason: string}>,
     *     skipped_labor: int,
     *     processed_rows: int,
     *     total_pieces: float,
     *     summary: string,
     *     ready: bool
     * }
     */
    public function parse(array $rows): array
    {
        $header = $this->findHeader($rows);
        if ($header === null) {
            return $this->emptyResult(false);
        }

        $merged = [];
        $unrecognized = [];
        $skippedLabor = 0;
        $processedRows = 0;

        for ($index = $header['index'] + 1, $count = count($rows); $index < $count; $index++) {
            $row = $rows[$index];
            if ($this->isEmptyRow($row) || $this->isTotalRow($row)) {
                continue;
            }

            $description = $this->cell($row, $header['description']);
            $quantityRaw = $this->cell($row, $header['quantity']);
            $unitRaw = $this->cell($row, $header['unit']);
            $group = $header['group'] !== null ? $this->cell($row, $header['group']) : '';
            $bnrCell = $header['bnr'] !== null ? $this->cell($row, $header['bnr']) : '';
            $rowNumber = $index + 1;

            if ($description === '' && $quantityRaw === '' && $unitRaw === '') {
                continue;
            }

            if ($description === '') {
                $unrecognized[] = [
                    'row' => $rowNumber,
                    'description' => $quantityRaw !== '' ? $quantityRaw : $unitRaw,
                    'reason' => 'Geen omschrijving',
                ];

                continue;
            }

            if ($this->isHoursUnit($unitRaw)) {
                $skippedLabor++;

                continue;
            }

            if (! $this->isPiecesUnit($unitRaw)) {
                $unrecognized[] = [
                    'row' => $rowNumber,
                    'description' => $description,
                    'reason' => $unitRaw === '' ? 'Geen eenheid' : 'Eenheid "'.$unitRaw.'" wordt niet als stuks ingelezen',
                ];

                continue;
            }

            $quantity = DutchNumber::parse($quantityRaw);
            if ($quantity === null || $quantity <= 0) {
                $unrecognized[] = [
                    'row' => $rowNumber,
                    'description' => $description,
                    'reason' => 'Geen geldig aantal',
                ];

                continue;
            }

            $bnr = $this->extractBnr($bnrCell, $group, $description);
            $normalizedDescription = $this->normalizeDescription($description);
            $key = mb_strtolower(($bnr ?? '').'|'.$normalizedDescription.'|'.WorkUnit::Pieces->value);

            if (! isset($merged[$key])) {
                $merged[$key] = [
                    'description' => $normalizedDescription,
                    'bnr' => $bnr,
                    'group' => $group !== '' ? $group : null,
                    'quantity' => 0.0,
                    'unit' => WorkUnit::Pieces->value,
                    'source_rows' => 0,
                ];
            }

            $merged[$key]['quantity'] += $quantity;
            $merged[$key]['source_rows']++;
            $processedRows++;
        }

        $lines = array_values($merged);
        $totalPieces = 0.0;
        foreach ($lines as $line) {
            $totalPieces += $line['quantity'];
        }

        return [
            'matched' => true,
            'lines' => $lines,
            'unrecognized' => $unrecognized,
            'skipped_labor' => $skippedLabor,
            'processed_rows' => $processedRows,
            'total_pieces' => $totalPieces,
            'summary' => $this->summary($processedRows, $totalPieces, count($unrecognized)),
            'ready' => $processedRows > 0 && $unrecognized === [],
        ];
    }

    public function summary(int $processedRows, float $totalPieces, int $unrecognizedCount): string
    {
        return $processedRows.' Excelregels verwerkt · '.$this->formatPieces($totalPieces).' stuks · '.$unrecognizedCount.' niet herkend';
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array{index: int, description: int, quantity: int, unit: int, group: ?int, bnr: ?int}|null
     */
    private function findHeader(array $rows): ?array
    {
        $limit = min(20, count($rows));
        for ($index = 0; $index < $limit; $index++) {
            $map = $this->mapHeader($rows[$index]);
            if ($map === null) {
                continue;
            }

            return ['index' => $index, ...$map];
        }

        return null;
    }

    /**
     * @param  list<string>  $cells
     * @return array{description: int, quantity: int, unit: int, group: ?int, bnr: ?int}|null
     */
    private function mapHeader(array $cells): ?array
    {
        $description = null;
        $descriptionScore = -1;
        $quantity = null;
        $unit = null;
        $group = null;
        $bnr = null;
        $hasFloorMarkers = false;

        foreach ($cells as $column => $cell) {
            $header = $this->normalizeHeader((string) $cell);
            if ($header === '') {
                continue;
            }

            if (in_array($header, ['verdieping', 'bouwlaag', 'm2', 'm²'], true) || $header === 'nummer') {
                $hasFloorMarkers = true;
            }

            $score = $this->descriptionHeaderScore($header);
            if ($score > $descriptionScore) {
                $description = $column;
                $descriptionScore = $score;
            }

            if ($quantity === null && in_array($header, ['aantal', 'qty', 'quantity'], true)) {
                $quantity = $column;
            }

            if ($unit === null && in_array($header, ['eh', 'eenheid', 'unit', 'eenh'], true)) {
                $unit = $column;
            }

            if ($group === null && in_array($header, ['groep', 'group'], true)) {
                $group = $column;
            }

            if ($bnr === null && in_array($header, ['bnr', 'bouwnummer'], true)) {
                $bnr = $column;
            }
        }

        if ($description === null || $quantity === null || $unit === null || $descriptionScore < 1) {
            return null;
        }

        if ($hasFloorMarkers && $descriptionScore < 3) {
            return null;
        }

        return [
            'description' => $description,
            'quantity' => $quantity,
            'unit' => $unit,
            'group' => $group,
            'bnr' => $bnr,
        ];
    }

    private function descriptionHeaderScore(string $header): int
    {
        if (str_contains($header, 'productie') && str_contains($header, 'omschrijving')) {
            return 3;
        }
        if ($header === 'omschrijving' || str_ends_with($header, ' omschrijving')) {
            return 2;
        }
        if (str_contains($header, 'omschrijving')) {
            return 1;
        }

        return -1;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(["\n", "\r", "\t", '-', '_'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function normalizeDescription(string $value): string
    {
        $value = str_replace(["\n", "\r", "\t"], ' ', trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  list<string>  $row
     */
    private function cell(array $row, int $index): string
    {
        return $this->normalizeDescription((string) ($row[$index] ?? ''));
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

    /**
     * @param  list<string>  $row
     */
    private function isTotalRow(array $row): bool
    {
        $first = mb_strtolower(trim((string) ($row[0] ?? '')));

        return $first === 'totaal' || $first === 'total' || str_starts_with($first, 'totaal ');
    }

    private function isPiecesUnit(string $unit): bool
    {
        $unit = rtrim(mb_strtolower(trim($unit)), '.');

        return in_array($unit, ['st', 'stk', 'stuk', 'stuks', 'pcs', 'pc'], true);
    }

    private function isHoursUnit(string $unit): bool
    {
        $unit = rtrim(mb_strtolower(trim($unit)), '.');

        return in_array($unit, ['uur', 'uren', 'u', 'hour', 'hours', 'hrs'], true);
    }

    private function extractBnr(string $bnrCell, string $group, string $description): ?string
    {
        foreach ([$bnrCell, $group, $description] as $value) {
            if (preg_match('/BNR\s*(\d+)/iu', $value, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private function formatPieces(float $quantity): string
    {
        if (fmod($quantity, 1.0) === 0.0) {
            return (string) (int) $quantity;
        }

        return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',');
    }

    /**
     * @return array{
     *     matched: bool,
     *     lines: list<array{
     *         description: string,
     *         bnr: ?string,
     *         group: ?string,
     *         quantity: float,
     *         unit: string,
     *         source_rows: int
     *     }>,
     *     unrecognized: list<array{row: int, description: string, reason: string}>,
     *     skipped_labor: int,
     *     processed_rows: int,
     *     total_pieces: float,
     *     summary: string,
     *     ready: bool
     * }
     */
    private function emptyResult(bool $matched): array
    {
        return [
            'matched' => $matched,
            'lines' => [],
            'unrecognized' => [],
            'skipped_labor' => 0,
            'processed_rows' => 0,
            'total_pieces' => 0.0,
            'summary' => $this->summary(0, 0, 0),
            'ready' => false,
        ];
    }
}
