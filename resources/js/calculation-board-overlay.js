import {
    assessTextLayer,
    clamp,
    contourBox,
    exactRoomHitForArea,
    normalizeRoomNumber,
    roomFocusBox,
    roomVisualContour,
    storedJumpTarget,
    unionBoxes,
} from './room-geometry.js';
import {
    overlayContrast,
    roomDrawingState,
    roomOverlayContent,
} from './calculation-board-selection.js';

export function roomFinishes(room) {
    if (Array.isArray(room?.floors) && room.floors.length > 0) {
        return room.floors;
    }
    if (room?.floor_code) {
        return [{
            code: room.floor_code,
            product: room.floor_product,
            role: 'main',
            material_key: room.material_key,
            material_color: room.material_color,
            quantity_label: room.m2_label,
        }];
    }

    return [];
}

export function roomsForDrawing(rooms, drawingId) {
    const id = Number(drawingId);
    if (! Number.isFinite(id)) {
        return [];
    }

    return (rooms || []).filter((room) => Number(room.drawing_id) === id);
}

export async function hydrateRoomMarkers(pdfDoc, rooms, drawingId, drawingLabel = '') {
    if (!pdfDoc) {
        return;
    }
    const drawingRooms = roomsOnDrawing(rooms, drawingId, drawingLabel);
    const { extractPageTextItems } = await import('./pdf-text-layer.js');
    const known = drawingRooms.map((room) => room.number).filter(Boolean);
    const hits = [];
    const allItems = [];
    for (let number = 1; number <= pdfDoc.numPages; number += 1) {
        const pdfPage = await pdfDoc.getPage(number);
        const viewport = pdfPage.getViewport({ scale: 1 });
        const content = await pdfPage.getTextContent();
        const items = extractPageTextItems(content, viewport, number);
        allItems.push(...items);
        hits.push(...assessTextLayer(items, known).hits);
    }
    placeBoardRooms(drawingRooms, drawingId, hits, drawingLabel);
    attachRoomCaptions(drawingRooms, allItems);
}

export function drawingStoreyPrefix(label) {
    const match = String(label || '').match(/_([A-Za-z])_(\d{2})(?:_|$|\.|-|\s)/);

    return match ? `${match[1].toLowerCase()}-${match[2]}` : null;
}

export function roomMatchesStorey(room, prefix) {
    if (!prefix) {
        return true;
    }

    return normalizeRoomNumber(room?.number).startsWith(`${prefix}-`);
}

export function roomsOnDrawing(rooms, drawingId, drawingLabel = '') {
    return roomsForDrawing(rooms, drawingId).filter((room) => (
        roomMatchesStorey(room, drawingStoreyPrefix(drawingLabel))
    ));
}

export function placeBoardRooms(rooms, drawingId, hits, drawingLabel = '') {
    const usable = usableLabelHits(hits);
    roomsOnDrawing(rooms, drawingId, drawingLabel).forEach((room) => {
        if (isDrawingAreaBox(boardChipBox(room))) {
            return;
        }
        assignHit(room, pickRoomLabelHit(room, usable));
    });
}

export function usableLabelHits(hits) {
    const items = hits || [];
    const plan = items.filter((hit) => (
        !isTextLegendCluster(items, hit)
        && !isTitleBlockHit(items, hit)
        && !isFooterLegendHit(items, hit)
    ));

    return keepHitsInBuilding(plan);
}

export function isTextLegendCluster(hits, hit) {
    const y = Number(hit?.y) || 0;
    if (y >= 0.14) {
        return false;
    }
    const pageHits = (hits || []).filter((item) => Number(item.page) === Number(hit.page));
    if (pageHits.length < 8) {
        return false;
    }
    const neighbors = pageHits.filter((item) => Math.abs(Number(item.y) - y) <= 0.04).length;
    const tiny = Number(hit.h) < 0.035 && Number(hit.w) < 0.12;

    return tiny && neighbors >= 8;
}

export function isTitleBlockHit(hits, hit) {
    const x = Number(hit?.x) || 0;
    if (x < 0.62) {
        return false;
    }
    const pageHits = (hits || []).filter((item) => Number(item.page) === Number(hit.page));
    const column = pageHits.filter((item) => (
        Number(item.x) >= 0.62
        && Math.abs(Number(item.x) - x) <= 0.10
    ));

    return column.length >= 4;
}

export function isFooterLegendHit(hits, hit) {
    const y = Number(hit?.y) || 0;
    if (y < 0.80) {
        return false;
    }
    const tiny = Number(hit.h) < 0.035 && Number(hit.w) < 0.14;
    if (!tiny) {
        return false;
    }
    const pageHits = (hits || []).filter((item) => Number(item.page) === Number(hit.page) && Number(item.y) >= 0.80);

    return y >= 0.84 || pageHits.length >= 3;
}

export function keepHitsInBuilding(hits) {
    const items = hits || [];
    if (items.length < 6) {
        return items;
    }
    const ys = items.map((hit) => Number(hit.y) || 0).sort((left, right) => left - right);
    const xs = items.map((hit) => Number(hit.x) || 0).sort((left, right) => left - right);
    const yMin = ys[Math.floor(ys.length * 0.1)];
    const yMax = ys[Math.min(ys.length - 1, Math.max(0, Math.ceil(ys.length * 0.9) - 1))];
    const xMin = xs[Math.floor(xs.length * 0.1)];
    const xMax = xs[Math.min(xs.length - 1, Math.max(0, Math.ceil(xs.length * 0.9) - 1))];

    return items.filter((hit) => {
        const x = Number(hit.x) || 0;
        const y = Number(hit.y) || 0;

        return x >= xMin - 0.05 && x <= xMax + 0.05 && y >= yMin - 0.05 && y <= yMax + 0.05;
    });
}

