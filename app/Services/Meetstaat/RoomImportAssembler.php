<?php

namespace App\Services\Meetstaat;

use App\Enums\ImportDecision;
use App\Enums\WorkUnit;
use App\Support\DutchNumber;
use App\Support\WorkType;

class RoomImportAssembler
{
    /**
     * @var array{
     *     duplicate_rooms_removed: int,
     *     duplicate_task_meters_removed: float,
     *     wrong_merge_prevented_meters: float,
     *     wrong_merge_rooms_kept_separate: int
     * }
     */
    private array $safeFixStats = [
        'duplicate_rooms_removed' => 0,
        'duplicate_task_meters_removed' => 0.0,
        'wrong_merge_prevented_meters' => 0.0,
        'wrong_merge_rooms_kept_separate' => 0,
        'ocr_meter_matches' => 0,
        'implausible_numbers_cleared' => 0,
        'floor_reassignments' => 0,
        'floor_reassignment_meters' => 0.0,
        'floor_reassignment_log' => [],
        'removed_duplicates' => [],
        'suppressed_drawing_tasks' => [],
        'suppressed_drawing_task_meters' => 0.0,
    ];

    /**
     * @param  array<string, mixed>|null  $meetstaat
     * @param  array<string, mixed>|null  $drawing
     * @param  array<string, mixed>|null  $materials
     * @param  array<string, mixed>|null  $snijmaten
     * @return array<string, mixed>
     */
    public function assemble(?array $meetstaat, ?array $drawing, ?array $materials = null, ?array $snijmaten = null): array
    {
        // Physical rooms come from meetstaat and/or plattegrond only.
        // Snijmaten and MaterialList never create rooms or floors.
        $this->resetSafeFixStats();
        $roomAreas = $meetstaat['areas'] ?? [];
        $drawingAreas = $drawing['areas'] ?? [];
        $duplicatesRemoved = (int) ($drawing['duplicates_removed'] ?? 0);
        $meetstaatIsTaskSource = $this->areasProvideFlooringTasks($roomAreas);

        $areas = $this->mergeAreas($roomAreas, $drawingAreas, $meetstaatIsTaskSource);
        $areas = $this->dropPlinthSquareMeterTasks($areas);
        $areas = $this->recoverUnknownFloors($areas, $roomAreas, $drawingAreas);
        $areas = $this->enrichExistingRooms($areas, $snijmaten['areas'] ?? [], 'snijmaten');
        $areas = $this->applyLegendTotals($areas, $drawing['legend'] ?? []);
        $areas = $this->annotateMaterialReview($areas, $drawing['legend'] ?? []);
        $areas = $this->collapseDuplicateTasks($areas);
        $areas = $this->collapseGhostDuplicateRooms($areas);
        $areas = $this->resolveCanonicalTaskMaterials(
            $areas,
            array_merge($materials['works'] ?? [], $meetstaat['works'] ?? []),
        );

        // Expected taak-m²: met meetstaat als TASK_SOURCE is meetstaat declared leidend.
        // MaterialList Netto mag die totalen niet via MAX opblazen (Rova PU 897 vs 448).
        $expectedSourceWorks = $this->mergeExpectedTaskSourceWorks(
            $materials['works'] ?? [],
            $meetstaat['works'] ?? [],
            $meetstaatIsTaskSource,
        );
        $expectedTaskTotals = $this->buildExpectedTaskTotals(
            $expectedSourceWorks,
            $meetstaatIsTaskSource ? $areas : ($meetstaat['areas'] ?? []),
        );

        $works = $this->mergeWorks(array_merge(
            $meetstaat['works'] ?? [],
            $materials['works'] ?? [],
            $snijmaten['works'] ?? [],
            $drawing['works'] ?? [],
        ), $areas);
        $works = $this->applyExpectedDeclaredTotals($works, $expectedTaskTotals);

        $headerResolution = $this->resolveProjectHeader(
            $materials['header'] ?? null,
            $meetstaat['header'] ?? null,
            $snijmaten['header'] ?? null,
            $drawing['header'] ?? null,
        );
        $header = $headerResolution['header'];
        $headerMismatches = $headerResolution['mismatches'];

        $warnings = array_values(array_filter(array_merge(
            $meetstaat['warnings'] ?? [],
            $drawing['warnings'] ?? [],
            $materials['warnings'] ?? [],
            $snijmaten['warnings'] ?? [],
            $this->englishOakWarnings($materials['works'] ?? [], $areas, $drawing['legend'] ?? []),
            array_map(
                fn (array $row): string => $row['message'].': '.$row['source'].' heeft '.$row['label'].' "'.$row['other'].'" (Materialenstaat: "'.$row['primary'].'")',
                $headerMismatches,
            ),
        )));
        $uncertain = array_values(array_filter(array_merge(
            $meetstaat['uncertain'] ?? [],
            $drawing['uncertain'] ?? [],
            $materials['uncertain'] ?? [],
            $snijmaten['uncertain'] ?? [],
        )));
        $uncertain = $this->dropResolvedUnknownFloorWarnings($uncertain, $areas);

        $floors = [];
        foreach ($areas as $area) {
            $floors[$area['floor'] ?? 'Onbekend'] = true;
        }

        $legend = $this->legendSummary($drawing['legend'] ?? [], $areas);
        $materialCheck = $this->materialListCheck(
            $materials['works'] ?? [],
            $areas,
            $legend,
            $meetstaat['works'] ?? [],
            $meetstaatIsTaskSource,
        );
        $drawingStyle = $drawing['drawing_style']
            ?? (new DrawingStyleAssessor)->assess($areas, $drawing['legend'] ?? []);
        $importReport = $this->importReport(
            $areas,
            $duplicatesRemoved,
            $legend,
            $materialCheck,
            $drawingStyle,
            $works,
            $meetstaat['areas'] ?? [],
            $expectedTaskTotals,
        );

        $preview = [
            'format' => $meetstaat['format'] ?? $materials['format'] ?? $snijmaten['format'] ?? ($drawing['format'] ?? null),
            'header' => $header,
            'project_header_mismatches' => $headerMismatches,
            'works' => $works,
            'areas' => $areas,
            'floors' => array_keys($floors),
            'warnings' => $warnings,
            'uncertain' => $uncertain,
            'duplicates' => $meetstaat['duplicates'] ?? [],
            'needs_ocr' => (bool) (($meetstaat['needs_ocr'] ?? false) && $areas === []),
            'engine' => $meetstaat['engine'] ?? $drawing['engine'] ?? $materials['engine'] ?? $snijmaten['engine'] ?? null,
            'sources' => [
                'meetstaat' => $meetstaat !== null,
                'plattegrond' => $drawing !== null,
                'materialenstaat' => $materials !== null,
                'snijmaten' => $snijmaten !== null,
                'kleur' => ($drawing['legend'] ?? []) !== [],
            ],
            'legend' => $legend,
            'material_check' => $materialCheck,
            'expected_task_totals' => $expectedTaskTotals,
            'import_report' => $importReport,
            'drawing_style' => $drawingStyle,
            'debug_rooms' => $drawing['debug_rooms'] ?? [],
            'closure_baselines' => [
                'meetstaat_areas' => $meetstaat['areas'] ?? [],
                'meetstaat_works' => $meetstaat['works'] ?? [],
                'material_works' => $materials['works'] ?? [],
                'expected_source_works' => array_values($expectedSourceWorks),
            ],
        ];

        $preview = (new ImportClosureEvaluator)->attach($preview);

        // Eindstatus moet consistent zijn met IMPORTCONTROLE (geen 100% én "Onvolledig").
        if ($preview['import_closure']['ready'] ?? false) {
            $preview['import_report']['incomplete_recognition'] = false;
            $preview['import_report']['quality_label'] = ($preview['import_closure']['decision'] ?? '') === ImportDecision::ReadyWithWarnings->value
                ? 'Importeren toegestaan met waarschuwingen'
                : 'Import gereed';
        } elseif (($preview['import_closure']['decision'] ?? '') === ImportDecision::TechnicalError->value) {
            $preview['import_report']['incomplete_recognition'] = true;
            $preview['import_report']['quality_label'] = 'Technische fout – importeren geblokkeerd';
        }

        return $preview;
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyPreview(): array
    {
        return $this->assemble(null, null);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $excludedWorks
     * @return array<string, mixed>
     */
    public function applyReview(array $preview, array $rows, array $excludedWorks = []): array
    {
        $excluded = array_map(fn ($name) => mb_strtolower(trim((string) $name)), $excludedWorks);
        $areas = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $number = trim((string) ($row['room_number'] ?? ''));
            $name = trim((string) ($row['room_name'] ?? ''));
            if ($number === '' && $name === '') {
                continue;
            }

            $tasks = [];
            foreach ($row['tasks'] ?? [] as $task) {
                if (! is_array($task)) {
                    continue;
                }
                $workName = trim((string) ($task['work_name'] ?? ''));
                if ($workName === '' || in_array(mb_strtolower($workName), $excluded, true)) {
                    continue;
                }
                $unit = (string) ($task['unit'] ?? WorkUnit::SquareMeter->value);
                $tasks[] = [
                    'work_name' => $workName,
                    'unit' => $unit,
                    'quantity' => DutchNumber::parse($task['quantity'] ?? null) ?? 0.0,
                    'perimeter' => DutchNumber::parse($task['perimeter'] ?? null) ?? 0.0,
                    'seams' => DutchNumber::parse($task['seams'] ?? null) ?? 0.0,
                    'parts' => 1,
                ];
            }

            $source = (string) ($row['source'] ?? 'handmatig');
            if (! in_array($source, ['meetstaat', 'plattegrond', 'beide', 'handmatig', 'materialenstaat', 'snijmaten', 'gecombineerd'], true)) {
                $source = 'handmatig';
            }

            $squareMeters = DutchNumber::parse($row['square_meters'] ?? null);
            $floor = trim((string) ($row['floor'] ?? '')) ?: 'Onbekend';
            $via = $row['recognized_via'] ?? [];
            if (is_string($via)) {
                $via = array_values(array_filter(array_map('trim', explode(',', $via))));
            }
            $confidence = (string) ($row['confidence'] ?? '');
            if ($confidence === '') {
                $confidence = 'midden';
            }
            $areas[] = [
                'key' => mb_strtolower($floor).'|'.$this->normalizeNumber($number !== '' ? $number : $name),
                'floor' => $floor,
                'room_number' => $number !== '' ? $number : null,
                'room_name' => $name !== '' ? $name : ($number !== '' ? $number : 'Ruimte'),
                'square_meters' => $squareMeters,
                'tasks' => $tasks,
                'source' => $source,
                'source_label' => $this->sourceLabel($source),
                'needs_review' => $confidence === 'controleren',
                'fill_color' => $row['fill_color'] ?? null,
                'recognized_via' => is_array($via) ? $via : [],
                'recognized_via_label' => $this->recognizedViaLabel(is_array($via) ? $via : [], $source),
                'confidence' => $confidence,
                'confidence_label' => $this->confidenceLabel($confidence),
            ];
        }

        $preview['areas'] = $areas;
        $preview['works'] = $this->mergeWorks($preview['works'] ?? [], $areas);
        $preview['floors'] = array_values(array_unique(array_map(fn (array $area) => $area['floor'], $areas)));

        return $this->refreshClosure($preview);
    }

    /**
     * Vergelijk sterke TASK_SOURCE-vloermeters tussen twee previews (of baseline vs areas).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function taskSourceLossMeters(array $before, array $after): float
    {
        $parsed = $this->sumFlooringTaskMeters(
            $before['closure_baselines']['meetstaat_areas']
                ?? $before['areas']
                ?? []
        );
        $kept = $this->sumFlooringTaskMeters($after['areas'] ?? []);

        return round(max(0.0, $parsed - $kept), 2);
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    public function sumFlooringTaskMeters(array $areas): float
    {
        $sum = 0.0;
        foreach ($areas as $area) {
            if (! is_array($area)) {
                continue;
            }
            $sum += $this->flooringTaskMeters($area);
        }

        return round($sum, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, float>
     */
    public function flooringTaskMetersByFloor(array $areas): array
    {
        $buckets = [];
        foreach ($areas as $area) {
            if (! is_array($area)) {
                continue;
            }
            $floor = (string) ($area['floor'] ?? 'Onbekend');
            $buckets[$floor] = ($buckets[$floor] ?? 0.0) + $this->flooringTaskMeters($area);
        }
        foreach ($buckets as $floor => $meters) {
            $buckets[$floor] = round($meters, 2);
        }

        return $buckets;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, float>
     */
    public function flooringTaskMetersByMaterial(array $areas): array
    {
        $buckets = [];
        foreach ($areas as $area) {
            if (! is_array($area)) {
                continue;
            }
            foreach ($area['tasks'] ?? [] as $task) {
                if (! is_array($task) || ! $this->isFlooringArea($task)) {
                    continue;
                }
                $name = trim((string) ($task['work_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $buckets[$name] = ($buckets[$name] ?? 0.0) + (float) ($task['quantity'] ?? 0);
            }
        }
        foreach ($buckets as $name => $meters) {
            $buckets[$name] = round($meters, 2);
        }

        return $buckets;
    }

    /**
     * Herbereken import_report + import_closure na handmatige controlewijzigingen.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    public function refreshClosure(array $preview): array
    {
        $baselines = is_array($preview['closure_baselines'] ?? null) ? $preview['closure_baselines'] : [];
        $areas = array_values(array_filter($preview['areas'] ?? [], fn ($area) => is_array($area)));
        $works = array_values(array_filter($preview['works'] ?? [], fn ($work) => is_array($work)));
        $legend = array_values(array_filter($preview['legend'] ?? [], fn ($row) => is_array($row)));
        $materialWorks = array_values(array_filter($baselines['material_works'] ?? [], fn ($work) => is_array($work)));
        $meetstaatAreas = array_values(array_filter($baselines['meetstaat_areas'] ?? [], fn ($area) => is_array($area)));
        $meetstaatWorks = array_values(array_filter($baselines['meetstaat_works'] ?? [], fn ($work) => is_array($work)));
        $expectedSourceWorks = array_values(array_filter($baselines['expected_source_works'] ?? [], fn ($work) => is_array($work)));
        if ($expectedSourceWorks === []) {
            $meetstaatIsTaskSource = $this->areasProvideFlooringTasks($meetstaatAreas);
            $expectedSourceWorks = array_values($this->mergeExpectedTaskSourceWorks(
                $materialWorks,
                $meetstaatWorks !== [] ? $meetstaatWorks : array_values(array_filter(
                    $preview['works'] ?? [],
                    fn ($work) => is_array($work) && (float) ($work['declared_total'] ?? 0) > 0
                )),
                $meetstaatIsTaskSource,
            ));
        }
        $expectedTaskTotals = $this->buildExpectedTaskTotals(
            $this->mergeDeclaredWorks($expectedSourceWorks),
            $meetstaatAreas
        );
        $materialCheck = $this->materialListCheck(
            $materialWorks,
            $areas,
            $legend,
            $meetstaatWorks,
            $this->areasProvideFlooringTasks($meetstaatAreas),
        );
        $drawingStyle = is_array($preview['drawing_style'] ?? null) ? $preview['drawing_style'] : [];

        $preview['expected_task_totals'] = $expectedTaskTotals;
        $preview['material_check'] = $materialCheck;
        $preview['import_report'] = $this->importReport(
            $areas,
            (int) ($preview['import_report']['duplicates_removed'] ?? 0),
            $legend,
            $materialCheck,
            $drawingStyle,
            $works,
            $meetstaatAreas,
            $expectedTaskTotals,
        );

        return (new ImportClosureEvaluator)->attach($preview);
    }

    /**
     * @param  list<array<string, mixed>>  $meetstaatAreas
     * @param  list<array<string, mixed>>  $drawingAreas
     * @return list<array<string, mixed>>
     */
    private function mergeAreas(array $meetstaatAreas, array $drawingAreas, bool $meetstaatIsTaskSource = false): array
    {
        $drawingAvailable = $drawingAreas !== [];
        $resolver = new RoomIdentityResolver;
        $drawingAreas = array_map(function (array $area) {
            $area = $this->splitGluedRoomIdentity($area);

            return $this->applyFloorNumberPlausibility($area);
        }, $drawingAreas);
        $drawingAreas = $this->reassignDrawingSubfloors($drawingAreas, $meetstaatAreas);
        $meetstaatAreas = $this->recoverUnknownFloors($meetstaatAreas, $meetstaatAreas, $drawingAreas);
        $drawingAreas = $this->recoverUnknownFloors($drawingAreas, $meetstaatAreas, $drawingAreas);
        $usedDrawing = [];
        $merged = [];

        foreach ($meetstaatAreas as $meetstaatIndex => $area) {
            $physical = $this->physicalFromMeetstaat($area, $drawingAvailable);
            $origin = $this->roomOrigin($area);
            $squareMeters = $physical['square_meters'];
            $needsReview = false;
            if ($squareMeters === null && $origin !== 'meetstaat' && ($area['square_meters'] ?? null) !== null) {
                $squareMeters = $area['square_meters'];
                $needsReview = (bool) ($area['needs_review'] ?? false);
            }
            if ($origin === 'meetstaat' && ($area['needs_review'] ?? false) && filled($area['material_conflict'] ?? null)) {
                $needsReview = true;
            }
            $row = [
                'key' => $area['key'] ?? $this->areaKey($area),
                'floor' => $area['floor'] ?? 'Onbekend',
                'room_number' => $area['room_number'] ?? null,
                'room_name' => $area['room_name'] ?? 'Ruimte',
                'square_meters' => $squareMeters,
                'derived_square_meters' => $physical['derived_square_meters'] ?? null,
                'square_meters_source' => $physical['square_meters_source'] ?? null,
                'tasks' => $area['tasks'] ?? [],
                'source' => $origin,
                'needs_review' => $needsReview,
                'fill_color' => $area['fill_color'] ?? null,
                'recognized_via' => $area['recognized_via'] ?? [$origin],
                'confidence' => $area['confidence'] ?? null,
                'page' => $area['page'] ?? null,
                'keep_separate' => $area['keep_separate'] ?? false,
            ];

            $hit = $resolver->match($area, $drawingAreas, $usedDrawing);
            if ($hit !== null && $this->drawingMatchHasCompetingHosts($area, $hit['area'], $meetstaatAreas, $meetstaatIndex)) {
                $hit = null;
            }
            if ($hit !== null) {
                $usedDrawing[$hit['index']] = true;
                $draw = $hit['area'];
                $evidence = $hit['evidence'];
                $row['source'] = $origin === 'meetstaat' ? 'beide' : 'gecombineerd';
                if ($this->floorLabel()->isUnknown((string) ($row['floor'] ?? ''))
                    && ! $this->floorLabel()->isUnknown((string) ($draw['floor'] ?? ''))) {
                    $row['floor_reassigned_from'] = $row['floor'] ?? 'Onbekend';
                    $row['floor'] = $draw['floor'];
                    $row['floor_reassignment_reason'] = 'bouwlaag hersteld uit tekeningpagina';
                }
                if (($row['room_name'] === '' || $row['room_name'] === ($row['room_number'] ?? '')) && ($draw['room_name'] ?? '') !== '') {
                    $row['room_name'] = $draw['room_name'];
                }
                $drawingMeters = $draw['square_meters'] ?? null;
                if ($drawingMeters !== null) {
                    $row['square_meters'] = $drawingMeters;
                    $row['square_meters_source'] = 'plattegrond';
                    $row['needs_review'] = false;
                }
                if ($meetstaatIsTaskSource && $this->areaHasFlooringTasks($row)) {
                    $this->recordSuppressedDrawingTasks($row, $draw['tasks'] ?? [], 'meetstaat_task_source');
                    $row['tasks'] = $this->appendUniqueTasks($row['tasks'] ?? [], $this->nonFlooringTasks($draw['tasks'] ?? []));
                } else {
                    $row['tasks'] = $this->appendUniqueTasks($row['tasks'] ?? [], $draw['tasks'] ?? []);
                }
                $row['fill_color'] = $draw['fill_color'] ?? $row['fill_color'] ?? null;
                $row['legend_material'] = $draw['legend_material'] ?? $row['legend_material'] ?? null;
                $row['recognized_via'] = $this->mergeVia($row['recognized_via'] ?? [$origin], $draw['recognized_via'] ?? ['tekening']);
                $row['match_evidence'] = $evidence;
                $row['auto_match_reason'] = (string) ($evidence['reason'] ?? 'combined_evidence');
                $row['needs_review'] = false;
                $row['confidence'] = 'hoog';
                $row['page'] = $draw['page'] ?? $row['page'] ?? null;
                foreach (['x', 'y', 'meter_x', 'meter_y', 'contour_id'] as $geoKey) {
                    if (($row[$geoKey] ?? null) === null && ($draw[$geoKey] ?? null) !== null) {
                        $row[$geoKey] = $draw[$geoKey];
                    }
                }
                if (($draw['floor_reassignment_reason'] ?? null) !== null) {
                    $row['floor_reassignment_reason'] = $draw['floor_reassignment_reason'];
                    $row['floor_reassigned_from'] = $draw['floor_reassigned_from'] ?? null;
                }
                if (empty($evidence['signals']['exact_number'])
                    && (! empty($evidence['signals']['name']) || ! empty($evidence['signals']['normalized_number']))
                    && ! empty($evidence['signals']['meters'])) {
                    $this->safeFixStats['ocr_meter_matches']++;
                } elseif (! empty($evidence['signals']['exact_number'])
                    && ! empty($evidence['signals']['meters'])
                    && $this->roomLabelsConflict((string) ($area['room_name'] ?? ''), (string) ($draw['room_name'] ?? ''))) {
                    // Exact nummer + m² overbrugt OCR-naamschade.
                    $this->safeFixStats['ocr_meter_matches']++;
                }
            }

            if (($row['confidence'] ?? null) === null || ($row['confidence'] ?? '') === '') {
                $row['confidence'] = ($row['source'] === 'beide' || $this->areaHasFlooringTasks($row))
                    ? (($row['square_meters'] ?? null) !== null ? 'hoog' : 'midden')
                    : 'midden';
            }

            $row['source_label'] = $this->sourceLabel($row['source']);
            $merged[] = $this->withRecognition($row);
        }

        foreach ($drawingAreas as $index => $area) {
            if (isset($usedDrawing[$index])) {
                continue;
            }

            // Tweede pass: koppel tekeningrest via naam/m²/geometrie aan meetstaat-host zonder tekening.
            $meetstaatHosts = [];
            $hostIndexes = [];
            foreach ($merged as $mi => $host) {
                if (! in_array((string) ($host['source'] ?? ''), ['meetstaat', 'beide', 'gecombineerd'], true)) {
                    continue;
                }
                if (in_array((string) ($host['source'] ?? ''), ['beide', 'gecombineerd'], true)
                    && (($host['square_meters_source'] ?? null) === 'plattegrond' || filled($host['fill_color'] ?? null) || filled($host['contour_id'] ?? null))) {
                    // Al tekeninggekoppeld — alleen verrijken bij hard conflict-pad hieronder.
                    continue;
                }
                $meetstaatHosts[] = $host;
                $hostIndexes[] = $mi;
            }
            $hostHit = $resolver->matchHost($area, $meetstaatHosts);
            if ($hostHit !== null && $this->drawingMatchHasCompetingHosts(
                $meetstaatHosts[$hostHit['index']],
                $area,
                $meetstaatHosts,
                $hostHit['index'],
            )) {
                $hostHit = null;
            }
            if ($hostHit !== null) {
                $mergedIndex = $hostIndexes[$hostHit['index']];
                $merged[$mergedIndex] = $this->enrichRoomFromDrawingFragment(
                    $merged[$mergedIndex],
                    $area,
                    $hostHit['evidence']
                );
                $this->recordDuplicateRemoval($area);
                $usedDrawing[$index] = true;

                continue;
            }

            $number = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
            if ($number !== '' && $this->hasNumber($merged, $number, (string) ($area['floor'] ?? ''))) {
                if ($this->isGhostDuplicateOfExisting($area, $merged)) {
                    $this->recordDuplicateRemoval($area);

                    continue;
                }
                if ($this->hasNameCompatibleNumberedRoom($merged, $area)) {
                    $this->recordDuplicateRemoval($area);

                    continue;
                }
                $hostIndex = $this->uniqueMergedRoomIndex($merged, $number, (string) ($area['floor'] ?? ''));
                if ($hostIndex !== null && in_array((string) ($merged[$hostIndex]['source'] ?? ''), ['meetstaat', 'beide', 'gecombineerd'], true)) {
                    if (! $this->isHardRoomIdentityConflict($merged[$hostIndex], $area)) {
                        $merged[$hostIndex] = $this->enrichRoomFromDrawingFragment($merged[$hostIndex], $area);
                        $this->recordDuplicateRemoval($area);

                        continue;
                    }
                    // Meetstaat is leidend: conflicterend tekeningfragment niet als aparte Controleren-ruimte houden
                    // wanneer de host al expliciete materiaaltaken heeft.
                    if ($meetstaatIsTaskSource && $this->areaHasFlooringTasks($merged[$hostIndex])) {
                        $this->safeFixStats['wrong_merge_prevented_meters'] = round(
                            $this->safeFixStats['wrong_merge_prevented_meters'] + (float) ($area['square_meters'] ?? 0),
                            2
                        );
                        $this->recordDuplicateRemoval($area);

                        continue;
                    }
                }
                $area['keep_separate'] = true;
                $this->safeFixStats['wrong_merge_rooms_kept_separate']++;
                if ($this->hasUnresolvedSharedNumberAmbiguity(
                    $merged,
                    $area,
                    $number,
                    (string) ($area['floor'] ?? ''),
                    $meetstaatIsTaskSource,
                )) {
                    $area['needs_review'] = true;
                    $area['confidence'] = 'controleren';
                    $area['material_conflict'] = 'ruimtenummer gedeeld, naam conflicteert';
                } else {
                    // Tekening-only of unieke fysieke ruimtes: geen handmatige Controleren.
                    $area['needs_review'] = false;
                    $area['confidence'] = ($area['square_meters'] ?? null) !== null ? 'hoog' : 'midden';
                    unset($area['material_conflict']);
                }
                $rejected = $this->flooringTaskMeters($area);
                if ($rejected <= 0.0001 && ($area['square_meters'] ?? null) !== null) {
                    $rejected = (float) $area['square_meters'];
                }
                if ($rejected > 0) {
                    $this->safeFixStats['wrong_merge_prevented_meters'] = round(
                        $this->safeFixStats['wrong_merge_prevented_meters'] + $rejected,
                        2
                    );
                }
            } elseif ($this->isGhostDuplicateOfExisting($area, $merged)) {
                $this->recordDuplicateRemoval($area);

                continue;
            }

            $drawingTasks = $area['tasks'] ?? [];
            if ($meetstaatIsTaskSource) {
                $this->recordSuppressedDrawingTasks($area, $drawingTasks, 'drawing_only_with_meetstaat_tasks');
                $drawingTasks = $this->nonFlooringTasks($drawingTasks);
            }

            $drawingNeedsReview = (bool) ($area['needs_review'] ?? false)
                || filled($area['material_conflict'] ?? null);
            // Gewist/implausibel nummer met wel betrouwbare fysieke m²: geen harde Controleren.
            if (filled($area['implausible_room_number'] ?? null) && ($area['square_meters'] ?? null) !== null
                && blank($area['material_conflict'] ?? null)) {
                $drawingNeedsReview = false;
                $area['needs_review'] = false;
            }
            // Meetstaat = TASK_SOURCE: kleur zonder legenda bevestigt alleen het fysieke vlak.
            // Vloertaken komen uit de meetstaat — geen harde Controleren voor ontbrekende legenda-koppeling.
            if ($meetstaatIsTaskSource
                && filled($area['fill_color'] ?? null)
                && blank($area['legend_material'] ?? null)
                && blank($area['material_conflict'] ?? null)
                && ! $this->areaHasFlooringTasks($area)) {
                $drawingNeedsReview = false;
                $area['needs_review'] = false;
            }
            $drawingConfidence = $area['confidence'] ?? null;
            if (! $drawingNeedsReview && ($area['square_meters'] ?? null) !== null) {
                $drawingConfidence = ($drawingConfidence === 'controleren' || $drawingConfidence === null || $drawingConfidence === '')
                    ? 'midden'
                    : $drawingConfidence;
            }

            $merged[] = $this->withRecognition([
                'key' => $area['key'] ?? $this->areaKey($area),
                'floor' => $area['floor'] ?? 'Onbekend',
                'room_number' => $area['room_number'] ?? null,
                'room_name' => $area['room_name'] ?? ($area['room_number'] ?? 'Ruimte'),
                'square_meters' => $area['square_meters'] ?? null,
                'square_meters_source' => ($area['square_meters'] ?? null) !== null ? 'plattegrond' : null,
                'tasks' => $drawingTasks,
                'source' => 'plattegrond',
                'source_label' => $this->sourceLabel('plattegrond'),
                'needs_review' => $drawingNeedsReview,
                'fill_color' => $area['fill_color'] ?? null,
                'legend_material' => $area['legend_material'] ?? null,
                'material_conflict' => $area['material_conflict'] ?? null,
                'implausible_room_number' => $area['implausible_room_number'] ?? null,
                'floor_reassignment_reason' => $area['floor_reassignment_reason'] ?? null,
                'floor_reassigned_from' => $area['floor_reassigned_from'] ?? null,
                'recognized_via' => $area['recognized_via'] ?? ['tekening'],
                'confidence' => $drawingConfidence,
                'page' => $area['page'] ?? null,
                'x' => $area['x'] ?? null,
                'y' => $area['y'] ?? null,
                'meter_x' => $area['meter_x'] ?? null,
                'meter_y' => $area['meter_y'] ?? null,
                'contour_id' => $area['contour_id'] ?? null,
                'keep_separate' => $area['keep_separate'] ?? false,
            ]);
        }

        return $this->settleMissingDrawingColor(array_values($merged), $meetstaatIsTaskSource);
    }

    /**
     * Plintkleur op de tekening mag nooit als vloer-m² in taken of werken landen.
     *
     * @param  list<array<string, mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    private function dropPlinthSquareMeterTasks(array $areas): array
    {
        foreach ($areas as &$area) {
            $legend = mb_strtolower((string) ($area['legend_material'] ?? ''));
            if (str_contains($legend, 'plint')) {
                $area['legend_material'] = null;
            }
            $kept = [];
            foreach ($area['tasks'] ?? [] as $task) {
                if (! is_array($task)) {
                    continue;
                }
                $name = mb_strtolower((string) ($task['work_name'] ?? ''));
                $unit = (string) ($task['unit'] ?? WorkUnit::SquareMeter->value);
                if (str_contains($name, 'plint') && in_array($unit, ['m2', 'm²', ''], true)) {
                    continue;
                }
                $kept[] = $task;
            }
            $area['tasks'] = $kept;
        }
        unset($area);

        return $areas;
    }

    /**
     * Fysieke ruimte-m² komt uit de plattegrond wanneer die beschikbaar is.
     * Meetstaat-taakhoeveelheden zijn nooit bewezen fysieke m².
     *
     * @param  array<string, mixed>  $area
     * @return array{square_meters: ?float, derived_square_meters: ?float, square_meters_source: ?string, needs_review: bool}
     */
    public function physicalFromMeetstaat(array $area, bool $drawingAvailable = false): array
    {
        $flooring = [];
        foreach ($area['tasks'] ?? [] as $task) {
            if (! $this->isFlooringArea($task)) {
                continue;
            }
            $flooring[] = (float) ($task['quantity'] ?? 0);
        }

        $derived = count($flooring) === 1 ? round($flooring[0], 2) : null;

        if ($drawingAvailable) {
            return [
                'square_meters' => null,
                'derived_square_meters' => $derived,
                'square_meters_source' => null,
                'needs_review' => false,
            ];
        }

        if ($derived !== null) {
            return [
                'square_meters' => $derived,
                'derived_square_meters' => $derived,
                'square_meters_source' => 'afgeleid_meetstaat',
                'needs_review' => false,
            ];
        }

        return [
            'square_meters' => null,
            'derived_square_meters' => null,
            'square_meters_source' => null,
            'needs_review' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function isFlooringArea(array $task): bool
    {
        $unit = $task['unit'] ?? WorkUnit::SquareMeter->value;
        $unitValue = $unit instanceof WorkUnit ? $unit->value : (string) $unit;
        if ($unitValue !== WorkUnit::SquareMeter->value) {
            return false;
        }

        $name = mb_strtolower((string) ($task['work_name'] ?? ''));
        if ($name === '' || str_contains($name, 'plint') || WorkType::looksLikeRoom($name)) {
            return false;
        }

        return (float) ($task['quantity'] ?? 0) > 0;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, list<array{index: int, area: array<string, mixed>}>>
     */
    private function indexByNumber(array $areas): array
    {
        $index = [];
        foreach ($areas as $i => $area) {
            $number = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $index[$number][] = ['index' => $i, 'area' => $area];
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $area
     * @param  array<string, list<array{index: int, area: array<string, mixed>}>>  $drawingByNumber
     * @param  array<int, true>  $usedDrawing
     * @return array<string, mixed>|null
     */
    private function takeDrawingMatch(array $area, array $drawingByNumber, array &$usedDrawing): ?array
    {
        $number = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
        if ($number === '' || ! isset($drawingByNumber[$number])) {
            return null;
        }

        $candidates = $drawingByNumber[$number];
        $floor = mb_strtolower((string) ($area['floor'] ?? ''));
        $sameFloor = [];
        foreach ($candidates as $candidate) {
            if (isset($usedDrawing[$candidate['index']])) {
                continue;
            }
            $candidateFloor = mb_strtolower((string) ($candidate['area']['floor'] ?? ''));
            if ($candidateFloor === $floor || $candidateFloor === 'onbekend' || $floor === 'onbekend') {
                $sameFloor[] = $candidate;
            }
        }

        if ($sameFloor === []) {
            return null;
        }
        if (count($sameFloor) > 1 && $floor !== 'onbekend') {
            $strictFloor = array_values(array_filter(
                $sameFloor,
                fn (array $candidate) => mb_strtolower((string) ($candidate['area']['floor'] ?? '')) === $floor
            ));
            if ($strictFloor !== []) {
                $sameFloor = $strictFloor;
            }
        }

        // Primaire sleutel: unieke bouwlaag + ruimtenummer → koppelen tenzij echt identiteitsconflict
        // (conflicterende naam én sterk afwijkende m²).
        if (count($sameFloor) === 1) {
            $hit = $sameFloor[0];
            if ($this->isHardRoomIdentityConflict($area, $hit['area'])) {
                return null;
            }
            $usedDrawing[$hit['index']] = true;
            if ($this->roomLabelsConflict(
                trim((string) ($area['room_name'] ?? '')),
                trim((string) ($hit['area']['room_name'] ?? ''))
            )) {
                $this->safeFixStats['ocr_meter_matches']++;
                $hit['area']['auto_match_reason'] = 'ocr_normalized_number';
            } else {
                $hit['area']['auto_match_reason'] = 'exact_floor_number';
            }

            return $hit['area'];
        }

        $meetName = trim((string) ($area['room_name'] ?? ''));
        $meetMeters = $this->physicalFromMeetstaat($area)['square_meters']
            ?? ($area['square_meters'] ?? null);
        $compatible = [];
        $rejectedMeters = 0.0;
        foreach ($sameFloor as $candidate) {
            $drawName = trim((string) ($candidate['area']['room_name'] ?? ''));
            $drawMeters = $candidate['area']['square_meters'] ?? null;
            $metersAlign = $meetMeters !== null && $drawMeters !== null
                && $this->quantitiesNearlyEqual((float) $meetMeters, (float) $drawMeters, 0.05);

            if ($this->roomLabelsConflict($meetName, $drawName)) {
                // Meerdere kandidaten: naamconflict alleen overbruggen met m² + geometrie.
                if ($metersAlign && $this->roomsGeometricallyNear($area, $candidate['area'])) {
                    $this->safeFixStats['ocr_meter_matches']++;
                    $candidate['area']['auto_match_reason'] = 'number_meters_geometry_ocr_name';
                    $compatible[] = $candidate;

                    continue;
                }
                $rejectedMeters += $this->flooringTaskMeters($candidate['area']);
                if ($drawMeters !== null && $this->flooringTaskMeters($candidate['area']) <= 0.0001) {
                    $rejectedMeters += (float) $drawMeters;
                }

                continue;
            }
            $candidate['area']['auto_match_reason'] = $metersAlign ? 'number_name_meters' : 'number_name';
            $compatible[] = $candidate;
        }

        if ($compatible === []) {
            if ($rejectedMeters > 0) {
                $this->safeFixStats['wrong_merge_prevented_meters'] = round(
                    $this->safeFixStats['wrong_merge_prevented_meters'] + $rejectedMeters,
                    2
                );
            }

            return null;
        }

        if (count($compatible) === 1) {
            $hit = $compatible[0];
            $usedDrawing[$hit['index']] = true;

            return $hit['area'];
        }

        // Meerdere kandidaten: kies op m²-nabijheid + geometrie, anders niet koppelen.
        $ranked = [];
        foreach ($compatible as $candidate) {
            $drawMeters = $candidate['area']['square_meters'] ?? null;
            $meterScore = ($meetMeters !== null && $drawMeters !== null)
                ? abs((float) $meetMeters - (float) $drawMeters)
                : 999.0;
            $near = $this->roomsGeometricallyNear($area, $candidate['area']);
            if ($meterScore <= 0.15 && $near) {
                $ranked[] = ['candidate' => $candidate, 'score' => $meterScore];
            }
        }
        if (count($ranked) === 1) {
            $hit = $ranked[0]['candidate'];
            $usedDrawing[$hit['index']] = true;
            $hit['area']['auto_match_reason'] = 'number_meters_geometry';

            return $hit['area'];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function hasNumber(array $areas, string $number, string $floor): bool
    {
        $floor = mb_strtolower($floor);
        foreach ($areas as $area) {
            if ($this->normalizeNumber((string) ($area['room_number'] ?? '')) !== $number) {
                continue;
            }
            $areaFloor = mb_strtolower((string) ($area['floor'] ?? ''));
            if ($floor === '' || $floor === 'onbekend' || $areaFloor === $floor || $areaFloor === 'onbekend') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  array<string, mixed>  $incoming
     */
    private function hasNameCompatibleNumberedRoom(array $areas, array $incoming): bool
    {
        $number = $this->normalizeNumber((string) ($incoming['room_number'] ?? ''));
        $floor = mb_strtolower((string) ($incoming['floor'] ?? ''));
        $incomingName = trim((string) ($incoming['room_name'] ?? ''));
        foreach ($areas as $area) {
            if ($this->normalizeNumber((string) ($area['room_number'] ?? '')) !== $number) {
                continue;
            }
            $areaFloor = mb_strtolower((string) ($area['floor'] ?? ''));
            if ($floor !== '' && $floor !== 'onbekend' && $areaFloor !== $floor && $areaFloor !== 'onbekend') {
                continue;
            }
            if (! $this->roomLabelsConflict($incomingName, (string) ($area['room_name'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hard conflict: conflicterende ruimtenaam én duidelijk andere m² → niet automatisch samenvoegen.
     * OCR-naamschade zonder m²-conflict mag een unieke nummerkoppeling niet blokkeren.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function isHardRoomIdentityConflict(array $left, array $right): bool
    {
        $leftName = trim((string) ($left['room_name'] ?? ''));
        $rightName = trim((string) ($right['room_name'] ?? ''));
        if (! $this->roomLabelsConflict($leftName, $rightName)) {
            return false;
        }

        $leftMeters = $this->identityCompareMeters($left);
        $rightMeters = $this->identityCompareMeters($right);
        if ($leftMeters === null || $rightMeters === null) {
            return false;
        }

        return abs($leftMeters - $rightMeters) > 2.0;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function identityCompareMeters(array $area): ?float
    {
        if (($area['square_meters'] ?? null) !== null) {
            return round((float) $area['square_meters'], 2);
        }
        $physical = $this->physicalFromMeetstaat($area)['square_meters'] ?? null;
        if ($physical !== null) {
            return round((float) $physical, 2);
        }
        $taskSum = $this->flooringTaskMeters($area);
        if ($taskSum > 0.0001) {
            return round($taskSum, 2);
        }

        return null;
    }

    /**
     * Exact één gemergde host metzelfde bouwlaag + ruimtenummer.
     *
     * @param  list<array<string, mixed>>  $areas
     */
    private function uniqueMergedRoomIndex(array $areas, string $number, string $floor): ?int
    {
        $matches = $this->mergedRoomIndexesWithNumber($areas, $number, $floor);

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return list<int>
     */
    private function mergedRoomIndexesWithNumber(array $areas, string $number, string $floor): array
    {
        $floor = mb_strtolower($floor);
        $matches = [];
        foreach ($areas as $index => $area) {
            if ($this->normalizeNumber((string) ($area['room_number'] ?? '')) !== $number) {
                continue;
            }
            $areaFloor = mb_strtolower((string) ($area['floor'] ?? ''));
            if ($floor !== '' && $floor !== 'onbekend' && $areaFloor !== $floor && $areaFloor !== 'onbekend') {
                continue;
            }
            $matches[] = $index;
        }

        return $matches;
    }

    /**
     * Echte ambiguïteit: ≥2 meetstaat-hosts, of 1 TASK_SOURCE-host met hard conflict
     * zonder veilige discard. Tekening-only duplicates zijn géén Controleren.
     *
     * @param  list<array<string, mixed>>  $merged
     * @param  array<string, mixed>  $incoming
     */
    private function hasUnresolvedSharedNumberAmbiguity(
        array $merged,
        array $incoming,
        string $number,
        string $floor,
        bool $meetstaatIsTaskSource,
    ): bool {
        $indexes = $this->mergedRoomIndexesWithNumber($merged, $number, $floor);
        $taskSourceHosts = [];
        foreach ($indexes as $index) {
            $host = $merged[$index];
            $source = (string) ($host['source'] ?? '');
            if (! in_array($source, ['meetstaat', 'beide', 'gecombineerd'], true)) {
                continue;
            }
            if ($this->areaHasFlooringTasks($host)) {
                $taskSourceHosts[] = $host;
            }
        }

        if (count($taskSourceHosts) >= 2) {
            return true;
        }

        if (count($taskSourceHosts) === 1) {
            $host = $taskSourceHosts[0];
            if (! $this->isHardRoomIdentityConflict($host, $incoming)) {
                return false;
            }

            // Hard conflict met unieke TASK_SOURCE-host: bij meetstaat-leiderschap
            // is discard al eerder geprobeerd; resterende cases zijn onoplosbaar.
            return $meetstaatIsTaskSource;
        }

        return false;
    }

    /**
     * Verrijk een bestaande meetstaatruimte met tekeninggeometrie/kleur zonder taken te verdubbelen.
     *
     * @param  array<string, mixed>  $host
     * @param  array<string, mixed>  $drawing
     * @param  array<string, mixed>|null  $evidence
     * @return array<string, mixed>
     */
    private function enrichRoomFromDrawingFragment(array $host, array $drawing, ?array $evidence = null): array
    {
        $host['source'] = in_array((string) ($host['source'] ?? ''), ['meetstaat', 'beide'], true)
            ? 'beide'
            : 'gecombineerd';
        $host['source_label'] = $this->sourceLabel((string) $host['source']);
        if (($drawing['square_meters'] ?? null) !== null) {
            $host['square_meters'] = $drawing['square_meters'];
            $host['square_meters_source'] = 'plattegrond';
        }
        $host['fill_color'] = $host['fill_color'] ?? $drawing['fill_color'] ?? null;
        $host['legend_material'] = $host['legend_material'] ?? $drawing['legend_material'] ?? null;
        $host['page'] = $host['page'] ?? $drawing['page'] ?? null;
        foreach (['x', 'y', 'meter_x', 'meter_y', 'contour_id'] as $geoKey) {
            if (($host[$geoKey] ?? null) === null && ($drawing[$geoKey] ?? null) !== null) {
                $host[$geoKey] = $drawing[$geoKey];
            }
        }
        $host['recognized_via'] = $this->mergeVia(
            $host['recognized_via'] ?? ['meetstaat'],
            $drawing['recognized_via'] ?? ['tekening']
        );
        if ($evidence !== null) {
            $host['match_evidence'] = $evidence;
            $host['auto_match_reason'] = (string) ($evidence['reason'] ?? 'combined_evidence');
        } else {
            $host['auto_match_reason'] = $host['auto_match_reason'] ?? 'exact_floor_number_absorb_fragment';
        }
        $host['needs_review'] = false;
        if (($host['confidence'] ?? '') === 'controleren' || ($host['confidence'] ?? '') === '') {
            $host['confidence'] = ($host['square_meters'] ?? null) !== null ? 'hoog' : 'midden';
        }
        $host['tasks'] = $this->appendUniqueTasks(
            $host['tasks'] ?? [],
            $this->nonFlooringTasks($drawing['tasks'] ?? [])
        );

        return $this->withRecognition($host);
    }

    /**
     * Ontbrekende tekeningkleur blokkeert niet wanneer Meetstaat al TASK_SOURCE is.
     * Alleen een écht conflict (twee of meer m²-kandidaten) blijft Controleren.
     *
     * @param  list<array<string, mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    private function settleMissingDrawingColor(array $areas, bool $meetstaatIsTaskSource): array
    {
        if (! $meetstaatIsTaskSource) {
            return $areas;
        }

        $drop = [];
        foreach ($areas as $index => $area) {
            if ((string) ($area['source'] ?? '') !== 'plattegrond') {
                continue;
            }
            if (filled($area['fill_color'] ?? null) || filled($area['material_conflict'] ?? null)) {
                continue;
            }
            if ($this->areaHasFlooringTasks($area)) {
                continue;
            }
            if (($area['square_meters'] ?? null) === null) {
                continue;
            }
            $floor = mb_strtolower(trim((string) ($area['floor'] ?? '')));
            if ($floor === '' || $floor === 'onbekend') {
                continue;
            }
            if (($area['confidence'] ?? '') !== 'controleren' && ! ($area['needs_review'] ?? false)) {
                continue;
            }

            $exactHits = [];
            $nearHits = [];
            foreach ($areas as $hostIndex => $host) {
                if ($hostIndex === $index) {
                    continue;
                }
                if (! in_array((string) ($host['source'] ?? ''), ['meetstaat', 'beide', 'gecombineerd'], true)) {
                    continue;
                }
                if (mb_strtolower(trim((string) ($host['floor'] ?? ''))) !== $floor) {
                    continue;
                }
                if (! $this->areaHasFlooringTasks($host)) {
                    continue;
                }
                if (! $this->roomNamesCompatible(
                    (string) ($area['room_name'] ?? ''),
                    (string) ($host['room_name'] ?? '')
                )) {
                    continue;
                }
                $hostMeters = $host['square_meters'] ?? $host['derived_square_meters'] ?? null;
                if ($hostMeters === null) {
                    $hostMeters = $this->singleFlooringTaskMeters($host);
                }
                if ($hostMeters === null) {
                    continue;
                }
                $delta = round(abs(round((float) $hostMeters, 2) - round((float) $area['square_meters'], 2)), 2);
                if ($delta <= 0.15) {
                    $nearHits[] = $hostIndex;
                }
                if ($delta <= 0.05) {
                    $exactHits[] = $hostIndex;
                }
            }

            if (count($exactHits) === 1) {
                $hostIndex = $exactHits[0];
                $areas[$hostIndex] = $this->enrichRoomFromDrawingFragment($areas[$hostIndex], $area, [
                    'reason' => 'meetstaat + room identity',
                ]);
                $drop[$index] = true;

                continue;
            }
            if (count($exactHits) > 1 || count($nearHits) > 1) {
                continue;
            }

            $areas[$index]['needs_review'] = false;
            $areas[$index]['confidence'] = 'midden';
            $areas[$index]['recognized_via'] = $this->mergeVia($area['recognized_via'] ?? ['tekening'], ['meetstaat']);
            $areas[$index] = $this->withRecognition($areas[$index]);
        }

        if ($drop === []) {
            return array_values($areas);
        }

        return array_values(array_filter(
            $areas,
            fn ($area, $index) => ! isset($drop[$index]),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * Eerste meetstaat-ruimte mag een tekeningvlak niet "stelen" wanneer een andere
     * naam-compatibele host op dezelfde bouwlaag even dicht bij de tekening-m² ligt.
     *
     * @param  array<string, mixed>  $meetstaatRoom
     * @param  array<string, mixed>  $drawingRoom
     * @param  list<array<string, mixed>>  $allMeetstaat
     */
    private function drawingMatchHasCompetingHosts(
        array $meetstaatRoom,
        array $drawingRoom,
        array $allMeetstaat,
        int $currentIndex,
    ): bool {
        $drawNumber = $this->normalizeNumber((string) ($drawingRoom['room_number'] ?? ''));
        $selfNumber = $this->normalizeNumber((string) ($meetstaatRoom['room_number'] ?? ''));
        if ($drawNumber !== '' && $selfNumber !== '' && $drawNumber === $selfNumber) {
            return false;
        }

        $floor = mb_strtolower(trim((string) ($meetstaatRoom['floor'] ?? '')));
        $drawName = (string) ($drawingRoom['room_name'] ?? '');
        $drawMeters = $drawingRoom['square_meters'] ?? null;
        if ($drawMeters === null) {
            return false;
        }

        $near = [];
        $exact = [];
        foreach ($allMeetstaat as $index => $other) {
            if (mb_strtolower(trim((string) ($other['floor'] ?? ''))) !== $floor) {
                continue;
            }
            if (! $this->roomNamesCompatible((string) ($other['room_name'] ?? ''), $drawName)) {
                continue;
            }
            $otherNumber = $this->normalizeNumber((string) ($other['room_number'] ?? ''));
            if ($drawNumber !== '' && $otherNumber !== '' && $drawNumber !== $otherNumber) {
                continue;
            }
            $otherMeters = $other['square_meters'] ?? $this->singleFlooringTaskMeters($other);
            if ($otherMeters === null) {
                continue;
            }
            $delta = round(abs(round((float) $otherMeters, 2) - round((float) $drawMeters, 2)), 2);
            if ($delta <= 0.15) {
                $near[] = (int) $index;
            }
            if ($delta <= 0.05) {
                $exact[] = (int) $index;
            }
        }

        if (count($exact) === 1) {
            return $exact[0] !== $currentIndex;
        }

        return count($near) > 1;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function singleFlooringTaskMeters(array $area): ?float
    {
        $meters = [];
        foreach ($area['tasks'] ?? [] as $task) {
            if (! is_array($task) || ! $this->isFlooringArea($task)) {
                continue;
            }
            $meters[] = (float) ($task['quantity'] ?? 0);
        }
        if (count($meters) !== 1) {
            return null;
        }

        return round($meters[0], 2);
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return list<string>
     */
    private function canonicalMaterialCandidatesFromAreas(array $areas): array
    {
        $names = [];
        foreach ($areas as $area) {
            foreach ($area['tasks'] ?? [] as $task) {
                if (! is_array($task) || ! $this->isFlooringArea($task)) {
                    continue;
                }
                $name = trim((string) ($task['work_name'] ?? ''));
                if ($name === '' || $this->materialIdentity()->isGenericTypeLabel($name)) {
                    continue;
                }
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param  list<array<string, mixed>>  $declared
     * @param  list<array<string, mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    private function mergeWorks(array $declared, array $areas): array
    {
        $works = $this->mergeDeclaredWorks($declared);

        foreach ($areas as $area) {
            foreach ($area['tasks'] ?? [] as $task) {
                $name = (string) ($task['work_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $taskUnit = (string) ($task['unit'] ?? WorkUnit::SquareMeter->value);
                if (str_contains(mb_strtolower($name), 'plint') && in_array($taskUnit, ['m2', 'm²', ''], true)) {
                    continue;
                }
                $identityKey = $this->findWorkKeyByMaterialIdentity($works, $name);
                if ($identityKey === null && $this->shouldSkipUncodedLegendWork($works, $name)) {
                    continue;
                }
                $identityKey = $identityKey ?? $name;
                if (! isset($works[$identityKey])) {
                    $works[$identityKey] = [
                        'name' => $name,
                        'unit' => $task['unit'] ?? WorkUnit::SquareMeter->value,
                        'declared_total' => 0.0,
                        'calculated_total' => 0.0,
                        'source_names' => [$name],
                    ];
                }
                $unit = $works[$identityKey]['unit'] ?? WorkUnit::SquareMeter->value;
                $amount = ($unit === WorkUnit::LinearMeter->value || $unit === WorkUnit::LinearMeter)
                    ? (float) ($task['perimeter'] ?? 0)
                    : (float) ($task['quantity'] ?? 0);
                $works[$identityKey]['calculated_total'] = round($works[$identityKey]['calculated_total'] + $amount, 2);
            }
        }

        return array_values($works);
    }

    /**
     * Voeg declared works samen. Zelfde productidentiteit (code of canonieke naam) wordt één regel.
     * Een bestaande netto-hoeveelheid wordt niet overschreven: Meetstaat eerst, daarna alleen vullen als die leeg is.
     *
     * @param  list<array<string, mixed>>  $declared
     * @return array<string, array<string, mixed>>
     */
    private function mergeDeclaredWorks(array $declared): array
    {
        $works = [];
        foreach ($declared as $work) {
            $name = (string) ($work['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $workUnit = (string) ($work['unit'] ?? WorkUnit::SquareMeter->value);
            if (str_contains(mb_strtolower($name), 'plint') && in_array($workUnit, ['m2', 'm²', ''], true)) {
                continue;
            }
            $incomingDeclared = array_key_exists('declared_total', $work) && $work['declared_total'] !== null
                ? (float) $work['declared_total']
                : 0.0;
            $identityKey = $this->findWorkKeyByMaterialIdentity($works, $name);
            if ($identityKey === null && $this->shouldSkipUncodedLegendWork($works, $name)) {
                continue;
            }
            $identityKey = $identityKey ?? $name;
            if (! isset($works[$identityKey])) {
                $works[$identityKey] = [
                    'name' => $name,
                    'unit' => $work['unit'] ?? WorkUnit::SquareMeter->value,
                    'declared_total' => $incomingDeclared,
                    'calculated_total' => 0.0,
                    'source_names' => $work['source_names'] ?? [$name],
                ];

                continue;
            }
            $existingName = (string) ($works[$identityKey]['name'] ?? '');
            if ($this->preferCanonicalMaterialName($name, $existingName)) {
                $works[$identityKey]['name'] = $name;
            }
            if ((float) ($works[$identityKey]['declared_total'] ?? 0) <= 0 && $incomingDeclared > 0) {
                $works[$identityKey]['declared_total'] = $incomingDeclared;
            }
            $works[$identityKey]['source_names'] = array_values(array_unique(array_merge(
                $works[$identityKey]['source_names'] ?? [$existingName],
                $work['source_names'] ?? [$name],
            )));
        }

        return $works;
    }

    /**
     * Expected taak-m² per materiaal.
     * Met meetstaat als TASK_SOURCE: meetstaat declared is leidend; MaterialList Netto mag niet via MAX opblazen.
     *
     * @param  list<array<string, mixed>>  $materialWorks
     * @param  list<array<string, mixed>>  $meetstaatWorks
     * @return array<string, array<string, mixed>>
     */
    private function mergeExpectedTaskSourceWorks(array $materialWorks, array $meetstaatWorks, bool $meetstaatIsTaskSource): array
    {
        if (! $meetstaatIsTaskSource) {
            return $this->mergeDeclaredWorks(array_merge($meetstaatWorks, $materialWorks));
        }

        $works = $this->mergeDeclaredWorks($meetstaatWorks);
        $materialNames = [];
        foreach ($materialWorks as $materialWork) {
            $materialName = trim((string) ($materialWork['name'] ?? ''));
            if ($materialName !== '' && ! $this->materialIdentity()->isGenericTypeLabel($materialName)) {
                $materialNames[] = $materialName;
            }
        }
        foreach ($works as $key => $work) {
            $current = trim((string) ($work['name'] ?? ''));
            if ($current === '' || $materialNames === []) {
                continue;
            }
            $resolved = $this->materialIdentity()->resolveUniqueCanonical($current, $materialNames);
            if ($resolved === null) {
                $resolved = $this->resolveCanonicalByDeclaredTotal($current, array_merge($materialWorks, $meetstaatWorks));
            }
            if ($resolved === null || $resolved === $current) {
                continue;
            }
            $works[$key]['name'] = $resolved;
            $works[$key]['source_names'] = array_values(array_unique(array_merge(
                $works[$key]['source_names'] ?? [$current],
                [$resolved],
            )));
        }
        foreach ($materialWorks as $materialWork) {
            $name = (string) ($materialWork['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $matchKey = $this->findWorkKeyByMaterialIdentity($works, $name);
            if ($matchKey === null) {
                continue;
            }
            $works[$matchKey]['source_names'] = array_values(array_unique(array_merge(
                $works[$matchKey]['source_names'] ?? [$works[$matchKey]['name']],
                $materialWork['source_names'] ?? [$name],
            )));
            if ($this->preferCanonicalMaterialName($name, (string) ($works[$matchKey]['name'] ?? ''))) {
                $works[$matchKey]['name'] = $name;
            }
            $works[$matchKey]['material_list_netto'] = array_key_exists('declared_total', $materialWork) && $materialWork['declared_total'] !== null
                ? (float) $materialWork['declared_total']
                : null;
        }

        return $works;
    }

    /**
     * @param  array<string, array<string, mixed>>  $works
     */
    private function findWorkKeyByMaterialIdentity(array $works, string $materialName): ?string
    {
        foreach ($works as $key => $work) {
            $candidate = (string) ($work['name'] ?? $key);
            if ($this->materialsShareIdentity($materialName, $candidate)) {
                return (string) $key;
            }
        }

        return null;
    }

    private function materialsShareIdentity(string $left, string $right): bool
    {
        return $this->materialIdentity()->sharesIdentity($left, $right)
            && $this->materialIdentity()->sameExecutionVariant($left, $right);
    }

    /**
     * Tekeninglegenda zonder werkcode mag geen extra werkzaamheid maken
     * wanneer de Meetstaat al gecodeerde werkzaamheden heeft.
     * Volledige Meetstaat-productregels zonder werkcode blijven gewoon werkzaamheden.
     *
     * @param  array<string, array<string, mixed>>  $works
     */
    private function shouldSkipUncodedLegendWork(array $works, string $name): bool
    {
        if ($this->materialIdentity()->workCodes($name) !== []) {
            return false;
        }
        if ($this->isDeclaredMeetstaatProductName($name)) {
            return false;
        }
        foreach ($works as $work) {
            $candidate = (string) ($work['name'] ?? '');
            if ($candidate !== '' && $this->materialIdentity()->workCodes($candidate) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Meetstaat-productkop zonder STABU-code, bijv. "Tarkett vinyl iQ Natural-black, PVC / Vinyl".
     * Korte legendanamen zoals "Coral Brush" vallen hier buiten.
     */
    private function isDeclaredMeetstaatProductName(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        if ($this->materialIdentity()->looksLikeStrongProductHeader($name)) {
            return true;
        }

        return str_contains($name, ',') && mb_strlen($name) >= 24;
    }

    private function floorLabel(): FloorLabel
    {
        return new FloorLabel;
    }

    private function preferCanonicalMaterialName(string $incoming, string $existing): bool
    {
        $identity = $this->materialIdentity();
        if (! $identity->sameExecutionVariant($incoming, $existing)) {
            return false;
        }
        $incomingKey = $identity->executionKey($incoming);
        $existingKey = $identity->executionKey($existing);
        if (substr_count($existingKey, '|') > substr_count($incomingKey, '|')) {
            return false;
        }
        $incomingCodes = $identity->productCodes($incoming);
        $existingCodes = $identity->productCodes($existing);
        if ($incomingCodes !== [] && $existingCodes === []) {
            return true;
        }
        if ($incomingCodes === [] && $existingCodes !== []) {
            return false;
        }

        return mb_strlen(trim($incoming)) > mb_strlen(trim($existing));
    }

    private function materialIdentity(): MaterialIdentity
    {
        return new MaterialIdentity;
    }

    /**
     * @param  array<string, array<string, mixed>>|list<array<string, mixed>>  $works
     * @param  array<string, mixed>  $expectedTaskTotals
     * @return list<array<string, mixed>>
     */
    private function applyExpectedDeclaredTotals(array $works, array $expectedTaskTotals): array
    {
        $byName = [];
        foreach ($expectedTaskTotals['by_material'] ?? [] as $row) {
            $byName[(string) ($row['material'] ?? '')] = (float) ($row['expected_task_meters'] ?? 0);
        }
        $list = array_is_list($works) ? $works : array_values($works);
        foreach ($list as $index => $work) {
            $name = (string) ($work['name'] ?? '');
            if ($name !== '' && isset($byName[$name])) {
                $list[$index]['declared_total'] = $byName[$name];
            }
        }

        return $list;
    }

    /**
     * @param  array<string, array<string, mixed>>|list<array<string, mixed>>  $works
     * @return array{
     *     known: bool,
     *     project_total: ?float,
     *     by_material: list<array{material: string, expected_task_meters: float, source: string}>,
     *     source_label: string,
     *     declared_vs_task_delta: list<array{material: string, declared: float, task_sum: float, difference: float}>
     * }
     */
    private function buildExpectedTaskTotals(array $works, array $meetstaatAreas = []): array
    {
        $list = array_is_list($works) ? $works : array_values($works);
        $byMaterial = [];
        $projectTotal = 0.0;
        $known = false;
        foreach ($this->primaryFlooringWorks($list) as $work) {
            if (! array_key_exists('declared_total', $work) || $work['declared_total'] === null) {
                continue;
            }
            $declared = round((float) $work['declared_total'], 2);
            if ($declared <= 0) {
                continue;
            }
            $known = true;
            $name = (string) ($work['name'] ?? '');
            $byMaterial[] = [
                'material' => $name,
                'expected_task_meters' => $declared,
                'source' => 'materiaalblok_eindtotaal',
            ];
            $projectTotal += $declared;
        }

        $taskRows = [];
        foreach ($meetstaatAreas as $area) {
            foreach ($area['tasks'] ?? [] as $task) {
                if (! is_array($task) || ! $this->isFlooringArea($task)) {
                    continue;
                }
                $taskRows[] = $task;
            }
        }
        $deltas = [];
        foreach ($byMaterial as $row) {
            $name = $row['material'];
            $taskSum = 0.0;
            foreach ($taskRows as $task) {
                $taskName = (string) ($task['work_name'] ?? '');
                if ($taskName === '' || ! $this->materialsShareIdentity($name, $taskName)) {
                    continue;
                }
                $taskSum += (float) ($task['quantity'] ?? 0);
            }
            $taskSum = round($taskSum, 2);
            $diff = round($taskSum - (float) $row['expected_task_meters'], 2);
            if (abs($diff) > 0.0001) {
                $deltas[] = [
                    'material' => $name,
                    'declared' => (float) $row['expected_task_meters'],
                    'task_sum' => $taskSum,
                    'difference' => $diff,
                ];
            }
        }

        return [
            'known' => $known,
            'project_total' => $known ? round($projectTotal, 2) : null,
            'by_material' => $byMaterial,
            'source_label' => 'Materiaalblok-eindtotaal',
            'declared_vs_task_delta' => $deltas,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function areasProvideFlooringTasks(array $areas): bool
    {
        foreach ($areas as $area) {
            if ($this->areaHasFlooringTasks($area)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function areaHasFlooringTasks(array $area): bool
    {
        foreach ($area['tasks'] ?? [] as $task) {
            if (is_array($task) && $this->isFlooringArea($task)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private function nonFlooringTasks(array $tasks): array
    {
        $kept = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            if ($this->isFlooringArea($task)) {
                continue;
            }
            $name = mb_strtolower((string) ($task['work_name'] ?? ''));
            $unit = (string) ($task['unit'] ?? WorkUnit::SquareMeter->value);
            // Plintkleur op de tekening is geen vloer-m²; plinthoeveelheden komen als m¹ uit de meetstaat.
            if (str_contains($name, 'plint') && in_array($unit, ['m2', 'm²', ''], true)) {
                continue;
            }
            $kept[] = $task;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $area
     * @param  list<array<string, mixed>>  $tasks
     */
    private function recordSuppressedDrawingTasks(array $area, array $tasks, string $reason): void
    {
        foreach ($tasks as $task) {
            if (! is_array($task) || ! $this->isFlooringArea($task)) {
                continue;
            }
            if (count($this->safeFixStats['suppressed_drawing_tasks']) >= 80) {
                break;
            }
            $this->safeFixStats['suppressed_drawing_tasks'][] = [
                'room' => trim(($area['room_number'] ?? '').' '.($area['room_name'] ?? '')),
                'floor' => $area['floor'] ?? null,
                'material' => $task['work_name'] ?? null,
                'drawing_task_m2' => round((float) ($task['quantity'] ?? 0), 2),
                'reason' => $reason,
            ];
            $this->safeFixStats['suppressed_drawing_task_meters'] = round(
                $this->safeFixStats['suppressed_drawing_task_meters'] + (float) ($task['quantity'] ?? 0),
                2
            );
        }
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function areaKey(array $area): string
    {
        $floor = mb_strtolower((string) ($area['floor'] ?? 'onbekend'));
        $number = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
        if ($number !== '') {
            return $floor.'|'.$number;
        }

        return $floor.'|'.mb_strtolower((string) ($area['room_name'] ?? 'ruimte'));
    }

    public function normalizeNumber(string $value): string
    {
        return (new RoomIdentityResolver)->normalizeNumber($value);
    }

    public function sourceLabel(string $source): string
    {
        return match ($source) {
            'meetstaat' => 'Meetstaat',
            'plattegrond' => 'Plattegrond',
            'beide' => 'Meetstaat + plattegrond',
            'materialenstaat' => 'Materialenstaat',
            'snijmaten' => 'Snijmaten',
            'gecombineerd' => 'Meerdere bronnen',
            default => 'Handmatig',
        };
    }

    /**
     * Attach material hints from Snijmaten onto existing physical rooms only.
     * Never creates rooms or floors.
     *
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $hints
     * @return list<array<string, mixed>>
     */
    /**
     * Bevestig of vul materiaal op bestaande plattegrondruimtes.
     * Maakt nooit nieuwe ruimtes of bouwlagen.
     *
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $hints
     * @return list<array<string, mixed>>
     */
    private function enrichExistingRooms(array $areas, array $hints, string $source): array
    {
        if ($hints === [] || $areas === []) {
            return $areas;
        }

        foreach ($hints as $hint) {
            $product = trim((string) ($hint['tasks'][0]['work_name'] ?? ''));
            if ($product === '') {
                continue;
            }

            $matches = [];
            foreach ($areas as $index => $area) {
                if ($this->roomsMatchForEnrichment($area, $hint)) {
                    $matches[] = $index;
                }
            }
            if ($matches === []) {
                continue;
            }

            foreach ($matches as $hit) {
                $via = $areas[$hit]['recognized_via'] ?? [];
                $hasLegend = in_array('legenda', $via, true) || filled($areas[$hit]['legend_material'] ?? null);
                $existing = trim((string) ($areas[$hit]['legend_material'] ?? $areas[$hit]['tasks'][0]['work_name'] ?? ''));

                if ($hasLegend && $existing !== '') {
                    if ($this->materialsCompatible($existing, $product)) {
                        $areas[$hit]['recognized_via'] = $this->mergeVia($via, [$source]);
                        $areas[$hit]['material_confirmed_by_snijmaten'] = true;
                        unset($areas[$hit]['material_conflict']);
                        $areas[$hit]['needs_review'] = false;
                        if (in_array($areas[$hit]['confidence'] ?? '', ['controleren', ''], true)) {
                            $areas[$hit]['confidence'] = 'hoog';
                        }
                    } elseif ($this->snijmatenProductIsSubstantial($product) && count($matches) === 1) {
                        // Kleur+legenda blijft leidend; noteer afwijking voor handmatige controle.
                        // Bij meerdere ruimtes met dezelfde naam op één bouwlaag geen conflict forceren.
                        $areas[$hit]['material_conflict'] = $product;
                        $areas[$hit]['needs_review'] = true;
                        $areas[$hit]['confidence'] = 'controleren';
                    }
                } else {
                    // Fallback: kleur/legenda ontbreekt → Snijmaten mag materiaal invullen.
                    $areas[$hit]['legend_material'] = $product;
                    $areas[$hit]['tasks'] = $this->replaceFlooringTask(
                        $areas[$hit]['tasks'] ?? [],
                        $product,
                        $areas[$hit]['square_meters'] ?? null
                    );
                    $areas[$hit]['recognized_via'] = $this->mergeVia($via, [$source]);
                    $areas[$hit]['material_from_snijmaten'] = true;
                    $areas[$hit]['needs_review'] = false;
                    if (($areas[$hit]['confidence'] ?? '') === 'controleren' || ($areas[$hit]['confidence'] ?? '') === '') {
                        $areas[$hit]['confidence'] = 'midden';
                    }
                }

                $current = (string) ($areas[$hit]['source'] ?? 'plattegrond');
                if ($current === 'meetstaat') {
                    $areas[$hit]['source'] = 'beide';
                } elseif (! in_array($current, ['beide', 'gecombineerd'], true)) {
                    $areas[$hit]['source'] = 'gecombineerd';
                }
                $areas[$hit] = $this->withRecognition($areas[$hit]);
            }
        }

        return $areas;
    }

    /**
     * @param  array<string, mixed>  $area
     * @param  array<string, mixed>  $hint
     */
    private function roomsMatchForEnrichment(array $area, array $hint): bool
    {
        $floor = mb_strtolower((string) ($area['floor'] ?? ''));
        $hintFloor = mb_strtolower((string) ($hint['floor'] ?? ''));
        if ($floor === '' || $hintFloor === '' || $floor !== $hintFloor) {
            return false;
        }
        $number = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
        $hintNumber = $this->normalizeNumber((string) ($hint['room_number'] ?? ''));
        if ($number !== '' && $hintNumber !== '') {
            if ($number !== $hintNumber) {
                return false;
            }
            // Nummer is sterk, maar conflicterende namen mogen geen materiaal overschrijven.
            if ($this->roomLabelsConflict(
                (string) ($area['room_name'] ?? ''),
                (string) ($hint['room_name'] ?? '')
            )) {
                return false;
            }

            return true;
        }

        return $this->roomNamesCompatible(
            (string) ($area['room_name'] ?? ''),
            (string) ($hint['room_name'] ?? '')
        );
    }

    /**
     * True wanneer beide labels betekenisvol zijn en niet compatibel — dan geen merge.
     */
    private function roomLabelsConflict(string $left, string $right): bool
    {
        $left = $this->normalizeRoomNameKey($left);
        $right = $this->normalizeRoomNameKey($right);
        if ($left === '' || $right === '') {
            return false;
        }
        // Nummer-als-naam of generieke labels blokkeren geen merge.
        if ($this->isGenericRoomLabel($left) || $this->isGenericRoomLabel($right)) {
            return false;
        }

        return ! $this->roomNamesCompatible($left, $right);
    }

    private function isGenericRoomLabel(string $normalizedName): bool
    {
        if ($normalizedName === '' || $normalizedName === 'ruimte') {
            return true;
        }
        if (preg_match('/^[0-3][.\-]\d{1,3}[a-z]?$/u', $normalizedName)) {
            return true;
        }

        return false;
    }

    private function roomNamesCompatible(string $left, string $right): bool
    {
        $leftNames = $this->roomNameAliases($left);
        $rightNames = $this->roomNameAliases($right);
        if (array_intersect($leftNames, $rightNames) !== []) {
            return true;
        }

        $a = $this->normalizeRoomNameKey($left);
        $b = $this->normalizeRoomNameKey($right);
        if ($a === '' || $b === '') {
            return false;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;

            return mb_strlen($shorter) >= 5;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function roomNameAliases(string $name): array
    {
        $name = $this->normalizeRoomNameKey($name);
        if ($name === '') {
            return [];
        }

        $map = [
            'instructie' => ['instructie', 'instructieruimte'],
            'instructieruimte' => ['instructie', 'instructieruimte'],
        ];

        return $map[$name] ?? [$name];
    }

    private function normalizeRoomNameKey(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = str_replace(['/', '+'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return $name;
    }

    private function materialsCompatible(string $left, string $right): bool
    {
        $a = $this->normalizeMaterialKey($left);
        $b = $this->normalizeMaterialKey($right);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
            if (mb_strlen($shorter) >= 12) {
                return true;
            }
        }

        $shared = array_intersect(
            $this->materialSignatureTokens($a),
            $this->materialSignatureTokens($b)
        );

        return count($shared) >= 2;
    }

    /**
     * Striktere match voor totalen per materiaal: productcode eerst, daarna canonieke naam.
     * Geen kleurtinten of productlijnen samenvoegen zonder bewijs.
     */
    private function taskBelongsToMaterial(string $material, string $taskName): bool
    {
        return $this->materialsShareIdentity($material, $taskName);
    }

    /**
     * Korte/generieke taaknamen upgraden naar unieke canonieke variant wanneer bronnen dat bewijzen.
     *
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $declaredWorks
     * @return list<array<string, mixed>>
     */
    private function resolveCanonicalTaskMaterials(array $areas, array $declaredWorks): array
    {
        $candidates = [];
        foreach ($declaredWorks as $work) {
            $name = trim((string) ($work['name'] ?? ''));
            if ($name === '' || $this->materialIdentity()->isGenericTypeLabel($name)) {
                continue;
            }
            $candidates[] = $name;
        }
        $candidates = array_values(array_unique($candidates));
        if ($candidates === []) {
            return $areas;
        }

        foreach ($areas as &$area) {
            foreach ($area['tasks'] ?? [] as $index => $task) {
                if (! is_array($task)) {
                    continue;
                }
                $current = trim((string) ($task['work_name'] ?? ''));
                if ($current === '') {
                    continue;
                }
                $resolved = $this->materialIdentity()->resolveUniqueCanonical($current, $candidates);
                if ($resolved === null) {
                    $resolved = $this->resolveCanonicalByDeclaredTotal($current, $declaredWorks);
                }
                if ($resolved === null || $resolved === $current) {
                    continue;
                }
                $area['tasks'][$index]['work_name'] = $resolved;
                $area['tasks'][$index]['canonical_from'] = $current;
                $area['tasks'][$index]['canonical_evidence'] = 'unieke productidentiteit in bronnen';
            }
        }
        unset($area);

        return $areas;
    }

    /**
     * Afgebroken PDF-regel ("grey, Linoleum") koppelen aan de unieke canonieke variant
     * met dezelfde declared m², zodat een andere complete variant met dezelfde suffix niet wint.
     *
     * @param  list<array<string, mixed>>  $declaredWorks
     */
    private function resolveCanonicalByDeclaredTotal(string $shortOrPartial, array $declaredWorks): ?string
    {
        $needle = trim($shortOrPartial);
        $needleKey = $this->normalizeMaterialKey($needle);
        if ($needle === '' || mb_strlen($needleKey) < 8) {
            return null;
        }

        $needleDeclared = null;
        foreach ($declaredWorks as $work) {
            $name = trim((string) ($work['name'] ?? ''));
            if ($name === '' || $this->normalizeMaterialKey($name) !== $needleKey) {
                continue;
            }
            if (array_key_exists('declared_total', $work) && $work['declared_total'] !== null) {
                $needleDeclared = round((float) $work['declared_total'], 2);
                break;
            }
        }
        if ($needleDeclared === null || $needleDeclared <= 0) {
            return null;
        }

        $hits = [];
        foreach ($declaredWorks as $work) {
            $name = trim((string) ($work['name'] ?? ''));
            if ($name === '' || $this->materialIdentity()->isGenericTypeLabel($name)) {
                continue;
            }
            $candidateKey = $this->normalizeMaterialKey($name);
            if ($candidateKey === $needleKey || mb_strlen($candidateKey) <= mb_strlen($needleKey)) {
                continue;
            }
            if (! str_ends_with($candidateKey, $needleKey) && ! str_contains($candidateKey, $needleKey)) {
                continue;
            }
            if (! array_key_exists('declared_total', $work) || $work['declared_total'] === null) {
                continue;
            }
            if (abs(round((float) $work['declared_total'], 2) - $needleDeclared) > 0.10) {
                continue;
            }
            $hits[] = $name;
        }
        $hits = array_values(array_unique($hits));

        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * Dubbele PDF-tekst: zelfde productlabel (incl. afkorting), niet alleen gedeelde merktokens.
     */
    private function isDuplicateMaterialLabel(string $left, string $right): bool
    {
        if ($this->taskBelongsToMaterial($left, $right)) {
            return true;
        }
        $a = $this->normalizeMaterialKey($left);
        $b = $this->normalizeMaterialKey($right);
        if ($a === '' || $b === '') {
            return false;
        }
        $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $longer = mb_strlen($a) <= mb_strlen($b) ? $b : $a;

        return mb_strlen($shorter) >= 8 && str_starts_with($longer, $shorter);
    }

    private function snijmatenProductIsSubstantial(string $product): bool
    {
        $key = $this->normalizeMaterialKey($product);
        if (mb_strlen($key) < 12) {
            return false;
        }
        if (preg_match('/^(banen\s+)?vinyl\s+\d+/u', $key)) {
            return false;
        }

        return (bool) preg_match(
            '/tarkett|desso|marmoleum|entreemat|coral|granit|iq|safe|classics|oak|pink|clay|warm|sand|desert|directie|brush/u',
            $key
        );
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function hasReliableMaterialLink(array $area): bool
    {
        if ($this->areaHasFlooringTasks($area)) {
            return true;
        }

        if ((bool) ($area['material_from_snijmaten'] ?? false)
            || (bool) ($area['material_confirmed_by_snijmaten'] ?? false)) {
            return true;
        }

        $via = $area['recognized_via'] ?? [];
        if (in_array('kleur', $via, true) || in_array('legenda', $via, true) || filled($area['fill_color'] ?? null)) {
            return true;
        }

        return filled($area['legend_material'] ?? null)
            || filled($area['tasks'][0]['work_name'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function materialSignatureTokens(string $normalized): array
    {
        $stop = [
            'tarkett' => true, 'vinyl' => true, 'pvc' => true, 'banen' => true, 'natural' => true,
            'iq' => true, 'safe' => true, 't' => true, 'coating' => true, 'granit' => true,
            'ral' => true, 'met' => true, 'vlok' => true, 'white' => true, 'moss' => true,
            'dark' => true, 'light' => true, 'warm' => true, 'grey' => true, 'gray' => true,
            'blue' => true, 'green' => true, 'black' => true, 'dusty' => true, 'aqua' => true,
            'linoleum' => true, 'entreemat' => true,
        ];
        $tokens = [];
        foreach (preg_split('/\s+/', $normalized) ?: [] as $token) {
            if (mb_strlen($token) < 4 || isset($stop[$token])) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private function replaceFlooringTask(array $tasks, string $material, mixed $quantity): array
    {
        $kept = [];
        foreach ($tasks as $task) {
            $unit = (string) ($task['unit'] ?? WorkUnit::SquareMeter->value);
            if ($unit === WorkUnit::SquareMeter->value) {
                continue;
            }
            $kept[] = $task;
        }
        $kept[] = [
            'work_name' => $material,
            'unit' => WorkUnit::SquareMeter->value,
            'quantity' => (float) ($quantity ?? 0),
            'perimeter' => 0.0,
            'seams' => 0.0,
            'parts' => 1,
        ];

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $materialWorks
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $legend
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<array<string, mixed>>  $materialWorks
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $legend
     * @param  list<array<string, mixed>>  $meetstaatWorks
     * @return list<array<string, mixed>>
     */
    private function materialListCheck(
        array $materialWorks,
        array $areas,
        array $legend,
        array $meetstaatWorks = [],
        bool $meetstaatIsTaskSource = false,
    ): array {
        if ($materialWorks === []) {
            return [];
        }

        $rows = [];
        foreach ($materialWorks as $work) {
            $name = (string) ($work['name'] ?? '');
            $unit = (string) ($work['unit'] ?? WorkUnit::SquareMeter->value);
            if ($unit !== WorkUnit::SquareMeter->value) {
                continue;
            }
            $materialListNetto = array_key_exists('declared_total', $work) && $work['declared_total'] !== null
                ? (float) $work['declared_total']
                : null;
            $meetstaatDeclared = $this->declaredTotalForMaterialIdentity($meetstaatWorks, $name);
            $legendDeclared = $this->legendDeclaredTotalForMaterialIdentity($legend, $name);
            $calculated = 0.0;
            foreach ($areas as $area) {
                foreach ($area['tasks'] ?? [] as $task) {
                    if (! $this->isFlooringArea($task)) {
                        continue;
                    }
                    $taskName = (string) ($task['work_name'] ?? '');
                    if ($taskName === '' || ! $this->materialsShareIdentity($name, $taskName)) {
                        continue;
                    }
                    $calculated += (float) ($task['quantity'] ?? 0);
                }
            }
            $calculated = round($calculated, 2);

            // Meetstaat-netto is leidend voor de werkhoeveelheid; MaterialList is alleen controle.
            $taskExpected = $meetstaatDeclared !== null
                ? $meetstaatDeclared
                : $materialListNetto;
            $declaredKnown = $taskExpected !== null;
            $difference = $declaredKnown ? round($calculated - (float) $taskExpected, 2) : null;
            $status = $this->totalStatus($taskExpected, $calculated);

            $expectedSource = 'MaterialList';
            $explanation = null;
            $audit = null;
            $consensus = $this->strongSourceConsensus($meetstaatDeclared, $legendDeclared, $calculated);

            if ($meetstaatDeclared !== null) {
                $expectedSource = $consensus
                    ? 'Meetstaat + tekeninglegenda (bronconsensus)'
                    : 'Meetstaat';

                if ($materialListNetto !== null && abs($materialListNetto - $meetstaatDeclared) > 0.10) {
                    $ratio = $meetstaatDeclared > 0.0001
                        ? round($materialListNetto / $meetstaatDeclared, 2)
                        : null;
                    $audit = [
                        'meetstaat_declared' => $meetstaatDeclared,
                        'drawing_legend_total' => $legendDeclared,
                        'material_list_netto' => $materialListNetto,
                        'area_tasks' => $calculated,
                        'material_list_ratio_vs_meetstaat' => $ratio,
                        'approx_factor_two' => $ratio !== null && abs($ratio - 2.0) <= 0.05,
                        'consensus' => $consensus ? 'meetstaat_drawing_legend' : null,
                    ];
                    $explanation = sprintf(
                        'MaterialList Netto %.2f wijkt af van meetstaat %.2f%s; sterke bronnen blijven leidend (geen verdubbeling).',
                        $materialListNetto,
                        $meetstaatDeclared,
                        $legendDeclared !== null
                            ? sprintf(' / tekeninglegenda %.2f', $legendDeclared)
                            : ''
                    );
                    if ($ratio !== null && abs($ratio - 2.0) <= 0.05) {
                        $explanation .= sprintf(' MaterialList ≈ factor %.2f t.o.v. meetstaat (audit).', $ratio);
                    }
                    // MaterialList mag de import niet blokkeren: Meetstaat blijft leidend.
                    $status = ($status === 'ok' && abs($materialListNetto - $meetstaatDeclared) <= 0.10)
                        ? 'ok'
                        : 'informatief';
                }
            }

            $rows[] = [
                'material' => $name,
                'unit' => $unit,
                'unit_label' => WorkUnit::tryFrom($unit)?->label() ?? $unit,
                'declared_total' => $taskExpected,
                'declared_known' => $declaredKnown,
                'material_list_netto' => $materialListNetto,
                'meetstaat_declared' => $meetstaatDeclared,
                'drawing_legend_total' => $legendDeclared,
                'source_consensus' => $consensus,
                'calculated_total' => $calculated,
                'difference' => $difference,
                'expected_source' => $expectedSource,
                'explanation' => $explanation,
                'audit' => $audit,
                'status' => $status,
                'status_label' => match ($status) {
                    'ok' => 'OK',
                    'waarschuwing' => 'Waarschuwing',
                    'informatief' => 'Informatief — bronafwijking MaterialList',
                    'onbekend' => 'Onbekend',
                    default => 'Controleren',
                },
            ];
        }

        return $rows;
    }

    /**
     * Sterke bronconsensus: meetstaat + tekeninglegenda exact gelijk (±0,10),
     * en area_tasks volgen die bevestigde netto hoeveelheid.
     */
    private function strongSourceConsensus(?float $meetstaatDeclared, ?float $legendDeclared, float $taskMeters): bool
    {
        if ($meetstaatDeclared === null || $legendDeclared === null) {
            return false;
        }
        if (abs($meetstaatDeclared - $legendDeclared) > 0.10) {
            return false;
        }

        return abs($taskMeters - $meetstaatDeclared) <= 0.10;
    }

    /**
     * @param  list<array<string, mixed>>  $legend
     */
    private function legendDeclaredTotalForMaterialIdentity(array $legend, string $materialName): ?float
    {
        $total = 0.0;
        $found = false;
        foreach ($legend as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $unit = (string) ($entry['unit'] ?? WorkUnit::SquareMeter->value);
            if ($unit !== WorkUnit::SquareMeter->value) {
                continue;
            }
            $label = (string) (($entry['canonical_material'] ?? null) ?: ($entry['material'] ?? ''));
            if ($label === '' || ! $this->materialsShareIdentity($materialName, $label)) {
                continue;
            }
            if (! array_key_exists('declared_total', $entry) || $entry['declared_total'] === null) {
                continue;
            }
            $found = true;
            $total += (float) $entry['declared_total'];
        }

        return $found ? round($total, 2) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $works
     */
    private function declaredTotalForMaterialIdentity(array $works, string $materialName): ?float
    {
        foreach ($works as $work) {
            $name = (string) ($work['name'] ?? '');
            if ($name === '' || ! $this->materialsShareIdentity($materialName, $name)) {
                continue;
            }
            if (! array_key_exists('declared_total', $work) || $work['declared_total'] === null) {
                continue;
            }

            return round((float) $work['declared_total'], 2);
        }

        return null;
    }

    private function normalizeMaterialKey(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\b(banen|vinyl|pvc|tapijttegels?|coating|lvt)\b/u', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $legend
     * @param  list<array<string, mixed>>  $materialCheck
     * @param  array<string, mixed>  $drawingStyle
     * @param  list<array<string, mixed>>  $works
     * @param  list<array<string, mixed>>  $meetstaatAreas
     * @return array<string, mixed>
     */
    private function importReport(
        array $areas,
        int $duplicatesRemoved,
        array $legend = [],
        array $materialCheck = [],
        array $drawingStyle = [],
        array $works = [],
        array $meetstaatAreas = [],
        array $expectedTaskTotals = [],
    ): array {
        $hoog = 0;
        $midden = 0;
        $controleren = 0;
        $without = 0;
        $byColor = 0;
        $bySnijmaten = 0;
        $byBoth = 0;
        $unknownMaterial = 0;
        $physicalMeters = 0.0;
        $taskMeters = 0.0;
        foreach ($areas as $area) {
            $confidence = (string) ($area['confidence'] ?? 'controleren');
            if ($confidence === 'hoog') {
                $hoog++;
            } elseif ($confidence === 'midden') {
                $midden++;
            } else {
                $controleren++;
            }
            if (($area['square_meters'] ?? null) === null) {
                $without++;
            } else {
                $physicalMeters += (float) $area['square_meters'];
            }
            $taskMeters += $this->flooringTaskMeters($area);
            $source = (string) ($area['material_source'] ?? $this->materialSourceKey($area));
            if ($source === 'kleur_legenda') {
                $byColor++;
            } elseif ($source === 'snijmaten') {
                $bySnijmaten++;
            } elseif ($source === 'kleur_snijmaten') {
                $byBoth++;
            } elseif (in_array($source, ['meetstaat', 'meetstaat_kleur'], true)) {
                // Meetstaat-materiaal is bekend; geen onbekend-teller.
            } elseif ((string) ($area['source'] ?? '') === 'plattegrond' && ! $this->areaHasFlooringTasks($area)) {
                // Tekeningrest zonder vloertaak: geen onbekend materiaal wanneer taken uit meetstaat komen.
            } else {
                $unknownMaterial++;
            }
        }

        $meetstaatTaskMeters = 0.0;
        foreach ($meetstaatAreas as $area) {
            $meetstaatTaskMeters += $this->flooringTaskMeters($area);
        }

        if ($expectedTaskTotals === []) {
            $expectedTaskTotals = $this->buildExpectedTaskTotals($works, $meetstaatAreas);
        }
        $taskExpectedKnown = ! empty($expectedTaskTotals['known']);
        $taskExpected = $taskExpectedKnown ? (float) ($expectedTaskTotals['project_total'] ?? 0) : 0.0;

        $materialRows = $this->materialReports($works, $areas, $legend, $materialCheck, $expectedTaskTotals);
        // Harde legendastatus controleren is één bron met IMPORTCONTROLE; informatief telt niet als Controleren.
        $legendMismatch = collect($legend)->contains(fn (array $entry) => ($entry['status'] ?? '') === 'controleren');
        $legendInformative = collect($legend)->contains(fn (array $entry) => ($entry['status'] ?? '') === 'informatief');
        $materialMismatch = collect($materialRows)->contains(fn (array $entry) => ($entry['status'] ?? '') === 'controleren');

        $physical = round($physicalMeters, 2);
        $tasksFound = round($taskMeters, 2);
        $tasksExpected = $taskExpectedKnown ? round($taskExpected, 2) : null;
        $tasksDifference = $tasksExpected !== null ? round($tasksFound - $tasksExpected, 2) : null;
        $meetstaatTasks = round($meetstaatTaskMeters, 2);
        if ($meetstaatAreas !== [] && $tasksExpected === null) {
            $taskExpectedKnown = true;
            $tasksExpected = $meetstaatTasks;
            $tasksDifference = round($tasksFound - $meetstaatTasks, 2);
        }
        $taskSourceLost = $meetstaatAreas === []
            ? 0.0
            : round(max(0.0, $meetstaatTasks - $tasksFound), 2);

        $roundingOk = $tasksDifference !== null
            && abs($tasksDifference) <= 0.05
            && collect($materialRows)->every(function (array $row) {
                if (($row['status'] ?? '') === 'onbekend' || ($row['difference'] ?? null) === null) {
                    return true;
                }

                return abs((float) $row['difference']) <= 0.05;
            });
        $hardTaskGap = $tasksDifference !== null && abs($tasksDifference) > 0.05 && ! $roundingOk;
        $incomplete = $controleren > 0
            || $materialMismatch
            || $hardTaskGap
            || $legendMismatch
            || $taskSourceLost > 0.05;

        $linkedDrawing = 0;
        $missingPhysicalExplained = 0;
        foreach ($areas as $area) {
            $source = (string) ($area['source'] ?? '');
            $via = $area['recognized_via'] ?? [];
            if (in_array($source, ['plattegrond', 'beide', 'gecombineerd'], true)
                || in_array('tekening', is_array($via) ? $via : [], true)
                || filled($area['fill_color'] ?? null)
                || filled($area['contour_id'] ?? null)) {
                $linkedDrawing++;
            }
            if (($area['square_meters'] ?? null) === null) {
                // Meetstaat-only zonder tekeningcontour: informatief, geen harde fout.
                if (in_array($source, ['meetstaat', 'handmatig'], true)
                    && ! filled($area['fill_color'] ?? null)
                    && ! filled($area['contour_id'] ?? null)
                    && ! filled($area['x'] ?? null)) {
                    $missingPhysicalExplained++;
                }
            }
        }

        return [
            'rooms' => count($areas),
            'hoog' => $hoog,
            'midden' => $midden,
            'controleren' => $controleren,
            'duplicates_removed' => $duplicatesRemoved,
            'without_square_meters' => $without,
            'without_square_meters_explained' => $missingPhysicalExplained,
            'drawing_linked_rooms' => $linkedDrawing,
            'material_by_color' => $byColor,
            'material_by_snijmaten' => $bySnijmaten,
            'material_by_both' => $byBoth,
            'material_unknown' => $unknownMaterial,
            'physical_meters' => $physical,
            'task_meters' => $tasksFound,
            'meetstaat_task_meters' => $meetstaatAreas === [] ? null : $meetstaatTasks,
            'task_source_meters_parsed' => $meetstaatAreas === [] ? null : $meetstaatTasks,
            'task_source_meters_kept' => $tasksFound,
            'task_source_meters_lost' => $meetstaatAreas === [] ? null : $taskSourceLost,
            'task_meters_expected' => $tasksExpected,
            'task_meters_difference' => $tasksDifference,
            // Legacy aliases: nooit fysieke m² als "verwacht materiaal" presenteren.
            'meters_found' => $physical,
            'meters_expected' => $tasksExpected,
            'meters_difference' => $tasksDifference,
            'safe_fix_stats' => [
                'duplicate_rooms_removed' => $this->safeFixStats['duplicate_rooms_removed'],
                'duplicate_task_meters_removed' => round($this->safeFixStats['duplicate_task_meters_removed'], 2),
                'wrong_merge_prevented_meters' => round($this->safeFixStats['wrong_merge_prevented_meters'], 2),
                'wrong_merge_rooms_kept_separate' => $this->safeFixStats['wrong_merge_rooms_kept_separate'],
                'ocr_meter_matches' => $this->safeFixStats['ocr_meter_matches'],
                'implausible_numbers_cleared' => $this->safeFixStats['implausible_numbers_cleared'],
                'floor_reassignments' => $this->safeFixStats['floor_reassignments'],
                'floor_reassignment_meters' => round($this->safeFixStats['floor_reassignment_meters'], 2),
                'floor_reassignment_log' => $this->safeFixStats['floor_reassignment_log'],
                'removed_duplicates' => $this->safeFixStats['removed_duplicates'],
                'suppressed_drawing_tasks' => $this->safeFixStats['suppressed_drawing_tasks'],
                'suppressed_drawing_task_meters' => round($this->safeFixStats['suppressed_drawing_task_meters'], 2),
            ],
            'incomplete_recognition' => $incomplete,
            'quality_label' => $incomplete
                ? 'Onvolledige herkenning – controleren'
                : 'Import gereed',
            'legend_informative_gaps' => $legendInformative,
            'legend_hard_gaps' => $legendMismatch,
            'drawing_strategy' => $drawingStyle['strategy'] ?? null,
            'drawing_strategy_label' => $drawingStyle['strategy_label'] ?? null,
            'expected_task_totals' => $expectedTaskTotals,
            'floors' => $this->floorReports($areas, $meetstaatAreas),
            'materials' => $materialRows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $meetstaatAreas
     * @return list<array<string, mixed>>
     */
    private function floorReports(array $areas, array $meetstaatAreas = []): array
    {
        $buckets = [];
        foreach ($areas as $area) {
            $floor = (string) ($area['floor'] ?? 'Onbekend');
            $buckets[$floor] ??= [
                'floor' => $floor,
                'rooms' => 0,
                'physical_meters' => 0.0,
                'task_meters' => 0.0,
                'hoog' => 0,
                'midden' => 0,
                'controleren' => 0,
            ];
            $buckets[$floor]['rooms']++;
            if (($area['square_meters'] ?? null) !== null) {
                $buckets[$floor]['physical_meters'] += (float) $area['square_meters'];
            }
            $buckets[$floor]['task_meters'] += $this->flooringTaskMeters($area);
            $confidence = (string) ($area['confidence'] ?? 'controleren');
            if ($confidence === 'hoog') {
                $buckets[$floor]['hoog']++;
            } elseif ($confidence === 'midden') {
                $buckets[$floor]['midden']++;
            } else {
                $buckets[$floor]['controleren']++;
            }
        }

        $meetstaatByFloor = [];
        foreach ($meetstaatAreas as $area) {
            $floor = (string) ($area['floor'] ?? 'Onbekend');
            $meetstaatByFloor[$floor] = ($meetstaatByFloor[$floor] ?? 0.0) + $this->flooringTaskMeters($area);
        }

        $rows = [];
        foreach ($buckets as $floor => $row) {
            $physical = round($row['physical_meters'], 2);
            $taskMeters = round($row['task_meters'], 2);
            $meetstaatTask = array_key_exists($floor, $meetstaatByFloor)
                ? round($meetstaatByFloor[$floor], 2)
                : null;
            $rows[] = [
                'floor' => $floor,
                'rooms' => $row['rooms'],
                'physical_meters' => $physical,
                'task_meters' => $taskMeters,
                'meetstaat_task_meters' => $meetstaatTask,
                'task_meters_difference' => $meetstaatTask !== null ? round($taskMeters - $meetstaatTask, 2) : null,
                // Legacy keys for older views/tests.
                'meters_found' => $physical,
                'meters_expected' => $meetstaatTask,
                'meters_difference' => $meetstaatTask !== null ? round($taskMeters - $meetstaatTask, 2) : null,
                'hoog' => $row['hoog'],
                'midden' => $row['midden'],
                'controleren' => $row['controleren'],
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['floor'], $b['floor']));

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $works
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $legend
     * @param  list<array<string, mixed>>  $materialCheck
     * @return list<array<string, mixed>>
     */
    private function materialReports(array $works, array $areas, array $legend, array $materialCheck, array $expectedTaskTotals = []): array
    {
        if ($materialCheck !== []) {
            return array_values(array_map(function (array $entry) {
                return [
                    'material' => $entry['material'] ?? '',
                    'found_task_meters' => round((float) ($entry['calculated_total'] ?? 0), 2),
                    'expected_task_meters' => ! empty($entry['declared_known']) ? round((float) ($entry['declared_total'] ?? 0), 2) : null,
                    'material_list_netto' => $entry['material_list_netto'] ?? null,
                    'meetstaat_declared' => $entry['meetstaat_declared'] ?? null,
                    'drawing_legend_total' => $entry['drawing_legend_total'] ?? null,
                    'source_consensus' => $entry['source_consensus'] ?? false,
                    'difference' => $entry['difference'] ?? null,
                    'expected_source' => $entry['expected_source'] ?? $entry['source'] ?? 'MaterialList',
                    'explanation' => $entry['explanation'] ?? null,
                    'audit' => $entry['audit'] ?? null,
                    'status' => $entry['status'] ?? 'onbekend',
                    'status_label' => $entry['status_label'] ?? 'Onbekend',
                ];
            }, $materialCheck));
        }

        $expectedByMaterial = [];
        foreach ($expectedTaskTotals['by_material'] ?? [] as $row) {
            $expectedByMaterial[(string) ($row['material'] ?? '')] = [
                'expected' => (float) ($row['expected_task_meters'] ?? 0),
                'source' => (string) ($row['source'] ?? 'materiaalblok_eindtotaal'),
            ];
        }

        $rows = [];
        foreach ($this->primaryFlooringWorks($works) as $work) {
            $name = (string) ($work['name'] ?? '');
            $found = 0.0;
            foreach ($areas as $area) {
                foreach ($area['tasks'] ?? [] as $task) {
                    if (! $this->isFlooringArea($task)) {
                        continue;
                    }
                    if (! $this->taskBelongsToMaterial($name, (string) ($task['work_name'] ?? ''))) {
                        continue;
                    }
                    $found += (float) ($task['quantity'] ?? 0);
                }
            }

            $expectedKnown = false;
            $expected = null;
            $expectedSource = '—';
            if (isset($expectedByMaterial[$name])) {
                $expectedKnown = true;
                $expected = $expectedByMaterial[$name]['expected'];
                $expectedSource = 'Materiaalblok-eindtotaal';
            } else {
                $fromWorks = array_key_exists('declared_total', $work) && $work['declared_total'] !== null
                    && (float) $work['declared_total'] > 0;
                $expectedKnown = $fromWorks;
                $expected = $fromWorks ? (float) $work['declared_total'] : null;
                $expectedSource = $fromWorks ? 'Materiaalblok-eindtotaal' : '—';
                // Legenda alleen als er geen project-materiaalblok is.
                if (! $expectedKnown && empty($expectedTaskTotals['known'])) {
                    foreach ($legend as $entry) {
                        if (($entry['unit'] ?? WorkUnit::SquareMeter->value) !== WorkUnit::SquareMeter->value) {
                            continue;
                        }
                        if (! $this->taskBelongsToMaterial($name, (string) ($entry['material'] ?? ''))) {
                            continue;
                        }
                        if (! empty($entry['declared_known']) && ($entry['declared_total'] ?? null) !== null) {
                            $expectedKnown = true;
                            $expected = (float) $entry['declared_total'];
                            $expectedSource = 'Tekeninglegenda';
                            break;
                        }
                    }
                }
            }
            $found = round($found, 2);
            $difference = $expectedKnown ? round($found - (float) $expected, 2) : null;
            $status = $this->totalStatus($expected, $found);
            $rows[] = [
                'material' => $name,
                'found_task_meters' => $found,
                'expected_task_meters' => $expectedKnown ? round((float) $expected, 2) : null,
                'difference' => $difference,
                'expected_source' => $expectedSource,
                'status' => $status,
                'status_label' => match ($status) {
                    'ok' => 'OK',
                    'waarschuwing' => 'Waarschuwing',
                    'informatief' => 'Informatief',
                    'onbekend' => 'Onbekend',
                    default => 'Controleren',
                },
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $works
     * @return list<array<string, mixed>>
     */
    private function primaryFlooringWorks(array $works): array
    {
        $flooring = [];
        foreach ($works as $work) {
            if ($this->isFlooringWork($work)) {
                $flooring[] = $work;
            }
        }

        $normalized = [];
        foreach ($flooring as $index => $work) {
            $normalized[$index] = $this->normalizeMaterialKey((string) ($work['name'] ?? ''));
        }

        $kept = [];
        foreach ($flooring as $index => $work) {
            $name = $normalized[$index];
            if ($name === '') {
                continue;
            }
            $isAlias = false;
            foreach ($normalized as $otherIndex => $otherName) {
                if ($otherIndex === $index || $otherName === '' || $otherName === $name) {
                    continue;
                }
                if (str_starts_with($otherName, $name) && mb_strlen($name) >= 12) {
                    $isAlias = true;
                    break;
                }
            }
            if (! $isAlias) {
                $kept[] = $work;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function flooringTaskMeters(array $area): float
    {
        $total = 0.0;
        foreach ($area['tasks'] ?? [] as $task) {
            if ($this->isFlooringArea($task)) {
                $total += (float) ($task['quantity'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $work
     */
    private function isFlooringWork(array $work): bool
    {
        $unit = (string) ($work['unit'] ?? WorkUnit::SquareMeter->value);
        if ($unit !== WorkUnit::SquareMeter->value) {
            return false;
        }
        $name = mb_strtolower((string) ($work['name'] ?? ''));

        return $name !== '' && ! str_contains($name, 'plint');
    }

    /**
     * @param  list<array<string, mixed>>  $primary
     * @param  list<array<string, mixed>>  $secondary
     * @return list<array<string, mixed>>
     */
    private function mergeRoomLists(array $primary, array $secondary): array
    {
        $merged = $primary;
        $index = $this->roomIndex($merged);

        foreach ($secondary as $area) {
            $hit = $this->findRoomIndex($index, $area);
            if ($hit === null) {
                $merged[] = $area;
                $index = $this->roomIndex($merged);

                continue;
            }

            $merged[$hit]['tasks'] = $this->appendUniqueTasks($merged[$hit]['tasks'] ?? [], $area['tasks'] ?? []);
            $currentName = (string) ($merged[$hit]['room_name'] ?? '');
            $incomingName = (string) ($area['room_name'] ?? '');
            $number = (string) ($merged[$hit]['room_number'] ?? '');
            if (($currentName === '' || $currentName === $number) && $incomingName !== '' && $incomingName !== $number) {
                $merged[$hit]['room_name'] = $incomingName;
            }
            $origin = $this->roomOrigin($merged[$hit]);
            $incomingOrigin = $this->roomOrigin($area);
            if ($origin !== $incomingOrigin) {
                $merged[$hit]['source'] = 'gecombineerd';
            }
        }

        return $merged;
    }

    /**
     * Materialenstaat is leidend voor de projectkop wanneer die bron aanwezig is.
     * Andere bestanden vullen alleen ontbrekende velden en worden op werknummer/referentie vergeleken.
     *
     * @param  array<string, mixed>|null  $materials
     * @param  array<string, mixed>|null  $meetstaat
     * @param  array<string, mixed>|null  $snijmaten
     * @param  array<string, mixed>|null  $drawing
     * @return array{header: array<string, mixed>, mismatches: list<array<string, string>>}
     */
    private function resolveProjectHeader(?array $materials, ?array $meetstaat, ?array $snijmaten, ?array $drawing): array
    {
        $merged = ProjectDocumentHeader::empty();
        $mismatches = [];
        $source = null;
        $sourceLabel = null;

        $candidates = [
            'materialenstaat' => ['header' => $materials, 'label' => 'Materialenstaat', 'primary' => true],
            'meetstaat' => ['header' => $meetstaat, 'label' => 'Meetstaat', 'primary' => false],
            'snijmaten' => ['header' => $snijmaten, 'label' => 'Snijmaten', 'primary' => false],
            'plattegrond' => ['header' => $drawing, 'label' => 'Plattegrond', 'primary' => false],
        ];

        $hasMaterials = is_array($materials) && $this->headerHasIdentity($materials);

        if ($hasMaterials) {
            foreach ($materials as $key => $value) {
                if (($merged[$key] ?? null) === null && filled($value)) {
                    $merged[$key] = is_string($value) ? $value : (string) $value;
                }
            }
            // Werknummer altijd als tekst bewaren.
            if (array_key_exists('project_number', $materials) && filled($materials['project_number'])) {
                $merged['project_number'] = trim((string) $materials['project_number']);
            }
            $source = 'materialenstaat';
            $sourceLabel = 'Materialenstaat';
        }

        foreach ($candidates as $key => $candidate) {
            $header = $candidate['header'];
            if (! is_array($header)) {
                continue;
            }

            if ($hasMaterials && ! $candidate['primary']) {
                $mismatches = array_merge(
                    $mismatches,
                    ProjectDocumentHeader::mismatchesAgainstPrimary($merged, $header, $candidate['label']),
                );
            }

            if ($candidate['primary'] && $hasMaterials) {
                continue;
            }

            foreach ($header as $field => $value) {
                if (($merged[$field] ?? null) !== null || blank($value)) {
                    continue;
                }
                $merged[$field] = $field === 'project_number' ? trim((string) $value) : $value;
                if ($source === null) {
                    $source = $key;
                    $sourceLabel = $candidate['label'];
                }
            }
        }

        if ($merged['project_name'] === null && filled($merged['reference'])) {
            $merged['project_name'] = (string) $merged['reference'];
        }

        if ($source !== null) {
            $merged['source'] = $source;
            $merged['source_label'] = $sourceLabel;
        }

        return [
            'header' => $merged,
            'mismatches' => array_values($mismatches),
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function headerHasIdentity(array $header): bool
    {
        return filled($header['customer_name'] ?? null)
            || filled($header['reference'] ?? null)
            || filled($header['project_number'] ?? null)
            || filled($header['project_name'] ?? null)
            || filled($header['date'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function roomOrigin(array $area): string
    {
        $source = (string) ($area['source'] ?? 'meetstaat');
        if (in_array($source, ['meetstaat', 'materialenstaat', 'snijmaten', 'gecombineerd'], true)) {
            return $source;
        }

        return 'meetstaat';
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, int>
     */
    private function roomIndex(array $areas): array
    {
        $index = [];
        foreach ($areas as $i => $area) {
            $number = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
            $floor = mb_strtolower((string) ($area['floor'] ?? ''));
            $name = mb_strtolower((string) ($area['room_name'] ?? ''));
            if ($number !== '') {
                $index['n:'.$floor.'|'.$number] = $i;
            }
            if ($name !== '' && empty($area['keep_separate'])) {
                $index['f:'.$floor.'|'.$name] = $i;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, int>  $index
     * @param  array<string, mixed>  $area
     */
    private function findRoomIndex(array $index, array $area): ?int
    {
        $number = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
        $floor = mb_strtolower((string) ($area['floor'] ?? ''));
        $name = mb_strtolower((string) ($area['room_name'] ?? ''));

        if ($number !== '' && isset($index['n:'.$floor.'|'.$number])) {
            return $index['n:'.$floor.'|'.$number];
        }
        if (($area['keep_separate'] ?? false) === true) {
            return null;
        }
        if ($name !== '' && isset($index['f:'.$floor.'|'.$name])) {
            return $index['f:'.$floor.'|'.$name];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    private function collapseDuplicateTasks(array $areas): array
    {
        foreach ($areas as $index => $area) {
            $collapsed = [];
            foreach ($area['tasks'] ?? [] as $task) {
                $collapsed = $this->mergeTaskIntoList($collapsed, $task);
            }
            $areas[$index]['tasks'] = $collapsed;
        }

        return $areas;
    }

    /**
     * Voeg taken toe zonder dubbele PDF-tekst mee te tellen.
     * Zelfde materiaal + vrijwel gelijke m² → één taak (langste naam wint).
     * Zelfde genormaliseerde naam + duidelijk andere m² → optellen (meerdere deelvlakken).
     *
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function appendUniqueTasks(array $existing, array $incoming): array
    {
        foreach ($incoming as $task) {
            $existing = $this->mergeTaskIntoList($existing, $task);
        }

        return $existing;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @param  array<string, mixed>  $task
     * @return list<array<string, mixed>>
     */
    private function mergeTaskIntoList(array $tasks, array $task): array
    {
        $name = trim((string) ($task['work_name'] ?? ''));
        if ($name === '') {
            return $tasks;
        }

        $quantity = (float) ($task['quantity'] ?? 0);
        $unit = $this->taskUnitValue($task);

        foreach ($tasks as $index => $existingTask) {
            $existingName = trim((string) ($existingTask['work_name'] ?? ''));
            if ($existingName === '' || $this->taskUnitValue($existingTask) !== $unit) {
                continue;
            }
            $incomingCodes = $this->materialIdentity()->workCodes($name);
            $existingCodes = $this->materialIdentity()->workCodes($existingName);
            if ($incomingCodes !== [] && $existingCodes !== [] && array_intersect($incomingCodes, $existingCodes) === []) {
                continue;
            }
            if ($incomingCodes !== [] && $existingCodes !== []
                && ! $this->materialIdentity()->sameExecutionVariant($name, $existingName)) {
                continue;
            }
            if (! $this->isDuplicateMaterialLabel($existingName, $name)) {
                continue;
            }

            $existingQty = (float) ($existingTask['quantity'] ?? 0);
            if ($this->quantitiesNearlyEqual($existingQty, $quantity)) {
                // Dubbele PDF-tekst / afgekorte + volledige naam: niet dubbel tellen.
                if (mb_strlen($name) > mb_strlen($existingName)) {
                    $tasks[$index]['work_name'] = $name;
                }

                return $tasks;
            }

            $sameNormalized = $this->normalizeMaterialKey($existingName) === $this->normalizeMaterialKey($name);
            if ($sameNormalized) {
                // Tekening-deelvlak dat al in het meetstaat-totaal zit: niet optellen.
                if ($quantity < $existingQty - 0.05) {
                    return $tasks;
                }

                $tasks[$index]['quantity'] = round($existingQty + $quantity, 2);
                $tasks[$index]['perimeter'] = round(
                    (float) ($existingTask['perimeter'] ?? 0) + (float) ($task['perimeter'] ?? 0),
                    2
                );
                $tasks[$index]['seams'] = round(
                    (float) ($existingTask['seams'] ?? 0) + (float) ($task['seams'] ?? 0),
                    2
                );
                $tasks[$index]['parts'] = max(
                    1,
                    (int) ($existingTask['parts'] ?? 1) + (int) ($task['parts'] ?? 1)
                );

                return $tasks;
            }
        }

        $tasks[] = $task;

        return $tasks;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function taskUnitValue(array $task): string
    {
        $unit = $task['unit'] ?? WorkUnit::SquareMeter->value;

        return $unit instanceof WorkUnit ? $unit->value : (string) $unit;
    }

    private function quantitiesNearlyEqual(float $left, float $right, float $tolerance = 0.05): bool
    {
        return abs($left - $right) <= $tolerance;
    }

    private function resetSafeFixStats(): void
    {
        $this->safeFixStats = [
            'duplicate_rooms_removed' => 0,
            'duplicate_task_meters_removed' => 0.0,
            'wrong_merge_prevented_meters' => 0.0,
            'wrong_merge_rooms_kept_separate' => 0,
            'ocr_meter_matches' => 0,
            'implausible_numbers_cleared' => 0,
            'floor_reassignments' => 0,
            'floor_reassignment_meters' => 0.0,
            'floor_reassignment_log' => [],
            'removed_duplicates' => [],
            'suppressed_drawing_tasks' => [],
            'suppressed_drawing_task_meters' => 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $area
     * @return array<string, mixed>
     */
    private function splitGluedRoomIdentity(array $area): array
    {
        $number = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
        $name = trim((string) ($area['room_name'] ?? ''));
        if ($number !== '' || $name === '') {
            return $area;
        }

        // "0.34a" → volledig nummer. "0.08berging" → 0.08 + berging.
        if (preg_match('/^([0-3][.\-]\d{1,3})([a-zA-Z])(.*)$/u', $name, $match)) {
            if ($match[3] === '') {
                $area['room_number'] = $match[1].$match[2];
                $area['number_from_glued_name'] = true;

                return $area;
            }
            $area['room_number'] = $match[1];
            $area['room_name'] = $match[2].$match[3];
            $area['number_from_glued_name'] = true;

            return $area;
        }

        if (preg_match('/^([0-3][.\-]\d{1,3})$/u', $name, $match)) {
            $area['room_number'] = $match[1];
            $area['number_from_glued_name'] = true;
        }

        return $area;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  list<array<string, mixed>>  $existing
     */
    private function isGhostDuplicateOfExisting(array $incoming, array $existing): bool
    {
        $incoming = $this->splitGluedRoomIdentity($incoming);
        $floor = mb_strtolower((string) ($incoming['floor'] ?? ''));
        $number = $this->normalizeNumber((string) ($incoming['room_number'] ?? ''));
        $incomingName = $this->normalizeRoomNameKey((string) ($incoming['room_name'] ?? ''));
        $fromGlued = ($incoming['number_from_glued_name'] ?? false) === true;

        // Alleen echte ghost-kandidaten: geplakt nummer, of géén nummer.
        // Genummerde, normaal herkende ruimtes niet tegen elkaar wegstrepen.
        if ($number !== '' && ! $fromGlued) {
            return false;
        }

        $incomingMeters = $incoming['square_meters'] ?? null;
        $incomingTaskMeters = $this->flooringTaskMeters($incoming);
        $compareMeters = $incomingMeters !== null
            ? (float) $incomingMeters
            : ($incomingTaskMeters > 0.0001 ? $incomingTaskMeters : null);
        if ($compareMeters === null) {
            return false;
        }

        $ghostHits = 0;
        $hitIndex = null;
        foreach ($existing as $index => $area) {
            $areaFloor = mb_strtolower((string) ($area['floor'] ?? ''));
            if ($floor !== '' && $areaFloor !== '' && $floor !== $areaFloor) {
                continue;
            }

            $areaNumber = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
            $numberMatch = $number !== '' && $areaNumber === $number;
            $nameMatch = ! $this->isGenericRoomLabel($incomingName)
                && $this->roomNamesCompatible(
                    (string) ($incoming['room_name'] ?? ''),
                    (string) ($area['room_name'] ?? '')
                );

            if (! $numberMatch) {
                if ($number !== '' || ! $nameMatch) {
                    continue;
                }
                // Naamloze ghost: alleen tegen een bestaande meetstaat/beide-ruimte (genummerd of niet).
                $existingSource = (string) ($area['source'] ?? '');
                if (! in_array($existingSource, ['meetstaat', 'beide'], true) && $areaNumber === '') {
                    continue;
                }
            }

            $areaPhys = $area['square_meters'] ?? null;
            $physicalHit = $areaPhys !== null && $this->quantitiesNearlyEqual((float) $compareMeters, (float) $areaPhys, 0.05);
            $taskHit = $this->hasNearEqualFlooringTask($area, $incoming, (float) $compareMeters);
            if ((! $physicalHit && ! $taskHit) || ! $this->roomsGeometricallyNear($incoming, $area)) {
                continue;
            }

            $ghostHits++;
            $hitIndex = (int) $index;
            if ($ghostHits > 1) {
                return false;
            }
        }

        if ($ghostHits !== 1 || $hitIndex === null || ! isset($existing[$hitIndex])) {
            return false;
        }

        if (blank($incoming['fill_color'] ?? null)
            && $this->drawingMatchHasCompetingHosts($existing[$hitIndex], $incoming, $existing, $hitIndex)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     */
    private function hasNearEqualFlooringTask(array $existing, array $incoming, float $meters): bool
    {
        $incomingMaterials = [];
        foreach ($incoming['tasks'] ?? [] as $task) {
            if ($this->isFlooringArea($task)) {
                $incomingMaterials[] = (string) ($task['work_name'] ?? '');
            }
        }
        if ($incomingMaterials === [] && filled($incoming['legend_material'] ?? null)) {
            $incomingMaterials[] = (string) $incoming['legend_material'];
        }

        foreach ($existing['tasks'] ?? [] as $task) {
            if (! $this->isFlooringArea($task)) {
                continue;
            }
            if (! $this->quantitiesNearlyEqual((float) ($task['quantity'] ?? 0), $meters, 0.05)) {
                continue;
            }
            $taskName = (string) ($task['work_name'] ?? '');
            foreach ($incomingMaterials as $material) {
                if ($material === '' || $this->taskBelongsToMaterial($material, $taskName) || $this->materialsCompatible($material, $taskName)) {
                    return true;
                }
            }
            if ($incomingMaterials === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function roomsGeometricallyNear(array $left, array $right): bool
    {
        $pageLeft = (int) ($left['page'] ?? 0);
        $pageRight = (int) ($right['page'] ?? 0);
        if ($pageLeft > 0 && $pageRight > 0 && $pageLeft !== $pageRight) {
            return false;
        }

        $lx = $left['meter_x'] ?? $left['x'] ?? null;
        $ly = $left['meter_y'] ?? $left['y'] ?? null;
        $rx = $right['meter_x'] ?? $right['x'] ?? null;
        $ry = $right['meter_y'] ?? $right['y'] ?? null;
        if ($lx === null || $ly === null || $rx === null || $ry === null) {
            // Geen contour/positie: laat nummer+m²+materiaal leidend zijn.
            return true;
        }

        $dx = (float) $lx - (float) $rx;
        $dy = (float) $ly - (float) $ry;

        return sqrt(($dx * $dx) + ($dy * $dy)) <= 120.0;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function recordDuplicateRemoval(array $area): void
    {
        $meters = $this->flooringTaskMeters($area);
        if ($meters <= 0.0001 && ($area['square_meters'] ?? null) !== null) {
            $meters = (float) $area['square_meters'];
        }
        $this->safeFixStats['duplicate_rooms_removed']++;
        $this->safeFixStats['duplicate_task_meters_removed'] = round(
            $this->safeFixStats['duplicate_task_meters_removed'] + $meters,
            2
        );
        if (count($this->safeFixStats['removed_duplicates']) < 40) {
            $this->safeFixStats['removed_duplicates'][] = [
                'floor' => (string) ($area['floor'] ?? ''),
                'room_number' => (string) ($area['room_number'] ?? ''),
                'room_name' => (string) ($area['room_name'] ?? ''),
                'meters' => round($meters, 2),
                'material' => (string) ($area['legend_material'] ?? $area['tasks'][0]['work_name'] ?? ''),
                'page' => $area['page'] ?? null,
                'glued' => (bool) ($area['number_from_glued_name'] ?? false),
            ];
        }
    }

    /**
     * Ruimtenummer moet bij de bouwlaag passen (0.x op BG, 1.x op V1, 2.x op V2).
     * Voorkomt dat maatvoering (bijv. 3,66 m²) als ruimtenummer 3.66 op begane grond belandt.
     *
     * @param  array<string, mixed>  $area
     * @return array<string, mixed>
     */
    private function applyFloorNumberPlausibility(array $area): array
    {
        $number = $this->normalizeNumber((string) ($area['room_number'] ?? ''));
        if ($number === '' || $this->roomNumberPlausibleForFloor($number, (string) ($area['floor'] ?? ''))) {
            return $area;
        }

        $area['implausible_room_number'] = $area['room_number'];
        $area['room_number'] = null;
        $area['needs_review'] = true;
        $area['confidence'] = 'controleren';
        $this->safeFixStats['implausible_numbers_cleared']++;

        return $area;
    }

    /**
     * Tekeningruimtes die op de basisbouwlaag staan maar bij een sub-bouwlaag horen
     * (bijv. "begane grond sporthal") herstellen op basis van meetstaat + pagina/sectie.
     *
     * @param  list<array<string, mixed>>  $drawingAreas
     * @param  list<array<string, mixed>>  $meetstaatAreas
     * @return list<array<string, mixed>>
     */
    private function reassignDrawingSubfloors(array $drawingAreas, array $meetstaatAreas): array
    {
        if ($drawingAreas === [] || $meetstaatAreas === []) {
            return $drawingAreas;
        }

        $meetstaatByFloor = [];
        foreach ($meetstaatAreas as $area) {
            $floor = mb_strtolower(trim((string) ($area['floor'] ?? '')));
            if ($floor === '') {
                continue;
            }
            $meetstaatByFloor[$floor][] = $area;
        }

        $subfloorsByBase = $this->qualifiedFloorsByBase(array_keys($meetstaatByFloor));
        if ($subfloorsByBase === []) {
            return $drawingAreas;
        }

        $sectionPages = $this->detectSubfloorSectionPages($drawingAreas, $meetstaatByFloor, $subfloorsByBase);

        foreach ($drawingAreas as $index => $area) {
            if ($this->shouldSkipSubfloorReassignment($area)) {
                continue;
            }

            $currentFloor = mb_strtolower(trim((string) ($area['floor'] ?? '')));
            if ($currentFloor === '' || str_contains($currentFloor, 'sporthal')) {
                continue;
            }
            $targets = $subfloorsByBase[$currentFloor] ?? [];
            if ($targets === []) {
                continue;
            }

            $page = (int) ($area['page'] ?? 0);
            $pageTarget = $sectionPages[$page] ?? null;
            $currentMatch = $this->bestSubfloorMatch($area, $meetstaatByFloor[$currentFloor] ?? []);
            $bestTarget = null;
            $bestTargetMatch = null;
            foreach ($targets as $targetFloor) {
                $match = $this->bestSubfloorMatch($area, $meetstaatByFloor[$targetFloor] ?? []);
                if ($match === null || ! $match['strong']) {
                    continue;
                }
                if ($bestTargetMatch === null || $match['score'] > $bestTargetMatch['score']) {
                    $bestTarget = $targetFloor;
                    $bestTargetMatch = $match;
                }
            }

            if ($bestTarget === null || $bestTargetMatch === null) {
                continue;
            }

            $currentStrong = ($currentMatch['strong'] ?? false) === true;
            $currentScore = (float) ($currentMatch['score'] ?? 0);
            if ($currentStrong && $currentScore >= $bestTargetMatch['score']) {
                continue;
            }
            // Bij conflict op huidige bouwlaag (zelfde nr, andere naam) mag sectiematch winnen.
            if ($currentStrong && ! ($currentMatch['name_conflict'] ?? false) && $pageTarget !== $bestTarget) {
                continue;
            }
            if ($pageTarget !== null && $pageTarget !== $bestTarget && ! $bestTargetMatch['exact_triple']) {
                continue;
            }
            if ($pageTarget === null && ! $bestTargetMatch['exact_triple'] && ! ($currentMatch['name_conflict'] ?? false)) {
                // Zonder sectiepagina alleen bij overduidelijke triple-match of nr-conflict op huidige laag.
                continue;
            }

            $fromLabel = (string) ($area['floor'] ?? '');
            $toLabel = $this->canonicalFloorLabel($bestTarget, $meetstaatAreas);
            $fromNumber = (string) (
                ($area['room_number'] ?? null)
                ?? ($area['implausible_room_number'] ?? null)
                ?? ''
            );
            $reasonParts = [
                'bouwlaag hersteld: '.$fromLabel.' → '.$toLabel,
                ($bestTargetMatch['number_match'] ?? false) ? 'nr exact' : 'nr via meetstaat',
                ($bestTargetMatch['name_match'] ?? false) ? 'naam sterk' : 'naam zwak',
                ($bestTargetMatch['meters_match'] ?? false) ? 'm² exact/deel' : 'm² niet leidend',
                $pageTarget === $bestTarget ? 'positie via tekeningpagina' : 'sectie via matchsterkte',
            ];
            $reason = implode('; ', $reasonParts);

            $area['floor_reassigned_from'] = $fromLabel;
            $area['floor'] = $toLabel;
            $area['floor_reassignment_reason'] = $reason;
            if (($bestTargetMatch['room_number'] ?? '') !== ''
                && ($this->normalizeNumber((string) ($area['room_number'] ?? '')) === ''
                    || ($bestTargetMatch['number_match'] ?? false) === false)) {
                // Herstel nummer wanneer OCR/maatvoering het wegveegde of verwisselde (3.8 → 0.03).
                $delta = $bestTargetMatch['meter_delta'] ?? null;
                if (($bestTargetMatch['name_match'] ?? false)
                    && ($bestTargetMatch['meters_match'] ?? false)
                    && $delta !== null
                    && $delta <= 0.05) {
                    $area['room_number'] = $bestTargetMatch['room_number'];
                    unset($area['implausible_room_number']);
                }
            }

            $meters = $this->flooringTaskMeters($area);
            if ($meters <= 0.0001 && ($area['square_meters'] ?? null) !== null) {
                $meters = (float) $area['square_meters'];
            }
            $this->safeFixStats['floor_reassignments']++;
            $this->safeFixStats['floor_reassignment_meters'] = round(
                $this->safeFixStats['floor_reassignment_meters'] + $meters,
                2
            );
            if (count($this->safeFixStats['floor_reassignment_log']) < 30) {
                $this->safeFixStats['floor_reassignment_log'][] = [
                    'page' => $page > 0 ? $page : null,
                    'from_floor' => $fromLabel,
                    'to_floor' => $toLabel,
                    'from_number' => $fromNumber,
                    'to_number' => (string) ($area['room_number'] ?? ''),
                    'room_name' => (string) ($area['room_name'] ?? ''),
                    'meters' => round($meters, 2),
                    'reason' => $reason,
                ];
            }

            $drawingAreas[$index] = $area;
        }

        return $drawingAreas;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function shouldSkipSubfloorReassignment(array $area): bool
    {
        $name = $this->normalizeRoomNameKey((string) ($area['room_name'] ?? ''));
        if ($name === '' || $name === 'ruimte' || preg_match('/^ruimte\s*\d+$/u', $name)) {
            return true;
        }
        if ($name === 'toilet' || $name === 'toiletruimte') {
            return true;
        }
        // Bewust niet: één-cijfer-afwijkingen of losse fragmenten zonder sterke sporthal-identiteit.
        if (str_contains($name, 'egel') || str_contains($name, 'lerplein') || str_contains($name, 'leerplein')) {
            return true;
        }

        return false;
    }

    /**
     * Gekwalificeerde bouwlagen (fase, sporthal, souterrain) gegroepeerd op basislaag.
     *
     * @param  list<string>  $floors
     * @return array<string, list<string>>
     */
    private function qualifiedFloorsByBase(array $floors): array
    {
        $grouped = [];
        foreach ($floors as $floor) {
            $normalized = mb_strtolower(trim($floor));
            if ($normalized === '' || $normalized === 'onbekend') {
                continue;
            }
            $base = $this->floorLabel()->base($normalized);
            if ($base === '' || $base === $normalized) {
                continue;
            }
            $grouped[$base][] = $normalized;
        }

        return $grouped;
    }

    /**
     * @param  list<array<string, mixed>>  $drawingAreas
     * @param  array<string, list<array<string, mixed>>>  $meetstaatByFloor
     * @param  array<string, list<string>>  $subfloorsByBase
     * @return array<int, string> page => target subfloor
     */
    private function detectSubfloorSectionPages(
        array $drawingAreas,
        array $meetstaatByFloor,
        array $subfloorsByBase
    ): array {
        $votes = [];
        foreach ($drawingAreas as $area) {
            if ($this->shouldSkipSubfloorReassignment($area)) {
                continue;
            }
            $page = (int) ($area['page'] ?? 0);
            if ($page <= 0) {
                continue;
            }
            $currentFloor = mb_strtolower(trim((string) ($area['floor'] ?? '')));
            foreach ($subfloorsByBase[$currentFloor] ?? [] as $target) {
                $match = $this->bestSubfloorMatch($area, $meetstaatByFloor[$target] ?? []);
                if ($match === null || ! $match['strong']) {
                    continue;
                }
                // Pagina alleen toewijzen op m²-bewijs, niet op nummer+naam alleen
                // (voorkomt dat een andere fase-tekening de hele pagina meeneemt).
                if (! ($match['meters_match'] ?? false) && ! ($match['exact_triple'] ?? false)) {
                    continue;
                }
                $votes[$page][$target] = ($votes[$page][$target] ?? 0) + (($match['exact_triple'] ?? false) ? 2 : 1);
            }
        }

        $out = [];
        foreach ($votes as $page => $counts) {
            arsort($counts);
            $top = array_key_first($counts);
            if ($top !== null && ($counts[$top] ?? 0) >= 2) {
                $out[(int) $page] = (string) $top;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $drawing
     * @param  list<array<string, mixed>>  $candidates
     * @return array{
     *     score: float,
     *     strong: bool,
     *     exact_triple: bool,
     *     number_match: bool,
     *     name_match: bool,
     *     meters_match: bool,
     *     name_conflict: bool,
     *     room_number: string
     * }|null
     */
    private function bestSubfloorMatch(array $drawing, array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $drawNumber = $this->normalizeNumber((string) ($drawing['room_number'] ?? ''));
        if ($drawNumber === '' && filled($drawing['implausible_room_number'] ?? null)) {
            // Gewist maatvoering-nummer telt niet als ruimtenummer-match.
            $drawNumber = '';
        }
        $drawName = trim((string) ($drawing['room_name'] ?? ''));
        $drawMeters = $drawing['square_meters'] ?? null;
        if ($drawMeters === null) {
            $taskMeters = $this->flooringTaskMeters($drawing);
            $drawMeters = $taskMeters > 0.0001 ? $taskMeters : null;
        }

        $best = null;
        foreach ($candidates as $candidate) {
            $candNumber = $this->normalizeNumber((string) ($candidate['room_number'] ?? ''));
            $candName = trim((string) ($candidate['room_name'] ?? ''));
            $numberMatch = $drawNumber !== '' && $candNumber !== '' && $drawNumber === $candNumber;
            $nameMatch = $drawName !== '' && $candName !== '' && $this->roomNamesCompatible($drawName, $candName);
            $nameConflict = $this->roomLabelsConflict($drawName, $candName);
            $metersMatch = $this->drawingMetersMatchMeetstaatRoom($drawMeters, $candidate);
            $materialOk = $this->drawingMaterialCompatibleWithMeetstaat($drawing, $candidate);
            $meterDelta = $this->meetstaatMeterDelta($drawMeters, $candidate);

            // Nooit puur op ruimtenummer koppelen.
            if ($numberMatch && ! $nameMatch && ! $metersMatch) {
                $score = 10.0;
                $strong = false;
            } elseif ($numberMatch && $nameMatch && $metersMatch) {
                $score = 100.0;
                $strong = true;
            } elseif ($numberMatch && $nameMatch) {
                $score = 85.0;
                $strong = true;
            } elseif ($nameMatch && $metersMatch && ($drawNumber !== '' || $materialOk)) {
                // Zonder ruimtenummer alleen sterk als materiaal ook past (voorkomt PU→Marmoleum).
                $score = 80.0;
                $strong = true;
            } elseif ($numberMatch && $metersMatch && ! $nameConflict) {
                $score = 70.0;
                $strong = true;
            } elseif ($numberMatch && $nameConflict) {
                $score = 15.0;
                $strong = false;
            } elseif ($nameMatch) {
                $score = 40.0;
                $strong = false;
            } elseif ($metersMatch) {
                $score = 25.0;
                $strong = false;
            } else {
                continue;
            }

            if ($meterDelta !== null) {
                $score += max(0.0, 5.0 - min(5.0, $meterDelta * 20.0));
            }

            $exactTriple = $numberMatch && $nameMatch && $metersMatch;
            $row = [
                'score' => $score,
                'strong' => $strong,
                'exact_triple' => $exactTriple,
                'number_match' => $numberMatch,
                'name_match' => $nameMatch,
                'meters_match' => $metersMatch,
                'name_conflict' => $nameConflict,
                'room_number' => $candNumber,
                'meter_delta' => $meterDelta,
            ];
            if ($best === null
                || $row['score'] > $best['score']
                || ($row['score'] === $best['score'] && ($row['meter_delta'] ?? 999) < ($best['meter_delta'] ?? 999))) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $drawing
     * @param  array<string, mixed>  $meetstaatRoom
     */
    private function drawingMaterialCompatibleWithMeetstaat(array $drawing, array $meetstaatRoom): bool
    {
        $drawMat = trim((string) ($drawing['legend_material'] ?? $drawing['tasks'][0]['work_name'] ?? ''));
        if ($drawMat === '') {
            return true;
        }
        foreach ($meetstaatRoom['tasks'] ?? [] as $task) {
            if (! $this->isFlooringArea($task)) {
                continue;
            }
            $meetMat = trim((string) ($task['work_name'] ?? ''));
            if ($meetMat !== '' && $this->isDuplicateMaterialLabel($drawMat, $meetMat)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meetstaatRoom
     */
    private function meetstaatMeterDelta(mixed $drawMeters, array $meetstaatRoom): ?float
    {
        if ($drawMeters === null) {
            return null;
        }
        $draw = (float) $drawMeters;
        $best = null;
        $physical = $this->physicalFromMeetstaat($meetstaatRoom)['square_meters'];
        if ($physical !== null) {
            $best = abs($draw - (float) $physical);
        }
        foreach ($meetstaatRoom['tasks'] ?? [] as $task) {
            if (! $this->isFlooringArea($task)) {
                continue;
            }
            $delta = abs($draw - (float) ($task['quantity'] ?? 0));
            $best = $best === null ? $delta : min($best, $delta);
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $meetstaatRoom
     */
    private function drawingMetersMatchMeetstaatRoom(mixed $drawMeters, array $meetstaatRoom): bool
    {
        if ($drawMeters === null) {
            return false;
        }
        $draw = (float) $drawMeters;
        $physical = $this->physicalFromMeetstaat($meetstaatRoom)['square_meters'];
        if ($physical !== null && $this->quantitiesNearlyEqual($draw, (float) $physical, 0.05)) {
            return true;
        }

        foreach ($meetstaatRoom['tasks'] ?? [] as $task) {
            if (! $this->isFlooringArea($task)) {
                continue;
            }
            $qty = (float) ($task['quantity'] ?? 0);
            if ($this->quantitiesNearlyEqual($draw, $qty, 0.05)) {
                return true;
            }
            $parts = max(1, (int) ($task['parts'] ?? 1));
            if ($parts >= 2 && $this->quantitiesNearlyEqual($draw, $qty / $parts, 1.0)) {
                return true;
            }
            // Deelvlak binnen het meetstaat-totaal (bijv. 33,03 van 64,74).
            if ($parts >= 2 && $draw < $qty - 0.05 && $draw > 1.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Onbekende bouwlaag herstellen uit ruimtenummerprefix, tekeningpagina of meetstaat-tegenhanger.
     *
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $meetstaatAreas
     * @param  list<array<string, mixed>>  $drawingAreas
     * @return list<array<string, mixed>>
     */
    private function recoverUnknownFloors(array $areas, array $meetstaatAreas, array $drawingAreas): array
    {
        $pool = array_merge($areas, $meetstaatAreas, $drawingAreas);
        foreach ($areas as $index => $area) {
            if (! $this->floorLabel()->isUnknown((string) ($area['floor'] ?? ''))) {
                continue;
            }
            $resolved = $this->inferFloorFromEvidence($area, $pool);
            if ($resolved === null) {
                continue;
            }
            $fromLabel = (string) ($area['floor'] ?? 'Onbekend');
            $area['floor_reassigned_from'] = $fromLabel;
            $area['floor'] = $resolved;
            $area['floor_reassignment_reason'] = 'bouwlaag hersteld uit ruimtenummer, meetstaat of tekeningpagina';
            $meters = $this->flooringTaskMeters($area);
            if ($meters <= 0.0001 && ($area['square_meters'] ?? null) !== null) {
                $meters = (float) $area['square_meters'];
            }
            $this->safeFixStats['floor_reassignments']++;
            $this->safeFixStats['floor_reassignment_meters'] = round(
                $this->safeFixStats['floor_reassignment_meters'] + $meters,
                2
            );
            $areas[$index] = $area;
        }

        return $areas;
    }

    /**
     * @param  array<string, mixed>  $area
     * @param  list<array<string, mixed>>  $pool
     */
    private function inferFloorFromEvidence(array $area, array $pool): ?string
    {
        $fromNumber = $this->floorLabel()->fromRoomNumber((string) ($area['room_number'] ?? ''));
        if ($fromNumber !== null) {
            return $this->existingFloorLabel($fromNumber, $pool);
        }

        $page = (int) ($area['page'] ?? 0);
        if ($page > 0) {
            $floorsOnPage = [];
            foreach ($pool as $other) {
                if ((int) ($other['page'] ?? 0) !== $page) {
                    continue;
                }
                $floor = trim((string) ($other['floor'] ?? ''));
                if ($this->floorLabel()->isUnknown($floor)) {
                    continue;
                }
                $floorsOnPage[mb_strtolower($floor)] = $floor;
            }
            if (count($floorsOnPage) === 1) {
                return array_values($floorsOnPage)[0];
            }
        }

        $number = mb_strtolower(trim((string) ($area['room_number'] ?? '')));
        $name = mb_strtolower(trim((string) ($area['room_name'] ?? '')));
        $meters = $this->areaMetersForFloorInference($area);
        $hits = [];
        foreach ($pool as $other) {
            $floor = trim((string) ($other['floor'] ?? ''));
            if ($this->floorLabel()->isUnknown($floor)) {
                continue;
            }
            $otherNumber = mb_strtolower(trim((string) ($other['room_number'] ?? '')));
            $otherName = mb_strtolower(trim((string) ($other['room_name'] ?? '')));
            $numberMatch = $number !== '' && $otherNumber !== '' && $number === $otherNumber;
            $nameMatch = $name !== '' && $name === $otherName;
            $otherMeters = $this->areaMetersForFloorInference($other);
            $meterMatch = $meters !== null && $otherMeters !== null && abs($meters - $otherMeters) <= 0.10;
            if ($numberMatch || ($nameMatch && $meterMatch)) {
                $hits[mb_strtolower($floor)] = $floor;
            }
        }
        if (count($hits) === 1) {
            return array_values($hits)[0];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function areaMetersForFloorInference(array $area): ?float
    {
        if (($area['square_meters'] ?? null) !== null) {
            return (float) $area['square_meters'];
        }
        $sum = $this->flooringTaskMeters($area);
        if ($sum > 0.0001) {
            return round($sum, 2);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function existingFloorLabel(string $inferred, array $areas): string
    {
        $want = mb_strtolower(trim($inferred));
        foreach ($areas as $area) {
            $floor = trim((string) ($area['floor'] ?? ''));
            if (mb_strtolower($floor) === $want) {
                return $floor;
            }
        }
        $wantCanonical = $this->floorLabel()->canonical($inferred) ?? $want;
        if (! preg_match('/^(verdieping \d+|kelder)$/u', $wantCanonical)) {
            return $wantCanonical;
        }
        foreach ($areas as $area) {
            $floor = trim((string) ($area['floor'] ?? ''));
            if ($this->floorLabel()->isUnknown($floor) || str_contains(mb_strtolower($floor), 'sporthal')) {
                continue;
            }
            $have = $this->floorLabel()->canonical($floor) ?? mb_strtolower($floor);
            if ($have === $wantCanonical) {
                return $floor;
            }
        }

        return $wantCanonical;
    }

    /**
     * @param  list<mixed>  $uncertain
     * @param  list<array<string, mixed>>  $areas
     * @return list<mixed>
     */
    private function dropResolvedUnknownFloorWarnings(array $uncertain, array $areas): array
    {
        foreach ($areas as $area) {
            if ($this->floorLabel()->isUnknown((string) ($area['floor'] ?? ''))) {
                return $uncertain;
            }
        }

        return array_values(array_filter(
            $uncertain,
            function ($row): bool {
                $reason = is_array($row) ? (string) ($row['reason'] ?? '') : '';

                return $reason !== 'Geen bouwlaag boven deze regel.';
            }
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $meetstaatAreas
     */
    private function canonicalFloorLabel(string $normalizedFloor, array $meetstaatAreas): string
    {
        foreach ($meetstaatAreas as $area) {
            $floor = (string) ($area['floor'] ?? '');
            if (mb_strtolower(trim($floor)) === $normalizedFloor) {
                return $floor;
            }
        }

        return $normalizedFloor;
    }

    private function roomNumberPlausibleForFloor(string $number, string $floor): bool
    {
        return $this->floorLabel()->numberPlausibleForFloor($number, $floor);
    }

    /**
     * Safety-net: ghost-ruimtes die na merge/enrichment alsnog dubbel zijn.
     *
     * @param  list<array<string, mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    private function collapseGhostDuplicateRooms(array $areas): array
    {
        $kept = [];
        foreach ($areas as $area) {
            $area = $this->splitGluedRoomIdentity($area);
            $source = (string) ($area['source'] ?? '');
            $isGhostCandidate = $source === 'plattegrond'
                || (($area['number_from_glued_name'] ?? false) === true);

            if ($isGhostCandidate && $this->isGhostDuplicateOfExisting($area, $kept)) {
                $this->recordDuplicateRemoval($area);

                continue;
            }

            // Plattegrond-only die een bestaande meetstaat/beide-ruimte herhaalt.
            if ($source === 'plattegrond' && $this->isGhostDuplicateOfExisting($area, array_values(array_filter(
                $kept,
                fn (array $row) => in_array((string) ($row['source'] ?? ''), ['meetstaat', 'beide'], true)
            )))) {
                $this->recordDuplicateRemoval($area);

                continue;
            }

            $kept[] = $area;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $area
     * @return array<string, mixed>
     */
    private function withRecognition(array $area): array
    {
        $via = $area['recognized_via'] ?? [];
        if ($via === []) {
            $via = match ($area['source'] ?? '') {
                'meetstaat' => ['meetstaat'],
                'plattegrond' => ['tekening'],
                'beide' => ['meetstaat', 'tekening'],
                'snijmaten' => ['snijmaten'],
                'materialenstaat' => ['materialenstaat'],
                'gecombineerd' => ['tekening'],
                default => ['handmatig'],
            };
        }
        if (($area['fill_color'] ?? null) && ! in_array('kleur', $via, true)) {
            $via[] = 'kleur';
        }
        if (($area['legend_material'] ?? null) && ! in_array('legenda', $via, true) && ! ($area['material_from_snijmaten'] ?? false)) {
            $via[] = 'legenda';
        }
        $confidence = (string) ($area['confidence'] ?? '');
        if ($confidence === '') {
            $confidence = count($via) >= 2 && ($area['square_meters'] ?? null) !== null ? 'hoog' : 'midden';
        }
        if (($area['needs_review'] ?? false) === true) {
            $confidence = 'controleren';
        }
        $area['recognized_via'] = array_values(array_unique($via));
        $area['recognized_via_label'] = $this->recognizedViaLabel($area['recognized_via'], (string) ($area['source'] ?? ''));
        $area['confidence'] = $confidence;
        $area['confidence_label'] = $this->confidenceLabel($confidence);
        $area['needs_review'] = $area['needs_review'] || $confidence === 'controleren';
        $area['material_source'] = $this->materialSourceKey($area);
        $area['material_source_label'] = $this->materialSourceLabel((string) $area['material_source']);

        return $area;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function materialSourceKey(array $area): string
    {
        $via = $area['recognized_via'] ?? [];
        $fromSnijmatenOnly = (bool) ($area['material_from_snijmaten'] ?? false);
        $confirmedBySnijmaten = (bool) ($area['material_confirmed_by_snijmaten'] ?? false);
        $hasColorLegend = in_array('kleur', $via, true)
            || (in_array('legenda', $via, true) && ! $fromSnijmatenOnly);
        $hasSnijmaten = $fromSnijmatenOnly || $confirmedBySnijmaten || in_array('snijmaten', $via, true);
        $hasMeetstaatMaterial = $this->areaHasFlooringTasks($area)
            && (in_array('meetstaat', $via, true)
                || in_array((string) ($area['source'] ?? ''), ['meetstaat', 'beide', 'gecombineerd'], true));

        if ($hasColorLegend && $hasSnijmaten) {
            return 'kleur_snijmaten';
        }
        if ($fromSnijmatenOnly || ($hasSnijmaten && ! $hasColorLegend)) {
            return 'snijmaten';
        }
        if ($hasColorLegend && $hasMeetstaatMaterial) {
            return 'meetstaat_kleur';
        }
        if ($hasMeetstaatMaterial) {
            return 'meetstaat';
        }
        if ($hasColorLegend) {
            return 'kleur_legenda';
        }

        return 'onbekend';
    }

    public function materialSourceLabel(string $key): string
    {
        return match ($key) {
            'kleur_legenda' => 'Kleur + legenda',
            'snijmaten' => 'Snijmaten bevestigd',
            'kleur_snijmaten' => 'Kleur + Snijmaten bevestigd',
            'meetstaat' => 'Meetstaat',
            'meetstaat_kleur' => 'Meetstaat + kleur/legenda',
            default => 'Onbekend',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $legend
     * @return list<array<string, mixed>>
     */
    private function annotateMaterialReview(array $areas, array $legend): array
    {
        foreach ($areas as &$area) {
            $area['review_reason'] = $this->reviewReason($area);
            $area['review_reason_label'] = $this->reviewReasonLabel((string) ($area['review_reason'] ?? ''));
            $area['material_source'] = $this->materialSourceKey($area);
            $area['material_source_label'] = $this->materialSourceLabel((string) $area['material_source']);
            $area = $this->withRecognition($area);
        }
        unset($area);

        return $areas;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function reviewReason(array $area): ?string
    {
        if (($area['confidence'] ?? '') !== 'controleren' && ! ($area['needs_review'] ?? false)) {
            return null;
        }

        $hasMeters = ($area['square_meters'] ?? null) !== null;
        $hasName = trim((string) ($area['room_name'] ?? '')) !== '';
        $hasColor = filled($area['fill_color'] ?? null);
        $hasMaterial = filled($area['legend_material'] ?? null) || filled($area['tasks'][0]['work_name'] ?? null);
        $legendStatus = (string) ($area['legend_total_status'] ?? '');
        $conflict = filled($area['material_conflict'] ?? null);

        if ($conflict) {
            return 'material_conflict';
        }
        if ($hasMeters && $hasName && $hasMaterial && in_array($legendStatus, ['controleren', 'waarschuwing'], true)) {
            // Legendatotaal-afwijking is geen reden voor ruimte-Controleren.
            return null;
        }
        if ($hasMeters && $hasName && $hasColor && ! $hasMaterial) {
            return 'color_no_legend';
        }
        if ($hasMeters && $hasName && ! $hasColor && ! $hasMaterial) {
            return 'no_color_no_material';
        }
        if ($hasMeters && $hasName && ! $hasColor && $hasMaterial) {
            return 'material_no_color';
        }
        if ($hasMeters && $hasName && ! $hasMaterial) {
            return 'material_missing';
        }

        return 'controleren';
    }

    public function reviewReasonLabel(string $reason): string
    {
        return match ($reason) {
            'material_missing' => 'Ruimte + m² goed, maar materiaal ontbreekt',
            'no_color_no_material' => 'Ruimte + m² goed, maar kleur niet herkend',
            'color_no_legend' => 'Kleur gevonden, maar legenda-materiaal niet gekoppeld',
            'total_mismatch' => 'Materiaal gevonden, maar verdiepingstotaal wijkt af',
            'material_conflict' => 'Mogelijke dubbele/verkeerde ruimte- of materiaalkoppeling',
            'material_no_color' => 'Materiaal gevonden zonder betrouwbare kleur',
            'controleren' => 'Controleren',
            default => '',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $materialWorks
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $legend
     * @return list<string>
     */
    private function englishOakWarnings(array $materialWorks, array $areas, array $legend): array
    {
        $oakWorks = array_values(array_filter(
            $materialWorks,
            fn (array $work) => (bool) preg_match('/english\s*oak|classics/i', (string) ($work['name'] ?? ''))
        ));
        if ($oakWorks === []) {
            return [];
        }

        $oakRooms = 0.0;
        foreach ($areas as $area) {
            $blob = mb_strtolower((string) ($area['legend_material'] ?? '').' '.($area['tasks'][0]['work_name'] ?? ''));
            if (preg_match('/english\s*oak|classics/i', $blob)) {
                $oakRooms += (float) ($area['square_meters'] ?? 0);
            }
        }
        $oakLegend = false;
        $oakPatternIds = [];
        $oakDeclared = 0.0;
        foreach ($legend as $entry) {
            if (! preg_match('/english\s*oak|classics/i', (string) ($entry['material'] ?? ''))) {
                continue;
            }
            $oakLegend = true;
            if (filled($entry['pattern_id'] ?? null)) {
                $oakPatternIds[] = (string) $entry['pattern_id'];
            }
            $oakDeclared += (float) ($entry['declared_total'] ?? 0);
        }
        if ($oakRooms > 0.1) {
            return [];
        }

        $patternList = $oakPatternIds === [] ? 'geen' : implode(', ', array_values(array_unique($oakPatternIds)));
        $legendFound = $oakLegend ? 'ja' : 'nee';

        return [
            'English Oak: PDF graphic type=tiling_pattern | pattern/resource-id='.$patternList
                .' | legendavak gevonden='.$legendFound
                .' | aantal ruimtevlakken met dezelfde resource=0'
                .' | legenda-som≈'.number_format($oakDeclared, 2, ',', '.')
                .' m². Kamers gebruiken geen Pattern-fill (alleen legenda-swatch + raster-onderlegger); zonder gokken niet te koppelen.',
        ];
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function mergeVia(array $left, array $right): array
    {
        return array_values(array_unique(array_merge($left, $right)));
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $legend
     * @return list<array<string, mixed>>
     */
    private function applyLegendTotals(array $areas, array $legend): array
    {
        $summary = $this->legendSummary($legend, $areas);

        foreach ($areas as &$area) {
            $matchedKnown = false;
            $worstKnown = 'ok';
            $hadUnknownLegend = false;

            foreach ($summary as $entry) {
                if (! $this->areaMatchesLegendEntry($area, $entry)) {
                    continue;
                }
                $status = (string) ($entry['status'] ?? 'ok');
                if ($status === 'onbekend' || $status === 'informatief') {
                    $hadUnknownLegend = true;

                    continue;
                }
                $matchedKnown = true;
                $worstKnown = $this->worseTotalStatus($worstKnown, $status);
            }

            if ($matchedKnown) {
                $area['legend_total_status'] = $worstKnown;
                // Alleen gewist/implausibel ruimtenummer mag niet door legendatotals terug naar Midden.
                $forceKeepReview = filled($area['implausible_room_number'] ?? null);
                if ($worstKnown === 'ok' && $this->hasReliableMaterialLink($area) && blank($area['material_conflict'] ?? null) && ! $forceKeepReview) {
                    if (($area['confidence'] ?? '') === 'controleren' || ($area['needs_review'] ?? false)) {
                        $area['needs_review'] = false;
                        $viaCount = count($area['recognized_via'] ?? []);
                        $area['confidence'] = $viaCount >= 2 && ($area['square_meters'] ?? null) !== null
                            ? 'hoog'
                            : 'midden';
                    }
                } elseif ($worstKnown === 'controleren') {
                    // Verdiepingsgat blijft zichtbaar op de legendatabel; nooit de ruimte zelf naar Controleren trekken.
                    $area['needs_review'] = false;
                    if (($area['confidence'] ?? '') === 'controleren' || ($area['confidence'] ?? '') === '') {
                        $area['confidence'] = 'midden';
                    }
                } elseif ($worstKnown === 'waarschuwing' && ($area['confidence'] ?? '') === 'hoog') {
                    $area['confidence'] = 'midden';
                    $area['needs_review'] = false;
                }
                // ok → bestaande kamerconfidence behouden (hoog als herkenning goed was)
            } elseif ($hadUnknownLegend && ($area['confidence'] ?? '') === 'hoog') {
                $area['legend_total_status'] = 'onbekend';
                // Kamer/materiaal ok, maar legendatotaal niet gelezen → Midden, geen Controleren.
                $area['confidence'] = 'midden';
                $area['needs_review'] = false;
            } else {
                $area['legend_total_status'] = $area['legend_total_status'] ?? null;
            }

            $area = $this->withRecognition($area);
        }
        unset($area);

        return $areas;
    }

    /**
     * @param  list<array<string, mixed>>  $legend
     * @param  list<array<string, mixed>>  $areas
     * @return list<array<string, mixed>>
     */
    private function legendSummary(array $legend, array $areas): array
    {
        $colorToMaterial = $this->learnLegendColorMaterialMap($areas);
        $canonicalCandidates = $this->canonicalMaterialCandidatesFromAreas($areas);
        $resolved = [];
        $claimedByFloor = [];

        foreach ($legend as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $unit = (string) ($entry['unit'] ?? WorkUnit::SquareMeter->value);
            $floor = $this->resolveLegendFloor($entry, $areas);
            $canonical = $this->canonicalLegendMaterial($entry, $floor, $colorToMaterial, $canonicalCandidates);
            $resolved[$index] = [
                'entry' => $entry,
                'unit' => $unit,
                'floor' => $floor,
                'canonical' => $canonical,
                'linked' => $canonical !== null && ! $this->isGenericMultiVariantFamilyLabel($canonical, $canonicalCandidates),
            ];
            if ($resolved[$index]['linked']) {
                $floorKey = mb_strtolower(trim($floor));
                $claimedByFloor[$floorKey][] = $this->normalizeMaterialKey($canonical);
            }
        }

        foreach ($resolved as $index => &$meta) {
            if ($meta['linked']) {
                continue;
            }
            $entry = $meta['entry'];
            $floor = $meta['floor'];
            $unit = $meta['unit'];
            $floorKey = mb_strtolower(trim($floor));
            $claimed = $claimedByFloor[$floorKey] ?? [];
            $byQuantity = $this->resolveCanonicalByFloorQuantity(
                (string) ($entry['material'] ?? ''),
                $floor,
                $unit,
                array_key_exists('declared_total', $entry) && $entry['declared_total'] !== null
                    ? (float) $entry['declared_total']
                    : null,
                $areas,
                $entry,
                $claimed,
            );
            if ($byQuantity !== null) {
                $meta['canonical'] = $byQuantity;
                $meta['linked'] = true;
                $claimedByFloor[$floorKey][] = $this->normalizeMaterialKey($byQuantity);
            }
        }
        unset($meta);

        $rows = [];
        foreach ($resolved as $meta) {
            $entry = $meta['entry'];
            $unit = $meta['unit'];
            $floor = $meta['floor'];
            $canonical = (string) ($meta['canonical'] ?? $entry['material'] ?? '');
            $linked = (bool) $meta['linked'];
            $needsVariantLink = $this->isGenericMultiVariantFamilyLabel((string) ($entry['material'] ?? ''), $canonicalCandidates);

            $calculated = 0.0;
            $matchedTasks = [];
            if ($linked || ! $needsVariantLink) {
                foreach ($areas as $area) {
                    if (! $this->areaFloorMatchesLegend($area, $floor, $entry, $areas)) {
                        continue;
                    }
                    foreach ($this->tasksMatchingLegend($area, $entry, $canonical, $unit) as $task) {
                        $qty = (float) ($task['quantity'] ?? 0);
                        if ($qty <= 0) {
                            continue;
                        }
                        $calculated += $qty;
                        $matchedTasks[] = [
                            'room_name' => $area['room_name'] ?? null,
                            'room_number' => $area['room_number'] ?? null,
                            'floor' => $area['floor'] ?? null,
                            'work_name' => $task['work_name'] ?? null,
                            'quantity' => $qty,
                        ];
                    }
                }
            }

            $declaredKnown = array_key_exists('declared_total', $entry) && $entry['declared_total'] !== null;
            $declared = $declaredKnown ? (float) $entry['declared_total'] : null;
            $isPlintLegend = str_contains(mb_strtolower((string) ($entry['material'] ?? '')), 'plint')
                || str_contains(mb_strtolower($canonical), 'plint')
                || in_array($unit, [WorkUnit::LinearMeter->value, 'm1', 'm¹'], true);

            if ($isPlintLegend) {
                // Plinten zijn m¹ (meetstaat-omtrek). Legendakleur mag bevestigen, maar
                // nooit als vloer-m²-totaal CONTROLEREN afdwingen.
                $status = 'informatief';
                $difference = null;
                $calculatedDisplay = round($calculated, 2);
            } elseif ($needsVariantLink && ! $linked) {
                $status = 'informatief';
                $difference = null;
                $calculatedDisplay = round($calculated, 2);
            } else {
                $difference = $declaredKnown ? round($calculated - (float) $declared, 2) : null;
                $status = $this->totalStatus($declared, $calculated);
                $calculatedDisplay = round($calculated, 2);
            }

            $rows[] = [
                'material' => $entry['material'],
                'canonical_material' => $canonical,
                'color' => $entry['color'] ?? null,
                'unit' => $unit,
                'unit_label' => WorkUnit::tryFrom($unit)?->label() ?? $unit,
                'floor' => $floor,
                'page' => $entry['page'] ?? null,
                'declared_total' => $declared,
                'declared_known' => $declaredKnown,
                'calculated_total' => $calculatedDisplay,
                'difference' => $difference,
                'status' => $status,
                'status_label' => match ($status) {
                    'ok' => 'OK',
                    'waarschuwing' => 'Waarschuwing',
                    'onbekend' => 'Onbekend',
                    'informatief' => $isPlintLegend
                        ? 'Plintlegenda (m¹) – informatief'
                        : 'Legenda-variant niet eenduidig gekoppeld – informatief',
                    default => 'Controleren',
                },
                'needs_review' => $status === 'controleren',
                'fill_type' => $entry['fill_type'] ?? (($entry['color'] ?? null) ? 'solid' : 'pattern'),
                'pattern_id' => $entry['pattern_id'] ?? null,
                'graphic_type' => $entry['graphic_type'] ?? null,
                'comparison' => 'area_tasks',
                'hint' => $status === 'informatief'
                    ? [
                        'message' => $isPlintLegend
                            ? 'Plintlegenda bevestigt kleur; plinthoeveelheden blijven m¹ uit de meetstaat.'
                            : 'Legendakleur is niet eenduidig naar één productvariant gekoppeld; geen foutieve family-vergelijking.',
                        'gap' => null,
                        'matched_room_count' => 0,
                        'candidates' => [],
                    ]
                    : $this->legendGapHint($entry, $floor, $unit, $declared, $calculatedDisplay, $difference, $matchedTasks, $areas),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, string> key: normalized fill color → specific canonical material name
     */
    private function learnLegendColorMaterialMap(array $areas): array
    {
        $votes = [];
        foreach ($areas as $area) {
            $color = $this->normalizeColorKey((string) ($area['fill_color'] ?? ''));
            if ($color === '') {
                continue;
            }
            $flooring = [];
            foreach ($area['tasks'] ?? [] as $task) {
                if (! is_array($task) || ! $this->isFlooringArea($task)) {
                    continue;
                }
                $name = trim((string) ($task['work_name'] ?? ''));
                if ($name === '' || $this->isGenericMultiVariantFamilyLabel($name)) {
                    continue;
                }
                $flooring[$name] = true;
            }
            // Alleen eenduidige één-taak-ruimtes: kleur → exacte productvariant.
            if (count($flooring) === 1) {
                $name = (string) array_key_first($flooring);
                $votes[$color][$name] = ($votes[$color][$name] ?? 0) + 1;
            }
        }

        $map = [];
        foreach ($votes as $color => $names) {
            if (count($names) !== 1) {
                continue;
            }
            $map[$color] = (string) array_key_first($names);
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, string>  $colorToMaterial
     * @param  list<string>  $canonicalCandidates
     */
    private function canonicalLegendMaterial(array $entry, string $floor, array $colorToMaterial, array $canonicalCandidates = []): ?string
    {
        $material = trim((string) ($entry['material'] ?? ''));
        if ($material === '') {
            return null;
        }
        if (! $this->isGenericMultiVariantFamilyLabel($material, $canonicalCandidates)) {
            $resolved = $this->materialIdentity()->resolveUniqueCanonical($material, $canonicalCandidates);
            if ($resolved !== null) {
                return $resolved;
            }

            return $material;
        }

        $color = $this->normalizeColorKey((string) ($entry['color'] ?? ''));
        if ($color !== '' && isset($colorToMaterial[$color])) {
            $mapped = $colorToMaterial[$color];
            if ($this->materialFamilyKey($mapped) === $this->materialFamilyKey($material)) {
                return $mapped;
            }
        }

        // Generieke multi-variant label zonder betrouwbare kleurmap: later quantity-resolve of informatief.
        return $material;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     * @param  list<string>  $claimedCanonicalKeys
     * @param  array<string, mixed>  $entry
     */
    private function resolveCanonicalByFloorQuantity(
        string $familyMaterial,
        string $floor,
        string $unit,
        ?float $declared,
        array $areas,
        array $entry,
        array $claimedCanonicalKeys,
    ): ?string {
        if ($declared === null || ! $this->isGenericMultiVariantFamilyLabel(
            $familyMaterial,
            $this->canonicalMaterialCandidatesFromAreas($areas),
        )) {
            return null;
        }

        $family = $this->materialFamilyKey($familyMaterial);
        $sums = [];
        foreach ($areas as $area) {
            if (! $this->areaFloorMatchesLegend($area, $floor, $entry, $areas)) {
                continue;
            }
            foreach ($area['tasks'] ?? [] as $task) {
                if (! is_array($task) || ! $this->isFlooringArea($task)) {
                    continue;
                }
                if ((string) ($task['unit'] ?? WorkUnit::SquareMeter->value) !== $unit) {
                    continue;
                }
                $workName = trim((string) ($task['work_name'] ?? ''));
                if ($workName === '' || $this->isGenericMultiVariantFamilyLabel($workName)) {
                    continue;
                }
                if ($this->materialFamilyKey($workName) !== $family) {
                    continue;
                }
                $key = $this->normalizeMaterialKey($workName);
                if (in_array($key, $claimedCanonicalKeys, true)) {
                    continue;
                }
                $sums[$workName] = ($sums[$workName] ?? 0.0) + (float) ($task['quantity'] ?? 0);
            }
        }

        $matches = [];
        foreach ($sums as $name => $qty) {
            if (abs($qty - $declared) <= 0.10) {
                $matches[] = $name;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function normalizeColorKey(string $color): string
    {
        $color = mb_strtolower(trim($color));
        if ($color === '') {
            return '';
        }
        if (! str_starts_with($color, '#')) {
            $color = '#'.$color;
        }

        return $color;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array<string, mixed>>  $areas
     */
    private function resolveLegendFloor(array $entry, array $areas): string
    {
        $page = (int) ($entry['page'] ?? 0);
        if ($page > 0) {
            $counts = [];
            foreach ($areas as $area) {
                if ((int) ($area['page'] ?? 0) !== $page) {
                    continue;
                }
                $floor = trim((string) ($area['floor'] ?? ''));
                if ($floor === '' || mb_strtolower($floor) === 'onbekend') {
                    continue;
                }
                $counts[$floor] = ($counts[$floor] ?? 0) + 1;
            }
            if ($counts !== []) {
                arsort($counts);

                return (string) array_key_first($counts);
            }
        }

        if (filled($entry['floor'] ?? null)) {
            return (string) $entry['floor'];
        }

        return $this->floorForPage($areas, $page);
    }

    /**
     * @param  array<string, mixed>  $area
     * @param  array<string, mixed>  $entry
     */
    /**
     * @param  array<string, mixed>  $area
     * @param  array<string, mixed>  $entry
     * @param  list<array<string, mixed>>  $areas
     */
    private function areaFloorMatchesLegend(array $area, string $legendFloor, array $entry, array $areas = []): bool
    {
        $areaFloor = mb_strtolower(trim((string) ($area['floor'] ?? '')));
        $legendFloor = mb_strtolower(trim($legendFloor));
        if ($legendFloor === '' || $legendFloor === 'onbekend') {
            return true;
        }
        // Strikt: begane grond ≠ begane grond sporthal.
        if ($areaFloor !== $legendFloor) {
            return false;
        }

        $entryPage = (int) ($entry['page'] ?? 0);
        if ($entryPage <= 0) {
            return true;
        }
        $areaPage = (int) ($area['page'] ?? 0);
        if ($areaPage > 0) {
            return $areaPage === $entryPage;
        }

        // Meetstaat-only: alleen meetellen op de primaire (laagste) pagina van deze bouwlaag.
        $primary = $this->primaryPageForFloor($areas, $legendFloor);
        if ($primary <= 0) {
            return true;
        }

        return $primary === $entryPage;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function primaryPageForFloor(array $areas, string $floor): int
    {
        $floor = mb_strtolower(trim($floor));
        $pages = [];
        foreach ($areas as $area) {
            if (mb_strtolower(trim((string) ($area['floor'] ?? ''))) !== $floor) {
                continue;
            }
            $page = (int) ($area['page'] ?? 0);
            if ($page > 0) {
                $pages[] = $page;
            }
        }
        if ($pages === []) {
            return 0;
        }

        return min($pages);
    }

    /**
     * @param  array<string, mixed>  $area
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function tasksMatchingLegend(array $area, array $entry, string $canonical, string $unit): array
    {
        $matched = [];
        if ($canonical === '' || $this->isGenericMultiVariantFamilyLabel($canonical)) {
            // Generieke family-labels (bv. "Marmoleum Walton") mogen nooit alle variant-taken opslokken.
            return [];
        }

        foreach ($area['tasks'] ?? [] as $task) {
            if (! is_array($task)) {
                continue;
            }
            $taskUnit = (string) ($task['unit'] ?? WorkUnit::SquareMeter->value);
            if ($taskUnit !== $unit) {
                continue;
            }
            $workName = trim((string) ($task['work_name'] ?? ''));
            if ($workName === '') {
                continue;
            }
            if ($unit === WorkUnit::SquareMeter->value && ! $this->isFlooringArea($task)) {
                continue;
            }
            if (! $this->taskMatchesLegendCanonical($canonical, $workName)) {
                continue;
            }
            $matched[] = $task;
        }

        return $matched;
    }

    /**
     * Exacte canonical productvariant, geen family-substring zoals "Marmoleum Walton" → alle Walton-kleuren.
     */
    private function taskMatchesLegendCanonical(string $canonical, string $workName): bool
    {
        if ($this->isGenericMultiVariantFamilyLabel($canonical)) {
            return false;
        }

        $a = $this->normalizeMaterialKey($canonical);
        $b = $this->normalizeMaterialKey($workName);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        // Specifieke canonieke naam mag een langere taaknaam matchen, niet andersom via family-prefix.
        if ($this->belongsToMultiVariantFamily($canonical) || $this->belongsToMultiVariantFamily($workName)) {
            return str_starts_with($b, $a) || str_starts_with($a, $b);
        }

        return $this->taskBelongsToMaterial($canonical, $workName);
    }

    /**
     * @param  list<string>  $canonicalCandidates
     */
    private function isGenericMultiVariantFamilyLabel(string $name, array $canonicalCandidates = []): bool
    {
        $key = $this->normalizeMaterialKey($name);
        if (in_array($key, $this->multiVariantFamilyKeys(), true)) {
            return true;
        }
        if ($canonicalCandidates === [] || mb_strlen($key) < 8) {
            return false;
        }
        $hits = 0;
        foreach ($canonicalCandidates as $candidate) {
            $candidateKey = $this->normalizeMaterialKey((string) $candidate);
            if ($candidateKey === $key || str_starts_with($candidateKey, $key.' ')) {
                $hits++;
                if ($hits > 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function belongsToMultiVariantFamily(string $name): bool
    {
        $key = $this->normalizeMaterialKey($name);
        foreach ($this->multiVariantFamilyKeys() as $family) {
            if ($key === $family || str_starts_with($key, $family.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function multiVariantFamilyKeys(): array
    {
        return [
            'marmoleum walton',
        ];
    }

    private function materialFamilyKey(string $name): string
    {
        $key = $this->normalizeMaterialKey($name);
        foreach (['marmoleum walton', 'marmoleum real', 'marmoleum sport', 'coral welcome', 'pu gietvloer'] as $family) {
            if (str_starts_with($key, $family) || str_contains($key, $family)) {
                return $family;
            }
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $area
     * @param  array<string, mixed>  $entry
     */
    private function areaMatchesLegendEntry(array $area, array $entry): bool
    {
        $floor = (string) ($entry['floor'] ?? '');
        if ($floor !== '' && ! $this->areaFloorMatchesLegend($area, $floor, $entry)) {
            return false;
        }
        $canonical = (string) ($entry['canonical_material'] ?? $entry['material'] ?? '');
        if ($canonical === '') {
            return false;
        }

        return $this->tasksMatchingLegend($area, $entry, $canonical, (string) ($entry['unit'] ?? WorkUnit::SquareMeter->value)) !== [];
    }

    /**
     * @deprecated Use tasksMatchingLegend; kept for callers that still pass unit alone.
     *
     * @param  array<string, mixed>  $area
     */
    private function quantityForUnit(array $area, string $unit): float
    {
        $sum = 0.0;
        foreach ($area['tasks'] ?? [] as $task) {
            if (! is_array($task)) {
                continue;
            }
            if ((string) ($task['unit'] ?? WorkUnit::SquareMeter->value) !== $unit) {
                continue;
            }
            if ($unit === WorkUnit::SquareMeter->value && ! $this->isFlooringArea($task)) {
                continue;
            }
            $sum += (float) ($task['quantity'] ?? 0);
        }

        return $sum;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<array<string, mixed>>  $matchedRooms
     * @param  list<array<string, mixed>>  $areas
     * @return array<string, mixed>|null
     */
    private function legendGapHint(
        array $entry,
        string $floor,
        string $unit,
        ?float $declared,
        float $calculated,
        ?float $difference,
        array $matchedRooms,
        array $areas,
    ): ?array {
        if ($declared === null || $difference === null || abs($difference) <= 2.0) {
            return null;
        }
        if ($unit !== WorkUnit::SquareMeter->value) {
            return null;
        }

        $gap = round(abs($difference), 2);
        $direction = $difference < 0
            ? 'Legenda heeft '.$this->formatQty($gap).' m² meer dan de som van materiaaltaken.'
            : 'Som materiaaltaken heeft '.$this->formatQty($gap).' m² meer dan de legenda.';

        $candidates = [];
        $needle = mb_strtolower((string) ($entry['material'] ?? ''));
        foreach ($areas as $area) {
            if (mb_strtolower((string) ($area['floor'] ?? '')) !== mb_strtolower($floor)) {
                continue;
            }
            $areaMaterial = mb_strtolower((string) ($area['legend_material'] ?? ''));
            $alreadyMatched = $areaMaterial !== '' && $areaMaterial === $needle;
            if ($alreadyMatched && $difference < 0) {
                continue;
            }
            $meters = (float) ($area['square_meters'] ?? 0);
            if ($meters <= 0) {
                continue;
            }
            // Alleen suggesties dicht bij het verschil; niet automatisch toewijzen.
            if (abs($meters - $gap) > max(2.0, $gap * 0.35)) {
                continue;
            }
            if ($difference < 0 && $areaMaterial === $needle) {
                continue;
            }
            if ($difference > 0 && $areaMaterial !== $needle) {
                continue;
            }
            $candidates[] = [
                'room_name' => $area['room_name'] ?? null,
                'square_meters' => $meters,
                'legend_material' => $area['legend_material'] ?? null,
                'reason' => $difference < 0
                    ? 'm² ligt dicht bij het tekort; mogelijk ontbreekt of verkeerde materiaalkoppeling'
                    : 'm² ligt dicht bij het overschot; mogelijk verkeerd gekoppeld aan dit materiaal',
            ];
        }

        usort($candidates, fn (array $left, array $right) => abs($left['square_meters'] - $gap) <=> abs($right['square_meters'] - $gap));

        return [
            'message' => $direction,
            'gap' => $difference < 0 ? -$gap : $gap,
            'matched_room_count' => count($matchedRooms),
            'candidates' => array_slice($candidates, 0, 6),
        ];
    }

    private function formatQty(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function floorForPage(array $areas, int $page): string
    {
        foreach ($areas as $area) {
            if ((int) ($area['page'] ?? 0) === $page && ($area['floor'] ?? '') !== '') {
                return (string) $area['floor'];
            }
        }

        return 'Onbekend';
    }

    private function totalStatus(?float $declared, float $calculated): string
    {
        if ($declared === null) {
            return 'onbekend';
        }
        $diff = abs($calculated - $declared);
        if ($declared <= 0 && $calculated <= 0) {
            return 'ok';
        }
        if ($diff <= 0.10) {
            return 'ok';
        }
        if ($diff <= 2.0) {
            return 'waarschuwing';
        }

        return 'controleren';
    }

    private function worseTotalStatus(string $current, string $incoming): string
    {
        $rank = ['ok' => 0, 'onbekend' => 0, 'informatief' => 0, 'waarschuwing' => 1, 'controleren' => 2];

        return ($rank[$incoming] ?? 0) > ($rank[$current] ?? 0) ? $incoming : $current;
    }

    /**
     * @param  list<string>  $via
     */
    public function recognizedViaLabel(array $via, string $source): string
    {
        if ($via === []) {
            return $this->sourceLabel($source);
        }
        $labels = [
            'tekening' => 'Tekening',
            'kleur' => 'kleur',
            'legenda' => 'legenda',
            'snijmaten' => 'snijmaten',
            'materialenstaat' => 'materialenstaat',
            'meetstaat' => 'meetstaat',
            'plattegrond' => 'Tekening',
            'handmatig' => 'Handmatig',
        ];
        $parts = [];
        foreach ($via as $item) {
            $parts[] = $labels[$item] ?? $item;
        }

        return implode(' + ', $parts);
    }

    public function confidenceLabel(string $confidence): string
    {
        return match ($confidence) {
            'hoog' => 'Hoog',
            'midden' => 'Midden',
            default => 'Controleren',
        };
    }
}
