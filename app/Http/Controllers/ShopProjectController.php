<?php

namespace App\Http\Controllers;

use App\Enums\MeasurementMaterialLocation;
use App\Enums\ProjectKind;
use App\Enums\SmallWorkType;
use App\Enums\WorkUnit;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\WorkActivity;
use App\Models\WorkActivityCategory;
use App\Models\Worker;
use App\Services\MeasurementFormService;
use App\Services\PlanningFitService;
use App\Services\ShopWorkService;
use App\Support\Format;
use App\Support\PlanningWeek;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShopProjectController extends Controller
{
    public function create(Request $request, PlanningFitService $fit, MeasurementFormService $measurements): View
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
            ...$this->preferredWorkerView($request, $fit),
            ...$measurements->viewData(),
        ]);
    }

    public function store(Request $request, ShopWorkService $shopWork, PlanningFitService $fit, MeasurementFormService $measurements): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $data = $this->validated($request, null, $fit, $measurements);

        $project = $shopWork->create($data, $request->user(), $request->file('attachments', []) ?: []);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Winkelwerk aangemaakt.');
    }

    public function update(Request $request, Project $project, ShopWorkService $shopWork, PlanningFitService $fit, MeasurementFormService $measurements): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->isWinkel(), 404);
        $data = $this->validated($request, $project, $fit, $measurements);

        if ($request->hasAny(['start_year', 'start_week', 'klaar_year', 'klaar_week', 'start_date', 'klaar_date'])) {
            $project->applyPlanningWindow($data);
        }

        $shopWork->update($project, $data, $request->user(), $request->file('attachments', []) ?: []);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Winkelwerk opgeslagen.');
    }

    public function availableWorkers(Request $request, PlanningFitService $fit): JsonResponse
    {
        Gate::authorize('create', Project::class);
        $data = $request->validate([
            'start_date' => ['nullable', 'date', 'required_with:end_date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'required_with:start_date'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        $project = isset($data['project_id'])
            ? Project::query()->find($data['project_id'])
            : null;
        if ($project instanceof Project) {
            Gate::authorize('update', $project);
            abort_unless($project->isWinkel(), 404);
        }

        $start = filled($data['start_date'] ?? null) ? Carbon::parse($data['start_date']) : null;
        $end = filled($data['end_date'] ?? null) ? Carbon::parse($data['end_date']) : null;

        return response()->json([
            'workers' => $fit->shopCandidates($start, $end, $project?->id),
        ]);
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

    public function measurementPdf(Project $project, MeasurementFormService $measurements): Response
    {
        Gate::authorize('view', $project);
        abort_unless($project->isWinkel(), 404);
        $project->load(['customer', 'measurementForm.meter', 'measurementForm.rows']);
        $data = $measurements->pdfData($project);
        abort_if($data === null, 404);

        $pdf = Pdf::loadView('measurement-forms.pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans');
        $pdf->addInfo([
            'Title' => $data['documentTitle'],
            'Author' => $data['companyName'],
        ]);

        return $pdf->download($data['filename']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Project $project = null, ?PlanningFitService $fit = null, ?MeasurementFormService $measurements = null): array
    {
        $this->normalizeDecimalMaps($request, ['activity_quantities', 'activity_hours']);
        $this->normalizeHourlyRate($request);
        $this->normalizeOrderAmount($request);
        $this->normalizeMeasurement($request);
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
            'order_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'worker_id' => ['nullable', 'integer', Rule::exists('workers', 'id')->where('active', true)],
            ...$this->measurementRules(),
            ...PlanningWeek::rules(),
        ], $this->messages());
        $validator->after(function ($weekValidator) use ($request, $project, $fit, $measurements): void {
            PlanningWeek::validateOrder(
                $weekValidator,
                $project?->planned_start_date,
                $project?->planned_end_date,
            );
            $this->validatePreferredWorker($weekValidator, $request, $project, $fit);
            $measurements?->validateAgainstShopWork($weekValidator, $request, $project);
        });
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

    private function normalizeOrderAmount(Request $request): void
    {
        if (! $request->exists('order_amount')) {
            return;
        }

        $value = Format::decimalInput($request->input('order_amount'));
        $request->merge([
            'order_amount' => $value === '' ? null : $value,
        ]);
    }

    private function normalizeMeasurement(Request $request): void
    {
        $measurement = $request->input('measurement');
        if (! is_array($measurement)) {
            return;
        }

        $rows = $measurement['rows'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[$index]['quantity'] = Format::decimalInput($row['quantity'] ?? null);
            }
            $measurement['rows'] = $rows;
        }

        $request->merge(['measurement' => $measurement]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function measurementRules(): array
    {
        $units = MeasurementFormService::unitValues();

        return [
            'measurement' => ['nullable', 'array'],
            'measurement.meter_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'measurement.ordered_at' => ['nullable', 'date'],
            'measurement.installation_at' => ['nullable', 'date'],
            'measurement.rows' => ['nullable', 'array', 'max:200'],
            'measurement.rows.*.room' => ['nullable', 'string', 'max:120'],
            'measurement.rows.*.product' => ['nullable', 'string', 'max:120'],
            'measurement.rows.*.brand' => ['nullable', 'string', 'max:80'],
            'measurement.rows.*.type' => ['nullable', 'string', 'max:80'],
            'measurement.rows.*.color_number' => ['nullable', 'string', 'max:40'],
            'measurement.rows.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'measurement.rows.*.unit' => ['nullable', Rule::in($units)],
            'measurement.rows.*.underlay' => ['nullable', 'string', 'max:80'],
            'measurement.rows.*.skirting' => ['nullable', 'string', 'max:80'],
            'measurement.rows.*.steps' => ['nullable', 'string', 'max:80'],
            'measurement.rows.*.profile' => ['nullable', 'string', 'max:80'],
            'measurement.rows.*.available_on_site' => ['nullable', 'boolean'],
            'measurement.rows.*.available_location' => ['nullable', 'string', Rule::in(MeasurementMaterialLocation::values())],
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
            'order_amount.min' => 'Het orderbedrag kan niet lager zijn dan 0.',
            'order_amount.numeric' => 'Vul een geldig orderbedrag in.',
            'worker_id.exists' => 'Deze vakman is niet beschikbaar.',
            'measurement.meter_user_id.exists' => 'Deze inmeter is niet beschikbaar.',
            'measurement.ordered_at.date' => 'Vul een geldige besteldatum in.',
            'measurement.installation_at.date' => 'Vul een geldige montagedatum in.',
            'measurement.rows.max' => 'Er zijn te veel inmeetregels.',
            'measurement.rows.*.quantity.numeric' => 'Vul een geldig aantal in bij M1/M2.',
            'measurement.rows.*.unit.in' => 'Kies m¹ of m².',
            'measurement.rows.*.available_location.in' => 'Kies Winkel, Nicon of Klant.',
            'attachments.required' => 'Kies minstens één bestand.',
            'attachments.*.mimes' => 'Alleen foto’s, PDF of tekeningen (JPG, PNG, WebP, GIF, PDF) zijn toegestaan.',
            'attachments.*.extensions' => 'Alleen foto’s, PDF of tekeningen (JPG, PNG, WebP, GIF, PDF) zijn toegestaan.',
            ...PlanningWeek::messages(),
        ];
    }

    /**
     * @return array{
     *     preferredWorkers: list<array{id: int, name: string, selectable: bool, status: string, status_label: string}>,
     *     selectedWorkerId: int|string|null,
     *     multiplePreferredWorkers: bool
     * }
     */
    public function preferredWorkerView(Request $request, PlanningFitService $fit, ?Project $project = null): array
    {
        $assignedIds = collect($project?->assignments ?? [])
            ->pluck('worker_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $multiple = $assignedIds->count() > 1;
        $selected = old('worker_id', $multiple ? null : $assignedIds->first());
        $dates = PlanningWeek::resolve(
            $request->all(),
            $project?->planned_start_date,
            $project?->planned_end_date,
        );
        $start = filled($dates['start']) ? Carbon::parse($dates['start']) : $project?->planned_start_date;
        $end = filled($dates['end']) ? Carbon::parse($dates['end']) : $project?->planned_end_date;

        return [
            'preferredWorkers' => $fit->shopCandidates($start, $end, $project?->id),
            'selectedWorkerId' => $selected,
            'multiplePreferredWorkers' => $multiple,
        ];
    }

    private function validatePreferredWorker(mixed $validator, Request $request, ?Project $project, ?PlanningFitService $fit): void
    {
        if ($fit === null || $validator->errors()->isNotEmpty()) {
            return;
        }

        $workerId = (int) $request->input('worker_id');
        if ($workerId <= 0) {
            return;
        }

        $dates = PlanningWeek::resolve(
            $request->all(),
            $project?->planned_start_date,
            $project?->planned_end_date,
        );
        if ($dates['start'] === null || $dates['end'] === null) {
            $validator->errors()->add('worker_id', 'Kies eerst start- en klaarweek om een vakman te kiezen.');

            return;
        }

        $worker = Worker::query()->find($workerId);
        if (! $worker instanceof Worker) {
            return;
        }

        $message = $fit->shopRejection(
            $worker,
            Carbon::parse($dates['start']),
            Carbon::parse($dates['end']),
            $project?->id,
        );
        if ($message !== null) {
            $validator->errors()->add('worker_id', $message);
        }
    }
}
