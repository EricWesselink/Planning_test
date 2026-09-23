<?php

namespace App\Http\Controllers;

use App\Enums\ContactRole;
use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\WorkActivity;
use App\Models\WorkActivityCategory;
use App\Models\Worker;
use App\Models\WorkItem;
use App\Services\SmallWorkService;
use App\Services\WorkTicketPdfService;
use App\Support\Format;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SmallWorkController extends Controller
{
    public function create(Request $request, SmallWorkService $smallWork): View
    {
        Gate::authorize('create', Project::class);
        $smallWork->restoreReturnedForm($request);

        return view('projects.small-create', $this->formData($request));
    }

    public function store(Request $request, SmallWorkService $smallWork): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $data = $this->validated($request);
        $type = SmallWorkType::from($data['type']);

        if ($type->attachesToExistingProject()) {
            Gate::authorize('update', Project::query()->findOrFail($data['project_id']));
        }

        $files = $type->isStandalone()
            ? ($request->file('attachments', []) ?: [])
            : [];
        $project = $smallWork->create($data, $request->user(), $files);

        return redirect()
            ->route('planning', [
                'week' => Carbon::parse($data['date'])->startOfWeek(Carbon::MONDAY)->toDateString(),
                'project_id' => $project->id,
            ])
            ->with('status', $type->label().' ingepland.');
    }

    public function update(Request $request, Project $project, SmallWorkService $smallWork): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->isSmallWork(), 404);
        $data = $this->validatedUpdate($request, $project);
        $smallWork->update($project, $data, $request->user(), $request->file('attachments', []) ?: []);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Klein werk opgeslagen.');
    }

    public function storeAttachments(Request $request, Project $project, SmallWorkService $smallWork): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->isSmallWork(), 404);
        $request->validate($this->attachmentRules(required: true), $this->messages());

        $smallWork->storeFiles($project, $request->file('attachments', []) ?: [], $request->user());

        return back()->with('status', 'Tekening opgeslagen.');
    }

    public function destroyAttachment(Project $project, ProjectDocument $document, SmallWorkService $smallWork): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->isSmallWork(), 404);
        abort_unless((int) $document->project_id === (int) $project->id, 404);
        abort_unless(in_array($document->document_type, [SmallWorkService::ATTACHMENT_TYPE, 'plattegrond'], true), 404);

        $path = $document->file_path;
        $smallWork->deleteAttachment($project, $document);
        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
        }

        return back()->with('status', 'Tekening verwijderd.');
    }

    public function preview(Request $request, SmallWorkService $smallWork, WorkTicketPdfService $pdfs): View
    {
        Gate::authorize('create', Project::class);
        $data = $this->validated($request);
        $this->authorizeLinkedProject($data);

        return $this->renderPreview(
            $smallWork,
            $request,
            $smallWork->draft($data),
            $pdfs,
            route('projects.small.preview.pdf'),
            $data,
            route('projects.small.create', ['terug' => 1]),
        );
    }

    public function previewPdf(Request $request, SmallWorkService $smallWork, WorkTicketPdfService $pdfs): Response
    {
        Gate::authorize('create', Project::class);
        $data = $this->validated($request);
        $this->authorizeLinkedProject($data);
        $smallWork->rememberReturnedForm($request);

        return $this->pdfResponse($pdfs->buildForSmallWork($smallWork->draft($data)));
    }

    public function previewSaved(Request $request, Project $project, SmallWorkService $smallWork, WorkTicketPdfService $pdfs): View
    {
        Gate::authorize('update', $project);
        abort_unless($project->isSmallWork(), 404);
        $data = $this->validatedUpdate($request, $project);

        return $this->renderPreview(
            $smallWork,
            $request,
            $smallWork->draft($data, $project),
            $pdfs,
            route('projects.small.preview.saved.pdf', $project),
            $data,
            route('projects.show', ['project' => $project, 'terug' => 1]),
        );
    }

    public function previewSavedPdf(Request $request, Project $project, SmallWorkService $smallWork, WorkTicketPdfService $pdfs): Response
    {
        Gate::authorize('update', $project);
        abort_unless($project->isSmallWork(), 404);
        $data = $this->validatedUpdate($request, $project);
        $smallWork->rememberReturnedForm($request);

        return $this->pdfResponse($pdfs->buildForSmallWork($smallWork->draft($data, $project)));
    }

    public function werkbon(Project $project, WorkTicketPdfService $pdfs): View
    {
        Gate::authorize('view', $project);
        abort_unless($project->isSmallWork(), 404);

        return view('work-tickets.small', [
            ...$pdfs->buildForSmallWork($project, embedDrawings: false),
            'project' => $project,
        ]);
    }

    public function werkbonPdf(Project $project, WorkTicketPdfService $pdfs): Response
    {
        Gate::authorize('view', $project);
        abort_unless($project->isSmallWork(), 404);

        return $this->pdfResponse($pdfs->buildForSmallWork($project));
    }

    public function editExtra(Project $project, WorkItem $extraWerk): View
    {
        Gate::authorize('view', $project);
        abort_unless($extraWerk->isExtraWork(), 404);

        $extraWerk->load(['assignments.worker', 'progressEntries']);
        $actualHours = (float) $extraWerk->progressEntries->sum('worked_hours');

        return view('projects.extra-edit', [
            'project' => $project,
            'item' => $extraWerk,
            'hourOptions' => [2, 4, 6, 8],
            'lines' => old('lines', $extraWerk->extraLinesForForm()),
            'actualHours' => $actualHours > 0.0001 ? $actualHours : null,
            'canUpdate' => (auth()->user()?->can('update', $project) || auth()->user()?->canEnterProgress()) ?? false,
        ]);
    }

    public function updateExtra(Request $request, Project $project, WorkItem $extraWerk, SmallWorkService $smallWork): RedirectResponse
    {
        Gate::authorize('view', $project);
        abort_unless($extraWerk->isExtraWork(), 404);
        abort_unless(
            $request->user()?->can('update', $project) || $request->user()?->canEnterProgress(),
            403
        );

        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'klaar_date' => ['nullable', 'date', 'after_or_equal:date'],
            'hours' => ['required', 'numeric', Rule::in([2, 4, 6, 8])],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'actual_hours' => ['nullable', 'numeric', 'min:0'],
            'completed_quantity' => ['nullable', 'numeric', 'min:0'],
            ...$this->lineRules(),
        ], $this->messages());

        $smallWork->updateAttached($extraWerk, $data, $request->user());

        return redirect()
            ->route('planning', [
                'week' => Carbon::parse($data['date'])->startOfWeek(Carbon::MONDAY)->toDateString(),
                'project_id' => $project->id,
            ])
            ->with('status', 'Extra werk opgeslagen.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function authorizeLinkedProject(array $data): void
    {
        $type = SmallWorkType::tryFrom((string) ($data['type'] ?? ''));
        if ($type?->attachesToExistingProject()) {
            Gate::authorize('update', Project::query()->findOrFail($data['project_id']));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderPreview(
        SmallWorkService $smallWork,
        Request $request,
        Project $project,
        WorkTicketPdfService $pdfs,
        string $pdfAction,
        array $data,
        string $backUrl,
    ): View {
        $smallWork->rememberReturnedForm($request);

        return view('work-tickets.small', [
            ...$pdfs->buildForSmallWork($project, embedDrawings: false),
            'project' => $project,
            'preview' => true,
            'backUrl' => $backUrl,
            'pdfAction' => $pdfAction,
            'pdfFields' => $this->previewFields($data),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pdfResponse(array $data): Response
    {
        $pdf = Pdf::loadView('work-tickets.pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');
        $pdf->addInfo([
            'Title' => $data['documentTitle'].' '.$data['number'],
            'Author' => $data['companyName'],
        ]);

        return $pdf->download($data['filename']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{name: string, value: string}>
     */
    private function previewFields(array $data): array
    {
        $fields = [];
        $walk = function (mixed $value, string $name) use (&$walk, &$fields): void {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $child = $name === '' ? (string) $key : $name.'['.$key.']';
                    $walk($item, $child);
                }

                return;
            }
            if ($value === null || is_object($value)) {
                return;
            }
            $fields[] = ['name' => $name, 'value' => (string) $value];
        };
        foreach ($data as $key => $value) {
            $walk($value, (string) $key);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request): array
    {
        return [
            'types' => SmallWorkType::cases(),
            'selectedType' => old('type', $request->string('type')->toString() ?: SmallWorkType::Service->value),
            'parentProjects' => Project::query()
                ->accessibleBy($request->user())
                ->active()
                ->with('customer')
                ->whereNotIn('kind', array_map(fn (ProjectKind $kind): string => $kind->value, ProjectKind::smallWorkCases()))
                ->orderBy('project_number')
                ->get(),
            'workers' => Worker::query()
                ->where('active', true)
                ->withLogin()
                ->orderBy('name')
                ->get(),
            'hourOptions' => [2, 4, 6, 8],
            'maxFileMegabytes' => (int) (config('filesystems.project_file_max_kilobytes') / 1024),
            'contactRoles' => Project::contactRoleChoices(),
            'lines' => old('lines', $this->defaultExtraLines()),
            ...$this->kleinActivityForm(
                old('work_activity_ids', []),
                old('activity_quantities', []),
                old('activity_notes', []),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $this->normalizeDecimalMaps($request, ['activity_quantities']);
        $type = SmallWorkType::tryFrom((string) $request->input('type'));
        $standalone = $type?->isStandalone() ?? true;

        return $request->validate([
            'type' => ['required', Rule::enum(SmallWorkType::class)],
            'customer_name' => [$standalone ? 'required' : 'nullable', 'string', 'max:255'],
            'project_id' => [
                $standalone ? 'nullable' : 'required',
                'integer',
                Rule::exists('projects', 'id')->where(function ($query) use ($request): void {
                    $query->whereNull('archived_at')
                        ->whereNotIn('kind', array_map(
                            fn (ProjectKind $kind): string => $kind->value,
                            ProjectKind::smallWorkCases()
                        ));
                    $user = $request->user();
                    if ($user !== null && ! $user->can_access_all_projects && ! $user->isVakman()) {
                        $query->whereHas('users', fn ($users) => $users->where('users.id', $user->id));
                    }
                }),
            ],
            'description' => ['required', 'string', 'max:255'],
            'work_address' => ['nullable', 'string', 'max:1000'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'location' => [Rule::requiredIf(fn () => $standalone && ! filled($request->input('work_address'))), 'nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'klaar_date' => ['nullable', 'date', 'after_or_equal:date'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'hours' => ['required', 'numeric', Rule::in([2, 4, 6, 8])],
            'worker_id' => ['nullable', 'integer', 'exists:workers,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'work_number' => ['nullable', 'string', 'max:64', Rule::unique('projects', 'project_number')],
            ...$this->contactRules(),
            ...$this->lineRules(),
            ...$this->activityRules(),
            ...$this->attachmentRules(),
        ], $this->messages());
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedUpdate(Request $request, Project $project): array
    {
        $this->normalizeDecimalMaps($request, ['activity_quantities']);

        return $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'work_address' => ['nullable', 'string', 'max:1000'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'location' => [Rule::requiredIf(fn () => ! filled($request->input('work_address'))), 'nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'hours' => ['required', 'numeric', Rule::in([2, 4, 6, 8])],
            'work_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('projects', 'project_number')->ignore($project->id),
            ],
            ...$this->contactRules(),
            ...$this->activityRules($project),
            ...$this->attachmentRules(),
        ], $this->messages());
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'type.required' => 'Kies het type werk.',
            'customer_name.required' => 'Vul een klantnaam in.',
            'project_id.required' => 'Kies een bestaand opdrachtnummer.',
            'project_id.exists' => 'Dit project is niet beschikbaar.',
            'description.required' => 'Vul een korte omschrijving in.',
            'location.required' => 'Vul een plaats in.',
            'date.required' => 'Kies een datum.',
            'klaar_date.after_or_equal' => 'Klaar moet op dezelfde dag of later vallen dan de startdatum.',
            'hours.required' => 'Kies de geplande uren.',
            'hours.in' => 'Kies 2, 4, 6 of 8 uur.',
            'work_number.unique' => 'Dit werknummer bestaat al.',
            'contact_role_custom.required_if' => 'Vul de nieuwe rol in.',
            'attachments.required' => 'Kies minstens één bestand.',
            'attachments.*.mimes' => 'Alleen foto’s of PDF (JPG, PNG, WebP, GIF, BMP, PDF) zijn toegestaan.',
            'attachments.*.extensions' => 'Alleen foto’s of PDF (JPG, PNG, WebP, GIF, BMP, PDF) zijn toegestaan.',
            'work_activity_ids.*.exists' => 'Deze werkzaamheid is niet beschikbaar.',
            'activity_quantities.*.numeric' => 'Vul een geldige hoeveelheid in.',
            'activity_notes.*.max' => 'De omschrijving mag maximaal 500 tekens zijn.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactRules(): array
    {
        return [
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contact_role' => ['nullable', 'string', 'max:32'],
            'contact_role_custom' => ['nullable', 'required_if:contact_role,'.ContactRole::CUSTOM, 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function attachmentRules(bool $required = false): array
    {
        $maxKb = (int) config('filesystems.project_file_max_kilobytes');

        return [
            'attachments' => [$required ? 'required' : 'nullable', 'array', 'max:20'],
            'attachments.*' => ['file', 'max:'.$maxKb, 'mimes:jpg,jpeg,png,webp,gif,bmp,pdf', 'extensions:jpg,jpeg,png,webp,gif,bmp,pdf'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function lineRules(): array
    {
        return [
            'lines' => ['nullable', 'array', 'max:20'],
            'lines.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.completed' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityRules(?Project $project = null): array
    {
        $keep = $project?->workActivities()
            ->pluck('work_activities.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all() ?? [];
        $allowed = WorkActivityCategory::floorFormActivities($keep)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        if ($allowed === []) {
            $allowed = [0];
        }

        return [
            'work_activity_ids' => ['nullable', 'array'],
            'work_activity_ids.*' => [
                'integer',
                Rule::exists('work_activities', 'id')->where(fn ($query) => $query->whereIn('id', $allowed)),
            ],
            'activity_quantities' => ['nullable', 'array'],
            'activity_quantities.*' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'activity_notes' => ['nullable', 'array'],
            'activity_notes.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  iterable<int|string>  $selectedIds
     * @return array{
     *     floorActivities: Collection<int, WorkActivity>,
     *     selectedIds: list<int>,
     *     activityQuantities: array<int|string, mixed>,
     *     activityNotes: array<int|string, mixed>
     * }
     */
    private function kleinActivityForm(iterable $selectedIds, mixed $quantities, mixed $notes = []): array
    {
        $ids = collect($selectedIds)->map(fn (mixed $id): int => (int) $id)->all();
        $quantityMap = is_array($quantities) ? $quantities : [];
        $noteMap = is_array($notes) ? $notes : [];
        [$formIds, $formQuantities, $formNotes] = WorkActivityCategory::kleinFormSelection($ids, $quantityMap, $noteMap);

        return [
            'floorActivities' => WorkActivityCategory::kleinFormActivities($ids),
            'selectedIds' => $formIds,
            'activityQuantities' => $formQuantities,
            'activityNotes' => $formNotes,
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    private function normalizeDecimalMaps(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            $values = $request->input($key);
            if (! is_array($values)) {
                continue;
            }

            $request->merge([
                $key => collect($values)
                    ->map(fn (mixed $value): mixed => Format::decimalInput($value))
                    ->all(),
            ]);
        }
    }

    /**
     * @return list<array{name: string, quantity: string, completed: string}>
     */
    private function defaultExtraLines(): array
    {
        return [
            ['name' => 'Egaliseren', 'quantity' => '', 'completed' => ''],
            ['name' => 'Materiaal', 'quantity' => '', 'completed' => ''],
            ['name' => '', 'quantity' => '', 'completed' => ''],
        ];
    }
}
