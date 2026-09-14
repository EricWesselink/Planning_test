@props([
    'worker',
    'memberId' => null,
    'phone' => '',
])

@php
    $rawId = $memberId;
    $memberId = is_numeric($rawId) && (int) $rawId > 0 ? (int) $rawId : null;
    $hasPhone = \App\Support\PhoneNumber::hasNumber($phone);
    $invite = session('vakman_login_invite');
    $showResult = is_array($invite) && $memberId !== null && (int) ($invite['crew_member_id'] ?? 0) === $memberId;
@endphp

<div class="shrink-0">
    @if ($memberId === null)
        <button type="button" disabled class="border border-nicon-line bg-nicon-paper px-3 py-2 text-xs text-nicon-muted" title="Sla eerst de gegevens op">Inlogbericht maken</button>
    @elseif (! $hasPhone)
        <button type="button" disabled class="border border-nicon-line bg-nicon-paper px-3 py-2 text-xs text-nicon-muted">Inlogbericht maken</button>
        <p class="mt-1 text-[11px] text-nicon-muted">Geen telefoonnummer ingevuld</p>
    @else
        <button
            type="submit"
            form="vakman-login-invite-{{ $memberId }}"
            class="border border-nicon-ink bg-nicon-ink px-3 py-2 text-xs font-medium text-white hover:bg-nicon-ink/90"
        >Inlogbericht maken</button>
    @endif
</div>

@if ($showResult)
    <div class="mt-2 w-full min-w-0 space-y-2 border border-nicon-line bg-white p-3 text-sm" data-login-invite-result>
        <label class="text-[10px] uppercase tracking-wide text-nicon-muted">WhatsApp-bericht</label>
        <textarea readonly rows="10" class="mt-1 w-full border border-nicon-line bg-nicon-paper px-2 py-1.5 font-mono text-xs" data-copy-source>{{ $invite['message'] }}</textarea>
        <div class="flex flex-wrap gap-2">
            <a
                href="{{ $invite['whatsapp_url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                class="bg-nicon-ok px-3 py-2 text-xs font-medium text-white"
            >Open WhatsApp</a>
            <button type="button" class="border border-nicon-line bg-white px-3 py-2 text-xs" data-copy-invite>Bericht kopiëren</button>
        </div>
        @if (($invite['account_existed'] ?? false) && ! ($invite['password_generated'] ?? false))
            <button
                type="submit"
                form="vakman-login-reset-{{ $memberId }}"
                class="border border-nicon-line bg-nicon-paper px-3 py-2 text-xs"
                onclick="return confirm('Nieuw tijdelijk wachtwoord maken? Het oude wachtwoord werkt daarna niet meer.')"
            >Nieuw tijdelijk wachtwoord maken</button>
            @push('detached-forms')
                <form id="vakman-login-reset-{{ $memberId }}" method="POST" action="{{ route('workers.login-invite.reset', [$worker, $memberId]) }}">
                    @csrf
                </form>
            @endpush
        @endif
        <p class="text-[11px] text-nicon-muted" data-copy-status hidden>Bericht gekopieerd.</p>
    </div>
@endif

@if ($memberId !== null && $hasPhone)
    @push('detached-forms')
        <form id="vakman-login-invite-{{ $memberId }}" method="POST" action="{{ route('workers.login-invite.store', [$worker, $memberId]) }}">
            @csrf
        </form>
    @endpush
@endif

@once
    @push('scripts')
        <script>
            document.addEventListener('click', (event) => {
                const button = event.target.closest('[data-copy-invite]');
                if (! button) {
                    return;
                }
                const root = button.closest('[data-login-invite-result]');
                const source = root?.querySelector('[data-copy-source]');
                const status = root?.querySelector('[data-copy-status]');
                if (! source) {
                    return;
                }
                navigator.clipboard.writeText(source.value).then(() => {
                    if (status) {
                        status.hidden = false;
                    }
                });
            });
        </script>
    @endpush
@endonce
