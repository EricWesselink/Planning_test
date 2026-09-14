import * as pdfjsLib from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import {
    assessTextLayer,
    clamp,
    hitTestLabels,
    labelVisualBox,
    drawingMarks,
    nameMatches,
    normalizeRoomNumber,
    uniqueAreasOnPage,
    viewerRenderScale,
    clickBox,
    displayBox,
    selectionBarBox,
    layerShowsRooms,
    layerShowsSnags,
    roomFocusBox,
    hasReliableRoomPosition,
    storedJumpTarget,
    focusViewport,
    exactRoomHitForArea,
    hitTestContours,
    roomContour,
    roomVisualContour,
    contourBox,
} from './room-geometry';
import {
    OCR_DPI,
    PDF_DPI,
    VIEW_RENDER_SCALE,
    extractPageTextItems,
    pageSize,
} from './pdf-text-layer';
import { ocrMissingRooms } from './pdf-ocr';
import {
    areaMatchesWorkKeys,
    buildOutsourceSelection,
    buildTicketChunk,
    formatBoardQty,
    groupedWorkFilters,
    groupRoomsByFloor,
    isEntireFloorPick,
    measureSelectedWorks,
    measureSelectedRooms,
    roomMeasureChipLabel,
    roomSelectionSummaryLabel,
    selectedRoomProgressWorks,
    groupedRoomProgressWorks,
    checkedKeysFromWorkFilter,
    activeSelectionFromFilter,
    activeWorkBarLabel,
    shortWorkLabel,
    ticketStorePayload,
    workFilterSummaryLabel,
    workKeysOnRooms,
    ticketRoomsToPick,
} from './drawing-work-selection';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const root = document.getElementById('project-board');
if (!root) {
    // Board is only on the project drawing screen.
} else {
    boot();
}

