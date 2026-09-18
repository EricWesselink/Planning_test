@php
    $worker ??= new \App\Models\Worker;
    $crewCount = max(1, min(50, (int) old('people_count', $worker->rosterCount())));
    $oldMembers = old('crew_members');
    $crewMembers = \App\Models\Worker::normalizeCrewMembers(
        is_array($oldMembers) ? $oldMembers : $worker->crewMembersForForm(),
        $crewCount,
    );
@endphp
<div>
    <div class="text-xs uppercase tracking-wide text-nicon-muted">Namen van het team</div>
    <p class="mt-1 text-[11px] text-nicon-muted">Per persoon een naam en telefoonnummer. Het aantal rijen volgt Personen.</p>
    <div class="mt-2 grid gap-3" data-crew-rows>
        @foreach ($crewMembers as $index => $member)
            @php
                $memberId = (int) ($member['id'] ?? 0);
                $memberActive = array_key_exists('active', $member) ? (bool) $member['active'] : true;
                $memberName = trim((string) ($member['name'] ?? '')) ?: 'Persoon '.($index + 1);
            @endphp
            <div class="grid gap-3 sm:grid-cols-2{{ $memberActive ? '' : ' opacity-60' }}" data-crew-row>
                <div>
                    <label class="text-xs uppercase tracking-wide text-nicon-muted">Naam persoon {{ $index + 1 }}</label>
                    <input type="text" name="crew_members[{{ $index }}][name]" value="{{ $member['name'] }}" data-crew-name class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Voor- en achternaam" autocomplete="name">
                    <input type="hidden" name="crew_members[{{ $index }}][id]" value="{{ $member['id'] ?? '' }}" data-crew-id>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        @if ($memberId > 0 && $worker->exists)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="hidden" form="crew-member-active-{{ $memberId }}" name="active" value="0">
                                <input
                                    type="checkbox"
                                    form="crew-member-active-{{ $memberId }}"
                                    name="active"
                                    value="1"
                                    data-crew-active
                                    class="size-4 accent-nicon-ok"
                                    @checked($memberActive)
                                    onchange="this.form.submit()"
                                >
                                Actief
                            </label>
                            <button
                                type="submit"
                                form="crew-member-delete-{{ $memberId }}"
                                class="text-xs text-nicon-danger hover:text-nicon-ink"
                                onclick="return confirm({{ json_encode($memberName.' wordt verwijderd uit het team. Dit kan niet ongedaan worden gemaakt.') }})"
                            >Verwijderen</button>
                            @push('detached-forms')
                                <form id="crew-member-active-{{ $memberId }}" method="POST" action="{{ route('workers.crew-members.active.update', [$worker, $memberId]) }}">
                                    @csrf
                                    @method('PATCH')
                                </form>
                                <form id="crew-member-delete-{{ $memberId }}" method="POST" action="{{ route('workers.crew-members.destroy', [$worker, $memberId]) }}">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            @endpush
                        @else
                            <label class="flex items-center gap-2 text-sm">
                                <input type="hidden" name="crew_members[{{ $index }}][active]" value="0">
                                <input type="checkbox" name="crew_members[{{ $index }}][active]" value="1" data-crew-active class="size-4 accent-nicon-ok" @checked($memberActive)>
                                Actief
                            </label>
                        @endif
                    </div>
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wide text-nicon-muted">Telefoon</label>
                    <div class="mt-1 flex flex-wrap items-start gap-2">
                        <input type="tel" name="crew_members[{{ $index }}][phone]" value="{{ $member['phone'] }}" data-crew-phone class="min-w-40 flex-1 border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="06 12345678" autocomplete="tel">
                        @can('update', $worker)
                            <x-vakman-login-invite :worker="$worker" :member-id="$member['id'] ?? null" :phone="$member['phone']" />
                        @endcan
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <template data-crew-row-template>
        <div class="grid gap-3 sm:grid-cols-2" data-crew-row>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">Naam persoon __NUMBER__</label>
                <input type="text" name="crew_members[__INDEX__][name]" value="" data-crew-name class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Voor- en achternaam" autocomplete="name">
                <input type="hidden" name="crew_members[__INDEX__][id]" value="" data-crew-id>
                <label class="mt-2 flex items-center gap-2 text-sm">
                    <input type="hidden" name="crew_members[__INDEX__][active]" value="0">
                    <input type="checkbox" name="crew_members[__INDEX__][active]" value="1" data-crew-active class="size-4 accent-nicon-ok" checked>
                    Actief
                </label>
            </div>
            <div>
                <label class="text-xs uppercase tracking-wide text-nicon-muted">Telefoon</label>
                <div class="mt-1 flex flex-wrap items-start gap-2">
                    <input type="tel" name="crew_members[__INDEX__][phone]" value="" data-crew-phone class="min-w-40 flex-1 border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="06 12345678" autocomplete="tel">
                    <button type="button" disabled class="border border-nicon-line bg-nicon-paper px-3 py-2 text-xs text-nicon-muted" title="Sla eerst de gegevens op">Inlogbericht maken</button>
                </div>
            </div>
        </div>
    </template>
