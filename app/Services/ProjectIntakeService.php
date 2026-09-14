<?php

namespace App\Services;

use App\Enums\AreaStatus;
use App\Enums\ProjectStatus;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\Meetstaat\MaterialIdentity;
use App\Support\MaterialColor;
use App\Support\WorkType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectIntakeService
{
    public function __construct(
        private SpreadsheetReader $reader,
        private MeetstaatParser $parser,
        private MaterialIdentity $materialIdentity,
        private ScreenExcelParser $screenExcel,
        private CalculationExcelParser $calculationExcel,
        private CalculationImportService $calculationImport,
    ) {}

    /**
     * @param  array{
     *     project_number?: ?string,
     *     name: string,
     *     customer_name: string,
     *     city?: ?string,
     *     address?: ?string,
     *     postal_code?: ?string,
     *     planned_start_date?: ?string,
     *     planned_end_date?: ?string,
     *     notes?: ?string
     * }  $data
     * @return array{project: Project, warnings: list<string>, rooms: int, works: int, screen_summary: ?string, screen_ready: bool}
     */
    public function create(array $data, ?UploadedFile $plattegrond, ?UploadedFile $meetstaat, User $user, ?UploadedFile $excel = null): array
    {
        return DB::transaction(function () use ($data, $plattegrond, $meetstaat, $user, $excel) {
            $customer = Customer::query()->firstOrCreate(
                ['name' => $data['customer_name']],
                ['city' => $data['city'] ?? null]
            );

            $project = Project::query()->create([
                'project_number' => ($data['project_number'] ?? null) ?: $this->nextProjectNumber(),
                'customer_id' => $customer->id,
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'city' => $data['city'] ?? null,
                'supervisor_user_id' => $user->id,
                'planned_start_date' => $data['planned_start_date'] ?? null,
                'planned_end_date' => $data['planned_end_date'] ?? null,
                'status' => ProjectStatus::Gepland,
                'notes' => $data['notes'] ?? null,
            ]);

            $warnings = [];
            $rooms = 0;
            $works = 0;
            $screenSummary = null;
            $screenReady = false;

            if ($plattegrond) {
                $this->storeDocument($project, $plattegrond, 'plattegrond', $user);
            }

            if ($excel) {
                $imported = $this->importScreenExcel($project, $excel, $user);
                $warnings = $imported['warnings'];
                $rooms = $imported['rooms'];
                $works = $imported['works'];
                $screenSummary = $imported['screen_summary'] ?? null;
                $screenReady = (bool) ($imported['screen_ready'] ?? false);
            } elseif ($meetstaat) {
                $imported = $this->importMeetstaat($project, $meetstaat, $user);
                $warnings = $imported['warnings'];
                $rooms = $imported['rooms'];
                $works = $imported['works'];
                $screenSummary = $imported['screen_summary'] ?? null;
                $screenReady = (bool) ($imported['screen_ready'] ?? false);
            }

            return [
                'project' => $project,
                'warnings' => $warnings,
                'rooms' => $rooms,
                'works' => $works,
                'screen_summary' => $screenSummary,
                'screen_ready' => $screenReady,
            ];
        });
    }

    /**
     * @return array{warnings: list<string>, rooms: int, works: int, screen_summary: ?string, screen_ready: bool}
     */
    public function importMeetstaat(Project $project, UploadedFile $file, User $user): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if (in_array($extension, ['csv', 'txt', 'xlsx', 'xlsm', 'xls'], true) && is_string($path) && $path !== '') {
            try {
                $rows = $this->reader->rows($path, $file->getClientOriginalName());
            } catch (\Throwable $e) {
                $document = $this->storeDocument($project, $file, 'meetstaat', $user);
                $document->parse_status = 'failed';
                $document->save();

                return [
                    'warnings' => ['Bestand kon niet worden gelezen: '.$e->getMessage()],
                    'rooms' => 0,
                    'works' => 0,
                    'screen_summary' => null,
                    'screen_ready' => false,
                ];
            }

            if ($this->calculationExcel->looksLike($rows)) {
                return $this->calculationImport->importFile($project, $file, $user);
            }

            if ($this->screenExcel->looksLike($rows)) {
                return $this->importScreenExcel($project, $file, $user, $rows);
            }
        }

        $document = $this->storeDocument($project, $file, 'meetstaat', $user);

        if (! in_array($extension, ['csv', 'txt', 'xlsx', 'xlsm', 'xls'], true)) {
            $document->parse_status = 'skipped';
            $document->save();

            return [
                'warnings' => ['Meetstaat is opgeslagen. Exporteer hem als CSV of Excel (.xlsx) om ruimtes automatisch in te lezen.'],
                'rooms' => 0,
                'works' => 0,
                'screen_summary' => null,
                'screen_ready' => false,
            ];
        }

        try {
            $parsed = $this->parser->parse($rows ?? $this->reader->rows($path, $file->getClientOriginalName()));
        } catch (\Throwable $e) {
            $document->parse_status = 'failed';
            $document->save();

            return [
                'warnings' => ['Meetstaat kon niet worden gelezen: '.$e->getMessage()],
                'rooms' => 0,
                'works' => 0,
                'screen_summary' => null,
                'screen_ready' => false,
            ];
        }

        $document->parse_status = $parsed['floors'] === [] ? 'failed' : 'ok';
        $document->parsed_json = $parsed;
        $document->save();

        if ($this->parsedMeetstaatIsUnusable($parsed)) {
            $document->parse_status = 'failed';
            $document->save();

            return [
                'warnings' => ['Dit bestand is geen bruikbare vloer-meetstaat. Voor raambekleding of zonwering gebruik je het Excel-veld op het handmatige projectformulier.'],
                'rooms' => 0,
                'works' => 0,
                'screen_summary' => null,
                'screen_ready' => false,
            ];
        }

        try {
            $created = $this->applyParsedMeetstaat($project, $parsed);
        } catch (\Throwable $e) {
            $document->parse_status = 'failed';
            $document->save();

            return [
                'warnings' => ['Meetstaat kon niet worden opgeslagen: '.$e->getMessage()],
                'rooms' => 0,
                'works' => 0,
                'screen_summary' => null,
                'screen_ready' => false,
            ];
        }

        return [
            'warnings' => $parsed['warnings'],
            'rooms' => $created['rooms'],
            'works' => $created['works'],
            'screen_summary' => null,
            'screen_ready' => false,
        ];
    }

    /**
     * @param  list<list<string>>|null  $rows
     * @return array{warnings: list<string>, rooms: int, works: int, screen_summary: ?string, screen_ready: bool}
     */
    public function importScreenExcel(Project $project, UploadedFile $file, User $user, ?array $rows = null): array
    {
        $path = $file->getRealPath();
        if ($rows === null) {
            if (! is_string($path) || $path === '') {
                return [
                    'warnings' => ['Excel-bestand kon niet worden gelezen.'],
                    'rooms' => 0,
                    'works' => 0,
                    'screen_summary' => null,
                    'screen_ready' => false,
                ];
            }

            $rows = $this->reader->rows($path, $file->getClientOriginalName());
        }

        if (! $this->screenExcel->looksLike($rows) && $this->calculationExcel->looksLike($rows)) {
            return $this->calculationImport->importFile($project, $file, $user);
        }

        $document = $this->storeDocument($project, $file, 'opdrachtlijst', $user);

        if (! $this->screenExcel->looksLike($rows)) {
            $document->parse_status = 'failed';
            $document->save();

            return [
                'warnings' => ['Dit Excel-bestand is geen opdrachtlijst voor raambekleding of zonwering. Controleer of de kolommen Omschrijving, Aantal en EH aanwezig zijn.'],
                'rooms' => 0,
                'works' => 0,
                'screen_summary' => null,
                'screen_ready' => false,
            ];
        }

        $parsed = $this->screenExcel->parse($rows);
        $document->parse_status = $parsed['lines'] === [] ? 'failed' : 'ok';
        $document->parsed_json = $parsed;
        $document->save();

        $created = $this->applyScreenExcel($project, $parsed);
        $warnings = [];
        foreach ($parsed['unrecognized'] as $row) {
            $warnings[] = 'Regel '.$row['row'].': '.$row['description'].' ('.$row['reason'].')';
        }
        if ($parsed['lines'] === []) {
            $warnings[] = 'Geen screenregels met eenheid stuks gevonden.';
        }

        return [
            'warnings' => $warnings,
            'rooms' => $created['rooms'],
            'works' => $created['works'],
            'screen_summary' => $parsed['summary'],
            'screen_ready' => $parsed['ready'],
        ];
    }

    /**
     * @param  array{
     *     lines: list<array{
     *         description: string,
     *         bnr: ?string,
     *         group: ?string,
     *         quantity: float,
     *         unit: string,
     *         source_rows: int
     *     }>
     * }  $parsed
     * @return array{rooms: int, works: int}
     */
    public function applyScreenExcel(Project $project, array $parsed): array
    {
        $workItems = [];
        $floors = [];
        $floorSort = (int) $project->floors()->max('sort_order');
        $rooms = 0;
        $bnrCounts = [];

        foreach ($parsed['lines'] as $line) {
            $floorName = $this->screenFloorName($line);
            if (! isset($floors[$floorName])) {
                $floorSort++;
                $floors[$floorName] = ProjectFloor::query()->firstOrCreate(
                    ['project_id' => $project->id, 'name' => $floorName],
                    ['sort_order' => $floorSort]
                );
            }
            $floor = $floors[$floorName];

            $bnr = $line['bnr'];
            $bnrCounts[$bnr ?? ''] = ($bnrCounts[$bnr ?? ''] ?? 0) + 1;
            $areaNumber = $this->screenAreaNumber($bnr, $bnrCounts[$bnr ?? '']);

            $area = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $areaNumber,
                'name' => $line['description'],
                'square_meters' => 0,
                'status' => AreaStatus::NietGestart,
                'sort_order' => $rooms + 1,
            ]);
            $rooms++;

            $key = mb_strtolower(($bnr ?? '').'|'.$line['description'].'|'.$line['unit']);
            if (! isset($workItems[$key])) {
                $workItems[$key] = WorkItem::query()->create([
                    'project_id' => $project->id,
                    'name' => $line['description'],
                    'unit' => $line['unit'],
                    'ordered_quantity' => 0,
                    'planned_start_date' => $project->planned_start_date,
                    'planned_end_date' => $project->planned_end_date,
                    'status' => 'gepland',
                    'sort_order' => count($workItems) + 1,
                    'notes' => $this->screenNotes($line),
                ]);
            }

            $item = $workItems[$key];
            $item->ordered_quantity = (float) $item->ordered_quantity + $line['quantity'];
            $item->save();

            AreaTask::query()->updateOrCreate(
                [
                    'project_area_id' => $area->id,
                    'work_item_id' => $item->id,
                ],
                [
                    'ordered_quantity' => $line['quantity'],
                    'quantity_source' => 'opdrachtlijst',
                    'unit' => $line['unit'],
                    'status' => AreaStatus::NietGestart,
                ]
            );
        }

        return ['rooms' => $rooms, 'works' => count($workItems)];
    }

    /**
     * @param  array{description: string, bnr: ?string, group: ?string}  $line
     */
    private function screenFloorName(array $line): string
    {
        $group = trim((string) ($line['group'] ?? ''));
        if ($group !== '' && preg_match('/BNR\s*\d+/iu', $group) !== 1) {
            return $group;
        }

        return 'Zonwering';
    }

    private function screenAreaNumber(?string $bnr, int $occurrence): string
    {
        if ($bnr === null || $bnr === '') {
            return (string) $occurrence;
        }

        return $occurrence > 1 ? 'BNR '.$bnr.'-'.$occurrence : 'BNR '.$bnr;
    }

    /**
     * @param  array{description: string, bnr: ?string, group: ?string}  $line
     */
    private function screenNotes(array $line): ?string
    {
        $parts = [];
        $group = trim((string) ($line['group'] ?? ''));
        if ($group !== '' && ! str_contains(mb_strtolower($line['description']), mb_strtolower($group))) {
            $parts[] = $group;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  array{floors?: list<array{areas?: list<array{square_meters?: float, tasks?: list<array{work_name?: string, quantity?: float}>}>}>}  $parsed
     */
    private function parsedMeetstaatIsUnusable(array $parsed): bool
    {
        foreach ($parsed['floors'] ?? [] as $floor) {
            foreach ($floor['areas'] ?? [] as $area) {
                if ((float) ($area['square_meters'] ?? 0) > 1_000_000) {
                    return true;
                }
                foreach ($area['tasks'] ?? [] as $task) {
                    if ((float) ($task['quantity'] ?? 0) > 1_000_000) {
                        return true;
                    }
                    $name = (string) ($task['work_name'] ?? '');
                    if (str_contains($name, 'xl/worksheets') || str_contains($name, "PK\x03\x04")) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function safeAreaQuantity(mixed $value): float
    {
        $number = (float) $value;
        if (! is_finite($number) || $number < 0) {
            return 0.0;
        }

        return min($number, 99_999_999.99);
    }

    /**
     * @param  array{floors: list<array{name: string, areas: list<array{area_number: ?string, name: string, square_meters: float, tasks: list<array{work_name: string, quantity: float, unit: string}>}>}>}  $parsed
     * @return array{rooms: int, works: int}
     */
    public function applyParsedMeetstaat(Project $project, array $parsed): array
    {
        $workItems = [];
        $rooms = 0;

        foreach ($parsed['floors'] as $floorIndex => $floorData) {
            $floor = ProjectFloor::query()->firstOrCreate(
                ['project_id' => $project->id, 'name' => $floorData['name']],
                ['sort_order' => $floorIndex + 1]
            );

            foreach ($floorData['areas'] as $areaIndex => $areaData) {
                $area = ProjectArea::query()->create([
                    'project_id' => $project->id,
                    'project_floor_id' => $floor->id,
                    'area_number' => $areaData['area_number'],
                    'name' => $areaData['name'],
                    'square_meters' => $this->safeAreaQuantity($areaData['square_meters'] ?? 0),
                    'status' => AreaStatus::NietGestart,
                    'sort_order' => $areaIndex + 1,
                ]);
                $rooms++;

                foreach ($areaData['tasks'] as $task) {
                    $key = mb_strtolower($task['work_name']).'|'.$task['unit'];
                    if (! isset($workItems[$key])) {
                        $workItems[$key] = WorkItem::query()->create([
                            'project_id' => $project->id,
                            'name' => $task['work_name'],
                            'unit' => $task['unit'],
                            'ordered_quantity' => 0,
                            'planned_start_date' => $project->planned_start_date,
                            'planned_end_date' => $project->planned_end_date,
                            'status' => 'gepland',
                            'sort_order' => count($workItems) + 1,
                        ]);
                    }

                    $item = $workItems[$key];
                    $item->ordered_quantity = (float) $item->ordered_quantity + $task['quantity'];
                    $item->save();

                    AreaTask::query()->updateOrCreate(
                        [
                            'project_area_id' => $area->id,
                            'work_item_id' => $item->id,
                        ],
                        [
                            'ordered_quantity' => $task['quantity'],
                            'unit' => $task['unit'] instanceof WorkUnit ? $task['unit']->value : $task['unit'],
                            'status' => AreaStatus::NietGestart,
                        ]
                    );
                }
            }
        }

        return ['rooms' => $rooms, 'works' => count($workItems)];
    }

    public function storeDocument(Project $project, UploadedFile $file, string $type, User $user): ProjectDocument
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs(
            'projects/'.$project->id.'/'.$type,
            Str::uuid()->toString().'.'.$extension,
            'local'
        );

        return ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => $type,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'parse_status' => $type === 'meetstaat' ? 'pending' : 'none',
            'uploaded_by' => $user->id,
        ]);
    }

    public function nextProjectNumber(): string
    {
        $year = now()->year;
        $prefix = $year.'-';
        $last = Project::query()
            ->where('project_number', 'like', $prefix.'%')
            ->orderByDesc('project_number')
            ->value('project_number');

        $sequence = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return sprintf('%s%03d', $prefix, $sequence);
    }

    /**
     * Publieke wrapper voor losse importroutes (screens/afmetingen) die al een opgeslagen pad hebben.
     *
     * @param  array<string, mixed>|null  $parsedJson
     */
    public function storeImportedFile(
        Project $project,
        string $absolutePath,
        string $type,
        string $originalName,
        User $user,
        string $parseStatus = 'none',
        ?array $parsedJson = null,
    ): ProjectDocument {
        return $this->storeImportedDocument(
            $project,
            $absolutePath,
            $type,
            $originalName,
            $user,
            $parseStatus,
            $parsedJson,
        );
    }

    /**
     * @param  array<string, mixed>|null  $parsedJson
     */
    private function storeImportedDocument(
        Project $project,
        string $absolutePath,
        string $type,
        string $originalName,
        User $user,
        string $parseStatus = 'none',
        ?array $parsedJson = null,
    ): ProjectDocument {
        $filename = Str::uuid()->toString().'.'.(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'pdf');
        $stored = 'projects/'.$project->id.'/'.$type.'/'.$filename;
        Storage::disk('local')->put($stored, (string) file_get_contents($absolutePath));

        return ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => $type,
            'original_filename' => $originalName,
            'file_path' => $stored,
            'mime_type' => $this->mimeFromPath($absolutePath),
            'file_size' => filesize($absolutePath) ?: null,
            'parse_status' => $parseStatus,
            'parsed_json' => $parsedJson,
            'uploaded_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  list<string>  $excludedWorks
     * @param  list<array{path: string, type: string, original: string}>  $extraDocuments
     */
    public function importPreview(
        array $preview,
        User $user,
        ?string $meetstaatPath = null,
        ?string $originalMeetstaatName = null,
        array $excludedWorks = [],
        ?string $plattegrondPath = null,
        ?string $originalPlattegrondName = null,
        array $extraDocuments = [],
    ): Project {
        return DB::transaction(function () use ($preview, $user, $meetstaatPath, $originalMeetstaatName, $excludedWorks, $plattegrondPath, $originalPlattegrondName, $extraDocuments) {
            $header = $preview['header'] ?? [];
            $customer = Customer::query()->firstOrCreate(
                ['name' => $header['customer_name'] ?: 'Onbekende klant']
            );

            $number = $header['project_number'] ?: $this->nextProjectNumber();
            if (Project::query()->where('project_number', $number)->exists()) {
                $number .= '-'.now()->format('His');
            }

            $project = Project::query()->create([
                'project_number' => $number,
                'customer_id' => $customer->id,
                'name' => filled($header['reference'] ?? null)
                    ? (string) $header['reference']
                    : ($header['project_name'] ?: 'Nieuw project'),
                'address' => $header['address'] ?? null,
                'postal_code' => $header['postal_code'] ?? null,
                'city' => $header['city'] ?? null,
                'supervisor_user_id' => $user->id,
                'planned_start_date' => $header['planned_start_date'] ?? $header['date'] ?? null,
                'planned_end_date' => $header['planned_end_date'] ?? null,
                'status' => ProjectStatus::Gepland,
                'notes' => filled($header['reference'] ?? null)
                    ? 'Referentie: '.$header['reference']
                    : (filled($header['project_name'] ?? null) ? 'Referentie: '.$header['project_name'] : null),
                'import_warnings' => $this->unresolvedImportWarnings($preview),
            ]);

            $excluded = array_map(fn ($name) => mb_strtolower($name), $excludedWorks);
            $workItems = [];
            $floors = [];

            foreach ($preview['works'] as $index => $work) {
                if (in_array(mb_strtolower($work['name']), $excluded, true)) {
                    continue;
                }
                $unit = $work['unit'] ?? WorkUnit::SquareMeter->value;
                if ($unit instanceof WorkUnit) {
                    $unit = $unit->value;
                }
                $item = WorkItem::query()->create([
                    'project_id' => $project->id,
                    'name' => $work['name'],
                    'display_color' => $this->workDisplayColor($work, $preview),
                    'unit' => $unit,
                    'ordered_quantity' => $work['calculated_total'] ?? 0,
                    'planned_start_date' => $project->planned_start_date,
                    'planned_end_date' => $project->planned_end_date,
                    'status' => 'gepland',
                    'sort_order' => $index + 1,
                ]);
                $this->rememberWorkItem($workItems, $item, $work);
            }

            foreach ($preview['areas'] as $areaIndex => $areaData) {
                $floorName = $areaData['floor'] ?? 'Onbekend';
                if (! isset($floors[$floorName])) {
                    $floors[$floorName] = ProjectFloor::query()->create([
                        'project_id' => $project->id,
                        'name' => $floorName,
                        'sort_order' => count($floors) + 1,
                    ]);
                }

                $area = ProjectArea::query()->create([
                    'project_id' => $project->id,
                    'project_floor_id' => $floors[$floorName]->id,
                    'area_number' => $areaData['room_number'] ?? null,
                    'name' => $areaData['room_name'] ?: ($areaData['room_number'] ?? 'Ruimte'),
                    'square_meters' => $areaData['square_meters'] ?? null,
                    'fill_color' => MaterialColor::normalizeHex($areaData['fill_color'] ?? null),
                    'status' => AreaStatus::NietGestart,
                    'sort_order' => $areaIndex + 1,
                ]);

                foreach ($areaData['tasks'] ?? [] as $task) {
                    if (in_array(mb_strtolower($task['work_name']), $excluded, true)) {
                        continue;
                    }
                    $item = $this->workItemForTask($workItems, (string) $task['work_name']);
                    if (! $item) {
                        continue;
                    }
                    $this->persistAreaTask($area, $item, $task);
                }
            }

            if ($meetstaatPath && is_file($meetstaatPath) && filled($originalMeetstaatName)) {
                $this->storeImportedDocument(
                    $project,
                    $meetstaatPath,
                    'meetstaat',
                    $originalMeetstaatName ?: basename($meetstaatPath),
                    $user,
                    'ok',
                    [
                        'header' => $header,
                        'works' => $preview['works'],
                    ],
                );
            }

            if ($plattegrondPath && is_file($plattegrondPath)) {
                $this->storeImportedDocument(
                    $project,
                    $plattegrondPath,
                    'plattegrond',
                    $originalPlattegrondName ?: basename($plattegrondPath),
                    $user,
                );
            }

            foreach ($extraDocuments as $document) {
                if (! is_file($document['path'])) {
                    continue;
                }
                $this->storeImportedDocument(
                    $project,
                    $document['path'],
                    $document['type'] !== '' ? $document['type'] : 'overig',
                    $document['original'] !== '' ? $document['original'] : basename($document['path']),
                    $user,
                );
            }

            $this->calculationImport->persist($project->load(['documents', 'workItems']), $preview);

            return $project->load(['documents', 'calculationLines', 'workItems']);
        });
    }

    public function rebuildWorksFromMeetstaat(Project $project): bool
    {
        $project->load(['documents', 'areas.floor', 'workItems.progressEntries', 'assignments.workItem']);

        $document = $project->documents->firstWhere('document_type', 'meetstaat');
        if (! $document?->file_path) {
            return false;
        }

        $path = Storage::disk('local')->path($document->file_path);
        if (! is_file($path)) {
            return false;
        }

        $preview = app(MeetstaatReader::class)->parseFile($path, $document->original_filename);
        $works = $preview['works'] ?? [];
        $hasRoomAsWork = collect($works)->contains(
            fn (array $work) => WorkType::looksLikeRoom((string) ($work['name'] ?? ''))
        );
        if (count($works) < 2 || $hasRoomAsWork) {
            return false;
        }

        $blockingProgress = $project->workItems->contains(
            fn (WorkItem $item) => $item->packageKey() !== 'ondergrond' && $item->progressEntries->isNotEmpty()
        );
        $blockingAssignments = $project->assignments->contains(
            fn ($assignment) => $assignment->workItem && $assignment->workItem->packageKey() !== 'ondergrond'
        );
        if ($blockingProgress || $blockingAssignments) {
            return false;
        }

        DB::transaction(function () use ($project, $preview, $works, $document) {
            $keepIds = $project->workItems
                ->filter(fn (WorkItem $item) => $item->packageKey() === 'ondergrond')
                ->pluck('id');

            AreaTask::query()
                ->whereIn('project_area_id', $project->areas()->pluck('id'))
                ->whereNotIn('work_item_id', $keepIds)
                ->delete();
            $project->workItems()->whereNotIn('id', $keepIds)->delete();

            $workItems = [];
            foreach ($works as $index => $work) {
                $unit = $work['unit'] ?? WorkUnit::SquareMeter->value;
                if ($unit instanceof WorkUnit) {
                    $unit = $unit->value;
                }
                $item = WorkItem::query()->create([
                    'project_id' => $project->id,
                    'name' => $work['name'],
                    'display_color' => $this->workDisplayColor($work, $preview),
                    'unit' => $unit,
                    'ordered_quantity' => $work['calculated_total'] ?? 0,
                    'planned_start_date' => $project->planned_start_date,
                    'planned_end_date' => $project->planned_end_date,
                    'status' => 'gepland',
                    'sort_order' => $index + 1,
                ]);
                $this->rememberWorkItem($workItems, $item, $work);
            }

            $areas = $project->areas()->with('floor')->get();
            $used = [];
            foreach ($preview['areas'] as $areaData) {
                $area = $this->matchExistingArea($areas, $areaData, $used);
                if ($area === null) {
                    continue;
                }
                $used[] = $area->id;
                $fill = MaterialColor::normalizeHex($areaData['fill_color'] ?? null);
                if ($fill !== null) {
                    $area->forceFill(['fill_color' => $fill])->save();
                }

                foreach ($areaData['tasks'] as $task) {
                    $item = $this->workItemForTask($workItems, (string) $task['work_name']);
                    if (! $item) {
                        continue;
                    }
                    $this->persistAreaTask($area, $item, $task);
                }
            }

            $document->parsed_json = [
                'header' => $preview['header'] ?? [],
                'works' => $works,
            ];
            $document->parse_status = 'ok';
            $document->save();
        });

        app(RoomWorkSetup::class)->ensureProject($project->fresh(['areas.tasks.workItem', 'workItems']));

        return true;
    }

    /**
     * @param  array<string, WorkItem>  $workItems
     * @param  array<string, mixed>  $work
     */
    private function rememberWorkItem(array &$workItems, WorkItem $item, array $work): void
    {
        $names = array_merge(
            [(string) $item->name],
            is_array($work['source_names'] ?? null) ? $work['source_names'] : [],
        );
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $workItems[$name] = $item;
        }
    }

    /**
     * @param  array<string, WorkItem>  $workItems
     */
    private function workItemForTask(array $workItems, string $taskName): ?WorkItem
    {
        $taskName = trim($taskName);
        if ($taskName === '') {
            return null;
        }
        if (isset($workItems[$taskName])) {
            return $workItems[$taskName];
        }
        foreach ($workItems as $name => $item) {
            if (strcasecmp((string) $name, $taskName) === 0) {
                return $item;
            }
        }
        $seen = [];
        foreach ($workItems as $item) {
            $id = spl_object_id($item);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($this->materialIdentity->sharesIdentity((string) $item->name, $taskName)
                && $this->materialIdentity->sameExecutionVariant((string) $item->name, $taskName)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function persistAreaTask(ProjectArea $area, WorkItem $item, array $task): void
    {
        $unit = $task['unit'] ?? $item->unit;
        $unitValue = $unit instanceof WorkUnit ? $unit->value : (string) $unit;
        $quantity = ($unitValue === WorkUnit::LinearMeter->value)
            ? (float) ($task['perimeter'] ?? 0)
            : (float) ($task['quantity'] ?? 0);
        $perimeter = (float) ($task['perimeter'] ?? 0);
        $seams = (float) ($task['seams'] ?? 0);

        $existing = AreaTask::query()
            ->where('project_area_id', $area->id)
            ->where('work_item_id', $item->id)
            ->first();

        if ($existing === null) {
            AreaTask::query()->create([
                'project_area_id' => $area->id,
                'work_item_id' => $item->id,
                'ordered_quantity' => $quantity,
                'perimeter' => $perimeter,
                'seams' => $seams,
                'unit' => $unitValue,
                'status' => AreaStatus::NietGestart,
            ]);

            return;
        }

        $existing->forceFill([
            'ordered_quantity' => round((float) $existing->ordered_quantity + $quantity, 2),
            'perimeter' => round((float) $existing->perimeter + $perimeter, 2),
            'seams' => round((float) $existing->seams + $seams, 2),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $work
     * @param  array<string, mixed>  $preview
     */
    private function workDisplayColor(array $work, array $preview): string
    {
        $name = (string) ($work['name'] ?? '');
        $isPlint = str_contains(mb_strtolower($name), 'plint');
        $plintHexes = [];
        foreach ($preview['legend'] ?? [] as $entry) {
            $material = trim((string) ($entry['material'] ?? $entry['name'] ?? ''));
            $color = MaterialColor::normalizeHex($entry['color'] ?? $entry['fill_color'] ?? null);
            if ($color !== null && str_contains(mb_strtolower($material), 'plint')) {
                $plintHexes[] = $color;
            }
        }

        foreach ($preview['legend'] ?? [] as $entry) {
            $material = trim((string) ($entry['material'] ?? $entry['name'] ?? ''));
            $color = MaterialColor::normalizeHex($entry['color'] ?? $entry['fill_color'] ?? null);
            if ($color === null || $material === '' || ! $this->materialIdentity->sharesIdentity($name, $material)) {
                continue;
            }
            if (! $isPlint && $this->hexIsPlintColor($color, $plintHexes)) {
                continue;
            }

            return MaterialColor::resolve($color, $name);
        }

        foreach ($preview['areas'] ?? [] as $area) {
            $fill = MaterialColor::normalizeHex($area['fill_color'] ?? null);
            if ($fill === null || (! $isPlint && $this->hexIsPlintColor($fill, $plintHexes))) {
                continue;
            }

            foreach ($area['tasks'] ?? [] as $task) {
                if ($this->materialIdentity->sharesIdentity($name, (string) ($task['work_name'] ?? ''))) {
                    return MaterialColor::resolve($fill, $name);
                }
            }
        }

        return MaterialColor::resolve(null, $name);
    }

    /**
     * @param  list<string>  $plintHexes
     */
    private function hexIsPlintColor(string $hex, array $plintHexes): bool
    {
        foreach ($plintHexes as $plintHex) {
            if (MaterialColor::hexesMatch($hex, $plintHex)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, ProjectArea>  $areas
     * @param  array<string, mixed>  $areaData
     * @param  list<int>  $used
     */
    private function matchExistingArea($areas, array $areaData, array $used): ?ProjectArea
    {
        $floor = mb_strtolower((string) ($areaData['floor'] ?? ''));
        $number = (string) ($areaData['room_number'] ?? '');
        $name = mb_strtolower((string) ($areaData['room_name'] ?? ''));

        return $areas->first(function (ProjectArea $area) use ($floor, $number, $name, $used) {
            if (in_array($area->id, $used, true)) {
                return false;
            }
            $sameFloor = mb_strtolower((string) $area->floor?->name) === $floor;
            $sameNumber = (string) $area->area_number === $number;
            $sameName = mb_strtolower((string) $area->name) === $name;

            return $sameFloor && ($number !== '' ? $sameNumber : $sameName);
        });
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<array<string, mixed>>|null
     */
    private function unresolvedImportWarnings(array $preview): ?array
    {
        $issues = array_values(array_filter(
            $preview['import_closure']['issues'] ?? [],
            function ($issue): bool {
                if (! is_array($issue)) {
                    return false;
                }

                return ($issue['severity'] ?? 'warning') === 'warning';
            }
        ));

        return $issues === [] ? null : $issues;
    }

    private function mimeFromPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'csv', 'txt' => 'text/csv',
            'xlsx', 'xlsm' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            default => 'application/octet-stream',
        };
    }
}
