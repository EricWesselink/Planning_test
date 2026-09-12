import assert from 'node:assert/strict';
import test from 'node:test';
import {
    assessTextLayer,
    dedupeTextItems,
    findExactRoomHits,
    labelBox,
    labelVisualBox,
    drawingMarks,
    nameMatches,
    normalizeRoomNumber,
    pickBestKnownRoomNumber,
    pointInLabel,
    roomNumbersIn,
    uniqueAreasOnPage,
    viewerRenderScale,
    displayBox,
    selectionBarBox,
    layerShowsRooms,
    layerShowsSnags,
    roomFocusBox,
    hasReliableRoomPosition,
    storedJumpTarget,
    focusViewport,
    exactRoomHitForArea,
    roomContour,
    pointInPolygon,
    hitTestContours,
} from '../../resources/js/room-geometry.js';

test('finds exact room numbers and ignores square meters', () => {
    const items = [
        { page: 1, text: '50.97 m²', x: 0.08, y: 0.25, w: 0.02, h: 0.01, source: 'text' },
        { page: 1, text: '0.07 groepsruimte', x: 0.1148, y: 0.2952, w: 0.0353, h: 0.0061, source: 'text' },
        { page: 1, text: '0.07 groepsruimte', x: 0.1148, y: 0.2952, w: 0.0353, h: 0.0061, source: 'text' },
        { page: 1, text: '0.09 groepsruimte', x: 0.2492, y: 0.2985, w: 0.0353, h: 0.0061, source: 'text' },
        { page: 1, text: '0.12 schoolleiding', x: 0.3321, y: 0.3158, w: 0.0352, h: 0.0061, source: 'text' },
        { page: 1, text: '0.19a administratie', x: 0.4963, y: 0.2881, w: 0.0372, h: 0.0061, source: 'text' },
        { page: 1, text: '0.19b administratie', x: 0.5369, y: 0.3001, w: 0.0372, h: 0.0061, source: 'text' },
    ];
    const known = ['0.07', '0.09', '0.12', '0.19a', '0.24'];
    const hits = findExactRoomHits(dedupeTextItems(items), known);
    const byNumber = Object.fromEntries(hits.map((hit) => [hit.number, hit]));

    assert.deepEqual(hits.map((hit) => hit.number).sort(), ['0.07', '0.09', '0.12', '0.19a']);
    assert.equal(byNumber['0.07'].x, 0.1148);
    assert.equal(byNumber['0.07'].y, 0.2952);
    assert.equal(byNumber['0.19a'].source, 'text');
    assert.equal(roomNumbersIn('0.19a administratie').join(','), '0.19a');
    assert.equal(normalizeRoomNumber('0,07'), '0.07');
    assert.equal(assessTextLayer(items, known).usable, true);
    assert.equal(assessTextLayer([], known).present, false);
    assert.equal(assessTextLayer(items, known).usable && assessTextLayer(items, known).present, true);
});

test('does not treat 0.19 as 0.19a', () => {
    const items = [
        { page: 1, text: '0.19a administratie', x: 0.5, y: 0.3, w: 0.04, h: 0.01, source: 'text' },
    ];
    const hits = findExactRoomHits(items, ['0.19', '0.19a']);
    assert.deepEqual(hits.map((hit) => hit.number), ['0.19a']);
});

test('keeps every 0.10 leerplein label and ignores square meters', () => {
    const items = [
        { page: 1, text: '54.66 m²', x: 0.1413, y: 0.3694, w: 0.02, h: 0.01, source: 'text' },
        { page: 1, text: '0.10 leerplein OB', x: 0.1867, y: 0.4174, w: 0.033, h: 0.006, source: 'text' },
        { page: 1, text: '0.10 leerplein OB', x: 0.1882, y: 0.4273, w: 0.033, h: 0.006, source: 'text' },
        { page: 1, text: '0.10 leerplein OB', x: 0.2941, y: 0.3940, w: 0.033, h: 0.006, source: 'text' },
    ];
    const hits = findExactRoomHits(items, ['0.10', '0.07']);
    assert.equal(hits.length, 3);
    assert.ok(hits.every((hit) => hit.number === '0.10'));
    assert.ok(hits.every((hit) => hit.w >= 0.08));
    assert.ok(nameMatches('leerplein OB', '0.10 leerplein OB'));
    assert.equal(nameMatches('entree', '0.10 leerplein OB'), false);
    const box = labelBox({ x: 0.1867, y: 0.4174, width: 0.033, height: 0.006, label_text: '0.10 leerplein OB' });
    assert.ok(box.w >= 0.08);
    assert.ok(box.h >= 0.018);
    assert.equal(pointInLabel({ x: 0.22, y: 0.425 }, box), true);
});

