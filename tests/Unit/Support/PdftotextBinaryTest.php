<?php

namespace Tests\Unit\Support;

use App\Services\QuoteCalculation\DrawingTakeoffParser;
use App\Services\QuoteCalculation\DrawingTextPositions;
use App\Support\PdftotextBinary;
use Tests\Support\SimplePdf;
use Tests\TestCase;

class PdftotextBinaryTest extends TestCase
{
    public function test_prefers_usr_bin_pdftotext_on_linux_without_path_lookup(): void
    {
        $path = PdftotextBinary::resolve(
            null,
            'Linux',
            fn (string $candidate): bool => $candidate === '/usr/bin/pdftotext',
            function (): ?string {
                $this->fail('PATH lookup should not run when /usr/bin/pdftotext exists');

                return null;
            },
        );

        $this->assertSame('/usr/bin/pdftotext', $path);
    }

    public function test_uses_command_v_result_when_well_known_linux_paths_are_missing(): void
    {
        $path = PdftotextBinary::resolve(
            null,
            'Linux',
            fn (string $candidate): bool => $candidate === '/home/bin/pdftotext',
            fn (string $family): ?string => $family === 'Linux' ? '/home/bin/pdftotext' : null,
        );

        $this->assertSame('/home/bin/pdftotext', $path);
    }

    public function test_keeps_windows_where_lookup_when_no_unix_binary_exists(): void
    {
        $windows = 'C:\\poppler\\Library\\bin\\pdftotext.exe';

        $path = PdftotextBinary::resolve(
            null,
            'Windows',
            fn (string $candidate): bool => $candidate === $windows,
            fn (string $family): ?string => $family === 'Windows' ? $windows : null,
        );

        $this->assertSame($windows, $path);
    }

    public function test_returns_null_when_pdftotext_is_unavailable_so_smalot_can_fall_back(): void
    {
        $path = PdftotextBinary::resolve(
            null,
            'Linux',
            fn (string $candidate): bool => false,
            fn (string $family): ?string => null,
        );

        $this->assertNull($path);
    }

    public function test_configured_path_wins_when_the_file_exists(): void
    {
        $configured = '/opt/custom/pdftotext';

        $path = PdftotextBinary::resolve(
            $configured,
            'Linux',
            fn (string $candidate): bool => $candidate === $configured,
            function (): ?string {
                $this->fail('PATH lookup should not run when a configured binary exists');

                return null;
            },
        );

        $this->assertSame($configured, $path);
    }

    public function test_path_returns_the_installed_windows_binary(): void
    {
        $path = PdftotextBinary::path();
        if ($path === null) {
            $this->markTestSkipped('pdftotext is not installed on this machine');
        }

        $this->assertFileExists($path);
        $this->assertStringContainsStringIgnoringCase('pdftotext', basename($path));
    }

    public function test_drawing_layout_text_comes_from_pdftotext_when_available(): void
    {
        if (PdftotextBinary::path() === null) {
            $this->markTestSkipped('pdftotext is not installed on this machine');
        }

        $path = SimplePdf::path('01.12 Woonkamer 24,5 m2 v01.g');
        try {
            $text = (new DrawingTextPositions)->layoutText($path);
            $parsed = (new DrawingTakeoffParser)->parseFile($path);

            $this->assertNotSame('', $text);
            $this->assertStringContainsString('Woonkamer', $text);
            $this->assertSame('pdftotext', $parsed['engine']);
        } finally {
            @unlink($path);
        }
    }
}
