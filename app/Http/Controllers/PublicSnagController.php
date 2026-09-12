<?php

namespace App\Http\Controllers;

use App\Models\SnagItem;
use App\Models\SnagPhoto;
use App\Services\SnagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicSnagController extends Controller
{
    public function show(string $token): View
    {
        $snag = $this->snag($token);

        return view('snags.public', compact('snag'));
    }

    public function comment(Request $request, string $token, SnagService $snags): RedirectResponse
    {
        $snag = $this->snag($token, forWrite: true);
        $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);
        $snags->addNote($snag, $request->string('note')->toString());

        return back()->with('status', 'Opmerking geplaatst.');
    }

    public function progress(string $token, SnagService $snags): RedirectResponse
    {
        $snag = $this->snag($token, forWrite: true);
        $snags->startProgress($snag);

        return back()->with('status', 'Status: In behandeling.');
    }

    public function complete(Request $request, string $token, SnagService $snags): RedirectResponse
    {
        $snag = $this->snag($token, forWrite: true);

        $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:20480'],
            'photos' => ['nullable', 'array', 'max:8'],
            'photos.*' => ['image', 'mimes:jpeg,jpg,png,webp,gif', 'max:20480'],
        ]);

        $files = [];
        if ($request->file('photo') instanceof UploadedFile) {
            $files[] = $request->file('photo');
        }
        foreach ((array) $request->file('photos', []) as $file) {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        }

        try {
            $snags->reportDone($snag, null, $request->input('note'), $files);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('status', 'Gereed gemeld. De uitvoerder controleert dit punt.');
    }

    public function photo(string $token, SnagPhoto $photo): StreamedResponse
    {
        $snag = $this->snag($token);
        abort_unless((int) $photo->snag_item_id === (int) $snag->id, 404);
        abort_unless(Storage::disk('local')->exists($photo->file_path), 404);

        return Storage::disk('local')->response($photo->file_path, $photo->original_filename);
    }

    private function snag(string $token, bool $forWrite = false): SnagItem
    {
        abort_unless(strlen($token) >= 24, 404);

        $snag = SnagItem::query()
            ->where('public_token', $token)
            ->with(['project', 'area', 'assignee', 'photos', 'history'])
            ->firstOrFail();

        abort_unless($snag->publicAccessIsActive(), 404);
        abort_if($forWrite && $snag->status->isFinished(), 403);

        return $snag;
    }
}