test('progress marks sit under the pdf room name, even with a saved marker', () => {
    const area = {
        id: 21,
        number: '0.21',
        name: 'speellokaal',
        marker: {
            source: 'manual',
            page: 1,
            x: 0.42,
            y: 0.31,
            width: 0.03,
            height: 0.012,
            label_text: '0.21 speellokaal',
        },
    };
    const pdfHit = {
        x: 0.11,
        y: 0.29,
        width: 0.08,
        height: 0.02,
        tw: 0.035,
        th: 0.006,
        label_text: '0.21 speellokaal',
    };
    const box = displayBox(area, pdfHit);
    assert.equal(box.x, 0.11);
    assert.equal(box.y, 0.29);
    const visual = labelVisualBox(box, '0.21 speellokaal');
    assert.ok(visual.w <= 0.04);
    assert.ok((visual.x + visual.w / 2) < box.x + 0.03);
});

test('status dots sit under the room name, not at the wide click box', () => {
    const box = labelBox({
        x: 0.1148,
        y: 0.2952,
        width: 0.10,
        height: 0.02,
        label_text: '0.21 speellokaal',
    });
    const visual = labelVisualBox(box, '0.21 speellokaal');
    assert.ok(visual.w < 0.05);
    assert.ok(visual.w < box.w / 2);
    assert.equal(visual.x, box.x);
    assert.ok((visual.x + visual.w) < box.x + box.w * 0.6);
});

test('selection bar wraps the room name, not the wide click box', () => {
    const box = labelBox({
        x: 0.1148,
        y: 0.2952,
        width: 0.10,
        height: 0.02,
        label_text: '0.17 hal',
    });
    const visual = labelVisualBox(box, '0.17 hal');
    const bar = selectionBarBox(box, '0.17 hal');

    assert.ok(bar.x <= visual.x);
    assert.ok(bar.y <= visual.y);
    assert.ok(bar.w < box.w);
    assert.ok(bar.w >= visual.w);
    assert.ok(bar.y + bar.h >= visual.y + visual.h - 0.0001);
});

test('drawing marks use product colors under the room name', () => {
    const base = {
        phases: { egaliseren: false, vloer: false, plinten: false },
        tone: 'open',
        dots: [],
    };
    assert.deepEqual(drawingMarks(base), []);
    const partial = drawingMarks({
        ...base,
        tone: 'partial',
        dots: [
            { key: 'ondergrond', label: 'Primen & Egaliseren' },
            { key: 'linoleum', label: 'Marmoleum Real, Linoleum' },
        ],
    });
    assert.deepEqual(partial.map((mark) => mark.text), ['E✓', 'V✓']);
    assert.deepEqual(partial.map((mark) => mark.color), ['ondergrond', 'linoleum']);
    const allDone = drawingMarks({
        ...base,
        tone: 'done',
        dots: [
            { key: 'ondergrond', label: 'Primen & Egaliseren' },
            { key: 'linoleum', label: 'Marmoleum Real, Linoleum' },
            { key: 'plinten', label: 'Plinten wit' },
        ],
    });
    assert.deepEqual(allDone.map((mark) => mark.text), ['E✓', 'V✓', 'P✓']);
    assert.deepEqual(allDone.map((mark) => mark.color), ['ondergrond', 'linoleum', 'plinten']);
    const pending = drawingMarks({
        ...base,
        tone: 'pending',
        dots: [
            { key: 'entreemat', label: 'Entreemat', provisional: true },
        ],
    });
    assert.equal(pending[0].provisional, true);
    assert.match(pending[0].title, /voorlopig, wacht op akkoord/);
    assert.deepEqual(
        drawingMarks({ ...base, phases: { egaliseren: true, vloer: true, plinten: false }, tone: 'partial' }).map((mark) => mark.color),
        ['ondergrond', 'vloer'],
    );
});

