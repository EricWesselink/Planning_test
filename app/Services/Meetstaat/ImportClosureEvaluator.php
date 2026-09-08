<?php

namespace App\Services\Meetstaat;

use App\Enums\ImportDecision;
use App\Support\Format;

/**
 * Harde eindcontrole vóór definitief importeren.
 * Geen project-/bestandsnaam-regels: alleen preview-inhoud.
 * Centrale beslissing: READY_AUTOMATIC of BLOCKED_CONFLICT.
 */
class ImportClosureEvaluator
{
    private const TOLERANCE = 0.005;

    /** Max. verklaard afrondingsverschil per materiaal / projecttotaal (som afgeronde detailregels vs declared). */
    private const ROUNDING_TOLERANCE = 0.05;

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    public function evaluate(array $preview): array
    {
        $areas = array_values(array_filter(
            $preview['areas'] ?? [],
            fn ($area) => is_array($area)
        ));
        $report = is_array($preview['import_report'] ?? null) ? $preview['import_report'] : [];
        $materials = array_values(array_filter(
            $report['materials'] ?? $preview['material_check'] ?? [],
            fn ($row) => is_array($row)
        ));
        $floors = array_values(array_filter(
            $report['floors'] ?? [],
            fn ($row) => is_array($row)
        ));
        // Parser-duplicaten zijn informatief tenzij expliciet onopgelost gemarkeerd.
        $duplicates = array_values(array_filter(
            $preview['duplicates'] ?? [],
            function ($row) {
                if (! is_array($row)) {
                    return filled($row);
                }
                if (array_key_exists('resolved', $row)) {
                    return ! $row['resolved'];
                }
                if (array_key_exists('unresolved', $row)) {
                    return (bool) $row['unresolved'];
                }

                // Standaard meetstaat-fold-duplicaten zijn al samengevoegd tot areas; niet hard blokkeren.
                return false;
            }
        ));
        $uncertain = array_values(array_filter(
            $preview['uncertain'] ?? [],
            function ($row) {
                if (! is_array($row)) {
                    return filled($row);
                }
                if (array_key_exists('resolved', $row)) {
                    return ! $row['resolved'];
                }

                return filled($row['message'] ?? $row['text'] ?? null);
            }
        ));
        $sources = is_array($preview['sources'] ?? null) ? $preview['sources'] : [];
        $hasDrawing = (bool) ($sources['plattegrond'] ?? false);
        $headerMismatches = array_values(array_filter(
            $preview['project_header_mismatches'] ?? [],
            fn ($row) => is_array($row)
        ));

        $issues = [];
        $checks = [];

        foreach ($headerMismatches as $mismatch) {
            $label = (string) ($mismatch['label'] ?? 'Projectgegeven');
            $otherSource = (string) ($mismatch['source'] ?? 'ander bestand');
            $issues[] = $this->issue(
                found: $label.': '.(string) ($mismatch['other'] ?? ''),
                expected: 'Materialenstaat: '.(string) ($mismatch['primary'] ?? ''),
                source: $otherSource,
                problem: (string) ($mismatch['message'] ?? 'Mogelijk bestand van ander project'),
                suggested: 'Controleer of alle bestanden bij hetzelfde werknummer/referentie horen, of verwijder het afwijkende bestand',
                anchor: '#projectgegevens',
                category: 'project_header',
            );
        }

        $openRooms = [];
        foreach ($areas as $index => $area) {
            $label = $this->areaLabel($area);
            $confidence = (string) ($area['confidence'] ?? 'controleren');
            // Alleen open Controleren blokkeert; Midden/Hoog (incl. bevestigde ruimtes zonder fysieke m²) mogen door.
            if ($confidence === 'controleren') {
                $openRooms[] = $index;
                $issues[] = $this->issue(
                    found: $label,
                    expected: 'goedgekeurde ruimte',
                    source: (string) ($area['source_label'] ?? $area['source'] ?? '—'),
                    problem: (string) ($area['review_reason_label'] ?? 'Ruimte staat op Controleren'),
                    suggested: 'Corrigeer of bevestig de ruimte op de controlepagina',
                    anchor: '#area-'.$index,
                    category: 'rooms',
                );
            }
        }

        $unknownFloors = [];
        foreach ($areas as $index => $area) {
            $floor = trim((string) ($area['floor'] ?? ''));
            if ($floor === '' || mb_strtolower($floor) === 'onbekend') {
                $unknownFloors[] = $index;
                $issues[] = $this->issue(
                    found: $this->areaLabel($area),
                    expected: 'bekende bouwlaag',
                    source: (string) ($area['source_label'] ?? $area['source'] ?? '—'),
                    problem: 'Onbekende bouwlaag',
                    suggested: 'Kies of typ de juiste bouwlaag',
                    anchor: '#area-'.$index,
                    category: 'floors',
                );
            }
        }

        $unknownMaterials = [];
        foreach ($areas as $index => $area) {
            $flooringTasks = $this->flooringTasks($area);
            $missingNamedTask = false;
            foreach ($area['tasks'] ?? [] as $task) {
                if (! is_array($task)) {
                    continue;
                }
                $unit = (string) ($task['unit'] ?? 'm2');
                if (! in_array($unit, ['m2', 'm²'], true)) {
                    continue;
                }
                if ((float) ($task['quantity'] ?? 0) > 0 && trim((string) ($task['work_name'] ?? '')) === '') {
                    $missingNamedTask = true;
                    break;
                }
            }

            // Ontbrekende materiaaltaken: hard bij tekeningkleur/legenda of MaterialList-zonder-meetstaat.
            // Met meetstaat als TASK_SOURCE komen vloertaken uit de meetstaat — kleur/legenda alleen bevestigt.
            $hasMeetstaat = (bool) ($sources['meetstaat'] ?? false);
            $expectsRoomMaterial = false;
            if (! $hasMeetstaat) {
                $expectsRoomMaterial = filled($area['legend_material'] ?? null)
                    || filled($area['fill_color'] ?? null)
                    || (bool) ($sources['materialenstaat'] ?? false);
            }
            $missingRequiredTasks = $flooringTasks === [] && $expectsRoomMaterial;
            if (! $missingNamedTask && ! $missingRequiredTasks) {
                continue;
            }

            $unknownMaterials[] = $index;
            $issues[] = $this->issue(
                found: $this->areaLabel($area).' · '.($area['material_source_label'] ?? 'geen taak'),
                expected: 'gekoppeld materiaal/werksoort',
                source: (string) ($area['material_source_label'] ?? $area['source_label'] ?? '—'),
                problem: $missingNamedTask ? 'Taak zonder materiaalnaam' : 'Geen materiaaltaak terwijl bronnen materiaal verwachten',
                suggested: 'Koppel materiaal via taakregel, legenda of Snijmaten',
                anchor: '#area-'.$index,
                category: 'materials',
            );
        }

        $openTasks = [];
        foreach ($areas as $index => $area) {
            foreach ($area['tasks'] ?? [] as $taskIndex => $task) {
                if (! is_array($task)) {
                    continue;
                }
                $workName = trim((string) ($task['work_name'] ?? ''));
                $qty = (float) ($task['quantity'] ?? 0);
                if ($workName === '' && $qty <= 0) {
                    continue;
                }
                if ($workName === '' || $qty < 0) {
                    $openTasks[] = [$index, $taskIndex];
                    $issues[] = $this->issue(
                        found: $workName !== '' ? $workName.' · '.$qty : '(lege taak)',
                        expected: 'materiaal + hoeveelheid ≥ 0',
                        source: $this->areaLabel($area),
                        problem: 'Incomplete area_task',
                        suggested: 'Vul materiaal en hoeveelheid in of verwijder de regel',
                        anchor: '#area-'.$index,
                        category: 'tasks',
                    );
                }
            }
        }

        $materialMismatches = [];
        foreach ($materials as $materialIndex => $row) {
            $status = (string) ($row['status'] ?? '');
            $accepted = ! empty($row['accepted_exception']);
            if ($accepted) {
                continue;
            }
            $diff = isset($row['difference']) ? abs((float) $row['difference']) : null;
            if ($diff !== null && $diff <= self::ROUNDING_TOLERANCE) {
                // Som afgeronde meetstaatregels vs declared eindtotaal — geen harde blokkade.
                continue;
            }
            // Alleen status controleren is hard; waarschuwing/ok met afrondingsverschil blijft zichtbaar in het rapport.
            if ($status === 'controleren') {
                $materialMismatches[] = $materialIndex;
                $issues[] = $this->issue(
                    found: (string) ($row['found_task_meters'] ?? $row['calculated_total'] ?? '—'),
                    expected: (string) ($row['expected_task_meters'] ?? $row['declared_total'] ?? '—'),
                    source: (string) ($row['expected_source'] ?? $row['source'] ?? 'materiaaltotaal'),
                    problem: 'Materiaaltotaal sluit niet: '.((string) ($row['material'] ?? '')),
                    suggested: 'Koppel ontbrekende taken of corrigeer hoeveelheden tot verschil 0,00',
                    anchor: '#material-'.$materialIndex,
                    category: 'quantities',
                );
            }
        }

        $legend = array_values(array_filter(
            $preview['legend'] ?? [],
            fn ($row) => is_array($row)
        ));
        $legendMismatches = [];
        foreach ($legend as $legendIndex => $row) {
            if ((string) ($row['status'] ?? '') !== 'controleren') {
                continue;
            }
            $diff = isset($row['difference']) ? abs((float) $row['difference']) : null;
            if ($diff !== null && $diff <= self::ROUNDING_TOLERANCE) {
                continue;
            }
            $legendMismatches[] = $legendIndex;
            $label = trim((string) (($row['canonical_material'] ?? null) ?: ($row['material'] ?? '')));
            $floor = trim((string) ($row['floor'] ?? ''));
            $issues[] = $this->issue(
                found: (string) ($row['calculated_total'] ?? '—'),
                expected: (string) ($row['declared_total'] ?? '—'),
                source: 'legenda'.($floor !== '' ? ' · '.$floor : ''),
                problem: 'Legenda versus materiaaltaken sluit niet: '.$label,
                suggested: 'Controleer canonieke materiaalkoppeling of taakhoeveelheden op deze bouwlaag',
                anchor: '#legend-'.$legendIndex,
                category: 'legend',
            );
        }

        $taskSourceLost = null;
        if (array_key_exists('task_source_meters_lost', $report) && $report['task_source_meters_lost'] !== null) {
            $taskSourceLost = round((float) $report['task_source_meters_lost'], 2);
        } else {
            $baselines = is_array($preview['closure_baselines']['meetstaat_areas'] ?? null)
                ? $preview['closure_baselines']['meetstaat_areas']
                : [];
            if ($baselines !== []) {
                $parsed = $this->sumTaskMeters($baselines);
                $kept = $this->sumTaskMeters($areas);
                $taskSourceLost = round(max(0.0, $parsed - $kept), 2);
            }
        }
        $taskSourceOk = $taskSourceLost === null || $taskSourceLost <= self::ROUNDING_TOLERANCE;
        if (! $taskSourceOk) {
            $issues[] = $this->issue(
                found: (string) ($report['task_source_meters_kept'] ?? $report['task_meters'] ?? $this->sumTaskMeters($areas)),
                expected: (string) ($report['task_source_meters_parsed'] ?? $report['meetstaat_task_meters'] ?? '—'),
                source: 'TASK_SOURCE meetstaat',
                problem: 'Geldige meetstaat-taken verdwenen na assemble/merge/review (verlies '.$taskSourceLost.' m²)',
                suggested: 'Behoud alle strong TASK_SOURCE-regels; fysieke tekeningmatch mag taken nooit wissen',
                anchor: '#importcontrole',
                category: 'task_source',
            );
        }

        $floorMismatches = [];
        foreach ($floors as $floorIndex => $row) {
            $diff = $row['task_meters_difference'] ?? $row['meters_difference'] ?? null;
            if ($diff === null) {
                continue;
            }
            if (abs((float) $diff) <= self::TOLERANCE) {
                continue;
            }
            $floorMismatches[] = $floorIndex;
            $issues[] = $this->issue(
                found: (string) ($row['task_meters'] ?? $row['meters_found'] ?? '—'),
                expected: (string) ($row['meetstaat_task_meters'] ?? $row['meters_expected'] ?? '—'),
                source: 'bouwlaag '.(string) ($row['floor'] ?? ''),
                problem: 'Bouwlaagtotaal taak-m² sluit niet',
                suggested: 'Controleer taken op deze bouwlaag tot verschil 0,00',
                anchor: '#floor-'.$floorIndex,
                category: 'floors',
            );
        }

        foreach ($uncertain as $index => $row) {
            $message = is_array($row)
                ? (string) ($row['message'] ?? $row['text'] ?? json_encode($row))
                : (string) $row;
            $issues[] = $this->issue(
                found: $message,
                expected: 'verwerkt of bewust uitgesloten',
                source: (string) (is_array($row) ? ($row['source'] ?? 'bron') : 'bron'),
                problem: 'Onzekere bronregel',
                suggested: 'Los op of sluit de regel bewust uit',
                anchor: '#uncertain-'.$index,
                category: 'source_rules',
            );
        }

        foreach ($duplicates as $index => $row) {
            $message = is_array($row)
                ? (string) ($row['message'] ?? $row['key'] ?? json_encode($row))
                : (string) $row;
            $issues[] = $this->issue(
                found: $message,
                expected: 'geen open duplicate',
                source: 'duplicaten',
                problem: 'Onverklaarde duplicate',
                suggested: 'Verwijder of voeg samen tot één gecontroleerde ruimte',
                anchor: '#duplicate-'.$index,
                category: 'source_rules',
            );
        }

        $drawingIssues = [];
        if ($hasDrawing) {
            foreach ($areas as $index => $area) {
                $source = (string) ($area['source'] ?? '');
                $via = $area['recognized_via'] ?? [];
                $viaList = is_array($via) ? $via : [];
                $linked = in_array($source, ['plattegrond', 'beide', 'gecombineerd'], true)
                    || in_array('plattegrond', $viaList, true)
                    || filled($area['fill_color'] ?? null)
                    || filled($area['bbox'] ?? null)
                    || filled($area['polygon'] ?? null);
                // Meetstaat-only rooms remain allowed when drawing is present, but flagged if needs drawing link.
                if ($linked || $source === 'meetstaat' || $source === 'handmatig') {
                    continue;
                }
                $drawingIssues[] = $index;
                $issues[] = $this->issue(
                    found: $this->areaLabel($area),
                    expected: 'tekeningkoppeling of bewuste handmatige ruimte',
                    source: (string) ($area['source_label'] ?? $source),
                    problem: 'Geen betrouwbare tekeningkoppeling',
                    suggested: 'Koppel contour/kleur of markeer als handmatig bevestigd',
                    anchor: '#area-'.$index,
                    category: 'drawing',
                );
            }
        }

        $expected = null;
        if (($preview['expected_task_totals']['project_total'] ?? null) !== null) {
            $expected = round((float) $preview['expected_task_totals']['project_total'], 2);
        } elseif (($report['expected_task_totals']['project_total'] ?? null) !== null) {
            $expected = round((float) $report['expected_task_totals']['project_total'], 2);
        } elseif (($report['task_meters_expected'] ?? null) !== null) {
            $expected = round((float) $report['task_meters_expected'], 2);
        } else {
            $materialDeclaredSum = 0.0;
            $materialDeclaredKnown = false;
            foreach ($materials as $row) {
                $declared = $row['expected_task_meters'] ?? $row['declared_total'] ?? null;
                if ($declared === null || ! empty($row['accepted_exception'])) {
                    continue;
                }
                if (($row['status'] ?? '') === 'onbekend') {
                    continue;
                }
                $materialDeclaredKnown = true;
                $materialDeclaredSum += (float) $declared;
            }
            if ($materialDeclaredKnown) {
                $expected = round($materialDeclaredSum, 2);
            }
        }

        $processed = round((float) ($report['task_meters'] ?? $this->sumTaskMeters($areas)), 2);
        $expectedFloat = $expected;
        $difference = $expectedFloat !== null ? round($processed - $expectedFloat, 2) : null;
        $quantitiesClosed = $materialMismatches === [] && $floorMismatches === [];

        if ($expectedFloat === null && $this->expectsMaterials($sources)) {
            $quantitiesClosed = false;
            $issues[] = $this->issue(
                found: (string) round($processed, 2),
                expected: 'gecontroleerd brontotaal',
                source: 'ontbrekend materiaaltotaal',
                problem: 'Geen betrouwbaar verwacht taak-m²-totaal uit bronnen',
                suggested: 'Upload MaterialList/meetstaat met totalen of vul ontbrekende totalen aan',
                anchor: '#importcontrole',
                category: 'quantities',
            );
        } elseif ($expectedFloat !== null && abs((float) $difference) > self::TOLERANCE) {
            if ($this->sourceRoundingProvesDifference($preview, (float) $difference, $materials, $areas)) {
                $quantitiesClosed = $materialMismatches === [] && $floorMismatches === [];
            } else {
                $quantitiesClosed = false;
                $issues[] = $this->issue(
                    found: (string) round($processed, 2),
                    expected: (string) round($expectedFloat, 2),
                    source: 'projecttotaal taak-m²',
                    problem: 'Projecttotaal sluit niet (verschil '.sprintf('%+.2f', (float) $difference).')',
                    suggested: 'Verklaar iedere m² via gekoppelde taken; totalen nooit kunstmatig passend maken',
                    anchor: '#importcontrole',
                    category: 'quantities',
                );
            }
        }

        $sourceRulesOk = $uncertain === [] && $duplicates === [];
        $projectHeaderOk = $headerMismatches === [];
        $roomsOk = $openRooms === [] && $areas !== [];
        $tasksOk = $openTasks === [];
        $materialsOk = $unknownMaterials === [] && $materialMismatches === [];
        $floorsOk = $unknownFloors === [] && $floorMismatches === [];
        $drawingOk = $drawingIssues === [];
        $noOpenControleren = $openRooms === [];
        $quantitiesOk = $quantitiesClosed;

        $checks = [
            $this->check('project_header', 'Projectgegevens van alle bestanden horen bij elkaar', $projectHeaderOk, count($headerMismatches)),
            $this->check('source_rules', 'Alle bronregels verwerkt of bewust uitgesloten', $sourceRulesOk, count($uncertain) + count($duplicates)),
            $this->check('rooms', 'Alle fysieke ruimtes gekoppeld/gecontroleerd', $roomsOk, count($openRooms), $areas === [] ? 'Geen fysieke ruimtes gevonden' : null),
            $this->check('tasks', 'Alle materiaal-/area_tasks gekoppeld', $tasksOk, count($openTasks)),
            $this->check('duplicates', 'Geen onverklaarde duplicates', $duplicates === [], count($duplicates)),
            $this->check('floors', 'Geen onbekende bouwlagen', $unknownFloors === [] && $floorMismatches === [], count($unknownFloors) + count($floorMismatches)),
            $this->check('materials', 'Geen onbekende materialen', $unknownMaterials === [], count($unknownMaterials)),
            $this->check('open_review', 'Geen open Controleren-regels', $noOpenControleren, count($openRooms)),
            $this->check('material_totals', 'Som taak-m² per materiaal = gecontroleerd brontotaal', $materialMismatches === [] && ($expectedFloat !== null || ! $this->expectsMaterials($sources)), count($materialMismatches)),
            $this->check('legend_totals', 'Legenda versus materiaaltaken sluitend of informatief', $legendMismatches === [], count($legendMismatches)),
            $this->check('task_source', 'Alle strong TASK_SOURCE-taken behouden', $taskSourceOk, $taskSourceOk ? 0 : 1),
            $this->check('floor_totals', 'Som taak-m² per bouwlaag = gecontroleerd brontotaal waar bron dit ondersteunt', $floorMismatches === [], count($floorMismatches)),
            $this->check('project_total', 'Projecttotaal = som van de gecontroleerde materiaaltotalen', $quantitiesOk, $quantitiesOk ? 0 : 1),
        ];

        $openPoints = count($issues);
        $differenceAcceptable = $difference === null
            ? ! $this->expectsMaterials($sources)
            : (
                abs((float) $difference) <= self::TOLERANCE
                || $this->sourceRoundingProvesDifference($preview, (float) $difference, $materials, $areas)
            );
        $ready = $openPoints === 0
            && $projectHeaderOk
            && $sourceRulesOk
            && $roomsOk
            && $tasksOk
            && $materialsOk
            && $floorsOk
            && $drawingOk
            && $quantitiesOk
            && $differenceAcceptable
            && $taskSourceOk;

        $roomTotal = max(count($areas), 1);
        $percentages = [
            'rooms' => $this->percent(count($areas) - count($openRooms), $roomTotal),
            'materials' => $this->percent(
                max(count($materials), count($areas)) - count($unknownMaterials) - count($materialMismatches),
                max(count($materials), count($areas), 1)
            ),
            'tasks' => $this->percent(
                $this->countFlooringTasks($areas) - count($openTasks),
                max($this->countFlooringTasks($areas), 1)
            ),
            'quantities' => $quantitiesOk ? 100 : 0,
            'floors' => $this->percent(
                count($areas) - count($unknownFloors),
                $roomTotal
            ),
            'drawing' => $hasDrawing
                ? $this->percent(count($areas) - count($drawingIssues), $roomTotal)
                : 100,
        ];

        $explainedRounding = $difference !== null
            && abs((float) $difference) > self::TOLERANCE
            && $this->sourceRoundingProvesDifference($preview, (float) $difference, $materials, $areas);

        $decision = $ready
            ? ImportDecision::ReadyAutomatic
            : ImportDecision::BlockedConflict;

        $differenceLabel = null;
        if ($difference !== null) {
            $signed = (((float) $difference > 0) ? '+' : '').Format::qty($difference, 2).' m²';
            if ($explainedRounding) {
                $differenceLabel = $signed.' — verklaarde bronafronding ✓';
            } elseif (abs((float) $difference) <= self::TOLERANCE) {
                $differenceLabel = $signed;
            } else {
                $differenceLabel = $signed.' — onverklaard verschil';
            }
        }

        return [
            'ready' => $ready,
            'decision' => $decision->value,
            'decision_label' => $decision->label(),
            'open_points' => $openPoints,
            'percentages' => $percentages,
            'checks' => $checks,
            'issues' => $issues,
            'totals' => [
                'expected' => $expectedFloat !== null ? round($expectedFloat, 2) : null,
                'processed' => round($processed, 2),
                'difference' => $difference,
                'rounding_explained' => $explainedRounding,
                'difference_label' => $differenceLabel,
            ],
            'button_label' => $ready
                ? 'Project definitief importeren'
                : 'Nog '.$openPoints.' '.($openPoints === 1 ? 'punt' : 'punten').' controleren',
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    public function attach(array $preview): array
    {
        $preview['import_closure'] = $this->evaluate($preview);

        return $preview;
    }

    /**
     * Verklaarde bronafronding: som (afgeronde taakregels − declared NET) per materiaal
     * is gelijk aan het projectverschil, en geen restant groter dan de afrondingsband.
     *
     * @param  array<string, mixed>  $preview
     * @param  list<array<string, mixed>>  $materials
     * @param  list<array<string, mixed>>  $areas
     */
    private function sourceRoundingProvesDifference(array $preview, float $projectDifference, array $materials, array $areas): bool
    {
        if (abs($projectDifference) > self::ROUNDING_TOLERANCE) {
            return false;
        }

        $byMaterial = $preview['expected_task_totals']['by_material'] ?? [];
        if (is_array($byMaterial) && $byMaterial !== []) {
            $identity = new MaterialIdentity;
            $residualSum = 0.0;
            $sawNonZero = false;
            foreach ($byMaterial as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['material'] ?? ''));
                $declared = (float) ($row['expected_task_meters'] ?? 0);
                if ($name === '' || $declared <= 0) {
                    continue;
                }
                $found = 0.0;
                foreach ($areas as $area) {
                    foreach ($this->flooringTasks($area) as $task) {
                        $taskName = trim((string) ($task['work_name'] ?? ''));
                        if ($taskName === '' || ! $identity->sharesIdentity($name, $taskName)) {
                            continue;
                        }
                        $found += (float) ($task['quantity'] ?? 0);
                    }
                }
                $residual = round($found - $declared, 2);
                if (abs($residual) > self::ROUNDING_TOLERANCE) {
                    return false;
                }
                if (abs($residual) > self::TOLERANCE) {
                    $sawNonZero = true;
                }
                $residualSum += $residual;
            }

            return $sawNonZero && abs(round($residualSum, 2) - $projectDifference) <= self::TOLERANCE;
        }

        $residualSum = 0.0;
        $sawNonZero = false;
        $sawKnown = false;
        foreach ($materials as $row) {
            if (! is_array($row) || ! empty($row['accepted_exception'])) {
                continue;
            }
            if (($row['status'] ?? '') === 'onbekend') {
                continue;
            }
            if (! array_key_exists('difference', $row) || $row['difference'] === null) {
                continue;
            }
            $sawKnown = true;
            $residual = round((float) $row['difference'], 2);
            if (abs($residual) > self::ROUNDING_TOLERANCE) {
                return false;
            }
            if (abs($residual) > self::TOLERANCE) {
                $sawNonZero = true;
            }
            $residualSum += $residual;
        }

        return $sawKnown
            && $sawNonZero
            && abs(round($residualSum, 2) - $projectDifference) <= self::TOLERANCE;
    }

