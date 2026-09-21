<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ScannedDimensions\ScannedDimensionsImportService;
use App\Support\DutchNumber;
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

class ScannedDimensionsImportController extends Controller
{
    public function __construct(
        private ScannedDimensionsImportService $service,
    ) {}

    /**
     * @param  list<array{file: UploadedFile, type: ?string}>  $uploads
     */
    public function startPreview(array $uploads, ?string $workType = null): RedirectResponse
    {
        Gate::authorize('create', Project::class);

        $found = $this->service->findUploads($uploads);
        if ($found === null) {
            return back()->withErrors(['files' => 'Geen afmetingen-PDF gevonden. Markeer het bestand als Afmetingen of gebruik een bestandsnaam met “afmetingen”.']);
        }

        $token = (string) Str::uuid();
        $afmetingenPath = $found['afmetingen']->storeAs(
            'afmetingen-previews/'.$token,
            'afmetingen.pdf',
            'local'
        );

        $plattegrondPath = null;
        $plattegrondOriginal = null;
        if ($found['plattegrond'] instanceof UploadedFile) {
            $plattegrondPath = $found['plattegrond']->storeAs(
                'afmetingen-previews/'.$token,
                'plattegrond.pdf',
                'local'
            );
            $plattegrondOriginal = $found['plattegrond']->getClientOriginalName();
        }

        $absoluteAfmetingen = Storage::disk('local')->path($afmetingenPath);
        $absoluteDrawing = $plattegrondPath ? Storage::disk('local')->path($plattegrondPath) : null;

        try {
            $parsed = $this->service->parseFile($absoluteAfmetingen, $absoluteDrawing);
        } catch (\Throwable $e) {
            Storage::disk('local')->deleteDirectory('afmetingen-previews/'.$token);

            return back()->withErrors(['files' => 'Afmetingen-PDF kon niet worden gelezen: '.$e->getMessage()]);
        }

        Cache::put('afmetingen.'.$token, [
            'parsed' => $parsed,
            'afmetingen' => $afmetingenPath,
            'afmetingen_original' => $found['afmetingen']->getClientOriginalName(),
            'plattegrond' => $plattegrondPath,
            'plattegrond_original' => $plattegrondOriginal,
            'work_type' => trim((string) $workType),
        ], now()->addHours(2));

        return redirect()->route('projects.afmetingen.review', $token);
    }

    public function review(string $token): View
    {
        Gate::authorize('create', Project::class);
        $payload = Cache::get('afmetingen.'.$token);
        abort_unless(is_array($payload), 404);

        $workType = (string) ($payload['work_type'] ?? '');

        return view('projects.afmetingen-review', [
            'token' => $token,
            'parsed' => $payload['parsed'],
            'filename' => $payload['afmetingen_original'] ?? 'afmetingen.pdf',
            'drawing' => $payload['plattegrond_original'] ?? null,
            'workType' => $workType,
            'reviewRows' => $this->reviewRows($payload['parsed'] ?? [], $workType),
            'workTypeOptions' => $this->workTypeOptions($workType),
        ]);
    }

    public function import(Request $request, string $token): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $payload = Cache::get('afmetingen.'.$token);
        abort_unless(is_array($payload), 404);

