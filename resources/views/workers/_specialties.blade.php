@php
    $worker ??= new \App\Models\Worker;
    $inputName = $inputName ?? 'specialties[]';
    $allowAdd = $allowAdd ?? true;
    $filterAction = $filterAction ?? null;
    $storeAction = $storeAction ?? null;
    $heading = $heading ?? 'Vakkennis';
    $hint = $hint ?? 'Wat voorkomt in projectstoffering. Meerdere keuzes mogelijk.';
    $specialtyCatalog = $specialtyCatalog ?? \App\Enums\FlooringSpecialty::catalog(old('specialties', $worker->specialtyValues()));
    $selected = collect($selected ?? old('specialties', $worker->specialtyValues()))->map(fn ($value) => (string) $value);
    $compact = $compact ?? false;
    $gridClass = $compact ? 'grid grid-cols-2 sm:grid-cols-3 gap-1.5' : 'grid grid-cols-5 gap-2';
    $choiceClass = $compact
        ? 'flex min-h-8 cursor-pointer items-center gap-2 border border-nicon-line bg-white px-2 py-1.5 text-xs has-[:checked]:border-nicon-ink has-[:checked]:bg-nicon-sand'
        : 'flex min-h-10 cursor-pointer items-center gap-2.5 border border-nicon-line bg-white px-3 py-2 text-sm has-[:checked]:border-nicon-ink has-[:checked]:bg-nicon-sand';
    $allSelected = $specialtyCatalog !== [] && collect($specialtyCatalog)->every(fn ($specialty) => $selected->contains($specialty['value']));
@endphp
<div class="border border-nicon-line bg-nicon-sand/60 {{ $compact ? 'p-3' : 'p-4' }}" data-specialty-picker @if ($filterAction) data-specialty-reset="{{ $filterAction }}" @endif>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <div class="text-xs uppercase tracking-wide text-nicon-muted">{{ $heading }}</div>
            <p class="mt-1 text-[11px] text-nicon-muted">{{ $hint }}</p>
        </div>
        <button type="button" data-specialty-toggle class="border border-nicon-line bg-white {{ $compact ? 'px-2 py-1 text-xs' : 'px-3 py-2 text-sm' }} shrink-0">{{ $allSelected ? 'Alles uitvinken' : 'Alles aanvinken' }}</button>
    </div>
    @if ($filterAction)
        <form method="GET" action="{{ $filterAction }}" class="mt-3" data-vakkennis-filter>
            <input type="hidden" name="zoeken" value="1">
            <div class="{{ $gridClass }}" data-specialty-list>
                @foreach ($specialtyCatalog as $specialty)
                    <label class="{{ $choiceClass }}">
                        <input type="checkbox" name="{{ $inputName }}" value="{{ $specialty['value'] }}" class="size-4 shrink-0 accent-nicon-ok" @checked($selected->contains($specialty['value']))>
                        <span>{{ $specialty['label'] }}</span>
                    </label>
                @endforeach
            </div>
        </form>
    @else
        <div class="mt-3 {{ $gridClass }}" data-specialty-list>
            @foreach ($specialtyCatalog as $specialty)
                <label class="{{ $choiceClass }}">
                    <input type="checkbox" name="{{ $inputName }}" value="{{ $specialty['value'] }}" class="size-4 shrink-0 accent-nicon-ok" @checked($selected->contains($specialty['value']))>
                    <span>{{ $specialty['label'] }}</span>
                </label>
            @endforeach
        </div>
    @endif
    @if ($allowAdd && $storeAction)
        <form method="POST" action="{{ $storeAction }}" class="mt-3 flex flex-col gap-2 sm:flex-row">
            @csrf
            <input type="text" name="onderdeel" maxlength="64" value="{{ old('onderdeel') }}" placeholder="Nieuw onderdeel, bijv. Parket" class="w-full flex-1 border border-nicon-line bg-white px-3 py-2 text-sm" autocomplete="off">
            <button class="border border-nicon-line bg-white px-4 py-2 text-sm shrink-0">Onderdeel toevoegen</button>
        </form>
    @elseif ($allowAdd)
        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
            <input type="text" maxlength="64" data-specialty-new placeholder="Nieuw onderdeel, bijv. Parket" class="w-full flex-1 border border-nicon-line bg-white px-3 py-2 text-sm" autocomplete="off">
            <button type="button" data-specialty-add class="border border-nicon-line bg-white px-4 py-2 text-sm shrink-0">Onderdeel toevoegen</button>
        </div>
        <template data-specialty-template>
            <label class="{{ $choiceClass }}">
                <input type="checkbox" name="{{ $inputName }}" value="" class="size-4 shrink-0 accent-nicon-ok" checked>
                <span></span>
            </label>
        </template>
    @endif
</div>
<script>
    (() => {
        const root = document.currentScript.previousElementSibling;
        if (! root?.hasAttribute('data-specialty-picker')) return;

        const list = root.querySelector('[data-specialty-list]');
        const form = root.querySelector('[data-vakkennis-filter]');
        const toggle = root.querySelector('[data-specialty-toggle]');
        const boxes = () => [...(list?.querySelectorAll('input[type="checkbox"]') ?? [])];
        const syncToggle = () => {
            if (! toggle) return;
            const items = boxes();
            const allOn = items.length > 0 && items.every((box) => box.checked);
            toggle.textContent = allOn ? 'Alles uitvinken' : 'Alles aanvinken';
        };

        if (toggle) {
            toggle.addEventListener('click', () => {
                const items = boxes();
                const allOn = items.length > 0 && items.every((box) => box.checked);
                if (allOn) {
                    items.forEach((box) => { box.checked = false; });
                    if (form) {
                        form.submit();
                        return;
                    }
                } else if (form && root.dataset.specialtyReset) {
                    window.location = root.dataset.specialtyReset;
                    return;
                } else {
                    items.forEach((box) => { box.checked = true; });
                }
                syncToggle();
            });
        }

        if (form) {
            form.querySelectorAll('input[type="checkbox"]').forEach((box) => {
                box.addEventListener('change', () => form.submit());
            });
        }

        const input = root.querySelector('[data-specialty-new]');
        const button = root.querySelector('[data-specialty-add]');
        const template = root.querySelector('[data-specialty-template]');
        if (! list || ! input || ! button || ! template) return;

        const existing = () => [...list.querySelectorAll('label')]
            .flatMap((row) => {
                const field = row.querySelector('input[type="checkbox"]');
                const text = row.querySelector('span');

                return [
                    (field?.value || '').trim().toLowerCase(),
                    (text?.textContent || '').trim().toLowerCase(),
                ];
            })
            .filter(Boolean);

        const add = () => {
            const name = (input.value || '').trim();
            if (name === '' || name.includes(',')) {
                return;
            }
            if (existing().includes(name.toLowerCase())) {
                const match = [...list.querySelectorAll('input[type="checkbox"]')]
                    .find((field) => (field.value || '').trim().toLowerCase() === name.toLowerCase());
                if (match) {
                    match.checked = true;
                }
                input.value = '';
                syncToggle();
                return;
            }
            const node = template.content.firstElementChild.cloneNode(true);
            const field = node.querySelector('input');
            const label = node.querySelector('span');
            field.value = name;
            label.textContent = name;
            list.append(node);
            input.value = '';
            input.focus();
            syncToggle();
        };

        button.addEventListener('click', add);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                add();
            }
        });
    })();
</script>