    /**
     * @param  array<string, bool>  $sources
     */
    private function expectsMaterials(array $sources): bool
    {
        return (bool) ($sources['meetstaat'] ?? false)
            || (bool) ($sources['materialenstaat'] ?? false)
            || (bool) ($sources['kleur'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function areaLabel(array $area): string
    {
        $parts = array_filter([
            $area['room_number'] ?? null,
            $area['room_name'] ?? null,
            isset($area['floor']) ? '('.$area['floor'].')' : null,
        ], fn ($value) => filled($value));

        return $parts !== [] ? implode(' ', $parts) : 'Ruimte';
    }

    /**
     * @param  array<string, mixed>  $area
     * @return list<array<string, mixed>>
     */
    private function flooringTasks(array $area): array
    {
        $tasks = [];
        foreach ($area['tasks'] ?? [] as $task) {
            if (! is_array($task)) {
                continue;
            }
            $unit = (string) ($task['unit'] ?? 'm2');
            if (! in_array($unit, ['m2', 'm²'], true)) {
                continue;
            }
            $name = mb_strtolower((string) ($task['work_name'] ?? ''));
            if (str_contains($name, 'plint')) {
                continue;
            }
            if (trim((string) ($task['work_name'] ?? '')) === '') {
                continue;
            }
            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function countFlooringTasks(array $areas): int
    {
        $count = 0;
        foreach ($areas as $area) {
            $count += count($this->flooringTasks($area));
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $areas
     */
    private function sumTaskMeters(array $areas): float
    {
        $sum = 0.0;
        foreach ($areas as $area) {
            foreach ($this->flooringTasks($area) as $task) {
                $sum += (float) ($task['quantity'] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(
        string $found,
        string $expected,
        string $source,
        string $problem,
        string $suggested,
        string $anchor,
        string $category,
    ): array {
        return [
            'found' => $found,
            'expected' => $expected,
            'source' => $source,
            'problem' => $problem,
            'suggested_match' => $suggested,
            'anchor' => $anchor,
            'category' => $category,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function check(string $key, string $label, bool $ok, int $open = 0, ?string $detail = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'open' => $open,
            'detail' => $detail,
        ];
    }

    private function percent(int $ok, int $total): int
    {
        if ($total <= 0) {
            return 100;
        }

        return (int) max(0, min(100, (int) round(($ok / $total) * 100)));
    }
}
