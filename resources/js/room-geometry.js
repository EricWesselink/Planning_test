export const ROOM_NUMBER_RE = /(?<![0-9a-z])([a-z]-\d{2}-\d{2}|(?:[a-z]\.\s*)?\d+\.\d+[a-z]?)(?![0-9a-z])/giu;

export function clamp(value) {
    return Math.max(0, Math.min(1, Number(value) || 0));
}

export function normalizeRoomNumber(value) {
    return String(value || '')
        .trim()
        .toLowerCase()
        .replaceAll(',', '.')
        .replace(/\s+/g, '');
}

export function coreRoomNumber(value) {
    const number = normalizeRoomNumber(value);
    if (/^[a-z]\.\d+\.\d+[a-z]?$/.test(number)) {
        return number.slice(2);
    }

    return number;
}

export function roomNumbersIn(text) {
    const source = String(text || '').replaceAll(',', '.');
    const pattern = new RegExp(ROOM_NUMBER_RE.source, ROOM_NUMBER_RE.flags);
    const found = [];
    [...source.matchAll(pattern)].forEach((match) => {
        const full = normalizeRoomNumber(match[1]);
        if (!full) {
            return;
        }
        found.push(full);
        const core = coreRoomNumber(full);
        if (core !== full) {
            found.push(core);
        }
    });

    return found;
}

export function pickBestKnownRoomNumber(candidates, knownNumbers) {
    const known = knownNumbers instanceof Set
        ? knownNumbers
        : new Set((knownNumbers || []).map(normalizeRoomNumber).filter(Boolean));
    const matches = [...new Set((candidates || []).map(normalizeRoomNumber).filter(Boolean))]
        .filter((number) => known.has(number));
    if (matches.length === 0) {
        return null;
    }
    matches.sort((left, right) => right.length - left.length || left.localeCompare(right));

    return matches[0];
}

export function dedupeTextItems(items) {
    const seen = new Map();
    (items || []).forEach((item) => {
        const key = [
            item.page || 1,
            String(item.text || '').trim(),
            Number(item.x || 0).toFixed(4),
            Number(item.y || 0).toFixed(4),
            item.source || 'text',
        ].join('|');
        if (!seen.has(key)) {
            seen.set(key, item);
        }
    });

    return [...seen.values()];
}

function labelScore(item, number) {
    const text = String(item.text || '').toLowerCase();
    let score = 0;
    if (text.startsWith(number)) {
        score += 6;
    }
    if (/[a-z]/.test(text.replace(number, ''))) {
        score += 3;
    }
    if (!/m²|m2|cm/.test(text)) {
        score += 2;
    }

    return score;
}

export function lettersOnly(value) {
    return String(value || '').toLowerCase().replace(/[^a-z]/g, '');
}

export function nameMatches(expected, text) {
    const needle = lettersOnly(expected);
    const haystack = lettersOnly(text);

    return needle !== '' && haystack.includes(needle);
}

export function estimatedLabelWidth(text, fallback = 0.08) {
    const chars = String(text || '').trim().length;
    if (chars === 0) {
        return fallback;
    }

    return Math.max(0.05, Math.min(0.28, chars * 0.005));
}

export function labelVisualBox(box, text) {
    const chars = String(text || '').trim().length;
    const clickW = Number(box?.w) || 0.08;
    const clickH = Number(box?.h) || 0.012;
    const sourceW = Number(box?.tw);
    const sourceH = Number(box?.th);
    const visualW = sourceW > 0.008
        ? Math.min(clickW, sourceW)
        : Math.min(clickW, Math.max(0.016, chars * 0.0022));
    const visualH = sourceH > 0.004
        ? Math.min(clickH, sourceH)
        : Math.min(clickH, 0.008);

    return {
        x: Number(box?.x) || 0,
        y: Number(box?.y) || 0,
        w: visualW,
        h: visualH,
    };
}

export function selectionBarBox(box, text) {
    const visual = labelVisualBox(box, text);
    const padX = Math.min(0.006, Math.max(0.002, visual.w * 0.08));
    const padY = Math.min(0.004, Math.max(0.0015, visual.h * 0.28));

    return {
        x: Math.max(0, visual.x - padX),
        y: Math.max(0, visual.y - padY),
        w: visual.w + padX * 2,
        h: visual.h + padY * 2,
    };
}

