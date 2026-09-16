import assert from 'node:assert/strict';
import test from 'node:test';
import {
    materialFillBox,
    materialLabel,
    overlayContrast,
    qtyInput,
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
