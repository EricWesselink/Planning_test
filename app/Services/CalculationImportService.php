<?php

namespace App\Services;

use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\ProjectCalculationLine;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\Meetstaat\MaterialIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CalculationImportService
{
    public function __construct(
        private SpreadsheetReader $reader,
        private CalculationExcelParser $parser,
        private CalculationWorkMatcher $matcher,
        private MaterialIdentity $identity,
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
        $works = $preview['works'] ?? [];

        foreach ($files as $file) {
            $parsed = $file['parsed'];
            $filenames[] = $parsed['filename'];
            foreach ($parsed['lines'] as $line) {
                $lines[] = $line;
            }
            foreach ($parsed['labor'] as $line) {
                $matched = $this->matcher->match($this->lineDescription($line), $works);
                if (($line['quantity_status'] ?? '') === 'review') {
                    $matched['status'] = 'review';
                }
                $labor[] = $this->laborPreviewRow($line, $matched);
                $totalHours += (float) ($line['hours'] ?? 0);
                $totalCost += (float) ($line['labor_cost'] ?? 0);
            }
        }

        $preview['calculation'] = [
            'filenames' => array_values(array_filter($filenames)),
            'lines' => $lines,
            'labor' => $labor,
            'options' => $this->matcher->optionLabels($works),
            'total_hours' => round($totalHours, 2),
            'total_labor_cost' => round($totalCost, 2),
            'open_matches' => $this->openMatchCount($labor),
        ];

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
            $workName = trim((string) ($posted[$index]['work_name'] ?? $line['work_name'] ?? ''));
            if ($workName === '') {
                $labor[$index]['work_name'] = null;
                $labor[$index]['work_key'] = null;
                $labor[$index]['status'] = 'review';

                continue;
            }

            $labor[$index]['work_name'] = $workName;
            $labor[$index]['work_key'] = mb_strtolower($workName);
            $labor[$index]['status'] = 'matched';
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
     * @param  list<array{path: string, type: string, original: string}>  $extraDocuments
     */
    public function persist(Project $project, array $preview): int
    {
        $calculation = $preview['calculation'] ?? [];
        if (($calculation['labor'] ?? []) === [] && ($calculation['lines'] ?? []) === []) {
            return 0;
        }

        $project->loadMissing(['documents', 'workItems']);
        $documents = $project->documents
            ->where('document_type', 'calculatie')
            ->values();
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

        $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $stored = $file->storeAs(
            'projects/'.$project->id.'/calculatie',
            uniqid('calc-', true).'.'.$extension,
            'local'
        );
        $document = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => 'calculatie',
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $stored,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'parse_status' => 'ok',
            'parsed_json' => [
                'filename' => $parsed['filename'],
                'work_number' => $parsed['work_number'],
                'total_hours' => $parsed['total_hours'],
                'total_labor_cost' => $parsed['total_labor_cost'],
            ],
            'uploaded_by' => $user->id,
        ]);
        $project->setRelation('documents', $project->documents->push($document));

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
            fn (array $line): bool => ($line['status'] ?? '') !== 'matched' || blank($line['work_name'] ?? null)
        ));
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array{status: string, work_name: ?string, work_key: ?string, candidates: list<string>}  $matched
     * @return array<string, mixed>
     */
    private function laborPreviewRow(array $line, array $matched): array
    {
        return [
            ...$line,
            'description' => $this->lineDescription($line),
            'status' => $matched['status'],
            'work_name' => $matched['work_name'],
            'work_key' => $matched['work_key'],
            'candidates' => $matched['candidates'],
            'status_label' => $matched['status'] === 'matched' ? 'Gekoppeld' : 'Controleren',
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
            $matched = ($labor['status'] ?? '') === 'matched' && filled($workName);
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

    /**
     * @param  array<string, mixed>  $line
     */
    private function workItemFor(Project $project, string $workName, array $line): WorkItem
    {
        $project->loadMissing('workItems');
        foreach ($project->workItems as $item) {
            if (strcasecmp($item->name, $workName) === 0
                || $this->identity->sharesIdentity($item->name, $workName)
                || $this->identity->sharesIdentity($workName, $item->name)) {
                return $item;
            }
        }

        $unit = $this->workUnit($line);
        $item = WorkItem::query()->create([
            'project_id' => $project->id,
            'name' => $workName,
            'unit' => $unit->value,
            'ordered_quantity' => $this->quantityForUnit($line, $unit),
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

            $item->forceFill([
                'begrote_uren' => $hours,
                'begrote_hoeveelheid' => $quantity > 0.0001 ? $quantity : $item->begrote_hoeveelheid,
                'uurtarief' => $rate,
            ])->save();

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

    /**
     * @param  array<string, mixed>  $line
     */
    private function quantityForUnit(array $line, WorkUnit $unit): float
    {
        $lineUnit = rtrim(mb_strtolower(trim((string) ($line['quantity_unit'] ?? $line['unit'] ?? ''))), '.');
        if ($unit === WorkUnit::SquareMeter && in_array($lineUnit, ['m2', 'm²'], true)) {
            return (float) ($line['quantity'] ?? 0);
        }
        if ($unit === WorkUnit::LinearMeter && in_array($lineUnit, ['m1', 'm¹', 'lm'], true)) {
            return (float) ($line['quantity'] ?? 0);
        }

        return 0.0;
    }

    private function isSpreadsheet(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xlsm', 'xls', 'csv', 'txt'], true);
    }
}
