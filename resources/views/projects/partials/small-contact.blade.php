@php
    $canUpdate = $canUpdate ?? true;
    $contactProject = $project ?? null;
    $selectedRole = (string) old('contact_role', $contactProject?->contact_role ?? '');
    $contactRoles = $contactRoles ?? \App\Models\Project::contactRoleChoices();
@endphp
<div data-contact-role>
    <label class="block text-xs uppercase tracking-wide text-nicon-muted" for="contact_name">Contactpersoon</label>
    <input id="contact_name" name="contact_name" value="{{ old('contact_name', $contactProject?->contact_name) }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Naam" autocomplete="name" @disabled(! $canUpdate)>
    <div class="mt-2 grid gap-2 sm:grid-cols-2">
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="contact_phone">Telefoon</label>
            <input id="contact_phone" name="contact_phone" type="tel" value="{{ old('contact_phone', $contactProject?->contact_phone) }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="06 12345678" autocomplete="tel" @disabled(! $canUpdate)>
        </div>
        <div>
            <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="contact_role">Wie is het</label>
            <select id="contact_role" name="contact_role" data-contact-role-select class="mt-1 w-full border border-nicon-line bg-white px-3 py-2" @disabled(! $canUpdate)>
                <option value="">Kies rol</option>
                @foreach ($contactRoles as $value => $label)
                    <option value="{{ $value }}" @selected($selectedRole === (string) $value)>{{ $label }}</option>
                @endforeach
                <option value="{{ \App\Enums\ContactRole::CUSTOM }}" @selected($selectedRole === \App\Enums\ContactRole::CUSTOM)>Nieuwe rol…</option>
            </select>
        </div>
    </div>
    <div data-contact-role-custom class="mt-2{{ $selectedRole === \App\Enums\ContactRole::CUSTOM ? '' : ' hidden' }}">
        <label class="block text-[11px] uppercase tracking-wide text-nicon-muted" for="contact_role_custom">Nieuwe rol</label>
        <input id="contact_role_custom" name="contact_role_custom" value="{{ old('contact_role_custom') }}" class="mt-1 w-full border border-nicon-line px-3 py-2" placeholder="Bijvoorbeeld voorman" maxlength="32" @disabled(! $canUpdate)>
    </div>
    @if ($contactProject?->contact_phone)
        <a href="tel:{{ $contactProject->contact_phone }}" class="mt-2 inline-block text-sm text-nicon-orange-dark">{{ $contactProject->contact_phone }}</a>
    @endif
</div>

@pushOnce('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-contact-role]').forEach((root) => {
                const select = root.querySelector('[data-contact-role-select]');
                const extra = root.querySelector('[data-contact-role-custom]');
                const input = extra?.querySelector('input');
                const sync = () => {
                    const isNew = select?.value === {{ \Illuminate\Support\Js::from(\App\Enums\ContactRole::CUSTOM) }};
                    extra?.classList.toggle('hidden', !isNew);
                    if (input) {
                        input.required = Boolean(isNew);
                    }
                };
                select?.addEventListener('change', sync);
                sync();
            });
        });
    </script>
@endpushOnce