export function isPlanLabelHit(hits, hit) {
    if (!hit) {
        return false;
    }
    if (isTextLegendCluster(hits, hit) || isTitleBlockHit(hits, hit) || isFooterLegendHit(hits, hit)) {
        return false;
    }

    return isDrawingAreaBox({
        x: Number(hit.x) || 0,
        y: Number(hit.y) || 0,
        w: Number(hit.w) || 0,
        h: Number(hit.h) || 0,
    });
}

export function pickRoomLabelHit(room, hits) {
    const wanted = normalizeRoomNumber(room?.number);
    if (!wanted) {
        return null;
    }
    const matches = (hits || []).filter((hit) => (
        normalizeRoomNumber(hit.number) === wanted
        || normalizeRoomNumber(hit.number) === normalizeRoomNumber(room.number_raw)
    )).filter((hit) => isPlanLabelHit(hits, hit));
    if (matches.length === 0) {
        return null;
    }
    const exact = exactRoomHitForArea({ number: room.number, number_raw: room.number }, matches);
    if (exact) {
        return exact;
    }
    const planHits = (hits || []).filter((item) => isPlanLabelHit(hits, item));
    const xs = planHits.map((item) => Number(item.x) || 0).sort((left, right) => left - right);
    const ys = planHits.map((item) => Number(item.y) || 0).sort((left, right) => left - right);
    const midX = xs[Math.floor(xs.length / 2)] ?? 0.4;
    const midY = ys[Math.floor(ys.length / 2)] ?? 0.4;
    matches.sort((left, right) => {
        const leftDist = Math.hypot((Number(left.x) || 0) - midX, (Number(left.y) || 0) - midY);
        const rightDist = Math.hypot((Number(right.x) || 0) - midX, (Number(right.y) || 0) - midY);
        if (leftDist !== rightDist) {
            return leftDist - rightDist;
        }

        return (Number(right.score) || 0) - (Number(left.score) || 0);
    });

    return matches[0];
}

export function reliableTraceRects(source) {
    if (!source || source.reliable === false) {
        return [];
    }

    return (source.rects || []).filter((rect) => Number(rect?.w) > 0.001 && Number(rect?.h) > 0.001);
}

export function roomTraceContour(room) {
    const rects = reliableTraceRects(room?.contour);
    if (rects.length === 0) {
        return null;
    }
    if (rects.length === 1) {
        return { type: 'box', box: rects[0], room };
    }

    return { type: 'rects', rects, room };
}

export function applyRoomGeometry(room) {
    const contour = roomTraceContour(room);
    const box = contourBox(contour);
    if (!contour || !box) {
        return false;
    }
    const page = Math.max(1, Number(room.contour?.page) || Number(room.marker?.page) || 1);
    room.marker = {
        page,
        x: box.x,
        y: box.y,
        width: box.w,
        height: box.h,
        source: 'contour',
    };
    room.has_position = true;
    room.jump_target = {
        page,
        bbox: { x: box.x, y: box.y, w: box.w, h: box.h },
        geometry: 'contour',
    };

    return true;
}

export function isPlausibleRoomBox(box) {
    if (!box) {
        return false;
    }
    const width = Number(box.w) || 0;
    const height = Number(box.h) || 0;
    if (width < 0.02 || height < 0.015) {
        return false;
    }
    const area = width * height;
    const ratio = width >= height ? width / height : height / width;
    if (area > 0.10 || ratio > 3.5) {
        return false;
    }

    return true;
}

export function isCompactLabelBox(box) {
    if (!box) {
        return false;
    }

    return (Number(box.w) || 0) <= 0.18 && (Number(box.h) || 0) <= 0.07;
}

export function printFillContour(room) {
    const points = Array.isArray(room?.contour?.polygon) ? room.contour.polygon : null;
    if (room?.contour?.reliable !== true || !points || points.length < 3) {
        return null;
    }
    const box = contourBox({ type: 'polygon', points });
    if (!isPlausibleRoomBox(box) || isCompactLabelBox(box)) {
        return null;
    }

    return { type: 'polygon', points, room };
}

export function printLabelContourBox(room) {
    const trace = roomTraceContour(room);
    if (!trace) {
        return null;
    }
    if (trace.type === 'rects' && (trace.rects || []).length > 3) {
        return null;
    }
    const box = contourBox(trace);
    if (!isPlausibleRoomBox(box) || !isDrawingAreaBox(box)) {
        return null;
    }

    return box;
}

export function isDrawingAreaBox(box) {
    if (!box) {
        return false;
    }
    const x = (Number(box.x) || 0) + (Number(box.w) || 0) / 2;
    const y = (Number(box.y) || 0) + (Number(box.h) || 0) / 2;

    return x >= 0.02 && x <= 0.98 && y >= 0.05 && y <= 0.94;
}

export function boardChipBox(room) {
    const visual = roomVisualContour(room);
    const box = contourBox(visual) || storedJumpTarget(room)?.box;
    if (!box || !(Number(box.w) > 0.001) || !(Number(box.h) > 0.001)) {
        return null;
    }

    return box;
}

