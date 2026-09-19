<?php

namespace App\Http\Controllers;

use App\Enums\EmploymentType;
use App\Enums\FlooringSpecialty;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\CrewMember;
use App\Models\Project;
use App\Models\SpecialtyOption;
use App\Models\User;
use App\Models\Worker;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with(['projects', 'worker', 'crewMember'])
            ->orderByRaw('last_login_at is null')
            ->orderByDesc('last_login_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.create', [
            'user' => new User([
                'active' => true,
                'can_access_all_projects' => true,
                'role' => UserRole::Uitvoerder,
            ]),
            'projects' => $this->projects(),
            'specialtyCatalog' => $this->specialtyCatalog(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', User::class);
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data) {
            $workerId = null;
            if ($data['role'] === UserRole::Vakman) {
                $workerId = $this->createTeam($data)->id;
            }

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $data['role'],
                'permissions' => $data['permissions'],
                'active' => $data['active'],
                'can_access_all_projects' => $data['can_access_all_projects'],
                'worker_id' => $workerId,
                'email_verified_at' => now(),
            ]);
            $this->syncProjects($user, $data);
            if ($user->isVakman()) {
                $this->syncMemberLogins($user, $data['crew_logins']);
            }

            return $user;
        });

        return redirect()
            ->route('users.show', $user)
            ->with('status', 'Gebruiker toegevoegd.');
    }

    public function show(User $user): View
    {
        Gate::authorize('view', $user);
        $user->load(['projects', 'worker.crewPeople.user', 'crewMember']);

        return view('users.show', [
            'user' => $user,
            'projects' => $this->projects(),
            'specialtyCatalog' => $this->specialtyCatalog($user->worker?->specialty),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        $data = $this->validated($request, $user);
        $this->authorizeAccountChanges($request->user(), $user, $data);

        DB::transaction(function () use ($user, $data) {
            $wasVakman = $user->isVakman();
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'permissions' => $data['permissions'],
                'active' => $data['active'],
                'can_access_all_projects' => $data['can_access_all_projects'],
            ]);

            if ($data['role'] === UserRole::Vakman) {
                $worker = $this->saveTeam($user, $data);
                $user->worker_id = $worker->id;
            } else {
                $user->worker_id = null;
                $user->crew_member_id = null;
            }

            if (! empty($data['password'])) {
                Gate::authorize('resetPassword', $user);
                $user->password = $data['password'];
            }

            $user->save();
            $this->syncProjects($user, $data);
            if ($user->isVakman()) {
                $this->syncMemberLogins($user, $data['crew_logins']);
            } elseif ($wasVakman) {
                $this->removeMemberLogins($user);
            }
        });

        return redirect()
            ->route('users.show', $user)
            ->with('status', 'Gegevens opgeslagen.');
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);
        $name = $user->name;
        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('status', $name.' is verwijderd.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $user = null): array
    {
        $passwordRules = $user === null
            ? ['required', 'string', 'min:8', 'confirmed']
            : ['nullable', 'string', 'min:8', 'confirmed'];

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'password' => $passwordRules,
            'role' => ['required', Rule::enum(UserRole::class)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::enum(Permission::class)],
            'active' => ['sometimes', 'boolean'],
            'employment_type' => ['required_if:role,'.UserRole::Vakman->value, 'nullable', Rule::enum(EmploymentType::class)],
            'people_count' => ['required_if:role,'.UserRole::Vakman->value, 'nullable', 'integer', 'min:1', 'max:50'],
            'crew_members' => ['nullable', 'array', 'max:50'],
            'crew_members.*.id' => ['nullable', 'integer', 'min:1'],
            'crew_members.*.name' => ['nullable', 'string', 'max:255'],
            'crew_members.*.email' => ['nullable', 'email', 'max:255'],
            'crew_members.*.password' => ['nullable', 'string', 'min:8'],
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['string', 'max:64', 'not_regex:/[,\r\n]/'],
            'phone' => ['nullable', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:255'],
            'project_access' => ['required_unless:role,'.UserRole::Vakman->value, Rule::in(['all', 'selected'])],
            'project_ids' => ['exclude_unless:project_access,selected', 'array'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
        ], [
            'name.required' => 'Vul een naam in.',
            'email.required' => 'Vul een e-mailadres in.',
            'email.email' => 'Vul een geldig e-mailadres in.',
            'email.unique' => 'Dit e-mailadres is al in gebruik.',
            'password.required' => 'Vul een wachtwoord in.',
            'password.min' => 'Het wachtwoord moet minstens 8 tekens zijn.',
            'password.confirmed' => 'De wachtwoorden komen niet overeen.',
            'role.required' => 'Kies een rol.',
            'employment_type.required_if' => 'Kies eigen personeel of ZZP.',
            'people_count.required_if' => 'Vul in met hoeveel personen dit team is.',
            'people_count.min' => 'Er moet minstens 1 persoon zijn.',
            'crew_members.*.email.email' => 'Vul een geldig e-mailadres in.',
            'crew_members.*.password.min' => 'Het wachtwoord moet minstens 8 tekens zijn.',
            'specialties.*.max' => 'Een onderdeel mag maximaal 64 tekens zijn.',
            'specialties.*.not_regex' => 'Gebruik geen komma in een onderdeel.',
            'project_access.required_unless' => 'Kies de projecttoegang.',
            'project_access.required' => 'Kies de projecttoegang.',
        ]);

        $validator->after(function ($validator) use ($request, $user): void {
            if ($request->input('role') !== UserRole::Vakman->value) {
                return;
            }

            $this->validateCrewLogins($validator, $request, $user);
        });

        $data = $validator->validate();
        $data['active'] = $request->boolean('active');
        $data['role'] = UserRole::from($data['role']);
        if ($data['role'] === UserRole::Aangepast) {
            $data['permissions'] = PermissionCatalog::sanitize($data['permissions'] ?? []);
        } else {
            $data['permissions'] = null;
        }
        $data['crew_logins'] = [];
        if ($data['role'] === UserRole::Vakman) {
            $data['can_access_all_projects'] = false;
            $data['project_ids'] = [];
            $data['employment_type'] = EmploymentType::from((string) $data['employment_type']);
            $data['people_count'] = max(1, (int) ($data['people_count'] ?? 1));
            $members = Worker::normalizeCrewMembers($request->input('crew_members', []), $data['people_count']);
            $data['crew_members'] = $members;
            $data['people_count'] = max(1, count($members));
            $data['crew_logins'] = $this->crewLoginsFromRequest($request, $data['people_count']);
            $data['specialties'] = $data['specialties'] ?? [];
            $data['phone'] = trim((string) ($data['phone'] ?? ''));
            $data['contact_name'] = trim((string) ($data['contact_name'] ?? ''));
            $data['address'] = trim((string) ($data['address'] ?? ''));
            $data['postal_code'] = trim((string) ($data['postal_code'] ?? ''));
            $data['city'] = trim((string) ($data['city'] ?? ''));
        } else {
            $data['can_access_all_projects'] = ($data['project_access'] ?? '') === 'all';
            $data['project_ids'] = $data['can_access_all_projects']
                ? []
                : array_map('intval', $data['project_ids'] ?? []);
        }

        return $data;
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     */
    private function validateCrewLogins($validator, Request $request, ?User $user): void
    {
        $accountEmail = strtolower(trim((string) $request->input('email')));
        $seen = $accountEmail !== '' ? [$accountEmail => true] : [];
        $ignoreIds = $this->existingLoginIds($user);

        foreach ($request->input('crew_members', []) as $index => $member) {
            if (! is_array($member)) {
                continue;
            }

            $email = strtolower(trim((string) ($member['email'] ?? '')));
            $password = (string) ($member['password'] ?? '');
            if ($email === '') {
                continue;
            }

            if ($email === $accountEmail) {
                continue;
            }

            if (isset($seen[$email])) {
                $validator->errors()->add(
                    'crew_members.'.$index.'.email',
                    'Dit e-mailadres is al in gebruik.',
                );

                continue;
            }
            $seen[$email] = true;

            $taken = User::query()
                ->where('email', $email)
                ->when($ignoreIds !== [], fn ($query) => $query->whereNotIn('id', $ignoreIds))
                ->exists();
            if ($taken) {
                $validator->errors()->add(
                    'crew_members.'.$index.'.email',
                    'Dit e-mailadres is al in gebruik.',
                );
            }

            $existing = $this->existingLoginForMember($user, $member, $email);
            if ($existing === null && $password === '') {
                $validator->errors()->add(
                    'crew_members.'.$index.'.password',
                    'Vul een wachtwoord in voor deze inlog.',
                );
            }
        }
    }

    /**
     * @return list<int>
     */
    private function existingLoginIds(?User $user): array
    {
        if ($user === null || $user->worker_id === null) {
            return $user?->id ? [(int) $user->id] : [];
        }

        return User::query()
            ->where('worker_id', $user->worker_id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function existingLoginForMember(?User $user, array $member, string $email): ?User
    {
        if ($user === null || $user->worker_id === null) {
            return null;
        }

        $memberId = (int) ($member['id'] ?? 0);
        $query = User::query()->where('worker_id', $user->worker_id);
        if ($memberId > 0) {
            $found = (clone $query)->where('crew_member_id', $memberId)->first();
            if ($found) {
                return $found;
            }
        }

        return $query->where('email', $email)->first();
    }

    /**
     * @return list<array{id: int, email: string, password: string}>
     */
    private function crewLoginsFromRequest(Request $request, int $count): array
    {
        $rows = [];
        foreach (array_values($request->input('crew_members', [])) as $index => $member) {
            if ($index >= $count || ! is_array($member)) {
                continue;
            }
            $rows[] = [
                'id' => (int) ($member['id'] ?? 0),
                'email' => strtolower(trim((string) ($member['email'] ?? ''))),
                'password' => (string) ($member['password'] ?? ''),
            ];
        }

        while (count($rows) < $count) {
            $rows[] = ['id' => 0, 'email' => '', 'password' => ''];
        }

        return array_slice($rows, 0, $count);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTeam(array $data): Worker
    {
        return Worker::query()->create($this->teamAttributes($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveTeam(User $user, array $data): Worker
    {
        $worker = $user->worker;
        if ($worker === null) {
            return $this->createTeam($data);
        }

        $worker->fill($this->teamAttributes($data))->save();

        return $worker;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function teamAttributes(array $data): array
    {
        $type = $data['employment_type'];
        $members = $data['crew_members'];
        $phone = (string) ($data['phone'] ?? '');
        if ($phone === '') {
            $phone = (string) (Worker::firstCrewPhone($members) ?? '');
        }

        $specialty = FlooringSpecialty::storedLabels($data['specialties'] ?? []);
        SpecialtyOption::rememberMany($data['specialties'] ?? []);

        return [
            'name' => $data['name'],
            'employment_type' => $type,
            'company' => $type->isExternal() ? $data['name'] : null,
            'people_count' => $data['people_count'],
            'crew_members' => $members,
            'crew_names' => Worker::joinedCrewNames($members),
            'phone' => $phone !== '' ? $phone : null,
            'email' => $data['email'],
            'contact_name' => ($data['contact_name'] ?? '') !== '' ? $data['contact_name'] : null,
            'address' => ($data['address'] ?? '') !== '' ? $data['address'] : null,
            'postal_code' => ($data['postal_code'] ?? '') !== '' ? $data['postal_code'] : null,
            'city' => ($data['city'] ?? '') !== '' ? $data['city'] : null,
            'specialty' => $specialty,
            'active' => true,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function specialtyCatalog(mixed $stored = null): array
    {
        $values = $stored === null
            ? Worker::query()->whereNotNull('specialty')->pluck('specialty')->all()
            : [(string) $stored];

        return FlooringSpecialty::catalog([
            ...SpecialtyOption::names(),
            ...$values,
            ...old('specialties', []),
        ]);
    }

    /**
     * @param  list<array{id: int, email: string, password: string}>  $logins
     */
    private function syncMemberLogins(User $account, array $logins): void
    {
        $worker = $account->worker()->with('crewPeople.user')->first();
        if ($worker === null) {
            return;
        }

        $keepIds = [(int) $account->id];
        $people = $worker->crewPeople->values();

        foreach ($people as $index => $member) {
            $login = $logins[$index] ?? ['email' => '', 'password' => ''];
            $email = $login['email'];
            if ($email === '') {
                $this->detachMemberLogin($member, $account);

                continue;
            }

            if ($email === strtolower($account->email)) {
                $account->forceFill(['crew_member_id' => $member->id])->save();
                $keepIds[] = (int) $account->id;

                continue;
            }

            $user = $member->user
                ?? User::query()
                    ->where('worker_id', $worker->id)
                    ->where('email', $email)
                    ->first();

            $attributes = [
                'name' => $member->label(),
                'email' => $email,
                'role' => UserRole::Vakman,
                'active' => true,
                'can_access_all_projects' => false,
                'worker_id' => $worker->id,
                'crew_member_id' => $member->id,
                'email_verified_at' => now(),
            ];
            if ($login['password'] !== '') {
                $attributes['password'] = $login['password'];
            } elseif ($user === null) {
                continue;
            }

            if ($user === null) {
                $user = User::query()->create($attributes);
            } else {
                $user->fill($attributes)->save();
            }
            $keepIds[] = (int) $user->id;
        }

        User::query()
            ->where('worker_id', $worker->id)
            ->whereNotIn('id', array_values(array_unique($keepIds)))
            ->whereNotNull('crew_member_id')
            ->get()
            ->each(fn (User $memberUser) => $memberUser->delete());
    }

    private function detachMemberLogin(CrewMember $member, User $account): void
    {
        $login = $member->user;
        if ($login === null || $login->is($account)) {
            if ($account->crew_member_id === $member->id) {
                $account->forceFill(['crew_member_id' => null])->save();
            }

            return;
        }

        $login->delete();
    }

    private function removeMemberLogins(User $account): void
    {
        if ($account->worker_id === null) {
            return;
        }

        User::query()
            ->where('worker_id', $account->worker_id)
            ->whereKeyNot($account->id)
            ->whereNotNull('crew_member_id')
            ->get()
            ->each(fn (User $memberUser) => $memberUser->delete());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function authorizeAccountChanges(User $actor, User $user, array $data): void
    {
        if (! $data['active']) {
            Gate::authorize('deactivate', $user);
        }

        if ($user->role === UserRole::Admin && $data['role'] !== UserRole::Admin) {
            Gate::authorize('demote', $user);
        }

        $roleChanged = $data['role'] !== $user->role;
        $permissionsChanged = ($data['permissions'] ?? null) !== $user->permissions;
        if ($roleChanged || $permissionsChanged) {
            Gate::authorize('updatePermissions', $user);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProjects(User $user, array $data): void
    {
        $user->projects()->sync($data['can_access_all_projects'] ? [] : $data['project_ids']);
    }

    /** @return Collection<int, Project> */
    private function projects(): Collection
    {
        return Project::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
