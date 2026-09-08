<?php

namespace App\Services\ScannedDimensions;

/**
 * Rendert PDF-pagina’s en leest tekst via Tesseract.
 * Alleen voor de aparte afmetingen-route — niet voor vloerimport.
 */
class ScannedDimensionsPdfOcr
{
    private const MAX_PAGES = 5;

    private const DPI = 200;

    private static ?string $pdftoppmCache = null;

    private static ?string $tesseractCache = null;

    private static bool $resolved = false;

    public function isAvailable(): bool
    {
        $this->resolveBinaries();

        return self::$pdftoppmCache !== null && self::$tesseractCache !== null;
    }

    /**
     * @return array{text: string, pages: int, engine: ?string, attempted: bool, available: bool}
     */
    public function extractText(string $pdfPath): array
    {
        if (! is_file($pdfPath)) {
            return [
                'text' => '',
                'pages' => 0,
                'engine' => null,
                'attempted' => false,
                'available' => false,
            ];
        }

        if (! $this->isAvailable()) {
            return [
                'text' => '',
                'pages' => 0,
                'engine' => null,
                'attempted' => false,
                'available' => false,
            ];
        }

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-scanned-ocr-'.bin2hex(random_bytes(8));
        if (! mkdir($dir) && ! is_dir($dir)) {
            return [
                'text' => '',
                'pages' => 0,
                'engine' => null,
                'attempted' => true,
                'available' => true,
            ];
        }

        try {
            $prefix = $dir.DIRECTORY_SEPARATOR.'page';
            $command = escapeshellarg((string) self::$pdftoppmCache)
                .' -png -r '.self::DPI.' '
                .escapeshellarg($pdfPath).' '
                .escapeshellarg($prefix)
                .' 2>NUL';
            exec($command, $_, $code);
            if ($code !== 0) {
                return [
                    'text' => '',
                    'pages' => 0,
                    'engine' => null,
                    'attempted' => true,
                    'available' => true,
                ];
            }

            $pages = glob($prefix.'-*.png') ?: [];
            natsort($pages);
            $pages = array_values(array_slice($pages, 0, self::MAX_PAGES));

            $chunks = [];
            foreach ($pages as $page) {
                $outBase = $dir.DIRECTORY_SEPARATOR.'ocr-'.basename($page, '.png');
                $ocrCommand = escapeshellarg((string) self::$tesseractCache)
                    .' '.escapeshellarg($page)
                    .' '.escapeshellarg($outBase)
                    .' -l nld+eng --psm 6'
                    .' 2>NUL';
                exec($ocrCommand, $__, $ocrCode);
                $txtFile = $outBase.'.txt';
                if ($ocrCode === 0 && is_file($txtFile)) {
                    $chunks[] = (string) file_get_contents($txtFile);
                }
            }

            $text = $this->normalize(implode("\n", $chunks));

            return [
                'text' => $text,
                'pages' => count($pages),
                'engine' => 'tesseract',
                'attempted' => true,
                'available' => true,
            ];
        } finally {
            $this->cleanupDirectory($dir);
        }
    }

    private function resolveBinaries(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;
        self::$pdftoppmCache = $this->findPdftoppm();
        self::$tesseractCache = $this->findTesseract();
    }

    private function findPdftoppm(): ?string
    {
        $fromEnv = $this->usableBinary((string) env('SCANNED_DIMENSIONS_PDFTOPPM', ''));
        if ($fromEnv !== null) {
            return $fromEnv;
        }

        $fromPath = $this->which('pdftoppm');
        if ($fromPath !== null) {
            return $fromPath;
        }

        $wingetRoot = getenv('LOCALAPPDATA');
        if (is_string($wingetRoot) && $wingetRoot !== '') {
            $matches = glob($wingetRoot.'\\Microsoft\\WinGet\\Packages\\*Poppler*\\poppler-*\\Library\\bin\\pdftoppm.exe') ?: [];
            rsort($matches);
            foreach ($matches as $match) {
                $usable = $this->usableBinary($match);
                if ($usable !== null) {
                    return $usable;
                }
            }
        }

        return null;
    }

    private function findTesseract(): ?string
    {
        $fromEnv = $this->usableBinary((string) env('SCANNED_DIMENSIONS_TESSERACT', ''));
        if ($fromEnv !== null) {
            return $fromEnv;
        }

        $fromPath = $this->which('tesseract');
        if ($fromPath !== null) {
            return $fromPath;
        }

        foreach ([
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        ] as $candidate) {
            $usable = $this->usableBinary($candidate);
            if ($usable !== null) {
                return $usable;
            }
        }

        return null;
    }

    private function which(string $binary): ?string
    {
        $which = trim((string) shell_exec('where '.escapeshellarg($binary).' 2>NUL'));
        if ($which === '') {
            return null;
        }

        $first = explode("\n", str_replace("\r", '', $which))[0] ?? '';

        return $this->usableBinary($first);
    }

    private function usableBinary(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        return $path;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim($text);
    }

    private function cleanupDirectory(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
