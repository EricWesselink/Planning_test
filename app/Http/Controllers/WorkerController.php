<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Enums\FlooringSpecialty;
use App\Enums\UserRole;
use App\Enums\WorkUnit;
use App\Mail\WorkerPlanningInviteMail;
use App\Models\SpecialtyOption;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerRate;
use App\Services\AccountActivationService;
use App\Services\ProductionOverviewService;
use App\Services\VoucherDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkerController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Worker::class);
        $workers = Worker::query()
            ->with(['crewPeople', 'availabilities', 'users'])
            ->orderByDesc('active')
            ->orderBy('employment_type')
            ->orderBy('name')
            ->get();

        $specialtyCatalog = $this->specialtyCatalog($workers->pluck('specialty'));
        $filtering = $request->boolean('zoeken');
        $filterSpecialties = $this->selectedFilterSpecialties($request, $specialtyCatalog, $filtering);

        if ($filtering) {
            $workers = $workers
                ->filter(fn (Worker $worker): bool => $worker->hasAnySpecialty($filterSpecialties))
                ->values();
        }

        return view('workers.index', [
            'workers' => $workers,
            'officeUsers' => User::query()->office()->get(),
            'specialtyCatalog' => $specialtyCatalog,
            'filterSpecialties' => $filterSpecialties,
            'filtering' => $filtering,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Worker::class);

        return view('workers.create', [
            'worker' => new Worker([
                'employment_type' => EmploymentType::Eigen,
                'active' => true,
            ]),
            'specialtyCatalog' => $this->specialtyCatalog(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Worker::class);

        $account = null;
        $worker = DB::transaction(function () use ($request, &$account): Worker {
            $worker = Worker::query()->create($this->payload($request, creating: true));
            if ($request->filled('email')) {
                $account = $this->createLogin($worker);
            }

            return $worker;
        });
        $this->sendPlanningInvite($request, $worker, $account);

        return redirect()
            ->route('workers.index')
            ->with('status', $this->savedStatus($request, $account));
    }

    public function show(Request $request, Worker $worker, ProductionOverviewService $overview, VoucherDraftService $drafts): View
    {
        Gate::authorize('view', $worker);
        $worker->load([
            'assignments.project',
            'crewPeople.user',
            'availabilities',
            'users',
            'workOrders.project',
            'workOrders.workItem',
            'rates',
        ]);

        $production = $overview->build($worker->id, null, null, null, $request->user());
        $vouchers = $worker->vouchers()
            ->with(['project', 'lines'])
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();
        $vouchersByKey = $vouchers->groupBy(fn ($voucher): string => $voucher->worker_id.'.'.$voucher->project_id);

        return view('workers.show', [
            'worker' => $worker,
            'groups' => $production['groups'],
            'specialtyCatalog' => $this->specialtyCatalog(),
            'canCreateVouchers' => $request->user()?->canManageProjects() ?? false,
            'vouchersByKey' => $vouchersByKey,
            'billingByKey' => $drafts->billingByWorkerProject($vouchers),
            'sheetsByKey' => $drafts->sheetsByWorkerProject($vouchers),
            'rateRows' => $this->rateRows($worker),
        ]);
    }

    public function update(Request $request, Worker $worker): RedirectResponse
    {
        Gate::authorize('update', $worker);
        $worker->update($this->payload($request));

        return redirect()
            ->route('workers.show', $worker)
            ->with('status', 'Gegevens opgeslagen.');
    }

    public function storeLogin(Request $request, Worker $worker): RedirectResponse
    {
        Gate::authorize('update', $worker);

        if ($worker->users()->exists()) {
            return redirect()
                ->route('workers.show', $worker)
                ->with('status', 'Dit team heeft al een inlog.');
        }

        $officeLogin = $worker->officeLogin();
        if ($officeLogin !== null) {
            return redirect()
                ->route('workers.show', $worker)
                ->with('status', $officeLogin->name.' heeft al een kantoorinlog. Geen aparte vakman-inlog nodig.');
        }

        $this->loginRules($request, required: true);
        $account = DB::transaction(function () use ($request, $worker): User {
            $email = strtolower(trim($request->string('email')->toString()));
            $worker->forceFill(['email' => $email])->save();

            return $this->createLogin($worker->fresh());
        });
        $this->sendPlanningInvite($request, $worker->fresh(), $account);

        return redirect()
            ->route('workers.show', $worker)
            ->with('status', $this->savedStatus($request, $account, createdWorker: false));
    }

    public function updateActive(Request $request, Worker $worker): RedirectResponse
    {
        Gate::authorize('update', $worker);
        $active = $request->boolean('active');

        DB::transaction(function () use ($worker, $active): void {
            $worker->update(['active' => $active]);
            $worker->users()->update(['active' => $active]);
        });

        return back()->with(
            'status',
            $active ? $worker->name.' is weer actief.' : $worker->name.' is inactief gezet.',
        );
    }

    public function destroy(Worker $worker): RedirectResponse
    {
        Gate::authorize('delete', $worker);

        if ($worker->vouchers()->exists() || $worker->workTickets()->exists()) {
            return back()->withErrors([
                'worker' => $worker->name.' heeft bonnen. Zet het team inactief in plaats van te verwijderen.',
            ]);
        }

        $name = $worker->name;
        DB::transaction(function () use ($worker): void {
            $worker->users()->delete();
            $worker->delete();
        });

        return redirect()
            ->route('workers.index')
            ->with('status', $name.' is verwijderd.');
    }

    public function storeSpecialty(Request $request): RedirectResponse
    {
        Gate::authorize('create', Worker::class);

        $data = $request->validate([
            'onderdeel' => ['required', 'string', 'max:64', 'not_regex:/[,\r\n]/'],
        ], [
            'onderdeel.required' => 'Vul een onderdeel in.',
            'onderdeel.max' => 'Een onderdeel mag maximaal 64 tekens zijn.',
            'onderdeel.not_regex' => 'Gebruik geen komma in een onderdeel.',
        ]);

        $name = trim($data['onderdeel']);
        $builtIn = FlooringSpecialty::caseFrom($name);
        if ($builtIn instanceof FlooringSpecialty) {
            return redirect()
                ->route('workers.index')
                ->with('status', $builtIn->label().' stond er al in.');
        }

        SpecialtyOption::remember($name);

        return redirect()
            ->route('workers.index')
            ->with('status', 'Vakkennis toegevoegd.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, bool $creating = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'company' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => $creating
                ? $this->emailRules($request, required: false)
                : ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:255'],
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['string', 'max:64', 'not_regex:/[,\r\n]/'],
            'people_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'crew_names' => ['nullable', 'string', 'max:255'],
            'crew_members' => ['nullable', 'array', 'max:50'],
            'crew_members.*.id' => ['nullable', 'integer', 'min:1'],
            'crew_members.*.name' => ['nullable', 'string', 'max:255'],
            'crew_members.*.phone' => ['nullable', 'string', 'max:64'],
            'crew_members.*.active' => ['sometimes', 'boolean'],
            'crew_members.*.registers_hours' => ['sometimes', 'boolean'],
            'registers_hours' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'friday_off' => ['sometimes', 'boolean'],
            'unavailable' => ['sometimes', 'boolean'],
        ];
        if ($creating) {
            $rules['invite'] = ['sometimes', 'boolean'];
        }

        $data = $request->validate($rules, [
            'email.required' => 'Vul een e-mailadres in voor de inlog.',
            'email.email' => 'Vul een geldig e-mailadres in.',
            'email.unique' => 'Dit e-mailadres is al in gebruik.',
            'people_count.min' => 'Er moet minstens 1 persoon zijn.',
            'specialties.*.max' => 'Een onderdeel mag maximaal 64 tekens zijn.',
            'specialties.*.not_regex' => 'Gebruik geen komma in een onderdeel.',
        ]);

        $data['active'] = $request->boolean('active', true);
        $hourly = filled($request->input('rates.hourly.unit_price'));
        $typeForHours = EmploymentType::tryFrom((string) $request->input('employment_type'));
        $data['registers_hours'] = $request->exists('registers_hours')
            ? $request->boolean('registers_hours')
            : Worker::defaultRegistersHours($typeForHours, $hourly);
        $type = $data['employment_type'] instanceof EmploymentType
            ? $data['employment_type']
            : EmploymentType::tryFrom((string) $data['employment_type']);
        if ($type === EmploymentType::Eigen) {
            unset($data['company'], $data['contact_name'], $data['address'], $data['postal_code'], $data['city']);
        }
        if ($type === EmploymentType::Eigen && $request->exists('friday_off')) {
            $data['friday_off'] = $request->boolean('friday_off');
        } elseif ($type !== EmploymentType::Eigen) {
            $data['friday_off'] = false;
        } else {
            unset($data['friday_off']);
        }
        if ($request->exists('unavailable')) {
            $data['unavailable'] = $request->boolean('unavailable');
        } else {
            unset($data['unavailable']);
        }
        $data['people_count'] = max(1, (int) ($data['people_count'] ?? 1));
        $members = $request->exists('crew_members')
            ? Worker::normalizeCrewMembers($request->input('crew_members', []), $data['people_count'])
            : Worker::legacyCrewMembers(
                (string) ($data['crew_names'] ?? ''),
                (string) ($data['phone'] ?? ''),
                $data['people_count'],
            );
        if (Worker::firstCrewPhone($members) === null && filled($data['phone'] ?? null)) {
            $members[0]['phone'] = trim((string) $data['phone']);
        }
        foreach ($members as $index => $member) {
            if (! array_key_exists('registers_hours', $member)) {
                $members[$index]['registers_hours'] = (bool) $data['registers_hours'];
            }
        }
        $data['crew_members'] = $members;
        $data['people_count'] = max(1, count($members));
        $data['crew_names'] = Worker::joinedCrewNames($members);
        $data['phone'] = Worker::firstCrewPhone($members);
        $data['specialty'] = FlooringSpecialty::storedLabels($data['specialties'] ?? []);
        SpecialtyOption::rememberMany($data['specialties'] ?? []);
        unset($data['specialties'], $data['password'], $data['password_confirmation'], $data['invite']);
        if ($creating && filled($data['email'] ?? null)) {
            $data['email'] = strtolower(trim((string) $data['email']));
        }

        if ($type?->isExternal() && blank($data['company'] ?? null) && (array_key_exists('company', $data) || $creating)) {
            $data['company'] = $data['name'];
        }

        return $data;
    }

    /**
     * @param  iterable<int, mixed>|null  $stored
     * @return list<array{value: string, label: string}>
     */
    private function specialtyCatalog(?iterable $stored = null): array
    {
        $values = $stored === null
            ? Worker::query()->whereNotNull('specialty')->pluck('specialty')->all()
            : collect($stored)->all();

        return FlooringSpecialty::catalog([
            ...SpecialtyOption::names(),
            ...$values,
            ...old('specialties', []),
        ]);
    }

    /**
     * @param  list<array{value: string, label: string}>  $catalog
     * @return list<string>
     */
    private function selectedFilterSpecialties(Request $request, array $catalog, bool $filtering): array
    {
        $all = array_map(fn (array $item): string => $item['value'], $catalog);
        if (! $filtering) {
            return $all;
        }

        $allowed = [];
        foreach ($all as $value) {
            $allowed[mb_strtolower($value)] = $value;
        }

        $selected = [];
        foreach ((array) $request->input('vakkennis', []) as $value) {
            $key = mb_strtolower(trim((string) $value));
            if ($key !== '' && isset($allowed[$key]) && ! in_array($allowed[$key], $selected, true)) {
                $selected[] = $allowed[$key];
            }
        }

        return $selected;
    }

    /**
     * @return list<array{specialty: string, label: string, unit: string, unit_price: mixed, unit_locked?: bool}>
     */
    private function rateRows(Worker $worker): array
    {
        $existing = $worker->rates->keyBy(
            fn ($rate): string => mb_strtolower((string) $rate->specialty).'|'.$rate->unit->value
        );
        $cases = $worker->specialtyCases();
        if ($cases === []) {
            $cases = FlooringSpecialty::cases();
        }

        $rows = [];
        foreach ($cases as $case) {
            if (! $case->hasQuantityRate()) {
                continue;
            }

            $unit = $case === FlooringSpecialty::Plinten ? WorkUnit::LinearMeter : WorkUnit::SquareMeter;
            $key = $case->value.'|'.$unit->value;
            $rate = $existing->get($key);
            $rows[] = [
                'specialty' => $case->value,
                'label' => $case->label(),
                'unit' => ($rate?->unit ?? $unit)->value,
                'unit_price' => $rate?->unit_price,
            ];
            $existing->forget($key);
        }

        foreach ($worker->specialtyValues() as $value) {
            if (FlooringSpecialty::caseFrom($value) instanceof FlooringSpecialty) {
                continue;
            }

            $key = mb_strtolower($value).'|'.WorkUnit::SquareMeter->value;
            $rate = $existing->get($key);
            $rows[] = [
                'specialty' => $value,
                'label' => $value,
                'unit' => ($rate?->unit ?? WorkUnit::SquareMeter)->value,
                'unit_price' => $rate?->unit_price,
            ];
            $existing->forget($key);
        }

        $hourlyKey = WorkerRate::HOURLY_SPECIALTY.'|'.WorkUnit::Hours->value;
        $hourly = $existing->get($hourlyKey) ?? $worker->hourlyRate();
        $rows[] = [
            'specialty' => WorkerRate::HOURLY_SPECIALTY,
            'label' => 'Uurtarief',
            'unit' => WorkUnit::Hours->value,
            'unit_price' => $hourly?->unit_price,
            'unit_locked' => true,
        ];
        foreach ($existing->keys() as $key) {
            if (str_starts_with((string) $key, WorkerRate::HOURLY_SPECIALTY.'|')) {
                $existing->forget($key);
            }
        }

        foreach ($existing as $rate) {
            $case = FlooringSpecialty::caseFrom($rate->specialty);
            $rows[] = [
                'specialty' => $rate->specialty,
                'label' => $case?->label() ?? $rate->specialty,
                'unit' => $rate->unit->value,
                'unit_price' => $rate->unit_price,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function loginRules(Request $request, bool $required): array
    {
        return $request->validate([
            'email' => $this->emailRules($request, $required),
            'invite' => ['sometimes', 'boolean'],
        ], [
            'email.required' => 'Vul een e-mailadres in voor de inlog.',
            'email.email' => 'Vul een geldig e-mailadres in.',
            'email.unique' => 'Dit e-mailadres is al in gebruik.',
        ]);
    }

    /**
     * @return list<mixed>
     */
    private function emailRules(Request $request, bool $required): array
    {
        $needsLogin = $required || $request->boolean('invite');

        return [
            $needsLogin ? 'required' : 'nullable',
            'email',
            'max:255',
            Rule::unique('users', 'email'),
        ];
    }

    private function createLogin(Worker $worker): User
    {
        $activations = app(AccountActivationService::class);

        return User::query()->create([
            'name' => $worker->name,
            'email' => strtolower(trim((string) $worker->email)),
            'password' => $activations->placeholderPassword(),
            'role' => UserRole::Vakman,
            'active' => true,
            'can_access_all_projects' => false,
            'worker_id' => $worker->id,
            'email_verified_at' => now(),
        ]);
    }

    private function sendPlanningInvite(Request $request, Worker $worker, ?User $account): void
    {
        if ($account === null) {
            return;
        }

        $activations = app(AccountActivationService::class);
        $url = $activations->url($activations->issue($account));

        if ($request->boolean('invite')) {
            Mail::to($account->email)->send(new WorkerPlanningInviteMail(
                $worker,
                $account,
                $url,
            ));
        }
    }

    private function savedStatus(Request $request, ?User $account, bool $createdWorker = true): string
    {
        if ($request->boolean('invite') && $account !== null) {
            return $createdWorker
                ? 'Vakman opgeslagen. Uitnodiging voor de planning is verstuurd.'
                : 'Inlog is klaar. Uitnodiging voor de planning is verstuurd.';
        }

        if ($account !== null) {
            return $createdWorker
                ? 'Vakman opgeslagen. Inlog is klaar.'
                : 'Inlog is klaar.';
        }

        return 'Vakman opgeslagen.';
    }
}
