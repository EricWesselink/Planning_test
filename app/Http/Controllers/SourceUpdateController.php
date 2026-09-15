<?php

namespace App\Http\Controllers;

use App\Enums\ImportDocumentType;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Services\CalculationImportService;
use App\Services\Meetstaat\ImportPreviewBuilder;
use App\Services\SourceDocumentService;
use App\Services\SourceUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SourceUpdateController extends Controller
{
    public function store(
        Request $request,
        Project $project,
        ImportPreviewBuilder $builder,
        CalculationImportService $calculations,
        SourceUpdateService $updates,
    ): RedirectResponse {
        Gate::authorize('update', $project);
        $maxKilobytes = $this->projectFileMaxKilobytes();
        $request->validate([
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', 'max:'.$maxKilobytes, 'mimes:pdf,csv,txt,xlsx,xlsm,xls,jpg,jpeg,png,webp', 'extensions:pdf,csv,txt,xlsx,xlsm,xls,jpg,jpeg,png,webp'],
            'types' => ['nullable', 'array'],
            'types.*' => ['nullable', 'string', 'max:32'],
            'meetstaat' => ['nullable', 'file', 'max:'.$maxKilobytes, 'mimes:csv,txt,xlsx,xlsm,xls,pdf', 'extensions:csv,txt,xlsx,xlsm,xls,pdf'],
            'plattegrond' => ['nullable', 'file', 'max:'.$maxKilobytes, 'mimes:pdf,jpg,jpeg,png,webp', 'extensions:pdf,jpg,jpeg,png,webp'],
            'source_file' => ['nullable', 'file', 'max:'.$maxKilobytes],
        ]);

        $uploads = $this->incomingUploads($request);
        if ($uploads === []) {
            return back()->withErrors(['files' => 'Kies minstens één bronbestand.']);
        }

        return $this->startFromUploads($request, $project, $uploads, $builder, $calculations, $updates);
    }

    /**
     * @param  list<array{file: UploadedFile, type: ?string}>  $uploads
     */
    public function startFromUploads(
        Request $request,
        Project $project,
        array $uploads,
        ImportPreviewBuilder $builder,
        CalculationImportService $calculations,
        SourceUpdateService $updates,
    ): RedirectResponse {
        Gate::authorize('update', $project);
        $extracted = $calculations->extract($uploads);
        $uploads = $extracted['remaining'];

        try {
            $built = $uploads === [] && $extracted['files'] !== []
                ? $builder->build([])
                : $builder->build($uploads);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['files' => $e->getMessage()]);
        }

        $token = (string) Str::uuid();
        $stored = $this->storePreviewUploads($token, $built['classified']);
        $stored = $this->storeCalculationUploads($token, $stored, $extracted['files']);
        $preview = $calculations->attach($built['preview'], $extracted['files']);
        $types = $this->typesFromStored($stored, $updates->typesFromPreview($preview));
        $comparison = $updates->compare($project, $preview, $types);

        Cache::put('source-update.'.$token, [
            'project_id' => $project->id,
            'preview' => $preview,
            'file' => $stored['meetstaat'],
            'original' => $stored['meetstaat_original'],
            'plattegrond' => $stored['plattegrond'],
            'plattegrond_original' => $stored['plattegrond_original'],
            'uploads' => $stored['uploads'],
            'extra' => $stored['extra'],
            'types' => $types,
            'comparison' => $comparison,
        ], now()->addHours(2));

        return redirect()->route('projects.sources.review', $token);
    }

    public function review(string $token, SourceDocumentService $documents): View
    {
        $payload = Cache::get('source-update.'.$token);
        abort_unless(is_array($payload), 404);
        $project = Project::query()->findOrFail($payload['project_id']);
        Gate::authorize('update', $project);

        return view('projects.source-update', [
            'token' => $token,
            'project' => $project,
            'preview' => $payload['preview'],
            'comparison' => $payload['comparison'],
            'uploads' => $payload['uploads'] ?? [],
            'catalog' => $documents->catalog($project),
        ]);
    }

    public function confirm(string $token, SourceUpdateService $updates): RedirectResponse
    {
        $payload = Cache::get('source-update.'.$token);
        abort_unless(is_array($payload), 404);
        $project = Project::query()->findOrFail($payload['project_id']);
        Gate::authorize('update', $project);

        $files = $this->filesForApply($payload);
        $project = $updates->apply(
            $project,
            request()->user(),
            $payload['preview'] ?? [],
            $files,
            $payload['types'] ?? [],
        );
        Cache::forget('source-update.'.$token);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Brongegevens bijgewerkt. Planning, uren, voortgang en opleverpunten zijn behouden.');
    }

    public function activate(Project $project, ProjectDocument $document, SourceDocumentService $documents): RedirectResponse
    {
        abort_unless((int) $document->project_id === (int) $project->id, 404);
        Gate::authorize('update', $project);
        $documents->markCurrent($document);

        return back()->with('status', $documents->typeLabel($document->document_type).' revisie '.$document->revision.' is nu actief.');
    }

    /**
     * @param  list<array{file: UploadedFile, type: ?string}>  $uploads
     */
    public function cacheExistingPreview(
        Project $project,
        string $token,
        array $payload,
        SourceUpdateService $updates,
    ): void {
        $preview = $payload['preview'] ?? [];
        $types = $this->typesFromStored($payload, $updates->typesFromPreview($preview));
        Cache::put('source-update.'.$token, [
            'project_id' => $project->id,
            'preview' => $preview,
            'file' => $payload['file'] ?? null,
            'original' => $payload['original'] ?? null,
            'plattegrond' => $payload['plattegrond'] ?? null,
            'plattegrond_original' => $payload['plattegrond_original'] ?? null,
            'uploads' => $payload['uploads'] ?? [],
            'extra' => $payload['extra'] ?? [],
            'types' => $types,
            'comparison' => $updates->compare($project, $preview, $types),
        ], now()->addHours(2));
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
                'type' => is_string($hint) && $hint !== '' && $hint !== 'unknown' ? $hint : null,
            ];
        }
        if ($request->file('source_file')) {
            $uploads[] = ['file' => $request->file('source_file'), 'type' => $request->input('source_type')];
        }
        if ($request->file('meetstaat')) {
            $uploads[] = ['file' => $request->file('meetstaat'), 'type' => ImportDocumentType::Meetstaat->value];
        }
        if ($request->file('plattegrond')) {
            $uploads[] = ['file' => $request->file('plattegrond'), 'type' => ImportDocumentType::Plattegrond->value];
        }

        return $uploads;
    }

    /**
     * @param  list<array{file: UploadedFile, type: string, confidence: string, original: string}>  $classified
     * @return array{
     *     meetstaat: ?string,
     *     meetstaat_original: ?string,
     *     plattegrond: ?string,
     *     plattegrond_original: ?string,
     *     uploads: list<array{original: string, type: string}>,
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
            $file = $item['file'];
            $type = $item['type'];
            $original = $item['original'] ?? $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
            $path = $file->storeAs(
                'meetstaat-previews/'.$token,
                $type.'-'.$index.'.'.$extension,
                'local'
            );
            $uploads[] = ['original' => $original, 'type' => $type];
            if ($type === ImportDocumentType::Meetstaat->value && $meetstaat === null) {
                $meetstaat = $path;
                $meetstaatOriginal = $original;
            } elseif ($type === ImportDocumentType::Plattegrond->value && $plattegrond === null) {
                $plattegrond = $path;
                $plattegrondOriginal = $original;
            } else {
                $extra[] = ['path' => $path, 'type' => $type, 'original' => $original];
            }
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
     * @param  array<string, mixed>  $stored
     * @param  list<array{file: UploadedFile, parsed: array<string, mixed>}>  $files
     * @return array<string, mixed>
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
     * @param  array<string, mixed>  $stored
     * @param  list<string>  $fromPreview
     * @return list<string>
     */
    private function typesFromStored(array $stored, array $fromPreview): array
    {
        $types = $fromPreview;
        foreach ($stored['uploads'] ?? [] as $upload) {
            if (is_array($upload) && filled($upload['type'] ?? null)) {
                $types[] = (string) $upload['type'];
            }
        }
        if (filled($stored['file'] ?? null) || filled($stored['meetstaat'] ?? null)) {
            $types[] = ImportDocumentType::Meetstaat->value;
        }
        if (filled($stored['plattegrond'] ?? null)) {
            $types[] = ImportDocumentType::Plattegrond->value;
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{path: string, type: string, original: string}>
     */
    private function filesForApply(array $payload): array
    {
        $files = [];
        $meetstaat = $this->absoluteStoredPath($payload['file'] ?? $payload['meetstaat'] ?? null);
        if ($meetstaat !== null) {
            $files[] = [
                'path' => $meetstaat,
                'type' => ImportDocumentType::Meetstaat->value,
                'original' => (string) ($payload['original'] ?? basename($meetstaat)),
            ];
        }
        $drawing = $this->absoluteStoredPath($payload['plattegrond'] ?? null);
        if ($drawing !== null) {
            $files[] = [
                'path' => $drawing,
                'type' => ImportDocumentType::Plattegrond->value,
                'original' => (string) ($payload['plattegrond_original'] ?? basename($drawing)),
            ];
        }
        foreach ($payload['extra'] ?? [] as $doc) {
            $absolute = $this->absoluteStoredPath($doc['path'] ?? null);
            if ($absolute === null) {
                continue;
            }
            $files[] = [
                'path' => $absolute,
                'type' => (string) ($doc['type'] ?? ImportDocumentType::Overig->value),
                'original' => (string) ($doc['original'] ?? basename($absolute)),
            ];
        }

        return $files;
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

    private function projectFileMaxKilobytes(): int
    {
        return (int) config('filesystems.project_file_max_kilobytes');
    }
}
