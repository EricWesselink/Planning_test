@props([
    'value' => null,
    'label' => 'Werkadres',
    'useOld' => true,
    'required' => false,
    'disabled' => false,
    'form' => null,
    'hint' => null,
    'showMaps' => false,
    'id' => null,
])

@php
    $current = $useOld ? old('work_address', $value ?? '') : ($value ?? '');
@endphp

<div data-work-address>
    @if (filled($label))
        <label @if ($id) for="{{ $id }}" @endif class="block text-xs uppercase tracking-wide text-nicon-muted">{{ $label }}</label>
    @endif
    <input
        @if ($id) id="{{ $id }}" @endif
        type="text"
        name="work_address"
        value="{{ $current }}"
        @if ($form) form="{{ $form }}" @endif
        placeholder="Willem Schuylenburglaan 40-9, 3571 SJ Utrecht"
        aria-label="Werkadres"
        data-address-line
        @required($required)
        @disabled($disabled)
        {{ $attributes->merge(['class' => 'w-full border border-nicon-line px-3 py-2']) }}
    >
    @error('work_address')
        <p class="mt-1 text-xs text-nicon-danger">{{ $message }}</p>
    @enderror
    @if (filled($hint))
        <p class="mt-1 text-xs text-nicon-muted">{{ $hint }}</p>
    @endif
    @if ($showMaps)
        <a href="#" target="_blank" rel="noopener noreferrer" data-address-maps class="mt-2 hidden text-sm text-nicon-orange-dark">Navigeren in Google Maps</a>
    @endif
</div>

@pushOnce('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelectorAll('[data-work-address]').forEach((root) => {
                    const input = root.querySelector('[data-address-line]');
                    const link = root.querySelector('[data-address-maps]');
                    if (! input || ! link) {
                        return;
                    }
                    const sync = () => {
                        const destination = input.value.trim();
                        if (! destination) {
                            link.classList.add('hidden');
                            link.removeAttribute('href');
                            return;
                        }
                        link.href = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(destination) + '&travelmode=driving';
                        link.classList.remove('hidden');
                    };
                    input.addEventListener('input', sync);
                    sync();
                });
            });
        </script>
@endPushOnce
