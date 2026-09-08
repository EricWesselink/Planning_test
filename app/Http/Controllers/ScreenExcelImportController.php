<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectIntakeService;
use App\Services\ScreenExcelParser;
use App\Services\SpreadsheetReader;
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

class ScreenExcelImportController extends Controller
{
    public function __construct(
        private ScreenExcelParser $parser,
        private SpreadsheetReader $reader,
        private ProjectIntakeService $intake,
    ) {}

    /**
     * @param  list<array{file: UploadedFile, type: ?string}>  $uploads
     */
    public function startPreview(array $uploads): RedirectResponse
    {
        Gate::authorize('create', Project::class);

        $screen = $this->findScreenUpload($uploads);
        if ($screen === null) {
            return back()->withErrors(['files' => 'Geen Excel-bestand voor raambekleding of zonwering gevonden.']);
        }

        $token = (string) Str::uuid();
        $extension = strtolower($screen['file']->getClientOriginalExtension() ?: 'xlsx');
        $path = $screen['file']->storeAs(
            'screen-excel-previews/'.$token,
            'opdrachtlijst.'.$extension,
            'local'
        );

        Cache::put('screen-excel.'.$token, [
            'parsed' => $this->parser->parse($screen['rows']),
            'file' => $path,
            'original' => $screen['file']->getClientOriginalName(),
        ], now()->addHours(2));

        return redirect()->route('projects.screens.review', $token);
    }

    public function review(string $token): View
    {
        Gate::authorize('create', Project::class);
        $payload = Cache::get('screen-excel.'.$token);
        abort_unless(is_array($payload), 404);

        return view('projects.screens-review', [
            'token' => $token,
            'parsed' => $payload['parsed'],
            'filename' => $payload['original'] ?? 'opdrachtlijst.xlsx',
        ]);
    }

    public function import(Request $request, string $token): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $payload = Cache::get('screen-excel.'.$token);
        abort_unless(is_array($payload), 404);

        $validator = Validator::make($request->all(), [
            'customer_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:255'],
            ...PlanningWeek::rules(),
        ], PlanningWeek::messages());
        $validator->after(fn ($weekValidator) => PlanningWeek::validateOrder($weekValidator));
        $data = PlanningWeek::applyTo($validator->validate());

        $stored = Storage::disk('local')->path($payload['file']);
        abort_unless(is_file($stored), 404);

        $file = new UploadedFile(
            $stored,
            (string) ($payload['original'] ?? 'opdrachtlijst.xlsx'),
            null,
            null,
            true
        );

        $result = $this->intake->create($data, null, $file, $request->user());
        Cache::forget('screen-excel.'.$token);
        Storage::disk('local')->deleteDirectory('screen-excel-previews/'.$token);

        return $this->projectRedirect($result['project'], $result);
    }

    public function storeOnProject(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $request->validate([
            'excel' => [
                'required',
                'file',
                'max:'.(int) config('filesystems.project_file_max_kilobytes'),
                'mimes:csv,txt,xlsx,xlsm',
                'extensions:csv,txt,xlsx,xlsm',
            ],
        ]);

        $result = $this->intake->importScreenExcel($project, $request->file('excel'), $request->user());

        return $this->projectRedirect($project, $result);
    }

    /**
     * @param  list<array{file: UploadedFile, type: ?string}>  $uploads
     * @return array{file: UploadedFile, rows: list<list<string>>}|null
     */
    public function findScreenUpload(array $uploads): ?array
    {
        foreach ($uploads as $upload) {
            $file = $upload['file'] ?? null;
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, ['csv', 'txt', 'xlsx', 'xlsm'], true)) {
                continue;
            }

            $path = $file->getRealPath();
            if (! is_string($path) || $path === '') {
                continue;
            }

            try {
                $rows = $this->reader->rows($path, $file->getClientOriginalName());
            } catch (\Throwable) {
                continue;
            }

            if ($this->parser->looksLike($rows)) {
                return ['file' => $file, 'rows' => $rows];
            }
        }

        return null;
    }

    /**
     * @param  array{warnings?: list<string>, rooms?: int, works?: int, screen_summary?: ?string, screen_ready?: bool}  $result
     */
    private function projectRedirect(Project $project, array $result): RedirectResponse
    {
        $summary = $result['screen_summary'] ?? null;
        $status = $summary ?: 'Opdrachtlijst opgeslagen.';
        if (! empty($result['screen_ready'])) {
            $status .= '. Klaar voor planning.';
        }

        return redirect()
            ->route('projects.show', $project)
            ->with('status', $status)
            ->with('warnings', $result['warnings'] ?? []);
    }
}
