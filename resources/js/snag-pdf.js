import * as pdfjsLib from 'pdfjs-dist';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorker;

const drawingUrl = document.body?.dataset.drawingUrl;
if (drawingUrl) {
    renderPdfDrawings(drawingUrl).then(() => {
        if (document.body?.dataset.printWhenReady === '1') {
            if (typeof window.niconPrintTicket === 'function') {
                window.niconPrintTicket();
            } else {
                window.print();
            }
        }
    }).catch(() => {
        document.querySelectorAll('.map-loading').forEach((el) => {
            el.textContent = 'Tekening kon niet worden geladen.';
        });
    });
}

async function renderPdfDrawings(url) {
    const pdf = await pdfjsLib.getDocument({ url, withCredentials: true }).promise;
    const pages = [...document.querySelectorAll('[data-page]')].map((el) => Number(el.dataset.page));
    const uniquePages = [...new Set(pages.filter((page) => page > 0))];
    const rendered = {};

    for (const pageNumber of uniquePages) {
        rendered[pageNumber] = await renderPage(pdf, pageNumber);
    }

    document.querySelectorAll('.map[data-page]').forEach((map) => {
        const src = rendered[Number(map.dataset.page)];
        map.querySelector('.map-loading')?.remove();
        if (src) {
            applyImage(map, src, 'map-drawing');
        }
    });

    document.querySelectorAll('.excerpt[data-page]').forEach((excerpt) => {
        const src = rendered[Number(excerpt.dataset.page)];
        if (src) {
            applyImage(excerpt, src, 'excerpt-drawing');
        }
    });
}

async function renderPage(pdf, pageNumber) {
    if (pageNumber < 1 || pageNumber > pdf.numPages) {
        return null;
    }

    const page = await pdf.getPage(pageNumber);
    const base = page.getViewport({ scale: 1 });
    const scale = Math.min(2, 1400 / Math.max(base.width, 1));
    const viewport = page.getViewport({ scale });
    const canvas = document.createElement('canvas');
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    await page.render({
        canvasContext: canvas.getContext('2d'),
        viewport,
    }).promise;

    return canvas.toDataURL('image/jpeg', 0.82);
}

function applyImage(container, src, className) {
    if (container.querySelector(`.${className}`)) {
        return;
    }

    const image = document.createElement('img');
    image.className = className;
    image.alt = '';
    image.src = src;
    container.prepend(image);
}