export function roomLabelAnchor(room) {
    const board = boardChipBox(room);
    if (board && isDrawingAreaBox(board)) {
        return board;
    }
    const fillBox = contourBox(printFillContour(room));
    if (fillBox && isDrawingAreaBox(fillBox)) {
        return fillBox;
    }
    const traceBox = printLabelContourBox(room);
    if (traceBox) {
        return traceBox;
    }
    const marker = roomFocusBox(room.marker);

    return marker && isDrawingAreaBox(marker) ? marker : null;
}

export function roomFloorBox(room) {
    const polygon = Array.isArray(room?.contour?.polygon) && room.contour.polygon.length >= 3
        ? room.contour.polygon
        : (Array.isArray(room?.marker?.polygon) && room.marker.polygon.length >= 3 ? room.marker.polygon : null);
    if (polygon) {
        const box = contourBox({ type: 'polygon', points: polygon });
        if (isRoomFloorBox(box)) {
            return box;
        }
    }
    const trace = roomTraceContour(room);
    if (trace && !(trace.type === 'rects' && (trace.rects || []).length > 3)) {
        const box = contourBox(trace);
        if (isRoomFloorBox(box)) {
            return box;
        }
    }

    return null;
}

export function roomInteriorBox(room) {
    return roomFloorBox(room);
}

export function tightGlyphBox(item) {
    if (!item) {
        return null;
    }
    const h = Math.max(0.005, Number(item.th) || Number(item.h) || 0.008);
    const text = String(item.text || item.label_text || item.number || '');
    const letters = Math.max(1, text.replace(/\s+/g, '').length);
    const fromChars = h * letters * 0.33;
    const raw = Number(item.tw) > 0.001 ? Number(item.tw) : Number(item.w) || 0;
    const w = Math.min(raw > 0.001 ? raw : fromChars, fromChars, 0.02);

    return {
        x: Number(item.x) || 0,
        y: Number(item.y) || 0,
        w: Math.max(0.004, w),
        h,
        page: Number(item.page) || undefined,
        text: String(item.text || item.label_text || item.number || ''),
    };
}

function isAreaLabelText(text) {
    return /m\s*[²2]/i.test(text) || /^\d+[.,]\d+\s*m/.test(text);
}

function isCaptionNameText(text, number) {
    const value = String(text || '').trim().toUpperCase().replace(/\s+/g, ' ');
    if (value === '' || value.length > 28) {
        return false;
    }
    if (number && normalizeRoomNumber(value) === number) {
        return false;
    }
    if (isAreaLabelText(value) || /^[\d.,+\-\s/]+$/.test(value)) {
        return false;
    }

    return /[A-Za-zÀ-ÿ]{2,}/.test(value);
}

function findCaptionNameItem(room, near, number, originY) {
    const nameKey = String(room?.name || '').trim().toUpperCase().replace(/\s+/g, ' ');
    const inColumn = near.filter((item) => isCaptionNameText(item.text, number));
    if (nameKey !== '') {
        const matched = inColumn.find((item) => {
            const text = String(item.text || '').trim().toUpperCase().replace(/\s+/g, ' ');

            return text === nameKey
                || nameKey.startsWith(text)
                || text.startsWith(nameKey.split(' ')[0]);
        });
        if (matched) {
            return matched;
        }
    }
    const above = inColumn
        .filter((item) => Number(item.y) <= originY + 0.002)
        .sort((left, right) => Math.abs(Number(left.y) - originY) - Math.abs(Number(right.y) - originY));

    return above[0] || null;
}

export function roomCaptionCluster(room, items = []) {
    const page = Number(room?.marker?.page || room?.jump_target?.page || room?.contour?.page || 1);
    const number = normalizeRoomNumber(room?.number);
    const name = String(room?.name || '').trim();
    const list = (items || []).filter((item) => Number(item.page || page) === page);
    const numberItem = list.find((item) => normalizeRoomNumber(item.text || item.number) === number)
        || (room?.marker && (room.marker.source === 'text' || String(room.jump_target?.geometry || '') === 'label')
            ? { ...room.marker, text: room.marker.label_text || room.number, page }
            : null);
    if (!numberItem && name === '') {
        return null;
    }
    const origin = numberItem || list[0];
    const ox = Number(origin?.x) || 0;
    const oy = Number(origin?.y) || 0;
    const near = list.filter((item) => (
        Math.abs(Number(item.x) - ox) < 0.022
        && Number(item.y) > oy - 0.035
        && Number(item.y) < oy + 0.04
    ));
    const nameItem = findCaptionNameItem(room, near, number, oy);
    const m2Item = near.find((item) => isAreaLabelText(String(item.text || '')));
    const parts = [nameItem, numberItem, m2Item].map(tightGlyphBox).filter(Boolean);
    if (parts.length === 0) {
        return null;
    }
    const box = unionBoxes(parts) || parts[0];

    return {
        ...box,
        page,
        name: nameItem ? tightGlyphBox(nameItem) : null,
        number: numberItem ? tightGlyphBox(numberItem) : null,
        m2: m2Item ? tightGlyphBox(m2Item) : null,
    };
}

export function attachRoomCaptions(rooms, items) {
    const list = rooms || [];
    list.forEach((room) => {
        room.caption = roomCaptionCluster(room, items);
    });
    list.forEach((room) => {
        if (room.caption) {
            room.caption_bounds = captionRoomBounds(room, list, room.caption, items);
        }
    });
}

