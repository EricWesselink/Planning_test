import assert from 'node:assert/strict';
import test from 'node:test';
import {
    applyRoomGeometry,
    overlayChipText,
    overlayPlan,
    overlayRoomsOnPage,
    placeBoardRooms,
    legendFromRooms,
    printDrawingSheets,
    printImageFromCanvas,
    isUsablePrintImageSrc,
    printPaper,
    roomLabelAnchor,
    usableLabelHits,
    waitForPrintAssets,
    waitForPrintImage,
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

test('print still places every known board room when labels sit in a dense strip', () => {
    const rooms = [
        room({ key: 'k-01-01', number: 'K-01-01', contour: null, floor_codes_label: 'v04' }),
        room({ key: 'k-01-02', number: 'K-01-02', contour: null, floor_codes_label: 'v01.d' }),
        room({ key: 'k-01-06', number: 'K-01-06', contour: null, floor_codes_label: 'v09' }),
        room({
            key: 'k-01-10',
            number: 'K-01-10',
            floor_codes_label: 'v04',
            contour: { page: 1, reliable: true, rects: [{ x: 0.41, y: 0.62, w: 0.10, h: 0.08 }] },
        }),
        room({
            key: 'k-01-12',
            number: 'K-01-12',
            contour: null,
            floor_code: 'v06.b',
            floor_codes_label: 'v06.b + v01.i + v01.d',
            material_key: 'v06.b',
            floors: [
                { code: 'v06.b', product: 'PVC', quantity: 40, material_key: 'v06.b', material_color: '#c2410c' },
                { code: 'v01.i', product: 'Marmoleum', quantity: 20, material_key: 'v01.i', material_color: '#7c3aed' },
                { code: 'v01.d', product: 'Marmoleum', quantity: 18.9, material_key: 'v01.d', material_color: '#0f766e' },
            ],
        }),
    ];
    const hits = rooms.map((item, index) => ({
        number: item.number,
        page: 1,
        x: 0.04 + (index % 10) * 0.08,
        y: 0.04,
        w: 0.04,
        h: 0.016,
        source: 'text',
    }));
    for (let index = 0; index < 12; index += 1) {
        hits.push({
            number: `K-01-${String(20 + index).padStart(2, '0')}`,
            page: 1,
            x: 0.04 + (index % 6) * 0.08,
            y: 0.04,
            w: 0.04,
            h: 0.016,
            source: 'text',
        });
    }

    placeBoardRooms(rooms, 336, hits);
    const plan = overlayPlan(rooms, 336, 1, { roomLabels: true, materialCodes: true });

    assert.equal(usableLabelHits(hits).length, 0);
    assert.deepEqual(plan.map((item) => item.number).sort(), ['K-01-01', 'K-01-02', 'K-01-06', 'K-01-10', 'K-01-12']);
    assert.equal(plan.find((item) => item.number === 'K-01-12').text, 'K-01-12 · v06.b + v01.i + v01.d');
    assert.equal(overlayRoomsOnPage(rooms, 336, 1).length, 5);
});

test('legend totals come from the rooms on that sheet, not the whole calculation', () => {
    const kelder = [
        room({
            key: 'k-01-01',
            number: 'K-01-01',
            floor_code: 'v04',
            floors: [{ code: 'v04', product: 'Gietvloer', quantity: 12.7, material_color: '#c2410c' }],
            contour: { page: 1, reliable: true, rects: [{ x: 0.2, y: 0.5, w: 0.1, h: 0.08 }] },
        }),
        room({
            key: 'k-01-06',
            number: 'K-01-06',
            floor_code: 'v09',
            floors: [{ code: 'v09', product: 'Schoonloopmat', quantity: 4.2, material_color: '#0369a1' }],
            contour: { page: 1, reliable: true, rects: [{ x: 0.4, y: 0.5, w: 0.1, h: 0.08 }] },
        }),
    ];
    const other = room({
        key: 'a-00-15',
        drawing_id: 337,
        number: 'A-00-15',
        floor_code: 'v01.g',
        floors: [{ code: 'v01.g', product: 'Marmoleum', quantity: 240, material_color: '#15803d' }],
        contour: { page: 1, reliable: true, rects: [{ x: 0.2, y: 0.5, w: 0.1, h: 0.08 }] },
    });

    const legend = legendFromRooms(overlayRoomsOnPage([...kelder, other], 336, 1));

    assert.deepEqual(legend.map((item) => item.code), ['v04', 'v09']);
    assert.equal(legend.find((item) => item.code === 'v04').m2_label, '12,70 m²');
    assert.equal(legend.some((item) => item.code === 'v01.g'), false);
});

test('print snapshot keeps a jpeg data url from the rendered canvas', async () => {
    const src = `data:image/jpeg;base64,${'A'.repeat(80)}`;
    const result = await printImageFromCanvas({
        width: 120,
        height: 80,
        toDataURL(type) {
            assert.equal(type, 'image/jpeg');

            return src;
        },
    });

    assert.equal(result.src, src);
    assert.equal(result.width, 120);
    assert.equal(result.height, 80);
});

test('print snapshot rejects a blank or zero-size canvas', async () => {
    await assert.rejects(() => printImageFromCanvas({
        width: 0,
        height: 10,
        toDataURL: () => `data:image/jpeg;base64,${'A'.repeat(80)}`,
    }), /empty drawing canvas/);
    await assert.rejects(() => printImageFromCanvas({
        width: 10,
        height: 10,
        toDataURL: () => 'data:,',
    }), /empty drawing snapshot/);
});

test('print snapshot falls back to png when jpeg data is empty', async () => {
    const png = `data:image/png;base64,${'B'.repeat(80)}`;
    const result = await printImageFromCanvas({
        width: 20,
        height: 20,
        toDataURL(type) {
            return type === 'image/png' ? png : 'data:,';
        },
    });

    assert.equal(result.src, png);
});

test('print snapshot prefers a jpeg data url from the canvas blob', async () => {
    const blob = new Blob([new Uint8Array(80)], { type: 'image/jpeg' });
    const result = await printImageFromCanvas({
        width: 120,
        height: 80,
        toBlob(callback) {
            callback(blob);
        },
        toDataURL: () => 'data:,',
    });

    assert.equal(isUsablePrintImageSrc(result.src), true);
    assert.ok(result.src.startsWith('data:image/') || result.src.startsWith('blob:'));
});

test('print waits until every drawing image has a non-empty size', async () => {
    const src = `data:image/jpeg;base64,${'A'.repeat(80)}`;
    const image = {
        tagName: 'IMG',
        src,
        getAttribute: () => src,
        complete: true,
        naturalWidth: 400,
        naturalHeight: 280,
        decode: async () => {},
    };

    const images = await waitForPrintAssets({
        querySelectorAll: () => [image],
    }, { fonts: { ready: Promise.resolve() }, requireDrawings: true });

    assert.equal(images.length, 1);
});

test('print does not treat a missing or empty drawing image as ready', async () => {
    await assert.rejects(() => waitForPrintImage({
        src: '',
        getAttribute: () => '',
        complete: true,
        naturalWidth: 0,
        naturalHeight: 0,
    }), /empty drawing image/);
    await assert.rejects(() => waitForPrintAssets({
        querySelectorAll: () => [],
    }, { requireDrawings: true }), /no print drawings/);
});
