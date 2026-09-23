<?php

namespace App\Services;

use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\ProjectCalculationLine;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\Meetstaat\MaterialIdentity;
use App\Support\WorkType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CalculationImportService
{
    public function __construct(
        private SpreadsheetReader $reader,
        private CalculationExcelParser $parser,
        private CalculationWorkMatcher $matcher,
        private CalculationSourceReconciler $reconciler,
        private MaterialIdentity $identity,
        private SourceDocumentService $sourceDocuments,
    ) {}

    /**
     * @param  list<array{file: UploadedFile, type?: ?string}>  $uploads
     * @return array{
     *     remaining: list<array{file: UploadedFile, type?: ?string}>,
     *     files: list<array{file: UploadedFile, parsed: array<string, mixed>}>
     * }
     */
    public function extract(array $uploads): array
    {
        $remaining = [];
        $files = [];

        foreach ($uploads as $upload) {
            $file = $upload['file'] ?? null;
            if (! $file instanceof UploadedFile || ! $this->isSpreadsheet($file)) {
                $remaining[] = $upload;

                continue;
            }

            $path = $file->getRealPath();
            if (! is_string($path) || $path === '') {
                $remaining[] = $upload;

                continue;
            }

            try {
                $rows = $this->reader->rows($path, $file->getClientOriginalName());
            } catch (\Throwable) {
                $remaining[] = $upload;

                continue;
            }

            if (! $this->parser->looksLike($rows)) {
                $remaining[] = $upload;

                continue;
            }

            $files[] = [
                'file' => $file,
                'parsed' => $this->parser->parse($rows, $file->getClientOriginalName()),
            ];
        }

        return [
            'remaining' => $remaining,
            'files' => $files,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  list<array{file: UploadedFile, parsed: array<string, mixed>}>  $files
     * @return array<string, mixed>
     */
    public function attach(array $preview, array $files): array
    {
        $labor = [];
        $lines = [];
        $filenames = [];
        $totalHours = 0.0;
        $totalCost = 0.0;
        $workNumber = null;
        $works = $preview['works'] ?? [];
        $sourceProducts = $this->reconciler->sourceProducts($preview);

        foreach ($files as $file) {
            $parsed = $file['parsed'];
            $filenames[] = $parsed['filename'];
            if ($workNumber === null && filled($parsed['work_number'] ?? null)) {
                $workNumber = (string) $parsed['work_number'];
            }
            foreach ($parsed['lines'] as $line) {
                $lines[] = $line;
            }
            foreach ($parsed['labor'] as $line) {
                $matched = $this->matcher->match($this->lineDescription($line), $works, [
                    'line' => $line,
                    'materials' => $line['context_materials'] ?? [],
                    'source_products' => $sourceProducts,
                ]);
                $labor[] = $this->laborPreviewRow($line, $matched);
                $totalHours += (float) ($line['hours'] ?? 0);
                $totalCost += (float) ($line['labor_cost'] ?? 0);
            }
        }

        $reconciliation = $this->reconciler->reconcile($lines, $preview);
        $labor = $this->applyMeetstaatLeadingQuantities($labor, $reconciliation['products']);
        $preview['calculation'] = [
            'filenames' => array_values(array_filter($filenames)),
            'lines' => $lines,
            'labor' => $labor,
            'products' => $reconciliation['products'],
            'source_checks' => $reconciliation['checks'],
            'options' => $this->matcher->optionLabels(array_merge($works, $sourceProducts)),
            'total_hours' => round($totalHours, 2),
            'total_labor_cost' => round($totalCost, 2),
            'open_matches' => $this->openMatchCount($labor),
            'warnings' => $this->warningCount($labor, $reconciliation['products']),
            'work_number' => $workNumber,
        ];
        if ($workNumber !== null && blank($preview['header']['project_number'] ?? null)) {
            $preview['header'] = array_merge($preview['header'] ?? [], ['project_number' => $workNumber]);
        }

        return $preview;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<int|string, array<string, mixed>>  $posted
     * @return array<string, mixed>
     */
    public function applyReview(array $preview, array $posted): array
    {
        $labor = $preview['calculation']['labor'] ?? [];
        foreach ($labor as $index => $line) {
            $postedName = $posted[$index]['work_name'] ?? null;
            $workName = trim((string) ($postedName ?? $line['work_name'] ?? ''));
            if ($workName === '') {
                $labor[$index]['work_name'] = null;
                $labor[$index]['work_key'] = null;
                $labor[$index]['status'] = 'review';
                $labor[$index]['status_label'] = 'Handmatige controle vereist';

                continue;
            }

            $labor[$index]['work_name'] = $workName;
            $labor[$index]['work_key'] = mb_strtolower($workName);
            if (($line['status'] ?? '') === 'review' || $postedName !== null) {
                $labor[$index]['status'] = 'matched';
                $labor[$index]['status_label'] = 'Automatisch bevestigd';
            }
        }

        $preview['calculation']['labor'] = $labor;
        $preview['calculation']['open_matches'] = $this->openMatchCount($labor);

        return $preview;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function hasOpenMatches(array $preview): bool
    {
        return ($preview['calculation']['open_matches'] ?? 0) > 0;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    public function replaceFromPreview(Project $project, array $preview): int
    {
        $oldIds = $project->documents()
            ->where('document_type', 'calculatie')
            ->where('is_current', false)
            ->pluck('id');
        if ($oldIds->isNotEmpty()) {
            ProjectCalculationLine::query()
                ->where('project_id', $project->id)
                ->whereIn('project_document_id', $oldIds)
                ->delete();
        }

        $project->unsetRelation('calculationLines');
        $project->load(['documents', 'workItems']);

        return $this->persist($project, $preview);
    }

    public function refreshBudgets(Project $project): void
    {
        $this->applyBudgets($project);
    }

    /**
     * Koppel arbeidsregels die nog op uren staan aan hun productie-m²/m¹
     * en vul de arbeidsprijs per eenheid aan.
     */
    public function linkOpenLaborQuantities(Project $project): void
    {
        $project->load(['calculationLines', 'workItems']);
        $this->alignDistinctLaborActivities($project);
        $project->unsetRelation('calculationLines');
        $project->unsetRelation('workItems');
        $project->load(['calculationLines', 'workItems']);
        $lines = $project->calculationLines->sortBy('row_number')->values();
        if ($lines->isEmpty()) {
            return;
        }

        $openIds = $lines
            ->filter(fn (ProjectCalculationLine $line): bool => $line->is_labor && $line->normalizedUnit() === WorkUnit::Hours)
            ->pluck('id')
            ->all();
        $payload = [];
        foreach ($lines as $line) {
            $payload[] = $this->storedLinePayload($line);
        }
        $linked = $this->parser->relink($payload);
        $byId = [];
        foreach ($linked as $line) {
            if (isset($line['id'])) {
                $byId[(int) $line['id']] = $line;
            }
        }

        $touchedItemIds = [];
        foreach ($lines as $line) {
            if (! in_array($line->id, $openIds, true)) {
                continue;
            }
            $fresh = $byId[$line->id] ?? null;
            if ($fresh === null || ($fresh['quantity_status'] ?? '') !== 'linked') {
                continue;
            }
            $unit = (string) ($fresh['quantity_unit'] ?? '');
            if (! in_array($unit, ['m2', 'm1'], true)) {
                continue;
            }

            if ($line->work_item_id === null) {
                $matched = $this->matcher->match($this->lineDescription($fresh), $project->workItems->pluck('name')->all(), [
                    'line' => $fresh,
                    'materials' => $fresh['context_materials'] ?? [],
                ]);
                if (! in_array($matched['status'] ?? '', ['matched', 'warning'], true) || ! filled($matched['work_name'] ?? null)) {
                    continue;
                }
                $workName = (string) $matched['work_name'];
                $workItem = $this->workItemFor($project, $workName, $fresh);
                $line->work_item_id = $workItem->id;
                $line->work_match_key = $matched['work_key'] ?? mb_strtolower($workName);
                $line->work_match_label = $workName;
                $line->match_status = $matched['status'];
            }

            $line->unit = $unit;
            $line->quantity = $fresh['quantity'];
            $line->save();
            if ($line->work_item_id !== null) {
                $touchedItemIds[] = (int) $line->work_item_id;
            }
        }

        $project->unsetRelation('calculationLines');
        $project->unsetRelation('workItems');
        $project->load(['calculationLines', 'workItems']);

        foreach ($project->workItems as $item) {
            $price = $item->calculatedLaborUnitPrice();
            $shouldFillQuantity = in_array($item->id, $touchedItemIds, true) && $item->begrote_hoeveelheid === null;
            if ($shouldFillQuantity) {
                $quantity = $this->linkedQuantityFor($project, $item);
                if ($quantity !== null) {
                    $item->begrote_hoeveelheid = $quantity;
                }
                if ($item->begrote_uren === null) {
                    $item->begrote_uren = round((float) $project->calculationLines
                        ->where('work_item_id', $item->id)
                        ->where('is_labor', true)
                        ->sum('hours'), 2);
                }
                if ($item->uurtarief === null) {
                    $rate = $project->calculationLines
                        ->where('work_item_id', $item->id)
                        ->where('is_labor', true)
                        ->pluck('hourly_rate')
                        ->filter(fn ($rate) => $rate !== null)
                        ->map(fn ($rate) => (float) $rate)
                        ->unique()
                        ->values();
                    if ($rate->count() === 1) {
                        $item->uurtarief = (float) $rate->first();
                    }
                }
                $price = $item->calculatedLaborUnitPrice();
            }
            if ($item->labor_unit_price === null && $price !== null) {
                $item->labor_unit_price = $price;
            } elseif ($shouldFillQuantity) {
                $item->labor_unit_price = $price;
            }
            if ($item->isDirty()) {
                $item->save();
            }
        }
    }

    public function persist(Project $project, array $preview): int
    {
        $calculation = $preview['calculation'] ?? [];
        if (($calculation['labor'] ?? []) === [] && ($calculation['lines'] ?? []) === []) {
            return 0;
        }

        $project->loadMissing(['documents', 'workItems']);
        $documents = $project->documents
            ->where('document_type', 'calculatie')
            ->filter(fn (ProjectDocument $document): bool => (bool) $document->is_current)
            ->values();
        if ($documents->isEmpty()) {
            $documents = $project->documents
                ->where('document_type', 'calculatie')
                ->values();
        }
        if ($documents->isEmpty()) {
            return 0;
        }

        $laborByRow = [];
        foreach ($calculation['labor'] ?? [] as $line) {
            $key = mb_strtolower((string) ($line['source_filename'] ?? '')).'#'.(int) ($line['row_number'] ?? 0);
            $laborByRow[$key] = $line;
        }

        $created = 0;
        foreach ($documents as $document) {
            $created += $this->persistDocument(
                $project,
                $document,
                $this->linesForDocument($calculation['lines'] ?? [], $document, $documents->count()),
                $laborByRow,
            );
        }

        $this->applyBudgets($project->fresh(['workItems', 'calculationLines']));

        return $created;
    }

    public function importFile(Project $project, UploadedFile $file, User $user): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            return [
                'warnings' => ['Excel-bestand kon niet worden gelezen.'],
                'rooms' => 0,
                'works' => 0,
                'created' => 0,
            ];
        }

        try {
            $rows = $this->reader->rows($path, $file->getClientOriginalName());
        } catch (\Throwable $e) {
            return [
                'warnings' => ['Excel-bestand kon niet worden gelezen: '.$e->getMessage()],
                'rooms' => 0,
                'works' => 0,
                'created' => 0,
            ];
        }

        if (! $this->parser->looksLike($rows)) {
            return [
                'warnings' => ['Dit Excel-bestand is geen calculatie met arbeidsuren (kolommen M/U en Kostprijs ontbreken).'],
                'rooms' => 0,
                'works' => 0,
                'created' => 0,
            ];
        }

        $hash = hash_file('sha256', $path);
        if (is_string($hash) && ProjectCalculationLine::query()
            ->where('project_id', $project->id)
            ->where('source_hash', $hash)
            ->exists()) {
            return [
                'warnings' => ['Deze calculatie was al ingelezen; bestaande regels zijn behouden.'],
                'rooms' => 0,
                'works' => 0,
                'created' => 0,
                'screen_summary' => 'Calculatie was al ingelezen',
                'screen_ready' => true,
            ];
        }

        $parsed = $this->parser->parse($rows, $file->getClientOriginalName());
        $preview = $this->attach([
            'works' => $project->workItems->map(fn (WorkItem $item): array => ['name' => $item->name])->all(),
        ], [['file' => $file, 'parsed' => $parsed]]);

        $this->sourceDocuments->storeUploaded($project, $file, 'calculatie', $user, 'ok', [
            'filename' => $parsed['filename'],
            'work_number' => $parsed['work_number'],
            'total_hours' => $parsed['total_hours'],
            'total_labor_cost' => $parsed['total_labor_cost'],
        ]);
        $project->unsetRelation('documents');
        $project->load('documents');

        $created = $this->persist($project, $preview);

        return [
            'warnings' => $created === 0 ? ['Deze calculatie was al ingelezen; bestaande regels zijn behouden.'] : [],
            'rooms' => 0,
            'works' => 0,
            'created' => $created,
            'screen_summary' => $created === 0
                ? 'Calculatie was al ingelezen'
                : $created.' calculatieregels bewaard',
            'screen_ready' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $labor
     */
    private function openMatchCount(array $labor): int
    {
        return count(array_filter(
            $labor,
            fn (array $line): bool => ($line['status'] ?? '') === 'review' || blank($line['work_name'] ?? null)
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $labor
     * @param  list<array<string, mixed>>  $products
     */
    private function warningCount(array $labor, array $products): int
    {
        return count(array_filter(
            $labor,
            fn (array $line): bool => ($line['status'] ?? '') === 'warning'
        )) + count(array_filter(
            $products,
            fn (array $row): bool => in_array($row['status'] ?? '', ['warning', 'review'], true)
        ));
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array{status: string, work_name: ?string, work_key: ?string, candidates: list<string>, warning?: ?string}  $matched
     * @return array<string, mixed>
     */
    private function laborPreviewRow(array $line, array $matched): array
    {
        $status = $matched['status'];

        return [
            ...$line,
            'description' => $this->lineDescription($line),
            'status' => $status,
            'work_name' => $matched['work_name'],
            'work_key' => $matched['work_key'],
            'candidates' => $matched['candidates'],
            'warning' => $matched['warning'] ?? null,
            'status_label' => match ($status) {
                'matched' => 'Automatisch bevestigd',
                'warning' => 'Waarschuwing',
                default => 'Handmatige controle vereist',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineDescription(array $line): string
    {
        $production = trim((string) ($line['production_description'] ?? ''));
        $article = trim((string) ($line['article_description'] ?? ''));
        if ($production !== '') {
            return $production;
        }

        return $article;
    }

    /**
     * @param  list<array<string, mixed>>  $labor
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function applyMeetstaatLeadingQuantities(array $labor, array $products): array
    {
        foreach ($labor as $index => $line) {
            $workName = trim((string) ($line['work_name'] ?? ''));
            if ($workName === '') {
                continue;
            }
            $product = $this->productForWork($workName, $products);
            if ($product === null) {
                continue;
            }
            if (($product['meetstaat_quantity'] ?? null) !== null) {
                $labor[$index]['excel_quantity'] = $line['quantity'] ?? null;
                $labor[$index]['quantity'] = $product['meetstaat_quantity'];
                $labor[$index]['quantity_source'] = 'meetstaat';
            }
        }

        return $labor;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array<string, mixed>|null
     */
    private function productForWork(string $workName, array $products): ?array
    {
        $hits = [];
        foreach ($products as $product) {
            $name = trim((string) ($product['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (strcasecmp($name, $workName) === 0
                || $this->identity->sharesIdentity($name, $workName)
                || $this->identity->sharesIdentity($workName, $name)) {
                $hits[] = $product;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function linesForDocument(array $lines, ProjectDocument $document, int $documentCount): array
    {
        if ($documentCount <= 1) {
            return $lines;
        }

        return array_values(array_filter(
            $lines,
            fn (array $line): bool => ($line['source_filename'] ?? '') === $document->original_filename
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, array<string, mixed>>  $laborByRow
     */
    private function persistDocument(Project $project, ProjectDocument $document, array $lines, array $laborByRow): int
    {
        $absolute = Storage::disk('local')->path($document->file_path);
        if (! is_file($absolute)) {
            return 0;
        }

        $hash = hash_file('sha256', $absolute);
        if (ProjectCalculationLine::query()
            ->where('project_id', $project->id)
            ->where('source_hash', $hash)
            ->exists()) {
            return 0;
        }

        $created = 0;
        $filename = $document->original_filename;
        foreach ($lines as $line) {
            if (($line['source_filename'] ?? '') !== '' && $line['source_filename'] !== $filename && count($lines) > 1) {
                continue;
            }

            $laborKey = mb_strtolower((string) ($line['source_filename'] ?? $filename)).'#'.(int) $line['row_number'];
            $labor = $laborByRow[$laborKey] ?? $laborByRow['#'.(int) $line['row_number']] ?? null;
            $workName = $labor['work_name'] ?? null;
            $matched = in_array(($labor['status'] ?? ''), ['matched', 'warning'], true) && filled($workName);
            $workItem = (($labor['is_labor'] ?? $line['is_labor'] ?? false) && $matched)
                ? $this->workItemFor($project, (string) $workName, $line)
                : null;

            ProjectCalculationLine::query()->create([
                'project_id' => $project->id,
                'project_document_id' => $document->id,
                'work_item_id' => $workItem?->id,
                'row_number' => $line['row_number'],
                'source_hash' => $hash,
                'source_filename' => $filename,
                'km' => $line['km'] ?? null,
                'group_code' => $line['group_code'] ?? null,
                'mu' => $line['mu'] ?? null,
                'article_number' => $line['article_number'] ?? null,
                'production_description' => $line['production_description'] ?? null,
                'article_description' => $line['article_description'] ?? null,
                'unit' => $line['quantity_unit'] ?? $line['unit'] ?? null,
                'quantity' => $line['quantity'] ?? null,
                'hours' => $line['hours'] ?? null,
                'hourly_rate' => $line['hourly_rate'] ?? null,
                'labor_cost' => $line['labor_cost'] ?? null,
                'unit_cost' => $line['unit_cost'] ?? null,
                'total_cost' => $line['total_cost'] ?? null,
                'is_labor' => (bool) ($line['is_labor'] ?? false),
                'work_match_key' => $labor['work_key'] ?? null,
                'work_match_label' => $workName,
                'match_status' => $labor['status'] ?? ((bool) ($line['is_labor'] ?? false) ? 'review' : 'ignored'),
                'naca_code' => $line['naca_code'] ?? null,
                'raw' => $line['raw'] ?? null,
            ]);
            $created++;
        }

        return $created;
    }

    private function alignDistinctLaborActivities(Project $project): void
    {
        $touched = [];
        foreach ($project->calculationLines as $line) {
            if (! $line->is_labor) {
                continue;
            }
            $activity = WorkType::distinctActivity((string) $line->production_description);
            if ($activity === null) {
                continue;
            }
            $current = $line->work_item_id === null
                ? null
                : $project->workItems->firstWhere('id', $line->work_item_id);
            if ($current instanceof WorkItem && $this->itemRepresentsActivity($current, $activity)) {
                continue;
            }

            $target = $this->workItemFor($project, $activity, $this->storedLinePayload($line), exactOnly: true);
            if ($current instanceof WorkItem) {
                $touched[] = (int) $current->id;
            }
            $line->work_item_id = $target->id;
            $line->work_match_key = mb_strtolower($activity);
            $line->work_match_label = $activity;
            $line->match_status = 'matched';
            $line->save();
            $touched[] = (int) $target->id;
        }

        if ($touched === []) {
            return;
        }

        $this->rebuildBudgetsFromLaborLines($project, array_values(array_unique($touched)));
    }

    private function itemRepresentsActivity(WorkItem $item, string $activity): bool
    {
        if (strcasecmp(trim($item->name), $activity) === 0) {
            return true;
        }

        $known = WorkType::knownType($item->name);

        return $known !== null && strcasecmp($known, $activity) === 0;
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function rebuildBudgetsFromLaborLines(Project $project, array $itemIds): void
    {
        $project->load(['calculationLines', 'workItems']);
        foreach ($itemIds as $itemId) {
            $item = $project->workItems->firstWhere('id', $itemId);
            if (! $item instanceof WorkItem) {
                continue;
            }
            $lines = $project->calculationLines
                ->where('work_item_id', $item->id)
                ->where('is_labor', true);
            if ($lines->isEmpty()) {
                $item->forceFill([
                    'begrote_uren' => null,
                    'begrote_hoeveelheid' => null,
                    'labor_unit_price' => null,
                ])->save();

                continue;
            }

            $hours = round((float) $lines->sum('hours'), 2);
            $quantity = $this->linkedQuantityFor($project, $item);
            $rates = $lines->pluck('hourly_rate')->filter(fn ($rate) => $rate !== null)->map(fn ($rate) => (float) $rate)->unique()->values();
            $item->begrote_uren = $hours;
            if ($quantity !== null) {
                $item->begrote_hoeveelheid = $quantity;
            }
            if ($item->uurtarief === null && $rates->count() === 1) {
                $item->uurtarief = (float) $rates->first();
            }
            $item->labor_unit_price = $item->calculatedLaborUnitPrice();
            $item->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function storedLinePayload(ProjectCalculationLine $line): array
    {
        $unit = $line->unit !== null ? (string) $line->unit : null;
        $isLabor = (bool) $line->is_labor;
        $linked = $isLabor && in_array(rtrim(mb_strtolower(trim((string) $unit)), '.'), ['m2', 'm²', 'm1', 'm¹', 'lm'], true);

        return [
            'id' => $line->id,
            'row_number' => $line->row_number,
            'group_code' => $line->group_code,
            'km' => $line->km,
            'mu' => $line->mu,
            'article_number' => $line->article_number,
            'production_description' => $line->production_description,
            'article_description' => $line->article_description,
            'unit' => $unit,
            'quantity' => $line->quantity !== null ? (float) $line->quantity : null,
            'quantity_unit' => $unit,
            'hours' => $line->hours !== null ? (float) $line->hours : null,
            'hourly_rate' => $line->hourly_rate !== null ? (float) $line->hourly_rate : null,
            'labor_cost' => $line->labor_cost !== null ? (float) $line->labor_cost : null,
            'is_labor' => $isLabor,
            'quantity_status' => $linked ? 'linked' : ($isLabor ? 'missing' : 'ignored'),
            'context_materials' => [],
        ];
    }

    private function linkedQuantityFor(Project $project, WorkItem $item): ?float
    {
        $quantity = round((float) $project->calculationLines
            ->where('work_item_id', $item->id)
            ->where('is_labor', true)
            ->filter(function (ProjectCalculationLine $line): bool {
                $unit = rtrim(mb_strtolower(trim((string) $line->unit)), '.');

                return in_array($unit, ['m2', 'm²', 'm1', 'm¹', 'lm'], true);
            })
            ->sum('quantity'), 2);

        return $quantity > 0.0001 ? $quantity : null;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function workItemFor(Project $project, string $workName, array $line, bool $exactOnly = false): WorkItem
    {
        $project->loadMissing('workItems');
        foreach ($project->workItems as $item) {
            if (strcasecmp($item->name, $workName) === 0) {
                return $item;
            }
            if ($exactOnly) {
                continue;
            }
            if ($this->identity->sharesIdentity($item->name, $workName)
                || $this->identity->sharesIdentity($workName, $item->name)) {
                return $item;
            }
        }

        $unit = $this->workUnit($line);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $workName,
            'unit' => $unit->value,
            'ordered_quantity' => 0,
            'status' => 'gepland',
            'sort_order' => (int) $project->workItems->max('sort_order') + 1,
        ]);
        $project->setRelation('workItems', $project->workItems->push($item));

        return $item;
    }

    private function applyBudgets(Project $project): void
    {
        $project->load(['workItems', 'calculationLines']);
        $laborLines = $project->calculationLines->where('is_labor', true);
        $rates = [];

        foreach ($project->workItems as $item) {
            $lines = $laborLines->where('work_item_id', $item->id);
            if ($lines->isEmpty()) {
                continue;
            }

            $hours = round((float) $lines->sum('hours'), 2);
            $cost = round((float) $lines->sum('labor_cost'), 2);
            $quantity = round((float) $lines
                ->filter(function (ProjectCalculationLine $line): bool {
                    $unit = rtrim(mb_strtolower(trim((string) $line->unit)), '.');

                    return in_array($unit, ['m2', 'm²', 'm1', 'm¹', 'lm'], true);
                })
                ->sum('quantity'), 2);
            $lineRates = $lines->pluck('hourly_rate')->filter(fn ($rate) => $rate !== null)->map(fn ($rate) => (float) $rate)->unique()->values();
            $rate = $lineRates->count() === 1
                ? (float) $lineRates->first()
                : ($hours > 0.0001 ? round($cost / $hours, 2) : null);

            $item->begrote_uren = $hours;
            $item->begrote_hoeveelheid = $quantity > 0.0001 ? $quantity : $item->begrote_hoeveelheid;
            $item->uurtarief = $rate;
            $item->labor_unit_price = $item->calculatedLaborUnitPrice();
            $item->save();

            if ($rate !== null) {
                $rates[] = $rate;
            }
        }

        $uniqueRates = array_values(array_unique($rates));
        if ($project->basis_uurtarief === null && count($uniqueRates) === 1) {
            $project->forceFill(['basis_uurtarief' => $uniqueRates[0]])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function workUnit(array $line): WorkUnit
    {
        $unit = rtrim(mb_strtolower(trim((string) ($line['quantity_unit'] ?? $line['unit'] ?? ''))), '.');

        return match ($unit) {
            'm1', 'm¹', 'lm' => WorkUnit::LinearMeter,
            'st', 'stk', 'stuk', 'stuks' => WorkUnit::Pieces,
            default => WorkUnit::SquareMeter,
        };
    }

    private function isSpreadsheet(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xlsm', 'xls', 'csv', 'txt'], true);
    }
}