export function chipAnchorInRoom(room, options = {}) {
    const text = options.text ?? overlayChipText(room, options);
    const chip = chipHalfSizeForText(text);
    const floor = options.interior && isRoomFloorBox(options.interior)
        ? options.interior
        : roomFloorBox(room);
    const items = options.items || room.caption_items || [];
    const caption = options.caption || room.caption || roomCaptionCluster(room, items);
    const neighbors = options.rooms || [];
    if (floor) {
        const inset = insetInterior(floor, chip);
        const bounds = inset.degenerate ? floor : inset;
        const manual = manualChipPoint(room);
        if (manual && pointInBox(manual, bounds)) {
            return pointBox(manual);
        }
        if (caption) {
            return pointBox(clampPointToBox(
                captionChipPoint(caption, chip, bounds),
                bounds,
            ));
        }
        const preferred = preferredChipPoint(floor, inset);

        return pointBox(inset.degenerate ? boxCenter(floor) : preferred);
    }
    if (caption) {
        const bounds = options.bounds
            || room.caption_bounds
            || captionRoomBounds(room, neighbors, caption, items);
        const manual = manualChipPoint(room);
        if (manual && pointInBox(manual, bounds)) {
            return pointBox(manual);
        }

        return pointBox(captionChipPoint(caption, chip, bounds));
    }
    const fallback = tightGlyphBox(room.marker || storedJumpTarget(room)?.box);
    if (!fallback) {
        return null;
    }
    const point = captionChipPoint({ ...fallback, number: fallback }, chip, {
        x: fallback.x - 0.004,
        y: fallback.y - 0.004,
        w: fallback.w + 0.02,
        h: fallback.h + 0.016,
    });
    const index = Math.max(0, Number(options.index) || 0);
    if (index === 0) {
        return pointBox(point);
    }

    return pointBox({ x: point.x, y: point.y + (index * Math.max(chip.h * 2.4, 0.007)) });
}

function pointBox(point) {
    return { x: Number(point?.x) || 0, y: Number(point?.y) || 0, w: 0, h: 0 };
}

function manualChipPoint(room) {
    const source = String(room?.marker?.source || '');
    if (source === '' || source === 'text' || source === 'ocr' || source === 'contour') {
        return null;
    }
    const box = boardChipBox(room);

    return box ? boxCenter(box) : null;
}

function captionOwnTexts(room, caption) {
    const number = normalizeRoomNumber(room?.number);
    const name = String(room?.name || '').trim().toUpperCase();
    const texts = new Set([number, name].filter(Boolean));
    ['name', 'number', 'm2'].forEach((key) => {
        const part = caption?.[key];
        if (part) {
            texts.add(String(part.text || '').trim().toUpperCase());
        }
    });

    return texts;
}

function isOwnCaptionItem(room, caption, item) {
    const text = String(item?.text || item?.number || '').trim().toUpperCase();
    if (text === '') {
        return false;
    }
    const own = captionOwnTexts(room, caption);
    if (own.has(text) || own.has(normalizeRoomNumber(text))) {
        const dx = Math.abs((Number(item.x) || 0) - caption.x);
        const dy = Math.abs((Number(item.y) || 0) - caption.y);

        return dx < 0.03 && dy < 0.04;
    }

    return false;
}

function rangesOverlap(start, end, otherStart, otherEnd) {
    return start < otherEnd && otherStart < end;
}

function shrinkCaptionBounds(left, right, top, bottom, caption, box, margin) {
    const ox = Number(box?.x) || 0;
    const oy = Number(box?.y) || 0;
    const ow = Math.max(0.004, Number(box?.w) || 0);
    const oh = Math.max(0.004, Number(box?.h) || 0);
    const captionRight = caption.x + caption.w;
    const captionBottom = caption.y + caption.h;
    if (ox >= caption.x + Math.min(0.006, caption.w * 0.4)
        && rangesOverlap(oy, oy + oh, caption.y - 0.012, captionBottom + 0.012)) {
        right = Math.min(right, ox - margin);
    }
    if ((ox + ow) <= caption.x + 0.004
        && rangesOverlap(oy, oy + oh, caption.y - 0.012, captionBottom + 0.012)) {
        left = Math.max(left, ox + ow + margin);
    }
    if (oy >= caption.y + Math.min(0.006, caption.h * 0.4)
        && rangesOverlap(ox, ox + ow, caption.x - 0.012, captionRight + 0.012)) {
        bottom = Math.min(bottom, oy - margin);
    }
    if ((oy + oh) <= caption.y + 0.004
        && rangesOverlap(ox, ox + ow, caption.x - 0.012, captionRight + 0.012)) {
        top = Math.max(top, oy + oh + margin);
    }

    return { left, right, top, bottom };
}

