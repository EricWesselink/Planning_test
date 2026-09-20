<?php

namespace App\Http\Controllers;

use App\Models\Calculation;
use App\Services\AreaWithoutM2Trial\AreaWithoutM2Analyzer;
use App\Services\AreaWithoutM2Trial\TrialStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AreaWithoutM2TrialController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('create', Calculation::class);

        return $this->uncachedView('calculations.area-without-m2.create', [
            'maxFileMegabytes' => (int) (config('filesystems.project_file_max_kilobytes') / 1024),
        ]);
    }

    public function store(Request $request, AreaWithoutM2Analyzer $analyzer, TrialStore $trials): RedirectResponse
    {
        Gate::authorize('create', Calculation::class);

        $maxKilobytes = (int) config('filesystems.project_file_max_kilobytes');
        $request->validate([
            'drawing' => ['required', 'file', 'max:'.$maxKilobytes, 'mimes:pdf', 'extensions:pdf'],
            'client_filename' => ['nullable', 'string', 'max:255'],
            'client_size' => ['nullable', 'string', 'max:32'],
            'client_input_name' => ['nullable', 'string', 'max:64'],
            'client_file_input_count' => ['nullable', 'string', 'max:8'],
            'client_file_input_names' => ['nullable', 'string', 'max:255'],
        ], [
            'drawing.required' => 'Upload een PDF-tekening.',
            'drawing.max' => 'Dit bestand is te groot. Gebruik een bestand van maximaal 100 MB.',
            'drawing.mimes' => 'Gebruik een PDF-tekening.',
            'drawing.extensions' => 'Gebruik een PDF-tekening.',
        ]);

        $file = $request->file('drawing');
        if ($file === null) {
            return back()->withErrors(['drawing' => 'De PDF kon niet worden gelezen.']);
        }

        $received = $this->incomingUploadDebug($request, $file);

        try {
            $stored = $trials->storeDrawing($file);
        } catch (RuntimeException $e) {
            return back()->withErrors(['drawing' => $e->getMessage()]);
        }

        $stored['received_upload'] = $this->receivedUploadWithStoredFile($received, $stored);

        try {
            $result = $analyzer->analyzeFile($stored['absolute_path'], $stored['original_filename']);
        } catch (\InvalidArgumentException $e) {
            $trials->forget($stored['id']);

            return back()->withErrors(['drawing' => $e->getMessage()]);
        }

        $trials->saveResult($stored, $result);

        return $this->uncachedRedirect(
            redirect()->route('calculations.area-without-m2.show', $stored['id'])->setStatusCode(303),
        );
    }

    public function show(string $trial, TrialStore $trials): Response
    {
        Gate::authorize('viewAny', Calculation::class);

        $result = $trials->find($trial);
        abort_if($result === null, 404);

        return $this->uncachedView('calculations.area-without-m2.show', [
            'trial' => $result,
        ]);
    }

    public function preview(string $trial, TrialStore $trials): StreamedResponse
    {
        Gate::authorize('viewAny', Calculation::class);

        abort_if($trials->find($trial) === null, 404);
        $path = $trials->previewPath($trial);
        abort_if($path === null, 404);

        return Storage::disk('local')->response($path, 'preview.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function uncachedView(string $view, array $data): Response
    {
        /** @var Response $response */
        $response = response()->view($view, $data);
        $this->preventStore($response);

        return $response;
    }

    private function uncachedRedirect(RedirectResponse $response): RedirectResponse
    {
        $this->preventStore($response);

        return $response;
    }

    private function preventStore(\Symfony\Component\HttpFoundation\Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
    }

    /**
     * Snapshot the UploadedFile Laravel actually received, before disk storage.
     *
     * @return array<string, mixed>
     */
    private function incomingUploadDebug(Request $request, UploadedFile $file): array
    {
        $tmp = $file->getRealPath() ?: $file->getPathname();

        return [
            'client_filename' => $request->string('client_filename')->toString(),
            'client_size' => $request->string('client_size')->toString(),
            'client_input_name' => $request->string('client_input_name')->toString(),
            'client_file_input_count' => $request->string('client_file_input_count')->toString(),
            'client_file_input_names' => $request->string('client_file_input_names')->toString(),
            'controller' => self::class.'::store',
            'route' => (string) $request->route()?->getName(),
            'input_name_read' => 'drawing',
            'original_name' => $file->getClientOriginalName(),
            'mime' => (string) $file->getClientMimeType(),
            'size' => $file->getSize(),
            'tmp_path' => is_string($tmp) ? $tmp : '',
            'tmp_sha256' => (is_string($tmp) && is_file($tmp)) ? (string) hash_file('sha256', $tmp) : '',
            'all_files' => $this->describeRequestFiles($request),
        ];
    }

    /**
     * @param  array<string, mixed>  $received
     * @param  array{original_filename: string, file_hash: string, absolute_path: string}  $stored
     * @return array<string, mixed>
     */
    private function receivedUploadWithStoredFile(array $received, array $stored): array
    {
        $client = (string) ($received['client_filename'] ?? '');
        $postName = (string) ($received['original_name'] ?? '');
        $tmpHash = (string) ($received['tmp_sha256'] ?? '');
        $storedHash = (string) ($stored['file_hash'] ?? '');

        return array_merge($received, [
            'stored_filename' => $stored['original_filename'],
            'stored_sha256' => $storedHash,
            'stored_path' => $stored['absolute_path'],
            'mismatch' => $this->uploadMismatch($client, $postName, $tmpHash, $storedHash),
        ]);
    }

    private function uploadMismatch(string $clientName, string $postName, string $tmpHash, string $storedHash): string
    {
        if ($clientName !== '' && $clientName !== $postName) {
            return 'Wissel in de browser/het formulier: gekozen naam is '.$clientName.', maar POST originalName is '.$postName.'.';
        }
        if ($tmpHash !== '' && $storedHash !== '' && $tmpHash !== $storedHash) {
            return 'Wissel bij opslaan: SHA-256 van het ontvangen tempbestand wijkt af van het geanalyseerde bestand.';
        }
        if ($clientName === '') {
            return 'Geen wissel na POST: originalName is '.$postName.'. De browser stuurde geen client_filename mee.';
        }

        return 'Geen wissel: browser, POST originalName en SHA-256 van het ontvangen bestand komen overeen.';
    }

    /**
     * @return list<array{field: string, original_name: string, mime: string, size: int, tmp_path: string, sha256: string}>
     */
    private function describeRequestFiles(Request $request): array
    {
        $rows = [];
        foreach ($request->allFiles() as $field => $file) {
            $this->appendFileDebug($rows, (string) $field, $file);
        }

        return $rows;
    }

    /**
     * @param  list<array{field: string, original_name: string, mime: string, size: int, tmp_path: string, sha256: string}>  $rows
     */
    private function appendFileDebug(array &$rows, string $field, mixed $file): void
    {
        if (is_array($file)) {
            foreach ($file as $index => $item) {
                $this->appendFileDebug($rows, $field.'['.$index.']', $item);
            }

            return;
        }

        if (! $file instanceof UploadedFile) {
            return;
        }

        $tmp = $file->getRealPath() ?: $file->getPathname();
        $rows[] = [
            'field' => $field,
            'original_name' => $file->getClientOriginalName(),
            'mime' => (string) $file->getClientMimeType(),
            'size' => $file->getSize(),
            'tmp_path' => is_string($tmp) ? $tmp : '',
            'sha256' => (is_string($tmp) && is_file($tmp)) ? (string) hash_file('sha256', $tmp) : '',
        ];
    }
}
