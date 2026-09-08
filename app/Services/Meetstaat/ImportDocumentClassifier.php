<?php

namespace App\Services\Meetstaat;

use App\Enums\ImportDocumentType;
use App\Enums\ImportSourceRole;
use App\Services\Meetstaat\Formats\NiconMeetbonParser;
use Illuminate\Http\UploadedFile;

class ImportDocumentClassifier
{
    public function __construct(private PdfTextExtractor $extractor) {}

    /**
     * @return array{
     *     type: string,
     *     confidence: string,
     *     roles: list<string>,
     *     usable_data: string,
     *     reliability_label: string,
     *     type_label: string
     * }
     */
    public function classify(UploadedFile $file, ?string $hint = null): array
    {
        $hint = is_string($hint) ? mb_strtolower(trim($hint)) : '';
        if (in_array($hint, ImportDocumentType::values(), true)) {
            $base = [
                'type' => $hint,
                'confidence' => 'manual',
            ];

            return $this->withRoleAnalysis($base, $this->sampleText($file));
        }

        $fromName = $this->fromFilename($file->getClientOriginalName());
        $text = $this->sampleText($file);
        $fromContent = $this->fromText($text);

        if ($fromContent['confidence'] === 'high') {
            return $this->withRoleAnalysis($fromContent, $text);
        }
        if ($fromName['confidence'] === 'high') {
            return $this->withRoleAnalysis($fromName, $text);
        }
        if ($fromContent['type'] !== ImportDocumentType::Overig->value) {
            return $this->withRoleAnalysis($fromContent, $text);
        }

        return $this->withRoleAnalysis($fromName, $text);
    }

