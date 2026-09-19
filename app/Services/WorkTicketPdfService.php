<?php

namespace App\Services;

use App\Enums\WorkTicketKind;
use App\Enums\WorkUnit;
use App\Models\AreaDrawingMarker;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectDocument;
use App\Models\WorkActivity;
use App\Models\WorkerAssignment;
use App\Models\WorkTicket;
use App\Support\Format;
use Illuminate\Support\Facades\Storage;

class WorkTicketPdfService
{
    /**
     * @var array<string, ?string>
     */
    private array $layerImages = [];

    public function __construct(
        private MeasurementFormService $measurements,
    ) {}

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
     *     foreman: ?string,
     *     workTicketHolder: ?string,
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
     *     number: string,
     *     drawingRender: string,
     *     floorLayers: list<array{name: string, rooms: string, page: int, image: ?string, pins: list<array{x: float, y: float, label: string}>}>,
     *     colleagues: list<string>,
     *     showPrices: bool
     * }
     */
    public function build(WorkTicket $ticket, bool $showPrices, bool $embedDrawings = true, bool $includeMeasurementForm = false): array
    {
        $ticket->loadMissing([
            'worker',
            'project.customer',
            'project.documents',
            'project.measurementForm.meter',
            'project.measurementForm.rows',
            'lines.workItem',
            'areas.floor',
            'areas.markers',
            'floors',
            'documents',
            'assignment.crewMembers',
            'assignment.worker.crewPeople',
            'assignment.foreman',
            'assignment.workTicketHolder',
            'worker.crewPeople',
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
                'path' => $embedDrawings ? $this->storedImagePath($document) : null,
                'is_image' => $document->isImage(),
            ])
            ->values()
            ->all();

        return [
            'ticket' => $ticket,
            'filename' => $this->filename($ticket),
            'documentTitle' => mb_strtoupper($ticket->kind->label()),
            'kindLabel' => $ticket->kind->label(),
            'number' => $ticket->number,
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
            'recipientKind' => $ticket->worker?->employment_type?->isExternal()
                ? $ticket->worker->employment_type->label()
                : null,
            'foreman' => $ticket->assignment?->foreman?->label(),
            'workTicketHolder' => $ticket->assignment?->workTicketHolder?->label(),
            'whoHeading' => $ticket->worker?->employment_type?->isExternal() ? 'Opdrachtnemer' : 'Vakmannen',
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
            'floorLayers' => $floorLayers = $this->floorLayers($ticket, $drawing, $embedDrawings),
            'drawingRender' => $this->drawingRender($floorLayers, (bool) $drawing?->isPdf()),
            'colleagues' => $this->colleagueNames($ticket),
            'showPrices' => $showPrices && $ticket->kind === WorkTicketKind::Opdrachtbon,
            'includeMeasurementForm' => $includeMeasurementForm && $this->measurements->isFilled($ticket->project?->measurementForm),
            'measurementForm' => ($includeMeasurementForm && $ticket->project instanceof Project)
                ? $this->measurements->pdfData($ticket->project)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForShop(Project $project, bool $embedDrawings = true, bool $includeMeasurementForm = false): array
    {
        $project->loadMissing([
            'customer',
            'workActivities',
            'workItems',
            'documents',
            'assignments.worker',
            'assignments.crewMembers',
            'assignments.foreman',
            'assignments.workTicketHolder',
            'measurementForm.meter',
            'measurementForm.rows',
        ]);

        $logoRelative = $project->issuerLogo();
        $documents = $project->documents
            ->where('document_type', ShopWorkService::ATTACHMENT_TYPE)
            ->values();
        $drawingItems = $documents
            ->map(fn (ProjectDocument $document): array => [
                'name' => (string) $document->original_filename,
                'url' => route('projects.documents.show', [$project, $document]),
                'path' => $embedDrawings ? $this->storedImagePath($document) : null,
                'is_image' => $document->isImage(),
            ])
            ->values()
            ->all();
        $includeMeasurement = $includeMeasurementForm && $this->measurements->isFilled($project->measurementForm);

        return [
            'ticket' => null,
            'filename' => $this->shopFilename($project),
            'documentTitle' => 'WERKBON',
            'kindLabel' => 'Werkbon',
            'number' => $project->workNumber(),
            'issuedOn' => ($project->planned_start_date ?? $project->created_at)?->format('d-m-Y') ?? now()->format('d-m-Y'),
            'logo' => $this->publicImagePath($logoRelative),
            'logoUrl' => asset($logoRelative),
            'companyName' => $project->issuerName(),
            'companyAddress' => (string) config('company.address'),
            'companyPostalCode' => (string) config('company.postal_code'),
            'companyCity' => (string) config('company.city'),
            'companyEmail' => (string) config('company.email'),
            'companyPhone' => (string) config('company.phone'),
            'recipient' => $this->shopVakmanNames($project),
            'recipientKind' => null,
            'foreman' => $this->shopRoleNames($project, 'foreman'),
            'workTicketHolder' => $this->shopRoleNames($project, 'workTicketHolder'),
            'whoHeading' => 'Vakmannen',
            'whenHeading' => 'Wanneer',
            'recipientCompact' => true,
            'projectTitle' => $project->displayTitle(),
            'projectNumber' => $project->workCode(),
            'workNumber' => $project->workNumber(),
            'address' => $project->nawLine(),
            'contactPhone' => $project->contact_phone ?: $project->customer?->phone,
            'contactEmail' => $project->contact_email ?: $project->customer?->email,
            'period' => $this->shopPeriod($project),
            'floors' => '',
            'rooms' => '',
            'rows' => $this->shopRows($project),
            'notesText' => $this->shopNotes($project),
            'drawings' => $documents
                ->map(fn (ProjectDocument $document): string => (string) $document->original_filename)
                ->filter()
                ->values()
                ->all(),
            'drawingItems' => $drawingItems,
            'drawingEmbeds' => $drawingItems,
            'drawingUrl' => null,
            'drawingIsPdf' => false,
            'drawingIsImage' => false,
            'drawingName' => null,
            'floorLayers' => [],
            'drawingRender' => 'image',
            'colleagues' => [],
            'showPrices' => false,
            'includeMeasurementForm' => $includeMeasurement,
            'measurementForm' => $includeMeasurement ? $this->measurements->pdfData($project) : null,
        ];
    }

    public function shopFilename(Project $project): string
    {
        $parts = [
            'Werkbon',
            $project->displayTitle(),
            $project->workNumber(),
        ];
        $safe = collect($parts)
            ->map(fn (mixed $part): string => $this->safeSegment((string) $part))
            ->filter()
            ->implode('_');

        return ($safe !== '' ? $safe : 'werkbon').'.pdf';
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
        $ticket->loadMissing(['assignment.crewMembers', 'assignment.worker.crewPeople', 'worker.crewPeople']);

        $assignmentId = (int) ($ticket->worker_assignment_id ?? $ticket->assignment?->id ?? 0);
        $ownKeys = $this->nameKeys([
            ...($ticket->assignment !== null ? $this->personNamesOnAssignment($ticket->assignment) : []),
            (string) ($ticket->worker?->planName() ?? ''),
            (string) ($ticket->worker?->displayName() ?? ''),
        ]);

        $others = WorkerAssignment::query()
            ->with(['worker.crewPeople', 'crewMembers'])
            ->where('project_id', $ticket->project_id)
            ->when($assignmentId > 0, fn ($query) => $query->where('id', '!=', $assignmentId))
            ->whereDate('end_date', '>=', $ticket->start_date)
            ->whereDate('start_date', '<=', $ticket->end_date)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return $others
            ->flatMap(fn (WorkerAssignment $row): array => $this->personNamesOnAssignment($row))
            ->filter(fn (string $name): bool => $name !== '' && ! $this->nameMatches($name, $ownKeys))
            ->unique(fn (string $name): string => mb_strtolower(trim($name)))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function personNamesOnAssignment(WorkerAssignment $assignment): array
    {
        $present = $assignment->presentNames();
        if ($present !== []) {
            return array_values(array_filter(
                $present,
                fn (string $name): bool => ! $this->looksLikeTeamLabel($name),
            ));
        }

        $worker = $assignment->worker;
        if ($worker === null) {
            return [];
        }

        if ($worker->employment_type?->isExternal()) {
            $company = trim((string) $worker->company);
            if ($company !== '') {
                return [$company];
            }

            $name = trim($worker->displayName());

            return $name !== '' && ! $this->looksLikeTeamLabel($name) ? [$name] : [];
        }

        $fromCrew = ($worker->relationLoaded('crewPeople') ? $worker->activeCrewPeople() : collect())
            ->map(fn (CrewMember $member): string => trim($member->label()))
            ->filter(fn (string $name): bool => $name !== '' && ! $this->looksLikeTeamLabel($name))
            ->values()
            ->all();
        if ($fromCrew !== []) {
            return $fromCrew;
        }

        $personal = trim($worker->displayName());

        return $personal !== '' && ! $this->looksLikeTeamLabel($personal) ? [$personal] : [];
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function nameKeys(array $names): array
    {
        $keys = [];
        foreach ($names as $name) {
            $normalized = mb_strtolower(trim($name));
            if ($normalized === '') {
                continue;
            }
            $keys[] = $normalized;
            $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $first = $parts[0] ?? '';
            if ($first !== '' && $first !== $normalized && ! $this->looksLikeTeamLabel($first)) {
                $keys[] = $first;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<string>  $keys
     */
    private function nameMatches(string $name, array $keys): bool
    {
        foreach ($this->nameKeys([$name]) as $key) {
            if (in_array($key, $keys, true)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeTeamLabel(string $name): bool
    {
        return preg_match('/^team(\s|\d|$)/iu', trim($name)) === 1;
    }

    /**
     * @return list<array{name: string, rooms: string, page: int, image: ?string, pins: list<array{x: float, y: float, label: string}>}>
     */
    private function floorLayers(WorkTicket $ticket, ?ProjectDocument $drawing, bool $embedDrawings): array
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
                'image' => $embedDrawings ? $this->layerImage($drawing, 1) : null,
                'pins' => [],
            ]];
        }

        ksort($grouped);

        return collect($grouped)
            ->map(function (array $group, int $page) use ($drawing, $embedDrawings): array {
                return [
                    'name' => implode(', ', array_keys($group['floors'])),
                    'rooms' => implode(', ', array_values(array_unique($group['rooms']))),
                    'page' => $page,
                    'image' => $embedDrawings ? $this->layerImage($drawing, $page) : null,
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

        if ($worker->employment_type?->isExternal()) {
            return filled($worker->company) ? trim((string) $worker->company) : $worker->displayName();
        }

        $names = $ticket->assignment?->presentNames() ?? [];
        if ($names !== []) {
            return implode(', ', $names);
        }

        return $worker->displayName();
    }

    /**
     * @return list<array{title: string, quantity: string, unit: string}>
     */
    private function shopRows(Project $project): array
    {
        return $project->workActivities
            ->sortBy(fn (WorkActivity $activity): array => [
                (int) ($activity->pivot?->sort_order ?? 0),
                (int) $activity->id,
            ])
            ->map(function (WorkActivity $activity): array {
                $quantity = $activity->pivot?->quantity;
                $unit = $activity->pivot?->unit;
                $hasQuantity = $quantity !== null && (float) $quantity > 0.0001;
                $parts = [];
                if ($hasQuantity) {
                    $qty = Format::qty($quantity, fmod((float) $quantity, 1.0) === 0.0 ? 0 : 2);
                    $label = $unit instanceof WorkUnit ? $unit->label() : '';
                    $parts[] = trim($qty.' '.$label);
                }
                $note = trim((string) ($activity->pivot?->notes ?? ''));
                $title = $activity->name;
                if ($note !== '') {
                    $title .= ' — '.$note;
                }

                return [
                    'title' => $title,
                    'quantity' => implode(' · ', $parts),
                    'unit' => '',
                ];
            })
            ->values()
            ->all();
    }

    private function shopNotes(Project $project): string
    {
        $notes = [];
        if (filled($project->work_description)) {
            $notes[] = trim((string) $project->work_description);
        }
        foreach ($project->workActivities as $activity) {
            $note = trim((string) ($activity->pivot?->notes ?? ''));
            if ($note !== '') {
                $notes[] = $activity->name.': '.$note;
            }
        }

        return implode("\n\n", $notes);
    }

    private function shopVakmanNames(Project $project): string
    {
        $names = [];
        $seen = [];
        foreach ($project->assignments as $assignment) {
            $people = $assignment->presentNames();
            if ($people === []) {
                $label = trim((string) ($assignment->worker?->planName() ?? ''));
                $people = $label === '' ? [] : [$label];
            }
            foreach ($people as $name) {
                $key = mb_strtolower($name);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $names[] = $name;
            }
        }

        return $names === [] ? 'Nog niet ingepland' : implode(', ', $names);
    }

    private function shopRoleNames(Project $project, string $relation): ?string
    {
        $names = $project->assignments
            ->map(function (WorkerAssignment $assignment) use ($relation): string {
                $person = $assignment->{$relation};

                return trim((string) ($person?->label() ?? ''));
            })
            ->filter()
            ->unique()
            ->values();

        return $names->isEmpty() ? null : $names->implode(', ');
    }

    private function shopPeriod(Project $project): string
    {
        $start = $project->assignments->min('start_date') ?? $project->planned_start_date;
        $end = $project->assignments->max('end_date') ?? $project->planned_end_date;
        if ($start === null && $end === null) {
            return '';
        }
        if ($start !== null && $end !== null && $start->isSameDay($end)) {
            return $start->translatedFormat('j-m-Y');
        }
        if ($start !== null && $end !== null) {
            return $start->translatedFormat('j-m').' t/m '.$end->translatedFormat('j-m-Y');
        }

        return ($start ?? $end)->translatedFormat('j-m-Y');
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

        return $this->embedImage($absolute);
    }

    /**
     * @param  list<array{name: string, rooms: string, page: int, image: ?string, pins: list<array{x: float, y: float, label: string}>}>  $floorLayers
     */
    private function drawingRender(array $floorLayers, bool $drawingIsPdf): string
    {
        if (! $drawingIsPdf) {
            return 'image';
        }

        foreach ($floorLayers as $layer) {
            if ((int) ($layer['page'] ?? 0) > 0 && blank($layer['image'] ?? null)) {
                return 'browser';
            }
        }

        return 'image';
    }

    private function layerImage(ProjectDocument $drawing, int $page): ?string
    {
        $key = $drawing->id.'-'.$page;
        if (array_key_exists($key, $this->layerImages)) {
            return $this->layerImages[$key];
        }

        $stored = $this->storedImagePath($drawing);
        if ($stored !== null) {
            return $this->layerImages[$key] = $stored;
        }

        if (! $drawing->isPdf() || $drawing->file_path === '') {
            return $this->layerImages[$key] = null;
        }

        $absolute = Storage::disk('local')->path($drawing->file_path);

        try {
            return $this->layerImages[$key] = $this->rasterizePdfPage($absolute, $page);
        } catch (\Throwable) {
            return $this->layerImages[$key] = null;
        }
    }

    private function rasterizePdfPage(string $pdfPath, int $page): ?string
    {
        if ($page < 1 || ! is_file($pdfPath) || ! function_exists('exec')) {
            return null;
        }

        $binary = $this->pdftoppmBinary();
        if ($binary === null) {
            return null;
        }

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nicon-ticket-draw-'.bin2hex(random_bytes(6));
        if (! mkdir($dir) && ! is_dir($dir)) {
            return null;
        }

        try {
            $prefix = $dir.DIRECTORY_SEPARATOR.'page';
            $command = escapeshellarg($binary)
                .' -png -r 120 -f '.$page.' -l '.$page.' '
                .escapeshellarg($pdfPath).' '
                .escapeshellarg($prefix);
            if (PHP_OS_FAMILY === 'Windows') {
                $command .= ' 2>NUL';
            } else {
                $command .= ' 2>/dev/null';
            }
            exec($command, $_, $code);
            if ($code !== 0) {
                return null;
            }

            $files = glob($prefix.'-*.png') ?: [];
            $file = $files[0] ?? null;
            if ($file === null || ! is_file($file)) {
                return null;
            }

            return $this->embedImage($file);
        } catch (\Throwable) {
            return null;
        } finally {
            $this->cleanupTempDirectory($dir);
        }
    }

    private function embedImage(string $absolute): ?string
    {
        if (! is_file($absolute)) {
            return null;
        }

        $mime = mime_content_type($absolute) ?: 'image/png';
        if (! str_starts_with($mime, 'image/')) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolute));
    }

    private function pdftoppmBinary(): ?string
    {
        static $binary = false;
        if ($binary !== false) {
            return $binary;
        }

        $fromPath = $this->whichBinary('pdftoppm');
        if ($fromPath !== null) {
            return $binary = $fromPath;
        }

        $wingetRoot = getenv('LOCALAPPDATA');
        if (is_string($wingetRoot) && $wingetRoot !== '') {
            $matches = glob($wingetRoot.'\\Microsoft\\WinGet\\Packages\\*Poppler*\\poppler-*\\Library\\bin\\pdftoppm.exe') ?: [];
            rsort($matches);
            foreach ($matches as $match) {
                if (is_file($match)) {
                    return $binary = $match;
                }
            }
        }

        return $binary = null;
    }

    private function whichBinary(string $name): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        try {
            $output = PHP_OS_FAMILY === 'Windows'
                ? trim((string) shell_exec('where '.escapeshellarg($name).' 2>NUL'))
                : trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));
        } catch (\Throwable) {
            return null;
        }

        if ($output === '') {
            return null;
        }

        $first = explode("\n", str_replace("\r", '', $output))[0] ?? '';
        $first = trim($first);

        return $first !== '' && is_file($first) ? $first : null;
    }

    private function cleanupTempDirectory(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
