<?php

namespace App\Http\Controllers;

use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Models\Project;
use App\Models\Worker;
use App\Models\WorkItem;
use App\Services\SmallWorkService;
use App\Services\WorkTicketPdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SmallWorkController extends Controller
{
    public function create(Request $request): View
    {
        Gate::authorize('create', Project::class);

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

        $project = $smallWork->create($data, $request->user());

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
        $smallWork->update($project, $data);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Klein werk opgeslagen.');
    }

    public function werkbon(Project $project, WorkTicketPdfService $pdfs): View
    {
        Gate::authorize('view', $project);
        abort_unless($project->isSmallWork(), 404);

        return view('work-tickets.small', [
            ...$pdfs->buildForSmallWork($project),
            'project' => $project,
        ]);
    }

    public function werkbonPdf(Project $project, WorkTicketPdfService $pdfs): Response
    {
        Gate::authorize('view', $project);
        abort_unless($project->isSmallWork(), 404);
        $data = $pdfs->buildForSmallWork($project);

        $pdf = Pdf::loadView('work-tickets.pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');
        $pdf->addInfo([
            'Title' => $data['documentTitle'].' '.$data['number'],
            'Author' => $data['companyName'],
        ]);

        return $pdf->download($data['filename']);
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
            'lines' => old('lines', $this->defaultExtraLines()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
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
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'location' => [$standalone ? 'required' : 'nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'klaar_date' => ['nullable', 'date', 'after_or_equal:date'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'hours' => ['required', 'numeric', Rule::in([2, 4, 6, 8])],
            'worker_id' => ['nullable', 'integer', 'exists:workers,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'work_number' => ['nullable', 'string', 'max:64', Rule::unique('projects', 'project_number')],
            ...$this->lineRules(),
        ], $this->messages());
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedUpdate(Request $request, Project $project): array
    {
        return $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'location' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'hours' => ['required', 'numeric', Rule::in([2, 4, 6, 8])],
            'work_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('projects', 'project_number')->ignore($project->id),
            ],
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
