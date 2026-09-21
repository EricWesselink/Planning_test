<?php

namespace App\Services\Meetstaat;

use App\Enums\ImportDocumentType;
use App\Enums\ImportSourceRole;
use App\Enums\WorkUnit;
use App\Services\MeetstaatParser;
use App\Services\SpreadsheetReader;
use Illuminate\Http\UploadedFile;

class ImportPreviewBuilder
{
    public function __construct(
        private ImportDocumentClassifier $classifier,
        private MeetstaatReader $meetstaatReader,
        private FloorPlanParser $floorPlan,
        private MaterialenstaatParser $materials,
        private SnijmatenParser $snijmaten,
        private MeetstaatParser $spreadsheetParser,
        private SpreadsheetReader $spreadsheetReader,
        private RoomImportAssembler $assembler,
        private PdfTextExtractor $extractor,
    ) {}

    /**
     * @param  list<array{file: UploadedFile, type: ?string}>  $uploads
     * @return array{
     *     preview: array<string, mixed>,
     *     classified: list<array{file: UploadedFile, type: string, confidence: string, original: string}>,
     *     ocrError: ?string
     * }
     */
    public function build(array $uploads): array
    {
        $classified = [];
        $sourceAnalysis = [];
        $meetstaat = null;
        $drawing = null;
        $materials = null;
        $snijmaten = null;
        $ocrError = null;
        $looseHeader = ProjectDocumentHeader::empty();

        foreach ($uploads as $upload) {
            $file = $upload['file'];
            $result = $this->classifier->classify($file, $upload['type'] ?? null);

            try {
                $parsed = $this->parseTyped($file, $result['type']);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException($file->getClientOriginalName().': '.$e->getMessage());
            }

            if ($parsed !== null) {
                $result = $this->classifier->withRoleAnalysis($result, '', $parsed);
            }

            $classified[] = [
                'file' => $file,
                'type' => $result['type'],
                'confidence' => $result['confidence'],
                'original' => $file->getClientOriginalName(),
                'roles' => $result['roles'],
                'usable_data' => $result['usable_data'],
                'reliability_label' => $result['reliability_label'],
                'type_label' => $result['type_label'],
            ];
            $sourceAnalysis[] = [
                'original' => $file->getClientOriginalName(),
                'type' => $result['type'],
                'type_label' => $result['type_label'],
                'roles' => $result['roles'],
                'roles_label' => implode(' + ', $result['roles']),
                'usable_data' => $result['usable_data'],
                'reliability_label' => $result['reliability_label'],
            ];

            if ($parsed === null) {
                $this->captureLooseHeader($looseHeader, $file);

                continue;
            }

            $bucket = $parsed['_bucket'] ?? $result['type'];
            unset($parsed['_bucket']);

            // Inhoud wint: ruimte-taken horen in de meetstaat-bucket, ook als de bestandsnaam "materiaallijst" is.
            if (
                $bucket === ImportDocumentType::Materialenstaat->value
                && in_array(ImportSourceRole::TaskSource->value, $result['roles'], true)
                && ($parsed['areas'] ?? []) !== []
            ) {
                $bucket = ImportDocumentType::Meetstaat->value;
            }

            if (in_array($bucket, [ImportDocumentType::Materialenstaat->value, ImportDocumentType::Snijmaten->value], true)) {
                $parsed = $this->stampAreaSource($parsed, $bucket);
            }

            match ($bucket) {
                ImportDocumentType::Meetstaat->value => $meetstaat = $this->mergeParsed($meetstaat, $parsed),
                ImportDocumentType::Plattegrond->value => $drawing = $this->mergeParsed($drawing, $parsed),
                ImportDocumentType::Materialenstaat->value => $materials = $this->mergeParsed($materials, $parsed),
                ImportDocumentType::Snijmaten->value => $snijmaten = $this->mergeParsed($snijmaten, $parsed),
                default => null,
            };

            if (
                $result['type'] === ImportDocumentType::Meetstaat->value
                && ($parsed['needs_ocr'] ?? false)
                && ($parsed['areas'] ?? []) === []
            ) {
                $ocrError = 'Deze PDF bevat geen leesbare tekst. OCR volgt later; gebruik tot die tijd Excel of CSV.';
            }
        }

        $preview = $this->assembler->assemble($meetstaat, $drawing, $materials, $snijmaten);
        $preview['header'] = $this->fillBlankHeader($preview['header'] ?? [], $looseHeader);
        $preview['source_analysis'] = $sourceAnalysis;
        $preview['source_priority_note'] = $this->sourcePriorityNote($preview['sources'] ?? [], $sourceAnalysis);
        if ($ocrError !== null && ($preview['areas'] ?? []) !== []) {
            $ocrError = null;
        }

        return [
            'preview' => $preview,
            'classified' => $classified,
            'ocrError' => $ocrError,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseTyped(UploadedFile $file, string $type): ?array
    {
        return match ($type) {
            ImportDocumentType::Meetstaat->value => $this->parseMeetstaat($file),
            ImportDocumentType::Plattegrond->value => $this->parseDrawing($file),
            ImportDocumentType::Materialenstaat->value => $this->parseMaterials($file),
            ImportDocumentType::Snijmaten->value => $this->parseSnijmaten($file),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMeetstaat(UploadedFile $file): array
    {
        if ($this->isSpreadsheet($file)) {
            return $this->spreadsheetAsMeetstaat($file);
        }

        $parsed = $this->meetstaatReader->parseFile($file->getRealPath(), $file->getClientOriginalName());
        if (($parsed['areas'] ?? []) === [] && ($parsed['format'] ?? null) === null) {
            $fallback = $this->floorPlan->parseFile($file->getRealPath(), $file->getClientOriginalName());
            if (($fallback['areas'] ?? []) !== []) {
                $fallback['_bucket'] = ImportDocumentType::Plattegrond->value;

                return $fallback;
            }
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDrawing(UploadedFile $file): array
    {
        if ($this->isSpreadsheet($file)) {
            return $this->spreadsheetAsMeetstaat($file);
        }

        return $this->floorPlan->parseFile($file->getRealPath(), $file->getClientOriginalName());
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMaterials(UploadedFile $file): array
    {
        if ($this->isSpreadsheet($file)) {
            return $this->spreadsheetAsMeetstaat($file);
        }

        $path = $file->getRealPath();
        $name = $file->getClientOriginalName();
        $extracted = $this->meetstaatReader->parseFile($path, $name);
        if (($extracted['format'] ?? null) !== null && ($extracted['areas'] ?? []) !== []) {
            $extracted['_bucket'] = ImportDocumentType::Meetstaat->value;

            return $extracted;
        }

        return $this->materials->parseFile($path, $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSnijmaten(UploadedFile $file): array
    {
        if ($this->isSpreadsheet($file)) {
            return $this->spreadsheetAsMeetstaat($file);
        }

        $parsed = $this->snijmaten->parseFile($file->getRealPath(), $file->getClientOriginalName());
        if (($parsed['areas'] ?? []) === []) {
            $fallback = $this->meetstaatReader->parseFile($file->getRealPath(), $file->getClientOriginalName());
            if (($fallback['areas'] ?? []) !== []) {
                return $fallback;
            }
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function spreadsheetAsMeetstaat(UploadedFile $file): array
    {
        $parsed = $this->spreadsheetParser->parse($this->spreadsheetReader->rows($file->getRealPath(), $file->getClientOriginalName()));
        $areas = [];
        foreach ($parsed['floors'] as $floor) {
            foreach ($floor['areas'] as $area) {
                $tasks = [];
                foreach ($area['tasks'] as $task) {
                    $unit = $task['unit'] instanceof WorkUnit ? $task['unit']->value : (string) $task['unit'];
                    $tasks[] = [
                        'work_name' => $task['work_name'],
                        'unit' => $unit,
                        'quantity' => (float) $task['quantity'],
                        'perimeter' => 0.0,
                        'seams' => 0.0,
                        'parts' => 1,
                    ];
                }
                $number = $area['area_number'] ?? null;
                $areas[] = [
                    'key' => mb_strtolower($floor['name']).'|'.mb_strtolower((string) ($number ?: $area['name'])),
                    'floor' => $floor['name'],
                    'room_number' => $number,
                    'room_name' => $area['name'],
                    'tasks' => $tasks,
                    'source' => ImportDocumentType::Meetstaat->value,
                ];
            }
        }

        return [
            'format' => 'spreadsheet',
            'header' => [
                'customer_name' => null,
                'reference' => null,
                'project_name' => null,
                'project_number' => null,
                'date' => null,
            ],
            'works' => [],
            'areas' => $areas,
            'floors' => array_column($parsed['floors'], 'name'),
            'warnings' => $parsed['warnings'] ?? [],
            'uncertain' => [],
            'duplicates' => [],
            'needs_ocr' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeParsed(?array $existing, array $incoming): array
    {
        if ($existing === null) {
            return $incoming;
        }

        $existing['areas'] = array_merge($existing['areas'] ?? [], $incoming['areas'] ?? []);
        $existing['works'] = array_merge($existing['works'] ?? [], $incoming['works'] ?? []);
        $existing['warnings'] = array_merge($existing['warnings'] ?? [], $incoming['warnings'] ?? []);
        $existing['uncertain'] = array_merge($existing['uncertain'] ?? [], $incoming['uncertain'] ?? []);
        $existing['floors'] = array_values(array_unique(array_merge($existing['floors'] ?? [], $incoming['floors'] ?? [])));
        foreach ($incoming['header'] ?? [] as $key => $value) {
            if (($existing['header'][$key] ?? null) === null && filled($value)) {
                $existing['header'][$key] = $value;
            }
        }
        if (($existing['format'] ?? null) === null) {
            $existing['format'] = $incoming['format'] ?? null;
        }
        $existing['needs_ocr'] = (bool) ($existing['needs_ocr'] ?? false) && (bool) ($incoming['needs_ocr'] ?? false);
        $existing['debug_rooms'] = array_merge($existing['debug_rooms'] ?? [], $incoming['debug_rooms'] ?? []);
        $existing['legend'] = array_merge($existing['legend'] ?? [], $incoming['legend'] ?? []);

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function stampAreaSource(array $parsed, string $source): array
    {
        foreach ($parsed['areas'] ?? [] as $index => $area) {
            $parsed['areas'][$index]['source'] = $source;
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function captureLooseHeader(array &$header, UploadedFile $file): void
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            return;
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            return;
        }

        $header = $this->fillBlankHeader($header, ProjectDocumentHeader::parseFromText($this->extractor->extract($path)['text']));
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function fillBlankHeader(array $header, array $fallback): array
    {
        foreach ($fallback as $key => $value) {
            if (blank($header[$key] ?? null) && filled($value)) {
                $header[$key] = $value;
            }
        }

        if (blank($header['project_name'] ?? null) && filled($header['reference'] ?? null)) {
            $header['project_name'] = (string) $header['reference'];
        }

        return $header;
    }

    private function isSpreadsheet(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt', 'xlsx', 'xls', 'xlsm'], true);
    }

    /**
     * @param  array<string, bool>  $sources
     * @param  list<array<string, mixed>>  $analysis
     */
    private function sourcePriorityNote(array $sources, array $analysis): string
    {
        $roles = [];
        foreach ($analysis as $row) {
            foreach ($row['roles'] ?? [] as $role) {
                $roles[$role] = true;
            }
        }

        if (! empty($roles[ImportSourceRole::TaskSource->value]) && ! empty($roles[ImportSourceRole::RoomSource->value])) {
            return 'Tekening levert fysieke ruimtes/posities; meetstaat/materiaaltaken leveren area_tasks. Legenda en MaterialList zijn controle, geen gokmateriaal.';
        }
        if (! empty($roles[ImportSourceRole::RoomSource->value]) && ! empty($roles[ImportSourceRole::MaterialRoomHintSource->value])) {
            return 'Plattegrond bepaalt fysieke ruimtes. Kleur/legenda + Snijmaten-hints bepalen materiaal per ruimte. MaterialList is projectbrede controle.';
        }
        if (! empty($sources['meetstaat'])) {
            return 'Meetstaat is leidend voor de netto m²/m¹ van het uit te voeren werk. Excel is calculatie/uren; Materialenstaat is materiaalcontrole; de plattegrond koppelt ruimtes.';
        }
        if (! empty($sources['plattegrond'])) {
            return 'De plattegrond bepaalt de fysieke ruimtes. Snijmaten en MaterialList mogen geen ruimtes of bouwlagen toevoegen; ze dienen alleen als controle/verrijking.';
        }

        return 'Zonder plattegrond of meetstaat maken Snijmaten en MaterialList geen fysieke ruimtes aan. Ruimte-m² en materiaal-m² blijven gescheiden.';
    }
}
