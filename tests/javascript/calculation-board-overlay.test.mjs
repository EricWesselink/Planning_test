import assert from 'node:assert/strict';
import test from 'node:test';
import {
    applyRoomGeometry,
    chipAnchorInRoom,
    overlayChipText,
    overlayPlan,
    overlayRoomsOnPage,
    placeBoardRooms,
    legendFromRooms,
    materialCodesInOverlayText,
    roomsForDrawing,
    printDrawingSheets,
    printFillContour,
    printImageFromCanvas,
    isUsablePrintImageSrc,
    printPaper,
    roomLabelAnchor,
    clampPrintLabelCenter,
    separatePrintLabelCenters,
    usableLabelHits,
    printLegendGoesBelow,
    waitForPrintAssets,
    waitForPrintImage,
    drawingStoreyPrefix,
    roomsOnDrawing,
    mergePrintRoomChips,
    attachRoomCaptions,
    tightGlyphBox,
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
    assert.equal(plan[0].text, 'v04');
    assert.ok(plan[0].box.y > 0.5);
    const chip = plan[0].chip;
    assert.ok(chip.x > 0.41 && chip.x < 0.51);
    assert.ok(chip.y > 0.62 && chip.y < 0.70);
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

    assert.equal(overlayChipText(kitchen, { roomLabels: true, materialCodes: true }), 'v04');
    assert.equal(overlayChipText(kitchen, { roomLabels: true, materialCodes: false }), 'A-01-01');
    assert.equal(overlayChipText(kitchen, { roomLabels: false, materialCodes: true }), 'v04');
    assert.equal(overlayChipText(kitchen, { roomLabels: false, materialCodes: false }), '');

    const colored = overlayPlan([kitchen], 336, 1, { colored: true, materialCodes: true });
    const plain = overlayPlan([kitchen], 336, 1, { colored: false, roomLabels: true, materialCodes: false });

    assert.equal(colored[0].colored, true);
    assert.equal(colored[0].fill, null);
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
    assert.equal(plan[0].text, 'v04');
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

test('print keeps a room on the plan and drops labels that only exist in a legend strip', () => {
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
    assert.deepEqual(plan.map((item) => item.number), ['K-01-10']);
    assert.equal(plan[0].text, 'v04');
    assert.equal(overlayRoomsOnPage(rooms, 336, 1).length, 1);
});

test('a full basement floor keeps in-plan material labels', () => {
    const rooms = [];
    const hits = [];
    for (let index = 1; index <= 16; index += 1) {
        const col = (index - 1) % 8;
        const row = Math.floor((index - 1) / 8);
        const number = `K-00-${String(index).padStart(2, '0')}`;
        rooms.push(room({
            key: number.toLowerCase(),
            number,
            floor_codes_label: index % 3 === 0 ? 'v09' : 'v01.a',
            contour: null,
        }));
        hits.push({
            number,
            page: 1,
            x: 0.08 + col * 0.06,
            y: 0.32 + row * 0.12,
            w: 0.05,
            h: 0.02,
            source: 'text',
        });
        hits.push({
            number,
            page: 1,
            x: 0.78,
            y: 0.12 + index * 0.03,
            w: 0.04,
            h: 0.016,
            source: 'text',
        });
    }

    placeBoardRooms(rooms, 336, hits);
    const plan = overlayPlan(rooms, 336, 1, { roomLabels: true, materialCodes: true });

    assert.equal(plan.length, 16);
    assert.ok(plan.every((item) => item.box.x < 0.60));
    assert.ok(plan.every((item) => item.box.y > 0.20));
    assert.equal(plan.find((item) => item.number === 'K-00-01').text, 'v01.a');
    assert.equal(plan.find((item) => item.number === 'K-00-03').text, 'v09');
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

test('print overlay keeps rooms on their own drawing_id and never guesses from the room number', () => {
    const k00 = 340;
    const rooms = [
        room({
            key: 'k-00-01',
            drawing_id: k00,
            number: 'K-00-01',
            floor_codes_label: 'v04',
            contour: { page: 1, reliable: true, rects: [{ x: 0.2, y: 0.5, w: 0.1, h: 0.08 }] },
        }),
        room({
            key: 'a-02-03',
            drawing_id: 342,
            number: 'A-02-03',
            floor_codes_label: 'v01.g',
            contour: { page: 1, reliable: true, rects: [{ x: 0.12, y: 0.18, w: 0.08, h: 0.06 }] },
            marker: { page: 1, x: 0.12, y: 0.18, width: 0.08, height: 0.06, source: 'text' },
        }),
        room({
            key: 'k-01-26',
            drawing_id: 341,
            number: 'K-01-26',
            floor_codes_label: 'v09',
            marker: { page: 1, x: 0.44, y: 0.22, width: 0.05, height: 0.02, source: 'text' },
            jump_target: { page: 1, bbox: { x: 0.44, y: 0.22, w: 0.05, h: 0.02 }, geometry: 'label' },
        }),
        room({
            key: 'k-01-29',
            drawing_id: 341,
            number: 'K-01-29',
            floor_codes_label: 'v04',
            marker: { page: 1, x: 0.62, y: 0.31, width: 0.05, height: 0.02, source: 'text' },
            jump_target: { page: 1, bbox: { x: 0.62, y: 0.31, w: 0.05, h: 0.02 }, geometry: 'label' },
        }),
    ];
    const hits = rooms.map((item, index) => ({
        number: item.number,
        page: 1,
        x: 0.1 + index * 0.15,
        y: 0.2,
        w: 0.05,
        h: 0.02,
        source: 'text',
    }));

    placeBoardRooms(rooms, k00, hits);
    const printed = overlayRoomsOnPage(rooms, k00, 1);
    const plan = overlayPlan(rooms, k00, 1, { roomLabels: true, materialCodes: true });

    assert.deepEqual(roomsForDrawing(rooms, k00).map((item) => item.number), ['K-00-01']);
    assert.deepEqual(printed.map((item) => item.number), ['K-00-01']);
    assert.ok(printed.every((item) => Number(item.drawing_id) === k00));
    assert.deepEqual(plan.map((item) => item.number), ['K-00-01']);
    assert.ok(plan.every((item) => item.drawing_id === k00));
    assert.equal(plan.some((item) => ['A-02-03', 'K-01-26', 'K-01-29'].includes(item.number)), false);
});

test('page legend contains every material code that a printed label shows', () => {
    const rooms = [
        room({
            key: 'k-01-18',
            number: 'K-01-18',
            floor_code: '',
            floor_codes_label: 'v08',
            floors: [],
            material_color: '#7c3aed',
            contour: { page: 1, reliable: true, rects: [{ x: 0.3, y: 0.5, w: 0.1, h: 0.08 }] },
        }),
        room({
            key: 'k-01-12',
            number: 'K-01-12',
            floor_code: 'v06.b',
            floor_codes_label: 'v06.b + v01.i + v01.d',
            material_color: '#c2410c',
            floors: [
                { code: 'v06.b', product: 'PVC', quantity: 40, material_color: '#c2410c' },
                { code: 'v01.i', product: 'Marmoleum', quantity: 20, material_color: '#7c3aed' },
                { code: 'v01.d', product: 'Marmoleum', quantity: 18.9, material_color: '#0f766e' },
            ],
            contour: { page: 1, reliable: true, rects: [{ x: 0.5, y: 0.5, w: 0.1, h: 0.08 }] },
        }),
        room({
            key: 'a-02-03',
            drawing_id: 337,
            number: 'A-02-03',
            floor_codes_label: 'v01.g',
            floors: [{ code: 'v01.g', product: 'Marmoleum', quantity: 12, material_color: '#15803d' }],
            contour: { page: 1, reliable: true, rects: [{ x: 0.1, y: 0.2, w: 0.1, h: 0.08 }] },
        }),
    ];
    const printed = overlayRoomsOnPage(rooms, 336, 1);
    const plan = overlayPlan(rooms, 336, 1, { roomLabels: true, materialCodes: true });
    const legend = legendFromRooms(printed);
    const legendCodes = legend.map((item) => item.code);
    const labelCodes = [...new Set(plan.flatMap((item) => materialCodesInOverlayText(item.text)))];

    assert.equal(plan.find((item) => item.number === 'K-01-18').text, 'v08');
    assert.equal(plan.find((item) => item.number === 'K-01-12').text, 'v06.b + v01.i + v01.d');
    assert.equal(plan.some((item) => item.number === 'A-02-03'), false);
    assert.ok(legendCodes.includes('v08'));
    assert.deepEqual(legendCodes, ['v01.d', 'v01.i', 'v06.b', 'v08']);
    assert.ok(labelCodes.every((code) => legendCodes.includes(code)));
    assert.equal(legend.find((item) => item.code === 'v08').color, '#7c3aed');
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

test('print does not fill from rects, text boxes or title-block strips', () => {
    const strip = room({
        key: 'a-01-13',
        number: 'A-01-13',
        floor_codes_label: 'v04',
        contour: {
            page: 1,
            reliable: true,
            rects: [{ x: 0.72, y: 0.06, w: 0.16, h: 0.58 }],
        },
        marker: { page: 1, x: 0.46, y: 0.41, width: 0.05, height: 0.02, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.46, y: 0.41, w: 0.05, h: 0.02 }, geometry: 'label' },
    });
    const textPoly = room({
        key: 'a-01-12',
        number: 'A-01-12',
        floor_codes_label: 'v06.c + v01.i + v01.f',
        contour: {
            page: 1,
            reliable: true,
            polygon: [
                { x: 0.20, y: 0.30 },
                { x: 0.25, y: 0.30 },
                { x: 0.25, y: 0.32 },
                { x: 0.20, y: 0.32 },
            ],
        },
        marker: { page: 1, x: 0.20, y: 0.30, width: 0.05, height: 0.02, source: 'text' },
    });
    const empty = room({
        key: 'a-02-01',
        drawing_id: 338,
        number: 'A-02-01',
        floor_code: '',
        floor_codes_label: '',
        contour: null,
        marker: null,
    });

    const plan = overlayPlan([strip, textPoly], 336, 1, { colored: true, roomLabels: true, materialCodes: true });
    const center = clampPrintLabelCenter(plan.find((item) => item.number === 'A-01-13').box);

    assert.equal(printFillContour(strip), null);
    assert.equal(printFillContour(textPoly), null);
    assert.equal(plan.every((item) => item.fill === null), true);
    assert.equal(plan.find((item) => item.number === 'A-01-13').text, 'v04');
    assert.equal(plan.find((item) => item.number === 'A-01-12').text, 'v06.c + v01.i + v01.f');
    assert.ok(plan.find((item) => item.number === 'A-01-13').box.x < 0.55);
    assert.ok(center.x < 0.55);
    assert.equal(overlayRoomsOnPage([empty], 338, 1).length, 0);
});

test('print fills only an explicit reliable room polygon', () => {
    const polygon = room({
        contour: {
            page: 1,
            reliable: true,
            polygon: [
                { x: 0.20, y: 0.40 },
                { x: 0.34, y: 0.40 },
                { x: 0.34, y: 0.54 },
                { x: 0.28, y: 0.54 },
                { x: 0.20, y: 0.48 },
            ],
        },
    });
    const rects = room({
        key: 'a-01-04',
        number: 'A-01-04',
        contour: {
            page: 1,
            reliable: true,
            rects: [{ x: 0.22, y: 0.44, w: 0.10, h: 0.08 }],
        },
    });

    const filled = overlayPlan([polygon], 336, 1, { colored: true, roomLabels: true, materialCodes: true });
    const labeled = overlayPlan([rects], 336, 1, { colored: true, roomLabels: true, materialCodes: true });

    assert.equal(filled[0].fill.type, 'polygon');
    assert.equal(filled[0].fill.points.length, 5);
    assert.equal(labeled[0].fill, null);
    assert.equal(labeled[0].text, 'v04');
});

test('print keeps neighbouring room codes in their own rooms', () => {
    const toilets = [
        room({
            key: 'k-01-03',
            number: 'K-01-03',
            floor_codes_label: 'v04',
            contour: null,
            marker: { page: 1, x: 0.22, y: 0.68, width: 0.04, height: 0.05, source: 'contour' },
            jump_target: { page: 1, bbox: { x: 0.22, y: 0.68, w: 0.04, h: 0.05 }, geometry: 'contour' },
        }),
        room({
            key: 'k-01-04',
            number: 'K-01-04',
            floor_codes_label: 'v04',
            contour: null,
            marker: { page: 1, x: 0.22, y: 0.74, width: 0.04, height: 0.05, source: 'contour' },
            jump_target: { page: 1, bbox: { x: 0.22, y: 0.74, w: 0.04, h: 0.05 }, geometry: 'contour' },
        }),
        room({
            key: 'k-01-05',
            number: 'K-01-05',
            floor_codes_label: 'v04',
            contour: null,
            marker: { page: 1, x: 0.22, y: 0.80, width: 0.04, height: 0.05, source: 'contour' },
            jump_target: { page: 1, bbox: { x: 0.22, y: 0.80, w: 0.04, h: 0.05 }, geometry: 'contour' },
        }),
    ];
    const plan = overlayPlan(toilets, 336, 1, { roomLabels: false, materialCodes: true });
    const ys = plan.map((item) => item.box.y + item.box.h / 2);
    const clamped = clampPrintLabelCenter({ x: 0.92, y: 0.08, w: 0.12, h: 0.50 });

    assert.equal(plan.length, 3);
    assert.ok(ys[0] < ys[1] && ys[1] < ys[2]);
    assert.ok(ys[2] < 0.90);
    assert.ok(plan.every((item) => item.text === 'v04'));
    assert.deepEqual(separatePrintLabelCenters([{ x: 0.40, y: 0.40 }, { x: 0.41, y: 0.40 }]).map((center) => center.y), [0.40, 0.40]);
    assert.equal(Number(clamped.x.toFixed(2)), 0.97);
});

function chipMissesBox(chip, box, halfW = 0.0075, halfH = 0.0045) {
    const right = chip.x + halfW;
    const left = chip.x - halfW;
    const top = chip.y - halfH;
    const bottom = chip.y + halfH;

    return right < box.x || left > box.x + box.w || bottom < box.y || top > box.y + box.h;
}

test('a toilet v04 stays inside the stall and not on the wall', () => {
    const stall = { x: 0.20, y: 0.42, w: 0.028, h: 0.038 };
    const toilet = room({
        key: 'k-01-03',
        number: 'K-01-03',
        name: 'TOILET',
        floor_codes_label: 'v04',
        floor_code: 'v04',
        contour: { page: 1, reliable: true, role: 'room_floor', rects: [stall] },
        marker: { page: 1, x: 0.204, y: 0.428, width: 0.018, height: 0.012, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.204, y: 0.428, w: 0.018, h: 0.012 }, geometry: 'label' },
    });
    const chip = chipAnchorInRoom(toilet, { roomLabels: false, materialCodes: true });
    const plan = overlayPlan([toilet], 336, 1, { roomLabels: false, materialCodes: true });

    assert.equal(plan[0].text, 'v04');
    assert.ok(chip.x > stall.x);
    assert.ok(chip.x < stall.x + stall.w);
    assert.ok(chip.y > stall.y);
    assert.ok(chip.y < stall.y + stall.h);
    assert.ok(chip.x < stall.x + stall.w - 0.001);
});

test('a toilet without a floor box keeps v04 beside the room name before the door', () => {
    const items = [
        { text: 'TOILET', page: 1, x: 0.180, y: 0.400, w: 0.05, h: 0.010 },
        { text: 'K-01-03', page: 1, x: 0.180, y: 0.412, w: 0.05, h: 0.009 },
        { text: '1.3 m²', page: 1, x: 0.180, y: 0.422, w: 0.04, h: 0.008 },
        { text: 'BI.H01', page: 1, x: 0.214, y: 0.408, w: 0.018, h: 0.008 },
        { text: 'TOILET', page: 1, x: 0.180, y: 0.460, w: 0.05, h: 0.010 },
        { text: 'K-01-04', page: 1, x: 0.180, y: 0.472, w: 0.05, h: 0.009 },
        { text: '1.3 m²', page: 1, x: 0.180, y: 0.482, w: 0.04, h: 0.008 },
        { text: 'BI.H01', page: 1, x: 0.214, y: 0.468, w: 0.018, h: 0.008 },
    ];
    const toilet = room({
        key: 'k-01-03',
        number: 'K-01-03',
        name: 'TOILET',
        floor_codes_label: 'v04',
        floor_code: 'v04',
        contour: null,
        marker: { page: 1, x: 0.180, y: 0.412, width: 0.05, height: 0.02, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.180, y: 0.412, w: 0.05, h: 0.02 }, geometry: 'label' },
    });
    const below = room({
        key: 'k-01-04',
        number: 'K-01-04',
        name: 'TOILET',
        floor_codes_label: 'v04',
        contour: null,
        marker: { page: 1, x: 0.180, y: 0.472, width: 0.05, height: 0.02, source: 'text' },
    });
    attachRoomCaptions([toilet, below], items);
    const chip = chipAnchorInRoom(toilet, {
        items,
        rooms: [toilet, below],
        roomLabels: false,
        materialCodes: true,
    });
    const name = tightGlyphBox(items[0]);
    const inflatedRight = 0.180 + 0.05;

    assert.ok(chip.x < 0.214);
    assert.ok(chip.x < inflatedRight);
    assert.ok(chip.x > name.x - 0.014);
    assert.ok(chip.y >= 0.392);
    assert.ok(chip.y < 0.455);
    assert.ok(chipMissesBox(chip, name));
});

test('stacked toilets without floor boxes keep each v04 inside their own stall', () => {
    const stalls = [
        { number: 'K-01-03', key: 'k-01-03', y: 0.300, doorY: 0.308 },
        { number: 'K-01-04', key: 'k-01-04', y: 0.360, doorY: 0.368 },
        { number: 'K-01-05', key: 'k-01-05', y: 0.420, doorY: 0.428 },
    ];
    const items = stalls.flatMap((stall) => [
        { text: 'TOILET', page: 1, x: 0.180, y: stall.y, w: 0.05, h: 0.010 },
        { text: stall.number, page: 1, x: 0.180, y: stall.y + 0.012, w: 0.05, h: 0.009 },
        { text: '1.3 m²', page: 1, x: 0.180, y: stall.y + 0.022, w: 0.04, h: 0.008 },
        { text: 'BI.H01', page: 1, x: 0.214, y: stall.doorY, w: 0.018, h: 0.008 },
    ]);
    const toilets = stalls.map((stall) => room({
        key: stall.key,
        number: stall.number,
        name: 'TOILET',
        floor_codes_label: 'v04',
        floor_code: 'v04',
        contour: null,
        marker: { page: 1, x: 0.180, y: stall.y + 0.012, width: 0.05, height: 0.02, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.180, y: stall.y + 0.012, w: 0.05, h: 0.02 }, geometry: 'label' },
    }));
    attachRoomCaptions(toilets, items);
    const chips = toilets.map((toilet) => chipAnchorInRoom(toilet, {
        items,
        rooms: toilets,
        roomLabels: false,
        materialCodes: true,
    }));

    chips.forEach((chip, index) => {
        const name = tightGlyphBox(items[index * 4]);
        assert.ok(chip.x < 0.214, `${stalls[index].number} leaked past the door`);
        assert.ok(chip.x > name.x - 0.014, `${stalls[index].number} left the stall`);
        assert.ok(chip.y >= stalls[index].y - 0.008);
        assert.ok(chip.y < stalls[index].y + 0.050);
        assert.ok(chipMissesBox(chip, name), `${stalls[index].number} covers TOILET`);
    });
    assert.ok(chips[0].y < chips[1].y);
    assert.ok(chips[1].y < chips[2].y);
});

test('a two-line toilet name is not covered by v04', () => {
    const items = [
        { text: 'TOILET', page: 1, x: 0.22, y: 0.40, w: 0.05, h: 0.010 },
        { text: 'KIND', page: 1, x: 0.22, y: 0.412, w: 0.04, h: 0.010 },
        { text: 'A-00-14', page: 1, x: 0.22, y: 0.424, w: 0.05, h: 0.009 },
        { text: '1.5 m²', page: 1, x: 0.22, y: 0.434, w: 0.04, h: 0.008 },
        { text: 'BI.H01', page: 1, x: 0.268, y: 0.418, w: 0.018, h: 0.008 },
    ];
    const toilet = room({
        key: 'a-00-14',
        number: 'A-00-14',
        name: 'TOILET KIND',
        floor_codes_label: 'v04',
        contour: null,
        marker: { page: 1, x: 0.22, y: 0.424, width: 0.05, height: 0.02, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.22, y: 0.424, w: 0.05, h: 0.02 }, geometry: 'label' },
    });
    attachRoomCaptions([toilet], items);
    const chip = chipAnchorInRoom(toilet, { items, rooms: [toilet], roomLabels: false, materialCodes: true });

    assert.ok(chipMissesBox(chip, tightGlyphBox(items[0])));
    assert.ok(chipMissesBox(chip, tightGlyphBox(items[1])));
    assert.ok(chip.x < 0.268);
    assert.ok(chip.x > 0.22 - 0.014);
    assert.ok(chip.y < 0.45);
});

