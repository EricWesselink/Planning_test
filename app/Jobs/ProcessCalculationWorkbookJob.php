<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\CalculationWorkbook;
use App\Services\QuoteCalculation\CalculationStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCalculationWorkbookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public int $workbookId) {}

    public function handle(CalculationStoreService $store): void
    {
        $workbook = CalculationWorkbook::query()->find($this->workbookId);
        if (! $workbook instanceof CalculationWorkbook) {
            return;
        }

        $store->processWorkbook($workbook);
        $store->continueImport($workbook->calculation_id);
    }

    public function failed(?Throwable $exception): void
    {
        $workbook = CalculationWorkbook::query()->find($this->workbookId);
        if (! $workbook instanceof CalculationWorkbook) {
            return;
        }

        if ($workbook->import_status !== ImportStatus::Ready) {
            $workbook->update([
                'import_status' => ImportStatus::Failed,
                'import_error' => $exception?->getMessage() ?: 'Excelbestand kon niet worden uitgelezen.',
            ]);
        }

        app(CalculationStoreService::class)->continueImport($workbook->calculation_id);
    }
}
