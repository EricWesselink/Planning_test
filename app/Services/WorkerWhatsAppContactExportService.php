<?php

namespace App\Services;

use App\Models\CrewMember;
use App\Models\Worker;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

class WorkerWhatsAppContactExportService
{
    public const FILENAME = 'nicon-vakmannen.vcf';

    public const NAME_PREFIX = 'Nicon - ';

    /**
     * @return array{vcf: string, skipped: list<string>}
     */
    public function export(): array
    {
        $contacts = [];
        $skipped = [];

        foreach ($this->ownStaffTeams() as $worker) {
            foreach ($this->peopleOn($worker) as $member) {
                if (! $member->isActive()) {
                    continue;
                }

                $name = $this->contactName($member, $worker);
                if ($name === '') {
                    continue;
                }

                $phone = PhoneNumber::e164($member->phone);
                if ($phone === null) {
                    $skipped[] = $name;

                    continue;
                }

                $contacts[] = [
                    'name' => $name,
                    'phone' => $phone,
                ];
            }
        }

        usort(
            $contacts,
            fn (array $left, array $right): int => strcmp(mb_strtolower($left['name']), mb_strtolower($right['name'])),
        );

        return [
            'vcf' => $this->vcf($contacts),
            'skipped' => $skipped,
        ];
    }

    /**
     * @return Collection<int, Worker>
     */
    private function ownStaffTeams(): Collection
    {
        return Worker::query()
            ->ownStaff()
            ->where('active', true)
            ->with(['crewPeople.user', 'users'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, CrewMember>
     */
    private function peopleOn(Worker $worker): Collection
    {
        $named = $worker->crewPeople
            ->filter(fn (CrewMember $member): bool => trim((string) $member->name) !== '')
            ->values();
        if ($named->isNotEmpty()) {
            return $named;
        }

        $phone = $worker->crewPeople
            ->map(fn (CrewMember $member): string => trim((string) $member->phone))
            ->first(fn (string $phone): bool => $phone !== '');

        return collect([
            $worker->crewPeople()->make([
                'name' => $worker->name,
                'phone' => $phone ?? (string) $worker->phone,
                'active' => true,
            ]),
        ]);
    }

    /**
     * @param  list<array{name: string, phone: string}>  $contacts
     */
    private function vcf(array $contacts): string
    {
        if ($contacts === []) {
            return '';
        }

        $cards = [];
        foreach ($contacts as $contact) {
            $displayName = $this->escape(self::NAME_PREFIX.$contact['name']);
            $cards[] = implode("\r\n", [
                'BEGIN:VCARD',
                'VERSION:3.0',
                'FN;CHARSET=UTF-8:'.$displayName,
                'N;CHARSET=UTF-8:'.$displayName.';;;;',
                'TEL;TYPE=CELL:'.$contact['phone'],
                'END:VCARD',
            ]);
        }

        return implode("\r\n", $cards)."\r\n";
    }

    private function contactName(CrewMember $member, Worker $worker): string
    {
        $candidates = [(string) $member->name];
        if (trim((string) $member->user?->name) !== '') {
            $candidates[] = (string) $member->user->name;
        }
        foreach ($worker->users as $user) {
            if ($this->namesReferToSamePerson((string) $member->name, (string) $user->name)) {
                $candidates[] = (string) $user->name;
            }
        }
        if (
            ! $this->looksLikeTeamLabel((string) $worker->name)
            && $this->namesReferToSamePerson((string) $member->name, (string) $worker->name)
        ) {
            $candidates[] = (string) $worker->name;
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $name = $this->normalizeDisplayName($candidate);
            if ($name !== '' && $this->namesReferToSamePerson((string) $member->name, $name)) {
                $normalized[] = $name;
            }
        }

        return $this->preferFullestName($normalized);
    }

    private function normalizeDisplayName(string $value): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($name === '') {
            return '';
        }

        if (preg_match('/^(.+?)\s*,\s*(\p{L}{1,2})\.?$/u', $name, $matches) === 1) {
            $before = trim($matches[1]);
            $words = preg_split('/\s+/u', $before, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $initial = mb_strtoupper($matches[2]);
            if ($words !== [] && mb_strtoupper(mb_substr($words[0], 0, mb_strlen($initial))) === $initial) {
                return $before;
            }
        }

        if (preg_match('/^(\p{L}[\p{L}\'\-]+)\s*,\s*(\p{L}[\p{L}\'\-]{2,})$/u', $name, $matches) === 1) {
            return trim($matches[2].' '.$matches[1]);
        }

        return $name;
    }

    /**
     * @param  list<string>  $names
     */
    private function preferFullestName(array $names): string
    {
        $best = '';
        $bestWords = 0;
        foreach ($names as $name) {
            $words = count(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            if ($words > $bestWords || ($words === $bestWords && mb_strlen($name) > mb_strlen($best))) {
                $best = $name;
                $bestWords = $words;
            }
        }

        return $best;
    }

    private function namesReferToSamePerson(string $left, string $right): bool
    {
        $left = mb_strtolower($this->normalizeDisplayName($left));
        $right = mb_strtolower($this->normalizeDisplayName($right));
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }

        $shorter = mb_strlen($left) <= mb_strlen($right) ? $left : $right;
        $longer = $shorter === $left ? $right : $left;
        $shorterFirst = explode(' ', $shorter, 2)[0];
        $longerFirst = explode(' ', $longer, 2)[0];

        return ($shorterFirst === $longerFirst && str_starts_with($longer, $shorter.' '))
            || $this->invertedFamilyMatches($left, $right)
            || $this->invertedFamilyMatches($right, $left);
    }

    private function invertedFamilyMatches(string $inverted, string $full): bool
    {
        if (preg_match('/^(.+?)\s*,\s*(\p{L}{1,2})\.?$/u', $inverted, $matches) !== 1) {
            return false;
        }

        $family = trim($matches[1]);
        $words = preg_split('/\s+/u', $full, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 2) {
            return false;
        }

        $last = $words[count($words) - 1];
        $initial = mb_strtoupper($matches[2]);

        return $family === $last
            && mb_strtoupper(mb_substr($words[0], 0, mb_strlen($initial))) === $initial;
    }

    private function looksLikeTeamLabel(string $name): bool
    {
        return preg_match('/^team(\s|\d|$)/iu', trim($name)) === 1;
    }

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }
}
