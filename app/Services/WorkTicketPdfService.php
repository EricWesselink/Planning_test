<?php

namespace App\Services;

use App\Enums\WorkTicketKind;
use App\Models\ProjectDocument;
use App\Models\WorkerAssignment;
use App\Models\WorkTicket;
use Illuminate\Support\Facades\Storage;

class WorkTicketPdfService
{
    /**
     * @return array{
     *     ticket: WorkTicket,
     *     filename: string,
     *     documentTitle: string,
     *     logo: ?string,
     *     logoUrl: string,
     *     companyName: string,
     *     companyAddress: string,
     *     companyPostalCode: string,
     *     companyCity: string,
     *     companyEmail: string,
     *     companyPhone: string,
     *     recipient: string,
     *     recipientKind: ?string,
     *     kindLabel: string,
     *     issuedOn: string,
     *     projectTitle: string,
     *     projectNumber: ?string,
     *     workNumber: string,
     *     address: ?string,
     *     period: string,
     *     floors: string,
     *     rooms: string,
     *     drawings: list<string>,
     *     drawingItems: list<array{name: string, url: ?string, path: ?string, is_image: bool}>,
     *     drawingEmbeds: list<array{name: string, path: ?string, is_image: bool}>,
     *     colleagues: list<string>,
     *     showPrices: bool
     * }
     */
    public function build(WorkTicket $ticket, bool $showPrices): array
    {
        $ticket->loadMissing([
            'worker',
            'project.customer',
            'lines.workItem',
            'areas.floor',
            'floors',
            'documents',
            'assignment.crewMembers',
        ]);

        $project = $ticket->project;
        $logoRelative = $project?->issuerLogo() ?? (string) config('company.logo');
        $drawingItems = $ticket->documents
            ->map(fn (ProjectDocument $document): array => [
                'name' => (string) $document->original_filename,
                'url' => $project !== null
                    ? route('projects.documents.show', [$project, $document])
                    : null,
                'path' => $this->storedImagePath($document),
                'is_image' => $document->isImage(),
            ])
            ->values()
            ->all();

        return [
            'ticket' => $ticket,
            'filename' => $this->filename($ticket),
            'documentTitle' => mb_strtoupper($ticket->kind->label()),
            'kindLabel' => $ticket->kind->label(),
            'issuedOn' => ($ticket->created_at ?? $ticket->start_date)->format('d-m-Y'),
            'logo' => $this->publicImagePath($logoRelative),
            'logoUrl' => asset($logoRelative),
            'companyName' => $project?->issuerName() ?? (string) config('company.name'),
            'companyAddress' => (string) config('company.address'),
            'companyPostalCode' => (string) config('company.postal_code'),
            'companyCity' => (string) config('company.city'),
            'companyEmail' => (string) config('company.email'),
            'companyPhone' => (string) config('company.phone'),
            'recipient' => $this->recipientName($ticket),
            'recipientKind' => $ticket->worker?->employment_type?->label(),
            'projectTitle' => $project?->displayTitle() ?? (string) $project?->name,
            'projectNumber' => $project?->workCode(),
            'workNumber' => $project?->workNumber() ?? '',
            'address' => $project?->nawLine(),
            'period' => $ticket->dateRangeLabel(),
            'floors' => $ticket->floorsLabel(),
            'rooms' => $ticket->roomsLabel(),
            'drawings' => $ticket->documents
                ->map(fn (ProjectDocument $document): string => (string) $document->original_filename)
                ->filter()
                ->values()
                ->all(),
            'drawingItems' => $drawingItems,
            'drawingEmbeds' => $drawingItems,
            'colleagues' => $this->colleagueNames($ticket),
            'showPrices' => $showPrices && $ticket->kind === WorkTicketKind::Opdrachtbon,
        ];
    }

    public function filename(WorkTicket $ticket): string
    {
        $ticket->loadMissing(['worker', 'project']);

        $parts = [
            $ticket->kind->label(),
            $this->recipientName($ticket),
            $ticket->project?->workCode(),
            $ticket->project?->workNumber(),
            $ticket->number,
        ];

        $safe = collect($parts)
            ->map(fn (mixed $part): string => $this->safeSegment((string) $part))
            ->filter()
            ->implode('_');

        return ($safe !== '' ? $safe : 'bon').'.pdf';
    }

    /**
     * @return list<string>
     */
    public function colleagueNames(WorkTicket $ticket): array
    {
        $ticket->loadMissing(['assignment.crewMembers', 'worker']);

        $own = array_values(array_filter([
            $ticket->worker?->planName(),
            ...($ticket->assignment?->presentNames() ?? []),
        ], fn (?string $name): bool => filled($name)));

        $others = WorkerAssignment::query()
            ->with(['worker', 'crewMembers'])
            ->where('project_id', $ticket->project_id)
            ->where('worker_id', '!=', $ticket->worker_id)
            ->whereDate('end_date', '>=', $ticket->start_date)
            ->whereDate('start_date', '<=', $ticket->end_date)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return $others
            ->flatMap(function (WorkerAssignment $row): array {
                $names = $row->presentNames();
                if ($names !== []) {
                    return $names;
                }

                $team = trim((string) ($row->worker?->planName() ?? ''));

                return $team !== '' ? [$team] : [];
            })
            ->filter(fn (string $name): bool => $name !== '' && ! in_array($name, $own, true))
            ->unique()
            ->values()
            ->all();
    }

    private function recipientName(WorkTicket $ticket): string
    {
        $worker = $ticket->worker;
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

    private function publicImagePath(string $relative): ?string
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

    private function storedImagePath(ProjectDocument $document): ?string
    {
        if (! $document->isImage() || $document->file_path === '') {
            return null;
        }

        $absolute = Storage::disk('local')->path($document->file_path);
        if (! is_file($absolute)) {
            return null;
        }

        return 'file://'.str_replace('\\', '/', $absolute);
    }
}