function captionRoomBounds(room, rooms, caption, items = []) {
    const page = Number(room?.marker?.page || caption.page || 1);
    const nameBox = caption.name || caption.number || caption;
    let left = caption.x - 0.006;
    let right = nameBox.x + nameBox.w + 0.014;
    let top = caption.y - 0.006;
    let bottom = caption.y + caption.h + 0.008;
    const apply = (box) => {
        const next = shrinkCaptionBounds(left, right, top, bottom, caption, box, 0.003);
        left = next.left;
        right = next.right;
        top = next.top;
        bottom = next.bottom;
    };
    (rooms || []).forEach((other) => {
        if (other === room || String(other?.key) === String(room?.key)) {
            return;
        }
        const otherBox = other.caption || tightGlyphBox(other.marker);
        if (!otherBox) {
            return;
        }
        const otherPage = Number(other.marker?.page || other.caption?.page || page);
        if (otherPage !== page) {
            return;
        }
        apply(otherBox);
    });
    (items || []).forEach((item) => {
        if (Number(item.page || page) !== page || isOwnCaptionItem(room, caption, item)) {
            return;
        }
        const dx = (Number(item.x) || 0) - caption.x;
        const dy = (Number(item.y) || 0) - caption.y;
        if (Math.hypot(dx, dy) > 0.08) {
            return;
        }
        apply(tightGlyphBox(item) || item);
    });
    right = Math.max(right, caption.x + 0.01);
    bottom = Math.max(bottom, caption.y + 0.01);

    return {
        x: left,
        y: top,
        w: Math.max(0.01, right - left),
        h: Math.max(0.01, bottom - top),
    };
}

function captionChipPoint(caption, chip, bounds) {
    const anchor = caption.name || caption.number || caption;
    const gap = 0.002;
    const candidates = [
        { x: anchor.x + anchor.w + gap + chip.w, y: anchor.y + (anchor.h / 2) },
        { x: anchor.x - gap - chip.w, y: anchor.y + (anchor.h / 2) },
        { x: anchor.x + (Math.min(anchor.w, 0.012) / 2), y: anchor.y + anchor.h + gap + chip.h },
        { x: anchor.x + (Math.min(anchor.w, 0.012) / 2), y: anchor.y - gap - chip.h },
    ];
    const fits = (point) => (
        (point.x - chip.w) >= bounds.x
        && (point.x + chip.w) <= (bounds.x + bounds.w)
        && (point.y - chip.h) >= bounds.y
        && (point.y + chip.h) <= (bounds.y + bounds.h)
    );
    const found = candidates.find(fits);
    if (found) {
        return found;
    }
    const inset = {
        x: bounds.x + chip.w,
        y: bounds.y + chip.h,
        w: Math.max(0, bounds.w - (chip.w * 2)),
        h: Math.max(0, bounds.h - (chip.h * 2)),
    };

    return inset.w > 0 && inset.h > 0 ? clampPointToBox(candidates[0], inset) : boxCenter(bounds);
}

function chipHalfSizeForText(text) {
    const letters = Math.max(3, String(text || 'v00').length);

    return {
        w: Math.min(0.0075, 0.003 + (letters * 0.00085)),
        h: 0.0045,
    };
}

function compactTextLabelBox(room, box) {
    if (!box) {
        return null;
    }
    const marker = room?.marker;
    const isLabel = marker?.source === 'text' || String(room?.jump_target?.geometry || '') === 'label';
    if (!isLabel) {
        return box;
    }
    const rawW = Number(marker?.tw);
    const rawH = Number(marker?.th);
    const width = rawW > 0.002 ? rawW : Math.min(Number(box.w) || 0.01, 0.01);
    const height = rawH > 0.002 ? rawH : Math.min(Number(box.h) || 0.008, 0.008);

    return {
        x: Number(box.x) || 0,
        y: Number(box.y) || 0,
        w: width,
        h: height,
    };
}

function isUsableInterior(box) {
    return Boolean(box && Number(box.w) > 0.006 && Number(box.h) > 0.006);
}

function isRoomFloorBox(box) {
    if (!isUsableInterior(box)) {
        return false;
    }
    const width = Number(box.w) || 0;
    const height = Number(box.h) || 0;
    const ratio = width >= height ? width / height : height / width;
    if (ratio > 3.5 || (width * height) > 0.10) {
        return false;
    }

    return true;
}

function boxCenter(box) {
    return {
        x: (Number(box?.x) || 0) + ((Number(box?.w) || 0) / 2),
        y: (Number(box?.y) || 0) + ((Number(box?.h) || 0) / 2),
    };
}

function pointInBox(point, box) {
    const x = Number(point?.x) || 0;
    const y = Number(point?.y) || 0;

    return x >= Number(box.x)
        && x <= Number(box.x) + Number(box.w)
        && y >= Number(box.y)
        && y <= Number(box.y) + Number(box.h);
}

function clampPointToBox(point, box) {
    return {
        x: Math.min(Number(box.x) + Number(box.w), Math.max(Number(box.x), Number(point?.x) || 0)),
        y: Math.min(Number(box.y) + Number(box.h), Math.max(Number(box.y), Number(point?.y) || 0)),
    };
}

function chipHalfSize(interior, text) {
    const letters = Math.max(3, String(text || 'v00').length);
    const width = Math.min(Number(interior.w) * 0.42, 0.004 + (letters * 0.0015));
    const height = Math.min(Number(interior.h) * 0.36, 0.007);

    return {
        w: Math.max(0.003, width),
        h: Math.max(0.003, height),
    };
}

function insetInterior(interior, chip) {
    const wall = Math.min(Number(interior.w) * 0.12, Number(interior.h) * 0.12, 0.008);
    const padX = Math.min((Number(interior.w) / 2) - 0.0004, chip.w + wall);
    const padY = Math.min((Number(interior.h) / 2) - 0.0004, chip.h + wall);
    const w = Number(interior.w) - (padX * 2);
    const h = Number(interior.h) - (padY * 2);
    if (w <= 0.001 || h <= 0.001) {
        return { x: 0, y: 0, w: 0, h: 0, degenerate: true };
    }

    return {
        x: Number(interior.x) + padX,
        y: Number(interior.y) + padY,
        w,
        h,
    };
}