test('a wet-area polygon left of the toilet does not steal v04', () => {
    const shower = { x: 0.12, y: 0.38, w: 0.048, h: 0.055 };
    const items = [
        { text: 'TOILET', page: 1, x: 0.180, y: 0.400, w: 0.05, h: 0.010 },
        { text: 'K-01-03', page: 1, x: 0.180, y: 0.412, w: 0.05, h: 0.009 },
        { text: '1.3 m²', page: 1, x: 0.180, y: 0.422, w: 0.04, h: 0.008 },
        { text: 'BI.H01', page: 1, x: 0.214, y: 0.408, w: 0.018, h: 0.008 },
    ];
    const toilet = room({
        key: 'k-01-03',
        number: 'K-01-03',
        name: 'TOILET',
        floor_codes_label: 'v04',
        contour: { page: 1, reliable: true, role: 'room_floor', rects: [shower] },
        marker: { page: 1, x: 0.180, y: 0.412, width: 0.05, height: 0.02, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.180, y: 0.412, w: 0.05, h: 0.02 }, geometry: 'label' },
    });
    attachRoomCaptions([toilet], items);
    const chip = chipAnchorInRoom(toilet, { items, rooms: [toilet], roomLabels: false, materialCodes: true });
    const name = tightGlyphBox(items[0]);

    assert.ok(chip.x > shower.x + shower.w);
    assert.ok(chip.x > name.x - 0.014);
    assert.ok(chip.x < 0.214);
    assert.ok(chipMissesBox(chip, name));
});

