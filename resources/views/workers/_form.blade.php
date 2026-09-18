@php
    $worker ??= new \App\Models\Worker;
    $currentType = old('employment_type', $worker->employment_type?->value ?? \App\Enums\EmploymentType::Eigen->value);
    $showZzpFields = $currentType !== \App\Enums\EmploymentType::Eigen->value;
@endphp
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">Naam van het team</label>
    <input type="text" name="name" value="{{ old('name', $worker->name) }}" required class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
</div>
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">Type</label>
    <select name="employment_type" data-employment-type class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
        @foreach (\App\Enums\EmploymentType::cases() as $type)
            <option value="{{ $type->value }}" @selected($currentType === $type->value)>{{ $type->label() }}</option>
        @endforeach
    </select>
</div>
<div data-zzp-only @class(['space-y-4', 'hidden' => ! $showZzpFields])>
    <div>
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Bedrijf</label>
        <input type="text" name="company" value="{{ old('company', $worker->company) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Alleen bij ZZP / onderaannemer">
    </div>
    <div>
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Adres</label>
        <input type="text" name="address" value="{{ old('address', $worker->address) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Straat 12">
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Postcode</label>
            <input type="text" name="postal_code" value="{{ old('postal_code', $worker->postal_code) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="8013 PM">
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Plaats</label>
            <input type="text" name="city" value="{{ old('city', $worker->city) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="Zwolle">
        </div>
    </div>
    <div>
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Contactpersoon</label>
        <input type="text" name="contact_name" value="{{ old('contact_name', $worker->contact_name) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
    </div>
</div>
<div>
    @include('workers._specialties', ['worker' => $worker])
</div>
<div data-crew-fields class="space-y-3">
    <div class="w-28 sm:w-40">
        <label class="text-xs uppercase tracking-wide text-nicon-muted">Personen</label>
        <input type="number" name="people_count" value="{{ old('people_count', $worker->rosterCount()) }}" min="1" max="50" required data-crew-count class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
    </div>
    @include('workers._crew-member-rows', ['worker' => $worker])
</div>
<div>
    <label class="text-xs uppercase tracking-wide text-nicon-muted">E-mail{{ $worker->exists ? '' : ' (inlog, optioneel)' }}</label>
    <input type="email" name="email" value="{{ old('email', $worker->email) }}" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm" placeholder="naam@niconvloeren.nl" autocomplete="off">
</div>
@unless ($worker->exists)
    <p class="text-xs text-nicon-muted">E-mail en tijdelijk wachtwoord zijn optioneel. Zonder inlog staat het team al in de lijst.</p>
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Tijdelijk wachtwoord</label>
            <input type="password" name="password" minlength="8" autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
        </div>
        <div>
            <label class="text-xs uppercase tracking-wide text-nicon-muted">Wachtwoord herhalen</label>
            <input type="password" name="password_confirmation" minlength="8" autocomplete="new-password" class="mt-1 w-full border border-nicon-line px-3 py-2 bg-white text-sm">
        </div>
    </div>
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="invite" value="1" @checked((int) old('invite') === 1) class="size-4 accent-nicon-ok">
        Stuur uitnodiging voor de planning
    </label>
@endunless
<label class="flex items-center gap-2 text-sm">
    <input type="hidden" name="active" value="0">
    <input type="checkbox" name="active" value="1" @checked((int) old('active', $worker->active ?? 1) === 1)>
    Actief
</label>
@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const typeSelect = document.querySelector('[data-employment-type]');
            if (! typeSelect) {
                return;
            }

            const sync = () => {
                const showZzp = typeSelect.value !== 'eigen';
                document.querySelectorAll('[data-zzp-only]').forEach((block) => {
                    block.classList.toggle('hidden', ! showZzp);
                });
            };

            typeSelect.addEventListener('change', sync);
            sync();
        });
    </script>
@endonce
