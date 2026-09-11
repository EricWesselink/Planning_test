@php
    $lines = $lines ?? [];
    $showCompleted = $showCompleted ?? false;
    $canUpdate = $canUpdate ?? true;
    $placeholder = $placeholder ?? 'Marmoleum, PVC, plinten…';
@endphp
<div data-extra-lines>
    <div class="flex items-end justify-between gap-3">
        <span class="text-xs uppercase tracking-wide text-nicon-muted">Werk / materiaal</span>
        @if ($canUpdate)
            <button type="button" class="text-sm text-nicon-orange-dark" data-extra-lines-add>Regel toevoegen</button>
        @endif
    </div>
    <p class="mt-1 text-xs text-nicon-muted">Vul per regel in wat er bij hoort, bijvoorbeeld egaliseren en het vloermateriaal.</p>
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-nicon-muted">
                    <th class="pb-1 font-normal">Omschrijving</th>
                    <th class="w-24 pb-1 font-normal">m²</th>
                    @if ($showCompleted)
                        <th class="w-24 pb-1 font-normal">Gereed</th>
                    @endif
                    <th class="w-8 pb-1"></th>
                </tr>
            </thead>
            <tbody data-extra-lines-body>
                @foreach ($lines as $index => $line)
                    @include('projects.partials.extra-line', [
                        'index' => $index,
                        'line' => $line,
                        'showCompleted' => $showCompleted,
                        'canUpdate' => $canUpdate,
                        'placeholder' => $placeholder,
                    ])
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($canUpdate)
        <template data-extra-lines-template>
            @include('projects.partials.extra-line', [
                'index' => '__INDEX__',
                'line' => ['name' => '', 'quantity' => '', 'completed' => ''],
                'showCompleted' => $showCompleted,
                'canUpdate' => true,
                'placeholder' => $placeholder,
            ])
        </template>
    @endif
</div>
@once
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-extra-lines]').forEach((root) => {
                const body = root.querySelector('[data-extra-lines-body]');
                const template = root.querySelector('[data-extra-lines-template]');
                const add = root.querySelector('[data-extra-lines-add]');
                if (!body) return;

                const nextIndex = () => {
                    let max = -1;
                    body.querySelectorAll('[name^="lines["]').forEach((input) => {
                        const match = input.name.match(/^lines\[(\d+)\]/);
                        if (match) max = Math.max(max, Number(match[1]));
                    });
                    return max + 1;
                };

                add?.addEventListener('click', () => {
                    if (!template) return;
                    const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex()));
                    body.insertAdjacentHTML('beforeend', html);
                });

                body.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-extra-lines-remove]');
                    if (!button) return;
                    const rows = body.querySelectorAll('[data-extra-line]');
                    if (rows.length <= 1) {
                        button.closest('[data-extra-line]')?.querySelectorAll('input').forEach((input) => {
                            input.value = '';
                        });
                        return;
                    }
                    button.closest('[data-extra-line]')?.remove();
                });
            });
        });
    </script>
@endonce
