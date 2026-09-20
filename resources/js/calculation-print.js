import * as pdfjsLib from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { viewerRenderScale } from './room-geometry';
import { VIEW_RENDER_SCALE } from './pdf-text-layer';
import {
    hydrateRoomMarkers,
    paintCalculationOverlays,
    printDrawingSheets,
    printImageFromCanvas,
    printLegendGoesBelow,
    printPaper,
    waitForPrintAssets,
    roomsForDrawing,
} from './calculation-board-overlay';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const PRINT_RENDER_SCALE = Math.max(VIEW_RENDER_SCALE, 4);
const PRINT_MAX_EDGE = 3200;
let printReady = Promise.resolve();
let autoPrint = false;

async function niconPrintCalculation() {
    try {
        await printReady;
        await waitUntilPrintable();
    } catch (error) {
        console.error(error);
        if (document.querySelector('.calc-print-drawing-page')) {
            return;
        }
    }
    const title = document.title;
    document.title = document.body?.dataset.printTitle || title;
    const restore = () => {
        document.title = title;
        window.removeEventListener('afterprint', restore);
    };
    window.addEventListener('afterprint', restore);
    window.print();
}

if (typeof window !== 'undefined') {
    window.niconPrintCalculation = niconPrintCalculation;
}

if (typeof document !== 'undefined') {
    const dataEl = document.getElementById('calc-print-data');
    if (dataEl) {
        printReady = boot(JSON.parse(dataEl.textContent)).then(async (documentData) => {
            await waitUntilPrintable(documentData);
            finish(documentData);

            return documentData;
        });
        printReady.then(() => {
            if (autoPrint) {
                return niconPrintCalculation();
            }

            return undefined;
        }, (error) => {
            const status = document.getElementById('calc-print-status');
            if (status) {
                status.textContent = 'Tekeningen konden niet worden geladen.';
                status.classList.add('is-error');
            }
            enablePrintButton();
            console.error(error);
        });
    }
}

async function boot(documentData) {
    autoPrint = Boolean(documentData.auto_print);
    const include = documentData.include || {};
    const rooms = documentData.rooms || [];
    const materialKeys = documentData.material_keys || [];
    const sheets = document.getElementById('calc-print-sheets');
    if (!sheets || !documentData.show_drawings) {
        return documentData;
    }

    for (const drawing of documentData.drawings || []) {
        const pdf = await pdfjsLib.getDocument({ url: drawing.url, withCredentials: true }).promise;
        const drawingRooms = roomsForDrawing(drawing.rooms || rooms, drawing.id);
        await hydrateRoomMarkers(pdf, drawingRooms, drawing.id);
        for (const sheet of printDrawingSheets([{ ...drawing, pageCount: pdf.numPages }])) {
            const host = createDrawingPage(sheets, documentData, drawing, sheet.page, sheet.pageCount);
            await renderDrawingPage(host, pdf, sheet.page, {
                rooms: drawingRooms,
                drawingId: drawing.id,
                materialKeys,
                colored: Boolean(include.colored),
                roomLabels: Boolean(include.rooms),
                materialCodes: Boolean(include.codes) || Boolean(include.colored),
            });
        }
    }

    return documentData;
}

function createDrawingPage(sheets, documentData, drawing, page, pageCount) {
    const pageEl = document.createElement('section');
    pageEl.className = 'calc-print-page calc-print-drawing-page';
    pageEl.dataset.drawingId = String(drawing.id);
    pageEl.dataset.page = String(page);
    const materials = drawing.materials || [];
    if (documentData.include?.legend && printLegendGoesBelow(materials)) {
        pageEl.classList.add('has-legend-below');
    }
    const label = pageCount > 1 ? `${drawing.label} · pagina ${page}` : drawing.label;
    pageEl.innerHTML = `
        <header class="calc-print-head">
            <div>
                <p class="calc-print-kicker">${escapeHtml(documentData.calculation?.name || '')}</p>
                <h1>${escapeHtml(label)}</h1>
            </div>
            <p class="calc-print-meta">A3 liggend</p>
        </header>
        <div class="calc-print-sheet${documentData.include?.legend ? '' : ' is-full'}">
            <div class="calc-print-drawing">
                <div class="calc-print-world">
                    <img class="calc-print-canvas" alt="">
                    <svg class="calc-print-hit" viewBox="0 0 1 1" preserveAspectRatio="none"></svg>
                    <div class="calc-print-markers"></div>
                </div>
            </div>
            ${documentData.include?.legend ? legendMarkup(materials) : ''}
        </div>
    `;
    sheets.append(pageEl);

    return pageEl;
}

