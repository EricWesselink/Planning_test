<?php

namespace Tests\Support;

/**
 * Locates real project drawing PDFs used as regression fixtures.
 * Prefer committed tests/fixtures copies; fall back to known local caches.
 */
class RealDrawingFixtures
{
    public static function laakseTuinenDrawingPath(): ?string
    {
        $candidates = [
            base_path('tests/fixtures/laakse-tuinen-tekening.pdf'),
            storage_path('app/private/meetstaat-previews'),
        ];

        $fixed = $candidates[0];
        if (is_file($fixed) && filesize($fixed) > 1_000_000) {
            return $fixed;
        }

        $attachment = self::attachmentNamed('tekening.pdf');
        if ($attachment !== null) {
            return $attachment;
        }

        $download = 'C:/Users/Administrator/Downloads/werk 1/tekening.pdf';
        if (is_file($download) && filesize($download) > 1_000_000) {
            return $download;
        }

        return null;
    }

    public static function laakseTuinenMeetstaatPath(): string
    {
        return base_path('tests/fixtures/nicon-meetbon-laakse-tuinen.pdf');
    }

    public static function griftlandMeetstaatPath(): ?string
    {
        return self::griftlandBundlePaths()['meetstaat'] ?? null;
    }

    public static function griftlandMaterialsPath(): ?string
    {
        return self::griftlandBundlePaths()['materials'] ?? null;
    }

    /**
     * Meetstaat + materialenstaat + plattegrond uit dezelfde Griftland preview-map.
     *
     * @return array{meetstaat: string, materials: string, drawing: string}|null
     */
    public static function griftlandBundlePaths(): ?array
    {
        $known = storage_path('app/private/meetstaat-previews/53cd2149-750d-4404-8e7a-50cff3a6c9bc');
        $fromKnown = self::bundleFromDirectory($known);
        if ($fromKnown !== null) {
            return $fromKnown;
        }

        return self::newestMatchingBundle(['Griftland', 'English Oak', 'Coral Brush']);
    }

    /**
     * @return array{meetstaat: string, materials: string, drawing: string}|null
     */
    public static function sluisbuurtBundlePaths(): ?array
    {
        $known = storage_path('app/private/meetstaat-previews/d2866a81-2854-459f-9fbf-509e1de85caf');
        $fromKnown = self::bundleFromDirectory($known);
        if ($fromKnown !== null) {
            return $fromKnown;
        }

        return self::newestMatchingBundle(['Sluisbuurt', 'flashy street', 'Lino Art Urban', 'op kurk']);
    }

    /**
     * @return array{meetstaat: string, materials: string, drawing: string}|null
     */
    public static function larenBundlePaths(): ?array
    {
        $known = storage_path('app/private/meetstaat-previews/b3bbaf8d-ea30-40b2-afb5-5e6a36c33b87');
        $fromKnown = self::bundleFromDirectory($known);
        if ($fromKnown !== null) {
            return $fromKnown;
        }

        return self::newestMatchingBundle(['Laren', 'Gezondheidscentrum', 'Desso Airmaster', 'Atmos B747']);
    }

    /**
     * @return array{meetstaat: string, materials: ?string, drawing: ?string}|null
     */
    /**
     * @return array{meetstaat: string, materials: ?string, drawing: ?string}|null
     */
    public static function drachtenBundlePaths(): ?array
    {
        $meetstaatName = 'Meetbon_012D.00047_20260907131411380.pdf';
        $materialsName = 'MaterialList_012D.00047_20260907131441997.pdf';
        $drawingName = 'Plattegrond_012D.00047_20260907131233820.pdf';
        $dropbox = 'C:/Users/Administrator/Dropbox/Niconvloeren/Werken Nicon vloeren/11p250459';
        $meetstaat = $dropbox.'/'.$meetstaatName;
        $materials = $dropbox.'/'.$materialsName;
        $drawing = $dropbox.'/'.$drawingName;
        if (is_file($meetstaat) && filesize($meetstaat) > 50_000) {
            return [
                'meetstaat' => $meetstaat,
                'materials' => is_file($materials) ? $materials : null,
                'drawing' => is_file($drawing) && filesize($drawing) > 1_000_000 ? $drawing : null,
            ];
        }

        $meetstaat = self::attachmentNamed($meetstaatName);
        if ($meetstaat === null) {
            return null;
        }

        return [
            'meetstaat' => $meetstaat,
            'materials' => self::attachmentNamed($materialsName),
            'drawing' => self::attachmentNamed($drawingName),
        ];
    }

