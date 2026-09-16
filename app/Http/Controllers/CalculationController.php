<?php

namespace App\Http\Controllers;

use App\Enums\CalculationStatus;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\User;
use App\Services\QuoteCalculation\CalculationBoardService;
use App\Services\QuoteCalculation\CalculationExcelExporter;
use App\Services\QuoteCalculation\CalculationRoomRows;
use App\Services\QuoteCalculation\CalculationStoreService;
use App\Services\QuoteCalculation\CalculationTotals;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalculationController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Calculation::class);

        $calculations = Calculation::query()
            ->withCount('lines')
            ->withCount('drawings')
            ->orderByDesc('dated_on')
            ->orderByDesc('id')
            ->get();

        return view('calculations.index', ['calculations' => $calculations]);
    }

    public function create(): View
    {
        Gate::authorize('create', Calculation::class);

        return view('calculations.create', [
            'maxFileMegabytes' => (int) (config('filesystems.project_file_max_kilobytes') / 1024),
        ]);
    }

    public function store(Request $request, CalculationStoreService $store): RedirectResponse
    {
        Gate::authorize('create', Calculation::class);

        $maxKilobytes = (int) config('filesystems.project_file_max_kilobytes');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'dated_on' => ['required', 'date'],
            'drawings' => ['required_without:workbooks', 'array'],
            'drawings.*' => ['file', 'max:'.$maxKilobytes, 'mimes:pdf', 'extensions:pdf'],
            'workbooks' => ['required_without:drawings', 'array'],
            'workbooks.*' => ['file', 'max:'.$maxKilobytes, 'mimes:xlsx,xlsm,xls,csv,txt', 'extensions:xlsx,xlsm,xls,csv,txt'],
        ], [
            'name.required' => 'Vul een naam voor de calculatie in.',
            'dated_on.required' => 'Vul een datum in.',
            'drawings.required_without' => 'Upload minstens één PDF-tekening of een Excelbestand.',
            'workbooks.required_without' => 'Upload minstens één PDF-tekening of een Excelbestand.',
            'drawings.*.mimes' => 'Gebruik PDF-tekeningen.',
            'drawings.*.extensions' => 'Gebruik PDF-tekeningen.',
            'workbooks.*.mimes' => 'Gebruik Excel of CSV.',
            'workbooks.*.extensions' => 'Gebruik Excel of CSV.',
            'drawings.*.max' => 'Dit bestand is te groot. Gebruik een bestand van maximaal 100 MB.',
            'workbooks.*.max' => 'Dit bestand is te groot. Gebruik een bestand van maximaal 100 MB.',
        ]);

        $files = array_values(array_filter(
            Arr::wrap($request->file('drawings')),
            fn ($file) => $file instanceof UploadedFile,
        ));
        $workbooks = array_values(array_filter(
            Arr::wrap($request->file('workbooks')),
            fn ($file) => $file instanceof UploadedFile,
        ));

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $calculation = $store->create($validated, $files, $user, $workbooks);

        if ($calculation->importIsFinished()) {
            return redirect()
                ->route('calculations.imported', $calculation)
                ->with('status', 'Bestanden uitgelezen.');
        }

        return redirect()->route('calculations.processing', $calculation);
    }

    public function show(Calculation $calculation, CalculationTotals $totals, CalculationRoomRows $rooms): View|RedirectResponse
    {
        Gate::authorize('view', $calculation);
        if ($calculation->isImporting()) {
            return redirect()->route('calculations.processing', $calculation);
        }

        $calculation->load(['lines', 'drawings', 'workbooks']);
        $table = $rooms->table($calculation->lines, $this->drawingLegend($calculation));

        return view('calculations.show', [
            'calculation' => $calculation,
            'totals' => $totals->grouped($calculation->lines),
            'roomRows' => $table['rows'],
            'roomCount' => $table['room_count'],
            'certainCount' => $table['certain_count'],
            'generousCount' => $table['generous_count'],
            'estimatedCount' => $table['estimated_count'],
            'reviewCount' => $table['blocking_count'],
            'linkedCount' => $table['linked_count'],
            'plinthLinkedCount' => $table['plinth_linked_count'],
            'plinthMetersCount' => $table['plinth_meters_count'],
            'plinthNetCount' => $table['plinth_net_count'],
            'plinthGenerousCount' => $table['plinth_generous_count'],
            'plinthEstimatedCount' => $table['plinth_estimated_count'],
            'plinthMissingMetersCount' => $table['plinth_missing_meters_count'],
            'readyForExcel' => $table['ready_for_excel'],
            'squareMeters' => $table['square_meters'],
            'statuses' => CalculationStatus::cases(),
        ]);
    }

    public function board(Request $request, Calculation $calculation, CalculationBoardService $board): View|RedirectResponse
    {
        Gate::authorize('view', $calculation);
        if ($calculation->isImporting()) {
            return redirect()->route('calculations.processing', $calculation);
        }

        $selected = trim((string) $request->query('room', ''));

        return view('calculations.board', [
            'calculation' => $calculation,
            'board' => $board->payload(
                $calculation,
                $selected !== '' ? $selected : null,
                $request->user()?->can('update', $calculation) ?? false,
            ),
        ]);
    }

    public function totals(Calculation $calculation, CalculationTotals $totals, CalculationRoomRows $rooms): View|RedirectResponse
    {
        Gate::authorize('view', $calculation);
        if ($calculation->isImporting()) {
            return redirect()->route('calculations.processing', $calculation);
        }

        $calculation->load('lines');
        $table = $rooms->table($calculation->lines);

        return view('calculations.totals', [
            'calculation' => $calculation,
            'totals' => $totals->grouped($calculation->lines),
            'squareMeters' => $table['square_meters'],
            'readyForExcel' => $table['ready_for_excel'],
            'roomCount' => $table['room_count'],
            'reviewCount' => $table['blocking_count'],
        ]);
    }

    public function files(Calculation $calculation): View|RedirectResponse
    {
        Gate::authorize('view', $calculation);
        if ($calculation->isImporting()) {
            return redirect()->route('calculations.processing', $calculation);
        }

        $calculation->load(['drawings', 'workbooks']);

        return view('calculations.files', [
            'calculation' => $calculation,
        ]);
    }

    public function imported(Calculation $calculation, CalculationRoomRows $rooms): View|RedirectResponse
    {
        Gate::authorize('view', $calculation);
        if ($calculation->isImporting()) {
            return redirect()->route('calculations.processing', $calculation);
        }

        $calculation->load(['drawings', 'workbooks', 'lines']);
        $table = $rooms->table($calculation->lines);

        return view('calculations.imported', [
            'calculation' => $calculation,
            'drawingCount' => $calculation->drawings->count(),
            'workbookCount' => $calculation->workbooks->count(),
            'roomCount' => $table['room_count'],
            'floorLinkedCount' => $table['floor_linked_count'],
            'plinthLinkedCount' => $table['plinth_linked_count'],
            'plinthMetersCount' => $table['plinth_meters_count'],
            'plinthNetCount' => $table['plinth_net_count'],
            'plinthGenerousCount' => $table['plinth_generous_count'],
            'plinthEstimatedCount' => $table['plinth_estimated_count'],
            'plinthMissingMetersCount' => $table['plinth_missing_meters_count'],
            'excelConfirmedCount' => $table['excel_confirmed_count'],
            'reviewCount' => $table['blocking_count'],
            'estimatedCount' => $table['estimated_count'],
            'generousCount' => $table['generous_count'],
            'warnings' => array_values(array_filter(
                $calculation->warnings ?? [],
                fn (string $warning): bool => ! str_contains($warning, 'Afwijking Excel'),
            )),
            'excelNotices' => array_values(array_filter(
                $calculation->warnings ?? [],
                fn (string $warning): bool => str_contains($warning, 'Afwijking Excel'),
            )),
            'needsMapping' => $calculation->workbooks->contains(fn ($workbook) => $workbook->status === 'pending'),
        ]);
    }

    public function processing(Calculation $calculation, CalculationStoreService $store): View|RedirectResponse
    {
        Gate::authorize('view', $calculation);
        if ($calculation->importIsFinished()) {
            return redirect()->route('calculations.imported', $calculation);
        }

        return view('calculations.processing', [
            'calculation' => $calculation,
            'progress' => $store->importProgress($calculation),
        ]);
    }

    public function importStatus(Calculation $calculation, CalculationStoreService $store): JsonResponse
    {
        Gate::authorize('view', $calculation);

        return response()->json($store->importProgress($calculation))
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Calculation $calculation, CalculationStoreService $store): RedirectResponse
    {
        Gate::authorize('update', $calculation);

        $incoming = $request->input('lines', []);
        if (is_array($incoming)) {
            foreach ($incoming as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $incoming[$index]['quantity'] = Format::decimalInput($line['quantity'] ?? null);
                if ($incoming[$index]['quantity'] === '') {
                    $incoming[$index]['quantity'] = null;
                }
            }
            $request->merge(['lines' => $incoming]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'dated_on' => ['required', 'date'],
            'status' => ['required', Rule::enum(CalculationStatus::class)],
            'lines' => ['nullable', 'array'],
            'lines.*.id' => ['required', 'integer', Rule::exists('calculation_lines', 'id')->where('calculation_id', $calculation->id)],
            'lines.*.room_number' => ['nullable', 'string', 'max:50'],
            'lines.*.room_name' => ['nullable', 'string', 'max:255'],
            'lines.*.product_code' => ['nullable', 'string', 'max:50'],
            'lines.*.product' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'numeric'],
            'lines.*.unit' => ['required', Rule::enum(WorkUnit::class)],
            'lines.*.source' => ['required', Rule::enum(QuantitySource::class)],
            'lines.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $store->update($calculation, [
            'name' => $validated['name'],
            'client_name' => $validated['client_name'] ?? null,
            'project_name' => $validated['project_name'] ?? null,
            'dated_on' => $validated['dated_on'],
            'status' => $validated['status'],
        ], $validated['lines'] ?? []);

        return redirect()
            ->route('calculations.show', $calculation)
            ->with('status', 'Controle opgeslagen. Totalen zijn bijgewerkt.');
    }

    public function destroy(Calculation $calculation, CalculationStoreService $store): RedirectResponse
    {
        Gate::authorize('delete', $calculation);

        $store->delete($calculation);

        return redirect()
            ->route('calculations.index')
            ->with('status', 'Calculatie verwijderd.');
    }

    public function excel(Calculation $calculation, CalculationExcelExporter $exporter): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view', $calculation);

        $calculation->load('lines');
        if ($calculation->lines->isEmpty()) {
            return redirect()
                ->route('calculations.show', $calculation)
                ->withErrors(['excel' => 'Nog geen regels om te exporteren.']);
        }

        return $exporter->download($calculation);
    }

    public function drawing(Calculation $calculation, CalculationDrawing $drawing): StreamedResponse
    {
        Gate::authorize('view', $calculation);
        abort_unless($drawing->calculation_id === $calculation->id, 404);
        abort_unless($drawing->existsOnDisk(), 404);

        return Storage::disk('local')->response(
            $drawing->file_path,
            $drawing->original_filename,
            ['Content-Type' => $drawing->mime_type ?: 'application/pdf'],
        );
    }

    /**
     * @return list<array{code?: string, product?: string}>
     */
    private function drawingLegend(Calculation $calculation): array
    {
        return $calculation->drawings
            ->flatMap(fn (CalculationDrawing $drawing): array => is_array($drawing->legend) ? $drawing->legend : [])
            ->values()
            ->all();
    }
}