test('a saved label inside the room stays put and one outside snaps in', () => {
    const office = { x: 0.40, y: 0.30, w: 0.12, h: 0.10 };
    const inside = room({
        key: 'k-01-08',
        number: 'K-01-08',
        floor_codes_label: 'v01.a',
        contour: { page: 1, reliable: true, rects: [office] },
        marker: { page: 1, x: 0.46, y: 0.34, width: 0.03, height: 0.02, source: 'user' },
        jump_target: { page: 1, bbox: { x: 0.46, y: 0.34, w: 0.03, h: 0.02 }, geometry: 'label' },
    });
    const outside = room({
        key: 'k-01-07',
        number: 'K-01-07',
        floor_codes_label: 'v01.a',
        contour: { page: 1, reliable: true, rects: [office] },
        marker: { page: 1, x: 0.56, y: 0.34, width: 0.03, height: 0.02, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.56, y: 0.34, w: 0.03, h: 0.02 }, geometry: 'label' },
    });
    const kept = chipAnchorInRoom(inside, { roomLabels: false, materialCodes: true });
    const snapped = chipAnchorInRoom(outside, { roomLabels: false, materialCodes: true });

    assert.ok(Math.abs(kept.x - 0.475) < 0.02);
    assert.ok(kept.y > office.y && kept.y < office.y + office.h);
    assert.ok(snapped.x <= office.x + office.w);
    assert.ok(snapped.x >= office.x);
    assert.ok(snapped.y >= office.y && snapped.y <= office.y + office.h);
});

