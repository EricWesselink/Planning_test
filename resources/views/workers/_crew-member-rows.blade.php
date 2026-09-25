@php
    $worker ??= new \App\Models\Worker;
    $oldMembers = old('crew_members');
    if (is_array($oldMembers)) {
        $crewMembers = \App\Models\Worker::normalizeCrewMembers(array_values($oldMembers), max(1, count($oldMembers)));
    } else {
        $crewMembers = $worker->crewMembersForForm();
        if ($crewMembers === []) {
            $crewMembers = [['name' => '', 'phone' => '', 'active' => true, 'registers_hours' => true]];
        }
    }
    $crewCount = count($crewMembers);
@endphp
<div>
    <div class="flex items-baseline justify-between gap-3">
        <div class="text-xs uppercase tracking-wide text-nicon-muted">Personen</div>
        <p class="text-sm font-medium text-nicon-ink" data-crew-total>{{ $crewCount === 1 ? '1 persoon' : $crewCount.' personen' }}</p>
    </div>
    <div class="mt-2 grid gap-2" data-crew-rows>
        @foreach ($crewMembers as $index => $member)
            @php
                $memberId = (int) ($member['id'] ?? 0);
                $memberActive = array_key_exists('active', $member) ? (bool) $member['active'] : true;
                $memberName = trim((string) ($member['name'] ?? '')) ?: 'Persoon '.($index + 1);
            @endphp
            <div @class(['border border-nicon-line px-3 py-2', 'opacity-60' => ! $memberActive]) data-crew-row>
                <div class="text-[11px] uppercase tracking-wide text-nicon-muted" data-crew-number>Persoon {{ $index + 1 }}</div>
                <div class="mt-1 grid gap-2 sm:grid-cols-2">
                    <input type="text" name="crew_members[{{ $index }}][name]" value="{{ $member['name'] }}" data-crew-name class="w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Voor- en achternaam" autocomplete="name" aria-label="Naam persoon {{ $index + 1 }}">
                    <input type="hidden" name="crew_members[{{ $index }}][id]" value="{{ $member['id'] ?? '' }}" data-crew-id>
                    <input type="tel" name="crew_members[{{ $index }}][phone]" value="{{ $member['phone'] }}" data-crew-phone class="w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="06 12345678" autocomplete="tel" aria-label="Telefoon persoon {{ $index + 1 }}">
                </div>
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
                        <button type="button" class="text-xs text-nicon-danger hover:text-nicon-ink" data-crew-remove>Verwijderen</button>
                    @endif
                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="crew_members[{{ $index }}][registers_hours]" value="0">
                        <input type="checkbox" name="crew_members[{{ $index }}][registers_hours]" value="1" data-crew-hours class="size-4 accent-nicon-ok" @checked($member['registers_hours'] ?? true)>
                        Uren registreren
                    </label>
                    @can('update', $worker)
                        @if ($worker->officeLoginForPerson((string) ($member['name'] ?? '')) === null)
                            <x-vakman-login-invite :worker="$worker" :member-id="$member['id'] ?? null" :phone="$member['phone']" />
                        @endif
                    @endcan
                </div>
            </div>
        @endforeach
    </div>
    <template data-crew-row-template>
        <div class="border border-nicon-line px-3 py-2" data-crew-row>
            <div class="text-[11px] uppercase tracking-wide text-nicon-muted" data-crew-number>Persoon __NUMBER__</div>
            <div class="mt-1 grid gap-2 sm:grid-cols-2">
                <input type="text" name="crew_members[__INDEX__][name]" value="" data-crew-name class="w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Voor- en achternaam" autocomplete="name">
                <input type="hidden" name="crew_members[__INDEX__][id]" value="" data-crew-id>
                <input type="tel" name="crew_members[__INDEX__][phone]" value="" data-crew-phone class="w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="06 12345678" autocomplete="tel">
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="crew_members[__INDEX__][active]" value="0">
                    <input type="checkbox" name="crew_members[__INDEX__][active]" value="1" data-crew-active class="size-4 accent-nicon-ok" checked>
                    Actief
                </label>
                <button type="button" class="text-xs text-nicon-danger hover:text-nicon-ink" data-crew-remove>Verwijderen</button>
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="crew_members[__INDEX__][registers_hours]" value="0">
                    <input type="checkbox" name="crew_members[__INDEX__][registers_hours]" value="1" data-crew-hours class="size-4 accent-nicon-ok" checked>
                    Uren registreren
                </label>
                <button type="button" disabled class="border border-nicon-line bg-nicon-paper px-3 py-2 text-xs text-nicon-muted" title="Sla eerst de gegevens op">Inlogbericht maken</button>
            </div>
        </div>
    </template>
    <button type="button" class="border border-nicon-line bg-white px-3 py-1.5 text-sm" data-crew-add>+ Persoon toevoegen</button>
</div>
@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-crew-fields]').forEach((root) => {
                const rows = root.querySelector('[data-crew-rows]');
                const template = root.querySelector('[data-crew-row-template]');
                const total = root.querySelector('[data-crew-total]');
                const addButton = root.querySelector('[data-crew-add]');
                if (! rows || ! template || ! addButton) {
                    return;
                }

                const refresh = () => {
                    const list = [...rows.querySelectorAll('[data-crew-row]')];
                    list.forEach((row, index) => {
                        row.querySelectorAll('[name^="crew_members["]').forEach((input) => {
                            input.name = input.name.replace(/crew_members\[\d+\]/, 'crew_members[' + index + ']');
                        });
                        const number = row.querySelector('[data-crew-number]');
                        if (number) {
                            number.textContent = 'Persoon ' + (index + 1);
                        }
                    });
                    if (total) {
                        total.textContent = list.length === 1 ? '1 persoon' : list.length + ' personen';
                    }
                    addButton.disabled = list.length >= 50;
                };

                addButton.addEventListener('click', () => {
                    const count = rows.querySelectorAll('[data-crew-row]').length;
                    if (count >= 50) {
                        return;
                    }
                    const html = template.innerHTML
                        .replaceAll('__INDEX__', String(count))
                        .replaceAll('__NUMBER__', String(count + 1));
                    const wrap = document.createElement('div');
                    wrap.innerHTML = html.trim();
                    const row = wrap.firstElementChild;
                    if (! row) {
                        return;
                    }
                    rows.append(row);
                    refresh();
                });

                rows.addEventListener('click', (event) => {
                    const remove = event.target.closest('[data-crew-remove]');
                    if (! remove || rows.querySelectorAll('[data-crew-row]').length < 2) {
                        return;
                    }
                    remove.closest('[data-crew-row]')?.remove();
                    refresh();
                });
            });
        });
    </script>
@endonce
