import assert from 'node:assert/strict';
import test from 'node:test';
import {
    localAreaPatchBody,
    isDoubleActivation,
    legendMaterialChoices,
    materialChoicePatch,
    materialFillBox,
    materialLabel,
    needsLocalAreaInput,
    overlayContrast,
    qtyInput,
    reviewKindClass,
    reviewKindOf,
    roomDrawingState,
    roomMatchesFilter,
    roomMatchesMaterials,
    roomMatchesSearch,
    roomOverlayContent,
} from '../../resources/js/calculation-board-selection.js';

const room = {
    needs_review: true,
    has_floor: true,
    has_plinth: true,
    material_key: 'v04',
    search: 'a-00-13 miva t v04 gietvloer',
};

test('review filter keeps only rooms that need checking', () => {
    assert.equal(roomMatchesFilter(room, 'all'), true);
    assert.equal(roomMatchesFilter(room, 'review'), true);
    assert.equal(roomMatchesFilter({ ...room, needs_review: false }, 'review'), false);
    assert.equal(roomMatchesFilter({ ...room, has_plinth: false }, 'plinths'), false);
});

test('manual filter keeps only rooms that were corrected by hand', () => {
    const checked = { ...room, needs_review: false, review_kind: 'certain' };
    const corrected = { ...room, needs_review: false, review_kind: 'manual' };

    assert.equal(roomMatchesFilter(checked, 'manual'), false);
    assert.equal(roomMatchesFilter(corrected, 'manual'), true);
    assert.equal(roomMatchesFilter(corrected, 'review'), false);
    assert.equal(reviewKindOf(corrected), 'manual');
    assert.equal(reviewKindClass(corrected), 'is-manual');
    assert.equal(reviewKindClass({ needs_review: true }), 'is-review');
});

test('search matches number name product or code', () => {
    assert.equal(roomMatchesSearch(room, 'v04'), true);
    assert.equal(roomMatchesSearch(room, 'MIVA'), true);
    assert.equal(roomMatchesSearch(room, 'pl02'), false);
});

test('material keys isolate matching rooms immediately', () => {
    assert.equal(roomMatchesMaterials(room, []), true);
    assert.equal(roomMatchesMaterials(room, ['v04']), true);
    assert.equal(roomMatchesMaterials(room, ['v09']), false);
    assert.equal(roomMatchesMaterials({ ...room, material_keys: ['v01.d', 'v09'] }, ['v09']), true);
    assert.equal(materialLabel([], [{ key: 'v04', label: 'v04 – Gietvloer' }]), 'Alle materialen');
    assert.equal(materialLabel(['v04'], [{ key: 'v04', label: 'v04 – Gietvloer' }]), 'v04 – Gietvloer');
});

test('qty input uses a comma decimal', () => {
    assert.equal(qtyInput(12.7), '12,7');
    assert.equal(qtyInput(null), '');
});

test('drawing overlay shows number and name but not the full product', () => {
    const overlay = roomOverlayContent({
        number: 'A-00-06',
        name: 'VERKEER, SPEEL, BEWEGING',
        floor_code: 'v01.g',
        floor_product: 'Marmoleum – Forbo 3752',
        m2_label: '97,20 m²',
    });

    assert.equal(overlay.number, 'A-00-06');
    assert.equal(overlay.name, 'VERKEER, SPEEL, BEWEGING');
    assert.equal(overlay.code, 'v01.g');
    assert.equal(overlay.title.includes('Marmoleum'), false);
    assert.equal(overlay.title.includes('v01.g'), true);
});

test('a local floor code also matches the room material filter', () => {
    const recreation = {
        ...room,
        material_key: 'v01.d',
        material_keys: ['v01.d', 'v09'],
        search: 'a-00-01 recreatie v01.d v09 schoonloopmat',
    };

    assert.equal(roomMatchesMaterials(recreation, ['v09']), true);
    assert.equal(roomMatchesMaterials(recreation, ['v04']), false);
});