export const PHASE_MARKS = [
    { key: 'egaliseren', letter: 'E', label: 'Primen & Egaliseren' },
    { key: 'vloer', letter: 'V', label: 'Vloer' },
    { key: 'plinten', letter: 'P', label: 'Plinten' },
];

function letterForColor(color) {
    if (color === 'ondergrond') {
        return 'E';
    }
    if (color === 'plinten') {
        return 'P';
    }

    return 'V';
}

function colorForPhase(key) {
    if (key === 'egaliseren') {
        return 'ondergrond';
    }
    if (key === 'plinten') {
        return 'plinten';
    }

    return 'vloer';
}

export function drawingMarks(area) {
    const dots = (area?.dots || []).filter((dot) => dot?.key);
    if (dots.length) {
        return dots.map((dot) => {
            const color = String(dot.key);

            return {
                kind: 'phase',
                color,
                text: `${letterForColor(color)}✓`,
                title: dot.provisional
                    ? `${dot.label || ''} · voorlopig, wacht op akkoord`.trim()
                    : (dot.label || ''),
                provisional: Boolean(dot.provisional),
            };
        });
    }

    const phases = area?.phases || {};
    const done = PHASE_MARKS.filter((item) => phases[item.key] === true);

    return done.map((item) => {
        const color = colorForPhase(item.key);

        return {
            kind: 'phase',
            color,
            letter: item.letter,
            text: `${item.letter}✓`,
            title: item.label,
        };
    });
}

export function findExactRoomHits(items, knownNumbers) {
    const known = new Set((knownNumbers || []).map(normalizeRoomNumber).filter(Boolean));
    const seen = new Set();
    const hits = [];
    (items || []).forEach((item) => {
        const exact = normalizeRoomNumber(item.text);
        const number = (exact && known.has(exact))
            ? exact
            : pickBestKnownRoomNumber(roomNumbersIn(item.text), known);
        if (!number) {
            return;
        }
        const key = [
            number,
            item.page || 1,
            Number(item.x || 0).toFixed(4),
            Number(item.y || 0).toFixed(4),
        ].join('|');
        if (seen.has(key)) {
            return;
        }
        seen.add(key);
        const text = String(item.text || '');
        const height = Math.max(Number(item.h) || 0.016, 0.016);
        const rawW = Math.max(0, Number(item.w) || 0);
        const rawH = Math.max(0, Number(item.h) || 0);
        const width = Math.max(rawW || 0.02, estimatedLabelWidth(text), 0.05);
        hits.push({
            number,
            page: item.page || 1,
            text,
            x: clamp(item.x),
            y: clamp(item.y),
            w: clamp(width),
            h: clamp(height),
            tw: rawW,
            th: rawH,
            source: item.source || 'text',
            score: labelScore(item, number),
            label_text: text,
            confidence: item.source === 'ocr' ? 0.72 : 1,
        });
    });

    return hits;
}

export function assessTextLayer(items, knownNumbers) {
    const hits = findExactRoomHits(items, knownNumbers);
    const knownCount = new Set((knownNumbers || []).map(normalizeRoomNumber).filter(Boolean)).size;
    const present = (items || []).length > 0;
    const needed = knownCount === 0 ? 3 : Math.max(1, Math.ceil(knownCount * 0.4));
    const usable = knownCount === 0
        ? present && (items || []).length >= 10
        : hits.length >= needed;

    return {
        present,
        usable,
        itemCount: (items || []).length,
        hits,
        numbers: [...new Set(hits.map((hit) => hit.number))],
    };
}

export function viewerRenderScale(pageWidth, pageHeight, preferred = 3, minimum = 2, maxEdge = 8192) {
    const edge = Math.max(Number(pageWidth) || 1, Number(pageHeight) || 1);
    const capped = maxEdge / edge;

    return Math.max(minimum, Math.min(preferred, capped));
}

