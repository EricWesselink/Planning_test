@php
    $actor = auth()->user();
    $isSelf = $actor?->is($user);
    $lastAdmin = $user->exists && $user->isLastActiveAdmin();
    $access = old('project_access', $user->can_access_all_projects ? 'all' : 'selected');
    $selectedProjects = collect(old('project_ids', $user->projects?->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id);
    $isVakmanRole = old('role', $user->role?->value) === \App\Enums\UserRole::Vakman->value;
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
    <select name="role" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" @disabled($lastAdmin)>
        @foreach (\App\Enums\UserRole::cases() as $role)
            <option value="{{ $role->value }}" @selected(old('role', $user->role?->value) === $role->value)>{{ $role->label() }}</option>
        @endforeach
    </select>
    @if ($lastAdmin)
        <input type="hidden" name="role" value="{{ \App\Enums\UserRole::Admin->value }}">
        <p class="mt-1 text-xs text-nicon-muted">Dit is de laatste actieve beheerder. De rol kan niet worden gewijzigd.</p>
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
        const syncRole = () => {
            const vakman = role?.value === '{{ \App\Enums\UserRole::Vakman->value }}';
            if (projectAccess) projectAccess.hidden = vakman;
            if (workerAccess) workerAccess.hidden = ! vakman;
            radios.forEach((radio) => { radio.disabled = vakman; });
            if (countInput) countInput.required = vakman;
            if (! vakman) syncProjects();
        };
        radios.forEach((radio) => radio.addEventListener('change', syncProjects));
        role?.addEventListener('change', syncRole);
        countInput?.addEventListener('input', renderCrew);
        countInput?.addEventListener('change', renderCrew);
        syncRole();
    })();
</script>
