<?php

namespace App\Services\ScannedDimensions;

use App\Enums\AreaStatus;
use App\Enums\ProjectStatus;
use App\Enums\WorkUnit;
use App\Models\AreaTask;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\ProjectIntakeService;
use App\Services\RoomWorkSetup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Aparte importroute voor afmetingen-PDF (+ optionele plattegrond).
 * Raakt RoomImportAssembler / Meetstaat-vloerimport niet.
 */
class ScannedDimensionsImportService
{
    public function __construct(
        private ScannedDimensionsParser $parser,
        private PdfTextExtractor $extractor,
        private ScannedDimensionsPdfOcr $ocr,
        private ProjectIntakeService $intake,
    ) {}

    public function looksLikeAfmetingenFilename(string $name): bool
    {
        $flat = mb_strtolower($name);

        return (bool) preg_match('/afmetingen|matenlijst|handgeschreven/', $flat);
    }

    /**
     * @param  list<array{file: UploadedFile, type: ?string}>  $uploads
     * @return array{afmetingen: UploadedFile, plattegrond: ?UploadedFile}|null
     */
    public function findUploads(array $uploads): ?array
    {
        $afmetingen = null;
        $plattegrond = null;
        $hasFloorImportSource = false;

        foreach ($uploads as $upload) {
            $file = $upload['file'] ?? null;
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $type = is_string($upload['type'] ?? null) ? mb_strtolower(trim($upload['type'])) : '';
            $name = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension());

            if (in_array($type, ['meetstaat', 'materialenstaat', 'snijmaten'], true)) {
                $hasFloorImportSource = true;
            }
            if (preg_match('/meetstaat|meetbon|materiallist|materialenstaat|snijma(a)?t/', mb_strtolower($name))) {
                $hasFloorImportSource = true;
            }

            if ($extension !== 'pdf') {
                continue;
            }

            if ($type === 'afmetingen' || ($type === '' && $this->looksLikeAfmetingenFilename($name))) {
                $afmetingen ??= $file;

                continue;
            }

            if ($type === 'plattegrond' || preg_match('/plattegrond|tekening|floor.?plan|\bdrawing\b/', mb_strtolower($name))) {
                $plattegrond ??= $file;
            }
        }

        // Alleen deze aparte route wanneer er géén klassieke vloerbron meekomt.
        if ($afmetingen === null || $hasFloorImportSource) {
            return null;
        }

