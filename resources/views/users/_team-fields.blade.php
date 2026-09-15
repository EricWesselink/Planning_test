@php
    $worker = $user->worker ?? new \App\Models\Worker([
        'employment_type' => \App\Enums\EmploymentType::Eigen,
        'people_count' => 1,
    ]);
    $crewCount = max(1, min(50, (int) old('people_count', $worker->peopleCount() ?: 1)));
    $oldMembers = old('crew_members');
    if (is_array($oldMembers)) {
        $raw = array_values($oldMembers);
        $crewMembers = \App\Models\Worker::normalizeCrewMembers($raw, $crewCount);
        foreach ($crewMembers as $index => $member) {
            $crewMembers[$index]['email'] = (string) ($raw[$index]['email'] ?? '');
        }
    } else {
        $people = $worker->relationLoaded('crewPeople') ? $worker->crewPeople : collect();
        if ($worker->exists && $people->isNotEmpty()) {
            $crewMembers = $worker->crewMembersForForm();
            foreach ($crewMembers as $index => $member) {
                $id = (int) ($member['id'] ?? 0);
                $person = $id > 0 ? $people->firstWhere('id', $id) : ($people[$index] ?? null);
                $crewMembers[$index]['email'] = (string) ($person?->user?->email ?? '');
            }
        } else {
            $crewMembers = [];
            for ($index = 0; $index < $crewCount; $index++) {
                $person = $people[$index] ?? null;
                $crewMembers[] = [
                    'id' => $person?->id,
                    'name' => $person?->name ?? '',
                    'email' => $person?->user?->email ?? '',
                ];
            }
        }
    }
    $hasDetails = filled(old('phone', $worker->phone))
        || filled(old('address', $worker->address))
        || filled(old('postal_code', $worker->postal_code))
        || filled(old('city', $worker->city))
        || filled(old('contact_name', $worker->contact_name));