    public static function rovaBundlePaths(): ?array
    {
        $meetstaat = base_path('tests/fixtures/nicon-meetbon-rova.pdf');
        if (! is_file($meetstaat)) {
            $hit = self::newestMatchingBundle(['Rova', 'Amersfoort', '11P260712']);
            if ($hit === null) {
                return null;
            }

            return $hit;
        }

        return [
            'meetstaat' => $meetstaat,
            'materials' => null,
            'drawing' => null,
        ];
    }

    public static function griftlandDrawingPath(): ?string
    {
        $fixed = base_path('tests/fixtures/griftland-plattegrond.pdf');
        if (is_file($fixed) && filesize($fixed) > 1_000_000) {
            return $fixed;
        }

        $fromBundle = self::griftlandBundlePaths()['drawing'] ?? null;
        if ($fromBundle !== null) {
            return $fromBundle;
        }

        $known = storage_path('app/private/meetstaat-previews/dea90957-c5db-41ed-a45e-1d1eb74f2bd5/plattegrond-2.pdf');
        if (is_file($known) && filesize($known) > 1_000_000) {
            return $known;
        }

        $attachment = self::attachmentNamed('Plattegrond_012D.00079_20260903135304067.pdf');
        if ($attachment !== null) {
            return $attachment;
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     * @return array{meetstaat: string, materials: string, drawing: string}|null
     */
    private static function newestMatchingBundle(array $needles): ?array
    {
        $root = storage_path('app/private/meetstaat-previews');
        if (! is_dir($root)) {
            return null;
        }

        $dirs = glob($root.'/*', GLOB_ONLYDIR) ?: [];
        usort($dirs, fn (string $left, string $right) => filemtime($right) <=> filemtime($left));

        foreach ($dirs as $dir) {
            $bundle = self::bundleFromDirectory($dir);
            if ($bundle === null) {
                continue;
            }
            if (! self::pdfMentionsAny($bundle['meetstaat'], $needles)
                && ! self::pdfMentionsAny($bundle['materials'], $needles)) {
                continue;
            }

            return $bundle;
        }

        return null;
    }

    /**
     * @return array{meetstaat: string, materials: string, drawing: string}|null
     */
    private static function bundleFromDirectory(string $dir): ?array
    {
        if (! is_dir($dir)) {
            return null;
        }

        $meetstaat = self::firstMatchingFile($dir, 'meetstaat*.pdf');
        $materials = self::firstMatchingFile($dir, 'materialenstaat*.pdf');
        $drawing = self::firstMatchingFile($dir, 'plattegrond*.pdf');
        if ($meetstaat === null || $materials === null || $drawing === null) {
            return null;
        }
        if (filesize($drawing) < 5_000_000) {
            return null;
        }

        return [
            'meetstaat' => $meetstaat,
            'materials' => $materials,
            'drawing' => $drawing,
        ];
    }

    /**
     * @param  list<string>  $needles
     */
    private static function pdfMentionsAny(string $path, array $needles): bool
    {
        if (! is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $sample = (string) fread($handle, 2_000_000);
        fclose($handle);
        $flat = mb_strtolower($sample);
        foreach ($needles as $needle) {
            if (str_contains($flat, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private static function firstMatchingFile(string $dir, string $pattern): ?string
    {
        $hits = glob($dir.DIRECTORY_SEPARATOR.$pattern) ?: [];

        return $hits[0] ?? null;
    }

    private static function attachmentNamed(string $name): ?string
    {
        $root = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        if ($root === '') {
            return null;
        }
        $base = $root.'/.cursor/projects/c-laragon-www-nicon-planning/attachments';
        if (! is_dir($base)) {
            return null;
        }
        foreach (glob($base.'/*/'.$name) ?: [] as $file) {
            if (is_file($file) && filesize($file) > 50_000) {
                return $file;
            }
        }

        return null;
    }
}
