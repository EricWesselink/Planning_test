import {
    assessTextLayer,
    clamp,
    contourBox,
    exactRoomHitForArea,
    normalizeRoomNumber,
    roomFocusBox,
    storedJumpTarget,
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

export async function hydrateRoomMarkers(pdfDoc, rooms, drawingId) {
    if (!pdfDoc) {
        return;
    }
    const drawingRooms = roomsForDrawing(rooms, drawingId);
    const { extractPageTextItems } = await import('./pdf-text-layer.js');
    const known = drawingRooms.map((room) => room.number).filter(Boolean);
    const hits = [];
    for (let number = 1; number <= pdfDoc.numPages; number += 1) {
        const pdfPage = await pdfDoc.getPage(number);
        const viewport = pdfPage.getViewport({ scale: 1 });
        const content = await pdfPage.getTextContent();
        const items = extractPageTextItems(content, viewport, number);
        hits.push(...assessTextLayer(items, known).hits);
    }
    placeBoardRooms(drawingRooms, drawingId, hits);
}

export function placeBoardRooms(rooms, drawingId, hits) {
    const usable = usableLabelHits(hits);
    roomsForDrawing(rooms, drawingId).forEach((room) => {
        assignHit(room, pickRoomLabelHit(room, usable));
    });
}

export function usableLabelHits(hits) {
    const items = hits || [];

    return items.filter((hit) => !isTextLegendCluster(items, hit) && !isTitleBlockHit(items, hit));
}

export function isTextLegendCluster(hits, hit) {
    const pageHits = (hits || []).filter((item) => Number(item.page) === Number(hit.page));
    if (pageHits.length < 8) {
        return false;
    }
    const neighbors = pageHits.filter((item) => Math.abs(Number(item.y) - Number(hit.y)) <= 0.10).length;
    const tiny = Number(hit.h) < 0.035 && Number(hit.w) < 0.12;

    return tiny && neighbors >= 8;
}

export function isTitleBlockHit(hits, hit) {
    const x = Number(hit?.x) || 0;
    if (x >= 0.70) {
        return true;
    }
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

export function isPlanLabelHit(hits, hit) {
    if (!hit) {
        return false;
    }
    if (isTextLegendCluster(hits, hit) || isTitleBlockHit(hits, hit)) {
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
    matches.sort((left, right) => (Number(right.score) || 0) - (Number(left.score) || 0));

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

export function isDrawingAreaBox(box) {
    if (!box) {
        return false;
    }
    const x = (Number(box.x) || 0) + (Number(box.w) || 0) / 2;
    const y = (Number(box.y) || 0) + (Number(box.h) || 0) / 2;

    return x >= 0.03 && x <= 0.63 && y >= 0.08 && y <= 0.92;
}

export function roomLabelAnchor(room) {
    const marker = roomFocusBox(room.marker);
    const stored = storedJumpTarget(room)?.box;
    if (marker && room.marker?.source !== 'contour' && isCompactLabelBox(marker) && isDrawingAreaBox(marker)) {
        return marker;
    }
    if (stored && isCompactLabelBox(stored) && isDrawingAreaBox(stored) && String(room.jump_target?.geometry || '') === 'label') {
        return stored;
    }
    const fillBox = contourBox(printFillContour(room));
    if (fillBox && isDrawingAreaBox(fillBox)) {
        return fillBox;
    }
    const trace = roomTraceContour(room);
    if (trace?.type === 'box' && isPlausibleRoomBox(trace.box) && isDrawingAreaBox(trace.box)) {
        return trace.box;
    }
    if (stored && isDrawingAreaBox(stored)) {
        return stored;
    }

    return marker && isDrawingAreaBox(marker) ? marker : null;
}

export function clampPrintLabelCenter(box) {
    const width = Number(box?.w) || 0;
    const height = Number(box?.h) || 0;
    const x = clampRange((Number(box?.x) || 0) + width / 2, 0.04, 0.63);
    const y = clampRange((Number(box?.y) || 0) + height / 2, 0.08, 0.92);

    return { x, y };
}

export function printLegendGoesBelow(materials) {
    return (materials || []).length > 18;
}

export function separatePrintLabelCenters(centers) {
    const placed = (centers || []).map((center) => ({
        x: Number(center?.x) || 0,
        y: Number(center?.y) || 0,
    }));
    placed.forEach((center, index) => {
        for (let previous = 0; previous < index; previous += 1) {
            const other = placed[previous];
            if (Math.abs(center.x - other.x) < 0.08 && Math.abs(center.y - other.y) < 0.028) {
                center.y = clampRange(other.y + 0.032, 0.05, 0.93);
            }
        }
    });

    return placed;
}

function clampRange(value, min, max) {
    return Math.max(min, Math.min(max, Number(value) || 0));
}

export function overlayChipText(room, options = {}) {
    const content = roomOverlayContent(room);
    const parts = [];
    if (options.roomLabels && content.number) {
        parts.push(content.number);
    }
    if (options.materialCodes !== false && content.code) {
        parts.push(content.code);
    }

    return parts.join(' · ');
}

export function overlayRoomsOnPage(rooms, drawingId, page) {
    return roomsForDrawing(rooms, drawingId).filter((room) => {
        const roomPage = Number(room.contour?.page || room.marker?.page || room.jump_target?.page);
        if (roomPage !== Number(page)) {
            return false;
        }

        return Boolean(roomLabelAnchor(room));
    });
}

export function overlayPlan(rooms, drawingId, page, options = {}) {
    return overlayRoomsOnPage(rooms, drawingId, page).map((room) => ({
        key: room.key,
        number: room.number,
        drawing_id: Number(room.drawing_id),
        box: roomLabelAnchor(room),
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
    chipTag = 'button',
    chipTransform = null,
    onRoomPointer = null,
    showChips = true,
} = {}) {
    if (!hitEl || !markersEl) {
        return;
    }
    hitEl.replaceChildren();
    markersEl.replaceChildren();
    const placedCenters = [];
    overlayRoomsOnPage(roomsForDrawing(rooms, drawingId), drawingId, page).forEach((room) => {
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
        const center = clampPrintLabelCenter(box);
        placedCenters.forEach((other) => {
            if (Math.abs(center.x - other.x) < 0.08 && Math.abs(center.y - other.y) < 0.028) {
                center.y = Math.max(0.05, Math.min(0.93, other.y + 0.032));
            }
        });
        placedCenters.push(center);
        appendCodeChip(markersEl, room, { x: center.x, y: center.y, w: 0, h: 0 }, state, {
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
        tw: Number(hit.tw) > 0 ? Number(hit.tw) : undefined,
        th: Number(hit.th) > 0 ? Number(hit.th) : undefined,
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
