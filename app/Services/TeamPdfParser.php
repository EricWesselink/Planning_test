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
        $teams = [];
        foreach ($this->sections($text) as $section) {
            $team = $this->parseSection($section);
            if ($team !== null) {
                $teams[] = $team;
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
        $peopleCount = $this->peopleCount($text, $crewNames);

        return [
            'name' => $name,
            'employment_type' => $this->employmentType($text),
            'people_count' => $peopleCount,
            'specialties' => $this->specialties($text),
            'crew_names' => $crewNames,
            'email' => $this->email($text),
            'phone' => $this->phone($text),
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
            if (preg_match('/^(?:team|ploeg)\s+(.+)$/iu', $line, $match) === 1) {
                $candidate = trim($line);
                if ($candidate !== '' && ! $this->isMetaLine($match[1])) {
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
        $candidate = $labeled ?? '';
        if ($candidate === '') {
            return null;
        }

        if (preg_match('/(?:\+31|0)\s*6[\s\-]?\d{2}[\s\-]?\d{2}[\s\-]?\d{2}[\s\-]?\d{2}/', $candidate, $match) === 1) {
            return $this->limit(preg_replace('/\s+/', ' ', trim($match[0])) ?? trim($match[0]), 64);
        }

        $digits = preg_replace('/\D+/', '', $candidate) ?? '';
        if (strlen($digits) >= 10 && strlen($digits) <= 13) {
            return $this->limit(trim($candidate), 64);
        }

        return null;
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

    private function limit(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }
}
