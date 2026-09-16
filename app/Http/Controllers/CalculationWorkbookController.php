<?php

namespace App\Http\Controllers;

use App\Models\Calculation;
use App\Models\CalculationWorkbook;
use App\Services\QuoteCalculation\CalculationStoreService;
use App\Services\QuoteCalculation\WorkbookAutoApplyService;
use App\Services\QuoteCalculation\WorkbookColumnGuesser;
use App\Services\QuoteCalculation\WorkbookMergeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalculationWorkbookController extends Controller
{
    public function edit(Calculation $calculation, WorkbookColumnGuesser $guesser): View
    {
        Gate::authorize('update', $calculation);
        $calculation->load('workbooks');

        return view('calculations.mapping', [
            'calculation' => $calculation,
            'guesser' => $guesser,
            'roles' => WorkbookColumnGuesser::ROLES,
        ]);
    }

    public function update(Request $request, Calculation $calculation, WorkbookMergeService $merge, WorkbookAutoApplyService $autoApply): RedirectResponse
    {
        Gate::authorize('update', $calculation);
        $calculation->load('workbooks');

        $payload = $request->validate([
            'workbooks' => ['required', 'array'],
            'workbooks.*.skip' => ['nullable', 'boolean'],
            'workbooks.*.sheets' => ['nullable', 'array'],
            'workbooks.*.sheets.*.name' => ['required', 'string', 'max:255'],
            'workbooks.*.sheets.*.skip' => ['nullable', 'boolean'],
            'workbooks.*.sheets.*.header_row' => ['nullable', 'integer', 'min:0'],
            'workbooks.*.sheets.*.product_hint' => ['nullable', 'string', 'max:255'],
            'workbooks.*.sheets.*.groups' => ['nullable', 'array'],
            'workbooks.*.sheets.*.groups.*.header_row' => ['nullable', 'integer', 'min:0'],
            'workbooks.*.sheets.*.groups.*.product_hint' => ['nullable', 'string', 'max:255'],
            'workbooks.*.sheets.*.groups.*.columns' => ['nullable', 'array'],
            'workbooks.*.sheets.*.groups.*.columns.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $applied = 0;
        foreach ($calculation->workbooks as $workbook) {
            if ($workbook->status !== 'pending') {
                continue;
            }
            $mapping = $payload['workbooks'][$workbook->id] ?? ['skip' => true];
            $mapping['skip'] = filter_var($mapping['skip'] ?? false, FILTER_VALIDATE_BOOLEAN);
            foreach ($mapping['sheets'] ?? [] as $index => $sheet) {
                $mapping['sheets'][$index]['skip'] = filter_var($sheet['skip'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
            $applied += $merge->apply($calculation, $workbook, $mapping);
            $autoApply->rememberFromAnalysis($workbook, $mapping);
        }

        return redirect()
            ->route('calculations.imported', $calculation)
            ->with('status', $applied === 0
                ? 'Excel overgeslagen. De calculatie gaat verder met de tekening.'
                : 'Excel gekoppeld. Controleer alleen de uitzonderingen.');
    }

    public function store(Request $request, Calculation $calculation, CalculationStoreService $store): RedirectResponse
    {
        Gate::authorize('update', $calculation);

        $maxKilobytes = (int) config('filesystems.project_file_max_kilobytes');
        $request->validate([
            'workbooks' => ['required', 'array', 'min:1'],
            'workbooks.*' => ['file', 'max:'.$maxKilobytes, 'mimes:xlsx,xlsm,xls,csv,txt', 'extensions:xlsx,xlsm,xls,csv,txt'],
        ], [
            'workbooks.required' => 'Kies minstens één Excelbestand.',
            'workbooks.*.mimes' => 'Gebruik Excel of CSV.',
            'workbooks.*.extensions' => 'Gebruik Excel of CSV.',
        ]);

        $files = array_values(array_filter(
            Arr::wrap($request->file('workbooks')),
            fn ($file) => $file instanceof UploadedFile,
        ));
        $store->attachWorkbooks($calculation, $files);

        return redirect()
            ->route('calculations.imported', $calculation)
            ->with('status', 'Excel uitgelezen.');
    }

    public function show(Calculation $calculation, CalculationWorkbook $workbook): StreamedResponse
    {
        Gate::authorize('view', $calculation);
        abort_unless($workbook->calculation_id === $calculation->id, 404);
        abort_unless($workbook->existsOnDisk(), 404);

        return Storage::disk('local')->response(
            $workbook->file_path,
            $workbook->original_filename,
            ['Content-Type' => $workbook->mime_type ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