test('keeps every project_area on a page even with shared marker points or duplicate names', () => {
    const areas = [
        { id: 31, number: '0.10', name: 'kleedkamer', marker: { page: 1, x: 0.1867, y: 0.4174, label_text: '0.10 kleedkamer' } },
        { id: 131, number: '0.11', name: 'kleedkamer', marker: { page: 1, x: 0.1867, y: 0.4174, label_text: '0.11 kleedkamer' } },
        { id: 9, number: '0.12', name: 'instructieruimte', marker: { page: 1, x: 0.3321, y: 0.3158, label_text: '0.12 instructieruimte' } },
        { id: 10, number: '0.13', name: 'instructieruimte', marker: { page: 1, x: 0.40, y: 0.32, width: 0.08, height: 0.04 } },
    ];
    const visible = uniqueAreasOnPage(areas, 1);
    assert.deepEqual(visible.map((area) => area.id).sort((a, b) => a - b), [9, 10, 31, 131]);
});

test('stored jump target uses only the area marker, never a sibling with the same name', () => {
    const a = {
        id: 15,
        number: '0.15',
        name: 'instructieruimte',
        floor: 'begane grond',
        marker: {
            page: 1,
            x: 0.20,
            y: 0.30,
            width: 0.10,
            height: 0.08,
            polygon: [
                { x: 0.18, y: 0.28 },
                { x: 0.32, y: 0.28 },
                { x: 0.32, y: 0.40 },
                { x: 0.18, y: 0.40 },
            ],
        },
    };
    const b = {
        id: 16,
        number: '0.16',
        name: 'instructieruimte',
        floor: 'begane grond',
        marker: { page: 2, x: 0.55, y: 0.60, width: 0.12, height: 0.09 },
    };
    const jumpA = storedJumpTarget(a);
    const jumpB = storedJumpTarget(b);
    assert.equal(jumpA.page, 1);
    assert.equal(jumpA.box.x, 0.18);
    assert.equal(jumpA.geometry.startsWith('polygon:'), true);
    assert.equal(jumpB.page, 2);
    assert.equal(jumpB.box.x, 0.55);
    assert.equal(storedJumpTarget({ id: 99, name: 'kleedkamer', marker: null }), null);
    assert.equal(storedJumpTarget({ id: 99, name: 'berging' }), null);
});

test('renders at least 2x and prefers 3x', () => {
    assert.equal(viewerRenderScale(1191, 842), 3);
    assert.ok(viewerRenderScale(5000, 5000) >= 2);
    assert.ok(viewerRenderScale(5000, 5000) <= 3);
});

test('hides opleverpunten on the voortgang layer', () => {
    assert.equal(layerShowsRooms('rooms'), true);
    assert.equal(layerShowsSnags('rooms'), false);
    assert.equal(layerShowsRooms('snags'), false);
    assert.equal(layerShowsSnags('snags'), true);
    assert.equal(layerShowsRooms('both'), true);
    assert.equal(layerShowsSnags('both'), true);
});

test('room focus uses stored polygon bbox and keeps a margin', () => {
    const marker = {
        page: 2,
        x: 0.12,
        y: 0.34,
        width: 0.02,
        height: 0.01,
        polygon: [
            { x: 0.10, y: 0.30 },
            { x: 0.22, y: 0.30 },
            { x: 0.22, y: 0.42 },
            { x: 0.10, y: 0.42 },
        ],
    };
    assert.equal(hasReliableRoomPosition(marker), true);
    const box = roomFocusBox(marker);
    assert.equal(box.page, 2);
    assert.equal(box.x, 0.10);
    assert.equal(box.y, 0.30);
    assert.ok(Math.abs(box.w - 0.12) < 0.0001);
    assert.ok(Math.abs(box.h - 0.12) < 0.0001);

    const view = focusViewport(box, 800, 600, 1000, 700);
    assert.ok(view.scale >= 0.55);
    assert.ok(view.scale <= 3.2);
    assert.ok(Number.isFinite(view.panX));
    assert.ok(Number.isFinite(view.panY));
});

test('missing room position is not treated as reliable', () => {
    assert.equal(hasReliableRoomPosition(null), false);
    assert.equal(hasReliableRoomPosition({ page: 0, x: 0.1, y: 0.1 }), false);
    assert.equal(hasReliableRoomPosition({ page: 1 }), false);
    assert.equal(roomFocusBox({ page: 1, x: 0.2, y: 0.3, width: 0.08, height: 0.04 }).w, 0.08);
});