function preferredChipPoint(interior, inset) {
    return {
        x: inset.x + (inset.w * 0.58),
        y: inset.y + (inset.h * 0.38),
    };
}

export function clampPrintLabelCenter(box) {
    const width = Number(box?.w) || 0;
    const height = Number(box?.h) || 0;
    const x = clampRange((Number(box?.x) || 0) + width / 2, 0.03, 0.97);
    const y = clampRange((Number(box?.y) || 0) + height / 2, 0.05, 0.93);

    return { x, y };
}

export function printLegendGoesBelow(materials) {
    return (materials || []).length > 18;
}

export function separatePrintLabelCenters(centers) {
    return (centers || []).map((center) => ({
        x: Number(center?.x) || 0,
        y: Number(center?.y) || 0,
    }));
}

function clampRange(value, min, max) {
    return Math.max(min, Math.min(max, Number(value) || 0));
}

export function overlayChipText(room, options = {}) {
    const content = roomOverlayContent(room);
    const code = content.code || printedMaterialCodes(room).join(' + ');
    if (options.materialCodes !== false && code) {
        return code;
    }
    if (options.roomLabels && content.number) {
        return content.number;
    }

    return '';
}

export function overlayRoomsOnPage(rooms, drawingId, page, drawingLabel = '') {
    return mergePrintRoomChips(roomsOnDrawing(rooms, drawingId, drawingLabel)).filter((room) => {
        const roomPage = Number(room.contour?.page || room.marker?.page || room.jump_target?.page);
        if (roomPage !== Number(page)) {
            return false;
        }

        return Boolean(roomLabelAnchor(room));
    });
}

export function overlayPlan(rooms, drawingId, page, options = {}) {
    return overlayRoomsOnPage(rooms, drawingId, page, options.drawingLabel).map((room) => ({
        key: room.key,
        number: room.number,
        drawing_id: Number(room.drawing_id),
        box: roomLabelAnchor(room),
        chip: chipAnchorInRoom(room, { ...options, rooms, items: options.items }),
        text: overlayChipText(room, options),
        colored: Boolean(options.colored),
        fill: options.colored ? printFillContour(room) : null,
        source: isPlausibleRoomBox(contourBox(roomTraceContour(room)))
            ? 'contour'
            : (room.marker?.source || room.jump_target?.geometry || 'label'),
    }));
}

export function printDrawingSheets(drawings) {
    return (drawings || []).flatMap((drawing) => {
        const pageCount = Math.max(1, Number(drawing.pageCount) || 1);

        return Array.from({ length: pageCount }, (_, index) => ({
            drawingId: Number(drawing.id),
            label: drawing.label,
            page: index + 1,
            pageCount,
        }));
    });
}

export function printedMaterialCodes(room) {
    const codes = [];
    const seen = new Set();
    const add = (value) => {
        const code = String(value || '').trim();
        if (code === '') {
            return;
        }
        const key = code.toLowerCase();
        if (seen.has(key)) {
            return;
        }
        seen.add(key);
        codes.push(code);
    };
    String(room?.floor_codes_label || '').split('+').forEach((part) => add(part));
    roomFinishes(room).forEach((finish) => add(finish.code));
    add(room?.floor_code);
    (room?.material_keys || []).forEach((key) => add(key));

    return codes;
}

export function mergePrintRoomChips(rooms) {
    const groups = new Map();
    (rooms || []).forEach((room) => {
        const key = normalizeRoomNumber(room.number) || `id:${room.key}`;
        const current = groups.get(key) || [];
        current.push(room);
        groups.set(key, current);
    });

    return [...groups.values()].map((group) => {
        if (group.length === 1) {
            return group[0];
        }
        const codes = [];
        const seen = new Set();
        const addCode = (value) => {
            const code = String(value || '').trim();
            const id = code.toLowerCase();
            if (code === '' || seen.has(id)) {
                return;
            }
            seen.add(id);
            codes.push(code);
        };
        const floors = [];
        group.forEach((item) => {
            printedMaterialCodes(item).forEach((code) => addCode(code));
            roomFinishes(item).forEach((finish) => {
                const id = String(finish.code || '').trim().toLowerCase();
                if (id === '' || floors.some((current) => String(current.code || '').toLowerCase() === id)) {
                    return;
                }
                floors.push(finish);
            });
        });
        const positioned = group.find((item) => roomLabelAnchor(item)) || group[0];

        return {
            ...positioned,
            floors,
            floor_code: codes[0] || positioned.floor_code,
            floor_codes_label: codes.join(' + '),
        };
    });
}

export function materialCodesInOverlayText(text) {
    const value = String(text || '').trim();
    if (value === '') {
        return [];
    }
    const codePart = value.includes('·')
        ? value.slice(value.indexOf('·') + 1)
        : value;

    return codePart.split('+').map((part) => part.trim()).filter(Boolean);
}

