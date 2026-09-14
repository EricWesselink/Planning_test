<?php

namespace App\Services;

use App\Enums\WorkTicketKind;
use App\Models\AreaDrawingMarker;
use App\Models\ProjectArea;
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
     *     drawingUrl: ?string,
     *     drawingIsPdf: bool,
     *     drawingIsImage: bool,
     *     drawingName: ?string,
     *     floorLayers: list<array{name: string, rooms: string, page: int, pins: list<array{x: float, y: float, label: string}>}>,
     *     colleagues: list<string>,
     *     showPrices: bool
     * }
     */
    public function build(WorkTicket $ticket, bool $showPrices): array
    {
        $ticket->loadMissing([
            'worker',
            'project.customer',
            'project.documents',
            'lines.workItem',
            'areas.floor',
            'areas.markers',
            'floors',
            'documents',
            'assignment.crewMembers',
        ]);

        $project = $ticket->project;
        $logoRelative = $project?->issuerLogo() ?? (string) config('company.logo');
        $drawing = $this->drawingFor($ticket);
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
            'drawingUrl' => $project !== null && $drawing !== null
                ? route('projects.documents.show', [$project, $drawing], false)
                : null,
            'drawingIsPdf' => (bool) $drawing?->isPdf(),
            'drawingIsImage' => (bool) $drawing?->isImage(),
            'drawingName' => $drawing !== null ? (string) $drawing->original_filename : null,
            'floorLayers' => $this->floorLayers($ticket, $drawing),
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

    /**
     * @return list<array{name: string, rooms: string, page: int, pins: list<array{x: float, y: float, label: string}>}>
     */
    private function floorLayers(WorkTicket $ticket, ?ProjectDocument $drawing): array
    {
        if ($drawing === null) {
            return [];
        }

        $pageByFloor = [];
        foreach ($ticket->areas as $area) {
            $marker = $this->markerFor($area, $drawing);
            $page = (int) ($marker?->page ?? 0);
            if ($page < 1) {
                continue;
            }
            $floorId = (int) ($area->project_floor_id ?? 0);
            $pageByFloor[$floorId][$page] = ($pageByFloor[$floorId][$page] ?? 0) + 1;
        }

        $grouped = [];
        foreach ($ticket->areas as $area) {
            $marker = $this->markerFor($area, $drawing);
            $page = $this->pageForArea($area, $marker, $pageByFloor);
            $grouped[$page] ??= [
                'floors' => [],
                'rooms' => [],
                'pins' => [],
            ];
            $floorName = trim((string) ($area->floor?->name ?? ''));
            $grouped[$page]['floors'][$floorName !== '' ? $floorName : 'Overige ruimtes'] = true;
            $grouped[$page]['rooms'][] = $area->label();
            $pin = $this->pinFor($area, $marker);
            if ($pin !== null) {
                $grouped[$page]['pins'][] = $pin;
            }
        }

        if ($grouped === []) {
            $name = $ticket->floorsLabel();

            return [[
                'name' => $name !== '' ? $name : 'Plattegrond',
                'rooms' => $ticket->roomsLabel(),
                'page' => 1,
                'pins' => [],
            ]];
        }

        ksort($grouped);

        return collect($grouped)
            ->map(function (array $group, int $page): array {
                return [
                    'name' => implode(', ', array_keys($group['floors'])),
                    'rooms' => implode(', ', array_values(array_unique($group['rooms']))),
                    'page' => $page,
                    'pins' => $group['pins'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<int, int>>  $pageByFloor
     */
    private function pageForArea(ProjectArea $area, ?AreaDrawingMarker $marker, array $pageByFloor): int
    {
        $page = (int) ($marker?->page ?? 0);
        if ($page > 0) {
            return $page;
        }

        $counts = $pageByFloor[(int) ($area->project_floor_id ?? 0)] ?? [];
        if ($counts === []) {
            return 1;
        }
        arsort($counts);

        return (int) array_key_first($counts);
    }

    private function markerFor(ProjectArea $area, ProjectDocument $drawing): ?AreaDrawingMarker
    {
        $markers = $area->markers;
        if ($markers->isEmpty()) {
            return null;
        }

        return $markers->firstWhere('project_document_id', $drawing->id) ?? $markers->first();
    }

    /**
     * @return array{x: float, y: float, label: string}|null
     */
    private function pinFor(ProjectArea $area, ?AreaDrawingMarker $marker): ?array
    {
        $box = $marker?->focusBox();
        if ($box === null) {
            return null;
        }

        $label = trim((string) ($area->displayNumber() ?: $area->label()));
        if ($label === '') {
            return null;
        }

        return [
            'x' => round($box['x'] + ($box['w'] / 2), 6),
            'y' => round($box['y'] + ($box['h'] / 2), 6),
            'label' => $label,
        ];
    }

    private function drawingFor(WorkTicket $ticket): ?ProjectDocument
    {
        $attached = $ticket->documents->first(
            fn (ProjectDocument $document): bool => $document->isPdf() || $document->isImage()
        );
        if ($attached !== null) {
            return $attached;
        }

        return $ticket->project?->plattegrond();
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
