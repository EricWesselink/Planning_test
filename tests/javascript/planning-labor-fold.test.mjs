import assert from 'node:assert/strict';
import test from 'node:test';
import {
    LABOR_FOLD_KEY,
    applyLaborFold,
    isLaborFolded,
    persistLaborFold,
} from '../../resources/js/planning-labor-fold.js';

function memoryStorage(start = {}) {
    const data = { ...start };

    return {
        getItem(key) {
            return Object.hasOwn(data, key) ? data[key] : null;
        },
        setItem(key, value) {
            data[key] = String(value);
        },
    };
}

test('reads the folded labor preference from storage', () => {
    assert.equal(isLaborFolded(memoryStorage()), false);
    assert.equal(isLaborFolded(memoryStorage({ [LABOR_FOLD_KEY]: '1' })), true);
});

test('persists the folded labor preference', () => {
    const storage = memoryStorage();

    persistLaborFold(true, storage);
    assert.equal(storage.getItem(LABOR_FOLD_KEY), '1');

    persistLaborFold(false, storage);
    assert.equal(storage.getItem(LABOR_FOLD_KEY), '0');
});

test('collapses the labor block without changing chevron labels', () => {
    const button = {
        attrs: {},
        textContent: '›',
        setAttribute(name, value) {
            this.attrs[name] = value;
        },
    };
    const classes = new Set(['plan-board', 'plan-board--labor']);
    const board = {
        classList: {
            contains: (name) => classes.has(name),
            toggle(name, on) {
                if (on) {
                    classes.add(name);
                } else {
                    classes.delete(name);
                }
            },
        },
        ownerDocument: {
            querySelectorAll() {
                return [button];
            },
        },
    };

    applyLaborFold(board, true);

    assert.equal(classes.has('plan-board--labor-collapsed'), true);
    assert.equal(button.textContent, '›');
    assert.equal(button.attrs['aria-expanded'], 'false');
    assert.equal(button.attrs['aria-label'], 'Urenkolommen tonen');

    applyLaborFold(board, false);

    assert.equal(classes.has('plan-board--labor-collapsed'), false);
    assert.equal(button.textContent, '›');
    assert.equal(button.attrs['aria-expanded'], 'true');
    assert.equal(button.attrs['aria-label'], 'Urenkolommen invouwen');
});
