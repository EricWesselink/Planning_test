import assert from 'node:assert/strict';
import test from 'node:test';
import { bindCalculationCreate, bindFileInputs, chosenFilesLabel, overallPercent, statusLabel, summaryLabel } from '../../resources/js/calculation-create.js';

test('names chosen files next to the browse button', () => {
    assert.equal(chosenFilesLabel([]), 'Geen bestanden geselecteerd.');
    assert.equal(chosenFilesLabel([{ name: 'bg.pdf' }]), 'bg.pdf');
    assert.equal(chosenFilesLabel([{ name: 'bg.pdf' }, { name: '1e.xlsx' }]), '2 bestanden geselecteerd.');
});

test('syncs the chosen-file label when the input changes', () => {
    const label = { textContent: '' };
    const listeners = {};
    const input = {
        id: 'drawings',
        files: [],
        addEventListener(event, handler) {
            listeners[event] = handler;
        },
    };
    const root = {
        querySelectorAll(selector) {
            return selector === '[data-file-input]' ? [input] : [];
        },
        querySelector(selector) {
            return selector === '[data-file-chosen="drawings"]' ? label : null;
        },
    };

    bindFileInputs(root);

    assert.equal(label.textContent, 'Geen bestanden geselecteerd.');

    input.files = [{ name: 'bg.pdf' }];
    listeners.change();

    assert.equal(label.textContent, 'bg.pdf');
});

test('summarizes selected drawings and workbooks in Dutch', () => {
    assert.equal(summaryLabel({ drawings: 7, workbooks: 1 }), '7 tekeningen en 1 Excelbestand. Dit kan een paar minuten duren.');
    assert.equal(summaryLabel({ drawings: 1, workbooks: 0 }), '1 tekening. Dit kan een paar minuten duren.');
    assert.equal(summaryLabel({ drawings: 0, workbooks: 2 }), '2 Excelbestanden. Dit kan een paar minuten duren.');
    assert.equal(summaryLabel({ drawings: 0, workbooks: 0 }), 'Bestanden worden verwerkt. Dit kan even duren.');
});

test('progress bar starts moving and stays below 100 while waiting', () => {
    assert.equal(overallPercent({ elapsedMs: 0, drawings: 7, workbooks: 1 }), 4);
    assert.ok(overallPercent({ elapsedMs: 8000, drawings: 7, workbooks: 1 }) > 20);
    assert.ok(overallPercent({ elapsedMs: 8000, drawings: 7, workbooks: 1 }) < overallPercent({ elapsedMs: 30000, drawings: 7, workbooks: 1 }));
    assert.equal(overallPercent({ elapsedMs: 10 * 60 * 1000, drawings: 7, workbooks: 1 }), 92);
});

test('status names the current wait and shows a percentage', () => {
    assert.equal(statusLabel({ elapsedMs: 400, drawings: 7, workbooks: 1, percent: 8 }), 'Bestanden uploaden… 8%');
    assert.equal(statusLabel({ elapsedMs: 4000, drawings: 7, workbooks: 1, percent: 31 }), 'Bestanden uploaden… 31%');
    assert.equal(statusLabel({ elapsedMs: 4000, drawings: 7, workbooks: 0, percent: 40 }), 'Bestanden uploaden… 40%');
    assert.equal(statusLabel({ elapsedMs: 4000, drawings: 1, workbooks: 0, percent: 22 }), 'Bestanden uploaden… 22%');
    assert.equal(statusLabel({ elapsedMs: 4000, drawings: 0, workbooks: 1, percent: 18 }), 'Bestanden uploaden… 18%');
});

test('submit reveals the overlay and disables the button', () => {
    const fill = { style: { width: '8%' } };
    const bar = {
        attrs: {},
        setAttribute(name, value) {
            this.attrs[name] = value;
        },
    };
    const status = { textContent: 'Bezig…' };
    const detail = { textContent: '' };
    const button = { disabled: false, textContent: 'Doorgaan' };
    const overlay = {
        classList: {
            tokens: new Set(['hidden']),
            add(name) {
                this.tokens.add(name);
            },
            remove(name) {
                this.tokens.delete(name);
            },
        },
        attrs: { hidden: '' },
        removeAttribute(name) {
            delete this.attrs[name];
        },
        setAttribute(name, value) {
            this.attrs[name] = value;
        },
        querySelector(selector) {
            return {
                '[data-progress-fill]': fill,
                '[data-progress-bar]': bar,
                '[data-progress-status]': status,
                '[data-progress-detail]': detail,
            }[selector];
        },
    };
    const listeners = {};
    const form = {
        querySelector(selector) {
            return {
                '#drawings': { files: { length: 7 } },
                '#workbooks': { files: { length: 1 } },
                '[data-calculation-submit]': button,
            }[selector];
        },
        addEventListener(event, handler) {
            listeners[event] = handler;
        },
    };

    bindCalculationCreate(form, overlay, {
        Date: { now: () => 0 },
        setInterval() {
            return 0;
        },
    });
    listeners.submit();

    assert.equal(overlay.classList.tokens.has('hidden'), false);
    assert.equal(overlay.classList.tokens.has('flex'), true);
    assert.equal(overlay.attrs.hidden, undefined);
    assert.equal(overlay.attrs['aria-busy'], 'true');
    assert.equal(button.disabled, true);
    assert.equal(button.textContent, 'Bezig…');
    assert.equal(detail.textContent, '7 tekeningen en 1 Excelbestand. Dit kan een paar minuten duren.');
    assert.equal(status.textContent, 'Bestanden uploaden… 4%');
    assert.equal(fill.style.width, '4%');
    assert.equal(bar.attrs['aria-valuenow'], '4');
});