        return [
            'afmetingen' => $afmetingen,
            'plattegrond' => $plattegrond,
        ];
    }

    /**
     * @return array{
     *     rooms: list<array{room_number: string, name: string, quantity: float, bruto: ?float, unit: string, status: string}>,
     *     netto_total: float,
     *     bruto_total: ?float,
     *     snijverlies_pct: ?float,
     *     declared_netto_on_pdf: ?float,
     *     warnings: list<string>,
     *     engine: ?string,
     *     used_ocr: bool,
     *     ocr_attempted: bool,
     *     ocr_available: bool,
     *     recognition_failed: bool,
     *     recognition_message: ?string,
     *     drawing_labels: list<string>
     * }
     */
    public function parseFile(string $path, ?string $drawingPath = null): array
    {
        $extracted = $this->extractor->extract($path);
        $text = (string) ($extracted['text'] ?? '');
        $engine = $extracted['engine'] ?? null;
        $usedOcr = false;
        $ocrAttempted = false;
        $ocrAvailable = $this->ocr->isAvailable();

        $parsed = $this->parser->parse($text);

        // Tekstlaag ontbreekt of is onbruikbaar: OCR proberen. Leeg resultaat is geen fout.
        if (! $this->hasRecognizedNetto($parsed['rooms'])) {
            $ocrResult = $this->ocr->extractText($path);
            $ocrAttempted = (bool) ($ocrResult['attempted'] ?? false);
            $ocrAvailable = (bool) ($ocrResult['available'] ?? $ocrAvailable);
            $ocrText = (string) ($ocrResult['text'] ?? '');

            if ($ocrText !== '') {
                $ocrParsed = $this->parser->parse($ocrText);
                if ($ocrParsed['rooms'] !== [] || mb_strlen($ocrText) > mb_strlen($text)) {
                    $parsed = $ocrParsed;
                    $usedOcr = true;
                    $engine = $ocrResult['engine'] ?? 'tesseract';
                }
            }
        }

        $drawingLabels = [];
        $knownNumbers = array_values(array_filter(array_column($parsed['rooms'], 'room_number')));
        if (is_string($drawingPath) && is_file($drawingPath) && $knownNumbers !== []) {
            $drawingLabels = $this->resolveDrawingLabels($drawingPath, $knownNumbers);
            if ($drawingLabels === []) {
                $parsed['warnings'][] = 'Plattegrond geaccepteerd; geen betrouwbare ruimte-labels gekoppeld (positie blijft leeg).';
            }
        } elseif (is_string($drawingPath) && is_file($drawingPath)) {
            $parsed['warnings'][] = 'Plattegrond geaccepteerd; koppel ruimtenummers handmatig. Netto m² komt niet uit de plattegrond.';
        }

        $recognitionFailed = $parsed['rooms'] === [];

        return [
            ...$parsed,
            'engine' => $engine,
            'used_ocr' => $usedOcr,
            'ocr_attempted' => $ocrAttempted,
            'ocr_available' => $ocrAvailable,
            'recognition_failed' => $recognitionFailed,
            'recognition_message' => $recognitionFailed
                ? 'Afmetingen konden niet betrouwbaar automatisch worden herkend. Vul de ruimtes en netto m² hieronder handmatig in.'
                : null,
            'drawing_labels' => $drawingLabels,
        ];
    }

    /**
     * @param  list<array{quantity?: float|int|string|null}>  $rooms
     */
    private function hasRecognizedNetto(array $rooms): bool
    {
        foreach ($rooms as $room) {
            if ((float) ($room['quantity'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $knownRoomNumbers
     * @return list<string>
     */
    private function resolveDrawingLabels(string $drawingPath, array $knownRoomNumbers): array
    {
        $drawExtracted = $this->extractor->extract($drawingPath);
        $drawText = (string) ($drawExtracted['text'] ?? '');
        $labels = $this->parser->matchedDrawingLabels($drawText, $knownRoomNumbers);
        if ($labels !== []) {
            return $labels;
        }

        // Scans zonder tekstlaag: soft OCR; mislukking blokkeert de import niet.
        $ocrResult = $this->ocr->extractText($drawingPath);
        $ocrText = (string) ($ocrResult['text'] ?? '');
        if ($ocrText === '') {
            return [];
        }

        return $this->parser->matchedDrawingLabels($ocrText, $knownRoomNumbers);
    }

    /**
     * @param  array{
     *     customer_name: string,
     *     name: string,
     *     address?: ?string,
     *     postal_code?: ?string,
     *     city?: ?string,
     *     project_number?: ?string
     * }  $data
     * @param  array{
     *     rooms: list<array{room_number: string, quantity: float, unit: string}>,
     *     netto_total: float,
     *     warnings?: list<string>
     * }  $parsed
     * @return array{project: Project, warnings: list<string>, rooms: int, works: int}
     */
    public function createProject(
        array $data,
        array $parsed,
        string $workType,
        User $user,
        ?string $afmetingenPath = null,
        ?string $afmetingenOriginal = null,
        ?string $plattegrondPath = null,
        ?string $plattegrondOriginal = null,
    ): array {
        $workType = trim($workType);
        if ($workType === '') {
            throw new \InvalidArgumentException('Kies een werksoort.');
        }
        if (($parsed['rooms'] ?? []) === []) {
            throw new \InvalidArgumentException('Voeg minstens één ruimte met netto m² toe.');
        }

        return DB::transaction(function () use (
            $data,
            $parsed,
            $workType,
            $user,
            $afmetingenPath,
            $afmetingenOriginal,
            $plattegrondPath,
            $plattegrondOriginal,
        ) {
            $customer = Customer::query()->firstOrCreate(
                ['name' => $data['customer_name']],
                ['city' => $data['city'] ?? null]
            );

            $project = Project::query()->create([
                'project_number' => ($data['project_number'] ?? null) ?: $this->intake->nextProjectNumber(),
                'customer_id' => $customer->id,
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'city' => $data['city'] ?? null,
                'supervisor_user_id' => $user->id,
                'planned_start_date' => $data['planned_start_date'] ?? null,
                'planned_end_date' => $data['planned_end_date'] ?? null,
                'status' => ProjectStatus::Gepland,
            ]);

            if (is_string($afmetingenPath) && is_file($afmetingenPath)) {
                $this->intake->storeImportedFile(
                    $project,
                    $afmetingenPath,
                    'afmetingen',
                    $afmetingenOriginal ?: basename($afmetingenPath),
                    $user,
                    'ok',
                    $parsed,
                );
            }

            if (is_string($plattegrondPath) && is_file($plattegrondPath)) {
                $this->intake->storeImportedFile(
                    $project,
                    $plattegrondPath,
                    'plattegrond',
                    $plattegrondOriginal ?: basename($plattegrondPath),
                    $user,
                );
            }

            $created = $this->applyParsed($project, $parsed, $workType);
            app(RoomWorkSetup::class)->ensureProject($project->fresh(['areas.tasks.workItem', 'workItems']));

            return [
                'project' => $project,
                'warnings' => $parsed['warnings'] ?? [],
                'rooms' => $created['rooms'],
                'works' => $created['works'],
            ];
        });
    }

    /**
     * @param  array{rooms: list<array{room_number?: string, name?: string, quantity: float, work_type?: string, unit?: string}>}  $parsed
     * @return array{rooms: int, works: int}
     */
    public function applyParsed(Project $project, array $parsed, string $workType): array
    {
        $floor = ProjectFloor::query()->firstOrCreate(
            ['project_id' => $project->id, 'name' => 'Begane grond'],
            ['sort_order' => 1]
        );

        $workItems = [];
        $workTotals = [];
        $sort = 0;
        $rooms = 0;

        foreach ($parsed['rooms'] as $room) {
            $qty = round((float) $room['quantity'], 2);
            if ($qty <= 0) {
                continue;
            }

            $rowWorkType = trim((string) ($room['work_type'] ?? $workType));
            if ($rowWorkType === '') {
                $rowWorkType = $workType;
            }
            if ($rowWorkType === '') {
                continue;
            }

            if (! isset($workItems[$rowWorkType])) {
                $sort++;
                $workItems[$rowWorkType] = WorkItem::query()->create([
                    'project_id' => $project->id,
                    'name' => $rowWorkType,
                    'unit' => WorkUnit::SquareMeter->value,
                    'ordered_quantity' => 0,
                    'planned_start_date' => $project->planned_start_date,
                    'planned_end_date' => $project->planned_end_date,
                    'status' => 'gepland',
                    'sort_order' => $sort,
                ]);
                $workTotals[$rowWorkType] = 0.0;
            }

            [$number, $name] = $this->areaIdentity($room);
            $area = ProjectArea::query()->create([
                'project_id' => $project->id,
                'project_floor_id' => $floor->id,
                'area_number' => $number,
                'name' => $name,
                'square_meters' => $qty,
                'status' => AreaStatus::NietGestart,
                'sort_order' => $rooms + 1,
            ]);
            AreaTask::query()->create([
                'project_area_id' => $area->id,
                'work_item_id' => $workItems[$rowWorkType]->id,
                'ordered_quantity' => $qty,
                'quantity_source' => 'afmetingen',
                'unit' => WorkUnit::SquareMeter->value,
                'status' => AreaStatus::NietGestart,
            ]);
            $workTotals[$rowWorkType] += $qty;
            $rooms++;
        }

        foreach ($workItems as $name => $workItem) {
            $workItem->ordered_quantity = round($workTotals[$name], 2);
            $workItem->save();
        }

        return ['rooms' => $rooms, 'works' => count($workItems)];
    }

    /**
     * @param  array{room_number?: string, name?: string, label?: string}  $room
     * @return array{0: string, 1: string}
     */
    private function areaIdentity(array $room): array
    {
        $number = trim((string) ($room['room_number'] ?? ''));
        $name = trim((string) ($room['name'] ?? $room['label'] ?? ''));
        if ($number === '' && preg_match('/^ruimte\s*([0-9]{1,3}[a-zA-Z]?)$/iu', $name, $match) === 1) {
            $number = mb_strtoupper($match[1]);
        }
        if ($name === '') {
            $name = $number !== '' ? 'Ruimte '.$number : 'Ruimte';
        }
        if ($number === '') {
            $number = $name;
        }

        return [$number, $name];
    }
}
