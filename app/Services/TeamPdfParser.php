<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Enums\FlooringSpecialty;
use App\Services\Meetstaat\PdfTextExtractor;

class TeamPdfParser
{
    public function __construct(private PdfTextExtractor $extractor) {}

    /**
     * @return array{
     *     teams: list<array{
     *         name: string,
     *         employment_type: string,
     *         people_count: int,
     *         specialties: list<string>,
     *         crew_names: ?string,
     *         email: ?string,
     *         phone: ?string,
     *         company: ?string,
     *         address: ?string,
     *         postal_code: ?string,
     *         city: ?string,
     *         contact_name: ?string
     *     }>,
     *     team: ?array{
     *         name: string,
     *         employment_type: string,
     *         people_count: int,
     *         specialties: list<string>,
     *         crew_names: ?string,
     *         email: ?string,
     *         phone: ?string,
     *         company: ?string,
     *         address: ?string,
     *         postal_code: ?string,
     *         city: ?string,
     *         contact_name: ?string
     *     }
     * }
     */
    public function parseFile(string $path): array
    {
        $extracted = $this->extractor->extract($path);
        $text = trim($extracted['text']);
        if ($text === '' || preg_match('/\p{L}/u', $text) !== 1) {
            throw new \InvalidArgumentException('Deze PDF bevat geen leesbare tekst. Gebruik een PDF met tekst, geen scan.');
        }

        return $this->parseText($text);
    }

