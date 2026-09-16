<?php

namespace App\Support;

class PdftotextBinary
{
    /**
     * @var list<string>
     */
    private const WELL_KNOWN_PATHS = [
        '/usr/bin/pdftotext',
        '/usr/local/bin/pdftotext',
    ];

    public static function path(): ?string
    {
        return self::resolve(
            config('pdf.pdftotext_binary'),
            PHP_OS_FAMILY,
        );
    }

    public static function stderrRedirect(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
    }

    /**
     * @param  callable(string): bool|null  $exists
     * @param  callable(string): ?string|null  $lookup
     */
    public static function resolve(
        mixed $configured,
        string $osFamily,
        ?callable $exists = null,
        ?callable $lookup = null,
    ): ?string {
        $exists ??= is_file(...);
        $lookup ??= fn (string $family): ?string => self::fromPathLookup($family);

        if (is_string($configured) && trim($configured) !== '') {
            $usable = self::usable($configured, $exists);
            if ($usable !== null) {
                return $usable;
            }
        }

        foreach (self::WELL_KNOWN_PATHS as $candidate) {
            $usable = self::usable($candidate, $exists);
            if ($usable !== null) {
                return $usable;
            }
        }

        $fromPath = $lookup($osFamily);
        if (! is_string($fromPath) || trim($fromPath) === '') {
            return null;
        }

        return self::usable($fromPath, $exists);
    }

    /**
     * @param  callable(string): bool  $exists
     */
    private static function usable(string $path, callable $exists): ?string
    {
        $path = trim($path);
        if ($path === '' || ! $exists($path)) {
            return null;
        }

        return $path;
    }

    private static function fromPathLookup(string $osFamily): ?string
    {
        $output = $osFamily === 'Windows'
            ? trim((string) shell_exec('where pdftotext 2>NUL'))
            : trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        if ($output === '') {
            return null;
        }

        $first = explode("\n", str_replace("\r", '', $output))[0] ?? '';

        return trim($first) === '' ? null : trim($first);
    }
}
