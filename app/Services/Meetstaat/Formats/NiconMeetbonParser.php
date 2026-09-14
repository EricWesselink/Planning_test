<?php

namespace App\Services\Meetstaat\Formats;

use App\Enums\WorkUnit;
use App\Services\Meetstaat\Contracts\MeetstaatFormatParser;
use App\Services\Meetstaat\FloorLabel;
use App\Services\Meetstaat\MaterialIdentity;
use App\Services\Meetstaat\ProjectDocumentHeader;
use App\Support\DutchNumber;

class NiconMeetbonParser implements MeetstaatFormatParser
{
    public function name(): string
    {
        return 'nicon_meetbon';
    }

    public function matches(string $text): bool
    {
        $flat = mb_strtolower($text);

        return str_contains($flat, 'meetstaat')
            && str_contains($flat, 'bouwlaag:')
            && (str_contains($flat, 'werknr') || str_contains($flat, 'opdrachtgever'));
    }

    public function parse(string $text): array
    {
        $lines = preg_split("/\n/", $text) ?: [];
        $header = ProjectDocumentHeader::empty();
        $works = [];
        $currentWorkIndex = null;
        $currentFloor = null;
        $pendingName = [];
        $awaitingProductTotal = false;
        $warnings = [];
        $uncertain = [];

        foreach ($lines as $line) {
            $line = preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $line) ?? $line;
            $line = preg_replace('/\bedit_square\b/i', '', $line) ?? $line;
            $line = trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line, " \t\n\r\0\v\f");
            if ($line === '') {
                continue;
            }
            if (preg_match('/^Pagina\s+\d+/i', $line)) {
                continue;
            }
            $line = trim(preg_replace('/^Meetstaat\s+/i', '', $line) ?? $line);
            if ($line === '' || preg_match('/^Meetstaat$/i', $line)) {
                continue;
            }
            if ($this->isColumnHeader($line)) {
                continue;
            }

            if (ProjectDocumentHeader::applyLine($header, $line)) {
                continue;
            }

            if (preg_match('/^Bouwlaag:\s*(.+)$/i', $line, $match)) {
                $pending = trim(implode(' ', $pendingName));
                if ($pending !== '' && $this->looksLikeProductName($pending)
                    && ($currentWorkIndex === null || $this->workAlreadyHasRooms($works[$currentWorkIndex]))) {
                    if ($this->pendingStartsNewWork($pending, $works, $currentWorkIndex)) {
                        $currentWorkIndex = $this->startWork($works, $pendingName, $pending);
                        $pendingName = [];
                    } else {
                        $this->applyPendingName($works, $currentWorkIndex, $pendingName);
                    }
                } else {
                    $this->applyPendingName($works, $currentWorkIndex, $pendingName);
                }
                $currentFloor = trim($match[1]);
                $awaitingProductTotal = false;

                continue;
            }

            if (preg_match('/^(Netto|Bruto(?:\s*-\s*Deur)?|Snijverlies)\s*:/i', $line)) {
                $needNew = $currentWorkIndex === null
                    || ($pendingName !== []
                        && $this->workAlreadyHasRooms($works[$currentWorkIndex])
                        && $this->pendingStartsNewWork(trim(implode(' ', $pendingName)), $works, $currentWorkIndex));
                if ($needNew) {
                    $currentWorkIndex = $this->startWork($works, $pendingName, $line);
                } else {
                    $this->applyPendingName($works, $currentWorkIndex, $pendingName);
                }
                $this->applyDeclaredTotal($works[$currentWorkIndex], $line);
                $pendingName = [];
                $awaitingProductTotal = false;

                continue;
            }

            if (preg_match('/^Totaal$/i', $line)) {
                $awaitingProductTotal = true;

                continue;
            }

