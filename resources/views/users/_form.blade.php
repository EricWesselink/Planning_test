@php
    $actor = auth()->user();
    $isSelf = $actor?->is($user);
    $lastAdmin = $user->exists && $user->isLastActiveAdmin();
    $access = old('project_access', $user->can_access_all_projects ? 'all' : 'selected');
    $selectedProjects = collect(old('project_ids', $user->projects?->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id);
    $isVakmanRole = old('role', $user->role?->value) === \App\Enums\UserRole::Vakman->value;
    $lockRights = $isSelf;
    $selectedPermissions = collect(old('permissions', $user->permissions ?? []));
    $permissionGroups = \App\Support\PermissionCatalog::groups();
@endphp
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">Naam</label>
    <input type="text" name="name" value="{{ old('name', $user->name) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
</div>
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">E-mail</label>
    <input type="email" name="email" value="{{ old('email', $user->email) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
</div>
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">Rol</label>
    <select name="role" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" @disabled($lastAdmin || $lockRights)>
        <optgroup label="Standaard rol">
            @foreach (\App\Enums\UserRole::officeStandardCases() as $role)
                <option value="{{ $role->value }}" @selected(old('role', $user->role?->value) === $role->value)>{{ $role->label() }}</option>
            @endforeach
            <option value="{{ \App\Enums\UserRole::Vakman->value }}" @selected(old('role', $user->role?->value) === \App\Enums\UserRole::Vakman->value)>{{ \App\Enums\UserRole::Vakman->label() }}</option>
        </optgroup>
        <optgroup label="Rechten">
            <option value="{{ \App\Enums\UserRole::AlleenLezen->value }}" @selected(old('role', $user->role?->value) === \App\Enums\UserRole::AlleenLezen->value)>{{ \App\Enums\UserRole::AlleenLezen->label() }}</option>
            <option value="{{ \App\Enums\UserRole::Aangepast->value }}" @selected(old('role', $user->role?->value) === \App\Enums\UserRole::Aangepast->value)>{{ \App\Enums\UserRole::Aangepast->label() }}</option>
        </optgroup>
    </select>
    @if ($lastAdmin)
        <input type="hidden" name="role" value="{{ \App\Enums\UserRole::Admin->value }}">
        <p class="mt-1 text-xs text-nicon-muted">Dit is de laatste actieve beheerder. De rol kan niet worden gewijzigd.</p>
    @elseif ($lockRights)
        <input type="hidden" name="role" value="{{ $user->role?->value }}">
        <p class="mt-1 text-xs text-nicon-muted">Je kunt je eigen rol en rechten niet wijzigen.</p>
    @endif
</div>
<div id="rechten" data-permission-matrix @hidden(old('role', $user->role?->value) !== \App\Enums\UserRole::Aangepast->value)>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="text-xs uppercase tracking-wide text-nicon-muted">Rechtenmatrix</div>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="border border-nicon-line px-2 py-1 text-xs" data-permission-preset="all">Alles toestaan</button>
            <button type="button" class="border border-nicon-line px-2 py-1 text-xs" data-permission-preset="view">Alleen lezen</button>
            <button type="button" class="border border-nicon-line px-2 py-1 text-xs" data-permission-preset="none">Alles uit</button>
        </div>
    </div>
    <p class="mt-1 text-xs text-nicon-muted">Zet eerst Zien aan. Wijzigrechten zonder Zien worden automatisch aangevuld.</p>
    <div class="mt-3 overflow-x-auto border border-nicon-line">
        <table class="w-full text-sm">
            <thead class="bg-nicon-paper text-left">
                <tr>
                    <th class="px-3 py-2">Onderdeel</th>
                    <th class="px-3 py-2">Rechten</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($permissionGroups as $group)
                <tr class="border-t border-nicon-line align-top">
                    <td class="px-3 py-2 font-medium whitespace-nowrap">{{ $group['label'] }}</td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap gap-x-4 gap-y-2">
                            @foreach ($group['permissions'] as $item)
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="permissions[]"
                                        value="{{ $item['permission']->value }}"
                                        data-permission
                                        data-group="{{ $group['key'] }}"
                                        data-kind="{{ $item['kind'] }}"
                                        @checked($selectedPermissions->contains($item['permission']->value))
                                        @disabled($lockRights)
                                    >
                                    {{ $item['label'] }}
                                </label>
                            @endforeach
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if ($lockRights)
        @foreach ($selectedPermissions as $permission)
            <input type="hidden" name="permissions[]" value="{{ $permission }}">
        @endforeach
    @endif
</div>
<div class="grid gap-3 sm:grid-cols-2">
    <div>
        <label class="text-xs uppercase tracking-wide text-nicon-muted">{{ $requirePassword ? 'Wachtwoord' : 'Nieuw wachtwoord' }}</label>
        <input type="password" name="password" {{ $requirePassword ? 'required' : '' }} autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
        @unless ($requirePassword)
            <p class="mt-1 text-xs text-nicon-muted">Leeg laten om het huidige wachtwoord te behouden.</p>
        @endunless
    </div>
    <div>
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Wachtwoord herhalen</label>
        <input type="password" name="password_confirmation" {{ $requirePassword ? 'required' : '' }} autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
    </div>
</div>
@if ($isSelf || $lastAdmin)
    <input type="hidden" name="active" value="1">
    <p class="text-sm text-nicon-muted">
        @if ($isSelf)
            Je eigen account blijft actief.
        @else
            De laatste actieve beheerder kan niet worden uitgeschakeld.
        @endif
    </p>
@else
    <label class="flex items-center gap-2 text-sm">
        <input type="hidden" name="active" value="0">
        <input type="checkbox" name="active" value="1" @checked((int) old('active', $user->active ?? 1) === 1)>
        Actief
    </label>
@endif
<div data-worker-access @hidden(! $isVakmanRole)>
    @include('users._team-fields', ['user' => $user, 'specialtyCatalog' => $specialtyCatalog ?? null])
</div>
<div data-project-access @hidden($isVakmanRole)>
    <div class="text-xs uppercase tracking-wide text-nicon-muted">Projecttoegang</div>
    <div class="mt-2 space-y-2 text-sm">
        <label class="flex items-center gap-2">
            <input type="radio" name="project_access" value="all" @checked($access === 'all')>
            Alle projecten
        </label>
        <label class="flex items-center gap-2">
            <input type="radio" name="project_access" value="selected" @checked($access === 'selected')>
            Alleen toegewezen projecten
        </label>
    </div>
    <div class="mt-3 space-y-2" data-project-list>
        @forelse ($projects as $project)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="project_ids[]" value="{{ $project->id }}" @checked($selectedProjects->contains((int) $project->id))>
                {{ $project->project_number }} · {{ $project->name }}
                @if ($project->isArchived())
                    <span class="text-nicon-muted">(archief)</span>
                @endif
            </label>
        @empty
            <p class="text-sm text-nicon-muted">Nog geen projecten om toe te wijzen.</p>
        @endforelse
    </div>
</div>
<script>
    (() => {
        const form = document.currentScript.closest('form');
        if (!form) return;
        const role = form.querySelector('[name="role"]');
        const projectAccess = form.querySelector('[data-project-access]');
        const workerAccess = form.querySelector('[data-worker-access]');
        const list = projectAccess?.querySelector('[data-project-list]');
        const radios = [...(projectAccess?.querySelectorAll('input[name="project_access"]') ?? [])];
        const team = form.querySelector('[data-team-fields]');
        const countInput = team?.querySelector('[data-crew-count]');
        const rows = team?.querySelector('[data-crew-rows]');
        const template = team?.querySelector('[data-crew-row-template]');
        const syncProjects = () => {
            const selected = radios.find((radio) => radio.checked)?.value === 'selected';
            if (list) list.hidden = ! selected;
        };
        const renderCrew = () => {
            if (! countInput || ! rows || ! template) return;
            let count = parseInt(countInput.value, 10);
            if (! Number.isFinite(count) || count < 1) count = 1;
            if (count > 50) count = 50;
            const existing = Array.from(rows.querySelectorAll('[data-crew-row]')).map((row) => ({
                id: row.querySelector('[data-crew-id]')?.value ?? '',
                name: row.querySelector('[data-crew-name]')?.value ?? '',
                email: row.querySelector('[data-crew-email]')?.value ?? '',
            }));
            rows.replaceChildren();
            for (let index = 0; index < count; index++) {
                const html = template.innerHTML
                    .replaceAll('__INDEX__', String(index))
                    .replaceAll('__NUMBER__', String(index + 1));
                const wrap = document.createElement('div');
                wrap.innerHTML = html.trim();
                const row = wrap.firstElementChild;
                if (! row) continue;
                const nameInput = row.querySelector('[data-crew-name]');
                const emailInput = row.querySelector('[data-crew-email]');
                const idInput = row.querySelector('[data-crew-id]');
                if (nameInput) nameInput.value = existing[index]?.name ?? '';
                if (emailInput) emailInput.value = existing[index]?.email ?? '';
                if (idInput) idInput.value = existing[index]?.id ?? '';
                rows.append(row);
            }
        };
        const matrix = form.querySelector('[data-permission-matrix]');
        const boxes = () => [...(matrix?.querySelectorAll('input[data-permission]') ?? [])];
        const applyPreset = (preset) => {
            boxes().forEach((box) => {
                if (preset === 'all') box.checked = true;
                else if (preset === 'none') box.checked = false;
                else box.checked = box.dataset.kind === 'view' || box.dataset.kind === 'extra' && /pdf|print|bekijken|zien/i.test(box.closest('label')?.textContent || '');
            });
            if (preset === 'view') {
                boxes().forEach((box) => {
                    box.checked = box.dataset.kind === 'view' || box.value.endsWith('.pdf') || box.value.endsWith('.print') || box.value === 'planning.week_pdf' || box.value === 'labor_costs.view' || box.value === 'reports.view' || box.value === 'files.view' || box.value === 'drawings.view' || box.value === 'meetstaat.view' || box.value === 'personnel_week.view';
                    if (box.value === 'users.view' || box.value === 'catalog.view') box.checked = false;
                });
            }
        };
        const syncGroup = (group) => {
            const groupBoxes = boxes().filter((box) => box.dataset.group === group);
            const viewBox = groupBoxes.find((box) => box.dataset.kind === 'view');
            const writes = groupBoxes.filter((box) => box !== viewBox);
            if (writes.some((box) => box.checked) && viewBox && ! viewBox.checked) {
                viewBox.checked = true;
            }
            if (viewBox && ! viewBox.checked) {
                writes.forEach((box) => { box.checked = false; });
            }
        };
        const syncRole = () => {
            const vakman = role?.value === '{{ \App\Enums\UserRole::Vakman->value }}';
            const custom = role?.value === '{{ \App\Enums\UserRole::Aangepast->value }}';
            if (projectAccess) projectAccess.hidden = vakman;
            if (workerAccess) workerAccess.hidden = ! vakman;
            if (matrix) matrix.hidden = ! custom;
            radios.forEach((radio) => { radio.disabled = vakman; });
            if (countInput) countInput.required = vakman;
            if (! vakman) syncProjects();
        };
        matrix?.querySelectorAll('[data-permission-preset]').forEach((button) => {
            button.addEventListener('click', () => applyPreset(button.dataset.permissionPreset));
        });
        boxes().forEach((box) => box.addEventListener('change', () => syncGroup(box.dataset.group)));
        radios.forEach((radio) => radio.addEventListener('change', syncProjects));
        role?.addEventListener('change', syncRole);
        countInput?.addEventListener('input', renderCrew);
        countInput?.addEventListener('change', renderCrew);
        syncRole();
    })();
</script>
