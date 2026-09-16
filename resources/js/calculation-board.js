import * as pdfjsLib from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import {
    assessTextLayer,
    clamp,
    contourBox,
    exactRoomHitForArea,
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
    materialFillBox,
    materialLabel,
    overlayContrast,
    qtyInput,
    roomDrawingState,
    roomMatchesFilter,
    roomMatchesMaterials,
    roomMatchesSearch,
    roomOverlayContent,
} from './calculation-board-selection';

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

    async function extractLabels() {
        if (!pdfDoc) {
            return;
        }
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
        rooms.filter((room) => Number(room.drawing_id) === Number(drawingId)).forEach((room) => {
            const hit = exactRoomHitForArea({ number: room.number, number_raw: room.number }, hits)
                || hits.find((item) => normalizeRoomNumber(item.number) === normalizeRoomNumber(room.number));
            assignHit(room, hit);
        });
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

    function bindOverlayPointer(el, room) {
        el.addEventListener('pointerdown', (event) => {
            event.stopPropagation();
        });
        el.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            hideTip();
            selectRoom(room.key, { keepView: true });
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
        if (room.needs_review) {
            classes.push('is-review');
        }
        shape.setAttribute('class', classes.join(' '));
        shape.dataset.roomKey = String(room.key);
        shape.style.fill = 'transparent';
        shape.style.stroke = 'transparent';
        bindOverlayPointer(shape, room);
        hitEl.append(shape);
    }

    function appendCodeChip(room, box, state) {
        const content = roomOverlayContent(room);
        if (!content.code || (!state.highlighted && !state.selected)) {
            return;
        }
        const contrast = overlayContrast(room.material_color);
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'calc-code-chip';
        if (state.selected) {
            chip.classList.add('is-on');
        }
        chip.textContent = content.code;
        chip.dataset.roomKey = String(room.key);
        chip.style.left = `${((Number(box.x) || 0) + (Number(box.w) || 0) / 2) * 100}%`;
        chip.style.top = `${((Number(box.y) || 0) + (Number(box.h) || 0) / 2) * 100}%`;
        chip.style.transform = codeChipTransform();
        chip.style.background = contrast.bg;
        chip.style.color = contrast.fg;
        chip.title = content.title;
        bindOverlayPointer(chip, room);
        markersEl.append(chip);
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
            finishes.forEach((finish) => {
                const finishOn = materialSet.size === 0 || materialSet.has(String(finish.material_key || '').toLowerCase());
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
                    return;
                }
                appendFill({ ...room, material_color: finish.material_color || room.material_color }, finishContour, finishState);
                if (isLocal) {
                    const localBox = contourBox(finishContour);
                    if (localBox) {
                        appendCodeChip({
                            ...room,
                            floor_code: finish.code,
                            floor_codes_label: finish.code,
                            material_color: finish.material_color || room.material_color,
                        }, localBox, { ...finishState, highlighted: true });
                    }
                }
            });
            if (finishes.length <= 1 || materialSet.size === 0 || materialSet.has(String(room.material_key || '').toLowerCase())) {
                appendCodeChip(room, box, state);
            }
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

    function paintPanel(room) {
        const empty = document.getElementById('calc-room-empty');
        const fields = document.getElementById('calc-room-fields');
        document.getElementById('room-drawing').textContent = room?.group || '';
        document.getElementById('room-title').textContent = room
            ? `${room.number || '—'} ${room.name || ''}`.trim()
            : 'Kies een ruimte';
        document.getElementById('room-m2').textContent = room?.m2_label || '';
        const status = document.getElementById('room-status');
        status.textContent = room?.status_label || '';
        status.className = `mt-1 text-sm ${room?.needs_review ? 'text-nicon-warn' : 'text-nicon-ok'}`;
        empty.classList.toggle('hidden', Boolean(room));
        fields.classList.toggle('hidden', !room);
        if (!room || !form) {
            return;
        }
        form.room_number.value = room.number || '';
        form.room_name.value = room.name || '';
        form.floor_quantity.value = qtyInput(room.floor_quantity);
        form.floor_code.value = room.floor_code || '';
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
        document.getElementById('plinth-status').textContent = room.plinth_status_label || '—';
        document.getElementById('pdf-source').textContent = room.pdf_source ? `PDF-bron: ${room.pdf_source}` : '';
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
        document.getElementById('excel-source').textContent = excelBits.join(' · ');
        const confirmBtn = document.getElementById('calc-confirm');
        confirmBtn.hidden = !room.can_confirm;
        setMessage('');
        setError('');
    }

    function setMessage(text) {
        const el = document.getElementById('calc-room-message');
        el.textContent = text;
        el.classList.toggle('hidden', !text);
    }

    function setError(text) {
        const el = document.getElementById('calc-room-error');
        el.textContent = text;
        el.classList.toggle('hidden', !text);
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
                marker: previous.marker,
                jump_target: previous.jump_target,
                has_position: previous.has_position,
                floors: (next.floors || []).map((finish) => {
                    const prior = (previous.floors || []).find((item) => Number(item.id) === Number(finish.id));
                    return { ...finish, marker: prior?.marker || finish.marker };
                }),
            };
        }
        const row = root.querySelector(`.calc-room-row[data-room-key="${CSS.escape(String(previousKey))}"]`);
        if (row) {
            row.dataset.roomKey = next.key;
            row.style.setProperty('--material-color', next.material_color);
            row.style.setProperty('--material-color-soft', next.material_color_soft);
            row.classList.toggle('is-review', Boolean(next.needs_review));
            row.dataset.review = next.needs_review ? '1' : '0';
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
        renderOverlays();
        refreshMaterialPanel();
    }

    async function selectRoom(key, options = {}) {
        const room = roomByKey(key);
        if (!room) {
            return;
        }
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
        renderOverlays();
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
    root.querySelectorAll('.calc-room-row').forEach((row) => {
        row.addEventListener('click', () => selectRoom(row.dataset.roomKey));
    });
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
        const room = hitRoom(toNorm(event));
        if (room) {
            selectRoom(room.key, { keepView: true });
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
        setError('');
        const body = {
            room_number: form.room_number.value,
            room_name: form.room_name.value,
            floor_code: form.floor_code.value,
            floor_product: form.floor_product.value,
            floor_quantity: form.floor_quantity.value,
            floors: roomFinishes(room).map((finish) => ({
                id: finish.id,
                code: finish.role === 'main' ? form.floor_code.value : finish.code,
                product: finish.role === 'main' ? form.floor_product.value : finish.product,
                quantity: finish.role === 'main' ? form.floor_quantity.value : finish.quantity,
            })),
            plinth_code: form.plinth_code.value,
            plinth_product: form.plinth_product.value,
            plinth_quantity: form.plinth_quantity.value,
        };
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
        setMessage('Opgeslagen.');
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
    loadDrawing().then(() => {
        if (selectedKey) {
            selectRoom(selectedKey);
        }
    }).catch(() => {
        setHint('Tekening kon niet worden geladen.');
    });
}