            $measures = $this->extractMeasures($line);
            if ($measures !== null) {
                $label = $measures['label'];

                if ($awaitingProductTotal || ($label === '' && $currentWorkIndex !== null && $currentFloor === null)) {
                    if ($currentWorkIndex !== null) {
                        $works[$currentWorkIndex]['declared_total'] = $measures['quantity']
                            ?? $measures['perimeter']
                            ?? $works[$currentWorkIndex]['declared_total'];
                    }
                    $awaitingProductTotal = false;

                    continue;
                }

                if (strcasecmp($label, 'Totaal') === 0) {
                    if ($currentWorkIndex !== null && $currentFloor !== null) {
                        $works[$currentWorkIndex]['floor_totals'][$currentFloor] = [
                            'declared' => $measures['quantity'] ?? $measures['perimeter'] ?? 0,
                            'declared_perimeter' => $measures['perimeter'],
                            'declared_seams' => $measures['seams'],
                        ];
                    }

                    continue;
                }

                if ($currentWorkIndex === null) {
                    if ($this->looksLikeRoomLabel($label) && $pendingName === []) {
                        $uncertain[] = ['line' => $line, 'reason' => 'Ruimte zonder product erboven.'];

                        continue;
                    }
                    $currentWorkIndex = $this->startWork($works, $pendingName, $label);
                    $pendingName = [];
                }

                $room = $this->splitRoom($label);
                if ($currentFloor === null) {
                    $currentFloor = 'Onbekend';
                }

                $floor = $currentFloor;
                if ($this->floorLabel()->isUnknown($floor)) {
                    $inferred = $this->floorLabel()->fromRoomNumber($room['number'] ?? '');
                    if ($inferred !== null) {
                        $floor = $inferred;
                    } else {
                        $uncertain[] = ['line' => $line, 'reason' => 'Geen bouwlaag boven deze regel.'];
                    }
                }

                $works[$currentWorkIndex]['lines'][] = [
                    'floor' => $floor,
                    'room_number' => $room['number'],
                    'room_name' => $room['name'],
                    'quantity' => $measures['quantity'],
                    'perimeter' => $measures['perimeter'],
                    'seams' => $measures['seams'],
                    'unit' => $works[$currentWorkIndex]['unit'],
                    'raw' => $line,
                ];

                continue;
            }

