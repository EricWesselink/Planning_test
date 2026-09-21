import assert from 'node:assert/strict';
import test from 'node:test';
import { planningWorkChoices } from '../../resources/js/planning-work-choices.js';

test('keeps every work item checkable instead of merging the same type', () => {
    const choices = planningWorkChoices([
        { id: 1, name: 'Primen & Egaliseren', group: 'Primen & Egaliseren', type_key: 'ondergrond', project_id: 9 },
        { id: 2, name: 'Marmoleum', group: 'Linoleum', type_key: 'linoleum|m2', project_id: 9 },
        { id: 3, name: 'Linoleum', group: 'Linoleum', type_key: 'linoleum|m2', project_id: 9 },
        { id: 4, name: 'Overig vloerwerk', group: 'Overig vloerwerk', notes: 'schoonloopmat', type_key: 'overig|m2', project_id: 9 },
    ]);

    assert.deepEqual(choices.map((choice) => choice.id), [1, 2, 3, 4]);
    assert.deepEqual(choices.map((choice) => choice.label), [
        'Primen & Egaliseren',
        'Marmoleum',
        'Linoleum',
        'Overig vloerwerk — schoonloopmat',
    ]);
});
