<?php

namespace App\Http\Controllers;

use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Services\QuoteCalculation\CalculationBoardService;
use App\Services\QuoteCalculation\CalculationRoomRows;
use App\Services\QuoteCalculation\CalculationStoreService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CalculationLineController extends Controller
{
    public function store(Calculation $calculation, CalculationStoreService $store): RedirectResponse
    {
        Gate::authorize('update', $calculation);

        $store->addManualLine($calculation);

        return redirect()
            ->route('calculations.show', $calculation)
            ->with('status', 'Handmatige regel toegevoegd.');
    }

    public function destroy(Calculation $calculation, CalculationLine $line): RedirectResponse
    {
        Gate::authorize('update', $calculation);
        abort_unless($line->calculation_id === $calculation->id, 404);

        $line->delete();

        return redirect()
            ->route('calculations.show', $calculation)
            ->with('status', 'Regel verwijderd.');
    }

    public function confirm(Calculation $calculation, CalculationLine $line, CalculationStoreService $store, CalculationRoomRows $rows): RedirectResponse
    {
        Gate::authorize('update', $calculation);
        abort_unless($line->calculation_id === $calculation->id, 404);

        if (! $store->confirmRoom($calculation, $line, $rows)) {
            return redirect()
                ->route('calculations.show', $calculation)
                ->withErrors(['confirm' => 'Vul ontbrekende waarden eerst in voordat je bevestigt.']);
        }

        return redirect()
            ->route('calculations.show', $calculation)
            ->with('status', 'Regel bevestigd.');
    }

    public function confirmComplete(Calculation $calculation, CalculationStoreService $store, CalculationRoomRows $rows): RedirectResponse
    {
        Gate::authorize('update', $calculation);

        $count = $store->confirmCompleteRooms($calculation, $rows);

        return redirect()
            ->route('calculations.show', $calculation)
            ->with('status', $count === 0
                ? 'Geen volledige regels om te bevestigen. Vul ontbrekende waarden eerst in.'
                : $count.' volledige regel(s) bevestigd.');
    }

    public function updateBoardRoom(
        Request $request,
        Calculation $calculation,
        CalculationLine $line,
        CalculationStoreService $store,
        CalculationRoomRows $rows,
        CalculationBoardService $board,
    ): JsonResponse {
        Gate::authorize('update', $calculation);
        abort_unless($line->calculation_id === $calculation->id, 404);

        foreach (['floor_quantity', 'plinth_quantity'] as $field) {
            if ($request->exists($field)) {
                $request->merge([$field => Format::decimalInput($request->input($field))]);
            }
        }
        $incomingFloors = $request->input('floors', []);
        if (is_array($incomingFloors)) {
            foreach ($incomingFloors as $index => $finish) {
                if (! is_array($finish) || ! array_key_exists('quantity', $finish)) {
                    continue;
                }
                $incomingFloors[$index]['quantity'] = Format::decimalInput($finish['quantity']);
                if ($incomingFloors[$index]['quantity'] === '') {
                    $incomingFloors[$index]['quantity'] = null;
                }
            }
            $request->merge(['floors' => $incomingFloors]);
        }

        $validated = $request->validate([
            'room_number' => ['nullable', 'string', 'max:50'],
            'room_name' => ['nullable', 'string', 'max:255'],
            'floor_code' => ['nullable', 'string', 'max:50'],
            'floor_product' => ['nullable', 'string', 'max:255'],
            'floor_quantity' => ['nullable', 'numeric'],
            'plinth_code' => ['nullable', 'string', 'max:50'],
            'plinth_product' => ['nullable', 'string', 'max:255'],
            'plinth_quantity' => ['nullable', 'numeric'],
            'floors' => ['nullable', 'array'],
            'floors.*.id' => ['nullable', 'integer', 'exists:calculation_lines,id'],
            'floors.*.code' => ['nullable', 'string', 'max:50'],
            'floors.*.product' => ['nullable', 'string', 'max:255'],
            'floors.*.quantity' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:1000'],
            'chip' => ['nullable', 'array'],
            'chip.x' => ['required_with:chip', 'numeric', 'min:0', 'max:1'],
            'chip.y' => ['required_with:chip', 'numeric', 'min:0', 'max:1'],
            'chip.page' => ['nullable', 'integer', 'min:1'],
            'chip.finish_id' => ['nullable', 'integer'],
            'chip_reset' => ['sometimes', 'boolean'],
            'restore_automatic' => ['sometimes', 'boolean'],
        ]);

        foreach (['floor_quantity', 'plinth_quantity'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] === '') {
                $validated[$field] = null;
            }
        }

        $store->updateBoardRoom($calculation, $line, $validated, $rows);

        return $this->boardRoomJson($calculation, $line, $board);
    }

    public function storeBoardRoom(
        Request $request,
        Calculation $calculation,
        CalculationStoreService $store,
        CalculationRoomRows $rows,
        CalculationBoardService $board,
    ): JsonResponse {
        Gate::authorize('update', $calculation);

        if ($request->exists('floor_quantity')) {
            $request->merge(['floor_quantity' => Format::decimalInput($request->input('floor_quantity'))]);
        }

        $validated = $request->validate([
            'document_id' => ['required', 'integer', 'exists:calculation_drawings,id'],
            'page' => ['required', 'integer', 'min:1'],
            'room_number' => ['required_without:room_name', 'nullable', 'string', 'max:50'],
            'room_name' => ['required_without:room_number', 'nullable', 'string', 'max:255'],
            'floor_code' => ['required', 'string', 'max:50'],
            'floor_product' => ['nullable', 'string', 'max:255'],
            'floor_quantity' => ['required', 'numeric'],
            'chip' => ['required', 'array'],
            'chip.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'chip.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'chip.page' => ['nullable', 'integer', 'min:1'],
        ], [
            'floor_code.required' => 'Kies of vul een materiaalcode in.',
            'floor_quantity.required' => 'Vul de oppervlakte in m² in.',
            'room_number.required_without' => 'Vul een ruimtenummer of ruimtenaam in.',
            'room_name.required_without' => 'Vul een ruimtenummer of ruimtenaam in.',
        ]);

        $drawing = CalculationDrawing::query()->findOrFail((int) $validated['document_id']);
        abort_unless((int) $drawing->calculation_id === (int) $calculation->id, 404);

        if (array_key_exists('floor_quantity', $validated) && $validated['floor_quantity'] === '') {
            $validated['floor_quantity'] = null;
        }

        $line = $store->createBoardRoom($calculation, $validated, $rows);

        return $this->boardRoomJson($calculation, $line, $board);
    }

    public function confirmBoardRoom(
        Calculation $calculation,
        CalculationLine $line,
        CalculationStoreService $store,
        CalculationRoomRows $rows,
        CalculationBoardService $board,
    ): JsonResponse {
        Gate::authorize('update', $calculation);
        abort_unless($line->calculation_id === $calculation->id, 404);

        if (! $store->confirmRoom($calculation, $line, $rows)) {
            return response()->json([
                'ok' => false,
                'message' => 'Vul ontbrekende waarden eerst in voordat je bevestigt.',
            ], 422);
        }

        return $this->boardRoomJson($calculation, $line, $board);
    }

    private function boardRoomJson(
        Calculation $calculation,
        CalculationLine $line,
        CalculationBoardService $board,
    ): JsonResponse {
        $fresh = $calculation->fresh(['lines.drawing', 'lines.workbook', 'drawings', 'workbooks']) ?? $calculation;
        $anchor = $fresh->lines->firstWhere('id', $line->id) ?? $line;
        $updated = $board->roomUpdateResponse($fresh, $anchor);

        return response()->json([
            'ok' => true,
            'room' => $updated['room'],
            'materials' => $updated['materials'],
        ]);
    }
}
