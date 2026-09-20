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

    private const OCR_TIMEOUT_SECONDS = 15.0;

    private static ?string $pdftoppmCache = null;

    private static ?string $tesseractCache = null;

    private static bool $resolved = false;

    /**
     * @var array<string, array{pages: list<array<string, mixed>>, engine: ?string, error: ?string, timings: array<string, float>, ocr_mean_confidence: ?float, ocr_word_count: int, preview_path: ?string}>
     */
    private array $cache = [];

    public function isAvailable(): bool
    {
        $this->resolveBinaries();

        return self::$pdftoppmCache !== null && self::$tesseractCache !== null;
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
        if (! is_file($pdfPath)) {
            return $empty;
        }

        $cacheKey = (hash_file('sha256', $pdfPath) ?: '').'|'.filesize($pdfPath);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        if (! $this->isAvailable()) {
            $result = [
                'pages' => [],
                'engine' => null,
                'error' => 'pdftoppm en Tesseract zijn nodig om een PDF zonder tekstlaag te lezen.',
                'timings' => $emptyTimings,
                'ocr_mean_confidence' => null,
                'ocr_word_count' => 0,
                'preview_path' => null,
            ];
            $this->cache[$cacheKey] = $result;

            return $result;
        }

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-area-ocr-'.bin2hex(random_bytes(8));
        if (! mkdir($dir) && ! is_dir($dir)) {
            $result = [
                'pages' => [],
                'engine' => 'tesseract',
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
            $command = escapeshellarg((string) self::$pdftoppmCache)
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
                    'engine' => 'tesseract',
                    'error' => 'De pagina kon niet naar een afbeelding worden gerenderd.',
                    'timings' => ['render' => $renderSeconds, 'ocr' => 0.0, 'walls' => 0.0],
                    'ocr_mean_confidence' => null,
                    'ocr_word_count' => 0,
                    'preview_path' => null,
                ];
                $this->cache[$cacheKey] = $result;

                return $result;
            }

            $ocrStarted = hrtime(true);
            $ocr = $this->ocrWords($image);
            $ocrSeconds = $this->secondsSince($ocrStarted);

            $size = getimagesize($image);
            $width = (float) ($size[0] ?? 1);
            $height = (float) ($size[1] ?? 1);

            $wallsStarted = hrtime(true);
            $detected = $this->detectWalls($image, $width, $height);
            $wallsSeconds = $this->secondsSince($wallsStarted);

            $previewPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-area-preview-'.bin2hex(random_bytes(8)).'.png';
            $copied = @copy($image, $previewPath);
            $result = [
                'pages' => [[
                    'page' => 1,
                    'width' => $width,
                    'height' => $height,
                    'texts' => $ocr['words'],
                    'fills' => [],
                    'walls' => $detected['walls'],
                    'ticks' => $detected['ticks'],
                ]],
                'engine' => 'tesseract',
                'error' => $ocr['words'] === [] ? 'Tesseract vond geen leesbare tekst op de afbeelding.' : null,
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
        $command = escapeshellarg((string) self::$tesseractCache)
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
     * @return array{walls: list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>, ticks: list<array{x1: float, y1: float, x2: float, y2: float, axis: string}>}
     */
    private function detectWalls(string $imagePath, float $width, float $height): array
    {
        $image = @imagecreatefrompng($imagePath);
        if ($image === false) {
            return ['walls' => [], 'ticks' => []];
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

                return ['walls' => [], 'ticks' => []];
            }
            imagecopyresampled($small, $image, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
            imagedestroy($image);
            $image = $small;
        }

        $minH = max(18, (int) round($w * 0.04));
        $minV = max(18, (int) round($h * 0.04));
        $minTick = 8;
        $step = self::WALL_STEP;
        $walls = [];
        $ticks = [];
        for ($row = 0; $row < $h; $row += $step) {
            $run = 0;
            $start = 0;
            for ($col = 0; $col < $w; $col++) {
                if ($this->isInk($image, $col, $row)) {
                    if ($run === 0) {
                        $start = $col;
                    }
                    $run++;
                } else {
                    if ($run >= $minH) {
                        $walls[] = $this->hWall($start, $col - 1, $row, $scale, $srcH);
                    } elseif ($run >= $minTick) {
                        $ticks[] = $this->hWall($start, $col - 1, $row, $scale, $srcH);
                    }
                    $run = 0;
                }
            }
            if ($run >= $minH) {
                $walls[] = $this->hWall($start, $w - 1, $row, $scale, $srcH);
            } elseif ($run >= $minTick) {
                $ticks[] = $this->hWall($start, $w - 1, $row, $scale, $srcH);
            }
        }
        for ($col = 0; $col < $w; $col += $step) {
            $run = 0;
            $start = 0;
            for ($row = 0; $row < $h; $row++) {
                if ($this->isInk($image, $col, $row)) {
                    if ($run === 0) {
                        $start = $row;
                    }
                    $run++;
                } else {
                    if ($run >= $minV) {
                        $walls[] = $this->vWall($col, $start, $row - 1, $scale, $srcH);
                    } elseif ($run >= $minTick) {
                        $ticks[] = $this->vWall($col, $start, $row - 1, $scale, $srcH);
                    }
                    $run = 0;
                }
            }
            if ($run >= $minV) {
                $walls[] = $this->vWall($col, $start, $h - 1, $scale, $srcH);
            } elseif ($run >= $minTick) {
                $ticks[] = $this->vWall($col, $start, $h - 1, $scale, $srcH);
            }
        }
        imagedestroy($image);

        unset($width, $height);

        return [
            'walls' => $this->mergeWalls($walls),
            'ticks' => $this->mergeWalls($ticks, 12),
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

        return (($r + $g + $b) / 3) < 90;
    }

    /**
     * Image row (top-left origin) to PDF-up Y, matching OCR.
     *
     * @return array{x1: float, y1: float, x2: float, y2: float, axis: string}
     */
    private function hWall(int $x1, int $x2, int $row, float $scale, int $srcH): array
    {
        $y = $srcH - ($row / $scale);

        return [
            'x1' => min($x1, $x2) / $scale,
            'y1' => $y,
            'x2' => max($x1, $x2) / $scale,
            'y2' => $y,
            'axis' => 'h',
        ];
    }

    /**
     * @return array{x1: float, y1: float, x2: float, y2: float, axis: string}
     */
    private function vWall(int $col, int $y1, int $y2, float $scale, int $srcH): array
    {
        $x = $col / $scale;

        return [
            'x1' => $x,
            'y1' => $srcH - (max($y1, $y2) / $scale),
            'x2' => $x,
            'y2' => $srcH - (min($y1, $y2) / $scale),
            'axis' => 'v',
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
                    if ($separation > 8) {
                        continue;
                    }
                    $existing['x1'] = min($existing['x1'], $wall['x1']);
                    $existing['x2'] = max($existing['x2'], $wall['x2']);
                    $kept = true;
                    break;
                }
                if ($wall['axis'] === 'v' && abs($existing['x1'] - $wall['x1']) <= 4) {
                    $separation = max($existing['y1'], $wall['y1']) - min($existing['y2'], $wall['y2']);
                    if ($separation > 8) {
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

    private function resolveBinaries(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;
        self::$pdftoppmCache = $this->findBinary('pdftoppm');
        self::$tesseractCache = $this->findBinary('tesseract');
    }

    private function findBinary(string $name): ?string
    {
        $fromPath = $this->which($name);
        if ($fromPath !== null) {
            return $fromPath;
        }
        $local = getenv('LOCALAPPDATA');
        $poppler = [];
        if ($name === 'pdftoppm' && is_string($local) && $local !== '') {
            $poppler = glob($local.'\\Microsoft\\WinGet\\Packages\\*Poppler*\\poppler-*\\Library\\bin\\pdftoppm.exe') ?: [];
        }
        $candidates = $name === 'tesseract'
            ? [
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            ]
            : $poppler;
        rsort($candidates);
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function which(string $binary): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }
        $output = PHP_OS_FAMILY === 'Windows'
            ? trim((string) shell_exec('where '.escapeshellarg($binary).' 2>NUL'))
            : trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));
        if ($output === '') {
            return null;
        }
        $first = explode("\n", str_replace("\r", '', $output))[0] ?? '';
        $first = trim($first);

        return $first !== '' && is_file($first) ? $first : null;
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
