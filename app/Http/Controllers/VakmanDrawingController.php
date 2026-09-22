<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\VakmanPlanningService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VakmanDrawingController extends Controller
{
    public function index(Request $request, Project $project, VakmanPlanningService $planning): View|RedirectResponse
    {
        $drawings = $this->drawings($request, $project, $planning);
        $day = $this->dayQuery($request);

        if ($drawings->count() === 1) {
            return redirect()->route('vakman.drawings.show', [
                'project' => $project,
                'document' => $drawings->first(),
                ...$day,
            ]);
        }

        return view('vakman.drawings.index', [
            'project' => $project,
            'drawings' => $drawings,
            'backUrl' => $this->planningUrl($day),
            'day' => $day,
        ]);
    }

    public function show(Request $request, Project $project, ProjectDocument $document, VakmanPlanningService $planning): View
    {
        $drawings = $this->drawings($request, $project, $planning);
        $this->ensureDrawing($drawings, $document);
        $day = $this->dayQuery($request);

        return view('vakman.drawings.show', [
            'project' => $project,
            'document' => $document,
            'backUrl' => $this->planningUrl($day),
            'listUrl' => $drawings->count() > 1
                ? route('vakman.drawings.index', ['project' => $project, ...$day])
                : null,
            'fileUrl' => route('vakman.drawings.file', ['project' => $project, 'document' => $document]),
            'downloadUrl' => route('vakman.drawings.download', ['project' => $project, 'document' => $document]),
        ]);
    }

    public function file(Request $request, Project $project, ProjectDocument $document, VakmanPlanningService $planning): StreamedResponse
    {
        $this->ensureDrawing($this->drawings($request, $project, $planning), $document);

        return $this->pdfResponse($document, inline: true);
    }

    public function download(Request $request, Project $project, ProjectDocument $document, VakmanPlanningService $planning): StreamedResponse
    {
        $this->ensureDrawing($this->drawings($request, $project, $planning), $document);

        return $this->pdfResponse($document, inline: false);
    }

    /**
     * @return Collection<int, ProjectDocument>
     */
    private function drawings(Request $request, Project $project, VakmanPlanningService $planning): Collection
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isVakman() && $user->canAccessProject($project), 403);

        return $planning->pdfDrawings($project);
    }

    /**
     * @param  Collection<int, ProjectDocument>  $drawings
     */
    private function ensureDrawing(Collection $drawings, ProjectDocument $document): void
    {
        abort_unless(
            $drawings->contains(fn (ProjectDocument $drawing): bool => (int) $drawing->id === (int) $document->id),
            404,
        );
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);
    }

    private function pdfResponse(ProjectDocument $document, bool $inline): StreamedResponse
    {
        $name = $this->downloadName($document);
        $headers = ['Content-Type' => 'application/pdf'];

        if ($inline) {
            return Storage::disk('local')->response($document->file_path, $name, $headers);
        }

        return Storage::disk('local')->download($document->file_path, $name, $headers);
    }

    private function downloadName(ProjectDocument $document): string
    {
        $name = basename(str_replace('\\', '/', (string) $document->original_filename));
        $name = str_replace(['"', "\r", "\n"], '', $name);
        if ($name === '') {
            return 'tekening.pdf';
        }

        return str_ends_with(strtolower($name), '.pdf') ? $name : $name.'.pdf';
    }

    /**
     * @return array{day?: string}
     */
    private function dayQuery(Request $request): array
    {
        $day = $request->query('day');
        if (! is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return [];
        }

        return ['day' => $day];
    }

    /**
     * @param  array{day?: string}  $day
     */
    private function planningUrl(array $day): string
    {
        if (! isset($day['day'])) {
            return route('vakman.planning');
        }

        $date = Carbon::createFromFormat('Y-m-d', $day['day']);
        if (! $date instanceof Carbon) {
            return route('vakman.planning');
        }

        return route('vakman.planning', [
            'view' => 'week',
            'week' => $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'day' => $date->toDateString(),
        ]);
    }
}