    /**
     * @return array{
     *     teams: list<array{
     *         name: string,
     *         employment_type: string,
     *         people_count: int,
     *         specialties: list<string>,
     *         crew_names: ?string,
     *         email: ?string,
     *         phone: ?string,
     *         company: ?string,
     *         address: ?string,
     *         postal_code: ?string,
     *         city: ?string,
     *         contact_name: ?string
     *     }>,
     *     team: ?array{
     *         name: string,
     *         employment_type: string,
     *         people_count: int,
     *         specialties: list<string>,
     *         crew_names: ?string,
     *         email: ?string,
     *         phone: ?string,
     *         company: ?string,
     *         address: ?string,
     *         postal_code: ?string,
     *         city: ?string,
     *         contact_name: ?string
     *     }
     * }
     */
    public function parseText(string $text): array
    {
        $text = $this->normalize($text);
        $teams = $this->parseRoster($text);
        if ($teams === []) {
            foreach ($this->sections($text) as $section) {
                $team = $this->parseSection($section);
                if ($team !== null) {
                    $teams[] = $team;
                }
            }
        }

        if ($teams === []) {
            $team = $this->parseSection($text);
            if ($team !== null) {
                $teams[] = $team;
            }
        }

        return [
            'teams' => $teams,
            'team' => $teams[0] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function sections(string $text): array
    {
        $parts = preg_split('/(?=^(?:team|ploeg)\s+\S)/imu', $text) ?: [];

        return array_values(array_filter(
            array_map(fn (string $part): string => trim($part), $parts),
            fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * @return array{
     *     name: string,
     *     employment_type: string,
     *     people_count: int,
     *     specialties: list<string>,
     *     crew_names: ?string,
     *     email: ?string,
     *     phone: ?string,
     *     company: ?string,
     *     address: ?string,
     *     postal_code: ?string,
     *     city: ?string,
     *     contact_name: ?string
     * }|null
     */
    private function parseSection(string $text): ?array
    {
        $name = $this->name($text);
        if ($name === null) {
            return null;
        }

        $crewNames = $this->crewNames($text);
        $crewMembers = $this->crewMembersFromNames($crewNames);
        $peopleCount = $this->peopleCount($text, $crewNames);
        $phone = $this->phone($text);
        if ($phone !== null && $crewMembers !== [] && $crewMembers[0]['phone'] === '') {
            $crewMembers[0]['phone'] = $phone;
        }

        return [
            'name' => $name,
            'employment_type' => $this->employmentType($text),
            'people_count' => $peopleCount,
            'specialties' => $this->specialties($text),
            'crew_names' => $crewNames,
            'crew_members' => $crewMembers,
            'email' => $this->email($text),
            'phone' => $phone,
            'company' => $this->labeled($text, ['bedrijf', 'bedrijfsnaam', 'zzp-bedrijf']),
            'address' => $this->labeled($text, ['adres', 'straat']),
            'postal_code' => $this->postalCode($text),
            'city' => $this->labeled($text, ['plaats', 'woonplaats', 'stad']),
            'contact_name' => $this->labeled($text, ['contactpersoon', 'contact', 'contactnaam']),
        ];
    }

    private function name(string $text): ?string
    {
        $labeled = $this->labeled($text, ['naam van het team', 'teamnaam', 'ploegnaam', 'naam']);
        if ($labeled !== null && ! $this->isMetaLine($labeled)) {
            return $this->limit($labeled, 255);
        }

        foreach ($this->lines($text) as $line) {
            if ($this->isRosterHeader($line)) {
                continue;
            }
            if (preg_match('/^(?:team|ploeg)\s+(\d+)\b/iu', $line, $match) === 1) {
                return $this->limit('Team '.$match[1], 255);
            }
            if (preg_match('/^(?:team|ploeg)\s+(.+)$/iu', $line, $match) === 1) {
                $candidate = trim($line);
                if ($candidate !== '' && ! $this->isMetaLine($match[1]) && ! $this->isRosterHeader($candidate)) {
                    return $this->limit($candidate, 255);
                }
            }
        }

        return null;
    }

    private function employmentType(string $text): string
    {
        $labeled = $this->labeled($text, ['type', 'dienstverband', 'soort']);
        if ($labeled !== null) {
            return $this->mapEmploymentType($labeled);
        }

        foreach ($this->lines($text) as $line) {
            $flat = mb_strtolower($line);
            if (in_array($flat, ['zzp', 'eigen', 'eigen medewerker', 'onderaannemer', 'intern'], true)) {
                return $this->mapEmploymentType($line);
            }
        }

        return EmploymentType::Eigen->value;
    }

    private function mapEmploymentType(string $value): string
    {
        $flat = mb_strtolower($value);
        if (str_contains($flat, 'onderaannemer')) {
            return EmploymentType::Onderaannemer->value;
        }
        if (preg_match('/\bzzp\b/u', $flat) === 1 || str_contains($flat, 'zelfstandig')) {
            return EmploymentType::Zzp->value;
        }

        return EmploymentType::Eigen->value;
    }

    private function peopleCount(string $text, ?string $crewNames): int
    {
        $count = 1;
        $labeled = $this->labeled($text, ['personen', 'grootte', 'aantal personen', 'aantal']);
        if ($labeled !== null && preg_match('/(\d{1,2})/', $labeled, $match) === 1) {
            $count = (int) $match[1];
        } elseif (preg_match('/(\d{1,2})\s*personen\b/iu', $text, $match) === 1) {
            $count = (int) $match[1];
        }

        $named = $crewNames === null ? 0 : count($this->splitList($crewNames));

        return max(1, min(50, max($count, $named)));
    }

    private function crewNames(string $text): ?string
    {
        $labeled = $this->labeled($text, ['namen van het team', 'personen in het team', 'teamleden', 'medewerkers', 'namen']);
        if ($labeled === null) {
            return null;
        }

        $names = $this->splitList($labeled);
        if ($names === []) {
            return null;
        }

        return $this->limit(implode(', ', $names), 255);
    }

    /**
     * @return list<string>
     */
    private function specialties(string $text): array
    {
        $labeled = $this->labeled($text, ['vakkennis', 'specialisatie', 'specialismen', 'werkzaamheden']);
        if ($labeled !== null) {
            return $this->specialtyValues($this->splitList($labeled), allowCustom: true);
        }

        foreach ($this->lines($text) as $line) {
            if ($this->looksLabeled($line) || $this->isTypeLine($line)) {
                continue;
            }

            $parts = $this->splitList($line);
            if ($parts === []) {
                continue;
            }

            $matched = $this->specialtyValues($parts);
            if ($matched === [] || count($matched) !== count($parts)) {
                continue;
            }

            return $matched;
        }

        return [];
    }

    /**
     * @param  list<string>  $parts
     * @return list<string>
     */
    private function specialtyValues(array $parts, bool $allowCustom = false): array
    {
        $values = [];
        foreach ($parts as $part) {
            $case = FlooringSpecialty::caseFrom($part);
            if ($case instanceof FlooringSpecialty) {
                $value = $case->value;
            } elseif ($allowCustom && $part !== '' && ! $this->isTypeLine($part)) {
                $value = $part;
            } else {
                continue;
            }

            $key = mb_strtolower($value);
            if (! isset($values[$key])) {
                $values[$key] = $value;
            }
        }

        return array_values($values);
    }

    private function email(string $text): ?string
    {
        $labeled = $this->labeled($text, ['e-mail', 'email', 'mail']);
        $candidate = $labeled ?? $text;
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $candidate, $match) !== 1) {
            return null;
        }

        $email = strtolower($match[0]);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function phone(string $text): ?string
    {
        $labeled = $this->labeled($text, ['telefoon', 'tel', 'gsm', 'mobiel', 'telefoonnummer']);

        return $this->extractMobile($labeled ?? '');
    }

    private function postalCode(string $text): ?string
    {
        $labeled = $this->labeled($text, ['postcode']);
        $candidate = $labeled ?? '';
        if ($candidate !== '' && preg_match('/\b(\d{4}\s*[A-Z]{2})\b/i', $candidate, $match) === 1) {
            return strtoupper(preg_replace('/\s+/', ' ', trim($match[1])) ?? trim($match[1]));
        }

        return $labeled !== null ? $this->limit($labeled, 16) : null;
    }

    /**
     * @param  list<string>  $labels
     */
    private function labeled(string $text, array $labels): ?string
    {
        usort($labels, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $pattern = implode('|', array_map(fn (string $label): string => preg_quote($label, '/'), $labels));
        if (preg_match('/(?:^|\n)\s*(?:'.$pattern.')\s*[:\-]\s*(.+)/iu', $text, $match) !== 1) {
            return null;
        }

        $value = trim($match[1]);

        return $value === '' ? null : $value;
    }

    private function isMetaLine(string $line): bool
    {
        if ($this->isRosterHeader($line)) {
            return true;
        }

        $flat = mb_strtolower($line);
        if (in_array($flat, ['meetstaat', 'materialenstaat', 'plattegrond', 'calculatie', 'afmetingen', 'teams', 'ploegen'], true)) {
            return true;
        }

        if ($this->looksLabeled($line) || $this->isTypeLine($line)) {
            return true;
        }

        if (preg_match('/^\d{1,2}\s*personen\b/iu', $line) === 1) {
            return true;
        }

        if ($this->extractMobile($line) !== null && $this->splitMobile($line)['line'] === '') {
            return true;
        }

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $line) === 1) {
            return true;
        }

        $parts = $this->splitList($line);
        if ($parts !== [] && count($this->specialtyValues($parts)) === count($parts)) {
            return true;
        }

        return false;
    }

    private function isTypeLine(string $line): bool
    {
        $flat = mb_strtolower(trim($line));

        return in_array($flat, ['zzp', 'eigen', 'eigen medewerker', 'onderaannemer', 'intern', 'zelfstandig'], true);
    }

    private function looksLabeled(string $line): bool
    {
        return preg_match('/^[^:\n]{2,40}\s*[:\-]\s*.+/u', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        $parts = preg_split('/\s*[,;\/]\s*/', $value) ?: [];

        return array_values(array_filter(
            array_map(fn (string $part): string => trim($part), $parts),
            fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $lines = preg_split("/\n+/", $text) ?: [];

        return array_values(array_filter(
            array_map(fn (string $line): string => trim($line), $lines),
            fn (string $line): bool => $line !== '',
        ));
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<array{
     *     name: string,
     *     employment_type: string,
     *     people_count: int,
     *     specialties: list<string>,
     *     crew_names: ?string,
     *     crew_members: list<array{name: string, phone: string}>,
     *     email: ?string,
     *     phone: ?string,
     *     company: ?string,
     *     address: ?string,
     *     postal_code: ?string,
     *     city: ?string,
     *     contact_name: ?string
     * }>
     */
    private function parseRoster(string $text): array
    {
        if (! $this->looksLikeRoster($text)) {
            return [];
        }

        $teams = [];
        $currentName = null;
        $currentLines = [];
        $flush = function () use (&$teams, &$currentName, &$currentLines): void {
            if ($currentName === null) {
                return;
            }

            $members = $this->memberRecordsFromRosterLines($currentLines);
            if ($members !== []) {
                $teams[] = $this->teamFromMembers($currentName, $members);
            }

            $currentName = null;
            $currentLines = [];
        };

        foreach ($this->lines($this->normalizeRoster($text)) as $line) {
            if ($this->isRosterHeader($line)) {
                continue;
            }

            if (preg_match('/^(?:team|ploeg)\s+(\d+)\b(.*)$/iu', $line, $match) === 1) {
                $flush();
                $currentName = 'Team '.$match[1];
                $rest = trim($match[2]);
                if ($rest !== '') {
                    $currentLines[] = $rest;
                }

                continue;
            }

            if ($currentName !== null) {
                $currentLines[] = $line;
            }
        }
        $flush();

        return $teams;
    }

    private function looksLikeRoster(string $text): bool
    {
        foreach ($this->lines($text) as $line) {
            if ($this->isRosterHeader($line)) {
                return true;
            }
        }

        return preg_match_all('/^(?:team|ploeg)\s+\d+\b/imu', $text) >= 2;
    }

    private function isRosterHeader(string $line): bool
    {
        $flat = mb_strtolower(preg_replace('/\s+/', ' ', trim($line)) ?? trim($line));

        return str_contains($flat, 'voornaam')
            && str_contains($flat, 'medewerker')
            && (
                str_contains($flat, 'team')
                || str_contains($flat, 'rol')
                || str_contains($flat, 'telefoon')
                || str_contains($flat, 'gsm')
            );
    }

    private function normalizeRoster(string $text): string
    {
        $text = preg_replace('/\s+(?=(?:team|ploeg)\s+\d+\b)/iu', "\n", $text) ?? $text;
        $text = preg_replace('/\s+(?=(?:\+31|0)\s*6)/', "\n", $text) ?? $text;
        $text = preg_replace(
            '/\b((?:vakman|voorman)(?:\s*\/\s*(?:vakman|voorman))?)\s+(?=\p{L})/iu',
            "$1\n",
            $text,
        ) ?? $text;

        return $this->normalize($text);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{name: string, phone: string}>
     */
    private function memberRecordsFromRosterLines(array $lines): array
    {
        $members = [];
        $pendingVoornaam = null;
        $pendingPhone = null;

        foreach ($lines as $raw) {
            $split = $this->splitMobile($raw);
            $line = $this->stripRole($split['line']);
            $phone = $split['phone'];

            if ($line === '') {
                if ($phone !== null) {
                    $this->assignPendingPhone($members, $pendingVoornaam, $pendingPhone, $phone);
                }

                continue;
            }

            if ($this->isRosterHeader($line) || $this->isRoleToken($line)) {
                if ($pendingVoornaam !== null) {
                    $members[] = $this->memberRecord($pendingVoornaam, $pendingPhone ?? $phone);
                    $pendingVoornaam = null;
                    $pendingPhone = null;
                }

                continue;
            }

            if (preg_match('/^(\p{L}[\p{L}\'\-]*)\s+(.+)$/u', $line, $match) === 1 && $this->looksLikeOfficialName($match[2])) {
                if ($pendingVoornaam !== null) {
                    $members[] = $this->memberRecord($pendingVoornaam, $pendingPhone);
                }
                $members[] = $this->memberRecord($this->displayName($match[1], $match[2]), $phone);
                $pendingVoornaam = null;
                $pendingPhone = null;

                continue;
            }

            if ($this->looksLikeOfficialName($line)) {
                $official = $this->isPlaceholderName($line) ? null : $line;
                $members[] = $this->memberRecord($this->displayName($pendingVoornaam, $official), $phone ?? $pendingPhone);
                $pendingVoornaam = null;
                $pendingPhone = null;

                continue;
            }

            if ($pendingVoornaam !== null) {
                $members[] = $this->memberRecord($pendingVoornaam, $pendingPhone);
            }
            $pendingVoornaam = $line;
            $pendingPhone = $phone;
        }

        if ($pendingVoornaam !== null) {
            $members[] = $this->memberRecord($pendingVoornaam, $pendingPhone);
        }

        return $this->uniqueRosterMembers(array_values(array_filter(
            $members,
            fn (array $member): bool => $member['name'] !== '',
        )));
    }

    /**
     * @param  list<array{name: string, phone: string}>  $members
     * @return list<array{name: string, phone: string}>
     */
    private function uniqueRosterMembers(array $members): array
    {
        $unique = [];
        $indexByName = [];
        foreach ($members as $member) {
            $key = mb_strtolower($member['name']);
            if (isset($indexByName[$key])) {
                $index = $indexByName[$key];
                if ($unique[$index]['phone'] === '' && $member['phone'] !== '') {
                    $unique[$index]['phone'] = $member['phone'];
                }

                continue;
            }

            $indexByName[$key] = count($unique);
            $unique[] = $member;
        }

        return $unique;
    }

    /**
     * @param  list<array{name: string, phone: string}>  $members
     */
    private function assignPendingPhone(array &$members, ?string &$pendingVoornaam, ?string &$pendingPhone, string $phone): void
    {
        if ($members !== []) {
            $last = count($members) - 1;
            if ($members[$last]['phone'] === '') {
                $members[$last]['phone'] = $phone;
            }

            return;
        }

        if ($pendingVoornaam !== null && $pendingPhone === null) {
            $pendingPhone = $phone;
        }
    }

    /**
     * @return array{name: string, phone: string}
     */
    private function memberRecord(?string $name, ?string $phone): array
    {
        return [
            'name' => trim((string) $name),
            'phone' => trim((string) $phone),
        ];
    }

    /**
     * @return array{line: string, phone: ?string}
     */
    private function splitMobile(string $line): array
    {
        $phone = $this->extractMobile($line);
        if ($phone === null) {
            return ['line' => trim($line), 'phone' => null];
        }

        $without = trim((string) preg_replace($this->mobilePattern(), ' ', $line));
        $without = trim((string) preg_replace('/\s+/', ' ', $without));

        return ['line' => $without, 'phone' => $phone];
    }

    private function extractMobile(string $text): ?string
    {
        if (preg_match($this->mobilePattern(), $text, $match) !== 1) {
            return null;
        }

        return $this->limit(preg_replace('/\s+/', ' ', trim($match[0])) ?? trim($match[0]), 64);
    }

    private function mobilePattern(): string
    {
        return '/(?:\+31|0)\s*6(?:[\s\-]?\d){8}/';
    }

    private function stripRole(string $line): string
    {
        $stripped = preg_replace(
            '/\s+((?:vakman|voorman)(?:\s*\/\s*(?:vakman|voorman))?)$/iu',
            '',
            trim($line),
        );

        return trim((string) $stripped);
    }

    private function isRoleToken(string $line): bool
    {
        $flat = mb_strtolower(preg_replace('/\s+/', ' ', trim($line)) ?? trim($line));

        return preg_match('/^(vakman|voorman)(?:\s*\/\s*(vakman|voorman))?$/u', $flat) === 1;
    }

    private function looksLikeOfficialName(string $line): bool
    {
        if ($this->isPlaceholderName($line)) {
            return true;
        }

        return preg_match('/^[A-Z]\.(?:[A-Z]\.)*/u', $line) === 1
            || preg_match('/^[\p{L}\-]+,\s*[A-Z]\.?$/u', $line) === 1;
    }

    private function isPlaceholderName(string $line): bool
    {
        return in_array(trim($line), ['??', '?', '-', '…', '...'], true);
    }

    private function displayName(?string $voornaam, ?string $medewerker): string
    {
        $voornaam = trim((string) $voornaam);
        $medewerker = trim((string) $medewerker);
        if ($voornaam !== '') {
            return $voornaam;
        }

        return $medewerker;
    }

    /**
     * @param  list<array{name: string, phone: string}>  $members
     * @return array{
     *     name: string,
     *     employment_type: string,
     *     people_count: int,
     *     specialties: list<string>,
     *     crew_names: ?string,
     *     crew_members: list<array{name: string, phone: string}>,
     *     email: ?string,
     *     phone: ?string,
     *     company: ?string,
     *     address: ?string,
     *     postal_code: ?string,
     *     city: ?string,
     *     contact_name: ?string
     * }
     */
    private function teamFromMembers(string $name, array $members): array
    {
        $names = [];
        $firstPhone = null;
        foreach ($members as $member) {
            if ($member['name'] !== '') {
                $names[] = $member['name'];
            }
            if ($firstPhone === null && $member['phone'] !== '') {
                $firstPhone = $member['phone'];
            }
        }
        $crewNames = $names === [] ? null : $this->limit(implode(', ', $names), 255);

        return [
            'name' => $this->limit($name, 255),
            'employment_type' => EmploymentType::Eigen->value,
            'people_count' => max(1, count($names)),
            'specialties' => [],
            'crew_names' => $crewNames,
            'crew_members' => $members,
            'email' => null,
            'phone' => $firstPhone,
            'company' => null,
            'address' => null,
            'postal_code' => null,
            'city' => null,
            'contact_name' => null,
        ];
    }

    /**
     * @return list<array{name: string, phone: string}>
     */
    private function crewMembersFromNames(?string $crewNames): array
    {
        if ($crewNames === null || $crewNames === '') {
            return [];
        }

        $members = [];
        foreach ($this->splitList($crewNames) as $name) {
            $split = $this->splitMobile($name);
            if ($split['line'] === '') {
                continue;
            }

            $members[] = [
                'name' => $split['line'],
                'phone' => $split['phone'] ?? '',
            ];
        }

        return $members;
    }

    private function limit(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }
}
