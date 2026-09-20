<?php

namespace App\Services\AreaWithoutM2Trial;

/**
 * Renders an image-only PDF page and reads words plus axis-aligned lines.
 * Isolated to the area-without-m² trial; not used by quote takeoff.
 *
 * One render, one OCR pass, one line scan per file. Results are cached on the instance.
 */
class RasterPageReader
{
    private const SCALE_TO = 1200;

    private const WALL_MAX_EDGE = 800;

    private const WALL_STEP = 3;

    private const WALL_INK = 90;

    private const WALL_GRAY = 140;

    private const OCR_TIMEOUT_SECONDS = 15.0;

    private ?string $pdftoppm = null;

    private ?string $tesseract = null;

    private bool $resolved = false;

    /**
     * @var array<string, array{pages: list<array<string, mixed>>, engine: ?string, error: ?string, timings: array<string, float>, ocr_mean_confidence: ?float, ocr_word_count: int, preview_path: ?string}>
     */
    private array $cache = [];

    public function isAvailable(): bool
    {
        try {
            $this->resolveBinaries();
        } catch (\Throwable) {
            return false;
        }

        return $this->pdftoppm !== null && $this->tesseract !== null;
    }

    public function canRender(): bool
    {
        try {
            $this->resolveBinaries();
        } catch (\Throwable) {
            return false;
        }

        return $this->pdftoppm !== null;
    }

    /**
     * @return array{
     *     pages: list<array<string, mixed>>,
     *     engine: ?string,
     *     error: ?string,
     *     timings: array{render: float, ocr: float, walls: float},
     *     ocr_mean_confidence: ?float,
     *     ocr_word_count: int,
     *     preview_path: ?string
     * }
     */
    public function read(string $pdfPath): array
    {
        $emptyTimings = ['render' => 0.0, 'ocr' => 0.0, 'walls' => 0.0];
        $empty = [
            'pages' => [],
            'engine' => null,
            'error' => 'Bestand ontbreekt.',
            'timings' => $emptyTimings,
            'ocr_mean_confidence' => null,
            'ocr_word_count' => 0,
            'preview_path' => null,
        ];
        try {
            return $this->readCached($pdfPath, $empty, $emptyTimings, true);
        } catch (\Throwable $e) {
            report($e);

            return [
                'pages' => [],
                'engine' => null,
                'error' => 'De PDF/OCR-tools konden niet worden gebruikt. Controleer of pdftoppm en Tesseract beschikbaar zijn.',
                'timings' => $emptyTimings,
                'ocr_mean_confidence' => null,
                'ocr_word_count' => 0,
                'preview_path' => null,
            ];
        }
    }

    /**
     * Render the page and detect walls without OCR. Used when a text layer already supplies words.
     *
     * @return array{
     *     pages: list<array<string, mixed>>,
     *     engine: ?string,
     *     error: ?string,
     *     timings: array{render: float, ocr: float, walls: float},
     *     ocr_mean_confidence: ?float,
     *     ocr_word_count: int,
     *     preview_path: ?string
     * }
     */
    public function readGeometry(string $pdfPath): array
    {
        $emptyTimings = ['render' => 0.0, 'ocr' => 0.0, 'walls' => 0.0];
        $empty = [
            'pages' => [],
            'engine' => null,
            'error' => 'Bestand ontbreekt.',
            'timings' => $emptyTimings,
            'ocr_mean_confidence' => null,
            'ocr_word_count' => 0,
            'preview_path' => null,
        ];
        try {
            return $this->readCached($pdfPath, $empty, $emptyTimings, false);
        } catch (\Throwable $e) {
            report($e);

            return [
                'pages' => [],
                'engine' => null,
                'error' => 'De pagina kon niet worden gerenderd voor wanddetectie. Controleer of pdftoppm beschikbaar is.',
                'timings' => $emptyTimings,
                'ocr_mean_confidence' => null,
                'ocr_word_count' => 0,
                'preview_path' => null,
            ];
        }
    }

