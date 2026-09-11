import assert from 'node:assert/strict';
import test from 'node:test';
import {
    areaMatchesWorkKeys,
    buildOutsourceSelection,
    formatBoardQty,
    groupedWorkFilters,
    measureSelectedWorks,
    shortWorkLabel,
    workFilterSummaryLabel,
} from '../../resources/js/drawing-work-selection.js';

const filters = [
    { key: 'ondergrond', label: 'Primen & Egaliseren' },
    { key: 'vloer|1', label: 'Marmoleum Real, 3120 rosato, Linoleum' },
    { key: 'vloer|2', label: 'Marmoleum Walton, 3352 berlin red, Linoleum' },
    { key: 'vloer|3', label: 'Marmoleum Walton, 3355 rosemary green, Linoleum' },
    { key: 'vloer|4', label: 'Marmoleum Walton, 3370 terracotta, Linoleum' },
    { key: 'plinten|9', label: 'Plinten wit' },
];

const rooms = [
    {
        id: 1,
        number: '0.07',
        name: 'groepsruimte',
        unique_name: 'groepsruimte',
        floor: 'begane grond',
        floor_id: 1,
        m2: 50.97,
        works: [
            { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 50.97, unit: 'm2' },
            { key: 'vloer|1', label: 'Marmoleum Real, 3120 rosato, Linoleum', quantity: 50.97, unit: 'm2' },
        ],
    },
    {
        id: 2,
        number: '0.09',
        name: 'groepsruimte',
        unique_name: 'groepsruimte 2',
        floor: 'begane grond',
        floor_id: 1,
        m2: 59,
        works: [
            { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 59, unit: 'm2' },
            { key: 'vloer|2', label: 'Marmoleum Walton, 3352 berlin red, Linoleum', quantity: 37.09, unit: 'm2' },
        ],
    },
    {
        id: 3,
        number: '1.10',
        name: 'lokaal',
        floor: '1e verdieping',
        m2: 40,
        works: [
            { key: 'vloer|1', label: 'Marmoleum Real, 3120 rosato, Linoleum', quantity: 40, unit: 'm2' },
        ],
    },
    {
        id: 4,
        number: '0.21',
        name: 'hal',
        floor: 'begane grond',
        floor_id: 1,
        m2: 136.72,
        works: [
            { key: 'vloer|3', label: 'Marmoleum Walton, 3355 rosemary green, Linoleum', quantity: 136.72, unit: 'm2' },
            { key: 'vloer|3', label: 'Marmoleum Walton, 3355 rosemary green, Linoleum', quantity: 136.72, unit: 'm2' },
        ],
    },
    {
        id: 5,
        number: '0.24',
        name: 'entree',
        floor: 'begane grond',
        floor_id: 1,
        m2: 11.44,
        works: [
            { key: 'vloer|1', label: 'Marmoleum Real, 3120 rosato, Linoleum', quantity: 868.22, unit: 'm2' },
            { key: 'vloer|4', label: 'Marmoleum Walton, 3370 terracotta, Linoleum', quantity: 11.44, unit: 'm2' },
        ],
    },
];

test('keeps all rooms when no materials are checked', () => {
    assert.equal(areaMatchesWorkKeys(rooms[0], []), true);
    assert.equal(workFilterSummaryLabel([]), 'Materialen kiezen');
});

test('matches a room when any checked material is present', () => {
    const keys = ['vloer|1', 'vloer|2'];

    assert.equal(areaMatchesWorkKeys(rooms[0], keys), true);
    assert.equal(areaMatchesWorkKeys(rooms[1], keys), true);
    assert.equal(areaMatchesWorkKeys(rooms[3], keys), false);
    assert.equal(areaMatchesWorkKeys(rooms[0], keys, ['ondergrond']), false);
});

test('sums netto m2 per material on the current floor and skips other floors', () => {
    const keys = ['vloer|1', 'vloer|2', 'vloer|3', 'vloer|4'];
    const floorRooms = rooms.filter((area) => area.floor === 'begane grond');
    const measure = measureSelectedWorks(floorRooms, keys, filters);

    assert.equal(measure.lines.find((line) => line.key === 'vloer|1').quantity, 919.19);
    assert.equal(measure.lines.find((line) => line.key === 'vloer|3').quantity, 136.72);
    assert.equal(measure.lines.find((line) => line.key === 'vloer|2').quantity, 37.09);
    assert.equal(measure.lines.find((line) => line.key === 'vloer|4').quantity, 11.44);
    assert.equal(measure.total, 1104.44);
    assert.equal(measure.label, '1.104,44 m²');
    assert.equal(measure.rooms.length, 4);
    assert.equal(measure.rooms.some((room) => room.id === 3), false);
});

