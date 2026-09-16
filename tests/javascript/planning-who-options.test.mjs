import assert from 'node:assert/strict';
import test from 'node:test';
import { whoOptionList } from '../../resources/js/planning-who-options.js';

const candidates = [
    { id: 1, name: 'H.D. Verwoert', people_count: 4, selectable: false, status_label: '0/4 geschikt en beschikbaar' },
    { id: 2, name: 'Max Project', people_count: 1, selectable: false, status_label: 'Geen vakkennis: PVC' },
    { id: 3, name: 'Team 4 Lukasz', people_count: 1, selectable: true, status_label: 'Beschikbaar' },
    { id: 4, name: 'Team 5 Lukas', people_count: 3, selectable: true, status_label: '3/3 geschikt en beschikbaar' },
];

test('keeps unsuitable and busy vakmensen enabled with their status label', () => {
    const options = whoOptionList(candidates);

    assert.equal(options[0].disabled, false);
    assert.deepEqual(options.slice(1).map((option) => ({
        value: option.value,
        disabled: option.disabled,
        selectable: option.selectable,
        label: option.label,
    })), [
        {
            value: 'worker:1',
            disabled: false,
            selectable: false,
            label: 'H.D. Verwoert — 0/4 geschikt en beschikbaar',
        },
        {
            value: 'worker:2',
            disabled: false,
            selectable: false,
            label: 'Max Project — Geen vakkennis: PVC',
        },
        {
            value: 'worker:3',
            disabled: false,
            selectable: true,
            label: 'Team 4 Lukasz — Beschikbaar',
        },
        {
            value: 'worker:4',
            disabled: false,
            selectable: true,
            label: 'Team 5 Lukas — 3/3 geschikt en beschikbaar',
        },
    ]);
});

test('keeps a previously chosen weaker-fit vakman selected', () => {
    const options = whoOptionList(candidates, 'worker:2');
    const chosen = options.find((option) => option.value === 'worker:2');

    assert.equal(chosen?.selected, true);
    assert.equal(chosen?.disabled, false);
    assert.equal(options.filter((option) => option.selected).length, 1);
});