export function labelBox(marker) {
    if (!marker) {
        return null;
    }
    const text = marker.label_text || marker.text || '';
    const width = Math.max(0.05, Number(marker.width) || Number(marker.w) || 0.08, estimatedLabelWidth(text));
    const height = Math.max(0.018, Number(marker.height) || Number(marker.h) || 0.02);

    return {
        x: clamp(marker.x),
        y: clamp(marker.y),
        w: clamp(width),
        h: clamp(height),
        tw: Number(marker.tw) > 0 ? Number(marker.tw) : undefined,
        th: Number(marker.th) > 0 ? Number(marker.th) : undefined,
    };
}

export function displayBox(area, textHit = null) {
    if (textHit) {
        return labelBox(textHit);
    }

    return labelBox(area?.marker);
}

export function clickBox(area, textHit = null) {
    return displayBox(area, textHit);
}

/**
 * Bounding box for focusing the viewport on a stored room marker/contour.
 * Prefers polygon contour, then marker width/height, never invents a position.
 */
export function roomFocusBox(marker) {
    if (!marker) {
        return null;
    }

    const page = Number(marker.page);
    const poly = Array.isArray(marker.polygon) ? marker.polygon : null;
    if (poly && poly.length >= 3) {
        let minX = Infinity;
        let minY = Infinity;
        let maxX = -Infinity;
        let maxY = -Infinity;
        let points = 0;
        poly.forEach((point) => {
            const x = Number(point?.x);
            const y = Number(point?.y);
            if (!Number.isFinite(x) || !Number.isFinite(y)) {
                return;
            }
            points += 1;
            minX = Math.min(minX, x);
            minY = Math.min(minY, y);
            maxX = Math.max(maxX, x);
            maxY = Math.max(maxY, y);
        });
        if (points >= 3 && maxX > minX && maxY > minY) {
            return {
                x: clamp(minX),
                y: clamp(minY),
                w: clamp(maxX - minX),
                h: clamp(maxY - minY),
                page: Number.isFinite(page) ? page : undefined,
            };
        }
    }

    const x = Number(marker.x);
    const y = Number(marker.y);
    if (!Number.isFinite(x) || !Number.isFinite(y)) {
        return null;
    }

    const width = Number(marker.width);
    const height = Number(marker.height);
    const w = Number.isFinite(width) && width > 0 ? width : Math.max(0.06, estimatedLabelWidth(marker.label_text || marker.text || ''));
    const h = Number.isFinite(height) && height > 0 ? height : 0.03;

    return {
        x: clamp(x),
        y: clamp(y),
        w: clamp(w),
        h: clamp(h),
        page: Number.isFinite(page) ? page : undefined,
    };
}

export function hasReliableRoomPosition(marker) {
    if (!marker) {
        return false;
    }
    const page = Number(marker.page);
    if (!Number.isFinite(page) || page < 1) {
        return false;
    }
    const box = roomFocusBox(marker);
    return Boolean(box && box.w > 0.001 && box.h > 0.001);
}

function jumpBoxFromStored(box) {
    if (!box) {
        return null;
    }
    const x = Number(box.x ?? box.min_x);
    const y = Number(box.y ?? box.min_y);
    const w = Number(box.w ?? box.width);
    const h = Number(box.h ?? box.height);
    if (![x, y, w, h].every(Number.isFinite) || w <= 0.001 || h <= 0.001) {
        return null;
    }

    return {
        x: clamp(x),
        y: clamp(y),
        w: clamp(w),
        h: clamp(h),
    };
}

/**
 * Jump target for list→drawing navigation.
 * Uses ONLY the stored marker / jump_target on this project_area — never name search.
 *
 * @returns {{ page: number, box: {x:number,y:number,w:number,h:number}, geometry: string, drawing_marker_id?: number|null }|null}
 */