function legendMarkup(materials) {
    const rows = materials.map((material) => {
        const color = escapeHtml(material.color || '#e7e5e4');

        return `
        <div class="calc-legend-row">
            <i class="calc-print-swatch" style="background:${color};border-color:${color};box-shadow:inset 0 0 0 8px ${color}"></i>
            <span class="calc-legend-code">${escapeHtml(material.code || '')}</span>
            <span class="calc-legend-product">${escapeHtml(material.product || '—')}</span>
            <span class="calc-legend-m2">${escapeHtml(material.m2_label || '')}</span>
        </div>
    `;
    }).join('');

    return `<aside class="calc-print-legend"><h2>Materiaallegenda</h2>${rows || '<p>Geen materialen.</p>'}</aside>`;
}

async function renderDrawingPage(host, pdf, pageNumber, paint) {
    const image = host.querySelector('.calc-print-canvas');
    const world = host.querySelector('.calc-print-world');
    const hitEl = host.querySelector('.calc-print-hit');
    const markersEl = host.querySelector('.calc-print-markers');
    const pdfPage = await pdf.getPage(pageNumber);
    const cssViewport = pdfPage.getViewport({ scale: 1 });
    const paper = printPaper(cssViewport);
    host.classList.toggle('is-landscape', paper.landscape);
    host.classList.toggle('is-portrait', !paper.landscape);
    const meta = host.querySelector('.calc-print-meta');
    if (meta) {
        meta.textContent = paper.landscape ? 'A3 liggend' : 'A3 staand';
    }
    const renderScale = printRenderScale(cssViewport);
    const renderViewport = pdfPage.getViewport({ scale: renderScale });
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { alpha: false, willReadFrequently: true });
    canvas.width = Math.max(1, Math.floor(renderViewport.width));
    canvas.height = Math.max(1, Math.floor(renderViewport.height));
    world.style.setProperty('--page-ratio', String(paper.ratio));
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    try {
        await pdfPage.render({
            canvasContext: context,
            viewport: renderViewport,
            intent: 'print',
        }).promise;
    } catch {
        await pdfPage.render({
            canvasContext: context,
            viewport: renderViewport,
        }).promise;
    }
    const snapshot = await printImageFromCanvas(canvas);
    image.src = snapshot.src;
    await waitForPrintAssets(host, { requireDrawings: true });
    paintCalculationOverlays({
        hitEl,
        markersEl,
        rooms: paint.rooms,
        drawingId: paint.drawingId,
        page: pageNumber,
        materialKeys: paint.materialKeys,
        colored: paint.colored,
        roomLabels: paint.roomLabels,
        materialCodes: paint.materialCodes,
        chipTag: 'span',
        showChips: Boolean(paint.roomLabels || paint.materialCodes),
    });
}

async function waitUntilPrintable(documentData = null) {
    const needsDrawings = Boolean(documentData?.show_drawings)
        || document.querySelectorAll('.calc-print-drawing-page').length > 0;
    await waitForPrintAssets(document, {
        fonts: document.fonts,
        requireDrawings: needsDrawings,
    });
    if (typeof requestAnimationFrame === 'function') {
        await new Promise((resolve) => {
            requestAnimationFrame(() => requestAnimationFrame(resolve));
        });
    }
}

function printRenderScale(cssViewport) {
    const width = Math.max(1, Number(cssViewport?.width) || 1);
    const height = Math.max(1, Number(cssViewport?.height) || 1);
    const scale = viewerRenderScale(width, height, PRINT_RENDER_SCALE, 1, PRINT_MAX_EDGE);
    const edge = Math.max(width, height) * scale;
    if (edge <= PRINT_MAX_EDGE) {
        return scale;
    }

    return PRINT_MAX_EDGE / Math.max(width, height);
}

function enablePrintButton() {
    document.querySelector('[data-print-start]')?.removeAttribute('disabled');
}

function finish(documentData) {
    const status = document.getElementById('calc-print-status');
    if (status) {
        const hint = documentData.output === 'print'
            ? 'Kies A3 liggend in het afdrukvenster.'
            : 'Kies in het afdrukvenster Opslaan als PDF, papierformaat A3 liggend.';
        status.textContent = hint;
    }
    enablePrintButton();
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}