test('does not double-count the same material rule twice in one room', () => {
    const measure = measureSelectedWorks([rooms[3]], ['vloer|3'], filters);

    assert.equal(measure.total, 136.72);
    assert.equal(measure.rooms.length, 1);
});

test('counts a room once when it has several checked finishes', () => {
    const measure = measureSelectedWorks([rooms[4]], ['vloer|1', 'vloer|4'], filters);

    assert.equal(measure.rooms.length, 1);
    assert.equal(measure.lines.find((line) => line.key === 'vloer|1').quantity, 868.22);
    assert.equal(measure.lines.find((line) => line.key === 'vloer|4').quantity, 11.44);
    assert.equal(measure.total, 879.66);
});

test('does not add hours or room m2 on top of a measured work quantity', () => {
    const area = {
        id: 9,
        number: '0.30',
        name: 'lokaal',
        floor: 'begane grond',
        floor_id: 1,
        m2: 999,
        hours: 80,
        works: [{ key: 'vloer|1', quantity: 12.5, unit: 'm2' }],
    };
    const measure = measureSelectedWorks([area], ['vloer|1'], filters);

    assert.equal(measure.total, 12.5);
});

test('formats dutch thousands for the selection total', () => {
    assert.equal(formatBoardQty(1104.44), '1.104,44');
    assert.equal(formatBoardQty(37.09), '37,09');
    assert.equal(workFilterSummaryLabel(['vloer|1', 'vloer|2']), 'Materialen kiezen (2)');
});

test('shortens product names and groups families in the dropdown', () => {
    assert.equal(shortWorkLabel('Primen & Egaliseren'), 'Primer & Egaliseren');
    assert.equal(shortWorkLabel('Marmoleum Real, 3120 rosato, Linoleum'), 'Real 3120 – rosato');
    assert.equal(shortWorkLabel('Marmoleum Sport 83020 move, Linoleum'), 'Sport 83020 – move');
    assert.equal(shortWorkLabel('Marmoleum Walton, 3352 berlin red, Linoleum'), 'Walton 3352 – berlin red');
    assert.equal(shortWorkLabel('Coral Welcome, 3202 desperado, Entreemat'), 'Coral Welcome – desperado');
    assert.equal(shortWorkLabel('PU gietvloer kleur n.t.b., Coating'), 'PU gietvloer');
    assert.equal(shortWorkLabel('Plinten wit'), 'Plinten');

    const groups = groupedWorkFilters([
        { key: 'ondergrond', label: 'Primen & Egaliseren', color_key: 'ondergrond' },
        { key: 'vloer|1', label: 'Marmoleum Real, 3120 rosato, Linoleum', color_key: 'linoleum' },
        { key: 'vloer|2', label: 'Marmoleum Walton, 3352 berlin red, Linoleum', color_key: 'linoleum' },
        { key: 'vloer|5', label: 'PU gietvloer', color_key: 'gietvloer' },
        { key: 'vloer|6', label: 'Coral Welcome, 3202 desperado, Entreemat', color_key: 'entreemat' },
        { key: 'plinten|9', label: 'Plinten wit', color_key: 'plinten' },
    ]);

    assert.deepEqual(groups.map((group) => group.name), ['', 'Marmoleum', 'Overig']);
    assert.equal(groups[0].items[0].key, 'ondergrond');
    assert.equal(groups[1].items.length, 2);
    assert.equal(groups[2].items.length, 3);
});

test('prepares an outsource payload without creating a job yet', () => {
    const keys = ['vloer|1', 'vloer|2'];
    const measure = measureSelectedWorks(rooms.filter((area) => area.floor === 'begane grond'), keys, filters);
    const payload = buildOutsourceSelection({
        project: { id: 12, name: 'Laakse Tuinen', number: '260200090' },
        floor: 'begane grond',
        keys,
        measure,
    });

    assert.equal(payload.project_id, 12);
    assert.equal(payload.floor, 'begane grond');
    assert.equal(payload.floor_id, 1);
    assert.deepEqual(payload.material_keys, keys);
    assert.equal(payload.materials.length, 2);
    assert.equal(payload.total_m2, measure.total);
    assert.equal(payload.worker_id, null);
    assert.equal(payload.worker_name, null);
    assert.ok(payload.rooms.every((room) => room.floor === 'begane grond'));
    assert.ok(payload.rooms.length >= 2);
});