export function storedJumpTarget(area) {
    const stored = area?.jump_target;
    const storedPage = Number(stored?.page);
    const storedBox = jumpBoxFromStored(stored?.bbox);
    if (Number.isFinite(storedPage) && storedPage >= 1 && storedBox) {
        return {
            page: storedPage,
            box: storedBox,
            geometry: stored.drawing_marker_id
                ? `marker:${stored.drawing_marker_id}`
                : (stored.geometry || 'stored'),
            drawing_marker_id: stored.drawing_marker_id ?? null,
            source: area?.marker?.source || null,
        };
    }

    const marker = area?.marker;
    if (!hasReliableRoomPosition(marker)) {
        return null;
    }
    const box = roomFocusBox(marker);
    if (!box) {
        return null;
    }
    const page = Number(marker.page);
    const geometry = Array.isArray(marker.polygon) && marker.polygon.length >= 3
        ? `polygon:${marker.polygon.length}`
        : (marker.width != null && marker.height != null
            ? `bbox:${Number(marker.x).toFixed(4)},${Number(marker.y).toFixed(4)},${Number(marker.width).toFixed(4)}x${Number(marker.height).toFixed(4)}`
            : `point:${Number(marker.x).toFixed(4)},${Number(marker.y).toFixed(4)}`);

    return {
        page,
        box: {
            x: box.x,
            y: box.y,
            w: box.w,
            h: box.h,
        },
        geometry,
        drawing_marker_id: marker.id ?? null,
        source: marker.source || null,
    };
}

export function exactRoomHitForArea(area, hits) {
    const wanted = [
        normalizeRoomNumber(area?.number),
        normalizeRoomNumber(area?.number_raw),
    ].filter(Boolean);
    const cores = [...new Set(wanted.map(coreRoomNumber).filter(Boolean))];
    const matches = (hits || []).filter((hit) => {
        const number = normalizeRoomNumber(hit.number);

        return wanted.includes(number) || cores.includes(coreRoomNumber(number));
    });
    if (matches.length === 0) {
        return null;
    }
    const preferred = matches.filter((hit) => wanted.includes(normalizeRoomNumber(hit.number)));
    const pool = preferred.length ? preferred : matches;
    if (pool.length === 1) {
        return pool[0];
    }

    return null;
}

/**
 * Fit a room box into the stage with margin; soft-cap zoom for tiny/large rooms.
 */
export function focusViewport(box, stageWidth, stageHeight, worldWidth, worldHeight, options = {}) {
    const margin = options.margin ?? 0.22;
    const minScale = options.minScale ?? 0.55;
    const maxScale = options.maxScale ?? 3.2;
    const sw = Math.max(1, Number(stageWidth) || 1);
    const sh = Math.max(1, Number(stageHeight) || 1);
    const ww = Math.max(1, Number(worldWidth) || 1);
    const wh = Math.max(1, Number(worldHeight) || 1);

    const padX = Math.max(box.w * margin, 0.012);
    const padY = Math.max(box.h * margin, 0.012);
    const focusW = Math.min(1, Math.max(0.02, box.w + padX * 2));
    const focusH = Math.min(1, Math.max(0.02, box.h + padY * 2));
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;

    let scale = Math.min(sw / (focusW * ww), sh / (focusH * wh));
    const area = Math.max(0.0001, box.w * box.h);
    const softMax = area < 0.0015 ? maxScale : area < 0.008 ? 2.4 : area < 0.03 ? 1.75 : 1.35;
    scale = Math.max(minScale, Math.min(softMax, scale));

    const width = ww * scale;
    const height = wh * scale;

    return {
        scale,
        panX: sw / 2 - cx * width,
        panY: sh / 2 - cy * height,
    };
}

export function pointInLabel(point, box) {
    return Boolean(point && box
        && point.x >= box.x
        && point.x <= box.x + box.w
        && point.y >= box.y
        && point.y <= box.y + box.h);
}

export function hitTestLabels(point, rooms) {
    const hits = (rooms || [])
        .filter((room) => room.box && pointInLabel(point, room.box))
        .sort((a, b) => (a.box.w * a.box.h) - (b.box.w * b.box.h));

    return hits[0] || null;
}

export function roomContour(area) {
    const points = polygonPointsFrom(area);
    if (points) {
        return { type: 'polygon', points };
    }
    const box = storedJumpTarget(area)?.box || displayBox(area);
    if (!box) {
        return null;
    }

    return { type: 'box', box };
}