</div>
@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-crew-fields]').forEach((root) => {
                const countInput = root.querySelector('[data-crew-count]');
                const rows = root.querySelector('[data-crew-rows]');
                const template = root.querySelector('[data-crew-row-template]');
                if (! countInput || ! rows || ! template) {
                    return;
                }

                const render = () => {
                    let count = parseInt(countInput.value, 10);
                    if (! Number.isFinite(count) || count < 1) {
                        count = 1;
                    }
                    if (count > 50) {
                        count = 50;
                    }

                    const existing = Array.from(rows.querySelectorAll('[data-crew-row]')).map((row) => ({
                        id: row.querySelector('[data-crew-id]')?.value ?? '',
                        name: row.querySelector('[data-crew-name]')?.value ?? '',
                        phone: row.querySelector('[data-crew-phone]')?.value ?? '',
                        active: row.querySelector('[data-crew-active]')?.checked ?? true,
                    }));

                    rows.replaceChildren();
                    for (let index = 0; index < count; index++) {
                        const html = template.innerHTML
                            .replaceAll('__INDEX__', String(index))
                            .replaceAll('__NUMBER__', String(index + 1));
                        const wrap = document.createElement('div');
                        wrap.innerHTML = html.trim();
                        const row = wrap.firstElementChild;
                        if (! row) {
                            continue;
                        }
                        const nameInput = row.querySelector('[data-crew-name]');
                        const phoneInput = row.querySelector('[data-crew-phone]');
                        const idInput = row.querySelector('[data-crew-id]');
                        const activeInput = row.querySelector('[data-crew-active]');
                        if (nameInput) {
                            nameInput.value = existing[index]?.name ?? '';
                        }
                        if (phoneInput) {
                            phoneInput.value = existing[index]?.phone ?? '';
                        }
                        if (idInput) {
                            idInput.value = existing[index]?.id ?? '';
                        }
                        if (activeInput) {
                            activeInput.checked = existing[index]?.active ?? true;
                        }
                        const inviteButton = row.querySelector('button');
                        const savedId = existing[index]?.id ?? '';
                        const savedPhone = existing[index]?.phone ?? '';
                        if (inviteButton && savedId) {
                            const digits = savedPhone.replace(/\D/g, '');
                            if (digits.length >= 8) {
                                inviteButton.disabled = false;
                                inviteButton.type = 'submit';
                                inviteButton.setAttribute('form', 'vakman-login-invite-' + savedId);
                                inviteButton.className = 'border border-nicon-ink bg-nicon-ink px-3 py-2 text-xs font-medium text-white hover:bg-nicon-ink/90';
                                inviteButton.removeAttribute('title');
                            } else {
                                const hint = document.createElement('p');
                                hint.className = 'mt-1 text-[11px] text-nicon-muted';
                                hint.textContent = 'Geen telefoonnummer ingevuld';
                                inviteButton.insertAdjacentElement('afterend', hint);
                            }
                        }
                        rows.append(row);
                    }
                };

                countInput.addEventListener('input', render);
                countInput.addEventListener('change', render);
            });
        });
    </script>
@endonce
