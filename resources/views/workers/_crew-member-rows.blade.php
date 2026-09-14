@php
    $worker ??= new \App\Models\Worker;
    $crewCount = max(1, min(50, (int) old('people_count', $worker->peopleCount())));
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
            <div class="grid gap-3 sm:grid-cols-2" data-crew-row>
                <div>
                    <label class="text-xs uppercase tracking-wide text-nicon-muted">Naam persoon {{ $index + 1 }}</label>
                    <input type="text" name="crew_members[{{ $index }}][name]" value="{{ $member['name'] }}" data-crew-name class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Voor- en achternaam" autocomplete="name">
                    <input type="hidden" name="crew_members[{{ $index }}][id]" value="{{ $member['id'] ?? '' }}" data-crew-id>
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
                        if (nameInput) {
                            nameInput.value = existing[index]?.name ?? '';
                        }
                        if (phoneInput) {
                            phoneInput.value = existing[index]?.phone ?? '';
                        }
                        if (idInput) {
                            idInput.value = existing[index]?.id ?? '';
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
