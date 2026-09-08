<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\Meetstaat\FloorPlanParser;
use App\Services\Meetstaat\MeetstaatReader;
use App\Services\Meetstaat\PdfTextExtractor;
use App\Services\Meetstaat\RoomImportAssembler;
use App\Support\WorkType;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\RealDrawingFixtures;

$extractor = new PdfTextExtractor;
$drawingPath = RealDrawingFixtures::laakseTuinenDrawingPath();
$drawing = (new FloorPlanParser($extractor))->parseFile($drawingPath, 'tekening.pdf');
$meetstaat = (new MeetstaatReader($extractor))->parseFile(
    RealDrawingFixtures::laakseTuinenMeetstaatPath(),
    'Meetbon_Laakse_Tuinen.pdf'
);

// Instrument duplicate removals via subclass
$assembler = new class extends RoomImportAssembler
{
    /** @var list<array<string, mixed>> */
    public array $removed = [];

    protected function recordDuplicateRemoval(array $area): void
    {
        $meters = 0.0;
        foreach ($area['tasks'] ?? [] as $task) {
            $unit = (string) ($task['unit'] ?? 'm2');
            $name = mb_strtolower((string) ($task['work_name'] ?? ''));
            if (($unit === 'm2' || $unit === 'm²') && $name !== '' && ! str_contains($name, 'plint')) {
                $meters += (float) ($task['quantity'] ?? 0);
            }
        }
        if ($meters <= 0.0001 && ($area['square_meters'] ?? null) !== null) {
            $meters = (float) $area['square_meters'];
        }
        $this->removed[] = [
            'floor' => $area['floor'] ?? '',
            'room_number' => $area['room_number'] ?? '',
            'room_name' => $area['room_name'] ?? '',
            'meters' => round($meters, 2),
            'material' => $area['legend_material'] ?? ($area['tasks'][0]['work_name'] ?? ''),
            'page' => $area['page'] ?? null,
            'source' => $area['source'] ?? 'plattegrond',
            'glued' => (bool) ($area['number_from_glued_name'] ?? false),
        ];
        parent::recordDuplicateRemoval($area);
    }
};

// recordDuplicateRemoval is private - need reflection approach instead
$ref = new ReflectionClass(RoomImportAssembler::class);
$preview = (new RoomImportAssembler)->assemble($meetstaat, $drawing);

$isFlooring = static function (array $task): bool {
    $unit = (string) ($task['unit'] ?? 'm2');
    if ($unit !== 'm2' && $unit !== 'm²') {
        return false;
    }
    $name = mb_strtolower((string) ($task['work_name'] ?? ''));

    return $name !== '' && ! str_contains($name, 'plint') && ! WorkType::looksLikeRoom($name)
        && (float) ($task['quantity'] ?? 0) > 0;
};

$normalizeName = static function (string $name): string {
    $name = mb_strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;

    return $name;
};

$ocrNormalize = static function (string $name) use ($normalizeName): string {
    $name = $normalizeName($name);
    // collapse repeated letters: speeellokaal / seloal heuristics later
    $name = preg_replace('/(.)\1{2,}/u', '$1$1', $name) ?? $name;

    return $name;
};

$numberScore = static function (?string $a, ?string $b): string {
    $a = mb_strtolower(trim((string) $a));
    $b = mb_strtolower(trim((string) $b));
    if ($a === '' || $b === '') {
        return 'geen';
    }
    if ($a === $b) {
        return 'exact';
    }

    return 'anders';
};

$nameScore = static function (string $found, string $candidate) use ($ocrNormalize): string {
    $a = $ocrNormalize($found);
    $b = $ocrNormalize($candidate);
    if ($a === '' || $b === '') {
        return 'geen';
    }
    if ($a === $b) {
        return 'exact';
    }
    if (str_contains($a, $b) || str_contains($b, $a)) {
        return 'deels';
    }
    // OCR: shared consonants / letters ratio
    $sa = preg_replace('/[^a-z]/u', '', $a) ?? '';
    $sb = preg_replace('/[^a-z]/u', '', $b) ?? '';
    if ($sa !== '' && $sb !== '') {
        similar_text($sa, $sb, $pct);
        if ($pct >= 70) {
            return 'ocr-zwak('.round($pct).'%)';
        }
        // check if found is subsequence of candidate letters (seloal ⊂ speellokaal)
        $i = 0;
        $len = mb_strlen($sb);
        foreach (mb_str_split($sa) as $ch) {
            while ($i < $len && $sb[$i] !== $ch) {
                $i++;
            }
            if ($i >= $len) {
                return 'anders';
            }
            $i++;
        }

        return 'ocr-subseq';
    }

    return 'anders';
};

