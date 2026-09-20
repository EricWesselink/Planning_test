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
    roomsOnDrawing,
} from './calculation-board-overlay';
import {
    canvasToJpegBytes,
    downloadPdfBytes,
    jpegsToPdf,
    paperForPrintPage,
    printPdfFilename,
} from './calculation-print-pdf';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const PRINT_RENDER_SCALE = Math.max(VIEW_RENDER_SCALE, 4);
const PRINT_MAX_EDGE = 3200;
const PAGE_CAPTURE_SCALE = 2;
let printReady = Promise.resolve();
let autoPrint = false;
let printOutput = 'pdf';
let printFileName = 'calculatie.pdf';

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
    if (printOutput !== 'print') {
        try {
            await downloadCalculationPdf();

            return;
        } catch (error) {
            console.error(error);
        }
    }
    const title = document.title;
    document.title = '';
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
    printOutput = documentData.output === 'print' ? 'print' : 'pdf';
    printFileName = printPdfFilename(documentData.calculation?.name);
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
        const drawingRooms = roomsOnDrawing(drawing.rooms || rooms, drawing.id, drawing.label);
        await hydrateRoomMarkers(pdf, drawingRooms, drawing.id, drawing.label);
        for (const sheet of printDrawingSheets([{ ...drawing, pageCount: pdf.numPages }])) {
            const host = createDrawingPage(sheets, documentData, drawing, sheet.page, sheet.pageCount);
            await renderDrawingPage(host, pdf, sheet.page, {
                rooms: drawingRooms,
                drawingId: drawing.id,
                drawingLabel: drawing.label,
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
        drawingLabel: paint.drawingLabel,
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
            ? 'Kies A3 liggend in het afdrukvenster. Zet kop- en voetteksten uit.'
            : 'Klik op PDF maken. De PDF wordt als A3 liggend gedownload, zonder browserkop.';
        status.textContent = hint;
    }
    enablePrintButton();
}

async function downloadCalculationPdf() {
    const status = document.getElementById('calc-print-status');
    if (status) {
        status.textContent = 'PDF maken…';
        status.classList.remove('is-error');
    }
    const pages = [...document.querySelectorAll('.calc-print-page')];
    if (pages.length === 0) {
        throw new Error('Geen printpaginas.');
    }
    const encoded = [];
    for (const page of pages) {
        const canvas = await capturePrintPage(page);
        const paper = paperForPrintPage(page);
        encoded.push({
            bytes: await canvasToJpegBytes(canvas),
            pixelWidth: canvas.width,
            pixelHeight: canvas.height,
            widthPt: paper.widthPt,
            heightPt: paper.heightPt,
        });
    }
    downloadPdfBytes(jpegsToPdf(encoded), printFileName);
    if (status) {
        status.textContent = 'PDF gedownload.';
    }
}

async function capturePrintPage(pageEl) {
    const pageBox = pageEl.getBoundingClientRect();
    const width = Math.max(1, Math.round(pageBox.width || pageEl.offsetWidth));
    const height = Math.max(1, Math.round(pageBox.height || pageEl.offsetHeight));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * PAGE_CAPTURE_SCALE));
    canvas.height = Math.max(1, Math.round(height * PAGE_CAPTURE_SCALE));
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.scale(PAGE_CAPTURE_SCALE, PAGE_CAPTURE_SCALE);

    const head = pageEl.querySelector('.calc-print-head');
    if (head) {
        drawSheetTitle(ctx, head, pageBox);
    }

    const drawing = pageEl.querySelector('img.calc-print-canvas');
    const world = pageEl.querySelector('.calc-print-world');
    if (drawing && world) {
        const box = world.getBoundingClientRect();
        ctx.drawImage(
            drawing,
            box.left - pageBox.left,
            box.top - pageBox.top,
            Math.max(1, box.width),
            Math.max(1, box.height),
        );
        const svg = pageEl.querySelector('.calc-print-hit');
        if (svg) {
            try {
                const overlay = await svgToImage(svg);
                ctx.drawImage(
                    overlay,
                    box.left - pageBox.left,
                    box.top - pageBox.top,
                    Math.max(1, box.width),
                    Math.max(1, box.height),
                );
            } catch {
                // The drawing still prints if the overlay snapshot fails.
            }
        }
        pageEl.querySelectorAll('.calc-code-chip').forEach((chip) => {
            drawChip(ctx, chip, pageBox);
        });
    }

    pageEl.querySelectorAll('.calc-print-table').forEach((table) => {
        drawTable(ctx, table, pageBox);
    });
    const legend = pageEl.querySelector('.calc-print-legend');
    if (legend) {
        drawLegend(ctx, legend, pageBox);
    }

    return canvas;
}