    /**
     * @param  array{type: string, confidence: string}  $classification
     * @param  array<string, mixed>|null  $parsed
     * @return array{
     *     type: string,
     *     confidence: string,
     *     roles: list<string>,
     *     usable_data: string,
     *     reliability_label: string,
     *     type_label: string
     * }
     */
    public function withRoleAnalysis(array $classification, string $text = '', ?array $parsed = null): array
    {
        $roles = $this->detectRoles($classification['type'], $text, $parsed);
        $usable = array_map(
            fn (string $role) => ImportSourceRole::from($role)->usableData(),
            $roles
        );

        return [
            'type' => $classification['type'],
            'confidence' => $classification['confidence'],
            'roles' => $roles,
            'usable_data' => $usable !== [] ? implode('; ', array_unique($usable)) : 'geen bruikbare importgegevens',
            'reliability_label' => $this->reliabilityLabel($classification['confidence'], $roles),
            'type_label' => ImportDocumentType::tryFrom($classification['type'])?->label() ?? 'Overig',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $parsed
     * @return list<string>
     */
    public function detectRoles(string $type, string $text = '', ?array $parsed = null): array
    {
        $roles = [];
        $flat = mb_strtolower($text);
        $areas = $parsed['areas'] ?? [];
        $works = $parsed['works'] ?? [];
        $legend = $parsed['legend'] ?? [];
        $hasAreas = $areas !== [];
        $hasRoomTasks = $this->parsedHasRoomLevelTasks($areas);
        $hasLegend = $legend !== [];
        $hasWorksTotals = $works !== [];

        if ($type === ImportDocumentType::Plattegrond->value || $this->looksLikeDrawing($flat, $parsed)) {
            $roles[] = ImportSourceRole::RoomSource->value;
            if ($hasLegend || $this->looksLikeLegend($flat)) {
                $roles[] = ImportSourceRole::DrawingLegendSource->value;
            }
        }

        if (
            $type === ImportDocumentType::Meetstaat->value
            || $hasRoomTasks
            || (new NiconMeetbonParser)->matches($text)
        ) {
            $roles[] = ImportSourceRole::TaskSource->value;
            if ($hasWorksTotals || preg_match('/\b(netto|bruto|totaal)\b/u', $flat)) {
                $roles[] = ImportSourceRole::MaterialTotalSource->value;
            }
        }

        if ($type === ImportDocumentType::Materialenstaat->value) {
            if ($hasRoomTasks) {
                $roles[] = ImportSourceRole::TaskSource->value;
            }
            $roles[] = ImportSourceRole::MaterialTotalSource->value;
        }

        if ($type === ImportDocumentType::Snijmaten->value || $this->looksLikeSnijmaten($flat)) {
            $roles[] = ImportSourceRole::MaterialRoomHintSource->value;
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return array{type: string, confidence: string}
     */
    public function fromFilename(string $name): array
    {
        $flat = mb_strtolower($name);
        $extension = pathinfo($flat, PATHINFO_EXTENSION);

        if (preg_match('/snijma(a)?t/', $flat)) {
            return ['type' => ImportDocumentType::Snijmaten->value, 'confidence' => 'high'];
        }
        if (preg_match('/materiallist|materialenstaat|materiaalstaat|materialen[_-]?lijst|materiaallijst/', $flat)) {
            return ['type' => ImportDocumentType::Materialenstaat->value, 'confidence' => 'high'];
        }
        if (preg_match('/plattegrond|tekening|floor.?plan|\bdrawing\b/', $flat)) {
            return ['type' => ImportDocumentType::Plattegrond->value, 'confidence' => 'high'];
        }
        if (preg_match('/afmetingen|matenlijst|handgeschreven/', $flat)) {
            return ['type' => ImportDocumentType::Afmetingen->value, 'confidence' => 'high'];
        }
        if (preg_match('/meetstaat|meetbon/', $flat)) {
            return ['type' => ImportDocumentType::Meetstaat->value, 'confidence' => 'high'];
        }
        if (in_array($extension, ['csv', 'xlsx', 'xls', 'xlsm', 'txt'], true)) {
            return ['type' => ImportDocumentType::Meetstaat->value, 'confidence' => 'low'];
        }

        return ['type' => ImportDocumentType::Overig->value, 'confidence' => 'low'];
    }

    /**
     * @return array{type: string, confidence: string}
     */
    public function fromText(string $text): array
    {
        $flat = mb_strtolower($text);
        if (mb_strlen(trim($text)) < 20) {
            return ['type' => ImportDocumentType::Overig->value, 'confidence' => 'low'];
        }

        if ((new NiconMeetbonParser)->matches($text)) {
            return ['type' => ImportDocumentType::Meetstaat->value, 'confidence' => 'high'];
        }

        if (preg_match('/snijma(a)?t|snijlijst|zaagmaat/', $flat)) {
            return ['type' => ImportDocumentType::Snijmaten->value, 'confidence' => 'high'];
        }
        if (preg_match('/materialenstaat|materiaalstaat|material\s*list/', $flat)) {
            return ['type' => ImportDocumentType::Materialenstaat->value, 'confidence' => 'high'];
        }

        $hasRooms = (bool) preg_match('/(?<![0-9])\d{1,2}[.\-]\d{1,3}[a-z]?(?![0-9.\-])/u', $flat);
        $hasSquareMeters = (bool) preg_match('/\d+(?:[.,]\d+)?\s*m(?:²|2)\b/u', $flat);
        $hasMeetstaatWords = str_contains($flat, 'meetstaat') || str_contains($flat, 'bouwlaag');
        $hasProduct = (bool) preg_match('/marmoleum|plint|linoleum|pvc|gietvloer|tapijt|vinyl|coral/', $flat);

        // Uitgebreide materiaallijst met per-ruimte m² = meetstaat/TASK_SOURCE, geen kale totalenlijst.
        if ($hasRooms && $hasSquareMeters && $hasProduct && $hasMeetstaatWords) {
            return ['type' => ImportDocumentType::Meetstaat->value, 'confidence' => 'high'];
        }

        if (preg_match('/\b(netto|bruto)\s*:/', $flat) && $hasProduct && ! $hasRooms) {
            return ['type' => ImportDocumentType::Materialenstaat->value, 'confidence' => 'high'];
        }

        if ($hasRooms && $hasSquareMeters && ! $hasMeetstaatWords) {
            return ['type' => ImportDocumentType::Plattegrond->value, 'confidence' => 'high'];
        }

        if ($hasRooms && $hasProduct && preg_match('/bouwlaag|begane grond|verdieping/', $flat)) {
            return ['type' => ImportDocumentType::Snijmaten->value, 'confidence' => 'low'];
        }

        if ($hasRooms && $hasSquareMeters) {
            return ['type' => ImportDocumentType::Plattegrond->value, 'confidence' => 'low'];
        }

        $looksLikeTable = (bool) preg_match('/\b(nummer|naam|verdieping|ruimte)\b.*\b(m2|m²|pvc|linoleum)\b/u', $flat)
            || (bool) preg_match('/\b(nummer|naam),/u', $flat);
        if ($looksLikeTable) {
            return ['type' => ImportDocumentType::Meetstaat->value, 'confidence' => 'high'];
        }

        return ['type' => ImportDocumentType::Overig->value, 'confidence' => 'low'];
    }

    private function sampleText(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (in_array($extension, ['csv', 'txt', 'xlsx', 'xls', 'xlsm'], true)) {
            return $this->spreadsheetSample($file);
        }
        if ($extension !== 'pdf') {
            return '';
        }

        $extracted = $this->extractor->extract($file->getRealPath());
        $text = $extracted['text'];
        if ($extracted['needs_ocr']) {
            $drawing = $this->extractor->extractDrawing($file->getRealPath());
            if (mb_strlen($drawing['text']) > mb_strlen($text)) {
                $text = $drawing['text'];
            }
        }

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function parsedHasRoomLevelTasks(array $areas): bool
    {
        foreach ($areas as $area) {
            $hasIdentity = filled($area['room_number'] ?? null) || filled($area['room_name'] ?? null);
            foreach ($area['tasks'] ?? [] as $task) {
                if ($hasIdentity && (float) ($task['quantity'] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $parsed
     */
    private function looksLikeDrawing(string $flat, ?array $parsed): bool
    {
        if (($parsed['legend'] ?? []) !== []) {
            return true;
        }

        return str_contains($flat, 'legenda') || str_contains($flat, 'schaal');
    }

    private function looksLikeLegend(string $flat): bool
    {
        return str_contains($flat, 'legenda')
            || (bool) preg_match('/\b(kleur|swatch|patroon)\b/u', $flat);
    }

    private function looksLikeSnijmaten(string $flat): bool
    {
        return (bool) preg_match('/snijma(a)?t|snijlengte|zaagmaat/u', $flat);
    }

    /**
     * @param  list<string>  $roles
     */
    private function reliabilityLabel(string $confidence, array $roles): string
    {
        if ($confidence === 'manual') {
            return 'Handmatig';
        }
        if ($confidence === 'high' && $roles !== []) {
            return 'Hoog';
        }
        if ($confidence === 'high') {
            return 'Hoog';
        }
        if ($roles !== []) {
            return 'Midden';
        }

        return 'Laag';
    }

    private function spreadsheetSample(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! is_string($path) || ! is_readable($path)) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $sample = (string) fread($handle, 4096);
        fclose($handle);

        return $sample;
    }
}