$meterScore = static function (?float $a, ?float $b): string {
    if ($a === null || $b === null) {
        return 'geen';
    }
    $d = abs($a - $b);
    if ($d <= 0.05) {
        return 'exact';
    }
    if ($d <= 0.5) {
        return 'dicht';
    }

    return 'anders('.round($d, 2).')';
};

$controleren = collect($preview['areas'])->filter(function (array $a) {
    return mb_strtolower(trim((string) ($a['floor'] ?? ''))) === 'begane grond'
        && (($a['confidence'] ?? '') === 'controleren' || ($a['needs_review'] ?? false) === true)
        && (($a['source'] ?? '') === 'plattegrond' || filled($a['material_conflict'] ?? null));
})->values();

// Also include plattegrond-only that were in the remaining list
$plattegrondBg = collect($preview['areas'])->filter(function (array $a) {
    return mb_strtolower(trim((string) ($a['floor'] ?? ''))) === 'begane grond'
        && ($a['source'] ?? '') === 'plattegrond';
})->values();

$meetBgAll = collect($meetstaat['areas'] ?? [])->filter(function (array $a) {
    $f = mb_strtolower(trim((string) ($a['floor'] ?? '')));

    return str_starts_with($f, 'begane grond');
})->values();

$meetAll = collect($meetstaat['areas'] ?? []);

$rows = [];
foreach ($plattegrondBg as $room) {
    $num = (string) ($room['room_number'] ?? '');
    $name = (string) ($room['room_name'] ?? '');
    $phys = isset($room['square_meters']) ? (float) $room['square_meters'] : null;
    $mat = (string) ($room['legend_material'] ?? '');
    $taskMat = '';
    $taskQty = 0.0;
    foreach ($room['tasks'] ?? [] as $t) {
        if ($isFlooring($t)) {
            $taskMat = (string) ($t['work_name'] ?? '');
            $taskQty += (float) ($t['quantity'] ?? 0);
        }
    }
    if ($mat === '') {
        $mat = $taskMat;
    }
    $m2 = $phys ?? ($taskQty > 0 ? $taskQty : null);

    $best = null;
    $bestRank = -1;
    foreach ($meetAll as $ms) {
        $msNum = (string) ($ms['room_number'] ?? '');
        $msName = (string) ($ms['room_name'] ?? '');
        $msFloor = (string) ($ms['floor'] ?? '');
        $msPhys = null;
        $msTask = 0.0;
        foreach ($ms['tasks'] ?? [] as $t) {
            if ($isFlooring($t)) {
                $msTask += (float) ($t['quantity'] ?? 0);
            }
        }
        if (count(array_filter($ms['tasks'] ?? [], $isFlooring)) === 1) {
            $msPhys = $msTask;
        }
        $msM2 = $msPhys ?? ($msTask > 0 ? $msTask : null);

        $nScore = $numberScore($num, $msNum);
        $nmScore = $nameScore($name, $msName);
        $mScore = $meterScore($m2, $msM2);
        $rank = 0;
        if ($nScore === 'exact') {
            $rank += 100;
        }
        if ($mScore === 'exact') {
            $rank += 50;
        } elseif ($mScore === 'dicht') {
            $rank += 20;
        }
        if (str_starts_with($nmScore, 'ocr') || $nmScore === 'deels' || $nmScore === 'exact') {
            $rank += 10;
        }
        if (mb_strtolower($msFloor) === 'begane grond') {
            $rank += 5;
        } elseif (str_contains(mb_strtolower($msFloor), 'begane grond')) {
            $rank += 2;
        }
        if ($rank > $bestRank) {
            $bestRank = $rank;
            $best = [
                'floor' => $msFloor,
                'number' => $msNum,
                'name' => $msName,
                'm2' => $msM2,
                'number_score' => $nScore,
                'name_score' => $nmScore,
                'm2_score' => $mScore,
            ];
        }
    }

    $sameFloorDrawing = collect($drawing['areas'] ?? [])->first(function (array $d) use ($num, $name, $phys) {
        if ($num !== '' && (string) ($d['room_number'] ?? '') === $num) {
            return true;
        }
        if ($phys !== null && abs((float) ($d['square_meters'] ?? -1) - $phys) < 0.02) {
            return true;
        }

        return mb_strtolower((string) ($d['room_name'] ?? '')) === mb_strtolower($name);
    });

    $rows[] = [
        'page' => $room['page'] ?? ($sameFloorDrawing['page'] ?? null),
        'found_number' => $num,
        'found_name' => $name,
        'm2' => $m2,
        'material' => $mat,
        'confidence' => $room['confidence'] ?? '',
        'conflict' => $room['material_conflict'] ?? null,
        'x' => $room['x'] ?? $sameFloorDrawing['x'] ?? null,
        'y' => $room['y'] ?? $sameFloorDrawing['y'] ?? null,
        'best' => $best,
        'rank' => $bestRank,
    ];
}

