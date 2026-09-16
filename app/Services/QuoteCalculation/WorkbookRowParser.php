<?php

namespace App\Services\QuoteCalculation;

use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Support\DutchNumber;

class WorkbookRowParser
{
    public function __construct(private WorkbookColumnGuesser $guesser = new WorkbookColumnGuesser) {}

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, mixed>  $sheetMapping
     * @return list<array<string, mixed>>
     */
    public function parse(array $rows, array $sheetMapping): array
    {
        if (($sheetMapping['skip'] ?? false) === true) {
            return [];
        }

        $groups = $sheetMapping['groups'] ?? [];
        if ($groups === [] && isset($sheetMapping['columns']) && is_array($sheetMapping['columns'])) {
            $groups = [[
                'columns' => $sheetMapping['columns'],
                'header_row' => $sheetMapping['header_row'] ?? null,
                'product_hint' => $sheetMapping['product_hint'] ?? null,
            ]];
        }

        $lines = [];
        foreach ($groups as $group) {
            $columns = $this->intColumns($group['columns'] ?? []);
            if ($columns === [] || ! isset($columns['room_number'])) {
                continue;
            }
            $headerRow = $this->optionalInt($group['header_row'] ?? $sheetMapping['header_row'] ?? null);
            $hint = $this->optionalString($group['product_hint'] ?? $sheetMapping['product_hint'] ?? null);
            $headers = $this->headerNames($rows, $headerRow, $columns);
            $start = $headerRow === null ? 0 : $headerRow + 1;
            for ($index = $start, $count = count($rows); $index < $count; $index++) {
                $parsed = $this->row($rows[$index], $columns, $hint, $index, $headers);
                if ($parsed === null) {
                    continue;
                }
                $lines[] = $parsed;
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $columns
     * @param  array<int, string>  $headers
     * @return array<string, mixed>|null
     */
    private function row(array $row, array $columns, ?string $hint, int $rowIndex, array $headers): ?array
    {
        $number = $this->cell($row, $columns['room_number'] ?? null);
        if ($number === '' || ! $this->guesser->looksLikeRoomNumber($number)) {
            return null;
        }
        if (preg_match('/^totaal\b/iu', $number)) {
            return null;
        }

        $name = $this->cell($row, $columns['room_name'] ?? null);
        $building = $this->cell($row, $columns['building'] ?? null);
        $level = $this->cell($row, $columns['floor_level'] ?? null);
        $floorFinish = $this->cell($row, $columns['floor_finish'] ?? null);
        $plinthFinish = $this->cell($row, $columns['plinth_finish'] ?? null);
        $product = $this->cell($row, $columns['product'] ?? null);
        $quantityIndex = $columns['quantity'] ?? null;
        $quantity = $quantityIndex === null ? null : DutchNumber::fromMachine($this->cell($row, $quantityIndex));
        $unitRaw = $this->cell($row, $columns['unit'] ?? null);

        $floorCode = $this->finishCode($floorFinish) ?? $this->finishCode($product) ?? $this->finishCode($hint);
        $plinthCode = $this->finishCode($plinthFinish, 'pl');
        $floorProduct = $this->productText($floorFinish, $product, $hint, $floorCode);
        $plinthProduct = $this->productText($plinthFinish, null, null, $plinthCode);
        $unit = $this->unit($unitRaw, $plinthCode !== null && $floorCode === null);

        $noteParts = array_values(array_filter([$building !== '' ? 'Gebouw '.$building : null, $level !== '' ? 'Verdieping '.$level : null]));

        return [
            'room_number' => $number,
            'room_name' => $name !== '' ? $name : null,
            'floor_code' => $floorCode,
            'floor_product' => $floorProduct,
            'plinth_code' => $plinthCode,
            'plinth_product' => $plinthProduct,
            'quantity' => $quantity,
            'quantity_source' => $quantity === null ? null : $this->quantitySource($quantityIndex, $headers, $rowIndex),
            'unit' => $unit,
            'source' => QuantitySource::FromExcel,
            'note' => $noteParts === [] ? null : implode(' · ', $noteParts),
        ];
    }

    /**
     * @param  array<string, int>  $columns
     * @param  list<list<string>>  $rows
     * @return array<int, string>
     */
    private function headerNames(array $rows, ?int $headerRow, array $columns): array
    {
        $names = [];
        foreach ($columns as $index) {
            $header = $headerRow === null ? '' : $this->cell($rows[$headerRow] ?? [], $index);
            $names[$index] = $header !== '' ? $header : 'Kolom '.$this->guesser->columnLetter($index);
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function quantitySource(?int $index, array $headers, int $rowIndex): ?string
    {
        if ($index === null) {
            return null;
        }

        $letter = $this->guesser->columnLetter($index);
        $header = trim($headers[$index] ?? '');
        $column = $header === '' || preg_match('/^Kolom\s+/u', $header) === 1 ? $letter : $header;

        return 'kolom '.$column.', cel '.$letter.($rowIndex + 1);
    }

    private function finishCode(?string $value, string $kind = 'v'): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $pattern = $kind === 'pl'
            ? '/(?<![a-z0-9])(pl\d{2}(?:\.[a-z0-9]+)?)(?![a-z0-9])/iu'
            : '/(?<![a-z0-9])((?:v|pl)\d{2}(?:\.[a-z0-9]+)?)(?![a-z0-9])/iu';
        if (! preg_match($pattern, $value, $match)) {
            return null;
        }

        return mb_strtolower($match[1]);
    }

    private function productText(?string $finish, ?string $product, ?string $hint, ?string $code): ?string
    {
        foreach ([$product, $finish, $hint] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $code;
    }

    private function unit(string $raw, bool $preferLinear): WorkUnit
    {
        $normalized = mb_strtolower(trim(str_replace(['²', '¹'], ['2', '1'], $raw)));
        if (in_array($normalized, ['m1', 'lm', 'm¹'], true) || str_contains($normalized, 'plint')) {
            return WorkUnit::LinearMeter;
        }
        if (in_array($normalized, ['m2', 'm²'], true)) {
            return WorkUnit::SquareMeter;
        }

        return $preferLinear ? WorkUnit::LinearMeter : WorkUnit::SquareMeter;
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array<string, int>
     */
    private function intColumns(array $columns): array
    {
        $mapped = [];
        foreach ($columns as $role => $index) {
            if ($index === null || $index === '' || $role === 'skip') {
                continue;
            }
            $mapped[(string) $role] = (int) $index;
        }

        return $mapped;
    }

    /**
     * @param  list<string>  $row
     */
    private function cell(array $row, ?int $index): string
    {
        if ($index === null) {
            return '';
        }

        return trim((string) ($row[$index] ?? ''));
    }

    private function optionalInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