test('print ignores a cloud of trace rects and a title-block copy of the room number', () => {
    const blob = room({
        key: 'a-00-02',
        number: 'A-00-02',
        floor_codes_label: 'v01.e',
        contour: {
            page: 1,
            reliable: true,
            rects: Array.from({ length: 40 }, (_, index) => ({
                x: 0.46 + (index % 8) * 0.012,
                y: 0.01 + Math.floor(index / 8) * 0.02,
                w: 0.01,
                h: 0.01,
            })),
        },
        marker: { page: 1, x: 0.52, y: 0.14, width: 0.05, height: 0.02, source: 'contour' },
        jump_target: { page: 1, bbox: { x: 0.52, y: 0.14, w: 0.05, h: 0.02 }, geometry: 'contour' },
    });
    const duplicate = room({
        key: 'a-00-18',
        number: 'A-00-18',
        floor_codes_label: 'v01.g + v09',
        contour: null,
        marker: null,
    });
    const hits = [
        { number: 'A-00-01', page: 1, x: 0.22, y: 0.48, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-03', page: 1, x: 0.31, y: 0.36, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-04', page: 1, x: 0.28, y: 0.42, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-08', page: 1, x: 0.40, y: 0.50, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-10', page: 1, x: 0.18, y: 0.72, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-18', page: 1, x: 0.82, y: 0.44, w: 0.04, h: 0.02, source: 'text' },
        { number: 'A-00-18', page: 1, x: 0.58, y: 0.46, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-02', page: 1, x: 0.52, y: 0.14, w: 0.05, h: 0.02, source: 'text' },
    ];

    placeBoardRooms([blob, duplicate], 336, hits);
    const plan = overlayPlan([blob, duplicate], 336, 1, { colored: true, roomLabels: true, materialCodes: true });
    const eighteen = plan.find((item) => item.number === 'A-00-18');
    const two = plan.find((item) => item.number === 'A-00-02');

    assert.equal(roomLabelAnchor(blob)?.x, 0.52);
    assert.equal(eighteen.box.x, 0.58);
    assert.ok(clampPrintLabelCenter(eighteen.box).x < 0.70);
    assert.ok(two.box.x < 0.60);
    assert.ok(two.box.y > 0.10);
    assert.equal(printLegendGoesBelow(Array.from({ length: 8 }, (_, index) => ({ code: `v${index}` }))), false);
    assert.equal(printLegendGoesBelow(Array.from({ length: 22 }, (_, index) => ({ code: `v${index}` }))), true);
});

test('print does not put a title-block room number on the drawing', () => {
    const onlyBlock = room({
        key: 'a-00-13',
        number: 'A-00-13',
        floor_codes_label: 'v04',
        contour: null,
        marker: null,
    });
    const onPlan = room({
        key: 'a-00-05',
        number: 'A-00-05',
        floor_codes_label: 'v04',
        contour: null,
        marker: null,
    });
    const hits = [
        { number: 'A-00-01', page: 1, x: 0.18, y: 0.42, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-04', page: 1, x: 0.26, y: 0.40, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-05', page: 1, x: 0.33, y: 0.38, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-08', page: 1, x: 0.41, y: 0.36, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-13', page: 1, x: 0.78, y: 0.16, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-13', page: 1, x: 0.79, y: 0.22, w: 0.04, h: 0.018, source: 'text' },
        { number: 'A-00-14', page: 1, x: 0.78, y: 0.28, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-16', page: 1, x: 0.78, y: 0.34, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-05', page: 1, x: 0.78, y: 0.40, w: 0.05, h: 0.02, source: 'text' },
    ];

    placeBoardRooms([onlyBlock, onPlan], 336, hits);
    const plan = overlayPlan([onlyBlock, onPlan], 336, 1, { roomLabels: true, materialCodes: true });

    assert.equal(plan.some((item) => item.number === 'A-00-13'), false);
    assert.equal(plan.find((item) => item.number === 'A-00-05').box.x, 0.33);
    assert.ok(plan.find((item) => item.number === 'A-00-05').box.x < 0.60);
});

test('print keeps only this storey on a K_00 drawing and merges duplicate room numbers', () => {
    const label = '2401531_TEK_BK5_1_K_00 - Plattegrond begane grond';
    const rooms = [
        room({ key: 'k-00-34-a', number: 'K-00-34', floor_codes_label: 'v01.c', floor_code: 'v01.c', material_color: '#7c3aed' }),
        room({ key: 'k-00-34-b', number: 'K-00-34', floor_code: 'v01.i', floor_codes_label: 'v01.i', material_color: '#c2410c' }),
        room({ key: 'k-01-28', number: 'K-01-28', floor_codes_label: 'v09', floor_code: 'v09' }),
        room({ key: 'a-00-26', number: 'A-00-26', floor_codes_label: 'v04', floor_code: 'v04' }),
        room({ key: 'k-00-07', number: 'K-00-07', floor_codes_label: 'v01.a', floor_code: 'v01.a' }),
    ];
    const hits = [
        { number: 'K-00-34', page: 1, x: 0.22, y: 0.36, w: 0.05, h: 0.02, source: 'text' },
        { number: 'K-00-01', page: 1, x: 0.12, y: 0.34, w: 0.05, h: 0.02, source: 'text' },
        { number: 'K-00-02', page: 1, x: 0.18, y: 0.38, w: 0.05, h: 0.02, source: 'text' },
        { number: 'K-00-08', page: 1, x: 0.28, y: 0.40, w: 0.05, h: 0.02, source: 'text' },
        { number: 'K-00-10', page: 1, x: 0.24, y: 0.48, w: 0.05, h: 0.02, source: 'text' },
        { number: 'K-00-12', page: 1, x: 0.32, y: 0.42, w: 0.05, h: 0.02, source: 'text' },
        { number: 'K-01-28', page: 1, x: 0.20, y: 0.86, w: 0.05, h: 0.02, source: 'text' },
        { number: 'K-00-07', page: 1, x: 0.16, y: 0.87, w: 0.05, h: 0.02, source: 'text' },
        { number: 'A-00-26', page: 1, x: 0.40, y: 0.44, w: 0.05, h: 0.02, source: 'text' },
    ];

    assert.equal(drawingStoreyPrefix(label), 'k-00');
    assert.deepEqual(roomsOnDrawing(rooms, 336, label).map((item) => item.number).sort(), ['K-00-07', 'K-00-34', 'K-00-34']);
    placeBoardRooms(rooms, 336, hits, label);
    const plan = overlayPlan(rooms, 336, 1, { roomLabels: true, materialCodes: true, drawingLabel: label });
    const merged = mergePrintRoomChips(roomsOnDrawing(rooms, 336, label));

    assert.equal(plan.some((item) => item.number === 'K-01-28'), false);
    assert.equal(plan.some((item) => item.number === 'A-00-26'), false);
    assert.equal(plan.some((item) => item.number === 'K-00-07'), false);
    assert.equal(plan.filter((item) => item.number === 'K-00-34').length, 1);
    assert.equal(plan.find((item) => item.number === 'K-00-34').text, 'v01.c + v01.i');
    assert.ok(plan.find((item) => item.number === 'K-00-34').box.y < 0.50);
    assert.equal(merged.filter((item) => item.number === 'K-00-34').length, 1);
});

test('print keeps short codes on the right side of a full-width floor', () => {
    const left = room({
        key: 'k-00-01',
        number: 'K-00-01',
        floor_codes_label: 'v01.b',
        floor_code: 'v01.b',
        contour: null,
        marker: { page: 1, x: 0.18, y: 0.36, width: 0.05, height: 0.02, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.18, y: 0.36, w: 0.05, h: 0.02 }, geometry: 'label' },
    });
    const right = room({
        key: 'k-00-20',
        number: 'K-00-20',
        floor_codes_label: 'v04',
        floor_code: 'v04',
        contour: null,
        marker: { page: 1, x: 0.78, y: 0.40, width: 0.05, height: 0.02, source: 'text' },
        jump_target: { page: 1, bbox: { x: 0.78, y: 0.40, w: 0.05, h: 0.02 }, geometry: 'label' },
    });

    const plan = overlayPlan([left, right], 336, 1, { roomLabels: false, materialCodes: true });
    const rightChip = plan.find((item) => item.number === 'K-00-20');

    assert.equal(plan.find((item) => item.number === 'K-00-01').text, 'v01.b');
    assert.equal(rightChip.text, 'v04');
    assert.ok(rightChip.box.x > 0.70);
    assert.ok(clampPrintLabelCenter(rightChip.box).x > 0.70);
});

test('print keeps the website room box when the pdf has another copy of the number', () => {
    const office = room({
        key: 'k-01-15',
        number: 'K-01-15',
        floor_codes_label: 'v06.b',
        floor_code: 'v06.b',
        contour: null,
        marker: { page: 1, x: 0.58, y: 0.42, width: 0.10, height: 0.08, source: 'contour' },
        jump_target: { page: 1, bbox: { x: 0.58, y: 0.42, w: 0.10, h: 0.08 }, geometry: 'contour' },
    });
    const hits = [
        { number: 'K-01-15', page: 1, x: 0.12, y: 0.88, w: 0.05, h: 0.02, source: 'text' },
        { number: 'K-01-15', page: 1, x: 0.58, y: 0.42, w: 0.05, h: 0.02, source: 'text' },
    ];

    placeBoardRooms([office], 336, hits);
    const box = roomLabelAnchor(office);

    assert.equal(Number(box.x.toFixed(2)), 0.58);
    assert.equal(Number(box.y.toFixed(2)), 0.42);
    assert.equal(overlayPlan([office], 336, 1, { roomLabels: false, materialCodes: true })[0].text, 'v06.b');
});
