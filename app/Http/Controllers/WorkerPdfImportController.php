<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Services\TeamPdfParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkerPdfImportController extends Controller
{
    public function preview(Request $request, TeamPdfParser $parser): RedirectResponse
    {
        Gate::authorize('create', Worker::class);

        $maxKilobytes = (int) config('filesystems.project_file_max_kilobytes');
        $request->validate([
            'pdf' => ['required', 'file', 'max:'.$maxKilobytes, 'mimes:pdf', 'extensions:pdf'],
        ], [
            'pdf.required' => 'Kies een PDF.',
            'pdf.max' => 'Dit bestand is te groot. Gebruik een bestand van maximaal 100 MB.',
            'pdf.mimes' => 'Het team moet als PDF worden ingeladen.',
            'pdf.extensions' => 'Het team moet als PDF worden ingeladen.',
        ]);

        $file = $request->file('pdf');
        $path = $file?->getRealPath();
        if (! is_string($path) || $path === '') {
            return back()->withErrors(['pdf' => 'De PDF kon niet worden gelezen.']);
        }

        try {
            $parsed = $parser->parseFile($path);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['pdf' => $e->getMessage()]);
        }

        $team = $parsed['team'] ?? null;
        if (! is_array($team) || blank($team['name'] ?? null)) {
            return back()->withErrors(['pdf' => 'In deze PDF staat geen team dat we kunnen uitlezen.']);
        }

        $extra = count($parsed['teams']) > 1
            ? ' Eerste team van '.count($parsed['teams']).' uit de PDF.'
            : '';

        return redirect()
            ->route('workers.index')
            ->withInput($this->formInput($team))
            ->with('status', 'PDF uitgelezen. Controleer de gegevens en klik op Toevoegen.'.$extra);
    }

    /**
     * @param  array{
     *     name: string,
     *     employment_type: string,
     *     people_count: int,
     *     specialties: list<string>,
     *     crew_names: ?string,
     *     email: ?string,
     *     phone: ?string,
     *     company: ?string,
     *     address: ?string,
     *     postal_code: ?string,
     *     city: ?string,
     *     contact_name: ?string
     * }  $team
     * @return array<string, mixed>
     */
    private function formInput(array $team): array
    {
        $input = [
            'name' => $team['name'],
            'employment_type' => $team['employment_type'],
            'people_count' => (string) $team['people_count'],
            'specialties' => $team['specialties'],
        ];

        foreach (['crew_names', 'email', 'phone', 'company', 'address', 'postal_code', 'city', 'contact_name'] as $key) {
            if (filled($team[$key] ?? null)) {
                $input[$key] = $team[$key];
            }
        }

        return $input;
    }
}
