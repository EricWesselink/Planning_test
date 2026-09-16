import assert from 'node:assert/strict';
import test from 'node:test';
import { bindImportProgress, fileRowMarkup } from '../../resources/js/calculation-import-progress.js';

test('file row shows waiting processing ready or error', () => {
    assert.equal(
        fileRowMarkup({ name: 'bg.pdf', status: 'processing', label: 'verwerken' }).includes('verwerken'),
        true,
    );
    assert.equal(
        fileRowMarkup({ name: 'kapot.pdf', status: 'failed', label: 'fout', error: 'Timeout' }).includes('Timeout'),
        true,
    );
});

test('poll updates percent and redirects when the import is finished', async () => {
    const fill = { style: { width: '4%' } };
    const bar = {
        attrs: {},
        setAttribute(name, value) {
            this.attrs[name] = value;
        },
    };
    const status = { textContent: 'Bezig…' };
    const filesEl = { innerHTML: '' };
    const overlay = {
        getAttribute(name) {
            return name === 'data-status-url' ? '/calculaties/4/verwerken/status' : null;
        },
        querySelector(selector) {
            return {
                '[data-progress-fill]': fill,
                '[data-progress-bar]': bar,
                '[data-progress-status]': status,
                '[data-progress-files]': filesEl,
            }[selector];
        },
    };
    const assigned = [];
    let intervalFn = null;

    bindImportProgress(overlay, {
        fetch() {
            return Promise.resolve({
                ok: true,
                json() {
                    return {
                        percent: 100,
                        label: 'Bestanden uitgelezen',
                        finished: true,
                        redirect: '/calculaties/4/klaar',
                        files: [{ name: 'bg.pdf', status: 'ready', label: 'gereed', error: null }],
                    };
                },
            });
        },
        setInterval(fn) {
            intervalFn = fn;
            return 7;
        },
        clearInterval() {},
        location: {
            assign(url) {
                assigned.push(url);
            },
        },
    });

    await Promise.resolve();
    await Promise.resolve();

    assert.equal(fill.style.width, '100%');
    assert.equal(bar.attrs['aria-valuenow'], '100');
    assert.equal(status.textContent, 'Bestanden uitgelezen');
    assert.equal(filesEl.innerHTML.includes('bg.pdf'), true);
    assert.equal(assigned[0], '/calculaties/4/klaar');
    assert.equal(typeof intervalFn, 'function');
});