usort($rows, fn ($a, $b) => ($b['m2'] ?? 0) <=> ($a['m2'] ?? 0));

echo "=== PLATTEGROND-ONLY / CONTROLEREN BG ===\n";
foreach ($rows as $r) {
    $b = $r['best'];
    echo sprintf(
        "p%s | %s | %s | %.2f | %s | nearest: [%s] %s %s %.2f | num=%s naam=%s m2=%s | conf=%s | conflict=%s | xy=%s,%s\n",
        $r['page'] ?? '?',
        $r['found_number'] !== '' ? $r['found_number'] : '—',
        $r['found_name'],
        $r['m2'] ?? 0,
        mb_substr($r['material'], 0, 28),
        $b['floor'] ?? '',
        $b['number'] ?? '',
        $b['name'] ?? '',
        $b['m2'] ?? 0,
        $b['number_score'] ?? '',
        $b['name_score'] ?? '',
        $b['m2_score'] ?? '',
        $r['confidence'],
        $r['conflict'] ? 'ja' : 'nee',
        $r['x'] ?? '—',
        $r['y'] ?? '—',
    );
}

// Raw drawing rooms with 3.66 / 3.8 / seloal / scheidsrechter
echo "\n=== RAW DRAWING HITS ===\n";
foreach ($drawing['areas'] ?? [] as $d) {
    $n = mb_strtolower((string) ($d['room_number'] ?? '').' '.($d['room_name'] ?? ''));
    $phys = (float) ($d['square_meters'] ?? 0);
    if (str_contains($n, 'seloal') || str_contains($n, 'speel') || str_contains($n, '0.21')
        || str_contains($n, '3.66') || str_contains($n, '3.8') || str_contains($n, 'scheid')
        || str_contains($n, '0.12') || abs($phys - 84.59) < 0.05 || abs($phys - 58) < 0.05
        || abs($phys - 10.43) < 0.05 || abs($phys - 10.32) < 0.05 || abs($phys - 3.66) < 0.05) {
        echo sprintf(
            "p%s floor=%s num=%s name=%s phys=%s\n",
            $d['page'] ?? '?',
            $d['floor'] ?? '',
            $d['room_number'] ?? '',
            $d['room_name'] ?? '',
            $d['square_meters'] ?? 'null'
        );
    }
}

// Meetstaat 0.21 and sporthal rooms
echo "\n=== MEETSTAAT 0.21 / scheids / hal / toilet ===\n";
foreach ($meetstaat['areas'] ?? [] as $m) {
    $blob = mb_strtolower(($m['room_number'] ?? '').' '.($m['room_name'] ?? ''));
    if (str_contains($blob, '0.21') || str_contains($blob, 'scheid') || str_contains($blob, 'hal')
        || str_contains($blob, 'toilet') || str_contains($blob, '0.14') || str_contains($blob, '0.16')
        || str_contains($blob, '0.17') || str_contains($blob, 'egels')) {
        $qty = 0;
        foreach ($m['tasks'] ?? [] as $t) {
            if ($isFlooring($t)) {
                $qty += (float) $t['quantity'];
            }
        }
        echo sprintf(
            "%s | %s %s | tasks=%.2f | floor=%s\n",
            $m['room_number'] ?? '—',
            $m['room_name'] ?? '',
            '',
            $qty,
            $m['floor'] ?? ''
        );
    }
}

$bg = collect($preview['import_report']['floors'])->first(fn ($f) => $f['floor'] === 'begane grond');
echo "\nBG diff=".$bg['task_meters_difference'].' stats='.json_encode($preview['import_report']['safe_fix_stats'])."\n";
echo 'plattegrond count='.count($rows)."\n";

file_put_contents(__DIR__.'/storage/app/tmp-controleren-class.json', json_encode([
    'rows' => $rows,
    'bg' => $bg,
    'stats' => $preview['import_report']['safe_fix_stats'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
