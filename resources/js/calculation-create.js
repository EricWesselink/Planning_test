export function fileCounts(form) {
    const drawings = form?.querySelector('#drawings')?.files?.length ?? 0;
    const workbooks = form?.querySelector('#workbooks')?.files?.length ?? 0;

    return { drawings, workbooks };
}

export function summaryLabel({ drawings = 0, workbooks = 0 } = {}) {
    const parts = [];

    if (drawings === 1) {
        parts.push('1 tekening');
    } else if (drawings > 1) {
        parts.push(`${drawings} tekeningen`);
    }

    if (workbooks === 1) {
        parts.push('1 Excelbestand');
    } else if (workbooks > 1) {
        parts.push(`${workbooks} Excelbestanden`);
    }

    if (parts.length === 0) {
        return 'Bestanden worden verwerkt. Dit kan even duren.';
    }

    return `${parts.join(' en ')}. Dit kan een paar minuten duren.`;
}

export function overallPercent({ elapsedMs = 0, drawings = 0, workbooks = 0 } = {}) {
    const files = Math.max(1, drawings + workbooks);
    const expectedMs = Math.max(20000, files * 8000);
    const t = Math.max(0, elapsedMs) / expectedMs;
    const eased = 1 - Math.exp(-3 * t);

    return Math.min(92, Math.round(4 + 88 * eased));
}

export function statusLabel({ elapsedMs = 0, drawings = 0, workbooks = 0, percent = 0 } = {}) {
    let phase = 'Bestanden verwerken…';

    if (elapsedMs < 2500) {
        phase = 'Bestanden uploaden…';
    } else if (drawings > 0 && workbooks > 0) {
        phase = 'Tekeningen en Excel uitlezen…';
    } else if (drawings > 1) {
        phase = `Tekeningen uitlezen (${drawings})…`;
    } else if (drawings === 1) {
        phase = 'Tekening uitlezen…';
    } else if (workbooks > 0) {
        phase = 'Excel uitlezen…';
    }

    return `${phase} ${percent}%`;
}

export function bindCalculationCreate(form, overlay, clock = globalThis) {
    if (! form || ! overlay) {
        return;
    }

    const fill = overlay.querySelector('[data-progress-fill]');
    const bar = overlay.querySelector('[data-progress-bar]');
    const status = overlay.querySelector('[data-progress-status]');
    const detail = overlay.querySelector('[data-progress-detail]');
    const button = form.querySelector('[data-calculation-submit]');

    form.addEventListener('submit', () => {
        const counts = fileCounts(form);

        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
        overlay.removeAttribute('hidden');
        overlay.setAttribute('aria-busy', 'true');

        if (detail) {
            detail.textContent = summaryLabel(counts);
        }

        if (button) {
            button.disabled = true;
            button.textContent = 'Bezig…';
        }

        const started = clock.Date.now();
        const tick = () => {
            const elapsedMs = clock.Date.now() - started;
            const percent = overallPercent({ elapsedMs, ...counts });
            const label = statusLabel({ elapsedMs, percent, ...counts });

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
    bindCalculationCreate(
        document.querySelector('[data-calculation-create]'),
        document.querySelector('[data-calculation-progress]'),
    );
}
