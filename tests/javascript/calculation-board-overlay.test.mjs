import assert from 'node:assert/strict';
import test from 'node:test';
import {
    applyRoomGeometry,
    overlayChipText,
    overlayPlan,
    printDrawingSheets,
    printPaper,
    roomLabelAnchor,
    usableLabelHits,
} from '../../resources/js/calculation-board-overlay.js';

function room(overrides = {}) {
    return {
        key: 'a-01-01',
        drawing_id: 336,
        number: 'A-01-01',
        floor_code: 'v04',
        floor_codes_label: 'v04',
        material_key: 'v04',
        material_color: '#c2410c',
        ...overrides,
    };
}

test('one drawing with one pdf page becomes one print sheet', () => {
    const sheets = printDrawingSheets([{ id: 336, label: 'begane grond', pageCount: 1 }]);

    assert.equal(sheets.length, 1);
    assert.equal(sheets[0].drawingId, 336);
    assert.equal(sheets[0].page, 1);
});

test('seven drawings become seven print sheets', () => {
    const drawings = [336, 337, 338, 339, 340, 341, 342].map((id) => ({
        id,
        label: `tekening ${id}`,
        pageCount: 1,
    }));

    const sheets = printDrawingSheets(drawings);

    assert.equal(sheets.length, 7);
    assert.deepEqual(sheets.map((sheet) => sheet.drawingId), [336, 337, 338, 339, 340, 341, 342]);
    assert.ok(sheets.every((sheet) => sheet.page === 1));
});

test('code sits at the contour center of its own room', () => {
    const kitchen = room({
        key: 'k-00-23',
        number: 'K-00-23',
        contour: {
            page: 1,
            reliable: true,
            rects: [{ x: 0.41, y: 0.62, w: 0.10, h: 0.08 }],
        },
        marker: { page: 1, x: 0.18, y: 0.04, width: 0.05, height: 0.02, source: 'text' },
    });

    applyRoomGeometry(kitchen);
    const box = roomLabelAnchor(kitchen);
    const plan = overlayPlan([kitchen], 336, 1, { roomLabels: true, materialCodes: true });

    assert.equal(Number((box.x + box.w / 2).toFixed(2)), 0.46);
    assert.equal(Number((box.y + box.h / 2).toFixed(2)), 0.66);
    assert.equal(plan[0].number, 'K-00-23');
    assert.equal(plan[0].source, 'contour');
    assert.equal(plan[0].text, 'K-00-23 · v04');
    assert.ok(plan[0].box.y > 0.5);
});

test('does not collect codes into a strip above the drawing when rooms have geometry', () => {
    const rooms = [
        room({
            key: 'a-01-01',
            number: 'A-01-01',
            contour: { page: 1, reliable: true, rects: [{ x: 0.12, y: 0.58, w: 0.08, h: 0.07 }] },
            marker: { page: 1, x: 0.10, y: 0.03, width: 0.04, height: 0.015, source: 'text' },
        }),
        room({
            key: 'k-00-23',
            number: 'K-00-23',
            contour: { page: 1, reliable: true, rects: [{ x: 0.55, y: 0.71, w: 0.09, h: 0.06 }] },
            marker: { page: 1, x: 0.22, y: 0.035, width: 0.04, height: 0.015, source: 'text' },
        }),
    ];

    const plan = overlayPlan(rooms, 336, 1, { roomLabels: true, materialCodes: true });
    const tops = plan.map((item) => item.box.y + item.box.h / 2);

    assert.equal(plan.length, 2);
    assert.ok(tops.every((top) => top > 0.5));
    assert.notEqual(tops[0], tops[1]);
    assert.ok(Math.abs(plan[0].box.x - plan[1].box.x) > 0.2);
});

test('rooms codes colored and legend flags stay independent', () => {
    const kitchen = room({
        contour: { page: 1, reliable: true, rects: [{ x: 0.4, y: 0.6, w: 0.1, h: 0.08 }] },
    });

    assert.equal(overlayChipText(kitchen, { roomLabels: true, materialCodes: false }), 'A-01-01');
    assert.equal(overlayChipText(kitchen, { roomLabels: false, materialCodes: true }), 'v04');
    assert.equal(overlayChipText(kitchen, { roomLabels: false, materialCodes: false }), '');

    const colored = overlayPlan([kitchen], 336, 1, { colored: true, materialCodes: true });
    const plain = overlayPlan([kitchen], 336, 1, { colored: false, roomLabels: true, materialCodes: false });

    assert.equal(colored[0].colored, true);
    assert.equal(colored[0].text, 'v04');
    assert.equal(plain[0].colored, false);
    assert.equal(plain[0].text, 'A-01-01');
    assert.equal(printPaper({ width: 1684, height: 1190 }).size, 'A3 landscape');
    assert.equal(printPaper({ width: 840, height: 1190 }).size, 'A3 portrait');
});

test('rooms without geometry still use their stored label position', () => {
    const loose = room({
        number: 'A-01-99',
        key: 'a-01-99',
        contour: null,
        marker: { page: 1, x: 0.33, y: 0.48, width: 0.06, height: 0.03, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.33, y: 0.48, w: 0.06, h: 0.03 }, geometry: 'label' },
    });

    assert.equal(applyRoomGeometry(loose), false);
    const box = roomLabelAnchor(loose);
    const plan = overlayPlan([loose], 336, 1, { roomLabels: true, materialCodes: true });

    assert.equal(Number(box.x.toFixed(2)), 0.33);
    assert.equal(Number(box.y.toFixed(2)), 0.48);
    assert.equal(Number(box.w.toFixed(2)), 0.06);
    assert.equal(Number(box.h.toFixed(2)), 0.03);
    assert.equal(plan[0].source, 'text');
    assert.equal(plan[0].text, 'A-01-99 · v04');
});

test('ignores a dense legend strip of room codes above the plan', () => {
    const hits = [];
    for (let index = 0; index < 20; index += 1) {
        hits.push({
            number: `A-01-${String(index).padStart(2, '0')}`,
            page: 1,
            x: 0.04 + (index % 10) * 0.08,
            y: 0.04 + Math.floor(index / 10) * 0.03,
            w: 0.04,
            h: 0.016,
            source: 'text',
        });
    }
    const inRoom = {
        number: 'A-01-01',
        page: 1,
        x: 0.42,
        y: 0.64,
        w: 0.05,
        h: 0.02,
        source: 'text',
    };

    assert.equal(usableLabelHits(hits).length, 0);
    assert.deepEqual(usableLabelHits([...hits, inRoom]).map((hit) => hit.y), [0.64]);
});
