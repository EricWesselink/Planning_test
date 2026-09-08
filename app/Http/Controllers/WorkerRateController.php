<?php

namespace App\Http\Controllers;

use App\Enums\FlooringSpecialty;
use App\Enums\WorkUnit;
use App\Models\Worker;
use App\Models\WorkerRate;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkerRateController extends Controller
{
    public function store(Request $request, Worker $worker): RedirectResponse
    {
        Gate::authorize('update', $worker);
        $this->normalizeDecimals($request);

        $data = $request->validate([
            'rates' => ['present', 'array'],
            'rates.*.specialty' => ['required', 'string', 'max:64', 'not_regex:/[,\r\n]/'],
            'rates.*.unit' => ['required', Rule::enum(WorkUnit::class)],
            'rates.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'rates.*.specialty.required' => 'Kies een onderdeel.',
            'rates.*.unit.required' => 'Kies een eenheid.',
            'rates.*.unit_price.min' => 'Een prijs kan niet lager zijn dan 0.',
        ]);

        $rows = [];
        foreach ($data['rates'] as $rate) {
            if (! filled($rate['unit_price'] ?? null)) {
                continue;
            }

            $case = FlooringSpecialty::caseFrom($rate['specialty']);
            $specialty = $case instanceof FlooringSpecialty ? $case->value : trim((string) $rate['specialty']);
            $unit = mb_strtolower($specialty) === WorkerRate::HOURLY_SPECIALTY
                ? WorkUnit::Hours->value
                : $rate['unit'];
            $key = mb_strtolower($specialty).'|'.$unit;
            $rows[$key] = [
                'specialty' => $specialty,
                'unit' => $unit,
                'unit_price' => round((float) $rate['unit_price'], 2),
            ];
        }

        DB::transaction(function () use ($worker, $rows): void {
            $worker->rates()->delete();
            foreach ($rows as $row) {
                $worker->rates()->create($row);
            }
        });

        return redirect()
            ->route('workers.show', $worker)
            ->with('status', 'Afgesproken prijzen opgeslagen.');
    }

    private function normalizeDecimals(Request $request): void
    {
        $rates = $request->input('rates', []);
        if (! is_array($rates)) {
            return;
        }

        foreach ($rates as $index => $rate) {
            if (is_array($rate) && array_key_exists('unit_price', $rate)) {
                $rates[$index]['unit_price'] = Format::decimalInput($rate['unit_price']);
            }
        }

        $request->merge(['rates' => $rates]);
    }
}
