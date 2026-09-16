import * as pdfjsLib from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { viewerRenderScale } from './room-geometry';
import { VIEW_RENDER_SCALE } from './pdf-text-layer';
import { hydrateRoomMarkers, paintCalculationOverlays } from './calculation-board-overlay';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const PRINT_RENDER_SCALE = Math.max(VIEW_RENDER_SCALE, 4);

function niconPrintCalculation() {
    const title = document.title;
    document.title = document.body?.dataset.printTitle || title;
    const restore = () => {
        document.title = title;
        window.removeEventListener('afterprint', restore);
    };
    window.addEventListener('afterprint', restore);
    window.print();
}

window.niconPrintCalculation = niconPrintCalculation;

const dataEl = document.getElementById('calc-print-data');
if (dataEl) {
    boot(JSON.parse(dataEl.textContent)).catch((error) => {
        const status = document.getElementById('calc-print-status');
        if (status) {
            status.textContent = 'Tekeningen konden niet worden geladen.';
            status.classList.add('is-error');
        }
        console.error(error);
    });
}

async function boot(documentData) {
    const include = documentData.include || {};
    const rooms = documentData.rooms || [];
            const materialKeys = documentData.material_keys || [];
    const sheets = document.getElementById('calc-print-sheets');
    if (!sheets || !documentData.show_drawings) {
        finish(documentData);
        return;
    }

    for (const drawing of documentData.drawings || []) {
        const pdf = await pdfjsLib.getDocument({ url: drawing.url, withCredentials: true }).promise;
        await hydrateRoomMarkers(pdf, rooms, drawing.id);
        for (let page = 1; page <= pdf.numPages; page += 1) {
            const host = createDrawingPage(sheets, documentData, drawing, page, pdf.numPages);
            await renderDrawingPage(host, pdf, page, {
                rooms,
                drawingId: drawing.id,
                materialKeys,
                colored: Boolean(include.colored),
                roomLabels: Boolean(include.rooms),
                materialCodes: Boolean(include.codes),
            });
        }
    }

    finish(documentData);
}

function createDrawingPage(sheets, documentData, drawing, page, pageCount) {
    const pageEl = document.createElement('section');
    pageEl.className = 'calc-print-page calc-print-drawing-page';
    const legendBelow = (documentData.materials || []).length > 18;
    if (documentData.include?.legend && legendBelow) {
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
                    <canvas class="calc-print-canvas"></canvas>
                    <svg class="calc-print-hit" viewBox="0 0 1 1" preserveAspectRatio="none"></svg>
                    <div class="calc-print-markers"></div>
                </div>
            </div>
            ${documentData.include?.legend ? legendMarkup(documentData.materials || []) : ''}
        </div>
    `;
    sheets.append(pageEl);

    return pageEl;
}

function legendMarkup(materials) {
    const rows = materials.map((material) => `
        <div class="calc-legend-row">
            <i style="background: ${escapeHtml(material.color || '#e7e5e4')}"></i>
            <span class="calc-legend-code">${escapeHtml(material.code || '')}</span>
            <span class="calc-legend-product">${escapeHtml(material.product || '—')}</span>
            <span class="calc-legend-m2">${escapeHtml(material.m2_label || '')}</span>
        </div>
    `).join('');

    return `<aside class="calc-print-legend"><h2>Materiaallegenda</h2>${rows || '<p>Geen materialen.</p>'}</aside>`;
}

async function renderDrawingPage(host, pdf, pageNumber, paint) {
    const canvas = host.querySelector('.calc-print-canvas');
    const world = host.querySelector('.calc-print-world');
    const hitEl = host.querySelector('.calc-print-hit');
    const markersEl = host.querySelector('.calc-print-markers');
    const pdfPage = await pdf.getPage(pageNumber);
    const cssViewport = pdfPage.getViewport({ scale: 1 });
    const renderScale = viewerRenderScale(cssViewport.width, cssViewport.height, PRINT_RENDER_SCALE, 3);
    const renderViewport = pdfPage.getViewport({ scale: renderScale });
    const context = canvas.getContext('2d', { alpha: false });
    canvas.width = Math.max(1, Math.floor(renderViewport.width));
    canvas.height = Math.max(1, Math.floor(renderViewport.height));
    world.style.setProperty('--page-ratio', String(cssViewport.width / Math.max(cssViewport.height, 1)));
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

function finish(documentData) {
    const status = document.getElementById('calc-print-status');
    if (status) {
        const hint = documentData.output === 'print'
            ? 'Kies A3 liggend in het afdrukvenster.'
            : 'Kies in het afdrukvenster Opslaan als PDF, papierformaat A3 liggend.';
        status.textContent = hint;
    }
    if (documentData.auto_print) {
        window.setTimeout(() => niconPrintCalculation(), 50);
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}
