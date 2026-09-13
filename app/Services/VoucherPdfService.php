<?php

namespace App\Services;

use App\Enums\VoucherType;
use App\Models\Voucher;
use App\Support\VoucherActivityGroups;

class VoucherPdfService
{
    /**
     * @return array{
     *     voucher: Voucher,
     *     filename: string,
     *     documentTitle: string,
     *     logo: ?string,
     *     companyName: string,
     *     companyAddress: string,
     *     companyPostalCode: string,
     *     companyCity: string,
     *     companyEmail: string,
     *     companyPhone: string,
     *     recipient: string,
     *     projectTitle: string,
     *     projectNumber: ?string,
     *     workNumber: string,
     *     groups: list<array<string, mixed>>,
     *     total: float
     * }
     */
    public function build(Voucher $voucher): array
    {
        $voucher->loadMissing(['worker', 'project.customer', 'lines.area', 'parent']);

        $groups = VoucherActivityGroups::fromVoucher($voucher);
        $total = round((float) collect($groups)->sum('amount'), 2);

        return [
            'voucher' => $voucher,
            'filename' => $this->filename($voucher),
            'documentTitle' => $voucher->type === VoucherType::Opdracht ? 'OPDRACHTBON' : 'BON',
            'logo' => $this->imagePath((string) config('company.logo')),
            'companyName' => (string) config('company.name'),
            'companyAddress' => (string) config('company.address'),
            'companyPostalCode' => (string) config('company.postal_code'),
            'companyCity' => (string) config('company.city'),
            'companyEmail' => (string) config('company.email'),
            'companyPhone' => (string) config('company.phone'),
            'recipient' => $this->recipientName($voucher),
            'projectTitle' => $voucher->project?->displayTitle() ?? (string) $voucher->project?->name,
            'projectNumber' => $voucher->project?->workCode(),
            'workNumber' => $voucher->project?->workNumber() ?? '',
            'groups' => $groups,
            'total' => $total,
        ];
    }

    public function filename(Voucher $voucher): string
    {
        $voucher->loadMissing(['worker', 'project']);

        $parts = [
            $voucher->type->label(),
            $this->recipientName($voucher),
            $voucher->project?->workCode(),
            $voucher->project?->workNumber(),
        ];

        $safe = collect($parts)
            ->map(fn (mixed $part): string => $this->safeSegment((string) $part))
            ->filter()
            ->implode('_');

        return ($safe !== '' ? $safe : 'bon').'.pdf';
    }

    private function recipientName(Voucher $voucher): string
    {
        $worker = $voucher->worker;
        if ($worker === null) {
            return '';
        }

        if ($worker->employment_type?->isExternal() && filled($worker->company)) {
            return trim((string) $worker->company);
        }

        return $worker->displayName();
    }

    private function safeSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9]+/', '-', trim($value)) ?? '';

        return trim($safe, '-');
    }

    private function imagePath(string $relative): ?string
    {
        $relative = trim($relative);
        if ($relative === '') {
            return null;
        }

        $absolute = public_path($relative);
        if (! is_file($absolute)) {
            return null;
        }

        return 'file://'.str_replace('\\', '/', $absolute);
    }
}