function boot() {
    const data = JSON.parse(document.getElementById('board-data').textContent);
    const areas = data.areas || [];
    const snags = data.snags || [];
    const routes = data.routes || {};
    const csrf = data.csrf;
    const drawing = data.drawing;
    const ticketMode = data.ticketMode || null;

    function canPickRooms() {
        return Boolean(data.canEnterProgress || ticketMode);
    }

    const stage = document.getElementById('draw-stage');
    const world = document.getElementById('draw-world');
    const canvas = document.getElementById('draw-canvas');
    const image = document.getElementById('draw-image');
    const hitEl = document.getElementById('draw-hit');
    const markersEl = document.getElementById('draw-markers');
    const tipEl = document.getElementById('draw-tip');
    const pageSelect = document.getElementById('draw-page');
    const workSelect = document.getElementById('draw-work');
    const workFilterLabel = document.getElementById('draw-work-label');
    const workFilterToggle = document.getElementById('draw-work-toggle');
    const workFilterPanel = document.getElementById('draw-work-panel');
    const workFilterList = document.getElementById('draw-work-list');
    const workFilterTotal = document.getElementById('draw-work-panel-total');
    const workFilterBoxes = () => [...(workFilterPanel?.querySelectorAll('[data-work-key]') ?? [])];
    const workFilterAllBox = workFilterPanel?.querySelector('[data-work-all]');
    if (workFilterPanel && workFilterPanel.parentElement !== document.body) {
        document.body.appendChild(workFilterPanel);
    }
    const pickWorkRoomsBtn = document.getElementById('pick-work-rooms');
    const pickRoomsBtn = document.getElementById('pick-rooms-btn');
    const roomMeasureBar = document.getElementById('room-measure-bar');
    const roomMeasureCount = document.getElementById('room-measure-count');
    const roomMeasureQty = document.getElementById('room-measure-qty');
    const roomMeasureActive = document.getElementById('room-measure-active');
    const roomMeasureViewBtn = document.getElementById('room-measure-view');
    const roomMeasureClearBtn = document.getElementById('room-measure-clear');
    const roomMeasurePanel = document.getElementById('room-measure-panel');
    const roomMeasureRoomsEl = document.getElementById('room-measure-rooms');
    const roomMeasureTotalsEl = document.getElementById('room-measure-totals');
    const roomProgressOpenBtn = document.getElementById('room-progress-open');
    const roomProgressPanel = document.getElementById('room-progress-panel');
    const roomProgressForm = document.getElementById('room-progress-form');
    const roomProgressWorksEl = document.getElementById('room-progress-works');
    const roomProgressRoomsEl = document.getElementById('room-progress-rooms');
    const roomProgressTotalsEl = document.getElementById('room-progress-totals');
    const roomProgressMeta = document.getElementById('room-progress-meta');
    const roomProgressActive = document.getElementById('room-progress-active-lines');
    const roomProgressError = document.getElementById('room-progress-error');
    const roomProgressSubmit = document.getElementById('room-progress-submit');
    const roomProgressNote = document.getElementById('room-progress-note');
    const roomProgressNoteCount = document.getElementById('room-progress-note-count');
    if (roomMeasurePanel && roomMeasurePanel.parentElement !== document.body) {
        document.body.appendChild(roomMeasurePanel);
    }
    if (roomProgressPanel && roomProgressPanel.parentElement !== document.body) {
        document.body.appendChild(roomProgressPanel);
    }
    const outsourceSelectionData = document.getElementById('outsource-selection-data');
    const hint = document.getElementById('draw-hint');
    const groupsEl = document.getElementById('room-groups');
    const completeForm = document.getElementById('complete-form');
    const snagForm = document.getElementById('snag-form');
    const roomPanel = document.getElementById('room-panel');
    const snagPanel = document.getElementById('snag-panel');

    function applyStatusSelect(select, locked = false) {
        if (!select) {
            return;
        }
        [...select.options].forEach((option) => {
            if (option.value === 'closed') {
                option.disabled = !data.canCloseSnags;
            }
        });
        select.disabled = Boolean(locked) || !data.canAdvanceSnagStatus;
    }

    function revertPopupStatus(snag) {
        const select = document.getElementById('snag-popup-status');
        if (!select) {
            return;
        }
        popupStatusLock = true;
        select.value = snag.status;
        popupStatusLock = false;
    }

    function forbidProgressSubmit(submit) {
        if (!data.canEnterProgress && submit) {
            submit.disabled = true;
        }
    }

    let pdfDoc = null;
    let pdfRenderTask = null;
    let pdfRenderGeneration = 0;
    let page = 1;
    let pageCount = 1;
    let workFilterKeys = [];
    let draftWorkKeys = [];
    let scale = 1;
    const MIN_ZOOM = 0.4;
    const MAX_ZOOM = 4;
    let renderInfo = {
        scale: VIEW_RENDER_SCALE,
        dpi: PDF_DPI * VIEW_RENDER_SCALE,
        width: 0,
        height: 0,
        cssWidth: 0,
        cssHeight: 0,
        pdfWidthPt: 0,
        pdfHeightPt: 0,
    };
    let pageDebug = {};
    let textHits = [];
    let panX = 0;
    let panY = 0;
    let lastJumpDebug = null;
    let linkModeAreaId = 0;
    let detectReviewIds = [];
    let dragging = false;
    let dragMoved = false;
    let dragStart = null;
    let tool = 'hand';
    let layer = (data.snags || []).length ? 'both' : 'rooms';
    let snagMode = false;
    let moveMode = false;
    let selectedId = Number(root.dataset.selected || areas[0]?.id || 0);
    let pickedIds = new Set(selectedId ? [selectedId] : []);
    let roomMeasureMode = false;
    let ticketChunks = [];
    let selectToken = 0;
    let pickedToken = 0;
    let savingWork = false;
    let snagSaving = false;
    let popupSaving = false;
    let pendingSnag = null;
    let snagPhotoFiles = [];
    let popupPhotoFiles = [];
    let editingSnagId = null;
    let selectedSnagId = null;
    let popupSnagId = null;
    let popupToken = 0;
    let popupStatusLock = false;
    let popupWorkerLock = false;
    let currentSnag = null;
    let draggingSnag = null;
    let areaDetails = {};

    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrf,
    };

    function route(name, id) {
        const map = {
            area: ['__AREA__', routes.area],
            areas: [null, routes.areas],
            complete: ['__TASK__', routes.complete],
            group: ['__AREA__', routes.group],
            process: ['__AREA__', routes.process],
            reopen: ['__AREA__', routes.reopen],
            approve: ['__AREA__', routes.approve],
            processMany: [null, routes.processMany],
            processSelection: [null, routes.processSelection],
            reopenMany: [null, routes.reopenMany],
            approveMany: [null, routes.approveMany],
            snagShow: ['__SNAG__', routes.snagShow],
            snagUpdate: ['__SNAG__', routes.snagUpdate],
            snagDestroy: ['__SNAG__', routes.snagDestroy],
            snagPhoto: ['__SNAG__', routes.snagPhoto],
            snagReport: ['__SNAG__', routes.snagReport],
            snagApprove: ['__SNAG__', routes.snagApprove],
            snagReject: ['__SNAG__', routes.snagReject],
            place: ['__AREA__', routes.place],
        };
        const pair = map[name];
        if (!pair || !pair[1]) {
            return '';
        }
        if (!pair[0] || id == null) {
            return pair[1];
        }
        return pair[1].replace(pair[0], String(id));
    }

    function pickedList() {
        return [...pickedIds].filter((id) => Number.isFinite(id) && id > 0);
    }

    function areaById(id) {
        return areas.find((area) => Number(area.id) === Number(id));
    }

    function syncBoardOverlayTop() {
        const toolbar = document.querySelector('.draw-toolbar');
        if (!toolbar) {
            return;
        }
        if (!window.matchMedia('(max-width: 1100px)').matches) {
            root.style.removeProperty('--board-overlay-top');

            return;
        }
        const offset = Math.max(0, Math.round(toolbar.getBoundingClientRect().bottom - root.getBoundingClientRect().top));
        root.style.setProperty('--board-overlay-top', `${offset}px`);
    }

    function applyTransform() {
        world.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
        document.getElementById('draw-zoom-label').textContent = Math.round(scale * 100) + '%';
        document.querySelectorAll('.status-badge').forEach((badge) => {
            badge.style.transform = badgeTransform();
        });
        document.querySelectorAll('.room-focus-bar').forEach((bar) => {
            bar.style.transform = focusBarTransform();
        });
        document.querySelectorAll('.room-name-overlay').forEach((label) => {
            label.style.transform = nameOverlayTransform();
        });
        document.querySelectorAll('.room-measure-chip').forEach((chip) => {
            chip.style.transform = measureChipTransform();
        });
        document.querySelectorAll('.snag-pin').forEach((pin) => {
            pin.style.transform = snagPinTransform();
        });
        positionSnagPopup();
    }

    function badgeTransform() {
        return `translate(-50%, 0) scale(${1 / Math.max(scale, 0.01)})`;
    }

    function focusBarTransform() {
        return `translate(-50%, -100%) scale(${1 / Math.max(scale, 0.01)})`;
    }

    function snagPinTransform() {
        return `translate(-50%, -50%) scale(${1 / Math.max(scale, 0.01)})`;
    }

    function nameOverlayTransform() {
        return `translate(-50%, -100%) scale(${1 / Math.max(scale, 0.01)})`;
    }

    function measureChipTransform() {
        return `translate(0, -100%) scale(${1 / Math.max(scale, 0.01)})`;
    }

    function setTool(next) {
        tool = next;
        document.getElementById('draw-hand').classList.toggle('is-on', next === 'hand');
        document.getElementById('draw-select').classList.toggle('is-on', next === 'select');
        stage.classList.toggle('is-grab', next === 'hand');
        stage.classList.toggle('is-select', next === 'select');
    }

    function setHint(text, options = {}) {
        if (!hint) {
            return;
        }
        hint.replaceChildren();
        if (!text) {
            hint.classList.add('hidden');
            return;
        }
        const label = document.createElement('span');
        label.textContent = text;
        hint.append(label);
        if (options.actionLabel && typeof options.onAction === 'function') {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'draw-hint-action';
            button.textContent = options.actionLabel;
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                options.onAction();
            });
            hint.append(button);
        }
        hint.classList.remove('hidden');
    }

    function workSaveError(error, fallback) {
        const message = String(error?.message || '');
        if (/unauthorized/i.test(message)) {
            return 'Je mag deze voortgang niet opslaan.';
        }
        return message || fallback;
    }

    function setModes({ snag = false, move = false } = {}) {
        snagMode = snag;
        moveMode = move;
        document.getElementById('draw-snag')?.classList.toggle('is-on', snagMode);
        stage.classList.toggle('is-snag', snagMode || moveMode);
        if (snagMode || moveMode) {
            roomMeasureMode = false;
            closeRoomMeasurePanel();
            closeRoomProgressPanel();
        }
        if (snagMode) {
            setHint('Tik op de exacte plek van het opleverpunt.');
        } else if (moveMode) {
            setHint('Sleep de marker of tik op de nieuwe plek.');
        } else if (roomMeasureMode) {
            setHint('Tik ruimtes aan op de tekening, of sleep om te verschuiven. Daarna kies je rechts de werkzaamheid en wie het werk uitvoerde.');
        } else {
            setHint('');
        }
        syncRoomMeasureMode();
        renderMarkers();
    }

    function uniqueName(area) {
        return String(area?.unique_name || area?.name || '').trim();
    }

    function roomTitle(area) {
        return `${area?.number || ''} ${uniqueName(area)}`.trim() || area?.label || '';
    }

    function areaLabelText(area) {
        return `${area.number || ''} ${area.name || ''}`.trim() || area.label || area.marker?.label_text || '';
    }

    function selectedFloorName() {
        const option = pageSelect?.selectedOptions[0];
        if (option?.dataset.floor) {
            return option.dataset.floor;
        }
        const text = option?.textContent || '';
        const match = text.match(/^(.*)\s+\(\d+\/\d+\)$/);
        if (match && !text.startsWith('Pagina ')) {
            return match[1].trim();
        }

        return '';
    }

    function rowWorkKeys(row) {
        const fromRow = (row.dataset.works || '').split(',').map((key) => key.trim()).filter(Boolean);
        if (fromRow.length) {
            return fromRow;
        }

        return (areaById(Number(row.dataset.areaId))?.works || []).map((work) => work.key);
    }

    function hasWorkFilter() {
        return workFilterKeys.length > 0;
    }

    function areaMatchesWork(area, row = null) {
        return areaMatchesWorkKeys(area, workFilterKeys, row ? rowWorkKeys(row) : null);
    }

    function areaOnSelectedFloor(area, row = null) {
        const floor = selectedFloorName();
        if (floor) {
            const rowFloor = row?.dataset.floor || area?.floor || '';

            return rowFloor === floor;
        }

        return areaOnCurrentPage(area);
    }

    function areaOnCurrentPage(area) {
        if (!area) {
            return false;
        }
        const areaPage = area.marker?.page ?? area.page;
        if (areaPage != null && areaPage !== '') {
            return Number(areaPage) === Number(page);
        }
        const floor = selectedFloorName();

        return floor !== '' && (area.floor || '') === floor;
    }

    function areaIsFilteredOut(area) {
        return hasWorkFilter() && !areaMatchesWork(area);
    }

    function matchingAreas() {
        return areas.filter((area) => {
            if (!areaMatchesWork(area)) {
                return false;
            }
            if (ticketMode || !hasWorkFilter()) {
                return true;
            }

            return areaOnSelectedFloor(area);
        });
    }

    function floorAreas() {
        if (ticketMode) {
            return areas;
        }

        return areas.filter((area) => areaOnSelectedFloor(area));
    }

    function applyRoomFilters(options = {}) {
        const tone = document.querySelector('.room-filter.is-on')?.dataset.filter || 'all';
        document.querySelectorAll('.room-row').forEach((row) => {
            const area = areaById(Number(row.dataset.areaId));
            const toneOk = tone === 'all' || row.dataset.tone === tone;
            const workOk = areaMatchesWork(area, row);
            const floorOk = ticketMode || !hasWorkFilter() || areaOnSelectedFloor(area, row);
            row.style.display = toneOk && workOk && floorOk ? '' : 'none';
        });
        prunePickedToFilter();
        document.querySelectorAll('.floor-head').forEach((head) => {
            let any = false;
            let el = head.nextElementSibling;
            while (el && !el.classList.contains('floor-head')) {
                if (el.classList.contains('room-row') && el.style.display !== 'none') {
                    any = true;
                    break;
                }
                el = el.nextElementSibling;
            }
            head.style.display = any ? '' : 'none';
        });
        document.querySelectorAll('.floor-pick').forEach((button) => {
            button.classList.toggle('hidden', hasWorkFilter() && !ticketMode);
        });
        const pickAll = document.getElementById('pick-all-rooms');
        if (pickAll) {
            pickAll.textContent = hasWorkFilter() && !ticketMode ? 'Alles aanvinken' : 'Hele werk';
        }
        document.querySelector('.board-left')?.classList.toggle('is-work-filter', hasWorkFilter());
        if (pickWorkRoomsBtn) {
            pickWorkRoomsBtn.classList.toggle('hidden', !hasWorkFilter());
        }
        refreshFilterCounts();
        if (!options.skipMarkers) {
            renderMarkers();
        }
    }

    function prunePickedToFilter() {
        if (!hasWorkFilter()) {
            return;
        }
        const visible = new Set(visibleRoomIds());
        const next = [...pickedIds].filter((id) => visible.has(id));
        if (next.length === pickedIds.size && next.every((id) => pickedIds.has(id))) {
            return;
        }
        if (next.length === 0) {
            pickedIds = new Set();
            selectedId = 0;
            root.dataset.selected = '';
            highlightList();
            return;
        }
        pickedIds = new Set(next);
        if (!pickedIds.has(selectedId)) {
            selectedId = next[0];
            root.dataset.selected = String(selectedId);
        }
        highlightList();
    }

    function pickWorkGroup() {
        if (ticketMode || !data.canEnterProgress || !hasWorkFilter() || !groupsEl) {
            return;
        }
        groupsEl.querySelectorAll('.work-group').forEach((card) => {
            const key = card.querySelector('.group-head')?.dataset.group;
            const shouldPick = workFilterKeys.includes(key) && !card.classList.contains('is-done');
            if (shouldPick && !card.classList.contains('is-picked')) {
                card.classList.add('is-picked');
                const check = card.querySelector('.task-check');
                if (check) {
                    check.textContent = '✓';
                }
                updateCardStatus(card);
            } else if (!shouldPick && card.classList.contains('is-picked')) {
                unpickCard(card);
            }
        });
        refreshCompleteForm();
    }

    function appendSelectionMark(area, box) {
        const areaId = Number(area.id);
        if (areaIsFilteredOut(area) || (!pickedIds.has(areaId) && areaId !== selectedId) || !box) {
            return;
        }
        if (storedJumpTarget(area)) {
            return;
        }
        const text = roomTitle(area) || areaLabelText(area);
        const bar = selectionBarBox(box, text);
        const mark = document.createElement('div');
        mark.className = areaId === selectedId ? 'room-focus-bar' : 'room-focus-bar is-extra';
        mark.textContent = text;
        mark.style.left = `${(bar.x + bar.w / 2) * 100}%`;
        mark.style.top = `${(bar.y + bar.h) * 100}%`;
        mark.style.transform = focusBarTransform();
        mark.dataset.areaId = String(area.id);
        mark.title = text;
        markersEl.append(mark);
    }

    function appendNameOverlay(area, box) {
        if (!box) {
            return;
        }
        const areaId = Number(area.id);
        const text = uniqueName(area) || areaLabelText(area);
        if (!text) {
            return;
        }
        const mark = document.createElement('button');
        mark.type = 'button';
        mark.className = 'room-name-overlay';
        if (areaIsFilteredOut(area)) {
            mark.classList.add('is-filtered-out');
        }
        if (areaId === selectedId) {
            mark.classList.add('is-on');
        } else if (pickedIds.has(areaId)) {
            mark.classList.add('is-extra');
        }
        mark.textContent = text;
        mark.dataset.areaId = String(area.id);
        paintNameOverlay(mark, area, areaId === selectedId);
        mark.style.left = `${((Number(box.x) || 0) + (Number(box.w) || 0) / 2) * 100}%`;
        mark.style.top = `${(Number(box.y) || 0) * 100}%`;
        mark.style.transform = nameOverlayTransform();
        mark.title = [text, area.m2_label].filter(Boolean).join(' · ');
        mark.setAttribute('aria-label', mark.title || text);
        mark.addEventListener('pointerdown', (event) => {
            event.stopPropagation();
        });
        mark.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            hideTip();
            if (roomMeasureMode) {
                toggleMeasuredRoom(area.id);
                return;
            }
            selectArea(area.id, { fromPin: true });
        });
        mark.addEventListener('mouseenter', (event) => showTip(area, event));
        mark.addEventListener('mousemove', (event) => showTip(area, event));
        mark.addEventListener('mouseleave', hideTip);
        markersEl.append(mark);
    }

    function paintNameOverlay(mark, area, selected) {
        const hex = area.material_color;
        if (!hex) {
            return;
        }
        const contrast = overlayContrast(hex);
        mark.style.background = contrast.bg;
        mark.style.color = contrast.fg;
        mark.style.borderColor = selected ? '#1c1917' : 'transparent';
        mark.style.boxShadow = selected
            ? '0 0 0 1px #fff, 0 0 0 3px #1c1917'
            : '0 1px 2px rgba(0, 0, 0, 0.16)';
    }

    function overlayContrast(hex) {
        const value = String(hex || '').replace('#', '');
        if (!/^[0-9a-f]{6}$/i.test(value)) {
            return { bg: hex, fg: '#1c1917' };
        }
        const red = parseInt(value.slice(0, 2), 16);
        const green = parseInt(value.slice(2, 4), 16);
        const blue = parseInt(value.slice(4, 6), 16);
        const luma = ((0.299 * red) + (0.587 * green) + (0.114 * blue)) / 255;

        return {
            bg: `#${value}`,
            fg: luma > 0.62 ? '#1c1917' : '#fff',
        };
    }

    function appendRoomMeasureShape(area) {
        if (!roomMeasureMode) {
            return;
        }
        const areaId = Number(area.id);
        const selected = pickedIds.has(areaId);
        if (!selected && areaIsFilteredOut(area)) {
            return;
        }
        const contour = roomVisualContour(area);
        if (!contour) {
            return;
        }
        let shape;
        if (contour.type === 'polygon') {
            shape = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
            shape.setAttribute('points', contour.points.map((point) => `${point.x},${point.y}`).join(' '));
        } else {
            shape = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            shape.setAttribute('x', String(contour.box.x));
            shape.setAttribute('y', String(contour.box.y));
            shape.setAttribute('width', String(contour.box.w));
            shape.setAttribute('height', String(contour.box.h));
        }
        shape.setAttribute('class', `room-label room-measure-shape${selected ? ' is-on' : ''}`);
        shape.dataset.areaId = String(area.id);
        if (!roomMeasureMode) {
            shape.style.pointerEvents = 'none';
        }
        shape.addEventListener('mouseenter', (event) => showTip(area, event));
        shape.addEventListener('mousemove', (event) => showTip(area, event));
        shape.addEventListener('mouseleave', hideTip);
        hitEl.append(shape);
        if (selected) {
            appendRoomMeasureChip(area, contour);
        }
    }

    function appendRoomMeasureChip(area, contour) {
        const box = contourBox(contour);
        if (!box) {
            return;
        }
        const chip = document.createElement('div');
        chip.className = 'room-measure-chip';
        chip.textContent = roomMeasureChipLabel(area);
        chip.style.left = `${Number(box.x) * 100}%`;
        chip.style.top = `${Number(box.y) * 100}%`;
        chip.style.transform = measureChipTransform();
        chip.dataset.areaId = String(area.id);
        markersEl.append(chip);
    }

    function appendLabelRect(area, box, extraClass = '') {
        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('x', String(box.x));
        rect.setAttribute('y', String(box.y));
        rect.setAttribute('width', String(box.w));
        rect.setAttribute('height', String(box.h));
        const filtered = areaIsFilteredOut(area) ? ' is-filtered-out' : '';
        rect.setAttribute('class', `room-label${extraClass}${filtered}`);
        rect.dataset.areaId = String(area.id);
        rect.addEventListener('mouseenter', (event) => showTip(area, event));
        rect.addEventListener('mousemove', (event) => showTip(area, event));
        rect.addEventListener('mouseleave', hideTip);
        hitEl.append(rect);
    }

    function hitAsMarker(hit) {
        return {
            x: hit.x,
            y: hit.y,
            width: hit.w,
            height: hit.h,
            tw: hit.tw,
            th: hit.th,
            label_text: hit.text || hit.label_text,
        };
    }

    function appendProgressMark(area, box) {
        const marks = drawingMarks(area);
        if (!marks.length || !box) {
            return;
        }
        const text = areaLabelText(area);
        const visual = labelVisualBox(box, text);
        const wrap = document.createElement('button');
        wrap.type = 'button';
        wrap.className = `status-badge${Number(area.id) === selectedId ? ' is-selected' : ''}${pickedIds.has(Number(area.id)) ? ' is-picked' : ''}${areaIsFilteredOut(area) ? ' is-filtered-out' : ''}`;
        wrap.style.left = `${(visual.x + visual.w / 2) * 100}%`;
        wrap.style.top = `calc(${(visual.y + visual.h) * 100}% + 1px)`;
        wrap.style.transform = badgeTransform();
        wrap.dataset.areaId = String(area.id);
        wrap.title = marks.map((mark) => mark.title).filter(Boolean).join(' · ') || text;
        wrap.setAttribute('aria-label', wrap.title || 'Ruimte openen');
        marks.forEach((mark) => {
            const pin = document.createElement('span');
            pin.className = `status-mark kind-${String(mark.color || 'overige').replace(/[^a-z0-9-]/g, '')}${mark.provisional ? ' is-provisional' : ''}`;
            pin.textContent = mark.text;
            pin.title = mark.title || '';
            wrap.append(pin);
        });
        wrap.addEventListener('pointerdown', (event) => {
            event.stopPropagation();
        });
        wrap.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            hideTip();
            if (roomMeasureMode) {
                toggleMeasuredRoom(area.id);
                return;
            }
            if (event.ctrlKey || event.metaKey || pickedIds.size > 1) {
                addPickedRoom(area.id, { focus: true });
                return;
            }
            selectArea(area.id, { fromPin: true });
        });
        wrap.addEventListener('mouseenter', (event) => showTip(area, event));
        wrap.addEventListener('mousemove', (event) => showTip(area, event));
        wrap.addEventListener('mouseleave', hideTip);
        markersEl.append(wrap);
    }

    function areaForLabelHit(hit) {
        const number = normalizeRoomNumber(hit.number || '');
        if (!number) {
            return null;
        }
        const candidates = areas.filter((area) => normalizeRoomNumber(area.number) === number);
        if (candidates.length === 0) {
            return null;
        }
        const named = candidates.filter((area) => nameMatches(area.name, hit.text || hit.label_text || ''));
        if (named.length === 1) {
            return named[0];
        }
        if (named.length > 1) {
            return named.find((area) => Number(area.marker?.page) === Number(hit.page)) || named[0];
        }
        if (candidates.length === 1) {
            return candidates[0];
        }

        return null;
    }

    function clickableRooms() {
        const rooms = [];
        const fromText = new Set();
        textHits.filter((hit) => Number(hit.page) === page).forEach((hit) => {
            const area = areaForLabelHit(hit);
            if (!area || areaIsFilteredOut(area)) {
                return;
            }
            const box = clickBox(area, hitAsMarker(hit));
            if (!box) {
                return;
            }
            rooms.push({ id: area.id, box });
            fromText.add(area.id);
        });
        uniqueAreasOnPage(areas, page).forEach((area) => {
            if (fromText.has(area.id) || areaIsFilteredOut(area)) {
                return;
            }
            const box = clickBox(area);
            if (box) {
                rooms.push({ id: area.id, box });
            }
        });

        return rooms;
    }

    function updatePageOptions() {
        const labels = {};
        areas.forEach((area) => {
            if (!area.marker) {
                return;
            }
            const key = area.marker.page;
            const floor = area.floor || 'Pagina';
            labels[key] = labels[key] || {};
            labels[key][floor] = (labels[key][floor] || 0) + 1;
        });
        pageSelect.innerHTML = '';
        for (let n = 1; n <= pageCount; n += 1) {
            const option = document.createElement('option');
            option.value = String(n);
            const floors = labels[n] ? Object.entries(labels[n]).sort((a, b) => b[1] - a[1]) : [];
            option.textContent = floors.length
                ? `${floors[0][0]} (${n}/${pageCount})`
                : `Pagina ${n}/${pageCount}`;
            if (floors.length) {
                option.dataset.floor = floors[0][0];
            }
            pageSelect.append(option);
        }
        pageSelect.value = String(page);
        if (hasWorkFilter()) {
            applyRoomFilters({ skipMarkers: true });
        }
    }

    function hideTip() {
        if (!tipEl) {
            return;
        }
        tipEl.classList.add('hidden');
    }

    function showTip(area, event) {
        if (!tipEl || !area) {
            hideTip();
            return;
        }
        tipEl.textContent = [uniqueName(area) || areaLabelText(area), area.m2_label].filter(Boolean).join(' · ');
        const rect = stage.getBoundingClientRect();
        tipEl.style.left = `${event.clientX - rect.left + 12}px`;
        tipEl.style.top = `${event.clientY - rect.top + 12}px`;
        tipEl.classList.remove('hidden');
    }

    function renderMarkers() {
        if (!hitEl || !markersEl) {
            return;
        }
        hitEl.innerHTML = '';
        markersEl.innerHTML = '';
        const showRooms = layerShowsRooms(layer);
        const showSnags = layerShowsSnags(layer);

        if (showRooms) {
            uniqueAreasOnPage(areas, page).forEach((area) => {
                appendRoomMeasureShape(area);
            });
            const marked = new Set();
            // Geselecteerde/picked ruimtes: ALTIJD opgeslagen marker/contour van dat area-id.
            areas.forEach((area) => {
                const areaId = Number(area.id);
                if ((!pickedIds.has(areaId) && areaId !== selectedId) || Number(area.marker?.page) !== page) {
                    return;
                }
                const target = storedJumpTarget(area);
                const box = target?.box || displayBox(area);
                if (!box) {
                    return;
                }
                appendSelectionMark(area, box);
                appendLabelRect(area, box);
                appendProgressMark(area, box);
                marked.add(areaId);
            });
            textHits.filter((hit) => Number(hit.page) === page).forEach((hit) => {
                const area = areaForLabelHit(hit);
                if (!area || marked.has(Number(area.id))) {
                    return;
                }
                const nameBox = clickBox(area, hitAsMarker(hit));
                if (!nameBox) {
                    return;
                }
                appendSelectionMark(area, nameBox);
                appendLabelRect(area, nameBox);
                appendProgressMark(area, nameBox);
                marked.add(area.id);
            });
            uniqueAreasOnPage(areas, page).forEach((area) => {
                if (marked.has(area.id)) {
                    return;
                }
                const box = clickBox(area);
                if (!box) {
                    return;
                }
                appendSelectionMark(area, box);
                appendLabelRect(area, box);
                appendProgressMark(area, box);
            });
            areas.forEach((area) => {
                const target = storedJumpTarget(area);
                if (!target || Number(target.page) !== page || !target.box) {
                    return;
                }
                appendNameOverlay(area, target.box);
            });
        }

        if (showSnags) {
            snags.forEach((snag) => appendSnagPin(snag));
            if (pendingSnag && Number(pendingSnag.drawing_page || pendingSnag.page) === page) {
                appendSnagPin({
                    id: 'pending',
                    number: pendingSnag.number,
                    x: pendingSnag.x,
                    y: pendingSnag.y,
                    page,
                    tone: 'open',
                }, true);
            }
        }
        positionSnagPopup();
    }

    function snagPage(snag) {
        return Number(snag.page ?? snag.drawing_page);
    }

    function appendSnagPin(snag, preview = false) {
        if (snag.x == null || snag.y == null || snagPage(snag) !== page) {
            return;
        }
        const button = document.createElement('button');
        button.type = 'button';
        const selected = !preview && (
            Number(snag.id) === Number(selectedSnagId)
            || Number(snag.id) === Number(editingSnagId)
            || Number(snag.id) === Number(popupSnagId)
        );
        button.className = `snag-pin tone-${snag.tone || 'open'}${preview ? ' is-preview' : ''}${selected ? ' is-on' : ''}`;
        button.style.left = `${snag.x * 100}%`;
        button.style.top = `${snag.y * 100}%`;
        button.style.transform = snagPinTransform();
        button.textContent = String(snag.number);
        button.dataset.snagId = String(snag.id);
        button.title = `Opleverpunt #${snag.number}`;
        if (!preview) {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (moveMode) {
                    return;
                }
                const id = Number(button.dataset.snagId);
                if (!id) {
                    return;
                }
                openSnagPopup(id);
            });
            button.addEventListener('pointerdown', (event) => {
                if (!moveMode || Number(snag.id) !== Number(editingSnagId) || event.button !== 0) {
                    return;
                }
                event.stopPropagation();
                event.preventDefault();
                draggingSnag = {
                    id: snag.id,
                    pointerId: event.pointerId,
                };
                button.setPointerCapture(event.pointerId);
            });
        }
        markersEl.append(button);
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[char]));
    }

    function highlightList() {
        document.querySelectorAll('.room-row').forEach((row) => {
            const id = Number(row.dataset.areaId);
            const area = areaById(id);
            row.classList.toggle('is-on', id === selectedId);
            row.classList.toggle('is-picked', pickedIds.has(id));
            row.classList.toggle('is-measured', roomMeasureMode && pickedIds.has(id));
            if (area) {
                row.dataset.tone = area.tone;
                if (Array.isArray(area.works)) {
                    row.dataset.works = area.works.map((work) => work.key).join(',');
                }
                if (area.page != null) {
                    row.dataset.page = String(area.page);
                }
                if (area.material_color) {
                    row.style.setProperty('--material-color', area.material_color);
                    if (area.material_color_soft) {
                        row.style.setProperty('--material-color-soft', area.material_color_soft);
                    }
                }
                const num = row.querySelector('.room-num');
                const name = row.querySelector('.room-name');
                if (num) {
                    num.textContent = area.number || '—';
                }
                if (name) {
                    name.textContent = uniqueName(area);
                }
                row.title = `${roomTitle(area)} · ${area.m2_label || ''} · ${area.status_label || ''}`.trim();
                const pill = row.querySelector('.status-pill');
                if (pill) {
                    pill.className = `status-pill tone-${area.tone}`;
                    pill.title = pickedIds.has(id)
                        ? `${area.status_label || ''} · aangevinkt`
                        : `${area.status_label || ''} · tik om extra aan te vinken`;
                }
            }
        });
        refreshPickedCount();
    }

    function refreshPickedCount() {
        const el = document.getElementById('picked-count');
        if (!el) {
            return;
        }
        const count = pickedIds.size;
        el.hidden = count < 2;
        el.textContent = count < 2 ? '' : ` · ${count} aangevinkt`;
    }

    function centerOn(area) {
        // Alias: navigatie gebruikt uitsluitend opgeslagen geometry (geen tekst/naam-zoek).
        focusOnRoom(area);
    }

    function focusOnRoom(area) {
        const target = storedJumpTarget(area);
        if (!target) {
            return false;
        }
        const rect = stage.getBoundingClientRect();
        const size = worldSize();
        const view = focusViewport(
            target.box,
            rect.width,
            rect.height,
            size.width,
            size.height,
        );
        scale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, view.scale));
        panX = view.panX;
        panY = view.panY;
        applyTransform();
        logRoomJump(area, target);

        return true;
    }

    function worldSize() {
        return {
            width: world.offsetWidth || Number.parseFloat(world.style.width) || 1,
            height: world.offsetHeight || Number.parseFloat(world.style.height) || 1,
        };
    }

    function logRoomJump(area, target) {
        const row = {
            project_area_id: Number(area?.id) || null,
            naam: `${area?.number || ''} ${area?.name || ''}`.trim(),
            floor: area?.floor || null,
            drawing_page: target?.page ?? null,
            geometry_id: target?.geometry ?? null,
            bbox: target?.box ?? null,
            jump_target: target
                ? { page: target.page, x: target.box.x, y: target.box.y, w: target.box.w, h: target.box.h }
                : null,
        };
        lastJumpDebug = row;
        if (window?.console?.debug) {
            console.debug('[room-jump]', row);
        }
        renderJumpDebug();
    }

    function renderJumpDebug() {
        const box = document.getElementById('draw-debug');
        if (!box || !lastJumpDebug) {
            return;
        }
        let line = box.querySelector('[data-jump-debug]');
        if (!line) {
            line = document.createElement('p');
            line.dataset.jumpDebug = '1';
            line.className = 'text-xs text-nicon-muted mt-2';
            box.append(line);
        }
        const j = lastJumpDebug;
        line.textContent = j.jump_target
            ? `Jump: area#${j.project_area_id} | ${j.naam} | ${j.floor || '—'} | pagina ${j.drawing_page} | ${j.geometry_id} | target ${JSON.stringify(j.jump_target)}`
            : `Jump: area#${j.project_area_id} | ${j.naam} | Positie op tekening niet bekend`;
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
        const size = pageSize(pdfPage);
        const context = canvas.getContext('2d', { alpha: false });
        canvas.width = Math.max(1, Math.floor(renderViewport.width));
        canvas.height = Math.max(1, Math.floor(renderViewport.height));
        canvas.classList.remove('hidden');
        image.classList.add('hidden');
        world.style.width = `${cssViewport.width}px`;
        world.style.height = `${cssViewport.height}px`;
        context.setTransform(1, 0, 0, 1, 0, 0);
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
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
        renderInfo = {
            scale: renderScale,
            dpi: PDF_DPI * renderScale,
            width: canvas.width,
            height: canvas.height,
            cssWidth: cssViewport.width,
            cssHeight: cssViewport.height,
            pdfWidthPt: size.widthPt,
            pdfHeightPt: size.heightPt,
        };
        if (pageDebug[page]) {
            pageDebug[page] = { ...pageDebug[page], render: renderInfo };
        }
        renderDebugPanel();
        renderMarkers();
    }

    function knownRoomNumbers() {
        const numbers = [];
        areas.forEach((area) => {
            [area.number, area.number_raw].forEach((value) => {
                if (value) {
                    numbers.push(value);
                }
            });
        });

        return numbers;
    }

    function roomsPayload(hits) {
        return hits.map((hit) => ({
            number: hit.number,
            page: hit.page,
            x: hit.x,
            y: hit.y,
            w: hit.w,
            h: hit.h,
            width: hit.w,
            height: hit.h,
            label_text: String(hit.label_text || hit.text || '').slice(0, 160),
            confidence: hit.confidence,
            source: hit.source || 'text',
        }));
    }

    async function extractAndDetect() {
        if (!pdfDoc || !routes.detect) {
            return;
        }
        const known = knownRoomNumbers();
        const items = [];
        const rooms = [];
        const textNumbers = [];
        const ocrNumbers = [];
        setHint('Tekstlaag van de PDF uitlezen…');
        for (let n = 1; n <= pdfDoc.numPages; n += 1) {
            const pdfPage = await pdfDoc.getPage(n);
            const viewport = pdfPage.getViewport({ scale: 1 });
            const size = pageSize(pdfPage);
            const content = await pdfPage.getTextContent();
            const pageItems = extractPageTextItems(content, viewport, n);
            const layer = assessTextLayer(pageItems, known);
            items.push(...pageItems);
            rooms.push(...layer.hits);
            textNumbers.push(...layer.numbers);
            pageDebug[n] = {
                page: n,
                pdf: size,
                viewport: { width: viewport.width, height: viewport.height },
                textLayer: {
                    present: layer.present,
                    usable: layer.usable,
                    items: layer.itemCount,
                    numbers: layer.numbers,
                },
                ocr: { used: false, numbers: [], dpi: OCR_DPI },
                hits: layer.hits,
                render: n === page ? renderInfo : null,
            };
        }
        const overall = assessTextLayer(items, known);
        if (!overall.usable) {
            setHint('Tekstlaag onvoldoende; OCR op ongeveer 300 DPI…');
            for (let n = 1; n <= pdfDoc.numPages; n += 1) {
                const pdfPage = await pdfDoc.getPage(n);
                const found = rooms.map((hit) => hit.number);
                try {
                    const ocr = await ocrMissingRooms(pdfPage, n, known, found);
                    if (!ocr.skipped) {
                        rooms.push(...ocr.hits);
                        items.push(...ocr.items);
                        ocrNumbers.push(...ocr.hits.map((hit) => hit.number));
                        pageDebug[n] = {
                            ...pageDebug[n],
                            ocr: {
                                used: true,
                                numbers: ocr.hits.map((hit) => hit.number),
                                dpi: ocr.dpi,
                                scale: ocr.scale,
                                width: ocr.width,
                                height: ocr.height,
                            },
                            hits: [...(pageDebug[n]?.hits || []), ...ocr.hits],
                        };
                    }
                } catch (error) {
                    pageDebug[n] = {
                        ...pageDebug[n],
                        ocr: { used: false, numbers: [], error: 'OCR mislukt', dpi: OCR_DPI },
                    };
                }
            }
        }
        textHits = rooms;
        renderMarkers();
        renderDebugPanel();
        const response = await fetch(routes.detect, {
            method: 'POST',
            headers: { ...headers, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                items: items.slice(0, 8000),
                rooms: roomsPayload(rooms),
            }),
        });
        if (!response.ok) {
            setHint('Kon ruimtenummers niet koppelen aan de meetstaat.');
            return;
        }
        const payload = await response.json();
        detectReviewIds = (payload.review_ids || []).map((id) => Number(id));
        if (Array.isArray(payload.areas)) {
            payload.areas.forEach((fresh) => {
                mergeAreaFromServer(fresh);
            });
        }
        payload.text_numbers = [...new Set(textNumbers)];
        payload.ocr_numbers = [...new Set(ocrNumbers)];
        renderMatchLog(payload);
        renderDebugPanel();
        updatePageOptions();
        const targetId = selectedId;
        const selected = areaById(targetId);
        const jump = selected ? storedJumpTarget(selected) : null;
        if (jump && !ticketMode) {
            page = Number(jump.page) || page;
            pageSelect.value = String(page);
            await renderPdfPage();
            if (selectedId !== targetId) {
                return;
            }
            focusOnRoom(areaById(selectedId) || selected);
            renderMarkers();
            if (!snagMode && !moveMode && !linkModeAreaId) {
                setHint(linkReportHint(payload));
            }
        } else {
            renderMarkers();
            if (ticketMode) {
                setHint('Kies materialen of klik Hele werk. Daarna Selectie toevoegen. Wissel van pagina voor een andere verdiepingstekening.');
            } else if (selected && selectedId === targetId && !snagMode && !moveMode && !linkModeAreaId) {
                showUnlinkedHint(selected);
            } else if (!snagMode && !moveMode && !linkModeAreaId) {
                setHint(linkReportHint(payload));
            }
        }
    }

    function mergeAreaFromServer(fresh) {
        const current = areaById(fresh.id);
        if (!current) {
            return;
        }
        // Opgeslagen import-geometry niet laten overschrijven door live tekst/OCR-rematch op naam.
        const keepMarker = hasReliableRoomPosition(current.marker);
        const nextMarker = keepMarker ? current.marker : (fresh.marker ?? current.marker);
        Object.assign(current, fresh, {
            marker: nextMarker,
            has_position: keepMarker || Boolean(fresh.has_position),
            jump_target: keepMarker ? current.jump_target : (fresh.jump_target ?? current.jump_target),
        });
    }

    function formatCoord(value) {
        return value == null || Number.isNaN(Number(value)) ? '—' : Number(value).toFixed(4);
    }

    function renderDebugPanel() {
        const box = document.getElementById('draw-debug');
        if (!box) {
            return;
        }
        const debug = pageDebug[page] || {
            page,
            pdf: { widthPt: renderInfo.pdfWidthPt, heightPt: renderInfo.pdfHeightPt },
            textLayer: { present: null, usable: null, items: 0, numbers: [] },
            ocr: { used: false, numbers: [] },
            hits: [],
            render: renderInfo,
        };
        const render = debug.render || renderInfo;
        const textNumbers = debug.textLayer?.numbers || [];
        const ocrNumbers = debug.ocr?.numbers || [];
        const sample = ['0.07', '0.09', '0.12', '0.19a']
            .map((number) => {
                const hit = (debug.hits || []).find((row) => row.number === number);
                if (!hit) {
                    return `<li><code>${number}</code> niet op deze pagina in de tekstlaag</li>`;
                }
                return `<li><code>${number}</code> ${escapeHtml(hit.source === 'ocr' ? 'OCR' : 'tekstlaag')} x=${formatCoord(hit.x)} y=${formatCoord(hit.y)} <span>(${escapeHtml(hit.text || '')})</span></li>`;
            })
            .join('');
        const presentLabel = debug.textLayer?.present == null
            ? 'nog bezig'
            : (debug.textLayer.present ? 'ja' : 'nee');
        const usableLabel = debug.textLayer?.present
            ? (debug.textLayer.usable ? 'bruikbaar' : 'onvoldoende')
            : 'niet aanwezig';
        box.innerHTML = `
            <summary>PDF-debug pagina ${page}</summary>
            <dl>
                <div><dt>Oorspronkelijke PDF</dt><dd>${Math.round(debug.pdf?.widthPt || render.pdfWidthPt || 0)} × ${Math.round(debug.pdf?.heightPt || render.pdfHeightPt || 0)} pt</dd></div>
                <div><dt>Renderresolutie</dt><dd>${render.width || 0} × ${render.height || 0} px</dd></div>
                <div><dt>DPI / schaal</dt><dd>${Math.round(render.dpi || 0)} DPI · ${Number(render.scale || 0).toFixed(2)}× (PDF = ${PDF_DPI} DPI)</dd></div>
                <div><dt>Tekstlaag</dt><dd>${presentLabel} · ${usableLabel} · ${debug.textLayer?.items || 0} tekstobjecten</dd></div>
                <div><dt>Uit tekstlaag</dt><dd>${textNumbers.length ? textNumbers.join(', ') : '—'}</dd></div>
                <div><dt>Alleen via OCR</dt><dd>${debug.ocr?.used ? (ocrNumbers.length ? ocrNumbers.join(', ') : 'geen extra nummers') : 'niet gebruikt'}</dd></div>
            </dl>
            <p>Voorbeeldcoördinaten (genormaliseerd 0–1)</p>
            <ul>${sample}</ul>
        `;
    }

    function renderMatchLog(payload) {
        const body = document.getElementById('draw-matches-body');
        const unmatchedEl = document.getElementById('draw-unmatched');
        const countEl = document.getElementById('draw-matches-count');
        if (!body) {
            return;
        }
        const rows = payload.matches || [];
        body.innerHTML = rows.map((row) => {
            const x = row.x == null ? '—' : Number(row.x).toFixed(3);
            const y = row.y == null ? '—' : Number(row.y).toFixed(3);
            const via = row.found_via === 'ocr' ? 'OCR' : (row.found_via === 'text' ? 'tekstlaag' : (row.source === 'manual' ? 'handmatig' : 'auto'));
            return `<li><code>${escapeHtml(row.found_text || '—')}</code> → <strong>${escapeHtml(row.number || '')}</strong> ${escapeHtml(row.name || '')} <span>${via} (${x}, ${y})</span></li>`;
        }).join('') || '<li>Nog geen kamernamen gevonden.</li>';
        if (countEl) {
            countEl.textContent = String(rows.length);
        }
        if (unmatchedEl) {
            const missing = payload.unmatched || [];
            const reviewing = payload.review || [];
            const auto = payload.counts?.auto ?? (payload.saved ?? rows.length);
            unmatchedEl.textContent = [
                `Automatisch gekoppeld: ${auto}`,
                `Handmatig nodig: ${payload.counts?.manual ?? 0}`,
            ].join(' · ');
            unmatchedEl.classList.toggle('hidden', false);
        }
    }

    async function loadDrawing() {
        if (!drawing) {
            return;
        }
        if (drawing.pdf) {
            const loaded = await pdfjsLib.getDocument({ url: drawing.url, withCredentials: true }).promise;
            pdfDoc = loaded;
            pageCount = loaded.numPages;
            updatePageOptions();
            await renderPdfPage();
            extractAndDetect();
            return;
        }
        if (drawing.image) {
            image.src = drawing.url;
            image.classList.remove('hidden');
            canvas.classList.add('hidden');
            image.onload = () => {
                world.style.width = `${image.naturalWidth}px`;
                world.style.height = `${image.naturalHeight}px`;
                renderMarkers();
            };
        }
    }

    async function jumpToStoredRoom(area, options = {}) {
        const current = areaById(area.id) || area;
        const jump = storedJumpTarget(current);
        if (!jump) {
            return false;
        }
        const markerPage = Number(jump.page);
        if (Number.isFinite(markerPage) && markerPage > 0 && markerPage !== page) {
            page = markerPage;
            pageSelect.value = String(page);
            if (pdfDoc && !options.keepPage) {
                await renderPdfPage();
            }
        }
        if (!options.fromPin && !options.keepPage) {
            const focused = focusOnRoom(areaById(area.id) || current);
            renderMarkers();
            if (focused && !snagMode && !moveMode && !linkModeAreaId) {
                setHint('');
            }

            return focused;
        }
        logRoomJump(current, jump);
        renderMarkers();

        return true;
    }

    async function resolveAndJump(area, token, options = {}) {
        const hit = exactRoomHitForArea(area, textHits);
        if (!hit) {
            return false;
        }
        await persistRoomLink(area, hit);
        if (token !== selectToken) {
            return false;
        }

        return jumpToStoredRoom(areaById(area.id) || area, options);
    }

    function markerFromHit(area, hit) {
        const width = Math.max(0.05, Number(hit.w) || Number(hit.width) || 0.08);
        const height = Math.max(0.016, Number(hit.h) || Number(hit.height) || 0.03);
        const x = clamp(Number(hit.x));
        const y = clamp(Number(hit.y));
        const pageNumber = Math.max(1, Number(hit.page) || page);

        return {
            page: pageNumber,
            x,
            y,
            width,
            height,
            label_text: String(hit.label_text || hit.text || areaLabelText(area)).slice(0, 160),
            polygon: [
                { x, y },
                { x: clamp(x + width), y },
                { x: clamp(x + width), y: clamp(y + height) },
                { x, y: clamp(y + height) },
            ],
            source: hit.source === 'manual' ? 'manual' : 'auto',
        };
    }

    function applyLocalLink(area, marker) {
        const current = areaById(area.id) || area;
        const box = {
            x: clamp(marker.x),
            y: clamp(marker.y),
            w: clamp(marker.width || 0.08),
            h: clamp(marker.height || 0.03),
        };
        current.marker = marker;
        current.has_position = true;
        current.jump_target = {
            project_area_id: Number(current.id),
            drawing_marker_id: marker.id ?? null,
            page: marker.page,
            center_x: box.x + (box.w / 2),
            center_y: box.y + (box.h / 2),
            bbox: box,
        };
    }

    async function persistRoomLink(area, hit) {
        const marker = markerFromHit(area, hit);
        applyLocalLink(area, marker);
        if (!routes.place || !drawing?.id) {
            return;
        }
        const width = marker.width;
        const height = marker.height;
        try {
            const response = await fetch(route('place', area.id), {
                method: 'POST',
                headers: { ...headers, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    document_id: drawing.id,
                    page: marker.page,
                    x: marker.x + (width / 2),
                    y: marker.y + (height / 2),
                    width,
                    height,
                    label_text: marker.label_text,
                }),
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            if (payload.area && Number(payload.area.id) === Number(area.id)) {
                applyAreaSummary(area.id, payload.area);
            }
        } catch (error) {
            // Lokale koppeling blijft beschikbaar voor deze sessie.
        }
    }

    function linkReportHint(payload) {
        const counts = payload.counts || {};
        const auto = counts.auto ?? payload.saved ?? 0;

        return `Automatisch gekoppeld: ${auto} · Handmatig nodig: ${counts.manual ?? 0}`;
    }

    function showUnlinkedHint(area) {
        logRoomJump(area, null);
        renderMarkers();
        if (!data.canManuallyLinkRooms) {
            return;
        }
        setHint(
            'Ruimte nog niet gekoppeld aan tekening',
            routes.place
                ? {
                    actionLabel: 'Aanwijzen op tekening',
                    onAction: () => startManualLink(area.id),
                }
                : {},
        );
    }

    function startManualLink(areaId) {
        linkModeAreaId = Number(areaId);
        roomMeasureMode = false;
        setTool('select');
        setHint('Tik de ruimte aan op de tekening.');
        syncRoomMeasureMode();
        renderMarkers();
    }

    async function finishManualLink(point) {
        const area = areaById(linkModeAreaId);
        if (!area) {
            linkModeAreaId = 0;
            return;
        }
        const hit = {
            page,
            x: clamp(point.x - 0.04),
            y: clamp(point.y - 0.015),
            w: 0.08,
            h: 0.03,
            label_text: areaLabelText(area),
            source: 'manual',
        };
        linkModeAreaId = 0;
        await persistRoomLink(area, hit);
        await jumpToStoredRoom(areaById(area.id) || area, {});
        if (!snagMode && !moveMode) {
            setHint('');
        }
    }

    async function selectArea(id, options = {}) {
        const areaId = Number(id);
        if (!Number.isFinite(areaId) || areaId <= 0) {
            return;
        }
        const token = ++selectToken;
        selectedId = areaId;
        pickedIds = new Set([areaId]);
        root.dataset.selected = String(areaId);
        if (linkModeAreaId && linkModeAreaId !== areaId) {
            linkModeAreaId = 0;
        }

        const area = areaById(areaId);
        highlightList();
        scrollListToSelected();
        if (!area) {
            return;
        }

        paintPanelFromSummary(area);

        const jumped = await jumpToStoredRoom(area, options);
        if (token !== selectToken) {
            return;
        }
        if (!jumped && !options.fromPin && !options.keepPage) {
            const resolved = await resolveAndJump(area, token, options);
            if (token !== selectToken) {
                return;
            }
            if (!resolved) {
                showUnlinkedHint(areaById(areaId) || area);
            }
        } else if (!jumped) {
            logRoomJump(area, null);
            renderMarkers();
        }

        try {
            const detail = await fetchArea(areaId);
            if (token !== selectToken || selectedId !== areaId) {
                return;
            }
            if (Number(detail.area?.id) !== areaId) {
                return;
            }
            renderPanel(detail);
            if (pickedIds.size > 1) {
                renderPickedPanel();
            }
        } catch (error) {
            if (token !== selectToken) {
                return;
            }
            setHint('Kon de werkzaamheden van deze ruimte niet laden.');
        }
    }

    function scrollListToSelected() {
        const row = document.querySelector(`.room-row[data-area-id="${selectedId}"]`);
        row?.classList.add('is-on');
        row?.scrollIntoView({ block: 'nearest' });
    }

    function paintPanelFromSummary(area) {
        const panel = document.getElementById('room-panel');
        if (panel) {
            panel.dataset.areaId = String(area.id);
        }
        document.getElementById('room-floor').textContent = area.floor || '';
        document.getElementById('room-title').textContent = roomTitle(area);
        document.getElementById('room-m2').textContent = area.m2_label || '';
        document.getElementById('room-progress-label').textContent = area.progress
            ? `${area.progress} · ${area.status_label || ''}`.trim()
            : (area.status_label || '');
        const percent = area.total ? Math.round((area.done / area.total) * 100) : 0;
        const bar = document.getElementById('room-progress-bar');
        if (bar) {
            bar.style.width = `${percent}%`;
            bar.className = `h-1.5 ${area.tone === 'done' ? 'bg-nicon-ok' : 'bg-nicon-orange'}`;
        }
        if (groupsEl) {
            groupsEl.innerHTML = '';
        }
        const taskName = document.getElementById('complete-task-name');
        if (taskName) {
            taskName.textContent = 'Kies een of meer werkzaamheden';
        }
        const submit = document.getElementById('complete-submit');
        if (submit) {
            submit.disabled = true;
        }
        const qty = document.getElementById('complete-qty');
        if (qty) {
            qty.value = '';
            qty.disabled = false;
        }
    }

    function applyAreaSummary(areaId, summary) {
        const current = areaById(areaId);
        if (!current || !summary || Number(summary.id) !== Number(areaId)) {
            return;
        }
        const keepMarker = hasReliableRoomPosition(current.marker)
            && !hasReliableRoomPosition(summary.marker);
        Object.assign(current, summary, keepMarker
            ? { marker: current.marker, has_position: true, jump_target: current.jump_target || summary.jump_target }
            : {});
    }

    async function fetchArea(id) {
        const response = await fetch(route('area', id), { headers });
        if (!response.ok) {
            throw new Error('Ruimte niet gevonden');
        }
        const detail = await response.json();
        if (Number(detail.area?.id) !== Number(id)) {
            throw new Error('Verkeerde ruimte ontvangen');
        }
        areaDetails[Number(id)] = detail;
        applyAreaSummary(id, detail.area);
        return detail;
    }

    async function fetchAreas(ids) {
        const wanted = ids.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0);
        if (wanted.length === 0) {
            return [];
        }
        if (wanted.length === 1) {
            const detail = await fetchArea(wanted[0]);
            return detail ? [detail] : [];
        }
        const response = await fetch(route('areas'), {
            method: 'POST',
            headers: { ...headers, 'Content-Type': 'application/json' },
            body: JSON.stringify({ area_ids: wanted }),
        });
        if (!response.ok) {
            throw new Error('Ruimtes niet gevonden');
        }
        const payload = await response.json();
        const details = Array.isArray(payload.areas) ? payload.areas : [];
        details.forEach((detail) => {
            const areaId = Number(detail.area?.id);
            if (!areaId) {
                return;
            }
            areaDetails[areaId] = detail;
            applyAreaSummary(areaId, detail.area);
        });
        return details;
    }

    function renderPanel(detail) {
        const area = detail.area;
        if (!area || Number(area.id) !== selectedId) {
            return;
        }
        paintPanelFromSummary(area);
        groupsEl.innerHTML = (detail.groups || []).map((group) => groupCardHtml(group)).join('');
        bindTaskCards();
        pickWorkGroup();
        refreshCompleteForm();
        renderWorkLegend(detail.groups || []);
    }

    function groupCardHtml(group) {
        const worker = group.done ? (group.tasks || []).map((task) => task.worker).find(Boolean) : '';
        const openIds = (group.open_task_ids || []).join(',');
        const doneIds = (group.done_task_ids || []).join(',');
        const ids = (group.done ? (group.task_ids || group.done_task_ids || []) : (group.open_task_ids || [])).join(',');
        const typeBit = group.type_label && !String(group.label || '').includes(group.type_label)
            ? ` · ${escapeHtml(group.type_label)}`
            : '';
        const roomsBit = pickedIds.size > 1
            ? ` · ${group.room_count || 1} ${(group.room_count || 1) === 1 ? 'ruimte' : 'ruimtes'}`
            : '';
        return `
            <section class="work-group ${group.done ? 'is-done' : group.partial ? 'is-partial' : ''}${group.provisional ? ' is-provisional' : ''}" data-kind="${escapeHtml(group.color_key || 'overige')}" style="--material-color: ${escapeHtml(group.display_color || '#9ca3af')}; --work-accent: ${escapeHtml(group.display_color || '#9ca3af')}; --work-bg: ${escapeHtml(group.display_color_soft || 'rgba(156, 163, 175, 0.14)')};">
                <button type="button" class="group-head"${data.canEnterProgress ? '' : ' disabled'} data-group="${escapeHtml(group.key)}" data-label="${escapeHtml(group.label)}" data-task-ids="${escapeHtml(ids)}" data-open-task-ids="${escapeHtml(openIds)}" data-done-task-ids="${escapeHtml(doneIds)}" data-remaining="${group.remaining ?? ''}" data-ordered="${group.ordered ?? ''}" data-unit="${escapeHtml(group.unit || '')}" title="${groupHeadTitle(group)}">
                    <span class="task-check">${group.done ? '✓' : ''}</span>
                    <span class="min-w-0 text-left">
                        <span class="block font-medium"><i class="work-swatch" aria-hidden="true"></i>${escapeHtml(group.label)}</span>
                        <span class="block text-[11px] text-nicon-muted">${escapeHtml(group.progress_label || group.quantity_label || group.tasks?.[0]?.progress_label || group.tasks?.[0]?.quantity_label || '')}${typeBit} · ${escapeHtml(group.status_label)}${worker ? ' · ' + escapeHtml(worker) : ''}${roomsBit}</span>
                    </span>
                    <span class="group-status text-[11px] ${group.done && !group.provisional ? 'text-nicon-ok' : 'text-nicon-muted'}">${escapeHtml(group.status_label)}</span>
                </button>
            </section>`;
    }

    function groupHeadTitle(group) {
        if (!data.canEnterProgress || !group.done) {
            return '';
        }
        if (group.provisional && data.canApproveProgress) {
            return 'Klik om akkoord te geven';
        }
        if (group.provisional) {
            return 'Klik om voorlopig gereed uit te zetten';
        }
        if (data.lockedWorkerId) {
            return 'Definitief werk mag je niet uitzetten';
        }
        return 'Klik om gereed uit te zetten';
    }

    async function togglePickedRoom(id) {
        const areaId = Number(id);
        if (!Number.isFinite(areaId) || areaId <= 0) {
            return;
        }
        if (pickedIds.has(areaId)) {
            if (pickedIds.size === 1) {
                return;
            }
            pickedIds.delete(areaId);
            if (selectedId === areaId) {
                selectedId = pickedList()[0] || 0;
                root.dataset.selected = selectedId ? String(selectedId) : '';
            }
        } else {
            pickedIds.add(areaId);
        }
        highlightList();
        renderMarkers();
        if (ticketMode) {
            syncRoomMeasureMode();
            return;
        }
        if (! (await ensurePickedDetails())) {
            return;
        }
        renderPickedPanel();
    }

    async function addPickedRoom(id, options = {}) {
        const areaId = Number(id);
        if (!Number.isFinite(areaId) || areaId <= 0) {
            return;
        }
        pickedIds.add(areaId);
        if (options.focus || !selectedId) {
            selectedId = areaId;
            root.dataset.selected = String(areaId);
        }
        highlightList();
        renderMarkers();
        if (ticketMode) {
            syncRoomMeasureMode();
            return;
        }
        if (! (await ensurePickedDetails())) {
            return;
        }
        renderPickedPanel();
    }

    async function ensurePickedDetails() {
        const token = ++pickedToken;
        const missing = pickedList().filter((id) => !areaDetails[id]);
        if (missing.length === 0) {
            return token === pickedToken;
        }
        try {
            await fetchAreas(missing);
        } catch (error) {
            setHint('Kon de werkzaamheden van deze ruimtes niet laden.');
            return false;
        }
        return token === pickedToken;
    }

    function visibleRoomIds(floor = null) {
        return [...document.querySelectorAll('.room-row')]
            .filter((row) => {
                if (row.style.display === 'none') {
                    return false;
                }
                if (floor !== null && (row.dataset.floor || '') !== floor) {
                    return false;
                }
                return Number(row.dataset.areaId) > 0;
            })
            .map((row) => Number(row.dataset.areaId));
    }

    async function pickVisibleRooms(floor = null) {
        if (!canPickRooms()) {
            return;
        }
        const ids = ticketMode
            ? ticketRoomsToPick(areas, { keys: workFilterKeys, floor }).map((area) => Number(area.id))
            : visibleRoomIds(floor);
        if (ids.length === 0) {
            return;
        }
        const allPicked = ids.every((id) => pickedIds.has(id));
        if (allPicked) {
            const keep = ids.includes(selectedId) ? selectedId : ids[0];
            pickedIds = new Set([keep]);
            selectedId = keep;
            root.dataset.selected = String(keep);
            highlightList();
            renderMarkers();
            const detail = areaDetails[keep];
            if (detail) {
                renderPanel(detail);
            } else {
                await selectArea(keep);
            }
            setHint('Selectie losgemaakt.');
            return;
        }
        pickedIds = new Set(ids);
        if (!ids.includes(selectedId)) {
            selectedId = ids[0];
            root.dataset.selected = String(selectedId);
        }
        highlightList();
        renderMarkers();
        if (ticketMode) {
            syncRoomMeasureMode();
            const scope = floor
                ? floor
                : (hasWorkFilter() ? `${workFilterKeys.length} onderdelen` : 'hele werk');
            setHint(`${ids.length} ruimtes geselecteerd (${scope}). Klik Selectie toevoegen om ze op de bon te zetten.`);
            return;
        }
        if (! (await ensurePickedDetails())) {
            return;
        }
        renderPickedPanel();
        if (ids.length > 1 && window.matchMedia('(max-width: 1100px)').matches) {
            document.querySelector('.board-right')?.classList.add('is-open');
            document.querySelector('.board-left')?.classList.remove('is-open');
        }
        const scope = floor
            ? floor
            : (hasWorkFilter() ? `${workFilterKeys.length} onderdelen` : 'hele werk');
        setHint(hasWorkFilter()
            ? `${ids.length} ruimtes aangevinkt (${scope}). Onderdeel staat klaar om op te slaan.`
            : `${ids.length} ruimtes aangevinkt (${scope}). Kies egaliseren of een vloertype.`);
    }

    function renderPickedPanel() {
        const ids = pickedList();
        if (ids.length <= 1) {
            const detail = areaDetails[ids[0] || selectedId];
            if (detail) {
                renderPanel(detail);
            } else {
                const area = areaById(ids[0] || selectedId);
                if (area) {
                    paintPanelFromSummary(area);
                }
            }
            return;
        }
        paintMultiSummary(ids);
        const groups = mergeGroupDetails(ids);
        groupsEl.innerHTML = groups.map((group) => groupCardHtml(group)).join('');
        bindTaskCards();
        pickWorkGroup();
        refreshCompleteForm();
        renderWorkLegend(groups);
    }

    function paintMultiSummary(ids) {
        const list = ids.map((id) => areaById(id) || areaDetails[id]?.area).filter(Boolean);
        const floors = [...new Set(list.map((area) => area.floor).filter(Boolean))];
        const panel = document.getElementById('room-panel');
        if (panel) {
            panel.dataset.areaId = String(selectedId || ids[0] || '');
        }
        document.getElementById('room-floor').textContent = floors.length === 1
            ? floors[0]
            : (floors.join(' · ') || 'Meerdere ruimtes');
        document.getElementById('room-title').textContent = `${list.length} ruimtes · dezelfde vakman`;
        document.getElementById('room-m2').textContent = list
            .map((area) => roomTitle(area))
            .filter(Boolean)
            .join(', ');
        const done = list.reduce((sum, area) => sum + (Number(area.done) || 0), 0);
        const total = list.reduce((sum, area) => sum + (Number(area.total) || 0), 0);
        const allDone = list.length > 0 && list.every((area) => area.tone === 'done');
        const allOpen = list.length > 0 && list.every((area) => area.tone === 'open');
        const status = allDone ? 'Gereed' : (allOpen ? 'Open' : 'Deels gereed');
        document.getElementById('room-progress-label').textContent = total
            ? `${done}/${total} · ${status}`
            : status;
        const bar = document.getElementById('room-progress-bar');
        if (bar) {
            bar.style.width = `${total ? Math.round((done / total) * 100) : 0}%`;
            bar.className = `h-1.5 ${allDone ? 'bg-nicon-ok' : 'bg-nicon-orange'}`;
        }
        const taskName = document.getElementById('complete-task-name');
        if (taskName) {
            taskName.textContent = 'Kies dezelfde handeling';
        }
        const submit = document.getElementById('complete-submit');
        if (submit) {
            submit.disabled = true;
        }
        const qty = document.getElementById('complete-qty');
        if (qty) {
            qty.value = '';
            qty.disabled = false;
        }
        if (groupsEl) {
            groupsEl.innerHTML = '';
        }
    }

    function mergeGroupDetails(ids) {
        const merged = new Map();
        ids.forEach((id) => {
            const groups = areaDetails[id]?.groups || [];
            groups.forEach((group) => {
                const key = group.key || group.label;
                const current = merged.get(key);
                if (!current) {
                    merged.set(key, {
                        ...group,
                        open_task_ids: [...(group.open_task_ids || [])],
                        done_task_ids: [...(group.done_task_ids || [])],
                        task_ids: [...(group.task_ids || [])],
                        remaining: Number(group.remaining) || 0,
                        ordered: Number(group.ordered) || 0,
                        completed: Number(group.completed) || 0,
                        room_count: 1,
                    });
                    return;
                }
                current.open_task_ids.push(...(group.open_task_ids || []));
                current.done_task_ids.push(...(group.done_task_ids || []));
                current.task_ids.push(...(group.task_ids || []));
                current.remaining += Number(group.remaining) || 0;
                current.ordered += Number(group.ordered) || 0;
                current.completed += Number(group.completed) || 0;
                current.room_count += 1;
                current.done = Boolean(current.done) && Boolean(group.done);
                current.partial = Boolean(current.partial) || Boolean(group.partial) || current.done !== Boolean(group.done);
                current.provisional = Boolean(current.provisional) || Boolean(group.provisional);
            });
        });
        return [...merged.values()].map((group) => {
            const openIds = [...new Set(group.open_task_ids.filter(Boolean))];
            const doneIds = [...new Set(group.done_task_ids.filter(Boolean))];
            const taskIds = [...new Set(group.task_ids.filter(Boolean))];
            const done = openIds.length === 0 && doneIds.length > 0;
            const partial = !done && doneIds.length > 0;
            const remaining = Math.round((Number(group.remaining) || 0) * 100) / 100;
            const ordered = Math.round((Number(group.ordered) || 0) * 100) / 100;
            const completed = Math.round((Number(group.completed) || (ordered - remaining)) * 100) / 100;
            const unit = group.unit === 'm1' ? 'm¹' : (group.unit === 'm2' ? 'm²' : (group.unit || ''));
            const fmt = (value) => String(value.toFixed(2)).replace('.', ',');
            const progressLabel = `Opdracht ${fmt(ordered)} | Gereed ${fmt(completed)} | Rest ${fmt(remaining)}${unit ? ` ${unit}` : ''}`;
            const provisional = done && Boolean(group.provisional);
            return {
                ...group,
                open_task_ids: openIds,
                done_task_ids: doneIds,
                task_ids: taskIds,
                remaining,
                ordered,
                completed,
                done,
                partial,
                provisional,
                status_label: done ? (provisional ? 'Voorlopig' : 'Gereed') : (partial ? 'Bezig' : 'Open'),
                progress_label: progressLabel,
                quantity_label: progressLabel,
            };
        });
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

    function bindTaskCards() {
        if (!data.canEnterProgress) {
            return;
        }
        groupsEl.querySelectorAll('.group-head').forEach((head) => {
            head.addEventListener('click', () => toggleGroup(head.closest('.work-group')));
        });
        refreshSelectAllButton();
    }

    function unpickCard(card) {
        card.classList.remove('is-picked');
        const check = card.querySelector('.task-check');
        if (check && !card.classList.contains('is-done')) {
            check.textContent = '';
        }
        updateCardStatus(card);
    }

    function updateCardStatus(card) {
        const status = card.querySelector('.group-status');
        if (!status || card.classList.contains('is-done')) {
            return;
        }
        const picked = card.classList.contains('is-picked');
        status.textContent = picked ? 'Geselecteerd' : 'Open';
        status.classList.toggle('text-nicon-ok', picked);
        status.classList.toggle('text-nicon-muted', !picked);
    }

    function toggleGroup(card) {
        if (!data.canEnterProgress || !card) {
            return;
        }
        if (card.classList.contains('is-done')) {
            if (card.classList.contains('is-provisional') && data.canApproveProgress) {
                pickDoneCard(card);
                return;
            }
            if (card.classList.contains('is-provisional')) {
                reopenCard(card);
                return;
            }
            if (data.lockedWorkerId) {
                setHint('Definitief werk mag je niet uitzetten. De projectleider geeft akkoord.');
                return;
            }
            reopenCard(card);
            return;
        }
        groupsEl.querySelectorAll('.work-group.is-done.is-picked').forEach((item) => unpickCard(item));
        card.classList.toggle('is-picked');
        const check = card.querySelector('.task-check');
        if (check) {
            check.textContent = card.classList.contains('is-picked') ? '✓' : '';
        }
        updateCardStatus(card);
        refreshCompleteForm();
    }

    function pickDoneCard(card) {
        groupsEl.querySelectorAll('.work-group:not(.is-done).is-picked').forEach((item) => unpickCard(item));
        card.classList.toggle('is-picked');
        refreshCompleteForm();
    }

    async function reopenCard(card) {
        const areaId = selectedId;
        const label = card.querySelector('.group-head')?.dataset.label || 'Onderdeel';
        const taskIds = taskIdsFrom(card, { done: true });
        if (!taskIds.length || !areaId) {
            setHint('Dit onderdeel kan niet worden uitgezet.');
            return;
        }
        if (card.dataset.busy === '1') {
            return;
        }
        card.dataset.busy = '1';
        const many = pickedIds.size > 1;
        const response = await fetch(many ? route('reopenMany') : route('reopen', areaId), {
            method: 'POST',
            headers: { ...headers, 'Content-Type': 'application/json' },
            body: JSON.stringify({ task_ids: taskIds }),
        });
        if (!response.ok) {
            card.dataset.busy = '';
            const error = await response.json().catch(() => ({}));
            setHint(workSaveError(error, 'Kon gereed niet uitzetten.'));
            return;
        }
        const payload = await response.json();
        if (many && Array.isArray(payload.areas)) {
            applySavedAreas(payload.areas, `${label} weer open gezet.`);
            return;
        }
        applySavedDetail(areaId, payload, `${areaById(areaId)?.number || ''} · ${label} weer open gezet.`);
    }

    function parseIds(value) {
        return String(value || '').split(',').map((id) => Number(id)).filter(Boolean);
    }

    function pickedCards() {
        return [...groupsEl.querySelectorAll('.work-group.is-picked')];
    }

    function taskIdsFrom(card, { done = false } = {}) {
        const head = card.querySelector('.group-head');
        if (done) {
            return parseIds(head?.dataset.doneTaskIds || head?.dataset.taskIds);
        }
        return parseIds(head?.dataset.openTaskIds || head?.dataset.taskIds);
    }

    function qtyFromHead(head, { done = false } = {}) {
        const remaining = Number(head?.dataset.remaining || 0);
        const ordered = Number(head?.dataset.ordered || 0);
        const value = done ? ordered : (remaining > 0 ? remaining : ordered);
        return value > 0 ? String(value) : '';
    }

    function setFormMode(mode) {
        const submit = document.getElementById('complete-submit');
        const reopenBtn = document.getElementById('complete-reopen');
        const worker = document.getElementById('complete-worker');
        const date = document.getElementById('complete-date');
        const note = document.getElementById('complete-note');
        const reopen = mode === 'reopen';
        const approve = mode === 'approve';
        if (worker) {
            worker.disabled = reopen || approve || Boolean(data.lockedWorkerId);
        }
        if (date) {
            date.disabled = reopen || approve;
        }
        if (note) {
            note.disabled = reopen || approve;
        }
        if (reopenBtn) {
            reopenBtn.hidden = !approve;
            reopenBtn.classList.toggle('hidden', !approve);
        }
        if (submit) {
            submit.dataset.mode = mode;
            if (approve) {
                submit.textContent = 'Akkoord (definitief)';
            } else if (reopen) {
                submit.textContent = 'Gereed uitzetten';
            } else {
                submit.textContent = data.lockedWorkerId ? 'Klaar melden (voorlopig)' : 'Opslaan en verwerken';
            }
        }
    }

    function refreshCompleteForm() {
        if (!data.canEnterProgress) {
            return;
        }
        const nameEl = document.getElementById('complete-task-name');
        const qty = document.getElementById('complete-qty');
        const submit = document.getElementById('complete-submit');
        const picked = pickedCards();
        const openPicked = picked.filter((card) => !card.classList.contains('is-done'));
        const donePicked = picked.filter((card) => card.classList.contains('is-done'));
        const hasOpen = groupsEl.querySelector('.work-group:not(.is-done)');

        if (donePicked.length && openPicked.length) {
            setFormMode('complete');
            nameEl.textContent = 'Kies alleen open of alleen gereed';
            qty.value = '';
            qty.disabled = true;
            submit.disabled = true;
            refreshSelectAllButton();
            forbidProgressSubmit(submit);
            return;
        }

        if (donePicked.length) {
            const allProvisional = donePicked.every((card) => card.classList.contains('is-provisional'));
            if (allProvisional && data.canApproveProgress) {
                setFormMode('approve');
                nameEl.textContent = donePicked.length === 1
                    ? `${donePicked[0].querySelector('.group-head')?.dataset.label || 'Werkzaamheid'} · akkoord geven`
                    : `${donePicked.length} onderdelen akkoord geven`;
                qty.value = '';
                qty.disabled = true;
                submit.disabled = false;
                refreshSelectAllButton();
                forbidProgressSubmit(submit);
                return;
            }
            setFormMode('reopen');
            if (donePicked.length === 1) {
                const head = donePicked[0].querySelector('.group-head');
                nameEl.textContent = `${head?.dataset.label || 'Werkzaamheid'} · gereed uitzetten`;
                qty.value = qtyFromHead(head, { done: true });
            } else {
                nameEl.textContent = `${donePicked.length} onderdelen gereed uitzetten`;
                qty.value = '';
            }
            qty.disabled = true;
            submit.disabled = false;
            refreshSelectAllButton();
            forbidProgressSubmit(submit);
            return;
        }

        setFormMode('complete');
        qty.placeholder = 'Aantal';
        if (openPicked.length === 0) {
            nameEl.textContent = hasOpen
                ? 'Kies een of meer werkzaamheden'
                : 'Alles gereed · klik om uit te zetten';
            qty.value = '';
            qty.disabled = false;
            submit.disabled = true;
        } else if (openPicked.length === 1) {
            const head = openPicked[0].querySelector('.group-head');
            nameEl.textContent = `${head?.dataset.label || 'Werkzaamheid'} (hele onderdeel)${pickedIds.size > 1 ? ` · ${pickedIds.size} ruimtes` : ''}`;
            qty.disabled = false;
            qty.value = qtyFromHead(head);
            submit.disabled = false;
        } else {
            const names = openPicked
                .map((card) => card.querySelector('.group-head')?.dataset.label)
                .filter(Boolean);
            const rooms = pickedIds.size > 1 ? ` · ${pickedIds.size} ruimtes` : '';
            nameEl.textContent = `${names.join(' + ')} · dezelfde vakman${rooms}`;
            qty.value = '';
            qty.disabled = true;
            qty.placeholder = 'Per onderdeel het restant';
            submit.disabled = false;
        }
        refreshSelectAllButton();
        forbidProgressSubmit(submit);
    }

    function refreshSelectAllButton() {
        const button = document.getElementById('select-all-tasks');
        if (!button) {
            return;
        }
        const open = groupsEl.querySelectorAll('.work-group:not(.is-done)');
        button.hidden = open.length === 0;
        const allPicked = open.length > 0 && [...open].every((card) => card.classList.contains('is-picked'));
        button.textContent = allPicked ? 'Niets aanvinken' : 'Alles aanvinken';
    }

    function toggleSelectAll() {
        const open = [...groupsEl.querySelectorAll('.work-group:not(.is-done)')];
        const allPicked = open.length > 0 && open.every((card) => card.classList.contains('is-picked'));
        groupsEl.querySelectorAll('.work-group.is-done.is-picked').forEach((card) => unpickCard(card));
        open.forEach((card) => {
            card.classList.toggle('is-picked', !allPicked);
            const check = card.querySelector('.task-check');
            if (check) {
                check.textContent = !allPicked ? '✓' : '';
            }
            updateCardStatus(card);
        });
        refreshCompleteForm();
    }

    function toNorm(event) {
        const rect = world.getBoundingClientRect();
        return {
            x: clamp((event.clientX - rect.left) / rect.width),
            y: clamp((event.clientY - rect.top) / rect.height),
        };
    }

    document.getElementById('draw-hand').addEventListener('click', () => setTool('hand'));
    document.getElementById('draw-select').addEventListener('click', () => setTool('select'));
    document.getElementById('draw-zoom-in').addEventListener('click', () => {
        scale = Math.min(MAX_ZOOM, scale + 0.15);
        applyTransform();
    });
    document.getElementById('draw-zoom-out').addEventListener('click', () => {
        scale = Math.max(MIN_ZOOM, scale - 0.15);
        applyTransform();
    });
    pageSelect.addEventListener('change', async () => {
        page = Number(pageSelect.value);
        applyRoomFilters({ skipMarkers: true });
        if (pdfDoc) {
            await renderPdfPage();
        } else {
            renderMarkers();
        }
    });
    function syncWorkFilterAllBox() {
        if (!workFilterAllBox) {
            return;
        }
        const boxes = workFilterBoxes();
        workFilterAllBox.checked = boxes.length > 0 && boxes.every((box) => box.checked);
        workFilterAllBox.indeterminate = boxes.some((box) => box.checked) && !workFilterAllBox.checked;
    }

    function readDraftKeys() {
        return workFilterBoxes().filter((box) => box.checked).map((box) => box.dataset.workKey);
    }

    function workPanelIsOpen() {
        return Boolean(workFilterPanel?.classList.contains('is-open'));
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
        const width = Math.min(420, window.innerWidth - 24);
        let left = rect.left;
        if (left + width > window.innerWidth - 12) {
            left = Math.max(12, window.innerWidth - width - 12);
        }
        workFilterPanel.style.left = `${left}px`;
        workFilterPanel.style.top = `${rect.bottom + 4}px`;
        workFilterPanel.style.width = `${width}px`;
    }

    function openWorkPanel() {
        if (!workFilterPanel) {
            return;
        }
        try {
            renderWorkPanelList();
        } catch (error) {
            console.error(error);
        }
        workFilterPanel.removeAttribute('hidden');
        workFilterPanel.classList.add('is-open');
        workSelect?.classList.add('is-open');
        workFilterToggle?.setAttribute('aria-expanded', 'true');
        positionWorkPanel();
        workFilterList?.scrollTo(0, 0);
    }

    function draftMeasure() {
        const filters = data.work_filters || [];

        return measureSelectedWorks(floorAreas(), draftWorkKeys, filters);
    }

    function refreshPanelTotal() {
        if (!workFilterTotal) {
            return;
        }
        workFilterTotal.textContent = `Geselecteerd: ${draftMeasure().label}`;
    }

    function renderWorkPanelList() {
        if (!workFilterList) {
            return;
        }
        const filters = data.work_filters || [];
        const quantities = measureSelectedWorks(floorAreas(), filters.map((item) => item.key), filters);
        const qtyByKey = Object.fromEntries(quantities.lines.map((line) => [line.key, line]));
        workFilterList.innerHTML = groupedWorkFilters(filters).map((group) => {
            const head = group.name ? `<div class="draw-work-group">${escapeHtml(group.name)}</div>` : '';
            const items = group.items.map((filter) => {
                const line = qtyByKey[filter.key] || { qty_label: `0,00 m²` };
                const checked = draftWorkKeys.includes(filter.key) ? ' checked' : '';

                return `<label class="draw-work-option"><input type="checkbox" data-work-key="${escapeHtml(filter.key)}"${checked}><span class="draw-work-option-name">${escapeHtml(shortWorkLabel(filter.label))}</span><span class="draw-work-option-qty">${escapeHtml(line.qty_label)}</span></label>`;
            }).join('');

            return head + items;
        }).join('');
        syncWorkFilterAllBox();
        refreshPanelTotal();
    }

    function commitWorkFilter(keys, { close = false, hintOn = true } = {}) {
        workFilterKeys = [...keys];
        draftWorkKeys = [...keys];
        if (workFilterLabel) {
            workFilterLabel.textContent = workFilterSummaryLabel(workFilterKeys);
        }
        applyRoomFilters();
        storeOutsourceSelection();
        syncRoomMeasureMode();
        if (hasWorkFilter()) {
            pickWorkGroup();
            if (hintOn) {
                setHint(`Alleen ruimtes met de gekozen materialen op deze verdieping. Tik een ruimte aan, of Alles aanvinken.`);
            }
            document.querySelector('.board-left')?.classList.add('is-open');
            document.querySelector('.board-right')?.classList.remove('is-open');
        }
        if (close) {
            closeWorkPanel();
        }
    }

    function storeOutsourceSelection() {
        if (!outsourceSelectionData) {
            return;
        }
        const workerSelect = document.getElementById('complete-worker');
        const workerOption = workerSelect?.selectedOptions?.[0];
        const workerId = workerSelect?.value ? Number(workerSelect.value) : null;
        const workerName = workerOption?.textContent?.trim() || null;
        if (pickedIds.size > 1 || roomMeasureMode) {
            const measure = currentRoomMeasure();
            const payload = buildOutsourceSelection({
                project: data.project || {},
                floor: selectedFloorName(),
                keys: measure.lines.map((line) => line.key),
                measure,
                workerId,
                workerName,
                source: 'rooms',
            });
            outsourceSelectionData.textContent = JSON.stringify(payload);
            return;
        }
        if (!hasWorkFilter()) {
            outsourceSelectionData.textContent = '{}';
            return;
        }
        const payload = buildOutsourceSelection({
            project: data.project || {},
            floor: selectedFloorName(),
            keys: workFilterKeys,
            measure: selectedWorkMeasure(),
            workerId,
            workerName,
            source: 'materials',
        });
        outsourceSelectionData.textContent = JSON.stringify(payload);
    }

    function currentRoomMeasure() {
        return measureSelectedRooms(pickedList().map((id) => areaById(id)).filter(Boolean));
    }

    function syncRoomMeasureMode() {
        const measure = currentRoomMeasure();
        const count = pickedList().length;
        if (roomMeasureCount) {
            roomMeasureCount.textContent = count === 1 ? '1 ruimte' : `${count} ruimtes`;
        }
        if (roomMeasureQty) {
            roomMeasureQty.textContent = measure.m2_label;
        }
        const progressWorks = selectedRoomProgressWorks(
            pickedList().map((id) => areaById(id)).filter(Boolean),
        );
        const active = activeSelectionFromFilter(progressWorks, workFilterKeys, data.work_filters || []);
        const activeLabel = activeWorkBarLabel(active);
        if (roomMeasureActive) {
            roomMeasureActive.textContent = activeLabel;
            roomMeasureActive.classList.toggle('hidden', activeLabel === '');
        }
        if (roomMeasureBar) {
            roomMeasureBar.setAttribute('aria-label', roomSelectionSummaryLabel(count, measure.total_m2));
        }
        roomMeasureBar?.classList.toggle('hidden', !roomMeasureMode && count === 0);
        if (roomMeasureClearBtn) {
            roomMeasureClearBtn.disabled = count === 0;
        }
        if (roomProgressOpenBtn) {
            roomProgressOpenBtn.disabled = count === 0 || !data.canEnterProgress;
        }
        pickRoomsBtn?.classList.toggle('is-on', roomMeasureMode);
        pickRoomsBtn?.setAttribute('aria-pressed', roomMeasureMode ? 'true' : 'false');
        stage.classList.toggle('is-room-measure', roomMeasureMode);
        if (roomMeasurePanelIsOpen()) {
            fillRoomMeasurePanel();
            positionRoomMeasurePanel();
        }
        if (roomProgressPanelIsOpen()) {
            fillRoomProgressPanel();
        }
        storeOutsourceSelection();
    }

    function refreshRoomMeasure() {
        syncRoomMeasureMode();
        highlightList();
        renderMarkers();
    }

    async function toggleMeasuredRoom(id) {
        await togglePickedRoom(id);
        syncRoomMeasureMode();
        document.querySelector('.board-right')?.classList.add('is-open');
        document.querySelector('.board-left')?.classList.remove('is-open');
    }

    function setRoomMeasureMode(on) {
        roomMeasureMode = Boolean(on);
        if (roomMeasureMode) {
            setModes({});
            roomMeasureMode = true;
            setHint(ticketMode
                ? 'Tik ruimtes aan op de tekening. Daarna Selectie toevoegen.'
                : 'Tik ruimtes aan op de tekening, of sleep om te verschuiven. Daarna kies je werkzaamheden en wie het werk uitvoerde.');
            closeWorkPanel();
            document.querySelector('.board-right')?.classList.add('is-open');
            document.querySelector('.board-left')?.classList.remove('is-open');
        } else {
            closeRoomMeasurePanel();
            closeRoomProgressPanel();
            if (!snagMode && !moveMode && !linkModeAreaId) {
                setHint('');
            }
        }
        refreshRoomMeasure();
    }

    function clickableMeasureRooms() {
        return uniqueAreasOnPage(areas, page)
            .filter((area) => !areaIsFilteredOut(area) || pickedIds.has(Number(area.id)))
            .map((area) => {
                const contour = roomContour(area);
                if (!contour) {
                    return null;
                }

                return { id: area.id, contour };
            })
            .filter(Boolean);
    }

    function roomMeasurePanelIsOpen() {
        return Boolean(roomMeasurePanel?.classList.contains('is-open'));
    }

    function closeRoomMeasurePanel() {
        roomMeasurePanel?.classList.remove('is-open');
        roomMeasureViewBtn?.setAttribute('aria-expanded', 'false');
    }

    function positionRoomMeasurePanel() {
        if (!roomMeasurePanel || !roomMeasureViewBtn) {
            return;
        }
        const rect = roomMeasureViewBtn.getBoundingClientRect();
        const width = Math.min(360, Math.max(280, window.innerWidth - 24));
        const left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12));
        roomMeasurePanel.style.left = `${left}px`;
        roomMeasurePanel.style.top = `${rect.bottom + 4}px`;
        roomMeasurePanel.style.width = `${width}px`;
    }

    function fillRoomMeasurePanel() {
        if (!roomMeasureRoomsEl || !roomMeasureTotalsEl) {
            return;
        }
        const measure = currentRoomMeasure();
        if (!measure.rooms.length) {
            roomMeasureRoomsEl.innerHTML = '<p class="room-measure-empty">Nog geen ruimtes geselecteerd.</p>';
        } else {
            roomMeasureRoomsEl.innerHTML = measure.rooms.map((room) => {
                const label = [room.number, room.name].filter(Boolean).join(' ');
                return `<button type="button" class="room-measure-room" data-area-id="${room.id}">${escapeHtml(label)} · ${escapeHtml(formatBoardQty(room.m2))} m²</button>`;
            }).join('');
        }
        const netLine = `<div class="room-measure-line"><span>Netto m²</span><span>${escapeHtml(measure.m2_label)}</span></div>`;
        const workLines = measure.lines.map((line) => (
            `<div class="room-measure-line"><span>${escapeHtml(line.display_label || line.label)}</span><span>${escapeHtml(line.qty_label)}</span></div>`
        )).join('');
        roomMeasureTotalsEl.innerHTML = netLine + (workLines || '<p class="room-measure-empty">Geen werkzaamheden gekoppeld.</p>');
    }

    function openRoomMeasurePanel() {
        if (!roomMeasurePanel) {
            return;
        }
        closeRoomProgressPanel();
        fillRoomMeasurePanel();
        positionRoomMeasurePanel();
        roomMeasurePanel.classList.add('is-open');
        roomMeasureViewBtn?.setAttribute('aria-expanded', 'true');
    }

    function roomProgressPanelIsOpen() {
        return Boolean(roomProgressPanel?.classList.contains('is-open'));
    }

    function closeRoomProgressPanel() {
        roomProgressPanel?.classList.remove('is-open');
        roomProgressPanel?.setAttribute('hidden', 'hidden');
        if (roomProgressError) {
            roomProgressError.hidden = true;
            roomProgressError.textContent = '';
        }
    }

    function positionRoomProgressPanel() {
        if (!roomProgressPanel) {
            return;
        }
        const width = Math.min(424, window.innerWidth - 24);
        roomProgressPanel.style.right = '12px';
        roomProgressPanel.style.top = '12px';
        roomProgressPanel.style.width = `${width}px`;
        roomProgressPanel.style.left = 'auto';
    }

    function setRoomProgressTab(name) {
        roomProgressPanel?.querySelectorAll('[data-progress-tab]').forEach((button) => {
            const on = button.dataset.progressTab === name;
            button.classList.toggle('is-on', on);
            button.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        roomProgressPanel?.querySelectorAll('[data-progress-pane]').forEach((pane) => {
            pane.hidden = pane.dataset.progressPane !== name;
        });
    }

    function selectedProgressKeys() {
        return [...(roomProgressWorksEl?.querySelectorAll('input[data-work-key]:checked') ?? [])]
            .map((box) => box.dataset.workKey)
            .filter(Boolean);
    }

    function updateRoomProgressSubmit() {
        if (!roomProgressSubmit) {
            return;
        }
        const workerId = document.getElementById('room-progress-worker')?.value;
        const date = document.getElementById('room-progress-date')?.value;
        roomProgressSubmit.disabled = savingWork
            || selectedProgressKeys().length === 0
            || !workerId
            || !date
            || pickedList().length === 0
            || !data.canEnterProgress;
    }

    function progressWorkRowsHtml(works, checkedKeys) {
        return works.map((work) => {
            if (!work.bookable) {
                return `<div class="room-progress-option is-done"><span>✓</span><span class="room-progress-option-copy">${escapeHtml(work.detail)}</span></div>`;
            }
            const isChecked = checkedKeys.has(work.key);
            const activeClass = isChecked ? ' is-active' : '';
            return `<label class="room-progress-option${activeClass}"><input type="checkbox" data-work-key="${escapeHtml(work.key)}"${isChecked ? ' checked' : ''}><span class="room-progress-option-copy">${escapeHtml(work.display_label)}${work.status === 'partial' ? `<small>${escapeHtml(work.detail)}</small>` : ''}</span><span class="room-progress-option-qty">${escapeHtml(work.qty_label)}</span></label>`;
        }).join('');
    }

    function fillRoomProgressActive(active) {
        if (!roomProgressActive) {
            return;
        }
        if (!workFilterKeys.length) {
            roomProgressActive.innerHTML = '<p class="room-progress-active-empty">Kies bovenin een werkzaamheid of materiaal. Die selectie wordt hier overgenomen.</p>';
            return;
        }
        roomProgressActive.innerHTML = active.map((line) => (
            `<div class="room-progress-active-line"><strong>${escapeHtml(line.display_label || line.label)}</strong><span>${escapeHtml(line.active_detail)}</span></div>`
        )).join('');
    }

    function fillRoomProgressPanel() {
        const measure = currentRoomMeasure();
        const works = selectedRoomProgressWorks(
            pickedList().map((id) => areaById(id)).filter(Boolean),
        );
        const active = activeSelectionFromFilter(works, workFilterKeys, data.work_filters || []);
        const checkedKeys = new Set(checkedKeysFromWorkFilter(works, workFilterKeys));
        if (roomProgressMeta) {
            roomProgressMeta.textContent = `${measure.rooms.length === 1 ? '1 ruimte geselecteerd' : `${measure.rooms.length} ruimtes geselecteerd`} | ${measure.m2_label}`;
        }
        fillRoomProgressActive(active);
        if (roomProgressWorksEl) {
            if (!works.length) {
                roomProgressWorksEl.innerHTML = '<p class="room-measure-empty">Geen werkzaamheden gekoppeld.</p>';
            } else {
                const grouped = groupedRoomProgressWorks(works);
                const parts = [];
                if (grouped.werkzaamheden.length) {
                    parts.push('<div class="room-progress-group-title">Werkzaamheden</div>');
                    parts.push(progressWorkRowsHtml(grouped.werkzaamheden, checkedKeys));
                }
                if (grouped.materialen.length) {
                    parts.push('<div class="room-progress-group-title">Materialen</div>');
                    parts.push(progressWorkRowsHtml(grouped.materialen, checkedKeys));
                }
                roomProgressWorksEl.innerHTML = parts.join('');
            }
        }
        if (roomProgressRoomsEl) {
            roomProgressRoomsEl.innerHTML = measure.rooms.length
                ? measure.rooms.map((room) => {
                    const label = [room.number, room.name].filter(Boolean).join(' ');
                    return `<div class="room-measure-line"><span>${escapeHtml(label)}</span><span>${escapeHtml(formatBoardQty(room.m2))} m²</span></div>`;
                }).join('')
                : '<p class="room-measure-empty">Nog geen ruimtes geselecteerd.</p>';
        }
        if (roomProgressTotalsEl) {
            const lines = [`<div class="room-measure-line"><span>Netto m²</span><span>${escapeHtml(measure.m2_label)}</span></div>`];
            measure.lines.forEach((line) => {
                lines.push(`<div class="room-measure-line"><span>${escapeHtml(line.display_label || line.label)}</span><span>${escapeHtml(line.qty_label)}</span></div>`);
            });
            roomProgressTotalsEl.innerHTML = lines.join('');
        }
        updateRoomProgressNoteCount();
        updateRoomProgressSubmit();
    }

    function updateRoomProgressNoteCount() {
        if (!roomProgressNoteCount) {
            return;
        }
        const length = String(roomProgressNote?.value || '').length;
        roomProgressNoteCount.textContent = `${length} / 500`;
    }

    function openRoomProgressPanel() {
        if (!roomProgressPanel || !data.canEnterProgress || pickedList().length === 0) {
            return;
        }
        closeRoomMeasurePanel();
        setRoomProgressTab('works');
        fillRoomProgressPanel();
        positionRoomProgressPanel();
        roomProgressPanel.removeAttribute('hidden');
        roomProgressPanel.classList.add('is-open');
        document.querySelector('.board-right')?.classList.add('is-open');
        document.querySelector('.board-left')?.classList.remove('is-open');
    }

    async function clearMeasuredRooms() {
        const keep = selectedId && pickedIds.has(selectedId) ? selectedId : pickedList()[0];
        pickedIds = keep ? new Set([keep]) : new Set();
        closeRoomMeasurePanel();
        closeRoomProgressPanel();
        highlightList();
        renderMarkers();
        if (keep) {
            if (areaDetails[keep]) {
                renderPickedPanel();
            } else {
                await selectArea(keep);
            }
        }
        syncRoomMeasureMode();
        setHint(roomMeasureMode ? 'Selectie gewist. Tik ruimtes aan op de tekening.' : 'Ruimteselectie gewist.');
    }

    async function saveRoomSelectionProgress() {
        if (savingWork || !data.canEnterProgress) {
            return;
        }
        const areaIds = pickedList();
        const workKeys = selectedProgressKeys();
        const workerId = document.getElementById('room-progress-worker')?.value;
        const date = document.getElementById('room-progress-date')?.value;
        if (!areaIds.length || !workKeys.length) {
            return;
        }
        if (!workerId || !date) {
            setHint('Kies een vakman en een datum.');
            return;
        }
        savingWork = true;
        if (roomProgressSubmit) {
            roomProgressSubmit.disabled = true;
        }
        if (roomProgressError) {
            roomProgressError.hidden = true;
            roomProgressError.textContent = '';
        }
        try {
            const response = await fetch(route('processSelection'), {
                method: 'POST',
                headers: { ...headers, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    area_ids: areaIds,
                    work_keys: workKeys,
                    worker_id: Number(workerId),
                    date,
                    note: roomProgressNote?.value || '',
                }),
            });
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                const message = workSaveError(error, 'Kon de werkzaamheden niet verwerken.');
                if (roomProgressError) {
                    roomProgressError.textContent = message;
                    roomProgressError.hidden = false;
                }
                setHint(message);
                return;
            }
            const payload = await response.json();
            const summary = payload.summary || {};
            const labels = Array.isArray(summary.labels) ? summary.labels.join(', ') : '';
            const parts = [
                `${summary.area_count || areaIds.length} ruimtes bijgewerkt`,
                labels,
                summary.quantity_label || '',
                summary.worker || '',
            ].filter(Boolean);
            if (Array.isArray(payload.areas)) {
                applySavedAreas(payload.areas, parts.join(' · '));
            }
            if (roomProgressNote) {
                roomProgressNote.value = '';
            }
            fillRoomProgressPanel();
            syncRoomMeasureMode();
        } finally {
            savingWork = false;
            updateRoomProgressSubmit();
            refreshCompleteForm();
        }
    }

    workFilterToggle?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (workPanelIsOpen()) {
            closeWorkPanel();
        } else {
            openWorkPanel();
        }
    });
    workFilterToggle?.addEventListener('pointerdown', (event) => {
        event.stopPropagation();
    });
    workFilterPanel?.addEventListener('pointerdown', (event) => {
        event.stopPropagation();
    });
    workFilterPanel?.addEventListener('change', (event) => {
        if (event.target.matches('[data-work-all]')) {
            draftWorkKeys = event.target.checked
                ? (data.work_filters || []).map((item) => item.key)
                : [];
            workFilterBoxes().forEach((box) => {
                box.checked = event.target.checked;
            });
            syncWorkFilterAllBox();
            refreshPanelTotal();
            commitWorkFilter(draftWorkKeys, { hintOn: false });
            return;
        }
        if (event.target.matches('[data-work-key]')) {
            draftWorkKeys = readDraftKeys();
            syncWorkFilterAllBox();
            refreshPanelTotal();
            commitWorkFilter(draftWorkKeys, { hintOn: false });
        }
    });
    document.getElementById('draw-work-clear')?.addEventListener('click', () => {
        commitWorkFilter([], { hintOn: false });
        setHint('Materiaalselectie gewist.');
    });
    document.getElementById('draw-work-apply')?.addEventListener('click', () => {
        commitWorkFilter(readDraftKeys(), { close: true });
    });
    document.addEventListener('pointerdown', (event) => {
        if (!workPanelIsOpen()) {
            return;
        }
        if (workSelect?.contains(event.target) || workFilterPanel?.contains(event.target)) {
            return;
        }
        closeWorkPanel();
    });
    window.addEventListener('resize', () => {
        if (workPanelIsOpen()) {
            positionWorkPanel();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && workPanelIsOpen()) {
            closeWorkPanel();
        }
    });
    pickWorkRoomsBtn?.addEventListener('click', () => {
        pickVisibleRooms();
    });
    pickRoomsBtn?.addEventListener('click', () => {
        setRoomMeasureMode(!roomMeasureMode);
    });
    roomMeasureViewBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        if (roomMeasurePanelIsOpen()) {
            closeRoomMeasurePanel();
            return;
        }
        openRoomMeasurePanel();
    });
    roomMeasureClearBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        clearMeasuredRooms();
    });
    roomMeasurePanel?.addEventListener('pointerdown', (event) => {
        event.stopPropagation();
    });
    roomMeasurePanel?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-area-id]');
        if (!button) {
            return;
        }
        const areaId = Number(button.dataset.areaId);
        if (areaId) {
            selectArea(areaId);
        }
    });
    roomProgressOpenBtn?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openRoomProgressPanel();
    });
    document.getElementById('room-progress-close')?.addEventListener('click', () => {
        closeRoomProgressPanel();
    });
    document.getElementById('room-progress-cancel')?.addEventListener('click', () => {
        closeRoomProgressPanel();
    });
    roomProgressPanel?.querySelectorAll('[data-progress-tab]').forEach((button) => {
        button.addEventListener('click', () => {
            setRoomProgressTab(button.dataset.progressTab);
        });
    });
    roomProgressWorksEl?.addEventListener('change', (event) => {
        const option = event.target.closest('.room-progress-option');
        if (option && event.target.matches('input[data-work-key]')) {
            option.classList.toggle('is-active', event.target.checked);
        }
        updateRoomProgressSubmit();
    });
    document.getElementById('room-progress-worker')?.addEventListener('change', updateRoomProgressSubmit);
    document.getElementById('room-progress-date')?.addEventListener('change', updateRoomProgressSubmit);
    roomProgressNote?.addEventListener('input', updateRoomProgressNoteCount);
    roomProgressForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        saveRoomSelectionProgress();
    });
    document.addEventListener('pointerdown', (event) => {
        if (roomMeasurePanelIsOpen()
            && !roomMeasurePanel?.contains(event.target)
            && !roomMeasureViewBtn?.contains(event.target)
            && !roomMeasureBar?.contains(event.target)) {
            closeRoomMeasurePanel();
        }
    });
    window.addEventListener('resize', () => {
        if (roomMeasurePanelIsOpen()) {
            positionRoomMeasurePanel();
        }
        if (roomProgressPanelIsOpen()) {
            positionRoomProgressPanel();
        }
    });

    document.querySelectorAll('.layer-btn').forEach((button) => {
        button.addEventListener('click', () => {
            layer = button.dataset.layer;
            document.querySelectorAll('.layer-btn').forEach((item) => item.classList.toggle('is-on', item === button));
            if (!layerShowsSnags(layer)) {
                closeSnagPopup();
            }
            renderMarkers();
        });
    });

    document.getElementById('draw-snag')?.addEventListener('click', () => {
        if (!data.canCreateSnags) {
            return;
        }
        const next = !snagMode;
        setModes({ snag: next });
        if (next) {
            layer = 'both';
            document.querySelectorAll('.layer-btn').forEach((item) => item.classList.toggle('is-on', item.dataset.layer === 'both'));
            renderMarkers();
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

    stage.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) {
            return;
        }
        if (event.target.closest('.snag-pin, .snag-popup, .snag-photo-preview, button, select, a, input, textarea, label, .status-badge')) {
            return;
        }
        if (event.target.closest('.room-label') && !roomMeasureMode) {
            return;
        }
        if (tool === 'select' || snagMode || moveMode) {
            return;
        }
        dragging = true;
        dragMoved = false;
        dragStart = {
            x: event.clientX - panX,
            y: event.clientY - panY,
            cx: event.clientX,
            cy: event.clientY,
            threshold: event.pointerType === 'touch' || event.pointerType === 'pen' ? 12 : 4,
        };
        stage.classList.add('is-grabbing');
        stage.setPointerCapture(event.pointerId);
    }, true);
    stage.addEventListener('pointermove', (event) => {
        if (draggingSnag) {
            const point = toNorm(event);
            const pin = markersEl.querySelector(`.snag-pin[data-snag-id="${draggingSnag.id}"]`);
            if (pin) {
                pin.style.left = `${point.x * 100}%`;
                pin.style.top = `${point.y * 100}%`;
            }
            draggingSnag.x = point.x;
            draggingSnag.y = point.y;
            return;
        }
        if (!dragging || !dragStart) {
            return;
        }
        const distance = Math.hypot(event.clientX - dragStart.cx, event.clientY - dragStart.cy);
        if (!dragMoved && distance < (dragStart.threshold || 4)) {
            return;
        }
        dragMoved = true;
        panX = event.clientX - dragStart.x;
        panY = event.clientY - dragStart.y;
        applyTransform();
    });
    stage.addEventListener('pointerup', async (event) => {
        if (draggingSnag) {
            const point = draggingSnag.x != null ? { x: draggingSnag.x, y: draggingSnag.y } : toNorm(event);
            draggingSnag = null;
            await applySnagMove(point);
            return;
        }
        const wasDrag = dragMoved;
        dragging = false;
        dragMoved = false;
        stage.classList.remove('is-grabbing');
        if (wasDrag || event.button !== 0) {
            return;
        }
        if (event.target.closest('.snag-pin, .snag-popup, .snag-photo-preview, button, select, a, input, textarea, label') && !event.target.closest('.room-label')) {
            return;
        }
        await handleDrawingTap(event);
    });
    stage.addEventListener('pointercancel', () => {
        draggingSnag = null;
        dragging = false;
        dragMoved = false;
        stage.classList.remove('is-grabbing');
    });
    stage.addEventListener('wheel', (event) => {
        event.preventDefault();
        const next = event.deltaY > 0 ? scale - 0.08 : scale + 0.08;
        scale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, next));
        applyTransform();
    }, { passive: false });

    world.addEventListener('click', (event) => {
        event.preventDefault();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (closeSnagPhotoPreview()) {
                return;
            }
            if (popupSnagId) {
                closeSnagPopup();
                return;
            }
            if (roomProgressPanelIsOpen()) {
                closeRoomProgressPanel();
                return;
            }
            if (roomMeasurePanelIsOpen()) {
                closeRoomMeasurePanel();
                return;
            }
            if (roomMeasureMode) {
                if (ticketMode) {
                    return;
                }
                setRoomMeasureMode(false);
                return;
            }
            if (snagMode || moveMode || pendingSnag || editingSnagId) {
                if (moveMode) {
                    setModes({});
                    setHint('Positie niet gewijzigd.');
                    return;
                }
                closeSnagPanel();
            }
        }
    });

    document.querySelector('.board-left')?.addEventListener('click', (event) => {
        if (event.target.closest('#pick-all-rooms')) {
            event.preventDefault();
            pickVisibleRooms();
            return;
        }
        const floorBtn = event.target.closest('.floor-pick');
        if (floorBtn) {
            event.preventDefault();
            pickVisibleRooms(floorBtn.dataset.floor || '');
            return;
        }
        const row = event.target.closest('.room-row');
        if (!row) {
            return;
        }
        event.preventDefault();
        if (ticketMode && canPickRooms()) {
            togglePickedRoom(row.dataset.areaId);
            const area = areaById(Number(row.dataset.areaId));
            if (area) {
                jumpToStoredRoom(area);
            }
            return;
        }
        if (hasWorkFilter() && canPickRooms()) {
            togglePickedRoom(row.dataset.areaId);
            return;
        }
        if (event.target.closest('.status-pill') && canPickRooms()) {
            togglePickedRoom(row.dataset.areaId);
            return;
        }
        selectArea(row.dataset.areaId);
    });

    document.querySelectorAll('.room-filter').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.room-filter').forEach((item) => item.classList.toggle('is-on', item === button));
            applyRoomFilters({ skipMarkers: true });
        });
    });

    completeForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        saveSelected();
    });
    document.getElementById('select-all-tasks')?.addEventListener('click', toggleSelectAll);
    document.getElementById('complete-reopen')?.addEventListener('click', () => {
        const submit = document.getElementById('complete-submit');
        if (submit) {
            submit.dataset.mode = 'reopen';
        }
        saveSelected();
    });

    async function saveSelected() {
        if (savingWork || !data.canEnterProgress) {
            return;
        }
        const areaId = selectedId;
        const mode = document.getElementById('complete-submit')?.dataset.mode || 'complete';
        const picked = pickedCards();
        const cards = mode === 'reopen' || mode === 'approve'
            ? picked.filter((card) => card.classList.contains('is-done'))
            : picked.filter((card) => !card.classList.contains('is-done'));
        const taskIds = cards.flatMap((card) => taskIdsFrom(card, { done: mode === 'reopen' || mode === 'approve' }));
        if (!taskIds.length) {
            return;
        }

        savingWork = true;
        const submit = document.getElementById('complete-submit');
        if (submit) {
            submit.disabled = true;
        }

        try {
            let response;
            const many = pickedIds.size > 1;
            if (mode === 'reopen') {
                response = await fetch(many ? route('reopenMany') : route('reopen', areaId), {
                    method: 'POST',
                    headers: { ...headers, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ task_ids: taskIds }),
                });
            } else if (mode === 'approve') {
                if (!data.canApproveProgress) {
                    setHint('Je mag dit werk niet definitief maken.');
                    return;
                }
                response = await fetch(many ? route('approveMany') : route('approve', areaId), {
                    method: 'POST',
                    headers: { ...headers, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ task_ids: taskIds }),
                });
            } else {
                const workerId = document.getElementById('complete-worker').value;
                const date = document.getElementById('complete-date').value;
                if (!workerId || !date) {
                    setHint('Kies een vakman en een datum.');
                    return;
                }
                const qtyInput = document.getElementById('complete-qty');
                const body = {
                    task_ids: taskIds,
                    worker_id: workerId,
                    date,
                    note: document.getElementById('complete-note').value,
                };
                const qty = Number(qtyInput?.value || 0);
                if (taskIds.length === 1 && qty > 0) {
                    body.quantity = qty;
                }
                response = await fetch(many ? route('processMany') : route('process', areaId), {
                    method: 'POST',
                    headers: { ...headers, 'Content-Type': 'application/json' },
                    body: JSON.stringify(body),
                });
            }
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                setHint(workSaveError(error, mode === 'reopen'
                    ? 'Kon gereed niet uitzetten.'
                    : (mode === 'approve'
                        ? 'Kon geen akkoord geven.'
                        : 'Kon de werkzaamheden niet verwerken.')));
                return;
            }
            const doneLabel = document.getElementById('complete-task-name').textContent;
            const payload = await response.json();
            if (many && Array.isArray(payload.areas)) {
                const numbers = payload.areas
                    .map((detail) => detail.area?.number)
                    .filter(Boolean)
                    .join(', ');
                applySavedAreas(payload.areas, mode === 'reopen'
                    ? `${numbers} · gereed uitgezet.`
                    : (mode === 'approve'
                        ? `${numbers} · akkoord, nu definitief.`
                        : `${numbers} · ${doneLabel} opgeslagen.`));
                return;
            }
            applySavedDetail(areaId, payload, mode === 'reopen'
                ? `${payload.area?.number || ''} · gereed uitgezet.`
                : (mode === 'approve'
                    ? `${payload.area?.number || ''} · akkoord, nu definitief.`
                    : `${payload.area?.number || ''} · ${doneLabel} opgeslagen.`));
        } finally {
            savingWork = false;
            refreshCompleteForm();
        }
    }

    function applySavedAreas(details, prefix) {
        details.forEach((detail) => {
            const id = Number(detail.area?.id);
            if (!id) {
                return;
            }
            areaDetails[id] = detail;
            if (detail.area) {
                applyAreaSummary(id, detail.area);
            }
        });
        highlightList();
        refreshFilterCounts();
        applyRoomFilters();
        renderPickedPanel();
        syncRoomMeasureMode();
        const note = document.getElementById('complete-note');
        if (note) {
            note.value = '';
        }
        setHint(`${prefix}`.trim());
    }

    function applySavedDetail(areaId, detail, prefix) {
        if (Number(detail.area?.id) !== areaId) {
            return;
        }
        areaDetails[areaId] = detail;
        if (detail.area) {
            applyAreaSummary(areaId, detail.area);
        }
        if (selectedId !== areaId) {
            return;
        }
        highlightList();
        refreshFilterCounts();
        applyRoomFilters();
        renderPanel(detail);
        const note = document.getElementById('complete-note');
        if (note) {
            note.value = '';
        }
        const area = detail.area;
        const markerNote = area?.tone === 'done'
            ? 'groen vinkje onder de kamernaam'
            : area?.tone === 'pending'
                ? 'voorlopig vinkje onder de kamernaam (wacht op akkoord)'
                : area?.tone === 'partial'
                    ? 'gereed-onderdelen onder de kamernaam'
                    : 'geen extra markering (nog niets gedaan)';
        setHint(`${prefix} ${area?.progress || ''} · ${area?.status_label || ''}. ${markerNote}.`.trim());
    }

    function selectedWorkMeasure() {
        return measureSelectedWorks(matchingAreas(), workFilterKeys, data.work_filters || []);
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function refreshWorkQuantity() {
        const measure = hasWorkFilter() ? selectedWorkMeasure() : null;
        document.querySelectorAll('.draw-work-qty').forEach((el) => {
            if (el.id === 'draw-work-qty') {
                el.textContent = measure ? `${measure.label} geselecteerd` : '';
            } else {
                el.textContent = measure?.label || '';
            }
            el.classList.toggle('is-on', Boolean(measure));
        });
        if (workPanelIsOpen()) {
            updateWorkPanelQuantities();
        }
    }

    function updateWorkPanelQuantities() {
        const filters = data.work_filters || [];
        if (!filters.length) {
            refreshPanelTotal();
            return;
        }
        const quantities = measureSelectedWorks(floorAreas(), filters.map((item) => item.key), filters);
        const qtyByKey = Object.fromEntries(quantities.lines.map((line) => [line.key, line]));
        workFilterBoxes().forEach((box) => {
            const qty = box.closest('.draw-work-option')?.querySelector('.draw-work-option-qty');
            if (qty) {
                qty.textContent = qtyByKey[box.dataset.workKey]?.qty_label || '0,00 m²';
            }
        });
        refreshPanelTotal();
    }

    function refreshFilterCounts() {
        const pool = matchingAreas();
        const counts = {
            all: pool.length,
            open: pool.filter((area) => area.tone === 'open').length,
            partial: pool.filter((area) => area.tone === 'partial').length,
            pending: pool.filter((area) => area.tone === 'pending').length,
            done: pool.filter((area) => area.tone === 'done').length,
        };
        const countLabel = document.getElementById('room-count-label');
        if (countLabel) {
            countLabel.textContent = `${counts.all} ruimtes`;
        }
        document.querySelectorAll('.room-filter').forEach((button) => {
            const key = button.dataset.filter;
            const labels = {
                all: `Alles (${counts.all})`,
                open: `Open (${counts.open})`,
                partial: `Deels gereed (${counts.partial})`,
                pending: `Voorlopig (${counts.pending})`,
                done: `Gereed (${counts.done})`,
            };
            if (labels[key]) {
                button.textContent = labels[key];
            }
        });
        refreshWorkQuantity();
    }

    async function handleDrawingTap(event) {
        const point = toNorm(event);
        if (snagMode) {
            openNewSnag(point);
            return;
        }
        if (moveMode) {
            await applySnagMove(point);
            return;
        }
        if (linkModeAreaId) {
            await finishManualLink(point);
            return;
        }
        const label = event.target.closest?.('.room-label, .status-badge, .status-mark');
        if (roomMeasureMode) {
            const fromLabel = Number(label?.dataset?.areaId || 0);
            const hit = fromLabel
                || hitTestContours(point, clickableMeasureRooms())?.id
                || hitTestLabels(point, clickableRooms())?.id;
            if (hit) {
                hideTip();
                closeSnagPopup();
                toggleMeasuredRoom(hit);
            }
            return;
        }
        const areaId = Number(label?.dataset?.areaId || 0)
            || hitTestContours(point, clickableMeasureRooms())?.id
            || hitTestLabels(point, clickableRooms())?.id;
        if (areaId) {
            hideTip();
            closeSnagPopup();
            closeSnagPanel({ keepRoom: true });
            if (event.ctrlKey || event.metaKey) {
                togglePickedRoom(areaId);
                return;
            }
            if (pickedIds.size > 1) {
                addPickedRoom(areaId, { focus: true });
                return;
            }
            selectArea(areaId, { fromPin: true });
        } else {
            closeSnagPopup();
        }
    }

    function nextSnagNumber() {
        const maxSaved = snags.reduce((max, item) => Math.max(max, Number(item.number) || 0), 0);

        return Math.max(maxSaved + 1, Number(data.next_snag_number) || 1);
    }

    function nearestAreaAt(point) {
        const hit = hitTestLabels(point, clickableRooms());
        if (hit) {
            return areaById(hit.id);
        }
        let best = null;
        let bestDistance = 0.16;
        clickableRooms().forEach((room) => {
            const cx = room.box.x + room.box.w / 2;
            const cy = room.box.y + room.box.h / 2;
            const distance = Math.hypot(point.x - cx, point.y - cy);
            if (distance < bestDistance) {
                bestDistance = distance;
                best = areaById(room.id);
            }
        });
        uniqueAreasOnPage(areas, page).forEach((area) => {
            if (!area.marker) {
                return;
            }
            const distance = Math.hypot(area.marker.x - point.x, area.marker.y - point.y);
            if (distance < bestDistance) {
                bestDistance = distance;
                best = area;
            }
        });

        return best;
    }

    function showSnagPanel() {
        roomPanel?.classList.add('hidden');
        snagPanel?.classList.remove('hidden');
        document.querySelector('.board-right')?.classList.add('is-open');
        document.querySelector('.board-left')?.classList.remove('is-open');
    }

    function closeSnagPanel({ keepRoom = false } = {}) {
        snagPanel?.classList.add('hidden');
        roomPanel?.classList.remove('hidden');
        pendingSnag = null;
        editingSnagId = null;
        currentSnag = null;
        snagPhotoFiles = [];
        draggingSnag = null;
        setModes({});
        renderPhotoThumbs();
        if (!popupSnagId) {
            selectedSnagId = null;
        }
        if (!keepRoom) {
            renderMarkers();
        }
    }

    function setSnagError(text) {
        const box = document.getElementById('snag-error');
        if (!box) {
            return;
        }
        box.textContent = text || '';
        box.classList.toggle('hidden', !text);
    }

    function resetSnagPhotos() {
        snagPhotoFiles = [];
        const file = document.getElementById('snag-photo');
        const camera = document.getElementById('snag-photo-camera');
        if (file) {
            file.value = '';
        }
        if (camera) {
            camera.value = '';
        }
        renderPhotoThumbs();
    }

    function renderPhotoThumbs() {
        const box = document.getElementById('snag-existing-photos');
        if (!box) {
            return;
        }
        const saved = (currentSnag?.photos || []).map((photo) => (
            `<div class="snag-thumb-card"><img src="${escapeHtml(photo.url)}" alt="" class="snag-thumb"><span>${escapeHtml(photo.type_label || (photo.type === 'completion' ? 'Gereedfoto' : 'Constatering'))}</span></div>`
        ));
        const pending = snagPhotoFiles.map((file, index) => (
            `<div class="snag-thumb-card"><img src="${URL.createObjectURL(file)}" alt="" class="snag-thumb"><span>Nieuw ${index + 1}</span></div>`
        ));
        box.innerHTML = [...saved, ...pending].join('');
    }

    function fillSnagForm(snag) {
        document.getElementById('snag-title').textContent = snag.title || `Opleverpunt #${snag.number || nextSnagNumber()}`;
        document.getElementById('snag-status-label').textContent = snag.status_label || 'Nieuw';
        const help = document.getElementById('snag-help');
        if (help) {
            help.textContent = snag.id
                ? `${snag.area || 'Geen ruimte'} · ${snag.worker || 'Nog geen vakman'}`
                : 'Foto, korte tekst, vakman. Klaar.';
        }
        document.getElementById('snag-description').value = snag.description || '';
        document.getElementById('snag-area').value = snag.area_id || '';
        document.getElementById('snag-worker').value = snag.assigned_worker_id || '';
        document.getElementById('snag-due').value = snag.due_date || '';
        document.getElementById('snag-logged').value = snag.logged_on || new Date().toISOString().slice(0, 10);
        document.getElementById('snag-priority').value = snag.priority || 'normal';
        const statusSelect = document.getElementById('snag-status');
        if (statusSelect) {
            statusSelect.value = snag.status || 'open';
            applyStatusSelect(statusSelect, !snag.id);
        }
        const review = document.getElementById('snag-review');
        review?.classList.toggle('hidden', snag.status !== 'reported_done');
        document.getElementById('snag-move')?.classList.toggle('hidden', !snag.id);
        document.getElementById('snag-delete')?.classList.toggle('hidden', !snag.id);
        document.getElementById('snag-send')?.classList.toggle('hidden', snag.status === 'closed' || snag.status === 'reported_done');
        const photoHint = document.getElementById('snag-photo-hint');
        if (photoHint) {
            photoHint.textContent = snag.id
                ? photoHintForStatus(snag.status)
                : 'Constateringfoto. Op telefoon opent de camera.';
        }
        const note = document.getElementById('snag-review-note');
        if (note) {
            note.value = '';
        }
        renderPhotoThumbs();
    }

    function openNewSnag(point) {
        const area = nearestAreaAt(point);
        const number = nextSnagNumber();
        pendingSnag = {
            x: point.x,
            y: point.y,
            drawing_page: page,
            page,
            document_id: drawing?.id || '',
            project_area_id: area?.id || '',
            number,
            tone: 'open',
        };
        editingSnagId = null;
        currentSnag = null;
        snagPhotoFiles = [];
        setModes({});
        if (layer === 'rooms') {
            layer = 'both';
            document.querySelectorAll('.layer-btn').forEach((item) => item.classList.toggle('is-on', item.dataset.layer === 'both'));
        }
        snagForm.reset();
        setSnagError('');
        closeSnagPopup();
        fillSnagForm({
            number,
            title: `Opleverpunt #${number}`,
            status_label: 'Nieuw',
            area_id: area?.id || '',
            logged_on: new Date().toISOString().slice(0, 10),
        });
        showSnagPanel();
        const roomLabel = area ? roomTitle(area) : '';
        setHint(roomLabel ? `Punt ${number} → ${roomLabel}` : `Punt ${number} gezet. Vul rechts het formulier in.`);
        renderMarkers();
    }

    async function compressImage(file) {
        if (!file || !file.type.startsWith('image/') || file.size < 900000) {
            return file;
        }
        try {
            const bitmap = await createImageBitmap(file);
            const max = 1920;
            const scaleDown = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
            const width = Math.round(bitmap.width * scaleDown);
            const height = Math.round(bitmap.height * scaleDown);
            const canvasEl = document.createElement('canvas');
            canvasEl.width = width;
            canvasEl.height = height;
            canvasEl.getContext('2d').drawImage(bitmap, 0, 0, width, height);
            const blob = await new Promise((resolve) => canvasEl.toBlob(resolve, 'image/jpeg', 0.82));
            bitmap.close();
            return blob ? new File([blob], 'opleverpunt.jpg', { type: 'image/jpeg' }) : file;
        } catch (error) {
            return file;
        }
    }

    function addSnagFiles(files) {
        [...files].forEach((file) => {
            if (file?.type?.startsWith('image/')) {
                snagPhotoFiles.push(file);
            }
        });
        renderPhotoThumbs();
    }

    function firstValidationError(payload) {
        if (payload?.message && !payload.errors) {
            return payload.message;
        }
        const errors = payload?.errors || {};
        const first = Object.values(errors).flat()[0];
        return first || payload?.message || 'Opslaan mislukt.';
    }

    function storeSnag(snag) {
        const index = snags.findIndex((item) => Number(item.id) === Number(snag.id));
        if (index >= 0) {
            snags[index] = snag;
        } else {
            snags.push(snag);
        }
        data.next_snag_number = nextSnagNumber();
    }

    function removeSnag(id) {
        const index = snags.findIndex((item) => Number(item.id) === Number(id));
        if (index >= 0) {
            snags.splice(index, 1);
        }
    }

    function upsertSnag(snag) {
        storeSnag(snag);
        currentSnag = snag;
        editingSnagId = snag.id;
        selectedSnagId = snag.id;
        pendingSnag = null;
        renderMarkers();
        fillSnagForm(snag);
        if (Number(popupSnagId) === Number(snag.id)) {
            fillSnagPopup(snag);
        }
    }

    async function saveSnag(notify) {
        if (snagSaving) {
            return;
        }
        setSnagError('');
        const description = document.getElementById('snag-description').value.trim();
        const workerId = document.getElementById('snag-worker').value;
        if (!description || !workerId) {
            setSnagError('Omschrijving en vakman zijn verplicht.');
            return;
        }
        const nextStatus = document.getElementById('snag-status')?.value;
        if (editingSnagId && nextStatus === 'closed' && currentSnag?.status !== 'closed') {
            if (!data.canCloseSnags || !confirm('Dit opleverpunt als Afgehandeld markeren?')) {
                return;
            }
        }
        if (editingSnagId && nextStatus && nextStatus !== currentSnag?.status && nextStatus !== 'closed' && !data.canAdvanceSnagStatus) {
            return;
        }
        snagSaving = true;
        const saveBtn = document.getElementById('snag-save');
        const sendBtn = document.getElementById('snag-send');
        if (saveBtn) {
            saveBtn.disabled = true;
        }
        if (sendBtn) {
            sendBtn.disabled = true;
        }
        try {
            const form = new FormData(snagForm);
            form.set('description', description);
            form.set('assigned_worker_id', workerId);
            form.set('notify', notify ? '1' : '0');
            if (!data.canAdvanceSnagStatus) {
                form.delete('status');
            } else if (nextStatus === 'closed' && !data.canCloseSnags) {
                form.set('status', currentSnag?.status || 'open');
            }
            if (pendingSnag) {
                ['x', 'y', 'drawing_page', 'document_id'].forEach((key) => {
                    if (pendingSnag[key] != null && pendingSnag[key] !== '') {
                        form.set(key, pendingSnag[key]);
                    }
                });
            }
            const files = await Promise.all(snagPhotoFiles.map((file) => compressImage(file)));
            files.forEach((file) => form.append('photos[]', file));
            const url = editingSnagId ? route('snagUpdate', editingSnagId) : routes.snagStore;
            if (editingSnagId) {
                form.append('_method', 'PATCH');
            }
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: form,
            });
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                setSnagError(firstValidationError(error));
                return;
            }
            const payload = await response.json();
            snagPhotoFiles = [];
            if (payload.snag) {
                upsertSnag(payload.snag);
            }
            setModes({});
            const workerEmail = (data.workers || []).find((item) => String(item.id) === String(payload.snag?.assigned_worker_id))?.email;
            if (notify && !workerEmail) {
                setHint(`Punt ${payload.snag?.number || ''} opgeslagen. Deze vakman heeft geen e-mail; vul die in bij Vakmensen.`);
            } else {
                setHint(notify
                    ? `Punt ${payload.snag?.number || ''} verstuurd naar ${payload.snag?.worker || 'de vakman'}.`
                    : `Opleverpunt ${payload.snag?.number || ''} opgeslagen.`);
            }
            if (!editingSnagId && payload.snag) {
                fillSnagForm(payload.snag);
            }
        } finally {
            snagSaving = false;
            if (saveBtn) {
                saveBtn.disabled = false;
            }
            if (sendBtn) {
                sendBtn.disabled = false;
            }
        }
    }

    async function applySnagMove(point) {
        if (pendingSnag) {
            pendingSnag.x = point.x;
            pendingSnag.y = point.y;
            pendingSnag.drawing_page = page;
            pendingSnag.page = page;
            const area = nearestAreaAt(point);
            pendingSnag.project_area_id = area?.id || pendingSnag.project_area_id;
            if (area) {
                document.getElementById('snag-area').value = String(area.id);
            }
            setModes({});
            renderMarkers();
            setHint('Positie aangepast.');
            return;
        }
        if (!editingSnagId) {
            return;
        }
        const form = new FormData();
        form.append('_method', 'PATCH');
        form.append('x', point.x);
        form.append('y', point.y);
        form.append('drawing_page', page);
        const area = nearestAreaAt(point);
        if (area) {
            form.append('project_area_id', area.id);
            document.getElementById('snag-area').value = String(area.id);
        }
        const response = await fetch(route('snagUpdate', editingSnagId), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: form,
        });
        if (!response.ok) {
            setHint('Positie opslaan mislukt.');
            setModes({});
            return;
        }
        const payload = await response.json();
        if (payload.snag) {
            upsertSnag(payload.snag);
        }
        setModes({});
        setHint('Positie aangepast. Nummer, foto en tekst blijven hetzelfde.');
    }

    function snagById(id) {
        return snags.find((item) => Number(item.id) === Number(id)) || null;
    }

    function snagThumbUrl(snag) {
        const photos = snag?.photos || [];
        const issue = photos.find((photo) => photo.type === 'issue');
        return issue?.url || photos[0]?.url || snag?.thumb || '';
    }

    function isSnagPanelOpen() {
        return Boolean(snagPanel && !snagPanel.classList.contains('hidden'));
    }

    function isMobilePopup() {
        return window.matchMedia('(max-width: 700px)').matches;
    }

    async function loadSnagById(id) {
        const cached = snagById(id);
        try {
            const response = await fetch(route('snagShow', id), { headers });
            if (!response.ok) {
                return cached;
            }
            const payload = await response.json();
            if (payload.snag) {
                storeSnag(payload.snag);
                return payload.snag;
            }
        } catch (error) {
            return cached;
        }

        return cached;
    }

    function fillSnagPopup(snag) {
        const popup = document.getElementById('snag-popup');
        if (!popup || !snag) {
            return;
        }
        popup.dataset.snagId = String(snag.id);
        document.getElementById('snag-popup-title').textContent = snag.title || `Opleverpunt #${snag.number}`;
        document.getElementById('snag-popup-status-label').textContent = snag.status_label || '';
        document.getElementById('snag-popup-area').textContent = snag.area || 'Niet gekoppeld';
        document.getElementById('snag-popup-description').textContent = snag.description || '—';
        document.getElementById('snag-popup-due').textContent = snag.due || 'Geen deadline';
        const thumbUrl = snagThumbUrl(snag);
        const thumbBtn = document.getElementById('snag-popup-thumb-btn');
        const thumbImg = document.getElementById('snag-popup-thumb');
        thumbBtn?.classList.toggle('hidden', !thumbUrl);
        if (thumbImg) {
            thumbImg.src = thumbUrl || '';
        }
        const workerSelect = document.getElementById('snag-popup-worker');
        if (workerSelect) {
            popupWorkerLock = true;
            ensurePopupWorkerOption(workerSelect, snag);
            workerSelect.value = snag.assigned_worker_id ? String(snag.assigned_worker_id) : '';
            popupWorkerLock = false;
        }
        const statusSelect = document.getElementById('snag-popup-status');
        if (statusSelect) {
            popupStatusLock = true;
            statusSelect.value = snag.status || 'open';
            applyStatusSelect(statusSelect);
            popupStatusLock = false;
        }
        const hint = document.getElementById('snag-popup-photo-hint');
        if (hint) {
            hint.textContent = photoHintForStatus(snag.status);
        }
        renderPopupPhotoThumbs(snag);
    }

    function photoHintForStatus(status) {
        return status === 'reported_done' || status === 'closed'
            ? 'Gereedfoto. Op telefoon opent de camera.'
            : 'Foto bij deze status. Op telefoon opent de camera.';
    }

    function renderPopupPhotoThumbs(snag) {
        const box = document.getElementById('snag-popup-thumbs');
        if (!box) {
            return;
        }
        const current = snag || snagById(popupSnagId);
        const saved = (current?.photos || []).map((photo) => (
            `<div class="snag-thumb-card"><img src="${escapeHtml(photo.url)}" alt="" class="snag-thumb"><span>${escapeHtml(photo.type_label || (photo.type === 'completion' ? 'Gereedfoto' : 'Constatering'))}</span></div>`
        ));
        const pending = popupPhotoFiles.map((file, index) => (
            `<div class="snag-thumb-card"><img src="${URL.createObjectURL(file)}" alt="" class="snag-thumb"><span>Nieuw ${index + 1}</span></div>`
        ));
        box.innerHTML = [...saved, ...pending].join('');
    }

    function ensurePopupWorkerOption(select, snag) {
        if (!snag?.assigned_worker_id) {
            return;
        }
        const value = String(snag.assigned_worker_id);
        if ([...select.options].some((option) => option.value === value)) {
            return;
        }
        const option = document.createElement('option');
        option.value = value;
        option.textContent = snag.worker || 'Vakman';
        select.append(option);
    }

    function positionSnagPopup() {
        const popup = document.getElementById('snag-popup');
        if (!popup || popup.classList.contains('hidden') || !popupSnagId) {
            return;
        }
        if (isMobilePopup()) {
            popup.style.left = '';
            popup.style.top = '';
            return;
        }
        const pin = markersEl.querySelector(`.snag-pin[data-snag-id="${popupSnagId}"]`);
        const stageRect = stage.getBoundingClientRect();
        const popW = popup.offsetWidth || 300;
        const popH = popup.offsetHeight || 280;
        let left = 12;
        let top = 12;
        if (pin) {
            const pinRect = pin.getBoundingClientRect();
            left = pinRect.right - stageRect.left + 10;
            top = pinRect.top - stageRect.top - 8;
            if (left + popW > stageRect.width - 8) {
                left = pinRect.left - stageRect.left - popW - 10;
            }
        }
        left = Math.max(8, Math.min(left, stageRect.width - popW - 8));
        top = Math.max(8, Math.min(top, stageRect.height - popH - 8));
        popup.style.left = `${left}px`;
        popup.style.top = `${top}px`;
    }

    function closeSnagPopup({ keepSelection = false } = {}) {
        popupToken += 1;
        const popup = document.getElementById('snag-popup');
        popup?.classList.add('hidden');
        if (popup) {
            popup.hidden = true;
        }
        popupSnagId = null;
        popupPhotoFiles = [];
        if (!keepSelection && !isSnagPanelOpen()) {
            selectedSnagId = null;
            renderMarkers();
        }
    }

    function revealSnagPopup() {
        const popup = document.getElementById('snag-popup');
        if (!popup) {
            return;
        }
        popup.hidden = false;
        popup.classList.remove('hidden');
        positionSnagPopup();
    }

    function snagTone(status) {
        return {
            open: 'open',
            assigned: 'assigned',
            in_progress: 'progress',
            reported_done: 'wait',
            closed: 'done',
        }[status] || 'open';
    }

    function showPopupFor(snag) {
        currentSnag = snag;
        selectedSnagId = snag.id;
        if (Number(popupSnagId) !== Number(snag.id)) {
            popupPhotoFiles = [];
        }
        popupSnagId = snag.id;
        if (layer === 'rooms') {
            layer = 'both';
            document.querySelectorAll('.layer-btn').forEach((item) => item.classList.toggle('is-on', item.dataset.layer === 'both'));
        }
        fillSnagPopup(snag);
        revealSnagPopup();
        renderMarkers();
        if (isSnagPanelOpen()) {
            editingSnagId = snag.id;
            fillSnagForm(snag);
        }
    }

    async function openSnagPopup(id) {
        const token = ++popupToken;
        const cached = snagById(id);
        if (cached) {
            showPopupFor(cached);
        }
        const snag = await loadSnagById(id);
        if (token !== popupToken || !snag?.id) {
            return;
        }
        if (snagPage(snag) && snagPage(snag) !== page) {
            page = snagPage(snag);
            pageSelect.value = String(page);
            if (pdfDoc) {
                await renderPdfPage();
            }
            if (token !== popupToken) {
                return;
            }
        }
        showPopupFor(snag);
    }

    async function changePopupStatus(status) {
        const id = popupSnagId;
        const snag = snagById(id);
        if (!id || !status || !snag || snag.status === status || popupSaving) {
            return;
        }
        if (status === 'closed' && !data.canCloseSnags) {
            revertPopupStatus(snag);
            return;
        }
        if (status !== 'closed' && !data.canAdvanceSnagStatus) {
            revertPopupStatus(snag);
            return;
        }
        if (status === 'closed' && snag.status !== 'closed') {
            if (!confirm('Dit opleverpunt als Afgehandeld markeren?')) {
                const select = document.getElementById('snag-popup-status');
                if (select) {
                    popupStatusLock = true;
                    select.value = snag.status;
                    popupStatusLock = false;
                }
                return;
            }
        }
        popupSaving = true;
        const queued = popupPhotoFiles.splice(0);
        const previous = {
            status: snag.status,
            tone: snag.tone,
            status_label: snag.status_label,
        };
        const select = document.getElementById('snag-popup-status');
        const optionLabel = [...(select?.options || [])].find((option) => option.value === status)?.textContent;
        snag.status = status;
        snag.tone = snagTone(status);
        snag.status_label = optionLabel || status;
        storeSnag(snag);
        renderMarkers();
        if (Number(popupSnagId) === Number(id)) {
            fillSnagPopup(snag);
            positionSnagPopup();
        }
        try {
            const files = await Promise.all(queued.map((file) => compressImage(file)));
            const form = new FormData();
            form.append('_method', 'PATCH');
            form.append('status', status);
            files.forEach((file) => form.append('photos[]', file));
            const response = await fetch(route('snagUpdate', id), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: form,
            });
            if (!response.ok) {
                const current = snagById(id);
                if (current) {
                    current.status = previous.status;
                    current.tone = previous.tone;
                    current.status_label = previous.status_label;
                    storeSnag(current);
                    renderMarkers();
                    if (Number(popupSnagId) === Number(id)) {
                        fillSnagPopup(current);
                    }
                }
                setHint('Status opslaan mislukt.');
                popupPhotoFiles = [...queued, ...popupPhotoFiles];
                renderPopupPhotoThumbs(snagById(id));
                return;
            }
            const payload = await response.json();
            if (!payload.snag) {
                return;
            }
            storeSnag(payload.snag);
            renderMarkers();
            if (Number(popupSnagId) === Number(payload.snag.id)) {
                currentSnag = payload.snag;
                selectedSnagId = payload.snag.id;
                fillSnagPopup(payload.snag);
                positionSnagPopup();
            }
            if (isSnagPanelOpen() && Number(editingSnagId) === Number(payload.snag.id)) {
                fillSnagForm(payload.snag);
            }
            setHint(files.length
                ? `Status: ${payload.snag.status_label}. Foto toegevoegd.`
                : `Status: ${payload.snag.status_label}.`);
        } finally {
            popupSaving = false;
        }
    }

    async function addPopupPhotos(list) {
        [...list].forEach((file) => {
            if (file?.type?.startsWith('image/')) {
                popupPhotoFiles.push(file);
            }
        });
        renderPopupPhotoThumbs();
        await uploadPopupPhotos();
    }

    async function uploadPopupPhotos() {
        const id = popupSnagId;
        const queued = popupPhotoFiles.splice(0);
        if (!id || queued.length === 0) {
            return;
        }
        const files = await Promise.all(queued.map((file) => compressImage(file)));
        const form = new FormData();
        form.append('_method', 'PATCH');
        files.forEach((file) => form.append('photos[]', file));
        const response = await fetch(route('snagUpdate', id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: form,
        });
        if (!response.ok) {
            popupPhotoFiles = [...queued, ...popupPhotoFiles];
            renderPopupPhotoThumbs(snagById(id));
            setHint('Foto opslaan mislukt.');
            return;
        }
        const payload = await response.json();
        if (!payload.snag) {
            return;
        }
        storeSnag(payload.snag);
        currentSnag = payload.snag;
        renderMarkers();
        if (Number(popupSnagId) === Number(payload.snag.id)) {
            fillSnagPopup(payload.snag);
            positionSnagPopup();
        }
        if (isSnagPanelOpen() && Number(editingSnagId) === Number(payload.snag.id)) {
            fillSnagForm(payload.snag);
        }
        const gereed = payload.snag.status === 'reported_done' || payload.snag.status === 'closed';
        setHint(gereed ? 'Gereedfoto toegevoegd.' : 'Foto toegevoegd.');
    }

    async function changePopupWorker(workerId) {
        const id = popupSnagId;
        const snag = snagById(id);
        if (!id || !workerId || !snag || String(snag.assigned_worker_id) === String(workerId)) {
            return;
        }
        const previous = {
            assigned_worker_id: snag.assigned_worker_id,
            worker: snag.worker,
        };
        const select = document.getElementById('snag-popup-worker');
        const optionLabel = [...(select?.options || [])].find((option) => String(option.value) === String(workerId))?.textContent;
        snag.assigned_worker_id = Number(workerId);
        snag.worker = optionLabel || snag.worker;
        storeSnag(snag);
        if (Number(popupSnagId) === Number(id)) {
            fillSnagPopup(snag);
            positionSnagPopup();
        }
        const form = new FormData();
        form.append('_method', 'PATCH');
        form.append('assigned_worker_id', workerId);
        const response = await fetch(route('snagUpdate', id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: form,
        });
        if (!response.ok) {
            const current = snagById(id);
            if (current) {
                current.assigned_worker_id = previous.assigned_worker_id;
                current.worker = previous.worker;
                storeSnag(current);
                if (Number(popupSnagId) === Number(id)) {
                    fillSnagPopup(current);
                }
            }
            setHint('Vakman opslaan mislukt.');
            return;
        }
        const payload = await response.json();
        if (!payload.snag) {
            return;
        }
        storeSnag(payload.snag);
        renderMarkers();
        if (Number(popupSnagId) === Number(payload.snag.id)) {
            currentSnag = payload.snag;
            selectedSnagId = payload.snag.id;
            fillSnagPopup(payload.snag);
            positionSnagPopup();
        }
        if (isSnagPanelOpen() && Number(editingSnagId) === Number(payload.snag.id)) {
            fillSnagForm(payload.snag);
        }
        setHint(`Vakman: ${payload.snag.worker || 'gewijzigd'}.`);
    }

    async function deleteCurrentSnag(id) {
        if (!data.canDeleteSnags || !id) {
            return;
        }
        if (!confirm('Dit opleverpunt definitief verwijderen?')) {
            return;
        }
        const form = new FormData();
        form.append('_method', 'DELETE');
        const response = await fetch(route('snagDestroy', id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: form,
        });
        if (!response.ok) {
            setHint('Verwijderen mislukt.');
            return;
        }
        removeSnag(id);
        closeSnagPopup();
        closeSnagPanel();
        renderMarkers();
        setHint('Opleverpunt verwijderd.');
    }

    function openSnagPhotoPreview(url) {
        const box = document.getElementById('snag-photo-preview');
        const img = document.getElementById('snag-photo-preview-img');
        if (!box || !img || !url) {
            return;
        }
        img.src = url;
        box.hidden = false;
        box.classList.remove('hidden');
    }

    function closeSnagPhotoPreview() {
        const box = document.getElementById('snag-photo-preview');
        if (!box || box.classList.contains('hidden')) {
            return false;
        }
        box.classList.add('hidden');
        box.hidden = true;
        const img = document.getElementById('snag-photo-preview-img');
        if (img) {
            img.src = '';
        }
        return true;
    }

    async function openExistingSnag(id, options = {}) {
        const snag = await loadSnagById(id);
        if (!snag) {
            return;
        }
        pendingSnag = null;
        snagPhotoFiles = [];
        currentSnag = snag;
        editingSnagId = snag.id;
        selectedSnagId = snag.id;
        setSnagError('');
        if (layer === 'rooms') {
            layer = 'both';
            document.querySelectorAll('.layer-btn').forEach((item) => item.classList.toggle('is-on', item.dataset.layer === 'both'));
        }
        if (snagPage(snag) && snagPage(snag) !== page) {
            page = snagPage(snag);
            pageSelect.value = String(page);
            if (pdfDoc) {
                await renderPdfPage();
            }
        }
        closeSnagPopup({ keepSelection: true });
        fillSnagForm(snag);
        showSnagPanel();
        renderMarkers();
        if (!options.skipZoom) {
            centerOnPoint(snag.x, snag.y);
        }
    }

    function centerOnPoint(x, y) {
        if (x == null || y == null) {
            return;
        }
        scale = Math.max(scale, 1.35);
        const rect = stage.getBoundingClientRect();
        const width = world.offsetWidth * scale;
        const height = world.offsetHeight * scale;
        panX = rect.width / 2 - Number(x) * width;
        panY = rect.height / 2 - Number(y) * height;
        applyTransform();
    }

    async function postSnagAction(name, extra = {}) {
        if (!editingSnagId) {
            return;
        }
        const form = new FormData();
        Object.entries(extra).forEach(([key, value]) => {
            if (value != null) {
                form.append(key, value);
            }
        });
        const response = await fetch(route(name, editingSnagId), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: form,
        });
        if (!response.ok) {
            const error = await response.json().catch(() => ({}));
            setSnagError(firstValidationError(error));
            return;
        }
        const payload = await response.json();
        if (payload.snag) {
            upsertSnag(payload.snag);
        }
    }

    document.getElementById('snag-camera-btn')?.addEventListener('click', () => {
        document.getElementById('snag-photo-camera')?.click();
    });
    document.getElementById('snag-library-btn')?.addEventListener('click', () => {
        document.getElementById('snag-photo')?.click();
    });
    document.getElementById('snag-photo-camera')?.addEventListener('change', (event) => {
        addSnagFiles(event.target.files || []);
        event.target.value = '';
    });
    document.getElementById('snag-photo')?.addEventListener('change', (event) => {
        addSnagFiles(event.target.files || []);
        event.target.value = '';
    });
    document.getElementById('snag-cancel')?.addEventListener('click', () => closeSnagPanel());
    document.getElementById('snag-popup-camera-btn')?.addEventListener('click', () => {
        document.getElementById('snag-popup-photo-camera')?.click();
    });
    document.getElementById('snag-popup-library-btn')?.addEventListener('click', () => {
        document.getElementById('snag-popup-photo')?.click();
    });
    document.getElementById('snag-popup-photo-camera')?.addEventListener('change', (event) => {
        addPopupPhotos(event.target.files || []);
        event.target.value = '';
    });
    document.getElementById('snag-popup-photo')?.addEventListener('change', (event) => {
        addPopupPhotos(event.target.files || []);
        event.target.value = '';
    });
    document.getElementById('snag-popup-close')?.addEventListener('click', () => closeSnagPopup());
    document.getElementById('snag-popup-dismiss')?.addEventListener('click', () => closeSnagPopup());
    document.getElementById('snag-popup-delete')?.addEventListener('click', () => {
        if (popupSnagId) {
            deleteCurrentSnag(popupSnagId);
        }
    });
    document.getElementById('snag-delete')?.addEventListener('click', () => {
        if (editingSnagId) {
            deleteCurrentSnag(editingSnagId);
        }
    });
    document.getElementById('snag-popup-edit')?.addEventListener('click', () => {
        if (popupSnagId) {
            openExistingSnag(popupSnagId, { skipZoom: true });
        }
    });
    document.getElementById('snag-popup-status-btn')?.addEventListener('click', () => {
        document.getElementById('snag-popup-status')?.focus();
    });
    document.getElementById('snag-popup-worker-btn')?.addEventListener('click', () => {
        document.getElementById('snag-popup-worker')?.focus();
    });
    document.getElementById('snag-popup-move')?.addEventListener('click', () => {
        if (!popupSnagId) {
            return;
        }
        editingSnagId = popupSnagId;
        selectedSnagId = popupSnagId;
        currentSnag = snagById(popupSnagId);
        closeSnagPopup({ keepSelection: true });
        setModes({ move: true });
        renderMarkers();
        setHint('Sleep de marker of tik de nieuwe plek aan.');
    });
    document.getElementById('snag-popup-status')?.addEventListener('change', (event) => {
        if (popupStatusLock) {
            return;
        }
        changePopupStatus(event.target.value);
    });
    document.getElementById('snag-popup-worker')?.addEventListener('change', (event) => {
        if (popupWorkerLock) {
            return;
        }
        changePopupWorker(event.target.value);
    });
    document.getElementById('snag-popup-thumb-btn')?.addEventListener('click', (event) => {
        event.stopPropagation();
        openSnagPhotoPreview(snagThumbUrl(snagById(popupSnagId)));
    });
    document.getElementById('snag-photo-preview')?.addEventListener('click', (event) => {
        if (event.target.id === 'snag-photo-preview' || event.target.id === 'snag-photo-preview-close') {
            closeSnagPhotoPreview();
        }
    });
    window.addEventListener('resize', () => {
        syncBoardOverlayTop();
        positionSnagPopup();
    });
    document.getElementById('snag-move')?.addEventListener('click', () => {
        setModes({ move: true });
        showSnagPanel();
    });
    document.getElementById('snag-send')?.addEventListener('click', () => saveSnag(true));
    document.getElementById('snag-approve')?.addEventListener('click', async () => {
        await postSnagAction('snagApprove');
        setHint('Afgehandeld. Marker is groen.');
    });
    document.getElementById('snag-reject')?.addEventListener('click', async () => {
        await postSnagAction('snagReject', { note: document.getElementById('snag-review-note')?.value || '' });
        setHint('Afgekeurd. De vakman krijgt opnieuw een bericht.');
    });
    snagForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        await saveSnag(false);
    });

    syncBoardOverlayTop();
    const overlayObserver = typeof ResizeObserver === 'function'
        ? new ResizeObserver(() => syncBoardOverlayTop())
        : null;
    overlayObserver?.observe(root);
    const overlayToolbar = document.querySelector('.draw-toolbar');
    if (overlayToolbar) {
        overlayObserver?.observe(overlayToolbar);
    }

    function ticketError(message) {
        const el = document.getElementById('ticket-error');
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.hidden = !message;
        el.classList.toggle('hidden', !message);
    }

    function renderTicketPanel() {
        const list = document.getElementById('ticket-chunks');
        if (!list) {
            return;
        }
        if (ticketChunks.length === 0) {
            list.innerHTML = '<p class="ticket-empty">Kies materialen, of klik Hele werk voor alle verdiepingen. Daarna Selectie toevoegen.</p>';
        } else {
            list.innerHTML = ticketChunks.map((chunk, index) => {
                const lines = (chunk.lines || [])
                    .map((line) => `<div class="ticket-chunk-line">${escapeHtml(line.label)} – ${escapeHtml(line.qty_label)}</div>`)
                    .join('');
                const rooms = chunk.entire
                    ? 'Hele verdieping'
                    : (chunk.rooms_label ? `Ruimtes: ${chunk.rooms_label}` : 'Ruimtes geselecteerd');
                return `<article class="ticket-chunk" data-index="${index}"><div class="ticket-chunk-head"><span>${escapeHtml(chunk.floor || 'Verdieping')}</span><button type="button" class="ticket-chunk-remove" data-ticket-remove="${index}" aria-label="Selectie verwijderen">×</button></div>${lines}<div class="ticket-chunk-rooms">${escapeHtml(rooms)}</div></article>`;
            }).join('');
        }
        fillTicketPreview();
    }

    function fillTicketPreview() {
        const preview = document.getElementById('ticket-preview-body');
        if (!preview) {
            return;
        }
        if (ticketChunks.length === 0) {
            preview.innerHTML = 'Nog geen selectie op de bon.';
            return;
        }
        preview.innerHTML = ticketChunks.map((chunk) => {
            const lines = (chunk.lines || [])
                .map((line) => `${escapeHtml(line.label)} – ${escapeHtml(line.qty_label)}`)
                .join('<br>');
            const rooms = chunk.entire ? 'Hele verdieping' : `Ruimtes: ${escapeHtml(chunk.rooms_label || '—')}`;
            return `<div><strong>${escapeHtml(chunk.floor || 'Verdieping')}</strong><br>${lines}<br>${rooms}</div>`;
        }).join('<hr class="ticket-preview-split">');
    }

    function addTicketSelection() {
        ticketError('');
        const rooms = pickedList().map((id) => areaById(id)).filter(Boolean);
        if (rooms.length === 0) {
            ticketError('Selecteer ruimtes, kies Deze verdieping, of klik Hele werk.');
            setHint('Klik Hele werk voor alle verdiepingen, of tik ruimtes aan op de tekening.');
            return;
        }
        const keys = hasWorkFilter() ? workFilterKeys : workKeysOnRooms(rooms);
        if (keys.length === 0) {
            ticketError('Kies eerst een of meer materialen.');
            setHint('Kies materialen bovenaan, of klik Hele werk om alle werkzaamheden mee te nemen.');
            return;
        }
        groupRoomsByFloor(rooms).forEach((floorRooms, floorId) => {
            const entire = isEntireFloorPick(areas, floorRooms, floorId, keys);
            ticketChunks.push(buildTicketChunk({
                floor: floorRooms[0]?.floor || selectedFloorName(),
                floorId,
                keys,
                rooms: floorRooms,
                entire,
                filters: data.work_filters || [],
            }));
        });
        pickedIds = new Set();
        selectedId = 0;
        root.dataset.selected = '';
        highlightList();
        renderMarkers();
        syncRoomMeasureMode();
        renderTicketPanel();
        document.querySelector('.board-right')?.classList.add('is-open');
        document.querySelector('.board-left')?.classList.remove('is-open');
        setHint('Selectie toegevoegd. Kies eventueel een andere verdieping of sla de bon op.');
    }

    function removeTicketChunk(index) {
        ticketChunks = ticketChunks.filter((_, i) => i !== index);
        renderTicketPanel();
    }

    function appendTicketField(form, name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value == null ? '' : String(value);
        form.appendChild(input);
    }

    function ticketBillingMethod() {
        return document.querySelector('input[name="ticket-billing"]:checked')?.value || 'unit';
    }

    function saveTicket() {
        if (!ticketMode) {
            return;
        }
        ticketError('');
        if (ticketChunks.length === 0) {
            ticketError('Voeg eerst een selectie toe.');
            return;
        }
        const payload = ticketStorePayload(ticketChunks, {
            notes: document.getElementById('ticket-notes')?.value || '',
            document_ids: ticketMode.document_id ? [ticketMode.document_id] : [],
        });
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = ticketMode.store_url;
        appendTicketField(form, '_token', csrf);
        payload.selections.forEach((selection, index) => {
            appendTicketField(form, `selections[${index}][floor_id]`, selection.floor_id);
            appendTicketField(form, `selections[${index}][entire]`, selection.entire);
            selection.area_ids.forEach((id) => {
                appendTicketField(form, `selections[${index}][area_ids][]`, id);
            });
            selection.work_keys.forEach((key) => {
                appendTicketField(form, `selections[${index}][work_keys][]`, key);
            });
        });
        if (payload.notes) {
            appendTicketField(form, 'notes', payload.notes);
        }
        payload.document_ids.forEach((id) => {
            appendTicketField(form, 'document_ids[]', id);
        });
        if (ticketMode.is_external) {
            const billing = ticketBillingMethod();
            appendTicketField(form, 'billing_method', billing);
            if (billing === 'hourly') {
                appendTicketField(form, 'hourly_rate', document.getElementById('ticket-hourly-rate')?.value || '');
            }
            if (billing === 'fixed') {
                appendTicketField(form, 'fixed_price', document.getElementById('ticket-fixed-price')?.value || '');
            }
        }
        document.body.appendChild(form);
        form.submit();
    }

    function bindTicketPanel() {
        if (!ticketMode) {
            return;
        }
        document.getElementById('ticket-add')?.addEventListener('click', addTicketSelection);
        document.getElementById('ticket-save')?.addEventListener('click', saveTicket);
        document.getElementById('ticket-preview')?.addEventListener('click', () => {
            const preview = document.getElementById('ticket-preview-body');
            if (!preview) {
                return;
            }
            const open = preview.hidden;
            fillTicketPreview();
            preview.hidden = !open;
            preview.classList.toggle('hidden', !open);
            document.getElementById('ticket-preview')?.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.getElementById('ticket-chunks')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-ticket-remove]');
            if (!button) {
                return;
            }
            removeTicketChunk(Number(button.dataset.ticketRemove));
        });
        renderTicketPanel();
        document.querySelector('.board-right')?.classList.add('is-open');
        setHint('Kies materialen of klik Hele werk. Daarna Selectie toevoegen. Wissel van pagina voor een andere verdiepingstekening.');
    }

    setTool('hand');
    if (layer === 'both') {
        document.querySelectorAll('.layer-btn').forEach((item) => {
            item.classList.toggle('is-on', item.dataset.layer === 'both');
        });
    }
    applyTransform();
    setHint(ticketMode
        ? 'Kies materialen of klik Hele werk. Daarna Selectie toevoegen.'
        : 'Tik op + Opleverpunt, zet het op de tekening, foto, tekst, versturen.');
    bindTaskCards();
    bindTicketPanel();
    refreshCompleteForm();
    loadDrawing().catch(() => {
        setHint('Tekening kon niet worden geladen.');
    }).finally(() => {
        if (ticketMode) {
            setRoomMeasureMode(true);
            setHint('Kies materialen of klik Hele werk. Daarna Selectie toevoegen. Wissel van pagina voor een andere verdiepingstekening.');
            return;
        }
        if (selectedId) {
            selectArea(selectedId, { keepPage: true });
        }
        const openId = Number(root.dataset.openSnag || 0);
        if (openId) {
            openExistingSnag(openId);
        }
    });
}
