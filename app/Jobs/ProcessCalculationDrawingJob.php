<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\CalculationDrawing;
use App\Services\QuoteCalculation\CalculationStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessCalculationDrawingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 270;

    public bool $failOnTimeout = true;

    public function __construct(public int $drawingId) {}

    public function handle(CalculationStoreService $store): void
    {
        $drawing = CalculationDrawing::query()->find($this->drawingId);
        if (! $drawing instanceof CalculationDrawing) {
            return;
        }

        $store->processDrawing($drawing);
        $store->continueImport($drawing->calculation_id);
    }

    public function failed(?Throwable $exception): void
    {
        $drawing = CalculationDrawing::query()->find($this->drawingId);
        if (! $drawing instanceof CalculationDrawing) {
            return;
        }

        if ($drawing->import_status !== ImportStatus::Ready) {
            $drawing->update([
                'import_status' => ImportStatus::Failed,
                'import_error' => $exception?->getMessage() ?: 'Tekening kon niet worden uitgelezen.',
            ]);
        }

        app(CalculationStoreService::class)->continueImport($drawing->calculation_id);
    }
}