        $validator = Validator::make($request->all(), [
            'customer_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'work_address' => ['nullable', 'string', 'max:1000'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:255'],
            'work_type' => ['nullable', 'string', 'max:120'],
            'work_type_custom' => ['nullable', 'string', 'max:120'],
            ...PlanningWeek::rules(),
            'rooms' => ['nullable', 'array'],
            'rooms.*.label' => ['nullable', 'string', 'max:120'],
            'rooms.*.netto' => ['nullable', 'string', 'max:40'],
            'rooms.*.bruto' => ['nullable', 'string', 'max:40'],
            'rooms.*.work_type' => ['nullable', 'string', 'max:120'],
            'rooms.*.status' => ['nullable', 'string', 'max:40'],
        ], PlanningWeek::messages());

        $defaultWorkType = $this->resolvedWorkType($request);
        $rooms = $this->normalizedPostedRooms($request->input('rooms', []), $defaultWorkType);

        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($rooms, $defaultWorkType): void {
            PlanningWeek::validateOrder($validator);
            if ($rooms === []) {
                $validator->errors()->add('rooms', 'Voeg minstens één ruimte toe met een naam of nummer, netto m² groter dan 0 en een werksoort.');

                return;
            }

            foreach ($rooms as $index => $room) {
                if ($room['name'] === '' && $room['room_number'] === '') {
                    $validator->errors()->add('rooms.'.$index.'.label', 'Vul een ruimte/nummer of herkenbare naam in.');
                }
                if ($room['quantity'] <= 0) {
                    $validator->errors()->add('rooms.'.$index.'.netto', 'Netto m² moet groter zijn dan 0.');
                }
                if ($room['work_type'] === '') {
                    $validator->errors()->add(
                        'rooms.'.$index.'.work_type',
                        $defaultWorkType === '' ? 'Kies een werksoort.' : 'Kies een werksoort voor deze ruimte.'
                    );
                }
            }
        });

        $data = PlanningWeek::applyTo($validator->validate());
        $workType = $defaultWorkType !== '' ? $defaultWorkType : (string) ($rooms[0]['work_type'] ?? '');
        if ($workType === '') {
            return back()->withInput()->withErrors(['work_type' => 'Kies een werksoort.']);
        }

        $parsed = $payload['parsed'];
        $parsed['rooms'] = array_values($rooms);
        $parsed['netto_total'] = round(array_sum(array_column($parsed['rooms'], 'quantity')), 2);

        $afmetingenAbs = $this->absoluteStoredPath($payload['afmetingen'] ?? null);
        abort_unless(is_string($afmetingenAbs) && is_file($afmetingenAbs), 404);
        $plattegrondAbs = $this->absoluteStoredPath($payload['plattegrond'] ?? null);

        try {
            $result = $this->service->createProject(
                $data,
                $parsed,
                $workType,
                $request->user(),
                $afmetingenAbs,
                $payload['afmetingen_original'] ?? null,
                $plattegrondAbs,
                $payload['plattegrond_original'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['rooms' => $e->getMessage()]);
        }

        Cache::forget('afmetingen.'.$token);
        Storage::disk('local')->deleteDirectory('afmetingen-previews/'.$token);

        $status = $result['rooms'].' ruimtes geïmporteerd uit afmetingen-PDF (netto '.$this->formatNetto($parsed).').';

        return redirect()
            ->route('projects.show', $result['project'])
            ->with('status', $status)
            ->with('warnings', $result['warnings']);
    }

    /**
     * Directe upload vanaf het aparte afmetingen-formulier.
     */
    public function previewFromForm(Request $request): RedirectResponse
    {
        Gate::authorize('create', Project::class);
        $max = (int) config('filesystems.project_file_max_kilobytes');
        $request->validate([
            'afmetingen' => ['required', 'file', 'max:'.$max, 'mimes:pdf', 'extensions:pdf'],
            'plattegrond' => ['nullable', 'file', 'max:'.$max, 'mimes:pdf', 'extensions:pdf'],
            'work_type' => ['nullable', 'string', 'max:120'],
            'work_type_custom' => ['nullable', 'string', 'max:120'],
        ], [
            'afmetingen.required' => 'Upload een afmetingen-PDF.',
            'afmetingen.mimes' => 'De afmetingen moeten een PDF zijn.',
            'plattegrond.mimes' => 'De plattegrond moet een PDF zijn.',
        ]);

        $uploads = [
            ['file' => $request->file('afmetingen'), 'type' => 'afmetingen'],
        ];
        if ($request->file('plattegrond')) {
            $uploads[] = ['file' => $request->file('plattegrond'), 'type' => 'plattegrond'];
        }

        return $this->startPreview($uploads, $this->resolvedWorkType($request));
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<array{label: string, netto: string, bruto: string, work_type: string, status: string}>
     */
    private function reviewRows(array $parsed, string $workType): array
    {
        $rows = [];
        foreach ($parsed['rooms'] ?? [] as $room) {
            $number = trim((string) ($room['room_number'] ?? ''));
            $name = trim((string) ($room['name'] ?? ''));
            $rows[] = [
                'label' => $name !== '' ? $name : ($number !== '' ? 'Ruimte '.$number : ''),
                'netto' => ((float) ($room['quantity'] ?? 0) > 0) ? (string) $room['quantity'] : '',
                'bruto' => isset($room['bruto']) && $room['bruto'] !== null && (float) $room['bruto'] > 0
                    ? (string) $room['bruto']
                    : '',
                'work_type' => $workType,
                'status' => ((string) ($room['status'] ?? 'ok')) === 'controleren' ? 'controleren' : 'ok',
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function workTypeOptions(string $current): array
    {
        $options = ['Raambekleding', 'Zonwering', 'PVC', 'Linoleum'];
        if ($current !== '' && ! in_array($current, $options, true) && $current !== '__custom__' && $current !== 'anders') {
            $options[] = $current;
        }

        return $options;
    }

    /**
     * @return array<int, array{room_number: string, name: string, quantity: float, bruto: ?float, unit: string, status: string, work_type: string}>
     */
    private function normalizedPostedRooms(mixed $posted, string $defaultWorkType): array
    {
        if (! is_array($posted)) {
            return [];
        }

        $rooms = [];
        foreach ($posted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $nettoRaw = trim((string) ($row['netto'] ?? ''));
            $brutoRaw = trim((string) ($row['bruto'] ?? ''));
            $rowWorkType = trim((string) ($row['work_type'] ?? ''));
            $status = trim((string) ($row['status'] ?? ''));

            if ($label === '' && $nettoRaw === '' && $brutoRaw === '' && $rowWorkType === '') {
                continue;
            }

            $quantity = DutchNumber::parse($nettoRaw) ?? 0.0;
            $bruto = DutchNumber::parse($brutoRaw);
            $number = $this->roomNumberFromLabel($label);
            $name = $label !== '' ? $label : ($number !== '' ? 'Ruimte '.$number : '');
            $workType = $rowWorkType !== '' ? $rowWorkType : $defaultWorkType;

            $rooms[] = [
                'room_number' => $number,
                'name' => $name,
                'quantity' => round((float) $quantity, 2),
                'bruto' => $bruto !== null && $bruto > 0 ? round($bruto, 2) : null,
                'unit' => 'm2',
                'status' => $status === 'controleren' ? 'controleren' : 'ok',
                'work_type' => $workType,
            ];
        }

        return $rooms;
    }

    private function roomNumberFromLabel(string $label): string
    {
        $label = trim($label);
        if (preg_match('/^ruimte\s*([0-9]{1,3}[a-zA-Z]?)$/iu', $label, $match) === 1) {
            return mb_strtoupper($match[1]);
        }
        if (preg_match('/^([0-9]{1,3}[a-zA-Z]?)$/u', $label, $match) === 1) {
            return mb_strtoupper($match[1]);
        }

        return '';
    }

    private function resolvedWorkType(Request $request): string
    {
        $selected = trim((string) $request->input('work_type', ''));
        $custom = trim((string) $request->input('work_type_custom', ''));
        if ($selected === '__custom__' || $selected === 'anders') {
            return $custom;
        }
        if ($selected !== '') {
            return $selected;
        }

        return $custom;
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

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function formatNetto(array $parsed): string
    {
        $netto = (float) ($parsed['netto_total'] ?? 0);

        return number_format($netto, 2, ',', '.').' m²';
    }
}