    /**
     * @param  array{pages: list<array<string, mixed>>, engine: ?string, error: ?string, timings: array{render: float, ocr: float, walls: float}, ocr_mean_confidence: ?float, ocr_word_count: int, preview_path: ?string}  $empty
     * @param  array{render: float, ocr: float, walls: float}  $emptyTimings
     * @return array{pages: list<array<string, mixed>>, engine: ?string, error: ?string, timings: array{render: float, ocr: float, walls: float}, ocr_mean_confidence: ?float, ocr_word_count: int, preview_path: ?string}
     */
    private function readCached(string $pdfPath, array $empty, array $emptyTimings, bool $withOcr): array
    {
        if (! is_file($pdfPath)) {
            return $empty;
        }

        $cacheKey = (hash_file('sha256', $pdfPath) ?: '').'|'.filesize($pdfPath).'|'.($withOcr ? 'ocr' : 'geom');
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        if ($withOcr && ! $this->isAvailable()) {
            $result = [
                'pages' => [],
                'engine' => null,
                'error' => $this->missingToolsMessage(),
                'timings' => $emptyTimings,
                'ocr_mean_confidence' => null,
                'ocr_word_count' => 0,
                'preview_path' => null,
            ];
            $this->cache[$cacheKey] = $result;

            return $result;
        }

        if (! $withOcr && ! $this->canRender()) {
            $result = [
                'pages' => [],
                'engine' => null,
                'error' => 'pdftoppm ontbreekt. Installeer deze tool om wandgeometrie te lezen.',
                'timings' => $emptyTimings,
                'ocr_mean_confidence' => null,
                'ocr_word_count' => 0,
                'preview_path' => null,
            ];
            $this->cache[$cacheKey] = $result;

            return $result;
        }

        $engine = $withOcr ? 'tesseract' : null;
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-area-ocr-'.bin2hex(random_bytes(8));
        if (! mkdir($dir) && ! is_dir($dir)) {
            $result = [
                'pages' => [],
                'engine' => $engine,
                'error' => 'Tijdelijke map kon niet worden gemaakt.',
                'timings' => $emptyTimings,
                'ocr_mean_confidence' => null,
                'ocr_word_count' => 0,
                'preview_path' => null,
            ];
            $this->cache[$cacheKey] = $result;

            return $result;
        }

        try {
            $prefix = $dir.DIRECTORY_SEPARATOR.'page';
            $renderStarted = hrtime(true);
            $command = escapeshellarg((string) $this->pdftoppm)
                .' -png -scale-to '.self::SCALE_TO.' -f 1 -l 1 '
                .escapeshellarg($pdfPath).' '
                .escapeshellarg($prefix).' '
                .$this->stderr();
            exec($command, $_, $code);
            $renderSeconds = $this->secondsSince($renderStarted);
            $images = glob($prefix.'-*.png') ?: [];
            natsort($images);
            $image = array_values($images)[0] ?? null;
            if ($code !== 0 || ! is_string($image) || ! is_file($image)) {
                $result = [
                    'pages' => [],
                    'engine' => $engine,
                    'error' => 'De pagina kon niet naar een afbeelding worden gerenderd.',
                    'timings' => ['render' => $renderSeconds, 'ocr' => 0.0, 'walls' => 0.0],
                    'ocr_mean_confidence' => null,
                    'ocr_word_count' => 0,
                    'preview_path' => null,
                ];
                $this->cache[$cacheKey] = $result;

                return $result;
            }

            $ocr = ['words' => [], 'mean_confidence' => null];
            $ocrSeconds = 0.0;
            if ($withOcr) {
                $ocrStarted = hrtime(true);
                $ocr = $this->ocrWords($image);
                $ocrSeconds = $this->secondsSince($ocrStarted);
            }

            $size = getimagesize($image);
            $width = (float) ($size[0] ?? 1);
            $height = (float) ($size[1] ?? 1);

            $wallsStarted = hrtime(true);
            $detected = $this->detectWalls($image, $width, $height);
            $wallsSeconds = $this->secondsSince($wallsStarted);

            $previewPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-area-preview-'.bin2hex(random_bytes(8)).'.png';
            $copied = @copy($image, $previewPath);
            $ocrError = $withOcr && $ocr['words'] === []
                ? 'Tesseract vond geen leesbare tekst op de afbeelding.'
                : null;
            $result = [
                'pages' => [[
                    'page' => 1,
                    'width' => $width,
                    'height' => $height,
                    'texts' => $ocr['words'],
                    'fills' => [],
                    'walls' => $detected['walls'],
                    'ticks' => $detected['ticks'],
                    'raw_walls' => $detected['raw_walls'],
                    'wall_extract' => $detected['wall_extract'],
                ]],
                'engine' => $engine,
                'error' => $ocrError,
                'timings' => [
                    'render' => $renderSeconds,
                    'ocr' => $ocrSeconds,
                    'walls' => $wallsSeconds,
                ],
                'ocr_mean_confidence' => $ocr['mean_confidence'],
                'ocr_word_count' => count($ocr['words']),
                'preview_path' => $copied ? $previewPath : null,
            ];
            $this->cache[$cacheKey] = $result;

            return $result;
        } finally {
            $this->cleanup($dir);
        }
    }

    /**
     * @return array{words: list<array{text: string, x: float, y: float, page: int, confidence: float}>, mean_confidence: ?float}
     */
    private function ocrWords(string $image): array
    {
        $outBase = dirname($image).DIRECTORY_SEPARATOR.'ocr';
        $ok = $this->runTesseract($image, $outBase, 'eng');
        $tsv = $outBase.'.tsv';
        if (! $ok || ! is_file($tsv)) {
            return ['words' => [], 'mean_confidence' => null];
        }

        $size = getimagesize($image);
        $height = (float) ($size[1] ?? 1);
        $words = [];
        $confidences = [];
        $handle = fopen($tsv, 'r');
        if ($handle === false) {
            return ['words' => [], 'mean_confidence' => null];
        }
        $header = fgetcsv($handle, 0, "\t");
        unset($header);
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            if (count($row) < 12 || (int) $row[0] !== 5) {
                continue;
            }
            $text = trim((string) $row[11]);
            $conf = (float) $row[10];
            if ($text === '' || $conf < 35) {
                continue;
            }
            $left = (float) $row[6];
            $top = (float) $row[7];
            $width = (float) $row[8];
            $wordHeight = (float) $row[9];
            $words[] = [
                'text' => $text,
                'x' => $left + ($width / 2),
                'y' => $height - ($top + ($wordHeight / 2)), // PDF-up, matches wall Y
                'page' => 1,
                'confidence' => $conf,
                'height' => $wordHeight,
                'width' => $width,
            ];
            $confidences[] = $conf;
        }
        fclose($handle);

        $stitched = $this->stitchWords($words);
        $mean = $confidences === [] ? null : array_sum($confidences) / count($confidences);

