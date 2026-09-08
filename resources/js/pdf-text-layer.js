import { Util, PixelsPerInch } from 'pdfjs-dist';
import { clamp, dedupeTextItems } from './room-geometry';

export const PDF_DPI = PixelsPerInch.PDF || 72;
export const VIEW_RENDER_SCALE = 3;
export const OCR_DPI = 300;

export function pageSize(pdfPage) {
    const view = pdfPage.view || [0, 0, 0, 0];

    return {
        x0: view[0],
        y0: view[1],
        widthPt: view[2] - view[0],
        heightPt: view[3] - view[1],
    };
}

export function extractPageTextItems(content, viewport, pageNumber) {
    const items = [];
    (content?.items || []).forEach((item) => {
        if (!item || typeof item.str !== 'string') {
            return;
        }
        const text = String(item.str).replace(/\s+/g, ' ').trim();
        if (!text) {
            return;
        }
        const tx = Util.transform(viewport.transform, item.transform || [1, 0, 0, 1, 0, 0]);
        const height = Math.max(Math.hypot(tx[2], tx[3]), (item.height || 0) * viewport.scale, 1);
        const widthFromPdf = Math.max((item.width || 0) * viewport.scale, 4);
        const widthFromText = height * Math.max(text.length, 1) * 0.62;
        const width = Math.max(widthFromPdf, widthFromText);
        const left = tx[4];
        const top = tx[5] - height;
        items.push({
            page: pageNumber,
            text: text.slice(0, 160),
            x: clamp(left / viewport.width),
            y: clamp(top / viewport.height),
            w: clamp(Math.max(width / viewport.width, 0.008)),
            h: clamp(Math.max(height / viewport.height, 0.006)),
            source: 'text',
        });
    });

    return dedupeTextItems(items);
}

export function ocrScaleForPage(pageWidth, pageHeight, dpi = OCR_DPI, maxEdge = 6000) {
    const scale = dpi / PDF_DPI;
    const edge = Math.max(pageWidth, pageHeight) * scale;
    if (edge <= maxEdge) {
        return scale;
    }

    return maxEdge / Math.max(pageWidth, pageHeight);
}
