<?php

namespace App\Http\Controllers;

use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\WorkActivity;
use App\Models\WorkActivityCategory;
use App\Services\ShopWorkService;
use App\Support\Format;
use App\Support\PlanningWeek;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShopProjectController extends Controller
{
    public function create(): View
    {
        Gate::authorize('create', Project::class);

        return view('projects.winkel-create', [
            'project' => new Project(['kind' => ProjectKind::Winkel]),
            'categories' => WorkActivityCategory::formCatalog(),
            'selectedIds' => collect(old('work_activity_ids', [])),
            'activityNotes' => old('activity_notes', []),
            'activityQuantities' => old('activity_quantities', []),
            'activityUnits' => old('activity_units', []),
            'activityHours' => old('activity_hours', []),
            'hourlyRate' => old('basis_uurtarief', SmallWorkType::HOURLY_RATE),
            'maxFileMegabytes' => (int) (config('filesystems.project_file_max_kilobytes') / 1024),
        ]);
    }

    public function store(Request $request, ShopWorkService $shopWork): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $data = $this->validated($request);

        $project = $shopWork->create($data, $request->user(), $request->file('attachments', []) ?: []);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Winkelwerk aangemaakt.');
    }

    public function update(Request $request, Project $project, ShopWorkService $shopWork): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->isWinkel(), 404);
        $data = $this->validated($request, $project);

        $shopWork->update($project, $data, $request->user(), $request->file('attachments', []) ?: []);

        if ($request->hasAny(['start_year', 'start_week', 'klaar_year', 'klaar_week', 'start_date', 'klaar_date'])) {
            $project->applyPlanningWindow($data);
        }

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Winkelwerk opgeslagen.');
    }

    public function storeAttachments(Request $request, Project $project, ShopWorkService $shopWork): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->isWinkel(), 404);
        $request->validate($this->attachmentRules(required: true), $this->messages());

        $shopWork->storeFiles($project, $request->file('attachments', []) ?: [], $request->user());

        return back()->with('status', 'Bijlagen toegevoegd.');
    }

    public function destroyAttachment(Project $project, ProjectDocument $document, ShopWorkService $shopWork): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->isWinkel(), 404);
        abort_unless((int) $document->project_id === (int) $project->id, 404);
        abort_unless($document->document_type === ShopWorkService::ATTACHMENT_TYPE, 404);

        $path = $document->file_path;
        $shopWork->deleteAttachment($project, $document);
        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
        }

        return back()->with('status', 'Bijlage verwijderd.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Project $project = null): array
    {
        $this->normalizeDecimalMaps($request, ['activity_quantities', 'activity_hours']);
        $this->normalizeHourlyRate($request);
        $maxKb = (int) config('filesystems.project_file_max_kilobytes');
        $allowedIds = $this->allowedActivityIds($project);
        $shopUnits = array_map(fn (WorkUnit $unit): string => $unit->value, WorkUnit::shopCases());
        $validator = Validator::make($request->all(), [
            'customer_name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'work_description' => ['nullable', 'string', 'max:5000'],
            'work_activity_ids' => ['required', 'array', 'min:1'],
            'work_activity_ids.*' => ['integer', Rule::exists('work_activities', 'id')->where(fn ($query) => $query->whereIn('id', $allowedIds))],
            'activity_notes' => ['nullable', 'array'],
            'activity_notes.*' => ['nullable', 'string', 'max:500'],
            'activity_quantities' => ['nullable', 'array'],
            'activity_quantities.*' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'activity_hours' => ['nullable', 'array'],
            'activity_hours.*' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'activity_units' => ['nullable', 'array'],
            'activity_units.*' => ['nullable', Rule::in($shopUnits)],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['file', 'max:'.$maxKb, 'mimes:jpg,jpeg,png,webp,gif,pdf', 'extensions:jpg,jpeg,png,webp,gif,pdf'],
            'basis_uurtarief' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            ...PlanningWeek::rules(),
        ], $this->messages());
        $validator->after(fn ($weekValidator) => PlanningWeek::validateOrder(
            $weekValidator,
            $project?->planned_start_date,
            $project?->planned_end_date,
        ));
        $data = $validator->validate();
        if (($data['basis_uurtarief'] ?? null) === null) {
            $data['basis_uurtarief'] = SmallWorkType::HOURLY_RATE;
        }

        return $project === null ? PlanningWeek::applyTo($data) : $data;
    }

    /**
     * @return list<int>
     */
    private function allowedActivityIds(?Project $project): array
    {
        $ids = WorkActivity::query()->active()->pluck('id');
        if ($project) {
            $ids = $ids->merge($project->workActivities()->pluck('work_activities.id'));
        }

        return $ids->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
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

    private function normalizeHourlyRate(Request $request): void
    {
        if (! $request->exists('basis_uurtarief')) {
            return;
        }

        $value = Format::decimalInput($request->input('basis_uurtarief'));
        $request->merge([
            'basis_uurtarief' => $value === '' ? null : $value,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function attachmentRules(bool $required = false): array
    {
        $maxKb = (int) config('filesystems.project_file_max_kilobytes');

        return [
            'attachments' => [$required ? 'required' : 'nullable', 'array', 'max:20'],
            'attachments.*' => ['file', 'max:'.$maxKb, 'mimes:jpg,jpeg,png,webp,gif,pdf', 'extensions:jpg,jpeg,png,webp,gif,pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'customer_name.required' => 'Vul een klantnaam in.',
            'contact_email.email' => 'Vul een geldig e-mailadres in.',
            'work_activity_ids.required' => 'Kies minstens één werkzaamheid.',
            'work_activity_ids.min' => 'Kies minstens één werkzaamheid.',
            'work_activity_ids.*.exists' => 'Deze werkzaamheid is niet beschikbaar.',
            'activity_quantities.*.numeric' => 'Vul een geldig aantal in.',
            'activity_hours.*.numeric' => 'Vul geldige begrote uren in.',
            'activity_hours.*.min' => 'Begrote uren kunnen niet lager zijn dan 0.',
            'activity_units.*.in' => 'Kies m², m¹ of stuks.',
            'basis_uurtarief.min' => 'Het uurtarief kan niet lager zijn dan 0.',
            'basis_uurtarief.numeric' => 'Vul een geldig uurtarief in.',
            'attachments.required' => 'Kies minstens één bestand.',
            'attachments.*.mimes' => 'Alleen foto’s, PDF of tekeningen (JPG, PNG, WebP, GIF, PDF) zijn toegestaan.',
            'attachments.*.extensions' => 'Alleen foto’s, PDF of tekeningen (JPG, PNG, WebP, GIF, PDF) zijn toegestaan.',
            ...PlanningWeek::messages(),
        ];
    }
}