export function legendFromRooms(rooms) {
    const groups = {};
    (rooms || []).forEach((room) => {
        const finishes = roomFinishes(room);
        printedMaterialCodes(room).forEach((code) => {
            const key = code.toLowerCase();
            const finish = finishes.find((item) => String(item.code || '').trim().toLowerCase() === key) || {};
            if (! groups[key]) {
                groups[key] = {
                    key,
                    code,
                    product: String(finish.product || '').trim(),
                    color: finish.material_color || room.material_color || '#e7e5e4',
                    m2: 0,
                };
            }
            const quantity = Number(finish.quantity);
            if (Number.isFinite(quantity)) {
                groups[key].m2 += quantity;
            }
            if (groups[key].product === '' && finish.product) {
                groups[key].product = String(finish.product).trim();
            }
            if (! groups[key].color && (finish.material_color || room.material_color)) {
                groups[key].color = finish.material_color || room.material_color;
            }
        });
    });

    return Object.keys(groups).sort().map((key) => {
        const group = groups[key];
        const m2 = Math.round(group.m2 * 1000) / 1000;

        return {
            ...group,
            m2,
            m2_label: `${m2.toFixed(2).replace('.', ',')} m²`,
            label: group.product !== '' ? `${group.code} – ${group.product}` : group.code,
        };
    });
}

export function printPaper(cssViewport) {
    const width = Math.max(1, Number(cssViewport?.width) || 1);
    const height = Math.max(1, Number(cssViewport?.height) || 1);
    const landscape = width >= height;

    return {
        landscape,
        size: landscape ? 'A3 landscape' : 'A3 portrait',
        ratio: width / height,
    };
}

export function isUsablePrintImageSrc(src) {
    return typeof src === 'string' && (
        src.startsWith('blob:')
        || (src.startsWith('data:image/') && src.includes('base64,') && src.length > 64)
    );
}

export async function printImageFromCanvas(canvas) {
    const source = cpuCanvasCopy(canvas);
    const width = Math.max(0, Math.floor(Number(source?.width) || 0));
    const height = Math.max(0, Math.floor(Number(source?.height) || 0));
    if (width < 2 || height < 2) {
        throw new Error('empty drawing canvas');
    }
    const blobSrc = await blobSrcFromCanvas(source);
    if (isUsablePrintImageSrc(blobSrc)) {
        return { src: blobSrc, width, height };
    }
    let src = '';
    try {
        src = source.toDataURL('image/jpeg', 0.85);
    } catch {
        src = '';
    }
    if (!isUsablePrintImageSrc(src) && typeof source.toDataURL === 'function') {
        try {
            src = source.toDataURL('image/png');
        } catch {
            src = '';
        }
    }
    if (!isUsablePrintImageSrc(src)) {
        throw new Error('empty drawing snapshot');
    }

    return { src, width, height };
}

function cpuCanvasCopy(canvas) {
    if (typeof document === 'undefined' || typeof document.createElement !== 'function') {
        return canvas;
    }
    const copy = document.createElement('canvas');
    copy.width = Math.max(1, Math.floor(Number(canvas?.width) || 0));
    copy.height = Math.max(1, Math.floor(Number(canvas?.height) || 0));
    if (copy.width < 2 || copy.height < 2) {
        return canvas;
    }
    const context = copy.getContext('2d', { alpha: false, willReadFrequently: true });
    if (!context || typeof context.drawImage !== 'function') {
        return canvas;
    }
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, copy.width, copy.height);
    context.drawImage(canvas, 0, 0);

    return copy;
}

async function blobSrcFromCanvas(canvas) {
    if (typeof canvas?.toBlob !== 'function') {
        return '';
    }
    const blob = await new Promise((resolve) => {
        try {
            canvas.toBlob(resolve, 'image/jpeg', 0.85);
        } catch {
            resolve(null);
        }
    });
    if (!blob || Number(blob.size) < 64) {
        return '';
    }
    if (typeof FileReader === 'function') {
        const dataUrl = await new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result || ''));
            reader.onerror = () => resolve('');
            reader.readAsDataURL(blob);
        });
        if (isUsablePrintImageSrc(dataUrl)) {
            return dataUrl;
        }
    }
    if (typeof Blob !== 'undefined' && blob instanceof Blob && typeof URL !== 'undefined' && typeof URL.createObjectURL === 'function') {
        return URL.createObjectURL(blob);
    }

    return '';
}

export function printDrawingHasSize(image) {
    return Number(image?.naturalWidth) >= 2 && Number(image?.naturalHeight) >= 2;
}

export async function waitForPrintImage(image) {
    if (!image) {
        throw new Error('missing drawing image');
    }
    const src = typeof image.getAttribute === 'function'
        ? image.getAttribute('src')
        : image.src;
    if (!src) {
        throw new Error('empty drawing image');
    }
    if (typeof image.decode === 'function') {
        try {
            await image.decode();
        } catch {
            throw new Error('empty drawing image');
        }
    } else if (!image.complete) {
        await new Promise((resolve, reject) => {
            image.addEventListener('load', () => resolve(image), { once: true });
            image.addEventListener('error', () => reject(new Error('drawing image failed')), { once: true });
        });
    }
    if (!printDrawingHasSize(image)) {
        throw new Error('empty drawing image');
    }

    return image;
}

export async function waitForPrintAssets(root, { fonts, requireDrawings = false } = {}) {
    const images = [...(root?.querySelectorAll?.('img.calc-print-canvas') || [])];
    if (requireDrawings && images.length === 0) {
        throw new Error('no print drawings');
    }
    await Promise.all(images.map((image) => waitForPrintImage(image)));
    if (fonts?.ready) {
        await fonts.ready;
    }

    return images;
}

