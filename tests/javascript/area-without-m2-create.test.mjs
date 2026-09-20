import assert from 'node:assert/strict';
import test from 'node:test';
import { bindAreaWithoutM2Create, chosenFileLabel, describeFileInputs, overallPercent, snapshotClientUpload, statusLabel, summaryLabel } from '../../resources/js/area-without-m2-create.js';

test('explains that a large drawing can take minutes', () => {
    assert.match(summaryLabel(), /paar minuten/);
});

test('progress bar starts moving and stays below 100 while waiting', () => {
    assert.equal(overallPercent({ elapsedMs: 0 }), 5);
    assert.ok(overallPercent({ elapsedMs: 15000 }) > 20);
    assert.ok(overallPercent({ elapsedMs: 15000 }) < overallPercent({ elapsedMs: 45000 }));
    assert.equal(overallPercent({ elapsedMs: 10 * 60 * 1000 }), 94);
});

test('status names the current step and shows a percentage', () => {
    assert.equal(statusLabel({ percent: 8 }), 'Tekening uploaden… 8%');
    assert.equal(statusLabel({ percent: 40 }), 'Ruimtes, namen en maatvoering herkennen… 40%');
    assert.equal(statusLabel({ percent: 70 }), 'Schaal en contouren berekenen… 70%');
    assert.equal(statusLabel({ percent: 90 }), 'Oppervlaktes afronden… 90%');
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
    const button = { disabled: false, textContent: 'Oppervlaktes bepalen' };
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
            return selector === '[data-area-without-m2-submit]' ? button : null;
        },
        addEventListener(event, handler) {
            listeners[event] = handler;
        },
    };

    bindAreaWithoutM2Create(form, overlay, {
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
    assert.match(detail.textContent, /geüpload/);
    assert.equal(status.textContent, 'Tekening uploaden… 5%');
    assert.equal(fill.style.width, '5%');
    assert.equal(bar.attrs['aria-valuenow'], '5');
});

test('shows the selected filename before submit', () => {
    const input = {
        files: [{ name: 'proef.pdf' }],
        addEventListener(event, handler) {
            this.handler = event === 'change' ? handler : this.handler;
        },
    };
    const chosen = { textContent: '' };
    const form = {
        querySelector(selector) {
            if (selector === '#drawing') {
                return input;
            }
            if (selector === '[data-chosen-filename]') {
                return chosen;
            }

            return null;
        },
        addEventListener() {},
    };
    const overlay = {
        querySelector() {
            return null;
        },
    };

    bindAreaWithoutM2Create(form, overlay);
    input.handler();

    assert.equal(chosen.textContent, 'Gekozen bestand: proef.pdf');
});

test('chosen file label comes from input.files[0].name', () => {
    assert.equal(chosenFileLabel({ name: 'proef.pdf' }), 'Gekozen bestand: proef.pdf');
    assert.equal(chosenFileLabel(undefined), '');
});

test('lists every file input on the page', () => {
    const documentRef = {
        querySelectorAll() {
            return [
                { getAttribute: (name) => name === 'name' ? 'drawing' : null, id: 'drawing', files: [{ name: 'proef.pdf' }], form: { getAttribute: () => '/calculaties/zonder-m2', action: '/calculaties/zonder-m2' } },
            ];
        },
    };

    assert.match(describeFileInputs(documentRef), /name=drawing/);
    assert.match(describeFileInputs(documentRef), /bestand=proef.pdf/);
});

test('submit copies the selected filename onto hidden fields', () => {
    const fields = {
        '[data-client-filename]': { value: '' },
        '[data-client-size]': { value: '' },
        '[data-client-input-name]': { value: '' },
        '[data-client-file-input-count]': { value: '' },
        '[data-client-file-input-names]': { value: '' },
    };
    const input = {
        files: [{ name: 'proef.pdf', size: 12 }],
        name: 'drawing',
        getAttribute(name) {
            return name === 'name' ? 'drawing' : null;
        },
    };
    const documentRef = {
        querySelectorAll() {
            return [input];
        },
    };
    const form = {
        querySelector(selector) {
            return fields[selector] ?? null;
        },
    };

    const snapshot = snapshotClientUpload(form, input, documentRef);

    assert.equal(snapshot.filename, 'proef.pdf');
    assert.equal(fields['[data-client-filename]'].value, 'proef.pdf');
    assert.equal(fields['[data-client-input-name]'].value, 'drawing');
    assert.equal(fields['[data-client-file-input-count]'].value, '1');
});
