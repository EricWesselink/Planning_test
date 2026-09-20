export function summaryLabel() {
    return 'De tekening wordt geüpload en daarna uitgelezen. Bij een grote plattegrond kan dit een paar minuten duren.';
}

export function overallPercent({ elapsedMs = 0 } = {}) {
    const expectedMs = 90_000;
    const t = Math.max(0, elapsedMs) / expectedMs;
    const eased = 1 - Math.exp(-2.2 * t);

    return Math.min(94, Math.round(5 + 89 * eased));
}

export function statusLabel({ percent = 0 } = {}) {
    if (percent < 22) {
        return `Tekening uploaden… ${percent}%`;
    }
    if (percent < 55) {
        return `Ruimtes, namen en maatvoering herkennen… ${percent}%`;
    }
    if (percent < 82) {
        return `Schaal en contouren berekenen… ${percent}%`;
    }

    return `Oppervlaktes afronden… ${percent}%`;
}

export function chosenFileLabel(file) {
    return file?.name ? 'Gekozen bestand: '+file.name : '';
}

export function describeFileInputs(documentRef) {
    const inputs = documentRef?.querySelectorAll?.('input[type="file"]');
    if (! inputs || inputs.length === 0) {
        return 'File-inputs op deze pagina: 0';
    }

    return [...inputs].map((el, index) => {
        const name = el.getAttribute?.('name') || el.name || '(geen name)';
        const id = el.id || '(geen id)';
        const file = el.files?.[0]?.name || '(leeg)';
        const formAction = el.form?.getAttribute?.('action') || el.form?.action || '(geen form)';

        return `${index + 1}. name=${name} id=${id} bestand=${file} form=${formAction}`;
    }).join('\n');
}

export function snapshotClientUpload(form, input, documentRef) {
    const file = input?.files?.[0] ?? null;
    const inputs = documentRef?.querySelectorAll?.('input[type="file"]');
    const count = inputs ? inputs.length : (input ? 1 : 0);
    const names = inputs
        ? [...inputs].map((el) => el.getAttribute?.('name') || el.name || '(geen name)').join(', ')
        : (input?.getAttribute?.('name') || input?.name || '');

    writeHidden(form, '[data-client-filename]', file?.name ?? '');
    writeHidden(form, '[data-client-size]', file ? String(file.size) : '');
    writeHidden(form, '[data-client-input-name]', input?.getAttribute?.('name') || input?.name || '');
    writeHidden(form, '[data-client-file-input-count]', String(count));
    writeHidden(form, '[data-client-file-input-names]', names);

    return {
        filename: file?.name ?? '',
        size: file?.size ?? 0,
        inputName: input?.getAttribute?.('name') || input?.name || '',
        fileInputCount: count,
    };
}

function writeHidden(form, selector, value) {
    const field = form?.querySelector?.(selector);
    if (field) {
        field.value = value;
    }
}

export function bindAreaWithoutM2Create(form, overlay, clock = globalThis, documentRef = globalThis.document) {
    if (! form || ! overlay) {
        return;
    }

    const fill = overlay.querySelector('[data-progress-fill]');
    const bar = overlay.querySelector('[data-progress-bar]');
    const status = overlay.querySelector('[data-progress-status]');
    const detail = overlay.querySelector('[data-progress-detail]');
    const button = form.querySelector('[data-area-without-m2-submit]');
    const input = form.querySelector('#drawing');
    const chosen = form.querySelector('[data-chosen-filename]');
    const inventory = documentRef?.querySelector?.('[data-file-input-inventory]');

    const syncChosen = () => {
        if (chosen) {
            chosen.textContent = chosenFileLabel(input?.files?.[0]);
        }
        if (inventory) {
            inventory.textContent = describeFileInputs(documentRef);
        }
    };

    input?.addEventListener('change', syncChosen);
    syncChosen();

    form.addEventListener('submit', () => {
        snapshotClientUpload(form, input, documentRef);
        syncChosen();

        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
        overlay.removeAttribute('hidden');
        overlay.setAttribute('aria-busy', 'true');

        if (detail) {
            const name = input?.files?.[0]?.name;
            detail.textContent = name ? `${summaryLabel()} Versturen: ${name}` : summaryLabel();
        }

        if (button) {
            button.disabled = true;
            button.textContent = 'Bezig…';
        }

        const started = clock.Date.now();
        const tick = () => {
            const elapsedMs = clock.Date.now() - started;
            const percent = overallPercent({ elapsedMs });
            const label = statusLabel({ percent });

            if (fill) {
                fill.style.width = `${percent}%`;
            }
            if (bar) {
                bar.setAttribute('aria-valuenow', String(percent));
            }
            if (status) {
                status.textContent = label;
            }
        };

        tick();
        clock.setInterval(tick, 250);
    });
}

if (typeof document !== 'undefined') {
    const form = document.querySelector('[data-area-without-m2-create]');
    const overlay = document.querySelector('[data-area-without-m2-progress]');
    bindAreaWithoutM2Create(form, overlay);

    if (typeof window !== 'undefined' && form) {
        window.addEventListener('pageshow', (event) => {
            const input = form.querySelector('#drawing');
            const chosen = form.querySelector('[data-chosen-filename]');
            const note = form.querySelector('[data-pageshow-debug]');
            const restored = input?.files?.[0]?.name;

            if (chosen) {
                chosen.textContent = chosenFileLabel(input?.files?.[0]);
            }

            if (note) {
                if (event.persisted) {
                    note.textContent = restored
                        ? 'Browser herstelde dit formulier (bfcache) met bestand: '+restored
                        : 'Browser herstelde dit formulier (bfcache); file-input is leeg.';
                } else {
                    note.textContent = restored
                        ? 'File-input bevat bij laden: '+restored
                        : 'File-input is bij laden leeg.';
                }
            }

            const inventory = document.querySelector('[data-file-input-inventory]');
            if (inventory) {
                inventory.textContent = describeFileInputs(document);
            }
        });
    }
}
