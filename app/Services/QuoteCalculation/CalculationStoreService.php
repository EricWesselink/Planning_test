<?php

namespace App\Services\QuoteCalculation;

use App\Enums\CalculationStatus;
use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use App\Models\Calculation;
use App\Models\CalculationDrawing;
use App\Models\CalculationLine;
use App\Models\CalculationWorkbook;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CalculationStoreService
{
    public function __construct(
        private DrawingTakeoffParser $parser = new DrawingTakeoffParser,
        private WorkbookAnalyzer $workbookAnalyzer = new WorkbookAnalyzer,
        private WorkbookAutoApplyService $autoApply = new WorkbookAutoApplyService,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     client_name?: ?string,
     *     project_name?: ?string,
     *     dated_on: string,
     * }  $attributes
     * @param  list<UploadedFile>  $files
     * @param  list<UploadedFile>  $workbooks
     */
    public function create(array $attributes, array $files, User $user, array $workbooks = []): Calculation
    {
        return DB::transaction(function () use ($attributes, $files, $user, $workbooks): Calculation {
            $calculation = Calculation::query()->create([
                'name' => $attributes['name'],
                'client_name' => $attributes['client_name'] ?? null,
                'project_name' => $attributes['project_name'] ?? null,
                'dated_on' => $attributes['dated_on'],
                'status' => CalculationStatus::Concept,
                'created_by' => $user->id,
                'warnings' => [],
            ]);

            $warnings = [];
            $sort = 0;
            foreach ($files as $file) {
                $drawing = $this->storeDrawing($calculation, $file);
                try {
                    $parsed = $this->parser->parseFile(Storage::disk('local')->path($drawing->file_path));
                } catch (\InvalidArgumentException $e) {
                    $parsed = [
                        'engine' => null,
                        'handler' => null,
                        'lines' => [],
                        'legend' => [],
                        'warnings' => [$e->getMessage()],
                    ];
                }

                $drawing->update([
                    'parse_engine' => $parsed['engine'] ?? null,
                    'format_handler' => $parsed['handler'] ?? null,
                    'legend' => $parsed['legend'] ?? [],
                    'warnings' => $parsed['warnings'] ?? [],
                ]);

                foreach ($parsed['warnings'] ?? [] as $warning) {
                    $warnings[] = $drawing->original_filename.': '.$warning;
                }

                foreach ($parsed['lines'] ?? [] as $line) {
                    $sort++;
                    CalculationLine::query()->create([
                        'calculation_id' => $calculation->id,
                        'calculation_drawing_id' => $drawing->id,
                        'sort_order' => $sort,
                        'room_number' => $line['room_number'] ?? null,
                        'room_name' => $line['room_name'] ?? null,
                        'product_code' => $line['product_code'] ?? null,
                        'product' => $line['product'] ?? null,
                        'original_product_code' => $line['original_product_code'] ?? $line['product_code'] ?? null,
                        'original_product' => $line['original_product'] ?? $line['product'] ?? null,
                        'quantity' => $line['quantity'] ?? null,
                        'original_quantity' => $line['original_quantity'] ?? $line['quantity'] ?? null,
                        'unit' => $line['unit'] instanceof WorkUnit ? $line['unit']->value : ($line['unit'] ?? WorkUnit::SquareMeter->value),
                        'finish_role' => $line['finish_role'] ?? null,
                        'room_area' => $line['room_area'] ?? null,
                        'source' => $line['source'] instanceof QuantitySource ? $line['source']->value : ($line['source'] ?? QuantitySource::Review->value),
                        'found_source' => $line['found_source'] ?? (is_object($line['source'] ?? null) ? $line['source']->value : ($line['source'] ?? QuantitySource::Review->value)),
                        'note' => $line['note'] ?? null,
                        'calculation_trace' => $line['calculation_trace'] ?? null,
                    ]);
                }
            }

            $calculation->update(['warnings' => array_values(array_unique($warnings))]);
            $this->applySharedLegend($calculation);
            $this->attachWorkbooks($calculation, $workbooks);

            return $calculation->fresh(['lines', 'drawings', 'workbooks']) ?? $calculation;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function update(Calculation $calculation, array $attributes, array $lines): Calculation
    {
        return DB::transaction(function () use ($calculation, $attributes, $lines): Calculation {
            $calculation->update($attributes);

            $existing = $calculation->lines()->get()->keyBy('id');
            foreach ($lines as $payload) {
                $id = (int) ($payload['id'] ?? 0);
                $line = $existing->get($id);
                if (! $line instanceof CalculationLine) {
                    continue;
                }

                $next = [
                    'room_number' => $payload['room_number'] ?? null,
                    'room_name' => $payload['room_name'] ?? null,
                    'product_code' => $payload['product_code'] ?? null,
                    'product' => $payload['product'] ?? null,
                    'quantity' => $payload['quantity'] ?? null,
                    'unit' => $payload['unit'] ?? $line->unit?->value,
                    'note' => $payload['note'] ?? null,
                ];
                $source = QuantitySource::tryFrom((string) ($payload['source'] ?? $line->source?->value));
                if ($this->changed($line, $next)) {
                    $source = QuantitySource::Manual;
                    $next['confirmed_at'] = null;
                    $next['confirmed_manually'] = false;
                }
                $next['source'] = $source?->value ?? QuantitySource::Manual->value;
                $line->update($next);
            }

            return $calculation->fresh(['lines', 'drawings']) ?? $calculation;
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function updateBoardRoom(Calculation $calculation, CalculationLine $anchor, array $fields, CalculationRoomRows $rows): Calculation
    {
        $table = $rows->table($calculation->lines()->get());
        $match = null;
        foreach ($table['rows'] as $row) {
            $ids = $this->rowLineIds($row);
            if (in_array((int) $anchor->id, $ids, true)) {
                $match = $row;
                break;
            }
        }
        if ($match === null) {
            return $calculation;
        }

        $lines = [];
        $floors = $match['floors'] ?? array_values(array_filter([$match['floor'] ?? null]));
        $plinth = $match['plinth'] instanceof CalculationLine ? $match['plinth'] : null;
        $incomingFinishes = is_array($fields['floors'] ?? null) ? $fields['floors'] : [];
        $incomingById = [];
        foreach ($incomingFinishes as $finish) {
            if (is_array($finish) && isset($finish['id'])) {
                $incomingById[(int) $finish['id']] = $finish;
            }
        }
        foreach ($floors as $offset => $floor) {
            if (! $floor instanceof CalculationLine) {
                continue;
            }
            $finish = $incomingById[$floor->id] ?? [];
            $isMain = $offset === 0 || $floor->finish_role === FinishRole::Main;
            $lines[] = [
                'id' => $floor->id,
                'room_number' => $fields['room_number'] ?? $floor->room_number,
                'room_name' => $fields['room_name'] ?? $floor->room_name,
                'product_code' => $finish['code'] ?? ($isMain ? ($fields['floor_code'] ?? $floor->product_code) : $floor->product_code),
                'product' => $finish['product'] ?? ($isMain ? ($fields['floor_product'] ?? $floor->product) : $floor->product),
                'quantity' => array_key_exists('quantity', $finish)
                    ? $finish['quantity']
                    : ($isMain && array_key_exists('floor_quantity', $fields) ? $fields['floor_quantity'] : $floor->quantity),
                'unit' => $floor->unit?->value ?? WorkUnit::SquareMeter->value,
                'source' => $floor->source?->value ?? QuantitySource::Manual->value,
                'note' => $fields['note'] ?? $floor->note,
            ];
        }
        if ($plinth instanceof CalculationLine) {
            $lines[] = [
                'id' => $plinth->id,
                'room_number' => $fields['room_number'] ?? $plinth->room_number,
                'room_name' => $fields['room_name'] ?? $plinth->room_name,
                'product_code' => $fields['plinth_code'] ?? $plinth->product_code,
                'product' => $fields['plinth_product'] ?? $plinth->product,
                'quantity' => array_key_exists('plinth_quantity', $fields) ? $fields['plinth_quantity'] : $plinth->quantity,
                'unit' => $plinth->unit?->value ?? WorkUnit::LinearMeter->value,
                'source' => $plinth->source?->value ?? QuantitySource::Manual->value,
                'note' => $plinth->note,
            ];
        }

        return $this->update($calculation, [
            'name' => $calculation->name,
            'client_name' => $calculation->client_name,
            'project_name' => $calculation->project_name,
            'dated_on' => $calculation->dated_on,
            'status' => $calculation->status,
        ], $lines);
    }

    public function addManualLine(Calculation $calculation): CalculationLine
    {
        $sort = (int) $calculation->lines()->max('sort_order') + 1;

        return CalculationLine::query()->create([
            'calculation_id' => $calculation->id,
            'sort_order' => $sort,
            'unit' => WorkUnit::SquareMeter->value,
            'source' => QuantitySource::Manual->value,
            'found_source' => QuantitySource::Manual->value,
        ]);
    }

    public function confirmRoom(Calculation $calculation, CalculationLine $line, CalculationRoomRows $rows): bool
    {
        $table = $rows->table($calculation->lines()->get());
        $match = null;
        foreach ($table['rows'] as $row) {
            $ids = $this->rowLineIds($row);
            if (in_array((int) $line->id, $ids, true)) {
                $match = $row;
                break;
            }
        }
        if ($match === null || ! $match['can_confirm']) {
            return false;
        }

        $number = mb_strtolower(trim((string) $line->room_number));
        $query = $calculation->lines();
        if ($number === '') {
            $query->where('id', $line->id);
        } else {
            $query->where('room_number', $line->room_number)
                ->where('calculation_drawing_id', $line->calculation_drawing_id);
        }

        $query->update([
            'confirmed_at' => now(),
            'confirmed_manually' => true,
        ]);

        return true;
    }

    public function confirmCompleteRooms(Calculation $calculation, CalculationRoomRows $rows): int
    {
        $table = $rows->table($calculation->lines()->get());
        $count = 0;
        foreach ($table['rows'] as $row) {
            if (! $row['can_confirm']) {
                continue;
            }
            $line = $row['floor'] ?? $row['plinth'];
            if (! $line instanceof CalculationLine) {
                continue;
            }
            $this->confirmRoom($calculation, $line, $rows);
            $count++;
        }

        return $count;
    }

    /**
     * Re-read floor finishes from stored drawings. Plinth m¹ stays untouched.
     *
     * @return list<array<string, mixed>>
     */
    public function reparseFloorFinishes(Calculation $calculation): array
    {
        $changes = [];
        $calculation->load(['drawings', 'lines']);
        $sort = (int) $calculation->lines()->max('sort_order');

        foreach ($calculation->drawings as $drawing) {
            $path = Storage::disk('local')->path($drawing->file_path);
            if (! is_file($path)) {
                continue;
            }

            $parsed = $this->parser->parseFile($path);
            $drawing->update([
                'legend' => $parsed['legend'] ?? $drawing->legend,
                'warnings' => $parsed['warnings'] ?? $drawing->warnings,
            ]);

            $parsedByRoom = [];
            foreach ($parsed['lines'] ?? [] as $line) {
                if (! $this->isSquareMeterLine($line)) {
                    continue;
                }
                $number = mb_strtolower(trim((string) ($line['room_number'] ?? '')));
                if ($number === '') {
                    continue;
                }
                $parsedByRoom[$number][] = $line;
            }

            $existing = $calculation->lines()
                ->where('calculation_drawing_id', $drawing->id)
                ->where('unit', WorkUnit::SquareMeter)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (CalculationLine $line) => mb_strtolower(trim((string) $line->room_number)));

            foreach ($parsedByRoom as $number => $incoming) {
                $current = $existing->get($number, collect());
                if ($current->contains(fn (CalculationLine $line) => $line->source === QuantitySource::Manual)) {
                    continue;
                }

                $incomingMain = collect($incoming)->first(
                    fn (array $line) => ($line['finish_role'] ?? FinishRole::Main->value) === FinishRole::Main->value
                ) ?? $incoming[0];
                $incomingLocals = collect($incoming)->filter(
                    fn (array $line) => ($line['finish_role'] ?? '') === FinishRole::Local->value
                );

                $main = $current->first(fn (CalculationLine $line) => $line->finish_role === FinishRole::Main)
                    ?? $current->first();
                if ($main instanceof CalculationLine) {
                    $from = $main->product_code;
                    $to = $incomingMain['product_code'] ?? null;
                    $main->fill([
                        'product_code' => $to,
                        'product' => $incomingMain['product'] ?? $main->product,
                        'quantity' => array_key_exists('quantity', $incomingMain) ? $incomingMain['quantity'] : $main->quantity,
                        'finish_role' => FinishRole::Main->value,
                        'room_area' => $incomingMain['room_area'] ?? $main->room_area,
                        'source' => $incomingMain['source'] instanceof QuantitySource
                            ? $incomingMain['source']->value
                            : ($incomingMain['source'] ?? $main->source),
                        'note' => $incomingMain['note'] ?? $main->note,
                    ]);
                    if ($main->isDirty(['product_code', 'product', 'quantity'])) {
                        $changes[] = [
                            'room_number' => $main->room_number,
                            'room_name' => $main->room_name,
                            'from' => $from,
                            'to' => $to,
                            'drawing' => $drawing->original_filename,
                        ];
                    }
                    $main->save();
                }

                $existingLocals = $current->filter(fn (CalculationLine $line) => $line->id !== ($main->id ?? 0));
                $keep = [];
                foreach ($incomingLocals as $local) {
                    $code = mb_strtolower((string) ($local['product_code'] ?? ''));
                    $match = $existingLocals->first(
                        fn (CalculationLine $line) => mb_strtolower((string) $line->product_code) === $code
                    );
                    if ($match instanceof CalculationLine) {
                        $match->update([
                            'product' => $local['product'] ?? $match->product,
                            'quantity' => $local['quantity'] ?? $match->quantity,
                            'finish_role' => FinishRole::Local->value,
                            'room_area' => $local['room_area'] ?? $match->room_area,
                            'note' => $local['note'] ?? $match->note,
                            'calculation_trace' => $local['calculation_trace'] ?? $match->calculation_trace,
                            'source' => $local['source'] instanceof QuantitySource
                                ? $local['source']->value
                                : ($local['source'] ?? $match->source),
                        ]);
                        $keep[] = $match->id;

                        continue;
                    }

                    $sort++;
                    $created = CalculationLine::query()->create([
                        'calculation_id' => $calculation->id,
                        'calculation_drawing_id' => $drawing->id,
                        'sort_order' => $sort,
                        'room_number' => $local['room_number'] ?? $main?->room_number,
                        'room_name' => $local['room_name'] ?? $main?->room_name,
                        'product_code' => $local['product_code'] ?? null,
                        'product' => $local['product'] ?? null,
                        'original_product_code' => $local['original_product_code'] ?? $local['product_code'] ?? null,
                        'original_product' => $local['original_product'] ?? $local['product'] ?? null,
                        'quantity' => $local['quantity'] ?? null,
                        'original_quantity' => $local['original_quantity'] ?? $local['quantity'] ?? null,
                        'unit' => WorkUnit::SquareMeter->value,
                        'finish_role' => FinishRole::Local->value,
                        'room_area' => $local['room_area'] ?? $main?->room_area,
                        'source' => $local['source'] instanceof QuantitySource
                            ? $local['source']->value
                            : ($local['source'] ?? QuantitySource::Review->value),
                        'found_source' => $local['found_source'] ?? QuantitySource::Review->value,
                        'note' => $local['note'] ?? null,
                        'calculation_trace' => $local['calculation_trace'] ?? null,
                    ]);
                    $keep[] = $created->id;
                    $changes[] = [
                        'room_number' => $created->room_number,
                        'room_name' => $created->room_name,
                        'from' => null,
                        'to' => $created->product_code,
                        'drawing' => $drawing->original_filename,
                        'local' => true,
                    ];
                }

                foreach ($existingLocals as $extra) {
                    if (in_array($extra->id, $keep, true) || $extra->source === QuantitySource::Manual) {
                        continue;
                    }
                    $changes[] = [
                        'room_number' => $extra->room_number,
                        'room_name' => $extra->room_name,
                        'from' => $extra->product_code,
                        'to' => null,
                        'drawing' => $drawing->original_filename,
                        'removed_local' => true,
                    ];
                    $extra->delete();
                }
            }
        }

        $this->applySharedLegend($calculation);

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isSquareMeterLine(array $line): bool
    {
        $unit = $line['unit'] ?? null;
        if ($unit instanceof WorkUnit) {
            return $unit === WorkUnit::SquareMeter;
        }

        return $unit === WorkUnit::SquareMeter->value;
    }

    public function delete(Calculation $calculation): void
    {
        DB::transaction(function () use ($calculation): void {
            $calculation->loadMissing(['drawings', 'workbooks']);
            foreach ($calculation->drawings as $drawing) {
                if ($drawing->file_path !== '') {
                    Storage::disk('local')->delete($drawing->file_path);
                }
            }
            foreach ($calculation->workbooks as $workbook) {
                if ($workbook->file_path !== '') {
                    Storage::disk('local')->delete($workbook->file_path);
                }
            }
            $calculation->delete();
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function attachWorkbooks(Calculation $calculation, array $files): void
    {
        foreach ($files as $file) {
            $directory = 'calculations/'.$calculation->id.'/excel';
            $path = $file->store($directory, 'local');
            $stored = CalculationWorkbook::query()->create([
                'calculation_id' => $calculation->id,
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'file_size' => $file->getSize(),
                'status' => 'pending',
            ]);
            try {
                $analysis = $this->workbookAnalyzer->analyze(
                    Storage::disk('local')->path($stored->file_path),
                    $stored->original_filename,
                );
            } catch (\RuntimeException $e) {
                $analysis = [
                    'filename' => $stored->original_filename,
                    'sheets' => [],
                    'skippable' => true,
                    'skip_reason' => $e->getMessage(),
                    'labels' => [],
                ];
            }
            $stored->update([
                'analysis' => $analysis,
                'warnings' => array_values(array_filter([
                    $analysis['skip_reason'] ?? null,
                ])),
            ]);
        }

        $this->autoApply->applyPending($calculation);
    }

    private function applySharedLegend(Calculation $calculation): void
    {
        $legend = [];
        foreach ($calculation->drawings()->get() as $drawing) {
            foreach ($drawing->legend ?? [] as $entry) {
                $code = mb_strtolower(trim((string) ($entry['code'] ?? '')));
                $product = trim((string) ($entry['product'] ?? ''));
                if ($code === '' || $product === '' || isset($legend[$code])) {
                    continue;
                }
                $legend[$code] = $product;
            }
        }
        if ($legend === []) {
            return;
        }

        foreach ($calculation->lines()->get() as $line) {
            $code = mb_strtolower(trim((string) $line->product_code));
            if ($code === '' || filled($line->product) || ! isset($legend[$code])) {
                continue;
            }
            $note = trim(str_replace('Productcode niet in het renvooi gevonden.', '', (string) $line->note));
            $updates = [
                'product' => $legend[$code],
                'note' => $note === '' ? null : $note,
            ];
            if (
                $line->unit === WorkUnit::SquareMeter
                && $line->quantity !== null
                && filled($line->room_name)
                && $line->source === QuantitySource::Review
            ) {
                $updates['source'] = QuantitySource::FromDrawing->value;
            }
            $line->update($updates);
        }
    }

    private function storeDrawing(Calculation $calculation, UploadedFile $file): CalculationDrawing
    {
        $directory = 'calculations/'.$calculation->id;
        $path = $file->store($directory, 'local');

        return CalculationDrawing::query()->create([
            'calculation_id' => $calculation->id,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/pdf',
            'file_size' => $file->getSize(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<int>
     */
    private function rowLineIds(array $row): array
    {
        $ids = [];
        foreach ($row['floors'] ?? [] as $floor) {
            if ($floor instanceof CalculationLine) {
                $ids[] = (int) $floor->id;
            }
        }
        if (($row['floor'] ?? null) instanceof CalculationLine) {
            $ids[] = (int) $row['floor']->id;
        }
        if (($row['plinth'] ?? null) instanceof CalculationLine) {
            $ids[] = (int) $row['plinth']->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $next
     */
    private function changed(CalculationLine $line, array $next): bool
    {
        foreach (['room_number', 'room_name', 'product_code', 'product', 'note'] as $field) {
            if ($this->comparableString($line->{$field}) !== $this->comparableString($next[$field] ?? '')) {
                return true;
            }
        }

        $currentQty = $line->quantity === null ? null : round((float) $line->quantity, 3);
        $nextQty = $next['quantity'] === null || $next['quantity'] === '' ? null : round((float) $next['quantity'], 3);
        if ($currentQty !== $nextQty) {
            return true;
        }

        $currentUnit = $line->unit instanceof WorkUnit ? $line->unit->value : (string) $line->unit;
        $nextUnit = $next['unit'] instanceof WorkUnit ? $next['unit']->value : (string) $next['unit'];

        return $currentUnit !== $nextUnit;
    }

    private function comparableString(mixed $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? (string) $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