@endphp
<div data-team-fields class="space-y-3">
    <div>
        <div class="text-xs uppercase tracking-wide text-nicon-muted">Team</div>
        <p class="mt-1 text-xs text-nicon-muted">Kies eigen personeel of ZZP, het aantal personen, vakkennis, en wie met een eigen e-mail mag inloggen.</p>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted" for="employment_type">Eigen personeel of ZZP</label>
            <select id="employment_type" name="employment_type" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
                <option value="{{ \App\Enums\EmploymentType::Eigen->value }}" @selected(old('employment_type', $worker->employment_type?->value) === \App\Enums\EmploymentType::Eigen->value)>Eigen medewerker</option>
                <option value="{{ \App\Enums\EmploymentType::Zzp->value }}" @selected(old('employment_type', $worker->employment_type?->value) === \App\Enums\EmploymentType::Zzp->value)>ZZP</option>
                <option value="{{ \App\Enums\EmploymentType::Onderaannemer->value }}" @selected(old('employment_type', $worker->employment_type?->value) === \App\Enums\EmploymentType::Onderaannemer->value)>Onderaannemer</option>
            </select>
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted" for="people_count">Aantal personen</label>
            <input id="people_count" type="number" name="people_count" min="1" max="50" value="{{ $crewCount }}" data-crew-count class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
        </div>
    </div>
    @include('workers._specialties', [
        'worker' => $worker,
        'compact' => true,
        'hint' => 'Meerdere keuzes mogelijk.',
        'specialtyCatalog' => $specialtyCatalog ?? null,
    ])
    <div>
        <div class="text-xs uppercase tracking-wide text-nicon-muted">Leden</div>
        <p class="mt-1 text-[11px] text-nicon-muted">E-mail en wachtwoord alleen bij wie zelf mag inloggen. Leeg laten = geen inlog.</p>
        <div class="mt-2 space-y-2" data-crew-rows>
            @foreach ($crewMembers as $index => $member)
                <div class="grid gap-2 sm:grid-cols-3 border border-nicon-line p-2" data-crew-row>
                    <div>
                        <label class="text-[10px] uppercase tracking-wide text-nicon-muted">Naam persoon {{ $index + 1 }}</label>
                        <input type="text" name="crew_members[{{ $index }}][name]" value="{{ $member['name'] }}" data-crew-name class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="Voor- en achternaam" autocomplete="name">
                        <input type="hidden" name="crew_members[{{ $index }}][id]" value="{{ $member['id'] ?? '' }}" data-crew-id>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-wide text-nicon-muted">E-mail (inlog)</label>
                        <input type="email" name="crew_members[{{ $index }}][email]" value="{{ $member['email'] ?? '' }}" data-crew-email class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="leeg = geen inlog" autocomplete="off">
                    </div>
                    <div>
                        <label class="text-[10px] uppercase tracking-wide text-nicon-muted">Wachtwoord</label>
                        <input type="password" name="crew_members[{{ $index }}][password]" value="" data-crew-password class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="{{ ($member['email'] ?? '') !== '' ? 'Leeg = behouden' : 'Voor nieuwe inlog' }}" autocomplete="new-password">
                    </div>
                </div>
            @endforeach
        </div>
        <template data-crew-row-template>
            <div class="grid gap-2 sm:grid-cols-3 border border-nicon-line p-2" data-crew-row>
                <div>
                    <label class="text-[10px] uppercase tracking-wide text-nicon-muted">Naam persoon __NUMBER__</label>
                    <input type="text" name="crew_members[__INDEX__][name]" value="" data-crew-name class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="Voor- en achternaam" autocomplete="name">
                    <input type="hidden" name="crew_members[__INDEX__][id]" value="" data-crew-id>
                </div>
                <div>
                    <label class="text-[10px] uppercase tracking-wide text-nicon-muted">E-mail (inlog)</label>
                    <input type="email" name="crew_members[__INDEX__][email]" value="" data-crew-email class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="leeg = geen inlog" autocomplete="off">
                </div>
                <div>
                    <label class="text-[10px] uppercase tracking-wide text-nicon-muted">Wachtwoord</label>
                    <input type="password" name="crew_members[__INDEX__][password]" value="" data-crew-password class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="Voor nieuwe inlog" autocomplete="new-password">
                </div>
            </div>
        </template>
    </div>
    <details class="border border-nicon-line bg-nicon-paper/60 p-3" @if ($hasDetails) open @endif>
        <summary class="cursor-pointer text-xs uppercase tracking-wide text-nicon-muted">Bijzonderheden</summary>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label class="text-[10px] uppercase tracking-wide text-nicon-muted" for="team-phone">Telefoon</label>
                <input id="team-phone" type="tel" name="phone" value="{{ old('phone', $worker->phone) }}" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="06 12345678" autocomplete="tel">
            </div>
            <div>
                <label class="text-[10px] uppercase tracking-wide text-nicon-muted" for="team-contact">Contactpersoon</label>
                <input id="team-contact" type="text" name="contact_name" value="{{ old('contact_name', $worker->contact_name) }}" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm">
            </div>
            <div class="sm:col-span-2">
                <label class="text-[10px] uppercase tracking-wide text-nicon-muted" for="team-address">Adres</label>
                <input id="team-address" type="text" name="address" value="{{ old('address', $worker->address) }}" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="Straat 12">
            </div>
            <div>
                <label class="text-[10px] uppercase tracking-wide text-nicon-muted" for="team-postal">Postcode</label>
                <input id="team-postal" type="text" name="postal_code" value="{{ old('postal_code', $worker->postal_code) }}" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="8013 PM">
            </div>
            <div>
                <label class="text-[10px] uppercase tracking-wide text-nicon-muted" for="team-city">Plaats</label>
                <input id="team-city" type="text" name="city" value="{{ old('city', $worker->city) }}" class="mt-0.5 w-full border border-nicon-line px-2 py-1.5 bg-white text-sm" placeholder="Zwolle">
            </div>
        </div>
    </details>
</div>
