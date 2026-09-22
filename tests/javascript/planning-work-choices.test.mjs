import assert from 'node:assert/strict';
import test from 'node:test';
import { planningCheckedWorkIds, planningProjectWorkItems, planningWorkChoices } from '../../resources/js/planning-work-choices.js';

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
    assert.deepEqual(choices.map((choice) => choice.memberIds), [[1], [2], [3], [4]]);
});

test('shows only the selected project when adjusting who does what', () => {
    const workItems = {
        11: [
            { id: 1, name: 'Linoleum', project_id: 11 },
            { id: 2, name: 'Plinten', project_id: 11 },
        ],
        22: [
            { id: 9, name: 'Tapijt', project_id: 22 },
        ],
    };

    const choices = planningWorkChoices(planningProjectWorkItems(workItems, 11));

    assert.deepEqual(choices.map((choice) => choice.label), ['Linoleum', 'Plinten']);
    assert.deepEqual(planningProjectWorkItems(workItems, ''), []);
    assert.deepEqual(planningProjectWorkItems(workItems, null), []);
});

test('keeps an already planned work item when its board group stays checked', () => {
    const choices = [
        { id: 30, checked: true, memberIds: [20, 30] },
        { id: 40, checked: true, memberIds: [40] },
        { id: 50, checked: false, memberIds: [50] },
    ];

    assert.deepEqual(planningCheckedWorkIds(choices, [20, 40]), [20, 40]);
    assert.deepEqual(planningCheckedWorkIds([{ id: 30, checked: true, memberIds: [20, 30] }], [30, 20]), [30, 20]);
    assert.deepEqual(planningCheckedWorkIds([{ id: 30, checked: true, memberIds: [20, 30] }], []), [30]);
});
