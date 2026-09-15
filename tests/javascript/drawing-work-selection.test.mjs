import assert from 'node:assert/strict';
import test from 'node:test';
import {
    areaMatchesWorkKeys,
    buildOutsourceSelection,
    formatBoardQty,
    groupedWorkFilters,
    measureSelectedWorks,
    measureSelectedRooms,
    roomSelectionSummaryLabel,
    roomMeasureChipLabel,
    selectedRoomProgressWorks,
    groupedRoomProgressWorks,
    checkedKeysFromWorkFilter,
    activeSelectionFromFilter,
    activeWorkBarLabel,
    shortWorkLabel,
    workFamily,
    workFilterSummaryLabel,
    buildTicketChunk,
    groupRoomsByFloor,
    isEntireFloorPick,
    ticketStorePayload,
    ticketHasGeneralWork,
    ticketRoomsToPick,
    workKeysOnRooms,
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

test('keeps coating and gietvloer in separate material families', () => {
    assert.equal(workFamily({
        key: 'vloer|5',
        label: 'PU gietvloer, Ral 7039 met vlok, Coating',
        color_key: 'gietvloer',
    }), 'PU gietvloer');
    assert.equal(workFamily({
        key: 'vloer|7',
        label: 'vloercoating op CD vloer, Coating',
        color_key: 'coating',
    }), 'Coating');
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

test('sums selected rooms from stored meetstaat quantities and never from polygon size', () => {
    const selected = [
        {
            id: 1,
            number: '0.07',
            name: 'groepsruimte',
            unique_name: 'groepsruimte',
            floor: 'begane grond',
            floor_id: 1,
            m2: 50.97,
            marker: { polygon: [{ x: 0, y: 0 }, { x: 1, y: 0 }, { x: 1, y: 1 }, { x: 0, y: 1 }] },
            works: [
                { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 50.97, unit: 'm2' },
                { key: 'vloer|1', label: 'Marmoleum Real, 3120 rosato, Linoleum', quantity: 50.97, unit: 'm2' },
                { key: 'plinten|9', label: 'Plinten wit', quantity: 42, unit: 'm1' },
            ],
        },
        {
            id: 2,
            number: '0.09',
            name: 'groepsruimte',
            unique_name: 'groepsruimte 2',
            floor: 'begane grond',
            floor_id: 1,
            m2: 159.38,
            works: [
                { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 159.38, unit: 'm2' },
                { key: 'vloer|1', label: 'Marmoleum Real, 3120 rosato, Linoleum', quantity: 159.43, unit: 'm2' },
                { key: 'plinten|9', label: 'Plinten wit', quantity: 80, unit: 'm1' },
            ],
        },
        {
            id: 3,
            number: '0.21',
            name: 'hal',
            floor: 'begane grond',
            m2: 98.22,
            works: [
                { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 98.22, unit: 'm2' },
                { key: 'vloer|3', label: 'Marmoleum Walton, 3355 rosemary green, Linoleum', quantity: 98.22, unit: 'm2' },
                { key: 'plinten|9', label: 'Plinten wit', quantity: 40, unit: 'm1' },
            ],
        },
        {
            id: 4,
            number: '0.12',
            name: 'lokaal',
            floor: 'begane grond',
            m2: 53.4,
            works: [
                { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 53.4, unit: 'm2' },
                { key: 'vloer|2', label: 'Marmoleum Walton, 3352 berlin red, Linoleum', quantity: 53.35, unit: 'm2' },
                { key: 'plinten|9', label: 'Plinten wit', quantity: 22.5, unit: 'm1' },
            ],
        },
    ];
    const duplicated = [...selected, selected[0], { ...selected[1], id: 2 }];
    const measure = measureSelectedRooms(duplicated);

    assert.equal(measure.rooms.length, 4);
    assert.equal(measure.total_m2, 361.97);
    assert.equal(measure.label, '361,97 m²');
    assert.equal(roomSelectionSummaryLabel(measure.rooms.length, measure.total_m2), '4 ruimtes · 361,97 m²');
    assert.equal(measure.lines.find((line) => line.key === 'ondergrond').quantity, 361.97);
    assert.equal(measure.lines.find((line) => line.key === 'vloer|1').quantity, 210.4);
    assert.equal(measure.lines.find((line) => line.key === 'vloer|3').quantity, 98.22);
    assert.equal(measure.lines.find((line) => line.key === 'vloer|2').quantity, 53.35);
    assert.equal(measure.lines.find((line) => line.key === 'plinten|9').quantity, 184.5);
    assert.equal(measure.lines.find((line) => line.key === 'plinten|9').unit, 'm1');
    assert.equal(measure.lines.find((line) => line.key === 'plinten|9').qty_label, '184,50 m¹');
    assert.deepEqual(measure.lines.map((line) => line.key), ['ondergrond', 'vloer|1', 'vloer|2', 'vloer|3', 'plinten|9']);
});

test('keeps pvc, tapijt and marmoleum types separate in a room selection', () => {
    const measure = measureSelectedRooms([
        {
            id: 8,
            number: '1.10',
            name: 'lokaal',
            m2: 40,
            works: [
                { key: 'vloer|pvc', label: 'PVC, 4002 oak, PVC', quantity: 22, unit: 'm2' },
                { key: 'vloer|tap', label: 'Tapijt, loop, Tapijt', quantity: 18, unit: 'm2' },
            ],
        },
        {
            id: 9,
            number: '1.11',
            name: 'gang',
            m2: 12,
            works: [
                { key: 'vloer|pvc', label: 'PVC, 4002 oak, PVC', quantity: 12, unit: 'm2' },
            ],
        },
    ]);

    assert.equal(measure.lines.find((line) => line.key === 'vloer|pvc').quantity, 34);
    assert.equal(measure.lines.find((line) => line.key === 'vloer|tap').quantity, 18);
    assert.equal(measure.total_m2, 52);
});

test('does not fill missing work quantity from room m2 when selecting rooms', () => {
    const measure = measureSelectedRooms([
        {
            id: 10,
            number: '0.30',
            name: 'berging',
            m2: 999,
            marker: { polygon: [{ x: 0, y: 0 }, { x: 0.9, y: 0 }, { x: 0.9, y: 0.9 }] },
            works: [{ key: 'vloer|1', label: 'Marmoleum Real, 3120 rosato, Linoleum', unit: 'm2' }],
        },
    ]);

    assert.equal(measure.total_m2, 999);
    assert.equal(measure.lines.length, 0);
});

test('builds a compact checkmark label from the stored room name and m2', () => {
    assert.equal(roomMeasureChipLabel({
        unique_name: 'oefenruimte',
        m2_label: '62,00 m²',
    }), '✓ oefenruimte · 62,00 m²');
    assert.equal(roomMeasureChipLabel({
        name: 'cabine',
        m2_label: '6,31 m²',
    }), '✓ cabine · 6,31 m²');
});

test('prepares a room outsource payload without creating a job', () => {
    const selected = [rooms[0], rooms[1]];
    const measure = measureSelectedRooms(selected);
    const payload = buildOutsourceSelection({
        project: { id: 12, name: 'Laakse Tuinen', number: '260200090' },
        floor: 'begane grond',
        keys: measure.lines.map((line) => line.key),
        measure,
        source: 'rooms',
    });

    assert.equal(payload.source, 'rooms');
    assert.equal(payload.project_id, 12);
    assert.equal(payload.floor, 'begane grond');
    assert.equal(payload.total_m2, 109.97);
    assert.equal(payload.rooms.length, 2);
    assert.equal(payload.worker_id, null);
    assert.ok(payload.materials.some((line) => line.key === 'ondergrond'));
    assert.ok(payload.materials.some((line) => line.key === 'vloer|1'));
    assert.ok(payload.materials.some((line) => line.key === 'vloer|2'));
});

test('lists remaining work of selected rooms and skips already done items', () => {
    const works = selectedRoomProgressWorks([
        {
            id: 1,
            works: [
                { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 83.65, remaining: 83.65, completed: 0, unit: 'm2' },
                { key: 'vloer|1', label: 'IVC Ultimo Chapman Oak, PVC', quantity: 53.42, remaining: 0, completed: 53.42, done: true, unit: 'm2' },
            ],
        },
        {
            id: 2,
            works: [
                { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 16.31, remaining: 16.31, completed: 0, unit: 'm2' },
                { key: 'vloer|2', label: 'IVC Ultimo Trasimeno, PVC', quantity: 16.31, remaining: 16.31, completed: 0, unit: 'm2' },
            ],
        },
        {
            id: 3,
            works: [
                { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 25, remaining: 7, completed: 18, unit: 'm2' },
            ],
        },
        {
            id: 4,
            works: [
                { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 26.72, remaining: 26.72, completed: 0, unit: 'm2' },
            ],
        },
    ]);

    const egaliseren = works.find((line) => line.key === 'ondergrond');
    const chapman = works.find((line) => line.key === 'vloer|1');
    const trasimeno = works.find((line) => line.key === 'vloer|2');

    assert.equal(egaliseren.remaining, 133.68);
    assert.equal(egaliseren.completed, 18);
    assert.equal(egaliseren.status, 'partial');
    assert.equal(egaliseren.bookable, true);
    assert.match(egaliseren.detail, /18,00 \/ 151,68/);
    assert.equal(chapman.status, 'done');
    assert.equal(chapman.bookable, false);
    assert.equal(trasimeno.remaining, 16.31);
    assert.equal(trasimeno.bookable, true);
});

const fourRooms = [
    {
        id: 1,
        m2: 83.65,
        works: [
            { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 83.65, remaining: 83.65, completed: 0, unit: 'm2' },
            { key: 'vloer|pvc1', label: 'IVC Ultimo Chapman Oak, 24245, PVC - LVT', quantity: 53.42, remaining: 53.42, completed: 0, unit: 'm2' },
            { key: 'plinten|9', label: 'Plinten wit', quantity: 40, remaining: 40, completed: 0, unit: 'm1' },
        ],
    },
    {
        id: 2,
        m2: 16.31,
        works: [
            { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 16.31, remaining: 16.31, completed: 0, unit: 'm2' },
            { key: 'vloer|pvc2', label: 'IVC Ultimo Trasimeno, 46906, PVC - LVT', quantity: 16.31, remaining: 16.31, completed: 0, unit: 'm2' },
            { key: 'plinten|9', label: 'Plinten wit', quantity: 18, remaining: 18, completed: 0, unit: 'm1' },
        ],
    },
    {
        id: 3,
        m2: 16.33,
        works: [
            { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 16.33, remaining: 16.33, completed: 0, unit: 'm2' },
            { key: 'vloer|mar', label: 'Marmoleum Real, 3120 rosato, Linoleum', quantity: 16.33, remaining: 16.33, completed: 0, unit: 'm2' },
        ],
    },
    {
        id: 4,
        m2: 26.72,
        works: [
            { key: 'ondergrond', label: 'Primen & Egaliseren', quantity: 26.72, remaining: 26.72, completed: 0, unit: 'm2' },
            { key: 'vloer|pvc1', label: 'IVC Ultimo Chapman Oak, 24245, PVC - LVT', quantity: 0, remaining: 0, completed: 0, unit: 'm2' },
        ],
    },
];

test('one material filter plus four rooms checks only that work with room quantities', () => {
    const works = selectedRoomProgressWorks(fourRooms);
    const checked = checkedKeysFromWorkFilter(works, ['ondergrond']);
    const active = activeSelectionFromFilter(works, ['ondergrond']);
    const grouped = groupedRoomProgressWorks(works);

    assert.deepEqual(checked, ['ondergrond']);
    assert.equal(active.length, 1);
    assert.equal(active[0].remaining, 143.01);
    assert.equal(active[0].active_detail, '143,01 m² in geselecteerde ruimtes');
    assert.equal(activeWorkBarLabel(active), 'Primer & Egaliseren · 143,01 m²');
    assert.ok(grouped.werkzaamheden.some((line) => line.key === 'ondergrond'));
    assert.ok(grouped.werkzaamheden.some((line) => line.key === 'plinten|9'));
    assert.ok(grouped.materialen.some((line) => line.key === 'vloer|pvc1'));
    assert.ok(!checked.includes('vloer|pvc1'));
    assert.ok(!checked.includes('plinten|9'));
});

test('two material filters plus four rooms check both and keep other works unchecked', () => {
    const works = selectedRoomProgressWorks(fourRooms);
    const keys = ['ondergrond', 'vloer|pvc1'];
    const checked = checkedKeysFromWorkFilter(works, keys);
    const active = activeSelectionFromFilter(works, keys);

    assert.deepEqual(checked, ['ondergrond', 'vloer|pvc1']);
    assert.equal(active.length, 2);
    assert.equal(active[0].remaining, 143.01);
    assert.equal(active[1].remaining, 53.42);
    assert.equal(activeWorkBarLabel(active), '2 onderdelen geselecteerd');
    assert.ok(!checked.includes('vloer|pvc2'));
    assert.ok(!checked.includes('vloer|mar'));
    assert.ok(!checked.includes('plinten|9'));
});

test('changing the top filter replaces which progress rows are checked', () => {
    const works = selectedRoomProgressWorks(fourRooms);

    assert.deepEqual(checkedKeysFromWorkFilter(works, ['ondergrond']), ['ondergrond']);
    assert.deepEqual(checkedKeysFromWorkFilter(works, ['vloer|pvc1', 'vloer|pvc2']), ['vloer|pvc1', 'vloer|pvc2']);
    assert.deepEqual(checkedKeysFromWorkFilter(works, []), []);

    const active = activeSelectionFromFilter(works, ['vloer|pvc1']);
    assert.equal(active[0].remaining, 53.42);
    assert.equal(activeWorkBarLabel(active), 'IVC Ultimo Chapman Oak, 24245 · 53,42 m²');
});

test('builds a werkbon chunk from the current floor, materials and rooms', () => {
    const picked = rooms.filter((room) => ['0.07', '0.09'].includes(room.number));
    const chunk = buildTicketChunk({
        floor: 'begane grond',
        floorId: 1,
        keys: ['vloer|1', 'vloer|2'],
        rooms: picked,
        entire: false,
        filters,
    });

    assert.equal(chunk.floor, 'begane grond');
    assert.equal(chunk.floor_id, 1);
    assert.equal(chunk.entire, false);
    assert.deepEqual(chunk.area_ids, [1, 2]);
    assert.equal(chunk.rooms_label, '0.07, 0.09');
    assert.equal(chunk.lines.length, 2);
});

test('marks a chunk as the whole floor when every matching room is picked', () => {
    const floorRooms = rooms.filter((room) => room.floor_id === 1 && areaMatchesWorkKeys(room, ['ondergrond']));
    assert.equal(isEntireFloorPick(rooms, floorRooms, 1, ['ondergrond']), true);
    assert.equal(isEntireFloorPick(rooms, floorRooms.slice(0, 1), 1, ['ondergrond']), false);
});

test('groups picked rooms by floor and posts selections without extra selectors', () => {
    const grouped = groupRoomsByFloor([
        { id: 1, floor_id: 1, number: '0.07' },
        { id: 3, floor_id: null, number: '1.10' },
        { id: 2, floor_id: 1, number: '0.09' },
    ]);
    assert.equal(grouped.get(1).length, 2);
    assert.equal(grouped.get(0).length, 1);

    const payload = ticketStorePayload([
        {
            floor_id: 1,
            entire: true,
            area_ids: [1, 2],
            work_keys: ['ondergrond'],
        },
        {
            floor_id: 2,
            entire: false,
            area_ids: [8],
            work_keys: ['vloer|3'],
        },
    ], { notes: 'let op naden' });

    assert.equal(payload.selections[0].entire, 1);
    assert.equal(payload.selections[1].entire, 0);
    assert.deepEqual(payload.selections[1].work_keys, ['vloer|3']);
    assert.equal(payload.notes, 'let op naden');
});

test('posts extra work without room selections', () => {
    const payload = ticketStorePayload([], {
        extra_work_item_ids: ['12', 0, '9'],
        notes: 'vloer herstel',
        document_ids: [4],
    });

    assert.equal('selections' in payload, false);
    assert.deepEqual(payload.extra_work_item_ids, [12, 9]);
    assert.equal(payload.notes, 'vloer herstel');
    assert.deepEqual(payload.document_ids, [4]);
});

test('posts general work without extra item ids', () => {
    const payload = ticketStorePayload([], { general_work: true, notes: 'nacalculatie' });

    assert.equal(payload.general_work, 1);
    assert.equal('extra_work_item_ids' in payload, false);
    assert.equal('selections' in payload, false);
    assert.equal(ticketHasGeneralWork({ extraIds: [12] }), true);
    assert.equal(ticketHasGeneralWork({ general: true }), true);
    assert.equal(ticketHasGeneralWork({ extraIds: [], general: false }), false);
});

test('hele werk for a bon picks matching rooms on every floor', () => {
    const picked = ticketRoomsToPick(rooms, { keys: ['vloer|1'] });

    assert.deepEqual(picked.map((room) => room.id).sort((left, right) => left - right), [1, 3, 5]);
    assert.ok(picked.some((room) => room.floor === 'begane grond'));
    assert.ok(picked.some((room) => room.floor === '1e verdieping'));
});

test('hele werk without a material filter takes every work key on the picked rooms', () => {
    const picked = ticketRoomsToPick(rooms, {});
    const keys = workKeysOnRooms(picked);

    assert.ok(picked.length >= 4);
    assert.ok(keys.includes('ondergrond'));
    assert.ok(keys.includes('vloer|1'));
});