export function paintCalculationOverlays({
    hitEl,
    markersEl,
    rooms,
    drawingId,
    page,
    materialKeys = [],
    selectedKey = null,
    filter = 'all',
    search = '',
    roomLabels = false,
    materialCodes = true,
    colored = false,
    drawingLabel = '',
    chipTag = 'button',
    chipTransform = null,
    onRoomPointer = null,
    showChips = true,
    items = [],
} = {}) {
    if (!hitEl || !markersEl) {
        return;
    }
    hitEl.replaceChildren();
    markersEl.replaceChildren();
    overlayRoomsOnPage(rooms, drawingId, page, drawingLabel).forEach((room) => {
        const state = roomDrawingState(room, {
            selectedKey,
            materialKeys,
            filter,
            search,
        });
        if (!state.show) {
            return;
        }
        const box = roomLabelAnchor(room);
        if (!box || !isDrawingAreaBox(box)) {
            return;
        }
        if (colored) {
            const fillContour = printFillContour(room);
            if (fillContour) {
                appendFill(hitEl, { ...room, material_color: room.material_color }, fillContour, state, onRoomPointer);
            }
        }
        const chipBox = chipAnchorInRoom(room, {
            roomLabels,
            materialCodes,
            text: overlayChipText(room, { roomLabels, materialCodes }),
            rooms,
            items,
        }) || box;
        appendCodeChip(markersEl, room, chipBox, state, {
            roomLabels,
            materialCodes,
            chipTag,
            chipTransform,
            onRoomPointer,
            showChips,
        });
    });
}

function assignHit(room, hit) {
    if (!hit) {
        return;
    }
    const width = Math.max(0.02, Number(hit.w) || Number(hit.width) || 0.04);
    const height = Math.max(0.012, Number(hit.h) || Number(hit.height) || 0.02);
    const x = clamp(Number(hit.x));
    const y = clamp(Number(hit.y));
    room.marker = {
        page: Math.max(1, Number(hit.page) || 1),
        x,
        y,
        width,
        height,
        tw: Number(hit.tw) > 0 ? Number(hit.tw) : (Number(hit.w) || Number(hit.width) || undefined),
        th: Number(hit.th) > 0 ? Number(hit.th) : (Number(hit.h) || Number(hit.height) || undefined),
        label_text: String(hit.label_text || hit.text || room.number || '').slice(0, 160),
        source: hit.source || 'text',
    };
    room.has_position = true;
    room.jump_target = {
        page: room.marker.page,
        bbox: { x, y, w: width, h: height },
        geometry: 'label',
    };
    room.number_raw = room.number;
}

function appendFill(hitEl, room, contour, state, onRoomPointer) {
    fillShapes(contour).forEach((shape) => {
        const classes = ['calc-fill', 'room-label'];
        if (state.selected) {
            classes.push('is-on');
        }
        if (state.filteredOut) {
            classes.push('is-filtered-out');
        }
        if (room.needs_review) {
            classes.push('is-review');
        }
        shape.setAttribute('class', classes.join(' '));
        shape.dataset.roomKey = String(room.key);
        if (state.highlighted || state.selected) {
            shape.style.fill = room.material_color || '#e7e5e4';
            shape.style.fillOpacity = String(state.fillAlpha ?? 0.18);
        } else {
            shape.style.fill = 'transparent';
        }
        shape.style.stroke = 'transparent';
        onRoomPointer?.(shape, room);
        hitEl.append(shape);
    });
}

function fillShapes(contour) {
    if (contour?.type === 'polygon') {
        const shape = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
        shape.setAttribute('points', contour.points.map((point) => `${point.x},${point.y}`).join(' '));

        return [shape];
    }
    const boxes = contour?.type === 'rects' ? (contour.rects || []) : [contour?.box];

    return boxes.filter((box) => box && Number(box.w) > 0 && Number(box.h) > 0).map((box) => {
        const shape = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        shape.setAttribute('x', String(box.x));
        shape.setAttribute('y', String(box.y));
        shape.setAttribute('width', String(box.w));
        shape.setAttribute('height', String(box.h));

        return shape;
    });
}

function appendCodeChip(markersEl, room, box, state, options) {
    if (options.showChips === false) {
        return;
    }
    const text = overlayChipText(room, options);
    if (!text || (!state.highlighted && !state.selected)) {
        return;
    }
    const contrast = overlayContrast(room.material_color);
    const chip = document.createElement(options.chipTag || 'button');
    if (chip.tagName === 'BUTTON') {
        chip.type = 'button';
    }
    chip.className = 'calc-code-chip';
    if (state.selected) {
        chip.classList.add('is-on');
    }
    chip.textContent = text;
    chip.dataset.roomKey = String(room.key);
    chip.style.left = `${((Number(box.x) || 0) + (Number(box.w) || 0) / 2) * 100}%`;
    chip.style.top = `${((Number(box.y) || 0) + (Number(box.h) || 0) / 2) * 100}%`;
    chip.style.transform = options.chipTransform?.() || 'translate(-50%, -50%)';
    chip.style.background = contrast.bg;
    chip.style.color = contrast.fg;
    chip.title = roomOverlayContent(room).title;
    options.onRoomPointer?.(chip, room);
    markersEl.append(chip);
}
