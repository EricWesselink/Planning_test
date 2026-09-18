import {
    assessTextLayer,
    clamp,
    contourBox,
    exactRoomHitForArea,
    normalizeRoomNumber,
    roomVisualContour,
    storedJumpTarget,
} from './room-geometry.js';
import {
    materialFillBox,
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

export async function hydrateRoomMarkers(pdfDoc, rooms, drawingId) {
    if (!pdfDoc) {
        return;
    }
    const { extractPageTextItems } = await import('./pdf-text-layer.js');
    const known = rooms
        .filter((room) => Number(room.drawing_id) === Number(drawingId))
        .map((room) => room.number)
        .filter(Boolean);
    const hits = [];
    const finishItems = [];
    for (let number = 1; number <= pdfDoc.numPages; number += 1) {
        const pdfPage = await pdfDoc.getPage(number);
        const viewport = pdfPage.getViewport({ scale: 1 });
        const content = await pdfPage.getTextContent();
        const items = extractPageTextItems(content, viewport, number);
        hits.push(...assessTextLayer(items, known).hits);
        finishItems.push(...items);
    }
    const usableHits = usableLabelHits(hits);
    rooms
        .filter((room) => Number(room.drawing_id) === Number(drawingId))
        .forEach((room) => {
            if (applyRoomGeometry(room)) {
                return;
            }
            const hit = exactRoomHitForArea({ number: room.number, number_raw: room.number }, usableHits)
                || usableHits.find((item) => normalizeRoomNumber(item.number) === normalizeRoomNumber(room.number));
            assignHit(room, hit);
        });
    assignFinishHits(rooms, drawingId, finishItems);
}

export function usableLabelHits(hits) {
    const items = hits || [];

    return items.filter((hit) => !isTextLegendCluster(items, hit));
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

export function roomContourFor(room) {
    const trace = roomTraceContour(room);
    if (trace) {
        return trace;
    }
    const visual = roomVisualContour(room);
    if (visual) {
        return { ...visual, room };
    }
    const jump = storedJumpTarget(room);
    if (!jump?.box) {
        return null;
    }

    return { type: 'box', box: jump.box, room };
}

export function roomLabelAnchor(room) {
    return contourBox(roomContourFor(room)) || storedJumpTarget(room)?.box || null;
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
    return rooms.filter((room) => {
        if (Number(room.drawing_id) !== Number(drawingId)) {
            return false;
        }
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
        box: roomLabelAnchor(room),
        text: overlayChipText(room, options),
        colored: Boolean(options.colored),
        source: reliableTraceRects(room.contour).length > 0
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
    return typeof src === 'string'
        && src.startsWith('data:image/')
        && src.includes('base64,')
        && src.length > 64;
}

export function printImageFromCanvas(canvas) {
    const width = Math.max(0, Math.floor(Number(canvas?.width) || 0));
    const height = Math.max(0, Math.floor(Number(canvas?.height) || 0));
    if (width < 2 || height < 2) {
        throw new Error('empty drawing canvas');
    }
    let src = '';
    try {
        src = canvas.toDataURL('image/jpeg', 0.82);
    } catch {
        src = '';
    }
    if (!isUsablePrintImageSrc(src) && typeof canvas.toDataURL === 'function') {
        try {
            src = canvas.toDataURL('image/png');
        } catch {
            src = '';
        }
    }
    if (!isUsablePrintImageSrc(src)) {
        throw new Error('empty drawing snapshot');
    }

    return { src, width, height };
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
        await image.decode();
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
    const materialSet = new Set(materialKeys);
    overlayRoomsOnPage(rooms, drawingId, page).forEach((room) => {
        const state = roomDrawingState(room, {
            selectedKey,
            materialKeys,
            filter,
            search,
        });
        if (!state.show) {
            return;
        }
        const contour = roomContourFor(room);
        const box = roomLabelAnchor(room);
        if (!contour || !box) {
            return;
        }
        const finishes = roomFinishes(room);
        finishes.forEach((finish) => {
            const finishOn = materialSet.size === 0 || materialSet.has(String(finish.material_key || '').toLowerCase());
            if (!finishOn && !state.selected) {
                return;
            }
            const finishState = {
                ...state,
                highlighted: finishOn,
                filteredOut: !finishOn && !state.selected,
            };
            const isLocal = finish.role === 'local' && finishes.length > 1;
            let finishContour = contour;
            if (isLocal && Array.isArray(finish.marker?.polygon) && finish.marker.polygon.length >= 3) {
                finishContour = { type: 'polygon', points: finish.marker.polygon, room };
            } else if (isLocal) {
                const localTrace = roomTraceContour({ contour: finish.overlay });
                if (localTrace) {
                    finishContour = localTrace;
                } else if (finish.overlay && Number(finish.overlay.w) > 0 && Number(finish.overlay.h) > 0) {
                    finishContour = { type: 'box', box: finish.overlay, room };
                } else {
                    return;
                }
            }
            if (colored) {
                appendFill(hitEl, { ...room, material_color: finish.material_color || room.material_color }, finishContour, finishState, onRoomPointer);
            }
            if (isLocal) {
                const localBox = contourBox(finishContour);
                if (localBox) {
                    appendCodeChip(markersEl, {
                        ...room,
                        floor_code: finish.code,
                        floor_codes_label: finish.code,
                        material_color: finish.material_color || room.material_color,
                    }, localBox, { ...finishState, highlighted: true }, {
                        roomLabels,
                        materialCodes,
                        chipTag,
                        chipTransform,
                        onRoomPointer,
                        showChips,
                    });
                }
            }
        });
        if (finishes.length <= 1 || materialSet.size === 0 || materialSet.has(String(room.material_key || '').toLowerCase())) {
            appendCodeChip(markersEl, room, box, state, {
                roomLabels,
                materialCodes,
                chipTag,
                chipTransform,
                onRoomPointer,
                showChips,
            });
        }
    });
}

function assignHit(room, hit) {
    if (!hit || applyRoomGeometry(room)) {
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

function assignFinishHits(rooms, drawingId, items) {
    const placed = rooms.filter((room) => Number(room.drawing_id) === Number(drawingId) && room.marker);
    placed.forEach((room) => {
        (room.floors || []).forEach((finish) => {
            const code = String(finish.code || '').toLowerCase();
            if (code === '') {
                return;
            }
            let best = null;
            let bestDistance = Infinity;
            items.forEach((item) => {
                if (String(item.text || '').toLowerCase().trim() !== code) {
                    return;
                }
                if (Number(item.page) !== Number(room.marker.page)) {
                    return;
                }
                const distance = Math.hypot(Number(item.x) - Number(room.marker.x), Number(item.y) - Number(room.marker.y));
                const closerOther = placed.some((other) => {
                    if (other === room || Number(other.marker?.page) !== Number(item.page)) {
                        return false;
                    }
                    return Math.hypot(Number(item.x) - Number(other.marker.x), Number(item.y) - Number(other.marker.y)) + 0.01 < distance;
                });
                if (closerOther || distance > 0.22) {
                    return;
                }
                if (distance < bestDistance) {
                    bestDistance = distance;
                    best = item;
                }
            });
            if (!best) {
                return;
            }
            const width = Math.max(0.035, Number(best.w) || 0.04);
            const height = Math.max(0.03, Number(best.h) || 0.03);
            const x = clamp(Number(best.x));
            const y = clamp(Number(best.y));
            finish.marker = {
                page: Number(best.page),
                x,
                y,
                width,
                height,
                polygon: [
                    { x, y },
                    { x: clamp(x + width), y },
                    { x: clamp(x + width), y: clamp(y + height) },
                    { x, y: clamp(y + height) },
                ],
            };
        });
    });
}

function appendFill(hitEl, room, contour, state, onRoomPointer) {
    const fillContour = state.highlighted || state.selected
        ? (contour.type === 'box' ? { type: 'box', box: materialFillBox(contour.box) || contour.box } : contour)
        : contour;
    fillShapes(fillContour).forEach((shape) => {
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
