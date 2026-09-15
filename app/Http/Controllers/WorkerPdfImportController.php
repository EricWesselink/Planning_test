<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Enums\FlooringSpecialty;
use App\Models\Worker;
use App\Services\TeamPdfParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $request->session()->put('worker_pdf.teams', $parsed['teams']);

        $names = trim((string) ($team['crew_names'] ?? ''));
        $status = 'PDF uitgelezen. Controleer de gegevens en klik op Toevoegen.';
        if ($names !== '') {
            $status = 'PDF uitgelezen: '.$team['name'].' · '.$names.'. Controleer en klik op Toevoegen.';
        }
        if (count($parsed['teams']) > 1) {
            $status .= ' Of voeg alle '.count($parsed['teams']).' teams in één keer toe.';
        }

        return redirect()
            ->route('workers.index')
            ->withInput($this->formInput($team))
            ->with('status', $status);
    }

    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('create', Worker::class);

        $teams = $request->session()->get('worker_pdf.teams');
        if (! is_array($teams) || $teams === []) {
            return back()->withErrors(['pdf' => 'Geen uitgelezen teams meer. Laad de PDF opnieuw.']);
        }

        $created = [];
        DB::transaction(function () use ($teams, &$created): void {
            foreach ($teams as $team) {
                if (! is_array($team)) {
                    continue;
                }

                $name = trim((string) ($team['name'] ?? ''));
                if ($name === '' || Worker::query()->where('name', $name)->exists()) {
                    continue;
                }

                $members = $this->crewMembers($team);
                $peopleCount = max(1, (int) ($team['people_count'] ?? count($members) ?: 1));
                $type = EmploymentType::tryFrom((string) ($team['employment_type'] ?? '')) ?? EmploymentType::Eigen;

                $members = Worker::normalizeCrewMembers($members, $peopleCount);
                $peopleCount = max(1, count($members));

                Worker::query()->create([
                    'name' => $name,
                    'employment_type' => $type,
                    'people_count' => $peopleCount,
                    'crew_names' => Worker::joinedCrewNames($members),
                    'crew_members' => $members,
                    'phone' => Worker::firstCrewPhone($members),
                    'specialty' => FlooringSpecialty::storedLabels($team['specialties'] ?? []),
                    'active' => true,
                ]);
                $created[] = $name;
            }
        });

        $request->session()->forget('worker_pdf.teams');

        if ($created === []) {
            return redirect()
                ->route('workers.index')
                ->with('status', 'Deze teams stonden er al in.');
        }

        return redirect()
            ->route('workers.index')
            ->with('status', count($created).' teams toegevoegd: '.implode(', ', $created).'.');
    }

    /**
     * @param  array{
     *     name: string,
     *     employment_type: string,
     *     people_count: int,
     *     specialties: list<string>,
     *     crew_names: ?string,
     *     crew_members?: list<array{name: string, phone?: string}>,
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

        $members = $this->crewMembers($team);
        if ($members !== []) {
            $input['crew_members'] = $members;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $team
     * @return list<array{name: string, phone: string}>
     */
    private function crewMembers(array $team): array
    {
        $members = [];
        foreach ($team['crew_members'] ?? [] as $member) {
            if (! is_array($member)) {
                continue;
            }

            $name = trim((string) ($member['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $members[] = [
                'name' => $name,
                'phone' => trim((string) ($member['phone'] ?? '')),
            ];
        }

        if ($members !== []) {
            return $members;
        }

        foreach (preg_split('/\s*,\s*/', (string) ($team['crew_names'] ?? '')) ?: [] as $name) {
            $name = trim($name);
            if ($name !== '') {
                $members[] = ['name' => $name, 'phone' => ''];
            }
        }

        return $members;
    }
}