        return ['words' => $stitched, 'mean_confidence' => $mean === null ? null : round($mean, 1)];
    }

    /**
     * @param  list<array{text: string, x: float, y: float, page: int, confidence: float, height?: float, width?: float}>  $words
     * @return list<array{text: string, x: float, y: float, page: int, confidence: float}>
     */
    private function stitchWords(array $words): array
    {
        usort($words, function (array $a, array $b): int {
            $y = $b['y'] <=> $a['y'];
            if ($y !== 0) {
                return $y;
            }

            return $a['x'] <=> $b['x'];
        });

        $stitched = [];
        $count = count($words);
        $i = 0;
        while ($i < $count) {
            $current = $words[$i];
            $j = $i + 1;
            while ($j < $count && $this->shouldJoin($current, $words[$j])) {
                $next = $words[$j];
                $current['text'] = trim($current['text'].' '.$next['text']);
                $current['x'] = ($current['x'] + $next['x']) / 2;
                $current['confidence'] = min((float) $current['confidence'], (float) $next['confidence']);
                $j++;
            }
            unset($current['height'], $current['width']);
            $stitched[] = $current;
            $i = $j;
        }

        return $stitched;
    }

    /**
     * @param  array{text: string, x: float, y: float, height?: float, width?: float}  $left
     * @param  array{text: string, x: float, y: float, height?: float, width?: float}  $right
     */
    private function shouldJoin(array $left, array $right): bool
    {
        $height = max(8.0, (float) ($left['height'] ?? 12));
        if (abs((float) $left['y'] - (float) $right['y']) > $height * 0.7) {
            return false;
        }
        $leftEdge = (float) $left['x'] + ((float) ($left['width'] ?? $height) / 2);
        $gap = (float) $right['x'] - $leftEdge;
        if ($gap < -4 || $gap > $height * 1.8) {
            return false;
        }

        $leftText = trim((string) $left['text']);
        $rightText = trim((string) $right['text']);
        if (preg_match('/^\d{1,2}$/u', $rightText) && preg_match('/[A-Za-zÀ-ÿ]{3,}/u', $leftText)) {
            return true;
        }

        return (bool) preg_match('/^\d{1,4}(?:[.,]\d+)?$/u', $leftText)
            && (bool) preg_match('/^m(?:²|2)$/iu', $rightText);
    }

    private function runTesseract(string $image, string $outBase, string $lang): bool
    {
        $command = escapeshellarg((string) $this->tesseract)
            .' '.escapeshellarg($image)
            .' '.escapeshellarg($outBase)
            .' -l '.escapeshellarg($lang)
            .' --psm 6 tsv '
            .$this->stderr();

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            return false;
        }

        $started = microtime(true);
        do {
            $status = proc_get_status($process);
            if (! ($status['running'] ?? false)) {
                break;
            }
            if ((microtime(true) - $started) > self::OCR_TIMEOUT_SECONDS) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);

                return false;
            }
            usleep(40000);
        } while (true);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $code = proc_close($process);

        return $code === 0 && is_file($outBase.'.tsv');
    }

    /**
     * Trace H/V ink on a PNG without OCR. Isolated for unit tests of wall extraction.
     *
     * @return array{
     *     walls: list<array<string, mixed>>,
     *     ticks: list<array<string, mixed>>,
     *     raw_walls: list<array<string, mixed>>,
     *     wall_extract: array<string, mixed>,
     *     width: float,
     *     height: float
     * }
     */
    public function tracePng(string $imagePath): array
    {
        $size = @getimagesize($imagePath);
        $width = (float) ($size[0] ?? 1);
        $height = (float) ($size[1] ?? 1);
        $detected = $this->detectWalls($imagePath, $width, $height);

        return $detected + ['width' => $width, 'height' => $height];
    }

    /**
     * @return array{
     *     walls: list<array<string, mixed>>,
     *     ticks: list<array<string, mixed>>,
     *     raw_walls: list<array<string, mixed>>,
     *     wall_extract: array<string, mixed>
     * }
     */
    private function detectWalls(string $imagePath, float $width, float $height): array
    {
        $empty = [
            'walls' => [],
            'ticks' => [],
            'raw_walls' => [],
            'wall_extract' => ['bands_h' => [], 'bands_v' => [], 'axes' => [], 'vertical_candidates' => [], 'horizontal_candidates' => []],
        ];
        $image = @imagecreatefrompng($imagePath);
        if ($image === false) {
            return $empty;
        }

        $srcW = imagesx($image);
        $srcH = imagesy($image);
        $scale = min(1.0, self::WALL_MAX_EDGE / max($srcW, $srcH));
        $w = max(1, (int) round($srcW * $scale));
        $h = max(1, (int) round($srcH * $scale));
        if ($scale < 0.999) {
            $small = imagecreatetruecolor($w, $h);
            if ($small === false) {
                imagedestroy($image);

                return $empty;
            }
            imagecopyresampled($small, $image, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
            imagedestroy($image);
            $image = $small;
        }

        $minH = max(18, (int) round($w * 0.04));
        $minV = max(18, (int) round($h * 0.04));
        $minTick = 8;
        $walls = [];
        $ticks = [];
        $horizontal = $this->detectHorizontalRows($image, $w, $h, $scale, $srcH, $minH, $minTick);
        array_push($walls, ...$horizontal['walls']);
        array_push($ticks, ...$horizontal['ticks']);
        $vertical = $this->detectVerticalColumns($image, $w, $h, $scale, $srcH, $minV, $minTick);
        array_push($walls, ...$vertical['walls']);
        array_push($ticks, ...$vertical['ticks']);

        $bands = $this->detectDarkBands($image, $w, $h, $scale, $srcH, $minH, $minV);
        imagedestroy($image);

        $raw = $this->mergeWalls($walls);
        $mergedTicks = $this->mergeWalls($ticks, 12);
        $assembled = (new WallAxisAssembler)->assemble(
            array_merge($raw, $bands),
            $width > 1 ? $width : $srcW,
            $height > 1 ? $height : $srcH,
        );

        unset($width, $height);

        return [
            'walls' => $assembled['walls'],
            'ticks' => $mergedTicks,
            'raw_walls' => $raw,
            'wall_extract' => [
                'bands_h' => $assembled['bands_h'],
                'bands_v' => $assembled['bands_v'],
                'axes' => $assembled['axes'],
                'vertical_candidates' => $vertical['candidates'],
                'horizontal_candidates' => $horizontal['candidates'],
            ],
        ];
    }

    /**
     * @param  \GdImage  $image
     */
    private function isInk($image, int $x, int $y): bool
    {
        $rgb = imagecolorat($image, $x, $y);
        $r = ($rgb >> 16) & 255;
        $g = ($rgb >> 8) & 255;
        $b = $rgb & 255;

        return (($r + $g + $b) / 3) < self::WALL_INK;
    }

    /**
     * Scan every column so a 1-pixel facade that falls between WALL_STEP samples is not dropped.
     * Interrupted ink at the same X is joined; nearby parallel edges become one cluster axis.
     *
     * @param  \GdImage  $image
     * @return array{walls: list<array<string, mixed>>, ticks: list<array<string, mixed>>, candidates: list<array<string, mixed>>}
     */
    private function detectVerticalColumns($image, int $w, int $h, float $scale, int $srcH, int $minV, int $minTick): array
    {
        $doorGap = max(16, (int) round($h * 0.12));
        $clusterWidth = max(8, (int) round(36 * $scale));
        $minCoverage = 0.35;
        $profiles = [];
        $ticks = [];
        $candidates = [];

        for ($col = 0; $col < $w; $col++) {
            $runs = $this->columnRuns($image, $col, $h);
            $darkMerged = $this->mergeColumnRuns($runs['dark'], $doorGap);
            $grayMerged = $this->mergeColumnRuns($runs['gray'], $doorGap);
            $bestDark = $this->longestRun($darkMerged);
            $bestGray = $this->longestRun($grayMerged);
            $x = $col / $scale;
            $widthPx = 1.0 / $scale;

            if ($bestDark !== null && $this->runAccepted($bestDark, $minV, $minCoverage)) {
                $profiles[] = $this->columnProfile($col, $x, $bestDark, $scale, $srcH, true);
                $candidates[] = $this->candidateRow($x, $bestDark, $scale, $srcH, $widthPx, 'accepted', 'donkere kolom');

                continue;
            }
            if ($bestDark !== null && ($bestDark['end'] - $bestDark['start'] + 1) >= $minV) {
                $profiles[] = $this->columnProfile($col, $x, $bestDark, $scale, $srcH, false);
                $candidates[] = $this->candidateRow($x, $bestDark, $scale, $srcH, $widthPx, 'rejected', 'te onderbroken');
                $this->appendTickFromRun($ticks, $col, $bestDark, $minTick, $scale, $srcH);

                continue;
            }
            if ($bestGray !== null && $this->runAccepted($bestGray, $minV, $minCoverage)) {
                $profiles[] = $this->columnProfile($col, $x, $bestGray, $scale, $srcH, false);
                $candidates[] = $this->candidateRow($x, $bestGray, $scale, $srcH, $widthPx, 'rejected', 'niet voldoende donker');

                continue;
            }
            if ($bestDark !== null && ($bestDark['end'] - $bestDark['start'] + 1) >= $minTick) {
                $candidates[] = $this->candidateRow($x, $bestDark, $scale, $srcH, $widthPx, 'rejected', 'te kort');
                $this->appendTickFromRun($ticks, $col, $bestDark, $minTick, $scale, $srcH);
            }
        }

        return [
            'walls' => $this->clusterVerticalProfiles($profiles, $clusterWidth, $scale, $srcH),
            'ticks' => $ticks,
            'candidates' => $candidates,
        ];
    }

    /**
     * Scan every row, matching the vertical column detector: interrupted ink on the same Y
     * is joined, and nearby parallel edges become one horizontal wall axis.
     *
     * @param  \GdImage  $image
     * @return array{walls: list<array<string, mixed>>, ticks: list<array<string, mixed>>, candidates: list<array<string, mixed>>}
     */
    private function detectHorizontalRows($image, int $w, int $h, float $scale, int $srcH, int $minH, int $minTick): array
    {
        $doorGap = max(16, (int) round($w * 0.28));
        $clusterWidth = max(8, (int) round(36 * $scale));
        $minCoverage = 0.35;
        $profiles = [];
        $ticks = [];
        $candidates = [];

        for ($row = 0; $row < $h; $row++) {
            $runs = $this->rowRuns($image, $row, $w);
            $runs['dark'] = $this->dropTinyRuns($runs['dark'], $minTick);
            $runs['gray'] = $this->dropTinyRuns($runs['gray'], $minTick);
            $darkMerged = $this->mergeColumnRuns($runs['dark'], $doorGap);
            $grayMerged = $this->mergeColumnRuns($runs['gray'], $doorGap);
            $bestDark = $this->joinRowAxis($darkMerged, $minH, $minCoverage);
            $bestGray = $this->joinRowAxis($grayMerged, $minH, $minCoverage);
            $y = $srcH - ($row / $scale);
            $widthPx = 1.0 / $scale;

            if ($bestDark !== null && $this->runAccepted($bestDark, $minH, $minCoverage)) {
                $profiles[] = $this->rowProfile($row, $y, $bestDark, $scale, true);
                $candidates[] = $this->horizontalCandidate($y, $bestDark, $scale, $widthPx, 'accepted', 'donkere rij');

                continue;
            }
            if ($bestDark !== null && ($bestDark['end'] - $bestDark['start'] + 1) >= $minH) {
                $profiles[] = $this->rowProfile($row, $y, $bestDark, $scale, false);
                $candidates[] = $this->horizontalCandidate($y, $bestDark, $scale, $widthPx, 'rejected', 'te onderbroken');
                $this->appendHorizontalTick($ticks, $bestDark, $row, $minTick, $scale, $srcH);

                continue;
            }
            if ($bestGray !== null && $this->runAccepted($bestGray, $minH, $minCoverage)) {
                $profiles[] = $this->rowProfile($row, $y, $bestGray, $scale, true);
                $candidates[] = $this->horizontalCandidate($y, $bestGray, $scale, $widthPx, 'accepted', 'grijze rij');

                continue;
            }
            if ($bestDark !== null && ($bestDark['end'] - $bestDark['start'] + 1) >= $minTick) {
                $candidates[] = $this->horizontalCandidate($y, $bestDark, $scale, $widthPx, 'rejected', 'te kort');
                $this->appendHorizontalTick($ticks, $bestDark, $row, $minTick, $scale, $srcH);
            }
        }

        return [
            'walls' => $this->clusterHorizontalProfiles($profiles, $clusterWidth, $doorGap, $scale, $srcH),
            'ticks' => $ticks,
            'candidates' => $candidates,
        ];
    }

    /**
     * @param  \GdImage  $image
     * @return array{dark: list<array{start: int, end: int}>, gray: list<array{start: int, end: int}>}
     */
    private function rowRuns($image, int $row, int $w): array
    {
        $dark = [];
        $gray = [];
        $darkRun = 0;
        $grayRun = 0;
        $darkStart = 0;
        $grayStart = 0;
        for ($col = 0; $col <= $w; $col++) {
            $brightness = $col < $w ? $this->pixelBrightness($image, $col, $row) : 255.0;
            $isDark = $brightness < self::WALL_INK;
            $isGray = $brightness < self::WALL_GRAY;
            if ($isDark) {
                if ($darkRun === 0) {
                    $darkStart = $col;
                }
                $darkRun++;
            } elseif ($darkRun > 0) {
                $dark[] = ['start' => $darkStart, 'end' => $col - 1];
                $darkRun = 0;
            }
            if ($isGray) {
                if ($grayRun === 0) {
                    $grayStart = $col;
                }
                $grayRun++;
            } elseif ($grayRun > 0) {
                $gray[] = ['start' => $grayStart, 'end' => $col - 1];
                $grayRun = 0;
            }
        }

        return ['dark' => $dark, 'gray' => $gray];
    }

    /**
     * Text glyphs are 1–6 px runs; architectural ink is longer. Drop the glyphs before gap-merging.
     *
     * @param  list<array{start: int, end: int}>  $runs
     * @return list<array{start: int, end: int}>
     */
    private function dropTinyRuns(array $runs, int $minLength): array
    {
        return array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['end'] - $run['start'] + 1) >= $minLength,
        ));
    }

    /**
     * @param  array{start: int, end: int, ink: int}  $run
     * @return array{row: int, y: float, x1: float, x2: float, start: int, end: int, ink: bool}
     */
    private function rowProfile(int $row, float $y, array $run, float $scale, bool $ink): array
    {
        return [
            'row' => $row,
            'y' => $y,
            'x1' => $run['start'] / $scale,
            'x2' => $run['end'] / $scale,
            'start' => $run['start'],
            'end' => $run['end'],
            'ink' => $ink,
        ];
    }

    /**
     * @param  array{start: int, end: int, ink?: int}  $run
     * @return array{y: float, x1: float, x2: float, width: float, decision: string, reason: string}
     */
    private function horizontalCandidate(float $y, array $run, float $scale, float $width, string $decision, string $reason): array
    {
        return [
            'y' => $y,
            'x1' => $run['start'] / $scale,
            'x2' => $run['end'] / $scale,
            'width' => $width,
            'decision' => $decision,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ticks
     * @param  array{start: int, end: int, ink?: int}  $run
     */
    private function appendHorizontalTick(array &$ticks, array $run, int $row, int $minTick, float $scale, int $srcH): void
    {
        if (($run['end'] - $run['start'] + 1) >= $minTick) {
            $ticks[] = $this->hWall($run['start'], $run['end'], $row, $scale, $srcH);
        }
    }

    /**
     * @param  list<array{row: int, y: float, x1: float, x2: float, start: int, end: int, ink: bool}>  $profiles
     * @return list<array<string, mixed>>
     */
    private function clusterHorizontalProfiles(array $profiles, int $clusterWidth, int $joinGap, float $scale, int $srcH): array
    {
        $dark = array_values(array_filter($profiles, fn (array $profile): bool => $profile['ink']));
        if ($dark === []) {
            return [];
        }
        usort($dark, fn (array $a, array $b): int => $a['row'] <=> $b['row']);
        $used = [];
        $walls = [];
        $count = count($dark);
        for ($i = 0; $i < $count; $i++) {
            if (isset($used[$i])) {
                continue;
            }
            $seedRow = $dark[$i]['row'];
            $members = [$dark[$i]];
            $used[$i] = true;
            $minRow = $seedRow;
            $maxRow = $seedRow;
            for ($j = $i + 1; $j < $count; $j++) {
                if (isset($used[$j])) {
                    continue;
                }
                if ($dark[$j]['row'] - $seedRow > $clusterWidth) {
                    break;
                }
                if (max($maxRow, $dark[$j]['row']) - min($minRow, $dark[$j]['row']) > $clusterWidth) {
                    continue;
                }
                if (! $this->horizontalProfilesJoin($dark[$i], $dark[$j], $joinGap)) {
                    continue;
                }
                $used[$j] = true;
                $members[] = $dark[$j];
                $minRow = min($minRow, $dark[$j]['row']);
                $maxRow = max($maxRow, $dark[$j]['row']);
            }
            foreach ($profiles as $profile) {
                if ($profile['ink']) {
                    continue;
                }
                if (abs($profile['row'] - $seedRow) > $clusterWidth) {
                    continue;
                }
                if (max($maxRow, $profile['row']) - min($minRow, $profile['row']) > $clusterWidth) {
                    continue;
                }
                if (! $this->horizontalProfilesJoin($dark[$i], $profile, $joinGap)) {
                    continue;
                }
                $members[] = $profile;
                $minRow = min($minRow, $profile['row']);
                $maxRow = max($maxRow, $profile['row']);
            }
            $midRow = ($minRow + $maxRow) / 2;
            $xStart = min(array_map(fn (array $member): int => $member['start'], $members));
            $xEnd = max(array_map(fn (array $member): int => $member['end'], $members));
            $kind = WallAxisAssembler::KIND_LINE;
            if (count($members) >= 3) {
                $kind = WallAxisAssembler::KIND_CLUSTER;
            } elseif (count($members) === 2) {
                $kind = WallAxisAssembler::KIND_PAIR;
            }
            $wall = $this->hWall($xStart, $xEnd, (int) round($midRow), $scale, $srcH, $kind);
            $wall['y1'] = $srcH - ($midRow / $scale);
            $wall['y2'] = $wall['y1'];
            $wall['thickness'] = max(1.0, ($maxRow - $minRow + 1) / $scale);
            $walls[] = $wall;
        }

        return $walls;
    }

    /**
     * @param  \GdImage  $image
     * @return array{dark: list<array{start: int, end: int}>, gray: list<array{start: int, end: int}>}
     */
    private function columnRuns($image, int $col, int $h): array
    {
        $dark = [];
        $gray = [];
        $darkRun = 0;
        $grayRun = 0;
        $darkStart = 0;
        $grayStart = 0;
        for ($row = 0; $row <= $h; $row++) {
            $brightness = $row < $h ? $this->pixelBrightness($image, $col, $row) : 255.0;
            $isDark = $brightness < self::WALL_INK;
            $isGray = $brightness < self::WALL_GRAY;
            if ($isDark) {
                if ($darkRun === 0) {
                    $darkStart = $row;
                }
                $darkRun++;
            } elseif ($darkRun > 0) {
                $dark[] = ['start' => $darkStart, 'end' => $row - 1];
                $darkRun = 0;
            }
            if ($isGray) {
                if ($grayRun === 0) {
                    $grayStart = $row;
                }
                $grayRun++;
            } elseif ($grayRun > 0) {
                $gray[] = ['start' => $grayStart, 'end' => $row - 1];
                $grayRun = 0;
            }
        }

        return ['dark' => $dark, 'gray' => $gray];
    }

    /**
     * @param  list<array{start: int, end: int}>  $runs
     * @return list<array{start: int, end: int, ink: int}>
     */
    private function mergeColumnRuns(array $runs, int $maxGap): array
    {
        if ($runs === []) {
            return [];
        }
        $merged = [];
        $current = [
            'start' => $runs[0]['start'],
            'end' => $runs[0]['end'],
            'ink' => $runs[0]['end'] - $runs[0]['start'] + 1,
        ];
        $count = count($runs);
        for ($i = 1; $i < $count; $i++) {
            $gap = $runs[$i]['start'] - $current['end'] - 1;
            $length = $runs[$i]['end'] - $runs[$i]['start'] + 1;
            if ($gap <= $maxGap) {
                $current['end'] = $runs[$i]['end'];
                $current['ink'] += $length;

                continue;
            }
            $merged[] = $current;
            $current = [
                'start' => $runs[$i]['start'],
                'end' => $runs[$i]['end'],
                'ink' => $length,
            ];
        }
        $merged[] = $current;

        return $merged;
    }

    /**
     * @param  list<array{start: int, end: int, ink: int}>  $runs
     * @return array{start: int, end: int, ink: int}|null
     */
    private function longestRun(array $runs): ?array
    {
        $best = null;
        foreach ($runs as $run) {
            $span = $run['end'] - $run['start'] + 1;
            $bestSpan = $best === null ? -1 : ($best['end'] - $best['start'] + 1);
            if ($span > $bestSpan) {
                $best = $run;
            }
        }

        return $best;
    }

    /**
     * Multiple wall-sized pieces on one row become one axis, including window-sized gaps.
     *
     * @param  list<array{start: int, end: int, ink: int}>  $runs
     * @return array{start: int, end: int, ink: int}|null
     */
    private function joinRowAxis(array $runs, int $minH, float $minCoverage): ?array
    {
        $chunks = array_values(array_filter(
            $runs,
            fn (array $run): bool => ($run['end'] - $run['start'] + 1) >= $minH,
        ));
        if ($chunks === []) {
            return $this->longestRun($runs);
        }
        if (count($chunks) === 1) {
            return $chunks[0];
        }

        $start = min(array_map(fn (array $chunk): int => $chunk['start'], $chunks));
        $end = max(array_map(fn (array $chunk): int => $chunk['end'], $chunks));
        $ink = 0;
        foreach ($chunks as $chunk) {
            $ink += $chunk['ink'] ?? ($chunk['end'] - $chunk['start'] + 1);
        }
        $union = ['start' => $start, 'end' => $end, 'ink' => $ink];
        if ($this->runAccepted($union, $minH, $minCoverage * 0.8)) {
            return $union;
        }

        return $this->longestRun($chunks);
    }

    /**
     * @param  array{start: int, end: int, ink: int}  $run
     */
    private function runAccepted(array $run, int $minV, float $minCoverage): bool
    {
        $span = $run['end'] - $run['start'] + 1;
        if ($span < $minV) {
            return false;
        }

        return ($run['ink'] / $span) >= $minCoverage;
    }

    /**
     * @param  array{start: int, end: int, ink: int}  $run
     * @return array{col: int, x: float, y1: float, y2: float, start: int, end: int, ink: bool}
     */
    private function columnProfile(int $col, float $x, array $run, float $scale, int $srcH, bool $ink): array
    {
        return [
            'col' => $col,
            'x' => $x,
            'y1' => $srcH - ($run['end'] / $scale),
            'y2' => $srcH - ($run['start'] / $scale),
            'start' => $run['start'],
            'end' => $run['end'],
            'ink' => $ink,
        ];
    }

    /**
     * @param  array{start: int, end: int, ink?: int}  $run
     * @return array{x: float, y1: float, y2: float, width: float, decision: string, reason: string}
     */
    private function candidateRow(float $x, array $run, float $scale, int $srcH, float $width, string $decision, string $reason): array
    {
        return [
            'x' => $x,
            'y1' => $srcH - ($run['end'] / $scale),
            'y2' => $srcH - ($run['start'] / $scale),
            'width' => $width,
            'decision' => $decision,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ticks
     * @param  array{start: int, end: int, ink?: int}  $run
     */
    private function appendTickFromRun(array &$ticks, int $col, array $run, int $minTick, float $scale, int $srcH): void
    {
        if (($run['end'] - $run['start'] + 1) >= $minTick) {
            $ticks[] = $this->vWall($col, $run['start'], $run['end'], $scale, $srcH);
        }
    }

    /**
     * @param  list<array{col: int, x: float, y1: float, y2: float, start: int, end: int, ink: bool}>  $profiles
     * @return list<array<string, mixed>>
     */
    private function clusterVerticalProfiles(array $profiles, int $clusterWidth, float $scale, int $srcH): array
    {
        $dark = array_values(array_filter($profiles, fn (array $profile): bool => $profile['ink']));
        if ($dark === []) {
            return [];
        }
        usort($dark, fn (array $a, array $b): int => $a['col'] <=> $b['col']);
        $used = [];
        $walls = [];
        $count = count($dark);
        for ($i = 0; $i < $count; $i++) {
            if (isset($used[$i])) {
                continue;
            }
            $seedCol = $dark[$i]['col'];
            $members = [$dark[$i]];
            $used[$i] = true;
            $minCol = $seedCol;
            $maxCol = $seedCol;
            for ($j = $i + 1; $j < $count; $j++) {
                if (isset($used[$j])) {
                    continue;
                }
                if ($dark[$j]['col'] - $seedCol > $clusterWidth) {
                    break;
                }
                if (max($maxCol, $dark[$j]['col']) - min($minCol, $dark[$j]['col']) > $clusterWidth) {
                    continue;
                }
                if (! $this->profilesOverlap($dark[$i], $dark[$j])) {
                    continue;
                }
                $used[$j] = true;
                $members[] = $dark[$j];
                $minCol = min($minCol, $dark[$j]['col']);
                $maxCol = max($maxCol, $dark[$j]['col']);
            }
            foreach ($profiles as $profile) {
                if ($profile['ink']) {
                    continue;
                }
                if (abs($profile['col'] - $seedCol) > $clusterWidth) {
                    continue;
                }
                if (max($maxCol, $profile['col']) - min($minCol, $profile['col']) > $clusterWidth) {
                    continue;
                }
                if (! $this->profilesOverlap($dark[$i], $profile)) {
                    continue;
                }
                $members[] = $profile;
                $minCol = min($minCol, $profile['col']);
                $maxCol = max($maxCol, $profile['col']);
            }
            $midCol = ($minCol + $maxCol) / 2;
            $yStart = min(array_map(fn (array $member): int => $member['start'], $members));
            $yEnd = max(array_map(fn (array $member): int => $member['end'], $members));
            $kind = WallAxisAssembler::KIND_LINE;
            if (count($members) >= 3) {
                $kind = WallAxisAssembler::KIND_CLUSTER;
            } elseif (count($members) === 2) {
                $kind = WallAxisAssembler::KIND_PAIR;
            }
            $wall = $this->vWall((int) round($midCol), $yStart, $yEnd, $scale, $srcH, $kind);
            $wall['x1'] = $midCol / $scale;
            $wall['x2'] = $midCol / $scale;
            $wall['thickness'] = max(1.0, ($maxCol - $minCol + 1) / $scale);
            $walls[] = $wall;
        }

        return $walls;
    }

    /**
     * @param  array{start: int, end: int}  $a
     * @param  array{start: int, end: int}  $b
     */
    private function profilesOverlap(array $a, array $b): bool
    {
        $overlap = min($a['end'], $b['end']) - max($a['start'], $b['start']);

        return $overlap >= 8;
    }

    /**
     * Nearby parallel edges join when they overlap, or when a door/window gap sits between them.
     *
     * @param  array{start: int, end: int}  $a
     * @param  array{start: int, end: int}  $b
     */
    private function horizontalProfilesJoin(array $a, array $b, int $joinGap): bool
    {
        if ($this->profilesOverlap($a, $b)) {
            return true;
        }

        $gap = max($a['start'], $b['start']) - min($a['end'], $b['end']) - 1;

        return $gap >= 0 && $gap <= $joinGap;
    }

    /**
     * @param  \GdImage  $image
     */
    private function pixelBrightness($image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);
        $r = ($rgb >> 16) & 255;
        $g = ($rgb >> 8) & 255;
        $b = $rgb & 255;

        return ($r + $g + $b) / 3;
    }

    /**
     * Long dark bands of wall thickness, separate from thin 1-pixel traces.
     *
     * @param  \GdImage  $image
     * @return list<array<string, mixed>>
     */
    private function detectDarkBands($image, int $w, int $h, float $scale, int $srcH, int $minH, int $minV): array
    {
        $minThickness = 8;
        $maxThickness = max(18, (int) round(min($w, $h) * 0.045));
        $vHits = [];
        for ($row = 0; $row < $h; $row += 2) {
            $run = 0;
            $start = 0;
            for ($col = 0; $col <= $w; $col++) {
                $ink = $col < $w && $this->isInk($image, $col, $row);
                if ($ink) {
                    if ($run === 0) {
                        $start = $col;
                    }
                    $run++;
                } else {
                    if ($run >= $minThickness && $run <= $maxThickness) {
                        $vHits[] = ['along' => ($start + $col - 1) / 2, 'perp' => $row, 'thickness' => $run];
                    }
                    $run = 0;
                }
            }
        }
        $hHits = [];
        for ($col = 0; $col < $w; $col += 2) {
            $run = 0;
            $start = 0;
            for ($row = 0; $row <= $h; $row++) {
                $ink = $row < $h && $this->isInk($image, $col, $row);
                if ($ink) {
                    if ($run === 0) {
                        $start = $row;
                    }
                    $run++;
                } else {
                    if ($run >= $minThickness && $run <= $maxThickness) {
                        $hHits[] = ['along' => ($start + $row - 1) / 2, 'perp' => $col, 'thickness' => $run];
                    }
                    $run = 0;
                }
            }
        }

        return array_merge(
            $this->clusterBandHits($vHits, 'v', $scale, $srcH, $minV),
            $this->clusterBandHits($hHits, 'h', $scale, $srcH, $minH),
        );
    }

    /**
     * @param  list<array{along: float, perp: float, thickness: int}>  $hits
     * @return list<array<string, mixed>>
     */
    private function clusterBandHits(array $hits, string $axis, float $scale, int $srcH, int $minLength): array
    {
        if ($hits === []) {
            return [];
        }
        usort($hits, function (array $a, array $b): int {
            $along = $a['along'] <=> $b['along'];

            return $along !== 0 ? $along : $a['perp'] <=> $b['perp'];
        });

        $groups = [];
        foreach ($hits as $hit) {
            $attached = false;
            foreach ($groups as &$group) {
                if (abs($group['along'] - $hit['along']) > 4) {
                    continue;
                }
                if ($hit['perp'] - $group['perp_hi'] > 8) {
                    continue;
                }
                $n = $group['count'];
                $group['along'] = (($group['along'] * $n) + $hit['along']) / ($n + 1);
                $group['perp_lo'] = min($group['perp_lo'], $hit['perp']);
                $group['perp_hi'] = max($group['perp_hi'], $hit['perp']);
                $group['thickness'] = max($group['thickness'], $hit['thickness']);
                $group['count'] = $n + 1;
                $attached = true;
                break;
            }
            unset($group);
            if (! $attached) {
                $groups[] = [
                    'along' => $hit['along'],
                    'perp_lo' => $hit['perp'],
                    'perp_hi' => $hit['perp'],
                    'thickness' => $hit['thickness'],
                    'count' => 1,
                ];
            }
        }

        $bands = [];
        foreach ($groups as $group) {
            $length = ($group['perp_hi'] - $group['perp_lo']) / $scale;
            if ($length < $minLength) {
                continue;
            }
            if ($axis === 'v') {
                $x = $group['along'] / $scale;
                $bands[] = [
                    'x1' => $x,
                    'y1' => $srcH - ($group['perp_hi'] / $scale),
                    'x2' => $x,
                    'y2' => $srcH - ($group['perp_lo'] / $scale),
                    'axis' => 'v',
                    'kind' => WallAxisAssembler::KIND_BAND,
                    'thickness' => $group['thickness'] / $scale,
                ];

                continue;
            }
            $y = $srcH - ($group['along'] / $scale);
            $bands[] = [
                'x1' => $group['perp_lo'] / $scale,
                'y1' => $y,
                'x2' => $group['perp_hi'] / $scale,
                'y2' => $y,
                'axis' => 'h',
                'kind' => WallAxisAssembler::KIND_BAND,
                'thickness' => $group['thickness'] / $scale,
            ];
        }

        return $bands;
    }

    /**
     * Image row (top-left origin) to PDF-up Y, matching OCR.
     *
     * @return array{x1: float, y1: float, x2: float, y2: float, axis: string, kind?: string}
     */
    private function hWall(int $x1, int $x2, int $row, float $scale, int $srcH, string $kind = WallAxisAssembler::KIND_LINE): array
    {
        $y = $srcH - ($row / $scale);

        return [
            'x1' => min($x1, $x2) / $scale,
            'y1' => $y,
            'x2' => max($x1, $x2) / $scale,
            'y2' => $y,
            'axis' => 'h',
            'kind' => $kind,
        ];
    }

    /**
     * @return array{x1: float, y1: float, x2: float, y2: float, axis: string, kind?: string}
     */
    private function vWall(int $col, int $y1, int $y2, float $scale, int $srcH, string $kind = WallAxisAssembler::KIND_LINE): array
    {
        $x = $col / $scale;

        return [
            'x1' => $x,
            'y1' => $srcH - (max($y1, $y2) / $scale),
            'x2' => $x,
            'y2' => $srcH - (min($y1, $y2) / $scale),
            'axis' => 'v',
            'kind' => $kind,
        ];
    }

    /**
     * @param  list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>  $walls
     * @return list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>
     */
    private function mergeWalls(array $walls, float $minLength = 28): array
    {
        $merged = [];
        foreach ($walls as $wall) {
            $kept = false;
            foreach ($merged as &$existing) {
                if ($existing['axis'] !== $wall['axis']) {
                    continue;
                }
                if ($wall['axis'] === 'h' && abs($existing['y1'] - $wall['y1']) <= 4) {
                    $separation = max($existing['x1'], $wall['x1']) - min($existing['x2'], $wall['x2']);
                    if ($separation > 48) {
                        continue;
                    }
                    $existing['x1'] = min($existing['x1'], $wall['x1']);
                    $existing['x2'] = max($existing['x2'], $wall['x2']);
                    $kept = true;
                    break;
                }
                if ($wall['axis'] === 'v' && abs($existing['x1'] - $wall['x1']) <= 4) {
                    $separation = max($existing['y1'], $wall['y1']) - min($existing['y2'], $wall['y2']);
                    if ($separation > 48) {
                        continue;
                    }
                    $existing['y1'] = min($existing['y1'], $wall['y1']);
                    $existing['y2'] = max($existing['y2'], $wall['y2']);
                    $kept = true;
                    break;
                }
            }
            unset($existing);
            if (! $kept) {
                $merged[] = $wall;
            }
        }

        return array_values(array_filter(
            $merged,
            fn (array $wall): bool => $this->wallLength($wall) >= $minLength,
        ));
    }

    /**
     * @param  array{x1: float, y1: float, x2: float, y2: float}  $wall
     */
    private function wallLength(array $wall): float
    {
        return hypot((float) $wall['x2'] - (float) $wall['x1'], (float) $wall['y2'] - (float) $wall['y1']);
    }

    private function secondsSince(int $started): float
    {
        return round((hrtime(true) - $started) / 1e9, 2);
    }

    /**
     * Locate pdftoppm/tesseract without statting paths outside open_basedir.
     *
     * @param  callable(string): ?string|null  $lookup
     * @param  callable(string): bool|null  $localFile
     */
    public static function resolveBinary(
        string $name,
        string $osFamily,
        ?callable $lookup = null,
        ?callable $localFile = null,
        ?string $localAppData = null,
    ): ?string {
        $lookup ??= fn (string $binary): ?string => self::lookupOnPath($binary, $osFamily);

        try {
            $fromPath = $lookup($name);
        } catch (\Throwable) {
            $fromPath = null;
        }

        if (is_string($fromPath) && trim($fromPath) !== '') {
            return trim($fromPath);
        }

        if ($osFamily !== 'Windows') {
            return null;
        }

        $localFile ??= self::isStatableFile(...);
        $localAppData ??= getenv('LOCALAPPDATA') ?: null;

        try {
            foreach (self::windowsCandidates($name, is_string($localAppData) ? $localAppData : null) as $candidate) {
                if ($localFile($candidate)) {
                    return $candidate;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function missingToolsMessage(): string
    {
        $this->resolveBinaries();
        $missing = [];
        if ($this->pdftoppm === null) {
            $missing[] = 'pdftoppm';
        }
        if ($this->tesseract === null) {
            $missing[] = 'Tesseract';
        }
        if ($missing === []) {
            return 'pdftoppm en Tesseract zijn nodig om een PDF zonder tekstlaag te lezen.';
        }
        if (count($missing) === 1) {
            return $missing[0].' ontbreekt. Installeer deze PDF/OCR-tool om een scan zonder tekstlaag te lezen.';
        }

        return 'pdftoppm en Tesseract ontbreken. Installeer deze PDF/OCR-tools om een scan zonder tekstlaag te lezen.';
    }

    private function resolveBinaries(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;
        try {
            $this->pdftoppm = $this->findBinary('pdftoppm');
            $this->tesseract = $this->findBinary('tesseract');
        } catch (\Throwable) {
            $this->pdftoppm = null;
            $this->tesseract = null;
        }
    }

    protected function findBinary(string $name): ?string
    {
        return self::resolveBinary($name, PHP_OS_FAMILY);
    }

    /**
     * command -v / where already prove the binary exists. Do not is_file() the
     * result: on Plesk, /usr/bin is outside open_basedir and is_file() becomes a 500.
     */
    private static function lookupOnPath(string $binary, string $osFamily): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        try {
            $output = $osFamily === 'Windows'
                ? trim((string) shell_exec('where '.escapeshellarg($binary).' 2>NUL'))
                : trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));
        } catch (\Throwable) {
            return null;
        }

        if ($output === '') {
            return null;
        }

        $first = explode("\n", str_replace("\r", '', $output))[0] ?? '';
        $first = trim($first);
        if ($first === '' || ! self::looksLikeBinaryPath($first, $binary)) {
            return null;
        }

        return $first;
    }

    private static function looksLikeBinaryPath(string $path, string $binary): bool
    {
        $leaf = strtolower(basename($path));
        $name = strtolower($binary);

        return $leaf === $name || $leaf === $name.'.exe';
    }

    /**
     * @return list<string>
     */
    private static function windowsCandidates(string $name, ?string $localAppData): array
    {
        if ($name === 'tesseract') {
            return [
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            ];
        }
        if ($name !== 'pdftoppm' || ! is_string($localAppData) || $localAppData === '') {
            return [];
        }

        try {
            $matches = glob($localAppData.'\\Microsoft\\WinGet\\Packages\\*Poppler*\\poppler-*\\Library\\bin\\pdftoppm.exe') ?: [];
        } catch (\Throwable) {
            return [];
        }
        rsort($matches);

        return array_values(array_filter($matches, 'is_string'));
    }

    private static function isStatableFile(string $path): bool
    {
        if ($path === '' || self::isOutsideOpenBasedir($path)) {
            return false;
        }

        try {
            return is_file($path);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function isOutsideOpenBasedir(string $path): bool
    {
        $restriction = ini_get('open_basedir');
        if (! is_string($restriction) || $restriction === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        foreach (explode(PATH_SEPARATOR, $restriction) as $allowed) {
            $allowed = rtrim(str_replace('\\', '/', $allowed), '/');
            if ($allowed === '') {
                continue;
            }
            if ($normalized === $allowed || str_starts_with($normalized, $allowed.'/')) {
                return false;
            }
        }

        return true;
    }

    private function stderr(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
