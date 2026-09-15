<?php

namespace App\Http\Controllers;

use App\Enums\ImportDecision;
use App\Enums\ImportDocumentType;
use App\Models\Project;
use App\Services\CalculationImportService;
use App\Services\Meetstaat\ImportClosureEvaluator;
use App\Services\Meetstaat\ImportPreviewBuilder;
use App\Services\Meetstaat\RoomImportAssembler;
use App\Services\ProjectIntakeService;
use App\Services\ScannedDimensions\ScannedDimensionsImportService;
use App\Services\SourceUpdateService;
use App\Support\PlanningWeek;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MeetstaatImportController extends Controller
{
    public function preview(
        Request $request,
        ImportPreviewBuilder $builder,
        ScreenExcelImportController $screens,
        ScannedDimensionsImportController $afmetingen,
        ScannedDimensionsImportService $afmetingenService,
        CalculationImportService $calculationImport,
        SourceUpdateService $updates,
        SourceUpdateController $sourceUpdates,
    ): RedirectResponse {
        Gate::authorize('create', Project::class);
        $maxKilobytes = $this->projectFileMaxKilobytes();
        $request->validate([
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', 'max:'.$maxKilobytes, 'mimes:pdf,csv,txt,xlsx,xlsm,xls', 'extensions:pdf,csv,txt,xlsx,xlsm,xls'],
            'types' => ['nullable', 'array'],
            'types.*' => ['nullable', 'string', 'in:'.implode(',', [...ImportDocumentType::values(), 'unknown'])],
            'meetstaat' => ['nullable', 'file', 'max:'.$maxKilobytes, 'mimes:pdf', 'extensions:pdf'],
            'plattegrond' => ['nullable', 'file', 'max:'.$maxKilobytes, 'mimes:pdf', 'extensions:pdf'],
            'work_type' => ['nullable', 'string', 'max:120'],
            'work_type_custom' => ['nullable', 'string', 'max:120'],
        ], $this->uploadMessages());

        $uploads = $this->incomingUploads($request);
        $extracted = $calculationImport->extract($uploads);
        $uploads = $extracted['remaining'];

        if ($screens->findScreenUpload($uploads) !== null) {
            return $screens->startPreview($uploads);
        }

        // Aparte route: alleen wanneer expliciet Afmetingen (geen vloerbronnen meegenomen).
        $workType = trim((string) $request->input('work_type', ''));
        if ($workType === '__custom__' || $workType === 'anders') {
            $workType = trim((string) $request->input('work_type_custom', ''));
        } elseif ($workType === '') {
            $workType = trim((string) $request->input('work_type_custom', ''));
        }
        if ($afmetingenService->findUploads($uploads) !== null) {
            return $afmetingen->startPreview($uploads, $workType !== '' ? $workType : null);
        }

        try {
            $built = $uploads === [] && $extracted['files'] !== []
                ? $builder->build([])
                : $builder->build($uploads);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors([$this->uploadErrorKey($request) => $e->getMessage()]);
        }

        if ($built['ocrError'] !== null && ($built['preview']['areas'] ?? []) === []) {
            return back()->withErrors([$this->uploadErrorKey($request) => $built['ocrError']]);
        }

        $token = (string) Str::uuid();
        $stored = $this->storePreviewUploads($token, $built['classified']);
        $stored = $this->storeCalculationUploads($token, $stored, $extracted['files']);
        $preview = $calculationImport->attach($built['preview'], $extracted['files']);

        Cache::put('meetstaat.'.$token, [
            'preview' => $preview,
            'file' => $stored['meetstaat'],
            'original' => $stored['meetstaat_original'],
            'plattegrond' => $stored['plattegrond'],
            'plattegrond_original' => $stored['plattegrond_original'],
            'uploads' => $stored['uploads'],
            'extra' => $stored['extra'],
        ], now()->addHours(2));

        $number = $preview['header']['project_number'] ?? $preview['calculation']['work_number'] ?? null;
        $existing = $updates->findProjectByWorkNumber(is_string($number) ? $number : null);
        if ($existing !== null) {
            if (! Gate::allows('update', $existing)) {
                return back()->withErrors([
                    $this->uploadErrorKey($request) => 'Dit werknummer hoort bij een bestaand project waarop je geen toegang hebt.',
                ]);
            }
            $sourceUpdates->cacheExistingPreview($existing, $token, Cache::get('meetstaat.'.$token), $updates);

            return redirect()->route('projects.sources.review', $token);
        }

        return redirect()->route('projects.review', $token);
    }

    public function review(string $token): View
    {
        Gate::authorize('create', Project::class);
        $payload = Cache::get('meetstaat.'.$token);
        abort_unless($payload, 404);

        return view('projects.review', [
            'token' => $token,
            'preview' => $payload['preview'],
            'filename' => $this->reviewFilename($payload),
            'drawing' => $payload['plattegrond_original'] ?? null,
            'hasMeetstaat' => filled($payload['file'] ?? null) || (bool) ($payload['preview']['sources']['meetstaat'] ?? false),
            'uploads' => $payload['uploads'] ?? [],
        ]);
    }

    public function import(Request $request, string $token, ProjectIntakeService $intake, RoomImportAssembler $assembler, CalculationImportService $calculationImport, SourceUpdateService $updates, SourceUpdateController $sourceUpdates): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        logger()->info('import.submit: route bereikt', ['token' => $token]);
        $payload = Cache::get('meetstaat.'.$token);
        abort_unless($payload, 404);

        $validator = Validator::make($request->all(), [
            'customer_name' => ['required', 'string', 'max:255'],
            'project_name' => ['required', 'string', 'max:255'],
            'project_number' => ['nullable', 'string', 'max:64'],
            'date' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:255'],
            ...PlanningWeek::rules(),
            'exclude_works' => ['array'],
            'exclude_works.*' => ['string'],
            'plattegrond' => ['nullable', 'file', 'max:'.$this->projectFileMaxKilobytes(), 'mimes:pdf', 'extensions:pdf'],
            'areas' => ['nullable', 'array'],
            'areas.*.floor' => ['nullable', 'string', 'max:120'],
            'areas.*.room_number' => ['nullable', 'string', 'max:32'],
            'areas.*.room_name' => ['nullable', 'string', 'max:120'],
            'areas.*.square_meters' => ['nullable', 'string', 'max:32'],
            'areas.*.source' => ['nullable', 'string', 'max:32'],
            'areas.*.recognized_via' => ['nullable', 'string', 'max:120'],
            'areas.*.confidence' => ['nullable', 'string', 'max:32'],
            'areas.*.fill_color' => ['nullable', 'string', 'max:16'],
            'areas.*.tasks' => ['nullable', 'array'],
            'areas.*.tasks.*.work_name' => ['nullable', 'string', 'max:255'],
            'areas.*.tasks.*.quantity' => ['nullable', 'string', 'max:32'],
            'areas.*.tasks.*.unit' => ['nullable', 'string', 'max:16'],
            'areas.*.tasks.*.perimeter' => ['nullable', 'string', 'max:32'],
            'areas.*.tasks.*.seams' => ['nullable', 'string', 'max:32'],
            'calculation_labor' => ['nullable', 'array'],
            'calculation_labor.*.work_name' => ['nullable', 'string', 'max:255'],
        ], array_merge($this->uploadMessages(), PlanningWeek::messages()));
        $validator->after(fn ($weekValidator) => PlanningWeek::validateOrder($weekValidator));
        $data = PlanningWeek::applyTo($validator->validate(), $request->input('date'));

        $preview = $payload['preview'];
        $preview['header'] = array_merge([
            'customer_name' => null,
            'reference' => null,
            'project_name' => null,
            'project_number' => null,
            'date' => null,
            'address' => null,
            'postal_code' => null,
            'city' => null,
            'planned_start_date' => null,
            'planned_end_date' => null,
        ], $preview['header'] ?? []);
        $preview['header']['customer_name'] = $data['customer_name'];
        $preview['header']['project_name'] = $data['project_name'];
        // Formulierveld "Project / referentie" is de letterlijke referentie; niet inkorten.
        $preview['header']['reference'] = $data['project_name'];
        $preview['header']['project_number'] = ($data['project_number'] ?? null) ?: ($preview['header']['project_number'] ?? null);
        $preview['header']['date'] = $data['date'] ?? $preview['header']['date'];
        $preview['header']['address'] = $data['address'] ?? $preview['header']['address'] ?? null;
        $preview['header']['postal_code'] = $data['postal_code'] ?? $preview['header']['postal_code'] ?? null;
        $preview['header']['city'] = $data['city'] ?? $preview['header']['city'] ?? null;
        $preview['header']['planned_start_date'] = $data['planned_start_date'] ?? null;
        $preview['header']['planned_end_date'] = $data['planned_end_date'] ?? null;
        $preview = $calculationImport->applyReview($preview, $data['calculation_labor'] ?? []);

        $cachedReady = (bool) ($payload['preview']['import_closure']['ready'] ?? false)
            || ImportDecision::parse((string) ($payload['preview']['import_closure']['decision'] ?? ''))?->allowsImport() === true;

        if ($cachedReady) {
            // READY / READY_WITH_WARNINGS: behoud geassembleerde preview. Geen form-areas (max_input_vars kapt
            // grote projecten af en mag TASK_SOURCE-taken nooit wissen).
            if (! empty($data['exclude_works'])) {
                $preview = $assembler->applyReview(
                    $preview,
                    $preview['areas'] ?? [],
                    $data['exclude_works'] ?? []
                );
            } else {
                $preview = (new ImportClosureEvaluator)->attach($preview);
            }
        } elseif (array_key_exists('areas', $data)) {
            $reviewed = $assembler->applyReview($preview, $data['areas'] ?? [], $data['exclude_works'] ?? []);
            $loss = $assembler->taskSourceLossMeters($payload['preview'], $reviewed);
            if ($loss > 0.05) {
                return redirect()
                    ->route('projects.review', $token)
                    ->withInput()
                    ->with('status', 'Import geblokkeerd: meetstaat-taken gingen verloren in het formulier')
                    ->withErrors([
                        'import_closure' => 'Er gingen '.number_format($loss, 2, ',', '.').' m² geldige meetstaat-taken verloren (waarschijnlijk door formulierlimiet). De opgeslagen preview is niet overschreven — upload opnieuw of importeer vanuit de automatische controle zonder handmatige area-POST.',
                    ]);
            }
            $preview = $reviewed;
        } else {
            $preview = (new ImportClosureEvaluator)->attach($preview);
        }

        $closure = is_array($preview['import_closure'] ?? null) ? $preview['import_closure'] : [];
        if ($calculationImport->hasOpenMatches($preview)) {
            logger()->info('import.submit: excel-arbeidsregels ongeblokkeerd als waarschuwing', [
                'token' => $token,
                'open_matches' => (int) ($preview['calculation']['open_matches'] ?? 0),
            ]);
        }

        if (! ($closure['ready'] ?? false)) {
            // Nooit een lossy/incomplete review terug in de cache zetten wanneer de eerdere preview al klaar was.
            if (! $cachedReady) {
                $payload['preview'] = $preview;
                Cache::put('meetstaat.'.$token, $payload, now()->addHour());
            }

            return redirect()
                ->route('projects.review', $token)
                ->withInput()
                ->with('status', $closure['button_label'] ?? 'Importeren geblokkeerd')
                ->withErrors([
                    'import_closure' => 'Importeren is geblokkeerd door een technische fout. Inhoudelijke waarschuwingen blokkeren het opslaan niet.',
                ]);
        }

        $number = $preview['header']['project_number'] ?? null;
        $existing = $updates->findProjectByWorkNumber(is_string($number) ? $number : null);
        if ($existing !== null && Gate::allows('update', $existing)) {
            $sourceUpdates->cacheExistingPreview($existing, $token, $payload, $updates);

            return redirect()
                ->route('projects.sources.review', $token)
                ->with('status', SourceUpdateService::CONFIRM_MESSAGE);
        }

        $drawing = $this->storePreviewDrawing($token, $request->file('plattegrond'));
        $plattegrondPath = $this->absoluteStoredPath($drawing['path'] ?? $payload['plattegrond'] ?? null);
        $plattegrondName = $drawing['original'] ?? $payload['plattegrond_original'] ?? null;
        $meetstaatPath = $this->absoluteStoredPath($payload['file'] ?? null);

        logger()->info('import.submit: importservice gestart', ['token' => $token]);

        try {
            $project = $intake->importPreview(
                $preview,
                $request->user(),
                $meetstaatPath,
                $payload['original'] ?? null,
                $data['exclude_works'] ?? [],
                $plattegrondPath,
                $plattegrondName,
                $this->extrasForImport($payload['extra'] ?? []),
            );
        } catch (\Throwable $e) {
            report($e);
            logger()->error('import.submit: foutmelding', [
                'token' => $token,
                'message' => $e->getMessage(),
            ]);

            return redirect()
                ->route('projects.review', $token)
                ->withInput()
                ->with('status', 'Importeren mislukt')
                ->withErrors([
                    'import' => 'Importeren mislukt: '.$e->getMessage(),
                ]);
        }

        Cache::forget('meetstaat.'.$token);

        $rooms = count($preview['areas'] ?? []);
        $works = count($preview['works'] ?? []);
        logger()->info('import.submit: import succesvol', [
            'token' => $token,
            'project_id' => $project->id,
            'rooms' => $rooms,
            'works' => $works,
        ]);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', $rooms.' ruimtes en '.$works.' werksoorten geïmporteerd. Materiaal-m² en ruimte-m² blijven gescheiden.');
    }

    /**
     * @param  list<array{file: UploadedFile, type: string, confidence: string, original: string}>  $classified
     * @return array{
     *     meetstaat: ?string,
     *     meetstaat_original: ?string,
     *     plattegrond: ?string,
     *     plattegrond_original: ?string,
     *     uploads: list<array{original: string, type: string, label: string}>,
     *     extra: list<array{path: string, type: string, original: string}>
     * }
     */
    private function storePreviewUploads(string $token, array $classified): array
    {
        $meetstaat = null;
        $meetstaatOriginal = null;
        $plattegrond = null;
        $plattegrondOriginal = null;
        $uploads = [];
        $extra = [];

        foreach ($classified as $index => $item) {
            $extension = strtolower($item['file']->getClientOriginalExtension() ?: 'bin');
            $type = $item['type'] === 'unknown' ? ImportDocumentType::Overig->value : $item['type'];
            $path = $item['file']->storeAs(
                'meetstaat-previews/'.$token,
                $type.'-'.$index.'.'.$extension,
                'local'
            );
            $label = ImportDocumentType::tryFrom($type)?->label() ?? 'Overig';
            $uploads[] = [
                'original' => $item['original'],
                'type' => $type,
                'label' => $label,
                'type_label' => $item['type_label'] ?? $label,
                'roles' => $item['roles'] ?? [],
                'roles_label' => implode(' + ', $item['roles'] ?? []),
                'usable_data' => $item['usable_data'] ?? '',
                'reliability_label' => $item['reliability_label'] ?? '',
            ];

            if ($type === ImportDocumentType::Meetstaat->value && $meetstaat === null) {
                $meetstaat = $path;
                $meetstaatOriginal = $item['original'];

                continue;
            }
            if ($type === ImportDocumentType::Plattegrond->value && $plattegrond === null) {
                $plattegrond = $path;
                $plattegrondOriginal = $item['original'];

                continue;
            }

            $extra[] = [
                'path' => $path,
                'type' => $type,
                'original' => $item['original'],
            ];
        }

        return [
            'meetstaat' => $meetstaat,
            'meetstaat_original' => $meetstaatOriginal,
            'plattegrond' => $plattegrond,
            'plattegrond_original' => $plattegrondOriginal,
            'uploads' => $uploads,
            'extra' => $extra,
        ];
    }

    /**
     * @param  array{
     *     meetstaat: ?string,
     *     meetstaat_original: ?string,
     *     plattegrond: ?string,
     *     plattegrond_original: ?string,
     *     uploads: list<array<string, mixed>>,
     *     extra: list<array{path: string, type: string, original: string}>
     * }  $stored
     * @param  list<array{file: UploadedFile, parsed: array<string, mixed>}>  $files
     * @return array{
     *     meetstaat: ?string,
     *     meetstaat_original: ?string,
     *     plattegrond: ?string,
     *     plattegrond_original: ?string,
     *     uploads: list<array<string, mixed>>,
     *     extra: list<array{path: string, type: string, original: string}>
     * }
     */
    private function storeCalculationUploads(string $token, array $stored, array $files): array
    {
        foreach ($files as $index => $item) {
            $file = $item['file'];
            $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
            $path = $file->storeAs(
                'meetstaat-previews/'.$token,
                'calculatie-'.$index.'.'.$extension,
                'local'
            );
            $original = $file->getClientOriginalName();
            $stored['uploads'][] = [
                'original' => $original,
                'type' => ImportDocumentType::Calculatie->value,
                'label' => ImportDocumentType::Calculatie->label(),
                'type_label' => ImportDocumentType::Calculatie->label(),
                'roles' => [],
                'roles_label' => 'Arbeidsuren',
                'usable_data' => 'uren + tarief',
                'reliability_label' => 'Excel-calculatie',
            ];
            $stored['extra'][] = [
                'path' => $path,
                'type' => ImportDocumentType::Calculatie->value,
                'original' => $original,
            ];
        }

        return $stored;
    }

    /**
     * @return list<array{file: UploadedFile, type: ?string}>
     */
    private function incomingUploads(Request $request): array
    {
        $uploads = [];
        $files = $request->file('files', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }
        foreach (array_values(is_array($files) ? $files : []) as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            $hint = $request->input('types.'.$index);
            $uploads[] = [
                'file' => $file,
                'type' => is_string($hint) && $hint !== 'unknown' ? $hint : null,
            ];
        }

        if ($request->file('meetstaat')) {
            $uploads[] = [
                'file' => $request->file('meetstaat'),
                'type' => ImportDocumentType::Meetstaat->value,
            ];
        }
        if ($request->file('plattegrond')) {
            $uploads[] = [
                'file' => $request->file('plattegrond'),
                'type' => ImportDocumentType::Plattegrond->value,
            ];
        }

        return $uploads;
    }

    private function uploadErrorKey(Request $request): string
    {
        return $request->hasFile('files') ? 'files' : 'meetstaat';
    }

    private function projectFileMaxKilobytes(): int
    {
        return (int) config('filesystems.project_file_max_kilobytes');
    }

    /**
     * @return array<string, string>
     */
    private function uploadMessages(): array
    {
        $message = 'Dit bestand is te groot. Gebruik een bestand van maximaal 100 MB.';

        return [
            'files.*.max' => $message,
            'meetstaat.max' => $message,
            'plattegrond.max' => $message,
            'files.*.mimes' => 'Dit bestandstype wordt niet ondersteund. Gebruik PDF, Excel of CSV.',
            'files.*.extensions' => 'Dit bestandstype wordt niet ondersteund. Gebruik PDF, Excel of CSV.',
            'meetstaat.mimes' => 'De meetstaat moet een PDF zijn.',
            'meetstaat.extensions' => 'De meetstaat moet een PDF zijn.',
            'plattegrond.mimes' => 'De plattegrond moet een PDF zijn.',
            'plattegrond.extensions' => 'De plattegrond moet een PDF zijn.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reviewFilename(array $payload): string
    {
        $uploads = $payload['uploads'] ?? [];
        if ($uploads !== []) {
            return implode(', ', array_column($uploads, 'original'));
        }

        return $payload['original'] ?? ($payload['plattegrond_original'] ?? 'Handmatig');
    }

    /**
     * @param  list<array{path?: string, type?: string, original?: string}>  $extras
     * @return list<array{path: string, type: string, original: string}>
     */
    private function extrasForImport(array $extras): array
    {
        $resolved = [];
        foreach ($extras as $doc) {
            $absolute = $this->absoluteStoredPath($doc['path'] ?? null);
            if ($absolute === null) {
                continue;
            }
            $resolved[] = [
                'path' => $absolute,
                'type' => (string) ($doc['type'] ?? ImportDocumentType::Overig->value),
                'original' => (string) ($doc['original'] ?? basename($absolute)),
            ];
        }

        return $resolved;
    }

    /**
     * @return array{path: ?string, original: ?string}
     */
    private function storePreviewDrawing(string $token, ?UploadedFile $file): array
    {
        if (! $file) {
            return ['path' => null, 'original' => null];
        }

        return [
            'path' => $file->storeAs('meetstaat-previews/'.$token, 'plattegrond.pdf', 'local'),
            'original' => $file->getClientOriginalName(),
        ];
    }

    private function absoluteStoredPath(?string $relative): ?string
    {
        if (! is_string($relative) || $relative === '') {
            return null;
        }

        $path = Storage::disk('local')->path($relative);
        if (is_file($path)) {
            return $path;
        }

        $private = storage_path('app/private/'.$relative);
        if (is_file($private)) {
            return $private;
        }

        $legacy = storage_path('app/'.$relative);

        return is_file($legacy) ? $legacy : null;
    }
}