test('alle materialen highlights every room and a single code isolates its fill', () => {
    const traffic = {
        key: 'a-00-06',
        number: 'A-00-06',
        material_key: 'v01.g',
        needs_review: false,
        search: 'a-00-06 verkeer v01.g',
    };
    const all = roomDrawingState(traffic, { materialKeys: [], filter: 'all', search: '' });
    const isolated = roomDrawingState(traffic, { materialKeys: ['v04'], filter: 'all', search: '' });
    const selected = roomDrawingState(traffic, {
        selectedKey: 'a-00-06',
        materialKeys: ['v04'],
        filter: 'all',
        search: '',
    });

    assert.equal(all.highlighted, true);
    assert.equal(all.filteredOut, false);
    assert.equal(isolated.highlighted, false);
    assert.equal(isolated.filteredOut, true);
    assert.equal(selected.selected, true);
    assert.equal(selected.filteredOut, false);
    assert.ok(all.fillAlpha > isolated.fillAlpha);
    assert.equal(overlayContrast('#2563eb').fg, '#fff');
    assert.equal(materialFillBox({ x: 0.4, y: 0.3, w: 0.04, h: 0.012 }).w > 0.04, true);
});

test('a local finish without m2 can be filled by hand', () => {
    const empty = { role: 'local', needs_local_area: true, quantity: null };
    const filled = { role: 'local', needs_local_area: false, quantity: 4.2 };
    const main = { role: 'main', needs_local_area: false, quantity: 31.9 };

    assert.equal(needsLocalAreaInput(empty, true), true);
    assert.equal(needsLocalAreaInput(empty, false), false);
    assert.equal(needsLocalAreaInput(filled, true), false);
    assert.equal(needsLocalAreaInput(main, true), false);
    assert.deepEqual(localAreaPatchBody(11595, '4,20'), {
        floors: [{ id: 11595, quantity: '4,20' }],
    });
    assert.equal(Object.hasOwn(localAreaPatchBody(11595, '4,20'), 'floor_quantity'), false);
});

test('material popup choices come from the drawing legend', () => {
    const legend = [
        { code: 'v01', product: 'Marmoleum', color: '#848482' },
        { code: 'v04', product: 'Gietvloer', color: '#8db600' },
    ];

    assert.deepEqual(legendMaterialChoices(legend, { code: 'v01' }).map((entry) => entry.code), ['v01', 'v04']);
    assert.deepEqual(materialChoicePatch({ floors: [{ id: 10, role: 'main' }] }, { code: 'v04', product: 'Gietvloer' }), {
        floor_code: 'v04',
        floor_product: 'Gietvloer',
    });
    assert.deepEqual(materialChoicePatch({
        floors: [
            { id: 10, role: 'main', code: 'v01' },
            { id: 11, role: 'local', code: 'v09' },
        ],
    }, { code: 'v04', product: 'Gietvloer', finishId: 11 }), {
        floors: [{ id: 11, code: 'v04', product: 'Gietvloer' }],
    });
    assert.equal(Object.hasOwn(materialChoicePatch({ floors: [{ id: 10, role: 'main' }] }, { code: 'v04', product: 'Gietvloer' }), 'floor_quantity'), false);
});

test('double activation is a second click on the same room', () => {
    assert.equal(isDoubleActivation({ key: 'a-00-08', at: 1000 }, 'a-00-08', 1300), true);
    assert.equal(isDoubleActivation({ key: 'a-00-08', at: 1000 }, 'a-00-08', 1600), false);
    assert.equal(isDoubleActivation({ key: 'a-00-08', at: 1000 }, 'a-00-13', 1100), false);
    assert.equal(isDoubleActivation(null, 'a-00-08', 1100), false);
});

test('legend picker still offers a typed code when the drawing legend is empty', () => {
    assert.deepEqual(
        legendMaterialChoices([], { code: 'v01e', product: 'Marmoleum' }).map((entry) => entry.code),
        ['v01e'],
    );
    assert.deepEqual(
        legendMaterialChoices([{ key: 'v04', product: 'Gietvloer' }]).map((entry) => entry.code),
        ['v04'],
    );
});
