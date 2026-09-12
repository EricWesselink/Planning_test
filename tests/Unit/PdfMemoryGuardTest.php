<?php

namespace Tests\Unit;

use App\Services\Meetstaat\PdfMemoryGuard;
use Tests\TestCase;

class PdfMemoryGuardTest extends TestCase
{
    public function test_rejects_a_file_larger_than_the_php_parser_budget(): void
    {
        config(['pdf.php_parser_max_bytes' => 1024]);
        $path = tempnam(sys_get_temp_dir(), 'pdfg');
        file_put_contents($path, str_repeat('a', 2048));

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('te zwaar om te verwerken');
            (new PdfMemoryGuard)->ensureCanParse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_a_file_that_would_exceed_remaining_php_memory(): void
    {
        config([
            'pdf.php_parser_max_bytes' => 10 * 1024 * 1024,
            'pdf.php_parser_expansion_factor' => 50,
            'pdf.php_parser_headroom' => 0.90,
            'pdf.php_parser_memory_limit' => '1M',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'pdfg');
        file_put_contents($path, '%PDF-1.4');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('te zwaar om te verwerken');
            (new PdfMemoryGuard)->ensureCanParse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_allows_a_small_readable_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdfg');
        file_put_contents($path, '%PDF-1.4');

        try {
            (new PdfMemoryGuard)->ensureCanParse($path);
            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function test_does_not_reject_an_empty_placeholder_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdfg');
        file_put_contents($path, '');

        try {
            (new PdfMemoryGuard)->ensureCanParse($path);
            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }
}
