const INCLUDE_DEFAULTS = ['colored', 'rooms', 'codes', 'legend'];

export function printDocumentUrl(base, state) {
    const params = new URLSearchParams();
    const includes = state.include?.length ? state.include : INCLUDE_DEFAULTS;
    includes.forEach((value) => params.append('include[]', value));
    (state.drawingIds || []).forEach((id) => params.append('drawing_ids[]', String(id)));
    params.set('material_mode', state.materialMode === 'selected' ? 'selected' : 'all');
    if (state.materialMode === 'selected') {
        (state.materialKeys || []).forEach((key) => params.append('material_keys[]', key));
    }
    params.set('output', state.output === 'print' ? 'print' : 'pdf');

    const glue = String(base || '').includes('?') ? '&' : '?';

    return `${base}${glue}${params.toString()}`;
}

export function bindCalculationPrintDialog(dialog, fetchImpl = globalThis.fetch.bind(globalThis)) {
    if (!dialog) {
        return;
    }

    const drawingsEl = dialog.querySelector('[data-print-drawings]');
    const materialsEl = dialog.querySelector('[data-print-materials]');
    const allDrawings = dialog.querySelector('[data-print-all-drawings]');
    const materialAll = dialog.querySelector('[data-print-material-all]');
    const materialSelected = dialog.querySelector('[data-print-material-selected]');
    const errorEl = dialog.querySelector('[data-print-error]');
    const titleEl = dialog.querySelector('[data-print-title]');
    let options = {
        print_url: '',
        drawings: [],
        materials: [],
        defaults: { include: INCLUDE_DEFAULTS, drawing_ids: [], material_mode: 'all', material_keys: [] },
    };

    function setError(message) {
        if (!errorEl) {
            return;
        }
        errorEl.textContent = message || '';
        errorEl.hidden = !message;
    }

    function drawingBoxes() {
        return [...dialog.querySelectorAll('[data-print-drawing]')];
    }

    function materialBoxes() {
        return [...dialog.querySelectorAll('[data-print-material]')];
    }

    function syncDrawingsMaster() {
        const boxes = drawingBoxes();
        if (allDrawings && boxes.length > 0) {
            allDrawings.checked = boxes.every((box) => box.checked);
        }
    }

    function fillLists(preselect = {}) {
        if (drawingsEl) {
            drawingsEl.replaceChildren();
            options.drawings.forEach((drawing) => {
                const label = document.createElement('label');
                label.className = 'calc-print-check';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.dataset.printDrawing = String(drawing.id);
                const selected = preselect.drawingIds
                    ? preselect.drawingIds.map(Number).includes(Number(drawing.id))
                    : true;
                input.checked = selected;
                const text = document.createElement('span');
                text.textContent = drawing.label;
                label.append(input, text);
                drawingsEl.append(label);
            });
            if (options.drawings.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'text-sm text-nicon-muted';
                empty.textContent = 'Geen tekeningen bij deze calculatie.';
                drawingsEl.append(empty);
            }
        }
        if (materialsEl) {
            materialsEl.replaceChildren();
            options.materials.forEach((material) => {
                const label = document.createElement('label');
                label.className = 'calc-print-check';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.dataset.printMaterial = String(material.key);
                const swatch = document.createElement('i');
                swatch.className = 'calc-swatch';
                swatch.style.background = material.color || '#e7e5e4';
                const text = document.createElement('span');
                text.textContent = material.label;
                label.append(input, swatch, text);
                materialsEl.append(label);
            });
        }
        syncDrawingsMaster();
        setMaterialMode(preselect.materialMode || 'all', preselect.materialKeys || []);
    }

    function setMaterialMode(mode, keys = []) {
        const selected = mode === 'selected';
        if (materialAll) {
            materialAll.checked = !selected;
        }
        if (materialSelected) {
            materialSelected.checked = selected;
        }
        materialsEl?.classList.toggle('is-disabled', !selected);
        materialBoxes().forEach((box) => {
            box.disabled = !selected;
            box.checked = selected && keys.includes(box.dataset.printMaterial);
        });
    }

    function currentState(output) {
        const include = [...dialog.querySelectorAll('[data-print-include]:checked')].map((el) => el.value);
        const drawingIds = drawingBoxes().filter((box) => box.checked).map((box) => box.dataset.printDrawing);
        const materialMode = materialSelected?.checked ? 'selected' : 'all';
        const materialKeys = materialBoxes().filter((box) => box.checked).map((box) => box.dataset.printMaterial);

        return {
            include,
            drawingIds,
            materialMode,
            materialKeys,
            output,
        };
    }

    async function open(source) {
        setError('');
        const url = source.getAttribute('data-print-options-url');
        const preset = source.hasAttribute('data-print-current-drawing')
            ? (document.getElementById('draw-drawing')?.value || source.getAttribute('data-print-drawing-id'))
            : source.getAttribute('data-print-drawing-id');
        if (url) {
            const response = await fetchImpl(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                setError('Printopties konden niet worden geladen.');
                dialog.showModal();
                return;
            }
            options = await response.json();
        } else if (source.hasAttribute('data-print-payload')) {
            options = JSON.parse(source.getAttribute('data-print-payload') || '{}');
        }
        if (titleEl) {
            titleEl.textContent = options.name ? `Print / PDF · ${options.name}` : 'Print / PDF';
        }
        dialog.querySelectorAll('[data-print-include]').forEach((box) => {
            box.checked = (options.defaults?.include || INCLUDE_DEFAULTS).includes(box.value);
        });
        fillLists({
            drawingIds: preset ? [preset] : options.defaults?.drawing_ids,
            materialMode: options.defaults?.material_mode,
            materialKeys: options.defaults?.material_keys,
        });
        dialog.showModal();
    }

    function submit(output) {
        const state = currentState(output);
        if (state.include.length === 0) {
            setError('Kies minstens één onderdeel om te exporteren.');
            return;
        }
        const wantsDrawing = state.include.some((value) => ['colored', 'rooms', 'codes', 'legend'].includes(value));
        if (wantsDrawing && options.drawings.length > 0 && state.drawingIds.length === 0) {
            setError('Kies minstens één tekening.');
            return;
        }
        if (state.materialMode === 'selected' && state.materialKeys.length === 0) {
            setError('Kies minstens één materiaal.');
            return;
        }
        const target = printDocumentUrl(options.print_url, state);
        window.open(target, '_blank', 'noopener');
        dialog.close();
    }

    allDrawings?.addEventListener('change', () => {
        drawingBoxes().forEach((box) => {
            box.checked = allDrawings.checked;
        });
    });
    dialog.addEventListener('change', (event) => {
        if (event.target?.dataset?.printDrawing !== undefined) {
            syncDrawingsMaster();
        }
    });
    materialAll?.addEventListener('change', () => setMaterialMode('all'));
    materialSelected?.addEventListener('change', () => setMaterialMode('selected'));
    dialog.querySelector('[data-print-pdf]')?.addEventListener('click', () => submit('pdf'));
    dialog.querySelector('[data-print-print]')?.addEventListener('click', () => submit('print'));
    dialog.querySelector('[data-print-cancel]')?.addEventListener('click', () => dialog.close());

    document.querySelectorAll('[data-print-open]').forEach((button) => {
        button.addEventListener('click', () => {
            open(button).catch(() => setError('Printopties konden niet worden geladen.'));
        });
    });
}

if (typeof document !== 'undefined') {
    const dialog = document.getElementById('calc-print-dialog');
    if (dialog) {
        bindCalculationPrintDialog(dialog);
    } else {
        document.addEventListener('DOMContentLoaded', () => {
            const late = document.getElementById('calc-print-dialog');
            if (late) {
                bindCalculationPrintDialog(late);
            }
        });
    }
}
