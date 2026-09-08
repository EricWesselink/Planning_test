import { clamp, findExactRoomHits, normalizeRoomNumber } from './room-geometry';
import { OCR_DPI, PDF_DPI, ocrScaleForPage } from './pdf-text-layer';

export async function ocrMissingRooms(pdfPage, pageNumber, knownNumbers, alreadyFound) {
    const found = new Set((alreadyFound || []).map(normalizeRoomNumber));
    const missing = (knownNumbers || []).map(normalizeRoomNumber).filter((number) => number && !found.has(number));
    if (missing.length === 0) {
        return emptyOcrResult(true);
    }

    const base = pdfPage.getViewport({ scale: 1 });
    const scale = ocrScaleForPage(base.width, base.height, OCR_DPI);
    const viewport = pdfPage.getViewport({ scale });
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.floor(viewport.width));
    canvas.height = Math.max(1, Math.floor(viewport.height));
    const context = canvas.getContext('2d', { alpha: false });
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    await pdfPage.render({ canvasContext: context, viewport }).promise;

    const tesseract = await import('tesseract.js');
    const createWorker = tesseract.createWorker || tesseract.default?.createWorker;
    const PSM = tesseract.PSM || tesseract.default?.PSM || {};
    const worker = await createWorker('eng');
    try {
        await worker.setParameters({
            tessedit_pageseg_mode: String(PSM.SPARSE_TEXT ?? 11),
        });
        const result = await worker.recognize(canvas);
        const items = (result.data.words || []).map((word) => wordToItem(word, pageNumber, canvas.width, canvas.height)).filter(Boolean);

        return {
            items,
            hits: findExactRoomHits(items, missing),
            dpi: Math.round(scale * PDF_DPI),
            scale,
            width: canvas.width,
            height: canvas.height,
            skipped: false,
        };
    } finally {
        await worker.terminate();
    }
}

function wordToItem(word, pageNumber, width, height) {
    const text = String(word?.text || '').trim();
    if (!text) {
        return null;
    }
    const box = word.bbox || {};
    const x0 = Number(box.x0) || 0;
    const y0 = Number(box.y0) || 0;
    const x1 = Number(box.x1) || x0;
    const y1 = Number(box.y1) || y0;

    return {
        page: pageNumber,
        text: text.slice(0, 160),
        x: clamp(x0 / width),
        y: clamp(y0 / height),
        w: clamp(Math.max((x1 - x0) / width, 0.008)),
        h: clamp(Math.max((y1 - y0) / height, 0.006)),
        source: 'ocr',
    };
}

function emptyOcrResult(skipped) {
    return {
        items: [],
        hits: [],
        dpi: OCR_DPI,
        scale: 0,
        width: 0,
        height: 0,
        skipped,
    };
}