function polygonPointsFrom(area) {
    const poly = Array.isArray(area?.marker?.polygon) ? area.marker.polygon : null;
    if (!poly || poly.length < 3) {
        return null;
    }
    const points = [];
    poly.forEach((point) => {
        const x = Number(point?.x);
        const y = Number(point?.y);
        if (!Number.isFinite(x) || !Number.isFinite(y)) {
            return;
        }
        points.push({ x, y });
    });

    return points.length >= 3 ? points : null;
}

export function roomVisualContour(area) {
    const points = polygonPointsFrom(area);
    if (points) {
        return { type: 'polygon', points };
    }
    const box = roomFocusBox(area?.marker) || storedJumpTarget(area)?.box;
    if (!box || !(Number(box.w) > 0.001) || !(Number(box.h) > 0.001)) {
        return null;
    }

    return { type: 'box', box };
}

export function contourBox(contour) {
    if (!contour) {
        return null;
    }
    if (contour.type === 'box') {
        return contour.box || null;
    }
    const points = contour.points || [];
    if (points.length < 3) {
        return null;
    }
    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;
    points.forEach((point) => {
        minX = Math.min(minX, Number(point.x));
        minY = Math.min(minY, Number(point.y));
        maxX = Math.max(maxX, Number(point.x));
        maxY = Math.max(maxY, Number(point.y));
    });
    if (!(maxX > minX) || !(maxY > minY)) {
        return null;
    }

    return { x: minX, y: minY, w: maxX - minX, h: maxY - minY };
}

export function contourArea(contour) {
    if (!contour) {
        return 0;
    }
    if (contour.type === 'box') {
        return (Number(contour.box?.w) || 0) * (Number(contour.box?.h) || 0);
    }
    const points = contour.points || [];
    if (points.length < 3) {
        return 0;
    }
    let sum = 0;
    for (let index = 0; index < points.length; index += 1) {
        const current = points[index];
        const next = points[(index + 1) % points.length];
        sum += (current.x * next.y) - (next.x * current.y);
    }

    return Math.abs(sum) / 2;
}

export function pointInPolygon(point, points) {
    if (!point || !Array.isArray(points) || points.length < 3) {
        return false;
    }
    const x = Number(point.x);
    const y = Number(point.y);
    if (!Number.isFinite(x) || !Number.isFinite(y)) {
        return false;
    }
    let inside = false;
    for (let index = 0, previous = points.length - 1; index < points.length; previous = index, index += 1) {
        const current = points[index];
        const last = points[previous];
        const xi = Number(current.x);
        const yi = Number(current.y);
        const xj = Number(last.x);
        const yj = Number(last.y);
        if (!Number.isFinite(xi) || !Number.isFinite(yi) || !Number.isFinite(xj) || !Number.isFinite(yj)) {
            continue;
        }
        const intersect = ((yi > y) !== (yj > y))
            && (x < (((xj - xi) * (y - yi)) / ((yj - yi) || Number.EPSILON)) + xi);
        if (intersect) {
            inside = !inside;
        }
    }

    return inside;
}

export function pointInContour(point, contour) {
    if (!contour) {
        return false;
    }
    if (contour.type === 'polygon') {
        return pointInPolygon(point, contour.points);
    }

    return pointInLabel(point, contour.box);
}

export function hitTestContours(point, rooms) {
    const hits = (rooms || [])
        .filter((room) => pointInContour(point, room.contour))
        .sort((left, right) => contourArea(left.contour) - contourArea(right.contour));

    return hits[0] || null;
}

export function uniqueAreasOnPage(areas, page) {
    // Elke project_area met eigen marker blijft zichtbaar — nooit dedupe op ruimtenaam.
    // Alleen exact dezelfde page+punt+id-groep: bij echt gedeelde coords blijft elk uniek area-id.
    const onPage = (areas || []).filter((area) => area?.marker && Number(area.marker.page) === Number(page));
    const seenIds = new Set();
    const result = [];
    onPage.forEach((area) => {
        const id = Number(area.id);
        if (!Number.isFinite(id) || seenIds.has(id)) {
            return;
        }
        seenIds.add(id);
        result.push(area);
    });

    return result;
}

export function layerShowsRooms(layer) {
    return layer !== 'snags';
}

export function layerShowsSnags(layer) {
    return layer !== 'rooms';
}
