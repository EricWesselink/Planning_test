<?php

namespace App\Services\Meetstaat;

class PdfMemoryGuard
{
    public function ensureCanParse(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException('Dit PDF-bestand kan niet worden gelezen.');
        }

        $size = (int) filesize($path);
        if ($size < 1) {
            return;
        }

        $maxBytes = (int) config('pdf.php_parser_max_bytes');
        if ($size > $maxBytes) {
            throw new \InvalidArgumentException($this->tooHeavyMessage());
        }

        $limit = $this->memoryLimitBytes();
        if ($limit === null) {
            return;
        }

        $projected = memory_get_usage(true) + (int) round($size * (float) config('pdf.php_parser_expansion_factor'));
        $ceiling = (int) round($limit * (float) config('pdf.php_parser_headroom', 0.90));

        if ($projected > $ceiling) {
            throw new \InvalidArgumentException($this->tooHeavyMessage());
        }
    }

    public function release(): void
    {
        gc_collect_cycles();
    }

    public function tooHeavyMessage(): string
    {
        return 'Dit PDF-bestand is te zwaar om te verwerken. Splits de pagina\'s of verklein de scan en probeer het opnieuw.';
    }

    private function memoryLimitBytes(): ?int
    {
        $configured = config('pdf.php_parser_memory_limit');
        $raw = is_string($configured) && $configured !== ''
            ? $configured
            : (string) ini_get('memory_limit');
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $raw,
        };
    }
}