            if ($this->looksLikeProductName($line)) {
                $pendingName[] = $line;
            } elseif ($pendingName !== [] && $this->materialIdentity()->looksLikeProductTypeContinuation($line)) {
                // Tweede regel van een productkop: "… 7133080 (96 x 96 cm)," + "Tapijttegels".
                $pendingName[] = $line;
            } elseif ($currentWorkIndex !== null
                && $this->leadingWorkCode((string) $works[$currentWorkIndex]['name']) !== null
                && $this->isDescriptionContinuation($line)) {
                $pendingName[] = $line;
            } else {
                $uncertain[] = ['line' => $line, 'reason' => 'Regel niet herkend.'];
            }
        }

        $this->applyPendingName($works, $currentWorkIndex, $pendingName);

        $folded = $this->foldAreas($works);
        $summaries = $this->summarizeWorks($works, $folded['areas']);

        if ($header['project_name'] === null && filled($header['reference'])) {
            $header['project_name'] = (string) $header['reference'];
        }

        return [
            'format' => $this->name(),
            'header' => $header,
            'works' => $summaries,
            'areas' => $folded['areas'],
            'floors' => $folded['floors'],
            'warnings' => array_merge($warnings, $folded['warnings']),
            'uncertain' => $uncertain,
            'duplicates' => $folded['duplicates'],
        ];
    }

    private function isColumnHeader(string $line): bool
    {
        return (bool) preg_match('/^Ruimte(\s+(Oppervlakte|Omtrek)|$)/i', $line)
            || (bool) preg_match('/^Oppervlakte(\s|$)/i', $line)
            || (bool) preg_match('/^Omtrek(\s|-|$)/i', $line)
            || strcasecmp($line, 'Banen') === 0
            || strcasecmp($line, 'Naden') === 0;
    }

    private function looksLikeProductName(string $line): bool
    {
        if ($this->isColumnHeader($line)) {
            return false;
        }
        if (preg_match('/\d+\s*m(²|2)?\b/u', $line)) {
            return false;
        }
        if (preg_match('/^(omtrek|oppervlakte|naden|ruimte|totaal|pagina|bouwlaag)\b/i', $line)) {
            return false;
        }
        if (preg_match('/^V\.\d{2}\b/u', $line)) {
            return true;
        }
        if ($this->materialIdentity()->leadingWorkCode($line) !== null) {
            return true;
        }
        if ($this->looksLikeRoomLabel($line)) {
            return false;
        }

        if ($this->materialIdentity()->looksLikeStrongProductHeader($line)) {
            return true;
        }

        return (bool) preg_match('/marmoleum|plint|gietvloer|entreemat|pvc|tapijt|linoleum|coral|coating|vinyl/i', $line)
            || (bool) preg_match('/^[^,]{2,},\s*[^,]{1,},\s*[^,]{2,}$/', $line);
    }

    private function materialIdentity(): MaterialIdentity
    {
        return new MaterialIdentity;
    }

    private function floorLabel(): FloorLabel
    {
        return new FloorLabel;
    }

    /** @param list<string> $pendingName */
    private function startWork(array &$works, array $pendingName, string $hint): int
    {
        $name = trim(implode(' ', $pendingName));
        if ($name === '' || $this->looksLikeMeasureLabel($name)) {
            $name = $this->cleanProductHint($hint);
        }
        $name = $this->cleanProductName($name);
        $unit = $this->unitFromProductName($name);

        $works[] = [
            'name' => $name !== '' ? $name : 'Onbekend product',
            'unit' => $unit,
            'declared_total' => null,
            'floor_totals' => [],
            'lines' => [],
        ];

        return array_key_last($works);
    }

    /** @param list<string> $pendingName */
    private function applyPendingName(array &$works, ?int $index, array &$pendingName): void
    {
        if ($index !== null && $pendingName !== []) {
            $pending = $this->cleanProductName(implode(' ', $pendingName));
            if (in_array($works[$index]['name'], ['Onbekend product', ''], true)) {
                $works[$index]['name'] = $pending;
                $works[$index]['unit'] = $this->unitFromProductName($works[$index]['name']);
            } elseif ($pending !== ''
                && $this->leadingWorkCode((string) $works[$index]['name']) !== null
                && $this->leadingWorkCode($pending) === null
                && ! str_contains(mb_strtolower((string) $works[$index]['name']), mb_strtolower($pending))) {
                $works[$index]['name'] = $this->cleanProductName($works[$index]['name'].' '.$pending);
                $works[$index]['unit'] = $this->unitFromProductName($works[$index]['name']);
            }
        }
        $pendingName = [];
    }

    /**
     * @param  list<array<string, mixed>>  $works
     */
    private function pendingStartsNewWork(string $pending, array $works, ?int $currentWorkIndex): bool
    {
        if ($currentWorkIndex === null) {
            return true;
        }
        $pendingCode = $this->leadingWorkCode($pending);
        $currentCode = $this->leadingWorkCode((string) ($works[$currentWorkIndex]['name'] ?? ''));
        if ($pendingCode !== null) {
            if ($pendingCode !== $currentCode) {
                return true;
            }

            return ! $this->materialIdentity()->sameExecutionVariant(
                (string) ($works[$currentWorkIndex]['name'] ?? ''),
                $pending
            );
        }
        if ($currentCode !== null) {
            return false;
        }

        return true;
    }

    private function leadingWorkCode(string $line): ?string
    {
        return $this->materialIdentity()->leadingWorkCode($line);
    }

    private function isDescriptionContinuation(string $line): bool
    {
        if ($this->leadingWorkCode($line) !== null) {
            return false;
        }

        return $this->materialIdentity()->looksLikeProductTypeContinuation($line)
            || $this->materialIdentity()->isGenericTypeLabel($line)
            || (bool) preg_match('/^(donkergrijs|lichtgrijs|zwart|wit|grijs)\b/iu', $line);
    }

    private function workAlreadyHasRooms(array $work): bool
    {
        return ($work['lines'] ?? []) !== [];
    }

    private function applyDeclaredTotal(array &$work, string $line): void
    {
        if (preg_match('/^Netto\s*:\s*(.+)$/i', $line, $match) && $work['declared_total'] === null) {
            $work['declared_total'] = DutchNumber::parse($match[1]);
            $work['unit'] = WorkUnit::SquareMeter->value;
        }
        if (preg_match('/^Bruto\s*-\s*Deur\s*:\s*(.+)$/i', $line, $match) && $work['declared_total'] === null) {
            $work['declared_total'] = DutchNumber::parse($match[1]);
            $work['unit'] = WorkUnit::LinearMeter->value;
        }
    }

    /**
     * @return array{label: string, quantity: ?float, perimeter: ?float, seams: ?float}|null
     */
    private function extractMeasures(string $line): ?array
    {
        if (! preg_match_all('/(\d+(?:[.,]\d+)?)\s*m(²|2)?\b/u', $line, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $label = trim(preg_replace('/(\d+(?:[.,]\d+)?)\s*m(²|2)?\b/u', '', $line) ?? $line);
        $label = trim($label, " \t-:");

        $area = null;
        $meters = [];
        foreach ($matches as $match) {
            $number = DutchNumber::parse($match[1]);
            if ($number === null) {
                continue;
            }
            if (($match[2] ?? '') !== '') {
                $area = $number;
            } else {
                $meters[] = $number;
            }
        }

        return [
            'label' => $label,
            'quantity' => $area,
            'perimeter' => $meters[0] ?? null,
            'seams' => $meters[1] ?? null,
        ];
    }

    /** @return array{number: ?string, name: string} */
    private function splitRoom(string $label): array
    {
        $label = trim($label);
        if (preg_match('/^(\d+[.\-]\d+)([a-zA-Z])?(.*)$/u', $label, $match)) {
            $base = $match[1];
            $letter = $match[2] ?? '';
            $rawRest = $match[3];
            $rest = trim($rawRest);

            if ($letter === '') {
                return ['number' => $base, 'name' => $rest !== '' ? $rest : $base];
            }

            $glued = $rawRest !== '' && ! preg_match('/^\s/u', $rawRest);
            if ($glued) {
                return ['number' => $base, 'name' => $letter.$rest];
            }

            $number = $base.$letter;

            return ['number' => $number, 'name' => $rest !== '' ? $rest : $number];
        }

        return ['number' => null, 'name' => $label !== '' ? $label : 'Ruimte'];
    }

    private function looksLikeRoomLabel(string $label): bool
    {
        if ($this->leadingWorkCode($label) !== null) {
            return false;
        }

        return (bool) preg_match('/^\d+[.\-]\d+/', $label);
    }

    /** @param list<array<string, mixed>> $works */
    private function foldAreas(array $works): array
    {
        $areas = [];
        $floors = [];
        $warnings = [];
        $duplicates = [];
        $unnumberedIndex = [];

        foreach ($works as $workIndex => $work) {
            $seenInWork = [];
            foreach ($work['lines'] as $line) {
                $floor = $line['floor'];
                $floors[$floor] = true;
                $number = $line['room_number'] ? mb_strtolower($line['room_number']) : null;
                $nameKey = mb_strtolower($line['room_name']);

                if ($number) {
                    $key = $floor.'|'.$number;
                } else {
                    $seqKey = $floor.'|'.$nameKey;
                    $seenInWork[$seqKey] = ($seenInWork[$seqKey] ?? 0) + 1;
                    $unnumberedIndex[$seqKey] = max($unnumberedIndex[$seqKey] ?? 0, $seenInWork[$seqKey]);
                    $key = $seqKey.'#'.$seenInWork[$seqKey];
                }

                if (! isset($areas[$key])) {
                    $areas[$key] = [
                        'key' => $key,
                        'floor' => $floor,
                        'room_number' => $line['room_number'],
                        'room_name' => $line['room_name'],
                        'tasks' => [],
                    ];
                }

                $taskKey = $this->canonicalWorkName($work['name']).'|'.$work['unit'];
                if (! isset($areas[$key]['tasks'][$taskKey])) {
                    $areas[$key]['tasks'][$taskKey] = [
                        'work_name' => $this->canonicalWorkName($work['name']),
                        'unit' => $work['unit'],
                        'quantity' => 0.0,
                        'perimeter' => 0.0,
                        'seams' => 0.0,
                        'parts' => 0,
                    ];
                } else {
                    $duplicates[] = [
                        'key' => $key,
                        'work' => $this->canonicalWorkName($work['name']),
                        'floor' => $floor,
                        'room' => trim(($line['room_number'] ?? '').' '.$line['room_name']),
                    ];
                }

                $areas[$key]['tasks'][$taskKey]['quantity'] += (float) ($line['quantity'] ?? 0);
                $areas[$key]['tasks'][$taskKey]['perimeter'] += (float) ($line['perimeter'] ?? 0);
                $areas[$key]['tasks'][$taskKey]['seams'] += (float) ($line['seams'] ?? 0);
                $areas[$key]['tasks'][$taskKey]['parts']++;
            }
        }

        foreach ($areas as &$area) {
            $area['tasks'] = array_values($area['tasks']);
            foreach ($area['tasks'] as $task) {
                if ($task['parts'] > 1) {
                    $warnings[] = $area['room_number']
                        ? $area['room_number'].' '.$area['room_name'].' · '.$task['work_name'].': '.$task['parts'].' deelvlakken samengevoegd.'
                        : $area['room_name'].' · '.$task['work_name'].': '.$task['parts'].' deelvlakken samengevoegd.';
                }
            }
        }

        return [
            'areas' => array_values($areas),
            'floors' => array_keys($floors),
            'warnings' => $warnings,
            'duplicates' => $this->uniqueDuplicates($duplicates),
        ];
    }

    /** @param list<array<string, mixed>> $works */
    private function summarizeWorks(array $works, array $areas): array
    {
        $merged = [];

        foreach ($works as $index => $work) {
            $name = $this->canonicalWorkName($work['name']);
            if (! isset($merged[$name])) {
                $merged[$name] = [
                    'name' => $name,
                    'unit' => $work['unit'],
                    'declared_total' => 0.0,
                    'calculated_total' => 0.0,
                    'source_names' => [],
                ];
            }
            $merged[$name]['declared_total'] = round($merged[$name]['declared_total'] + (float) ($work['declared_total'] ?? 0), 2);
            $merged[$name]['source_names'][] = $work['name'];
            $merged[$name]['indexes'][] = $index;
        }

        foreach ($areas as $area) {
            foreach ($area['tasks'] as $task) {
                $name = $this->canonicalWorkName($task['work_name']);
                if (! isset($merged[$name])) {
                    continue;
                }
                $amount = $merged[$name]['unit'] === WorkUnit::LinearMeter->value
                    ? (float) $task['perimeter']
                    : (float) $task['quantity'];
                $merged[$name]['calculated_total'] = round($merged[$name]['calculated_total'] + $amount, 2);
            }
        }

        return array_values($merged);
    }

    private function canonicalWorkName(string $name): string
    {
        $flat = mb_strtolower($name);
        if (str_contains($flat, 'plint')) {
            return 'Plinten wit';
        }

        return trim($name, ' ,');
    }

    private function unitFromProductName(string $name): string
    {
        $flat = mb_strtolower($name);

        return str_contains($flat, 'plint')
            ? WorkUnit::LinearMeter->value
            : WorkUnit::SquareMeter->value;
    }

    private function cleanProductName(string $name): string
    {
        $name = preg_replace('/\b(edit_square|banen|omtrek\s*-?\s*deur|oppervlakte|naden)\b/i', '', $name) ?? $name;
        $name = preg_replace('/^(meetstaat|pagina\s+\d+.*)\b/i', '', $name) ?? $name;
        $name = preg_replace('/\s+,/', ',', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name, ' ,');
    }

    private function cleanProductHint(string $hint): string
    {
        return $this->cleanProductName(preg_replace('/^(Netto|Bruto).*/i', '', $hint) ?? $hint);
    }

    private function looksLikeMeasureLabel(string $name): bool
    {
        return (bool) preg_match('/^(netto|bruto|snijverlies)/i', $name);
    }

    /** @param list<array<string, string>> $duplicates */
    private function uniqueDuplicates(array $duplicates): array
    {
        $unique = [];
        foreach ($duplicates as $row) {
            $id = $row['key'].'|'.$row['work'];
            $unique[$id] = $row;
        }

        return array_values($unique);
    }
}
