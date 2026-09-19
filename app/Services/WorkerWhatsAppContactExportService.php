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
                $name = trim((string) $member->name);
                if ($name === '' || ! $member->isActive()) {
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
            ->with('crewPeople')
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

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }
}
