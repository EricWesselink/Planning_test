<?php

namespace App\Services\QuoteCalculation;

use App\Models\Calculation;
use App\Models\CalculationWorkbook;
use App\Models\WorkbookColumnMemory;

class WorkbookAutoApplyService
{
    public function __construct(private WorkbookMergeService $merge = new WorkbookMergeService) {}

    public function applyPending(Calculation $calculation): void
    {
        $calculation->load('workbooks');
        foreach ($calculation->workbooks as $workbook) {
            if ($workbook->status !== 'pending') {
                continue;
            }
            $mapping = $this->mappingFromAnalysis($workbook);
            if (($mapping['auto'] ?? true) === false) {
                continue;
            }
            $this->merge->apply($calculation, $workbook, $mapping);
            $this->rememberFromAnalysis($workbook, $mapping);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function mappingFromAnalysis(CalculationWorkbook $workbook): array
    {
        $analysis = $workbook->analysis ?? [];
        if (($analysis['skippable'] ?? false) === true) {
            return ['skip' => true];
        }

        $sheets = [];
        $any = false;
        foreach ($analysis['sheets'] ?? [] as $sheet) {
            $name = (string) ($sheet['name'] ?? '');
            if ($name === '' || ($sheet['skippable'] ?? false) === true) {
                $sheets[] = ['name' => $name, 'skip' => true];

                continue;
            }

            $groups = [];
            foreach ($sheet['groups'] ?? [] as $group) {
                $columns = [];
                foreach ($group['columns'] ?? [] as $role => $index) {
                    if (($group['confidence'][$role] ?? 'review') !== 'certain') {
                        continue;
                    }
                    $columns[$role] = $index;
                }
                if (! isset($columns['room_number'])) {
                    continue;
                }
                $hint = $group['product_hint'] ?? $sheet['product_hint'] ?? null;
                if (! isset($columns['floor_finish']) && ! isset($columns['plinth_finish'])) {
                    if (! isset($columns['quantity']) || ! filled($hint)) {
                        continue;
                    }
                }
                $groups[] = [
                    'columns' => $columns,
                    'header_row' => $group['header_row'] ?? $sheet['header_row'] ?? null,
                    'product_hint' => $hint,
                ];
            }

            if ($groups === []) {
                $sheets[] = ['name' => $name, 'skip' => true];

                continue;
            }

            $any = true;
            $sheets[] = [
                'name' => $name,
                'skip' => false,
                'header_row' => $sheet['header_row'] ?? null,
                'product_hint' => $sheet['product_hint'] ?? null,
                'groups' => $groups,
            ];
        }

        if (! $any) {
            return ['skip' => false, 'auto' => false, 'sheets' => $sheets];
        }

        return ['skip' => false, 'sheets' => $sheets];
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    public function rememberFromAnalysis(CalculationWorkbook $workbook, array $mapping): void
    {
        if (($mapping['skip'] ?? false) === true) {
            return;
        }
        $used = [];
        foreach ($mapping['sheets'] ?? [] as $sheet) {
            if (($sheet['skip'] ?? false) === true) {
                continue;
            }
            foreach ($sheet['groups'] ?? [] as $group) {
                foreach (array_keys($group['columns'] ?? []) as $role) {
                    $used[$role] = true;
                }
            }
        }
        foreach ($workbook->analysis['labels'] ?? [] as $label) {
            $role = (string) ($label['role'] ?? '');
            $header = (string) ($label['header'] ?? '');
            if ($header === '' || ! isset($used[$role])) {
                continue;
            }
            WorkbookColumnMemory::remember($header, $role);
        }
    }
}