async function svgToImage(svg) {
    const clone = svg.cloneNode(true);
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    const box = svg.getBoundingClientRect();
    clone.setAttribute('width', String(Math.max(1, box.width)));
    clone.setAttribute('height', String(Math.max(1, box.height)));
    const image = new Image();
    image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(new XMLSerializer().serializeToString(clone))}`;
    await image.decode();

    return image;
}

function drawSheetTitle(ctx, head, pageBox) {
    const title = head.querySelector('h1');
    const meta = head.querySelector('.calc-print-meta');
    if (title) {
        drawBoxText(ctx, title, pageBox);
    }
    if (meta) {
        drawBoxText(ctx, meta, pageBox);
    }
}

function drawBoxText(ctx, el, pageBox) {
    const box = el.getBoundingClientRect();
    const style = getComputedStyle(el);
    ctx.save();
    ctx.fillStyle = style.color || '#1c1917';
    ctx.font = style.font || '16px sans-serif';
    ctx.textBaseline = 'top';
    ctx.fillText(el.textContent.trim(), box.left - pageBox.left, box.top - pageBox.top, Math.max(24, box.width));
    ctx.restore();
}

function drawChip(ctx, chip, pageBox) {
    const box = chip.getBoundingClientRect();
    const style = getComputedStyle(chip);
    const x = box.left - pageBox.left;
    const y = box.top - pageBox.top;
    ctx.save();
    ctx.fillStyle = style.backgroundColor || '#ffffff';
    ctx.strokeStyle = style.borderColor || 'rgba(28, 25, 23, 0.22)';
    ctx.lineWidth = 1;
    ctx.fillRect(x, y, box.width, box.height);
    ctx.strokeRect(x, y, box.width, box.height);
    ctx.fillStyle = style.color || '#1c1917';
    ctx.font = style.font || '700 9px sans-serif';
    ctx.textBaseline = 'middle';
    ctx.fillText(chip.textContent.trim(), x + 3, y + box.height / 2, Math.max(8, box.width - 6));
    ctx.restore();
}

function drawLegend(ctx, legend, pageBox) {
    const origin = legend.getBoundingClientRect();
    const x = origin.left - pageBox.left;
    let y = origin.top - pageBox.top;
    const heading = legend.querySelector('h2');
    if (heading) {
        const style = getComputedStyle(heading);
        ctx.save();
        ctx.fillStyle = style.color || '#1c1917';
        ctx.font = style.font || '650 11px sans-serif';
        ctx.textBaseline = 'top';
        ctx.fillText(heading.textContent.trim(), x, y);
        ctx.restore();
        y += 16;
    }
    legend.querySelectorAll('.calc-legend-row').forEach((row) => {
        const swatch = row.querySelector('.calc-print-swatch');
        if (swatch) {
            ctx.fillStyle = getComputedStyle(swatch).backgroundColor || '#e7e5e4';
            ctx.fillRect(x, y + 1, 10, 10);
        }
        ctx.fillStyle = '#1c1917';
        ctx.font = getComputedStyle(row).font || '9px sans-serif';
        ctx.textBaseline = 'top';
        ctx.fillText(row.innerText.replace(/\s+/g, ' ').trim(), x + 16, y, Math.max(40, origin.width - 20));
        y += 14;
    });
}

function drawTable(ctx, table, pageBox) {
    table.querySelectorAll('th, td').forEach((cell) => {
        const box = cell.getBoundingClientRect();
        const style = getComputedStyle(cell);
        ctx.save();
        ctx.fillStyle = style.color || '#1c1917';
        ctx.font = style.font || '11px sans-serif';
        ctx.textBaseline = 'middle';
        ctx.fillText(
            cell.textContent.trim(),
            box.left - pageBox.left + 4,
            box.top - pageBox.top + box.height / 2,
            Math.max(12, box.width - 8),
        );
        ctx.restore();
    });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}
