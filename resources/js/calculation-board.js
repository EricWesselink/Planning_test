import * as pdfjsLib from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import {
    assessTextLayer,
    clamp,
    contourBox,
    focusViewport,
    hitTestContours,
    hitTestLabels,
    normalizeRoomNumber,
    roomVisualContour,
    storedJumpTarget,
    viewerRenderScale,
} from './room-geometry';
import {
    VIEW_RENDER_SCALE,
    extractPageTextItems,
} from './pdf-text-layer';
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
} from './calculation-board-selection';
import { chipAnchorInRoom, attachRoomCaptions, applyStoredChips, finishChipViews, finishInterior, unplacedRoomDraft, pickExclusiveRoomHit } from './calculation-board-overlay';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const root = document.getElementById('calculation-board');
if (root) {
    boot();
}

function boot() {
    const data = JSON.parse(document.getElementById('board-data').textContent);
    const rooms = data.rooms || [];
    const drawings = data.drawings || [];
    const csrf = data.csrf;
    let materials = data.materials || [];
    const legend = data.legend || [];
    let selectedKey = data.selected_key || null;
    let filter = 'all';
    let search = '';
    let materialKeys = [];
    let drawingId = Number(drawings[0]?.id || 0);
    let page = 1;
    let pageCount = 1;
    let pdfDoc = null;
    let pdfRenderTask = null;
    let pdfRenderGeneration = 0;
    let scale = 1;
    let panX = 0;
    let panY = 0;
    let tool = 'hand';
    let dragging = false;
    let dragMoved = false;
    let dragStart = null;
    let lastActivate = { key: '', at: 0 };
    let lastEmptyClick = { at: 0, x: 0, y: 0 };
    let pageItems = [];
    let creatingPoint = null;
    let selectToken = 0;
    const MIN_ZOOM = 0.4;
    const MAX_ZOOM = 4;

    const stage = document.getElementById('draw-stage');
    const world = document.getElementById('draw-world');
    const canvas = document.getElementById('draw-canvas');
    const image = document.getElementById('draw-image');
    const hitEl = document.getElementById('draw-hit');
    const markersEl = document.getElementById('draw-markers');
    const tipEl = document.getElementById('draw-tip');
    const hintEl = document.getElementById('draw-hint');
    const drawingSelect = document.getElementById('draw-drawing');
    const pageSelect = document.getElementById('draw-page');
    const workSelect = document.getElementById('draw-work');
    const workFilterLabel = document.getElementById('draw-work-label');
    const workFilterToggle = document.getElementById('draw-work-toggle');
    const workFilterPanel = document.getElementById('draw-work-panel');
    const workFilterAllBox = workFilterPanel?.querySelector('[data-work-all]');
    const form = document.getElementById('calc-room-form');
    if (workFilterPanel && workFilterPanel.parentElement !== document.body) {
        document.body.appendChild(workFilterPanel);
    }

    function roomByKey(key) {
        return rooms.find((room) => String(room.key) === String(key)) || null;
    }

    function drawingById(id) {
        return drawings.find((item) => Number(item.id) === Number(id)) || null;
    }

    function route(name, id) {
        const template = data.routes?.[name] || '';
        if (!id) {
            return template;
        }
        return template.replace('__DRAWING__', String(id)).replace('__LINE__', String(id));
    }

    function applyTransform() {
        world.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
        const zoomLabel = document.getElementById('draw-zoom-label');
        if (zoomLabel) {
            zoomLabel.textContent = `${Math.round(scale * 100)}%`;
        }
        markersEl.querySelectorAll('.calc-code-chip').forEach((chip) => {
            chip.style.transform = codeChipTransform();
        });
    }

    function codeChipTransform() {
        return `translate(-50%, -50%) scale(${1 / Math.max(scale, 0.01)})`;
    }

    function setHint(text) {
        if (!hintEl) {
            return;
        }
        hintEl.textContent = text || '';
        hintEl.classList.toggle('hidden', !text);
    }

    function worldSize() {
        return {
            width: world.offsetWidth || Number.parseFloat(world.style.width) || 1,
            height: world.offsetHeight || Number.parseFloat(world.style.height) || 1,
        };
    }

    function visibleRooms() {
        return rooms.filter((room) => (
            roomMatchesFilter(room, filter)
            && roomMatchesSearch(room, search)
            && roomMatchesMaterials(room, materialKeys)
        ));
    }

    function applyRoomFilters() {
        const visible = new Set(visibleRooms().map((room) => String(room.key)));
        root.querySelectorAll('.calc-room-row').forEach((row) => {
            row.hidden = !visible.has(String(row.dataset.roomKey));
        });
        const count = document.getElementById('room-count-label');
        if (count) {
            count.textContent = `${visible.size} ruimtes`;
        }
        renderOverlays();
    }

    function highlightList() {
        root.querySelectorAll('.calc-room-row').forEach((row) => {
            row.classList.toggle('is-on', String(row.dataset.roomKey) === String(selectedKey));
        });
        const on = root.querySelector(`.calc-room-row[data-room-key="${CSS.escape(String(selectedKey || ''))}"]`);
        on?.scrollIntoView({ block: 'nearest' });
    }

    function bindRoomRow(row) {
        row.addEventListener('click', () => {
            const room = roomByKey(row.dataset.roomKey);
            if (!room) {
                return;
            }
            if (consumeDoubleActivate(room)) {
                openMaterialDialog(room);
                return;
            }
            selectRoom(room.key);
        });
        row.addEventListener('dblclick', (event) => {
            event.preventDefault();
            const room = roomByKey(row.dataset.roomKey);
            if (room) {
                openMaterialDialog(room);
            }
        });
    }

    function appendRoomRow(room) {
        const list = root.querySelector('.board-left .overflow-auto');
        if (!list || !room?.key) {
            return;
        }
        const row = document.createElement('button');
        row.type = 'button';
        row.className = `room-row calc-room-row ${reviewKindClass(room)}`;
        row.dataset.roomKey = String(room.key);
        row.dataset.drawingId = String(room.drawing_id || '');
        row.dataset.review = room.needs_review ? '1' : '0';
        row.dataset.reviewKind = room.review_kind || reviewKindOf(room);
        row.dataset.floor = room.has_floor ? '1' : '0';
        row.dataset.plinth = room.has_plinth ? '1' : '0';
        row.dataset.material = room.material_key || '';
        row.dataset.search = room.search || '';
        row.style.setProperty('--material-color', room.material_color || '');
        row.style.setProperty('--material-color-soft', room.material_color_soft || '');
        row.innerHTML = `<span class="room-num"></span><span class="room-name"></span><span class="room-m2"></span><span class="room-code"></span>`;
        row.querySelector('.room-num').textContent = room.number || '—';
        row.querySelector('.room-name').textContent = room.name || '—';
        row.querySelector('.room-m2').textContent = room.m2_label || '—';
        row.querySelector('.room-code').textContent = room.floor_codes_label || room.floor_code || '—';
        bindRoomRow(row);
        list.append(row);
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
            page: Math.max(1, Number(hit.page) || page),
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

    async function extractLabels() {
        if (!pdfDoc) {
            return;
        }
        applyStoredChips(rooms);
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
        pageItems = finishItems;
        const drawingRooms = rooms.filter((room) => Number(room.drawing_id) === Number(drawingId));
        const claimed = new Set();
        [...drawingRooms].sort((left, right) => {
            const leftName = String(left.name || '').trim();
            const rightName = String(right.name || '').trim();
            if (leftName !== '' && rightName === '') {
                return -1;
            }
            if (leftName === '' && rightName !== '') {
                return 1;
            }

            return 0;
        }).forEach((room) => {
            if (room.marker?.source === 'user') {
                return;
            }
            assignHit(room, pickExclusiveRoomHit(room, hits, finishItems, claimed, drawingRooms));
        });
        attachRoomCaptions(
            rooms.filter((room) => Number(room.drawing_id) === Number(drawingId)),
            finishItems,
        );
        applyStoredChips(rooms);
        assignFinishHits(finishItems);
    }

    function assignFinishHits(items) {
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

    function roomFinishes(room) {
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

    function overlayRooms() {
        return rooms.filter((room) => (
            Number(room.drawing_id) === Number(drawingId)
            && Number(room.marker?.page || room.jump_target?.page) === Number(page)
            && storedJumpTarget(room)
        ));
    }

    function roomContourFor(room) {
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

    function clickContourFor(room) {
        const contour = roomContourFor(room);
        if (!contour) {
            return null;
        }
        if (contour.type === 'polygon') {
            return contour;
        }
        const padded = materialFillBox(contour.box);
        if (!padded) {
            return contour;
        }

        return { type: 'box', box: padded, room };
    }

    function consumeDoubleActivate(room) {
        const now = Date.now();
        const doubled = isDoubleActivation(lastActivate, room?.key, now);
        lastActivate = { key: String(room?.key || ''), at: now };
        if (doubled) {
            lastActivate = { key: '', at: 0 };
        }

        return doubled;
    }

    function bindOverlayPointer(el, room) {
        el.addEventListener('pointerdown', (event) => {
            event.stopPropagation();
        });
        el.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            hideTip();
            if (consumeDoubleActivate(room)) {
                openMaterialDialog(room);
                return;
            }
            selectRoom(room.key, { keepView: true });
        });
        el.addEventListener('dblclick', (event) => {
            event.preventDefault();
            event.stopPropagation();
            hideTip();
            openMaterialDialog(room);
        });
        el.addEventListener('mouseenter', (event) => showTip(room, event));
        el.addEventListener('mousemove', (event) => showTip(room, event));
        el.addEventListener('mouseleave', hideTip);
    }

    function appendFill(room, contour, state) {
        const fillContour = state.highlighted || state.selected
            ? (contour.type === 'box' ? { type: 'box', box: materialFillBox(contour.box) || contour.box } : contour)
            : contour;
        let shape;
        if (fillContour.type === 'polygon') {
            shape = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
            shape.setAttribute('points', fillContour.points.map((point) => `${point.x},${point.y}`).join(' '));
        } else {
            shape = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            shape.setAttribute('x', String(fillContour.box.x));
            shape.setAttribute('y', String(fillContour.box.y));
            shape.setAttribute('width', String(fillContour.box.w));
            shape.setAttribute('height', String(fillContour.box.h));
        }
        const classes = ['calc-fill', 'room-label'];
        if (state.selected) {
            classes.push('is-on');
        }
        if (state.filteredOut) {
            classes.push('is-filtered-out');
        }
        classes.push(reviewKindClass(room));
        shape.setAttribute('class', classes.join(' '));
        shape.dataset.roomKey = String(room.key);
        shape.style.fill = 'transparent';
        shape.style.stroke = 'transparent';
        bindOverlayPointer(shape, room);
        hitEl.append(shape);
    }

    function appendCodeChip(room, box, state) {
        const content = roomOverlayContent(room);
        if (!state.highlighted && !state.selected) {
            return;
        }
        const hasCode = Boolean(content.code);
        const contrast = overlayContrast(room.material_color);
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = `calc-code-chip ${reviewKindClass(room)}`;
        if (state.selected) {
            chip.classList.add('is-on');
        }
        if (!hasCode) {
            chip.classList.add('is-empty');
        }
        chip.textContent = content.code || '+';
        chip.dataset.roomKey = String(room.key);
        chip.style.left = `${((Number(box.x) || 0) + (Number(box.w) || 0) / 2) * 100}%`;
        chip.style.top = `${((Number(box.y) || 0) + (Number(box.h) || 0) / 2) * 100}%`;
        chip.style.transform = codeChipTransform();
        chip.style.background = hasCode ? contrast.bg : '#fff';
        chip.style.color = hasCode ? contrast.fg : '#1c1917';
        chip.title = `${content.title || 'Ruimte'} · Dubbelklik om materiaal te kiezen`;
        bindChipPointer(chip, room);
        markersEl.append(chip);
    }

    function bindChipPointer(chip, room) {
        let draggingChip = false;
        let moved = false;
        let chipOrigin = null;
        chip.addEventListener('pointerdown', (event) => {
            event.stopPropagation();
            if (event.button !== 0 || !data.can_update) {
                return;
            }
            draggingChip = true;
            moved = false;
            chipOrigin = { x: event.clientX, y: event.clientY };
            chip.setPointerCapture(event.pointerId);
        });
        chip.addEventListener('pointermove', (event) => {
            if (!draggingChip) {
                return;
            }
            if (!moved && chipOrigin && Math.hypot(event.clientX - chipOrigin.x, event.clientY - chipOrigin.y) < 4) {
                return;
            }
            moved = true;
            const point = toNorm(event);
            chip.style.left = `${point.x * 100}%`;
            chip.style.top = `${point.y * 100}%`;
        });
        chip.addEventListener('pointerup', (event) => {
            if (!draggingChip) {
                return;
            }
            draggingChip = false;
            event.stopPropagation();
            if (!moved) {
                hideTip();
                const current = roomByKey(room.key) || room;
                if (consumeDoubleActivate(current)) {
                    openMaterialDialog(current, room.finish_id);
                    return;
                }
                selectRoom(room.key, { keepView: true });
                return;
            }
            event.preventDefault();
            const point = toNorm(event);
            saveRoom(room, {
                chip: {
                    x: point.x,
                    y: point.y,
                    page: Math.max(1, Number(room.marker?.page || page)),
                    finish_id: room.finish_id || undefined,
                },
            }, 'Positie opgeslagen.');
        });
        chip.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
        });
        chip.addEventListener('dblclick', (event) => {
            event.preventDefault();
            event.stopPropagation();
            hideTip();
            openMaterialDialog(roomByKey(room.key) || room, room.finish_id);
        });
        chip.addEventListener('mouseenter', (event) => showTip(room, event));
        chip.addEventListener('mousemove', (event) => showTip(room, event));
        chip.addEventListener('mouseleave', hideTip);
    }

    function renderOverlays() {
        hitEl.replaceChildren();
        markersEl.replaceChildren();
        const materialSet = new Set(materialKeys);
        overlayRooms().forEach((room) => {
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
            const box = contourBox(contour) || storedJumpTarget(room)?.box;
            if (!contour || !box) {
                return;
            }
            const finishes = roomFinishes(room);
            finishChipViews(room).forEach((view, index) => {
                const finish = finishes[index] || { code: view.floor_code, material_color: view.material_color, role: index === 0 ? 'main' : 'local' };
                const finishOn = materialSet.size === 0 || materialSet.has(String(finish.material_key || view.material_key || '').toLowerCase());
                if (!finishOn && !state.selected) {
                    return;
                }
                const finishState = {
                    ...state,
                    highlighted: finishOn,
                    filteredOut: !finishOn && !state.selected,
                    fillAlpha: state.selected && finishOn ? 0.28 : (finishOn ? 0.18 : 0.05),
                };
                const isLocal = finish.role === 'local' && finishes.length > 1;
                let finishContour = contour;
                if (isLocal && Array.isArray(finish.marker?.polygon) && finish.marker.polygon.length >= 3) {
                    finishContour = { type: 'polygon', points: finish.marker.polygon, room };
                } else if (isLocal && finish.overlay) {
                    finishContour = { type: 'box', box: finish.overlay, room };
                } else if (isLocal) {
                    finishContour = null;
                }
                if (finishContour) {
                    appendFill({ ...room, material_color: finish.material_color || room.material_color }, finishContour, finishState);
                }
                const chipBox = chipAnchorInRoom(view, {
                    text: view.floor_codes_label || finish.code,
                    rooms,
                    index: finishInterior(view) ? 0 : view.chipIndex,
                    interior: finishInterior(view) || undefined,
                }) || box;
                appendCodeChip(view, chipBox, { ...finishState, highlighted: true });
            });
        });
        applyTransform();
    }

    function showTip(room, event) {
        if (!tipEl) {
            return;
        }
        const content = roomOverlayContent(room);
        tipEl.textContent = content.title;
        tipEl.classList.remove('hidden');
        const rect = stage.getBoundingClientRect();
        tipEl.style.left = `${event.clientX - rect.left + 12}px`;
        tipEl.style.top = `${event.clientY - rect.top + 12}px`;
    }

    function hideTip() {
        tipEl?.classList.add('hidden');
    }

    function focusOnRoom(room) {
        const target = storedJumpTarget(room);
        if (!target) {
            return false;
        }
        const rect = stage.getBoundingClientRect();
        const size = worldSize();
        const view = focusViewport(target.box, rect.width, rect.height, size.width, size.height);
        scale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, view.scale));
        panX = view.panX;
        panY = view.panY;
        applyTransform();

        return true;
    }

    async function renderPdfPage() {
        if (!pdfDoc) {
            return;
        }
        const generation = ++pdfRenderGeneration;
        if (pdfRenderTask) {
            pdfRenderTask.cancel();
            pdfRenderTask = null;
        }
        const pdfPage = await pdfDoc.getPage(page);
        if (generation !== pdfRenderGeneration) {
            return;
        }
        const cssViewport = pdfPage.getViewport({ scale: 1 });
        const renderScale = viewerRenderScale(cssViewport.width, cssViewport.height, VIEW_RENDER_SCALE, 2);
        const renderViewport = pdfPage.getViewport({ scale: renderScale });
        const context = canvas.getContext('2d', { alpha: false });
        canvas.width = Math.max(1, Math.floor(renderViewport.width));
        canvas.height = Math.max(1, Math.floor(renderViewport.height));
        canvas.classList.remove('hidden');
        image.classList.add('hidden');
        world.style.width = `${cssViewport.width}px`;
        world.style.height = `${cssViewport.height}px`;
        context.setTransform(1, 0, 0, 1, 0, 0);
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        const task = pdfPage.render({ canvasContext: context, viewport: renderViewport });
        pdfRenderTask = task;
        try {
            await task.promise;
        } catch (error) {
            if (generation !== pdfRenderGeneration || error?.name === 'RenderingCancelledException') {
                return;
            }
            throw error;
        } finally {
            if (pdfRenderTask === task) {
                pdfRenderTask = null;
            }
        }
        if (generation !== pdfRenderGeneration) {
            return;
        }
        renderOverlays();
    }

    function updatePageOptions() {
        pageSelect.innerHTML = '';
        for (let number = 1; number <= pageCount; number += 1) {
            const option = document.createElement('option');
            option.value = String(number);
            option.textContent = `Pagina ${number}`;
            pageSelect.append(option);
        }
        pageSelect.value = String(page);
        pageSelect.hidden = pageCount <= 1;
    }

    async function loadDrawing() {
        const drawing = drawingById(drawingId);
        if (!drawing) {
            setHint('Nog geen PDF-tekening bij deze calculatie.');
            return;
        }
        setHint('Tekening laden…');
        const loaded = await pdfjsLib.getDocument({ url: drawing.url, withCredentials: true }).promise;
        pdfDoc = loaded;
        pageCount = loaded.numPages;
        if (page > pageCount) {
            page = 1;
        }
        updatePageOptions();
        await renderPdfPage();
        await extractLabels();
        renderOverlays();
        setHint('');
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    function setRoomProgressBar(percent, done) {
        const bar = document.getElementById('room-progress-bar');
        if (!bar) {
            return;
        }
        bar.style.width = `${percent}%`;
        bar.classList.toggle('is-done', Boolean(done));
        bar.classList.toggle('bg-nicon-ok', Boolean(done));
        bar.classList.toggle('bg-nicon-orange', !done);
    }

    function workCardQtyHtml(label) {
        const text = String(label || '').trim();
        if (!text) {
            return '';
        }
        const parts = text.split(/\s*\|\s*/).filter(Boolean);
        const inner = parts.map((part, index) => (
            `<span class="work-card-qty-part">${escapeHtml(index ? `| ${part}` : part)}</span>`
        )).join('');

        return `<span class="work-card-qty" title="${escapeHtml(text)}">${inner}</span>`;
    }

    function groupCardHtml(group) {
        const typeLabel = group.type_label && !String(group.label || '').includes(group.type_label)
            ? group.type_label
            : '';
        const qty = group.progress_label || group.quantity_label || '';
        const metaParts = [typeLabel].filter(Boolean);
        const isLocal = Boolean(group.is_local) || group.role === 'local';
        const showInput = needsLocalAreaInput(group, Boolean(data.can_update));
        const finishId = group.finish_id || '';

        return `
            <section class="work-group${isLocal ? ' is-local' : ''}" data-kind="${escapeHtml(group.color_key || 'overige')}" data-role="${escapeHtml(group.role || '')}" data-finish-id="${escapeHtml(finishId)}" style="--material-color: ${escapeHtml(group.display_color || '#9ca3af')}; --work-accent: ${escapeHtml(group.display_color || '#9ca3af')}; --work-bg: ${escapeHtml(group.display_color_soft || 'rgba(156, 163, 175, 0.14)')};">
                <button type="button" class="group-head" disabled data-group="${escapeHtml(group.key)}" data-label="${escapeHtml(group.label)}">
                    <span class="task-check"></span>
                    <span class="work-card-copy">
                        <span class="work-card-title"><i class="work-swatch" aria-hidden="true"></i>${escapeHtml(group.label)}</span>
                        ${workCardQtyHtml(qty)}
                        ${metaParts.length ? `<span class="work-card-meta">${escapeHtml(metaParts.join(' · '))}</span>` : ''}
                    </span>
                    <span class="group-status text-nicon-muted">${isLocal ? '<span class="work-role">Deelvlak</span>' : ''}${escapeHtml(group.status_label || '')}</span>
                </button>
                ${showInput ? localAreaFormHtml(finishId) : ''}
            </section>`;
    }

    function localAreaFormHtml(finishId) {
        return `
            <form class="local-area-form" data-finish-id="${escapeHtml(finishId)}">
                <label>
                    <span class="text-[11px] uppercase tracking-wide text-nicon-muted">Oppervlakte deelvlak</span>
                    <span class="local-area-input-row">
                        <input name="local_area" inputmode="decimal" class="border border-nicon-line bg-white px-2 py-1" aria-label="Oppervlakte deelvlak">
                        <span class="text-xs text-nicon-muted">m²</span>
                    </span>
                </label>
                <button type="submit" class="bg-nicon-ink px-3 py-1.5 text-xs text-white">Opslaan</button>
            </form>`;
    }

    function renderWorkLegend(groups) {
        const legend = document.getElementById('work-legend');
        if (!legend) {
            return;
        }
        legend.innerHTML = (groups || []).map((group) => {
            const key = group.color_key || 'overige';
            const label = group.label || group.color_label || group.type_label || key;
            const color = group.display_color || '#9ca3af';

            return `<span data-kind="${escapeHtml(key)}" style="--work-accent: ${escapeHtml(color)}"><i></i>${escapeHtml(label)}</span>`;
        }).join('');
    }

    function roomMaterialGroups(room) {
        if (Array.isArray(room?.groups) && room.groups.length > 0) {
            return room.groups;
        }
        const groups = roomFinishes(room).map((finish) => {
            const label = [finish.product, finish.code].filter(Boolean).join(' ') || 'Vloer';

            return {
                key: `floor:${finish.id || finish.code || label}`,
                finish_id: finish.id || null,
                role: finish.role || 'main',
                is_local: finish.role === 'local',
                needs_local_area: finish.role === 'local' && (finish.quantity == null || finish.quantity === ''),
                label,
                quantity: finish.quantity ?? null,
                progress_label: finish.quantity_label || '',
                quantity_label: finish.quantity_label || '',
                display_color: finish.material_color || room.material_color || '#9ca3af',
                display_color_soft: finish.material_color_soft || room.material_color_soft || 'rgba(156, 163, 175, 0.14)',
                color_key: 'vloer',
                status_label: room.status_label || '',
            };
        });
        if (room?.has_plinth || room?.plinth_code || room?.plinth_product) {
            groups.push({
                key: `plinth:${room.plinth_id || room.plinth_code || 'plinth'}`,
                label: [room.plinth_product, room.plinth_code].filter(Boolean).join(' ') || 'Plint',
                progress_label: room.plinth_quantity_label || '',
                quantity_label: room.plinth_quantity_label || '',
                display_color: '#f38400',
                display_color_soft: 'rgba(243, 132, 0, 0.14)',
                color_key: 'plinten',
                status_label: room.status_label || '',
            });
        }

        return groups;
    }

    function renderRoomGroups(room) {
        const groupsEl = document.getElementById('room-groups');
        if (!groupsEl) {
            return;
        }
        const groups = room ? roomMaterialGroups(room) : [];
        groupsEl.innerHTML = groups.length
            ? groups.map((group) => groupCardHtml(group)).join('')
            : (room ? '<p class="text-sm text-nicon-muted">Geen materialen in deze ruimte.</p>' : '');
        renderWorkLegend(groups);
        groupsEl.querySelectorAll('.local-area-form').forEach((localForm) => {
            localForm.addEventListener('submit', (event) => {
                event.preventDefault();
                event.stopPropagation();
                saveLocalArea(room, localForm);
            });
        });
    }

    function paintPanel(room) {
        const panel = document.getElementById('room-panel');
        const empty = document.getElementById('calc-room-empty');
        const fields = document.getElementById('calc-room-fields');
        const groupsEl = document.getElementById('room-groups');
        panel?.classList.toggle('has-room', Boolean(room));
        const drawingEl = document.getElementById('room-drawing');
        if (drawingEl) {
            drawingEl.textContent = room?.group || '';
        }
        const titleEl = document.getElementById('room-title');
        if (titleEl) {
            titleEl.textContent = room
                ? `${room.number || '—'} ${room.name || ''}`.trim()
                : 'Kies een ruimte';
        }
        const m2El = document.getElementById('room-m2');
        if (m2El) {
            m2El.textContent = room?.m2_label || '';
        }
        const kindEl = document.getElementById('room-review-kind');
        if (kindEl) {
            const kind = room ? reviewKindOf(room) : '';
            kindEl.hidden = !room;
            kindEl.textContent = room?.review_kind_label || '';
            kindEl.className = `room-review-kind${kind ? ` is-${kind}` : ''}`;
        }
        const materialLine = document.getElementById('room-material-line');
        if (materialLine) {
            const code = room ? (room.floor_codes_label || room.floor_code || '') : '';
            const product = room?.floor_product || '';
            materialLine.textContent = room
                ? [code || '—', product].filter(Boolean).join(' · ')
                : '';
        }
        const status = document.getElementById('room-progress-label') || document.getElementById('room-status');
        if (status) {
            status.textContent = room
                ? (room.progress ? `${room.progress} · ${room.status_label || ''}` : (room.status_label || ''))
                : '';
            const kind = room ? reviewKindOf(room) : '';
            status.className = `text-sm ${kind === 'review' ? 'text-nicon-warn' : (kind === 'manual' ? 'text-nicon-ink' : (room ? 'text-nicon-ok' : ''))}`;
        }
        setRoomProgressBar(
            room?.total ? Math.round((Number(room.done || 0) / Number(room.total)) * 100) : 0,
            Boolean(room) && !room.needs_review && Number(room.total || 0) > 0,
        );
        empty?.classList.toggle('hidden', Boolean(room));
        fields?.classList.toggle('hidden', !room);
        if (form) {
            form.hidden = !room;
            form.classList.toggle('hidden', !room);
        }
        groupsEl?.classList.toggle('hidden', !room);
        renderRoomGroups(room);
        if (!room || !form) {
            return;
        }
        form.room_number.value = room.number || '';
        form.room_name.value = room.name || '';
        form.floor_quantity.value = qtyInput(room.floor_quantity);
        fillMaterialPicker(room);
        form.floor_product.value = room.floor_product || '';
        form.plinth_code.value = room.plinth_code || '';
        form.plinth_product.value = room.plinth_product || '';
        form.plinth_quantity.value = qtyInput(room.plinth_quantity);
        const finishList = document.getElementById('floor-finishes');
        const finishTotal = document.getElementById('floor-finishes-total');
        if (finishList) {
            finishList.replaceChildren();
            roomFinishes(room).forEach((finish) => {
                const item = document.createElement('li');
                const label = [finish.product, finish.code].filter(Boolean).join(' ');
                item.textContent = `• ${label || '—'} — ${finish.quantity_label || '—'}`;
                finishList.append(item);
            });
        }
        if (finishTotal) {
            finishTotal.textContent = roomFinishes(room).length > 1
                ? `totaal vloerafwerkingen → ${room.m2_label || '—'}`
                : '';
        }
        const plinthStatus = document.getElementById('plinth-status');
        if (plinthStatus) {
            plinthStatus.textContent = room.plinth_status_label || '—';
        }
        const pdfSource = document.getElementById('pdf-source');
        if (pdfSource) {
            pdfSource.textContent = room.pdf_source ? `PDF-bron: ${room.pdf_source}` : '';
        }
        const excelBits = [];
        if (room.excel_source) {
            excelBits.push(`Excel-bron: ${room.excel_source}`);
        }
        if (room.excel_conflict && room.excel_quantity_label) {
            excelBits.push(`Afwijking Excel ${room.excel_quantity_label}`);
        } else if (room.excel_quantity_label) {
            excelBits.push(`Excel ${room.excel_quantity_label}`);
        }
        if (room.excel_code_conflict && room.excel_product_code) {
            excelBits.push(`Excel-code ${room.excel_product_code}`);
        }
        const excelSource = document.getElementById('excel-source');
        if (excelSource) {
            excelSource.textContent = excelBits.join(' · ');
        }
        const confirmBtn = document.getElementById('calc-confirm');
        if (confirmBtn) {
            confirmBtn.hidden = !room.can_confirm;
        }
        setMessage('');
        setError('');
    }

    function fillMaterialPicker(room) {
        if (form?.floor_code) {
            form.floor_code.value = room.floor_code || '';
        }
        const label = document.getElementById('calc-open-material-label');
        const swatch = document.getElementById('calc-open-material-swatch');
        const code = String(room.floor_codes_label || room.floor_code || '').trim();
        const product = String(room.floor_product || '').trim();
        if (label) {
            label.textContent = code
                ? [code, product].filter(Boolean).join(' · ')
                : 'Materiaal kiezen';
        }
        if (swatch) {
            swatch.hidden = !room.material_color;
            if (room.material_color) {
                swatch.style.background = room.material_color;
            }
        }
    }

    function openMaterialDialog(room, finishId) {
        const dialog = document.getElementById('calc-material-dialog');
        if (!dialog || !room) {
            return;
        }
        creatingPoint = null;
        dialog.dataset.create = '';
        setCreateFields(false);
        const target = roomByKey(room.key) || room;
        if (String(selectedKey) !== String(target.key)) {
            selectRoom(target.key, { keepView: true });
        }
        const finishes = roomFinishes(target);
        const finish = finishes.find((item) => Number(item.id) === Number(finishId)) || finishes[0] || null;
        dialog.dataset.roomKey = String(target.key || '');
        dialog.dataset.finishId = finish?.id ? String(finish.id) : '';
        const roomEl = document.getElementById('calc-material-dialog-room');
        if (roomEl) {
            roomEl.textContent = `${target.number || '—'} ${target.name || ''}`.trim();
        }
        const m2El = document.getElementById('calc-material-dialog-m2');
        if (m2El) {
            const area = finish?.quantity_label || target.m2_label || 'geen m² gevonden';
            m2El.textContent = `Oppervlakte: ${area}`;
        }
        fillMaterialChoiceList(dialog, {
            code: String(finish?.code || target.floor_code || '').toLowerCase(),
            product: finish?.product || target.floor_product || '',
            color: finish?.material_color || target.material_color || '',
        });
        revealMaterialDialog(dialog);
    }

    function setCreateFields(visible, draft = null) {
        const wrap = document.getElementById('calc-material-dialog-create');
        wrap?.toggleAttribute('hidden', !visible);
        const m2El = document.getElementById('calc-material-dialog-m2');
        if (m2El) {
            m2El.hidden = Boolean(visible);
        }
        const title = document.getElementById('calc-material-dialog-title');
        if (title) {
            title.textContent = visible ? 'Ruimte aanmaken' : 'Materiaal kiezen';
        }
        if (!visible) {
            return;
        }
        const numberInput = document.getElementById('calc-create-number');
        const nameInput = document.getElementById('calc-create-name');
        const qtyField = document.getElementById('calc-create-m2');
        if (numberInput) {
            numberInput.value = draft?.number || '';
        }
        if (nameInput) {
            nameInput.value = draft?.name || '';
        }
        if (qtyField) {
            qtyField.value = draft?.quantity == null ? '' : qtyInput(draft.quantity);
        }
    }

    function fillMaterialChoiceList(dialog, current) {
        const currentCode = String(current?.code || '').toLowerCase();
        const choices = legendMaterialChoices([...legend, ...materials], current);
        const list = document.getElementById('calc-material-dialog-list');
        const empty = document.getElementById('calc-material-dialog-empty');
        empty?.toggleAttribute('hidden', choices.length > 0);
        const codeInput = document.getElementById('calc-material-code');
        const productInput = document.getElementById('calc-material-product');
        if (codeInput) {
            codeInput.value = currentCode;
        }
        if (productInput) {
            productInput.value = current?.product || '';
        }
        if (!list) {
            return;
        }
        list.replaceChildren();
        choices.forEach((entry) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'calc-material-choice';
            if (entry.code === currentCode) {
                button.classList.add('is-on');
            }
            const contrast = overlayContrast(entry.color || current?.color);
            const mark = document.createElement('i');
            mark.className = 'calc-swatch';
            mark.style.background = contrast.bg;
            const codeEl = document.createElement('span');
            codeEl.className = 'calc-material-choice-code';
            codeEl.textContent = entry.code;
            const productEl = document.createElement('span');
            productEl.className = 'calc-material-choice-product';
            productEl.textContent = entry.product || '—';
            button.append(mark, codeEl, productEl);
            button.addEventListener('click', () => {
                if (dialog.dataset.create === '1') {
                    if (codeInput) {
                        codeInput.value = entry.code;
                    }
                    if (productInput) {
                        productInput.value = entry.product || '';
                    }
                    list.querySelectorAll('.calc-material-choice').forEach((item) => item.classList.toggle('is-on', item === button));
                    return;
                }
                pickMaterial(entry.code, entry.product);
            });
            list.append(button);
        });
    }

    function roomByNumberOnDrawing(number, name = '') {
        const needle = normalizeRoomNumber(number);
        if (!needle) {
            return null;
        }
        const matches = rooms.filter((room) => (
            Number(room.drawing_id) === Number(drawingId)
            && normalizeRoomNumber(room.number) === needle
        ));
        const nameNeedle = String(name || '').trim().toLowerCase().replace(/\s+/g, ' ');
        if (nameNeedle !== '') {
            const named = matches.find((room) => (
                String(room.name || '').trim().toLowerCase().replace(/\s+/g, ' ') === nameNeedle
            ));
            if (named) {
                return named;
            }
            const hasOtherName = matches.some((room) => {
                const current = String(room.name || '').trim().toLowerCase().replace(/\s+/g, ' ');

                return current !== '' && current !== nameNeedle;
            });
            if (hasOtherName) {
                return null;
            }
        }

        return matches.length === 1 ? matches[0] : null;
    }

    function consumeEmptyDouble(point) {
        const now = Date.now();
        const doubled = (now - lastEmptyClick.at) < 450
            && Math.hypot(point.x - lastEmptyClick.x, point.y - lastEmptyClick.y) < 0.035;
        lastEmptyClick = { at: now, x: point.x, y: point.y };

        return doubled;
    }

    function openCreateRoomDialog(point) {
        if (!data.can_update) {
            return;
        }
        const draft = unplacedRoomDraft(pageItems, point, page);
        const existing = roomByNumberOnDrawing(draft.number, draft.name);
        creatingPoint = { x: draft.x, y: draft.y, page: draft.page || page };
        const dialog = document.getElementById('calc-material-dialog');
        if (!dialog) {
            return;
        }
        if (existing) {
            dialog.dataset.create = '1';
            dialog.dataset.roomKey = String(existing.key || '');
            dialog.dataset.finishId = '';
            const roomEl = document.getElementById('calc-material-dialog-room');
            if (roomEl) {
                roomEl.textContent = `${existing.number || '—'} ${existing.name || ''}`.trim();
            }
            setCreateFields(true, {
                number: existing.number || draft.number,
                name: existing.name || draft.name,
                quantity: existing.floor_quantity ?? draft.quantity,
            });
            fillMaterialChoiceList(dialog, {
                code: String(existing.floor_code || '').toLowerCase(),
                product: existing.floor_product || '',
                color: existing.material_color || '',
            });
            revealMaterialDialog(dialog);
            return;
        }
        dialog.dataset.create = '1';
        dialog.dataset.roomKey = '';
        dialog.dataset.finishId = '';
        const roomEl = document.getElementById('calc-material-dialog-room');
        if (roomEl) {
            roomEl.textContent = 'Niet herkende ruimte';
        }
        setCreateFields(true, draft);
        fillMaterialChoiceList(dialog, { code: '', product: '' });
        revealMaterialDialog(dialog);
    }

    function revealMaterialDialog(dialog) {
        if (!dialog) {
            return;
        }
        if (typeof dialog.showModal === 'function') {
            if (!dialog.open) {
                dialog.showModal();
            }
            return;
        }
        dialog.setAttribute('open', '');
    }

    async function pickMaterial(code, product) {
        const dialog = document.getElementById('calc-material-dialog');
        const room = roomByKey(dialog?.dataset.roomKey || selectedKey);
        if (!room?.id || !data.can_update) {
            dialog?.close();
            return;
        }
        const chosenProduct = product || legendProductFor(code);
        if (form?.floor_code && !dialog?.dataset.finishId) {
            form.floor_code.value = code;
        }
        if (form?.floor_product && chosenProduct && !dialog?.dataset.finishId) {
            form.floor_product.value = chosenProduct;
        }
        await saveRoom(room, materialChoicePatch(room, {
            code,
            product: chosenProduct,
            finishId: dialog?.dataset.finishId,
        }), 'Materiaal opgeslagen.');
        dialog?.close();
    }

    function applyTypedMaterial() {
        const dialog = document.getElementById('calc-material-dialog');
        if (dialog?.dataset.create === '1') {
            createBoardRoomFromDialog();
            return;
        }
        const code = String(document.getElementById('calc-material-code')?.value || '').trim();
        const product = String(document.getElementById('calc-material-product')?.value || '').trim();
        if (code === '') {
            setError('Vul een materiaalcode in.');
            return;
        }
        setError('');
        pickMaterial(code, product || legendProductFor(code));
    }

    async function createBoardRoomFromDialog() {
        const dialog = document.getElementById('calc-material-dialog');
        if (!data.can_update || !data.routes?.create_room || !drawingId) {
            return;
        }
        const number = String(document.getElementById('calc-create-number')?.value || '').trim();
        const name = String(document.getElementById('calc-create-name')?.value || '').trim();
        const quantity = String(document.getElementById('calc-create-m2')?.value || '').trim();
        const code = String(document.getElementById('calc-material-code')?.value || '').trim();
        const product = String(document.getElementById('calc-material-product')?.value || '').trim();
        if (code === '') {
            setError('Kies of vul een materiaalcode in.');
            return;
        }
        if (quantity === '') {
            setError('Vul de oppervlakte in m² in.');
            return;
        }
        if (number === '' && name === '') {
            setError('Vul een ruimtenummer of ruimtenaam in.');
            return;
        }
        setError('');
        const point = creatingPoint || { x: 0.5, y: 0.5, page };
        const response = await fetch(data.routes.create_room, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                document_id: drawingId,
                page: Math.max(1, Number(point.page || page)),
                room_number: number,
                room_name: name,
                floor_code: code,
                floor_product: product || legendProductFor(code),
                floor_quantity: quantity,
                chip: {
                    x: clamp(point.x),
                    y: clamp(point.y),
                    page: Math.max(1, Number(point.page || page)),
                },
            }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            setError(payload.message || Object.values(payload.errors || {}).flat().join(' ') || 'Aanmaken mislukt.');
            return;
        }
        if (payload.room) {
            payload.room.materials = payload.materials;
            applyRoomUpdate(payload.room);
            creatingPoint = null;
            dialog?.close();
            setMessage('Ruimte aangemaakt.');
            selectRoom(payload.room.key, { keepView: true });
        }
    }

    function legendProductFor(code) {
        const needle = String(code || '').toLowerCase();
        const entry = legend.find((item) => String(item.code || '').toLowerCase() === needle)
            || materials.find((item) => String(item.code || '').toLowerCase() === needle);

        return entry?.product || '';
    }

    function setMessage(text) {
        const el = document.getElementById('calc-room-message');
        if (!el) {
            return;
        }
        el.textContent = text;
        el.classList.toggle('hidden', !text);
    }

    function setError(text) {
        const el = document.getElementById('calc-room-error');
        if (!el) {
            return;
        }
        el.textContent = text;
        el.classList.toggle('hidden', !text);
    }

    function typedLocalArea(finishId) {
        const input = document.querySelector(`.local-area-form[data-finish-id="${CSS.escape(String(finishId))}"] [name="local_area"]`);
        if (!input) {
            return null;
        }
        const value = String(input.value || '').trim();

        return value === '' ? null : value;
    }

    async function saveLocalArea(room, localForm) {
        if (!room?.id || !data.can_update) {
            return;
        }
        const finishId = Number(localForm.dataset.finishId);
        const quantity = typedLocalArea(finishId);
        if (quantity == null) {
            setError('Vul de oppervlakte van het deelvlak in.');
            return;
        }
        await saveRoom(room, localAreaPatchBody(finishId, quantity), 'Deelvlak opgeslagen.');
    }

    async function saveRoom(room, body, message = 'Opgeslagen.') {
        setError('');
        const response = await fetch(route('update_room', room.id), {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            setError(payload.message || Object.values(payload.errors || {}).flat().join(' ') || 'Opslaan mislukt.');
            return;
        }
        if (payload.room) {
            payload.room.materials = payload.materials;
            applyRoomUpdate(payload.room);
        }
        setMessage(message);
    }

    function applyRoomUpdate(next) {
        if (!next?.key) {
            return;
        }
        const index = rooms.findIndex((room) => Number(room.id) === Number(next.id) || String(room.key) === String(next.key));
        const previousKey = index >= 0 ? rooms[index].key : next.key;
        if (index >= 0) {
            const previous = rooms[index];
            rooms[index] = {
                ...previous,
                ...next,
                marker: next.chip?.manual
                    ? {
                        page: Math.max(1, Number(next.chip.page) || Number(previous.marker?.page) || 1),
                        x: Number(next.chip.x),
                        y: Number(next.chip.y),
                        width: 0.04,
                        height: 0.02,
                        source: 'user',
                    }
                    : (previous.chip?.manual && !next.chip
                        ? { ...previous.marker, source: 'text' }
                        : previous.marker),
                jump_target: next.chip?.manual
                    ? {
                        page: Math.max(1, Number(next.chip.page) || 1),
                        bbox: { x: Number(next.chip.x), y: Number(next.chip.y), w: 0.04, h: 0.02 },
                        geometry: 'label',
                    }
                    : previous.jump_target,
                has_position: Boolean(next.chip?.manual) || (previous.has_position && !(!next.chip && previous.chip?.manual)),
                floors: (next.floors || []).map((finish) => {
                    const prior = (previous.floors || []).find((item) => Number(item.id) === Number(finish.id));
                    return { ...finish, marker: prior?.marker || finish.marker };
                }),
            };
        } else {
            rooms.push({
                ...next,
                marker: next.chip?.manual
                    ? {
                        page: Math.max(1, Number(next.chip.page) || page),
                        x: Number(next.chip.x),
                        y: Number(next.chip.y),
                        width: 0.04,
                        height: 0.02,
                        source: 'user',
                    }
                    : next.marker,
                jump_target: next.chip?.manual
                    ? {
                        page: Math.max(1, Number(next.chip.page) || page),
                        bbox: { x: Number(next.chip.x), y: Number(next.chip.y), w: 0.04, h: 0.02 },
                        geometry: 'label',
                    }
                    : next.jump_target,
                has_position: Boolean(next.chip?.manual),
            });
            appendRoomRow(next);
        }
        const row = root.querySelector(`.calc-room-row[data-room-key="${CSS.escape(String(previousKey))}"]`);
        if (row) {
            row.dataset.roomKey = next.key;
            row.style.setProperty('--material-color', next.material_color);
            row.style.setProperty('--material-color-soft', next.material_color_soft);
            row.classList.remove('is-review', 'is-manual', 'is-certain');
            row.classList.add(reviewKindClass(next));
            row.dataset.review = next.needs_review ? '1' : '0';
            row.dataset.reviewKind = next.review_kind || reviewKindOf(next);
            row.dataset.material = next.material_key || '';
            row.dataset.search = next.search || '';
            const num = row.querySelector('.room-num');
            const name = row.querySelector('.room-name');
            const m2 = row.querySelector('.room-m2');
            const code = row.querySelector('.room-code');
            if (num) {
                num.textContent = next.number || '—';
            }
            if (name) {
                name.textContent = next.name || '—';
            }
            if (m2) {
                m2.textContent = next.m2_label || '—';
            }
            if (code) {
                code.textContent = next.floor_codes_label || next.floor_code || '—';
            }
        }
        if (Array.isArray(next.materials)) {
            materials = next.materials;
        }
        paintPanel(roomByKey(next.key));
        applyRoomFilters();
        refreshMaterialPanel();
    }

    async function selectRoom(key, options = {}) {
        const room = roomByKey(key);
        if (!room) {
            return;
        }
        const alreadySelected = String(selectedKey) === String(room.key);
        const token = ++selectToken;
        selectedKey = room.key;
        root.dataset.selected = String(room.key);
        highlightList();
        paintPanel(room);
        if (room.drawing_id && Number(room.drawing_id) !== Number(drawingId)) {
            drawingId = Number(room.drawing_id);
            drawingSelect.value = String(drawingId);
            page = 1;
            await loadDrawing();
            if (token !== selectToken) {
                return;
            }
        }
        const targetPage = Number(room.marker?.page || room.jump_target?.page || page);
        if (targetPage >= 1 && targetPage !== page) {
            page = targetPage;
            pageSelect.value = String(page);
            await renderPdfPage();
            if (token !== selectToken) {
                return;
            }
        }
        if (!options.keepView) {
            if (!focusOnRoom(room) && !room.marker) {
                setHint('Ruimte gelokaliseerd via het ruimtenummer wanneer de tekstlaag beschikbaar is.');
            }
        }
        if (!alreadySelected || !options.keepView) {
            renderOverlays();
        }
    }

    function clickableHits() {
        return overlayRooms().map((room) => {
            const state = roomDrawingState(room, {
                selectedKey,
                materialKeys,
                filter,
                search,
            });
            if (!state.show || state.filteredOut) {
                return null;
            }

            return {
                room,
                box: storedJumpTarget(room)?.box,
                contour: clickContourFor(room),
            };
        }).filter((item) => item && (item.box || item.contour));
    }

    function hitRoom(point) {
        const contourRooms = clickableHits()
            .filter((item) => item.contour)
            .map((item) => ({ contour: item.contour, room: item.room }));
        const contourHit = hitTestContours(point, contourRooms);
        if (contourHit?.room) {
            return contourHit.room;
        }
        const labelHit = hitTestLabels(point, clickableHits().map((item) => ({ box: item.box, room: item.room })));

        return labelHit?.room || null;
    }

    function refreshMaterialPanel() {
        if (workFilterLabel) {
            workFilterLabel.textContent = materialLabel(materialKeys, materials);
        }
        const total = document.getElementById('draw-work-panel-total');
        if (total) {
            const selected = materials.filter((item) => materialKeys.includes(item.key));
            const m2 = selected.reduce((sum, item) => sum + Number(item.m2 || 0), 0);
            total.textContent = materialKeys.length === 0
                ? 'Alle materialen'
                : `Geselecteerd: ${m2.toFixed(2).replace('.', ',')} m²`;
        }
        if (workFilterAllBox) {
            const boxes = [...(workFilterPanel?.querySelectorAll('[data-work-key]') ?? [])];
            workFilterAllBox.checked = materialKeys.length === 0;
            workFilterAllBox.indeterminate = materialKeys.length > 0
                && boxes.some((box) => box.checked)
                && !boxes.every((box) => box.checked);
        }
    }

    function readMaterialKeys() {
        return [...(workFilterPanel?.querySelectorAll('[data-work-key]') ?? [])]
            .filter((box) => box.checked)
            .map((box) => box.dataset.workKey);
    }

    function closeWorkPanel() {
        workFilterPanel?.classList.remove('is-open');
        workSelect?.classList.remove('is-open');
        workFilterToggle?.setAttribute('aria-expanded', 'false');
    }

    function positionWorkPanel() {
        if (!workFilterPanel || !workFilterToggle) {
            return;
        }
        const rect = workFilterToggle.getBoundingClientRect();
        workFilterPanel.style.left = `${Math.round(rect.left)}px`;
        workFilterPanel.style.top = `${Math.round(rect.bottom + 4)}px`;
    }

    document.getElementById('draw-hand').addEventListener('click', () => {
        tool = 'hand';
        document.getElementById('draw-hand').classList.add('is-on');
        document.getElementById('draw-select').classList.remove('is-on');
        stage.classList.add('is-grab');
        stage.classList.remove('is-select');
    });
    document.getElementById('draw-select').addEventListener('click', () => {
        tool = 'select';
        document.getElementById('draw-select').classList.add('is-on');
        document.getElementById('draw-hand').classList.remove('is-on');
        stage.classList.add('is-select');
        stage.classList.remove('is-grab');
    });
    document.getElementById('draw-zoom-in').addEventListener('click', () => {
        scale = Math.min(MAX_ZOOM, scale + 0.15);
        applyTransform();
    });
    document.getElementById('draw-zoom-out').addEventListener('click', () => {
        scale = Math.max(MIN_ZOOM, scale - 0.15);
        applyTransform();
    });
    drawingSelect?.addEventListener('change', async () => {
        drawingId = Number(drawingSelect.value);
        page = 1;
        await loadDrawing();
    });
    pageSelect.addEventListener('change', async () => {
        page = Number(pageSelect.value);
        await renderPdfPage();
    });
    root.querySelectorAll('#room-filters [data-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            filter = button.dataset.filter || 'all';
            root.querySelectorAll('#room-filters [data-filter]').forEach((item) => {
                item.classList.toggle('is-on', item === button);
            });
            applyRoomFilters();
        });
    });
    document.getElementById('calc-room-search')?.addEventListener('input', (event) => {
        search = event.target.value || '';
        applyRoomFilters();
    });
    root.querySelectorAll('.calc-room-row').forEach((row) => bindRoomRow(row));
    workFilterToggle?.addEventListener('click', (event) => {
        event.stopPropagation();
        const open = !workFilterPanel.classList.contains('is-open');
        workFilterPanel.classList.toggle('is-open', open);
        workSelect?.classList.toggle('is-open', open);
        workFilterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            positionWorkPanel();
        }
    });
    workFilterPanel?.addEventListener('change', (event) => {
        if (event.target.matches('[data-work-all]')) {
            workFilterPanel.querySelectorAll('[data-work-key]').forEach((box) => {
                box.checked = false;
            });
            workFilterAllBox.checked = true;
            materialKeys = [];
            refreshMaterialPanel();
            applyRoomFilters();
            return;
        }
        if (!event.target.matches('[data-work-key]')) {
            return;
        }
        materialKeys = readMaterialKeys();
        const boxes = [...(workFilterPanel.querySelectorAll('[data-work-key]') ?? [])];
        if (boxes.length > 0 && boxes.every((box) => box.checked)) {
            boxes.forEach((box) => {
                box.checked = false;
            });
            materialKeys = [];
        }
        refreshMaterialPanel();
        applyRoomFilters();
    });
    document.getElementById('draw-work-clear')?.addEventListener('click', () => {
        workFilterPanel.querySelectorAll('[data-work-key]').forEach((box) => {
            box.checked = false;
        });
        materialKeys = [];
        refreshMaterialPanel();
        applyRoomFilters();
        closeWorkPanel();
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('#draw-work, #draw-work-panel')) {
            closeWorkPanel();
        }
    });
    document.getElementById('toggle-rooms')?.addEventListener('click', () => {
        document.querySelector('.board-left')?.classList.toggle('is-open');
        document.querySelector('.board-right')?.classList.remove('is-open');
    });
    document.getElementById('toggle-tasks')?.addEventListener('click', () => {
        document.querySelector('.board-right')?.classList.toggle('is-open');
        document.querySelector('.board-left')?.classList.remove('is-open');
    });

    function toNorm(event) {
        const rect = world.getBoundingClientRect();

        return {
            x: clamp((event.clientX - rect.left) / rect.width),
            y: clamp((event.clientY - rect.top) / rect.height),
        };
    }

    stage.addEventListener('pointerdown', (event) => {
        if (event.button !== 0 || event.target.closest('button, select, a, input, textarea, label')) {
            return;
        }
        if (tool === 'select') {
            return;
        }
        dragging = true;
        dragMoved = false;
        dragStart = {
            x: event.clientX - panX,
            y: event.clientY - panY,
            cx: event.clientX,
            cy: event.clientY,
        };
        stage.classList.add('is-grabbing');
        stage.setPointerCapture(event.pointerId);
    });
    stage.addEventListener('pointermove', (event) => {
        if (!dragging || !dragStart) {
            return;
        }
        if (!dragMoved && Math.hypot(event.clientX - dragStart.cx, event.clientY - dragStart.cy) < 4) {
            return;
        }
        dragMoved = true;
        panX = event.clientX - dragStart.x;
        panY = event.clientY - dragStart.y;
        applyTransform();
    });
    stage.addEventListener('pointerup', (event) => {
        const wasDrag = dragMoved;
        dragging = false;
        dragMoved = false;
        stage.classList.remove('is-grabbing');
        if (wasDrag || event.button !== 0) {
            return;
        }
        if (event.target.closest('button, select, a, input, textarea, label') && !event.target.closest('.room-label')) {
            return;
        }
        if (event.target.closest('.calc-code-chip, .calc-fill, .room-label')) {
            return;
        }
        const point = toNorm(event);
        const room = hitRoom(point);
        if (room) {
            if (consumeDoubleActivate(room)) {
                openMaterialDialog(room);
                return;
            }
            selectRoom(room.key, { keepView: true });
            return;
        }
        if (data.can_update && consumeEmptyDouble(point)) {
            openCreateRoomDialog(point);
        }
    });
    stage.addEventListener('dblclick', (event) => {
        if (event.target.closest('button, select, a, input, textarea, label') && !event.target.closest('.calc-code-chip, .room-label')) {
            return;
        }
        const point = toNorm(event);
        const room = hitRoom(point);
        if (room) {
            event.preventDefault();
            openMaterialDialog(room);
            return;
        }
        if (data.can_update) {
            event.preventDefault();
            openCreateRoomDialog(point);
        }
    });
    stage.addEventListener('wheel', (event) => {
        event.preventDefault();
        const next = event.deltaY < 0 ? scale + 0.12 : scale - 0.12;
        scale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, next));
        applyTransform();
    }, { passive: false });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const room = roomByKey(selectedKey);
        if (!room?.id || !data.can_update) {
            return;
        }
        await saveRoom(room, {
            room_number: form.room_number.value,
            room_name: form.room_name.value,
            floor_code: form.floor_code.value,
            floor_product: form.floor_product.value,
            floor_quantity: form.floor_quantity.value,
            floors: roomFinishes(room).map((finish) => ({
                id: finish.id,
                code: finish.role === 'main' ? form.floor_code.value : finish.code,
                product: finish.role === 'main' ? form.floor_product.value : finish.product,
                quantity: finish.role === 'main' ? form.floor_quantity.value : (typedLocalArea(finish.id) ?? finish.quantity),
            })),
            plinth_code: form.plinth_code.value,
            plinth_product: form.plinth_product.value,
            plinth_quantity: form.plinth_quantity.value,
        });
    });
    form?.floor_quantity?.addEventListener('change', async () => {
        const room = roomByKey(selectedKey);
        if (!room?.id || !data.can_update) {
            return;
        }
        await saveRoom(room, {
            floor_quantity: form.floor_quantity.value,
        }, 'Oppervlakte opgeslagen.');
    });
    document.getElementById('calc-open-material')?.addEventListener('click', () => {
        const room = roomByKey(selectedKey);
        if (!room) {
            return;
        }
        openMaterialDialog(room);
    });
    document.getElementById('calc-material-dialog-close')?.addEventListener('click', () => {
        document.getElementById('calc-material-dialog')?.close();
    });
    document.getElementById('calc-material-apply')?.addEventListener('click', applyTypedMaterial);
    document.querySelector('#calc-material-dialog form')?.addEventListener('submit', (event) => {
        const code = String(document.getElementById('calc-material-code')?.value || '').trim();
        if (code === '') {
            return;
        }
        event.preventDefault();
        applyTypedMaterial();
    });
    document.getElementById('calc-reset-chip')?.addEventListener('click', async () => {
        const room = roomByKey(selectedKey);
        if (!room?.id || !data.can_update) {
            return;
        }
        await saveRoom(room, { chip_reset: true }, 'Positie hersteld.');
    });
    document.getElementById('calc-restore-auto')?.addEventListener('click', async () => {
        const room = roomByKey(selectedKey);
        if (!room?.id || !data.can_update) {
            return;
        }
        await saveRoom(room, { restore_automatic: true }, 'Automatisch hersteld.');
    });
    document.getElementById('calc-confirm')?.addEventListener('click', async () => {
        const room = roomByKey(selectedKey);
        if (!room?.id || !data.can_update) {
            return;
        }
        const response = await fetch(route('confirm_room', room.id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            setError(payload.message || 'Bevestigen mislukt.');
            return;
        }
        if (payload.room) {
            payload.room.materials = payload.materials;
            applyRoomUpdate(payload.room);
        }
        setMessage('Bevestigd.');
    });

    stage.classList.add('is-grab');
    refreshMaterialPanel();
    paintPanel(selectedKey ? roomByKey(selectedKey) : null);
    loadDrawing().then(() => {
        if (selectedKey) {
            selectRoom(selectedKey);
        }
    }).catch(() => {
        setHint('Tekening kon niet worden geladen.');
        if (selectedKey) {
            paintPanel(roomByKey(selectedKey));
        }
    });
}