test('P.0.17 matches only that exact room number', () => {
    const items = [
        { page: 1, text: 'P.0.17 theorielokaal', x: 0.22, y: 0.31, w: 0.12, h: 0.02, source: 'text' },
        { page: 1, text: 'P.0.170 berging', x: 0.48, y: 0.31, w: 0.10, h: 0.02, source: 'text' },
        { page: 1, text: 'P.0.17a kantoor', x: 0.62, y: 0.31, w: 0.10, h: 0.02, source: 'text' },
        { page: 1, text: 'P.0.18 wasruimte', x: 0.22, y: 0.55, w: 0.12, h: 0.02, source: 'text' },
        { page: 2, text: 'P. 0.17 theorielokaal', x: 0.30, y: 0.40, w: 0.12, h: 0.02, source: 'text' },
    ];
    const known = ['P.0.17', 'P.0.18', 'P.0.17a', 'P.0.170'];
    const hits = findExactRoomHits(items, known);
    const byNumber = Object.fromEntries(hits.map((hit) => [`${hit.number}|${hit.page}`, hit]));

    assert.deepEqual(roomNumbersIn('P.0.17 theorielokaal').slice(0, 1), ['p.0.17']);
    assert.equal(pickBestKnownRoomNumber(roomNumbersIn('P.0.17 theorielokaal'), known.map((n) => n.toLowerCase())), 'p.0.17');
    assert.equal(byNumber['p.0.17|1'].page, 1);
    assert.equal(byNumber['p.0.18|1'].page, 1);
    assert.equal(byNumber['p.0.17a|1'].x, 0.62);
    assert.equal(byNumber['p.0.170|1'].x, 0.48);
    assert.equal(hits.filter((hit) => hit.number === 'p.0.17').length, 2);

    const area = { id: 17, number: 'P.0.17', name: 'theorielokaal' };
    const pageOne = hits.filter((hit) => hit.page === 1);
    const hit = exactRoomHitForArea(area, pageOne);
    assert.equal(hit.page, 1);
    assert.equal(hit.number, 'p.0.17');
    assert.equal(exactRoomHitForArea({ id: 18, number: 'P.0.18', name: 'wasruimte' }, pageOne).x, 0.22);
    assert.equal(exactRoomHitForArea(area, hits), null);
    assert.equal(exactRoomHitForArea({ id: 99, number: 'P.0.17', name: 'theorielokaal' }, [
        { number: 'p.0.170', page: 1, x: 0.48, y: 0.31, text: 'P.0.170 berging' },
        { number: 'p.0.17a', page: 1, x: 0.62, y: 0.31, text: 'P.0.17a kantoor' },
        { number: 'p.0.18', page: 1, x: 0.22, y: 0.55, text: 'P.0.18 wasruimte' },
    ]), null);
});

test('stored jump target prefers the persisted jump_target on the area id', () => {
    const area = {
        id: 17,
        number: 'P.0.17',
        name: 'theorielokaal',
        jump_target: {
            project_area_id: 17,
            drawing_marker_id: 44,
            page: 3,
            center_x: 0.26,
            center_y: 0.41,
            bbox: { x: 0.20, y: 0.38, w: 0.12, h: 0.06 },
        },
        marker: { page: 1, x: 0.80, y: 0.80, width: 0.05, height: 0.04 },
    };
    const jump = storedJumpTarget(area);
    assert.equal(jump.page, 3);
    assert.equal(jump.box.x, 0.20);
    assert.equal(jump.drawing_marker_id, 44);
    assert.equal(jump.geometry, 'marker:44');
});

test('hits the smaller room polygon when contours overlap', () => {
    const square = [
        { x: 0.10, y: 0.10 },
        { x: 0.40, y: 0.10 },
        { x: 0.40, y: 0.40 },
        { x: 0.10, y: 0.40 },
    ];
    const inner = [
        { x: 0.18, y: 0.18 },
        { x: 0.28, y: 0.18 },
        { x: 0.28, y: 0.28 },
        { x: 0.18, y: 0.28 },
    ];
    const rooms = [
        { id: 1, contour: roomContour({ marker: { polygon: square } }) },
        { id: 2, contour: roomContour({ marker: { polygon: inner } }) },
    ];

    assert.equal(pointInPolygon({ x: 0.23, y: 0.23 }, square), true);
    assert.equal(pointInPolygon({ x: 0.05, y: 0.05 }, square), false);
    assert.equal(hitTestContours({ x: 0.23, y: 0.23 }, rooms).id, 2);
    assert.equal(hitTestContours({ x: 0.12, y: 0.12 }, rooms).id, 1);
    assert.equal(hitTestContours({ x: 0.90, y: 0.90 }, rooms), null);
    assert.equal(roomContour({ marker: { page: 1, x: 0.2, y: 0.3, width: 0.1, height: 0.05 } }).type, 'box');
});
