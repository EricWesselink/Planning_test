export const A3_LANDSCAPE_PT = { widthPt: 1190.55, heightPt: 841.89 };
export const A3_PORTRAIT_PT = { widthPt: 841.89, heightPt: 1190.55 };

export function printPdfFilename(name) {
    const base = String(name || 'calculatie')
        .replace(/[<>:"/\\|?*\u0000-\u001f]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 80) || 'calculatie';

    return `${base}.pdf`;
}

export function paperForPrintPage(pageEl) {
    return pageEl?.classList.contains('is-portrait') ? A3_PORTRAIT_PT : A3_LANDSCAPE_PT;
}

/**
 * @param {Array<{ bytes: Uint8Array, pixelWidth: number, pixelHeight: number, widthPt: number, heightPt: number }>} pages
 */
export function jpegsToPdf(pages) {
    if (!pages.length) {
        throw new Error('Geen printpaginas.');
    }

    const encoder = new TextEncoder();
    const chunks = [];
    let offset = 0;
    const starts = [0];

    const add = (part) => {
        const bytes = typeof part === 'string' ? encoder.encode(part) : part;
        chunks.push(bytes);
        offset += bytes.length;
    };

    const startObj = (num) => {
        starts[num] = offset;
        add(`${num} 0 obj\n`);
    };

    add('%PDF-1.4\n');

    startObj(1);
    add('<< /Type /Catalog /Pages 2 0 R >>\nendobj\n');

    const kids = pages.map((_, index) => `${3 + index * 3} 0 R`).join(' ');
    startObj(2);
    add(`<< /Type /Pages /Count ${pages.length} /Kids [${kids}] >>\nendobj\n`);

    pages.forEach((page, index) => {
        const pageObj = 3 + index * 3;
        const imageObj = pageObj + 1;
        const contentObj = pageObj + 2;
        const widthPt = roundPt(page.widthPt);
        const heightPt = roundPt(page.heightPt);
        const content = `q ${widthPt} 0 0 ${heightPt} 0 0 cm /Im0 Do Q\n`;

        startObj(pageObj);
        add(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${widthPt} ${heightPt}] /Resources << /XObject << /Im0 ${imageObj} 0 R >> >> /Contents ${contentObj} 0 R >>\nendobj\n`);

        startObj(imageObj);
        add(`<< /Type /XObject /Subtype /Image /Width ${page.pixelWidth} /Height ${page.pixelHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${page.bytes.length} >>\nstream\n`);
        add(page.bytes);
        add('\nendstream\nendobj\n');

        startObj(contentObj);
        add(`<< /Length ${content.length} >>\nstream\n${content}endstream\nendobj\n`);
    });

    const xrefAt = offset;
    const last = 2 + pages.length * 3;
    add(`xref\n0 ${last + 1}\n0000000000 65535 f \n`);
    for (let number = 1; number <= last; number += 1) {
        add(`${String(starts[number]).padStart(10, '0')} 00000 n \n`);
    }
    add(`trailer\n<< /Size ${last + 1} /Root 1 0 R >>\nstartxref\n${xrefAt}\n%%EOF\n`);

    const output = new Uint8Array(offset);
    let cursor = 0;
    chunks.forEach((chunk) => {
        output.set(chunk, cursor);
        cursor += chunk.length;
    });

    return output;
}

export function downloadPdfBytes(bytes, filename) {
    const blob = new Blob([bytes], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.rel = 'noopener';
    document.body.append(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1500);
}

export function canvasToJpegBytes(canvas, quality = 0.85) {
    return new Promise((resolve, reject) => {
        if (typeof canvas.toBlob !== 'function') {
            reject(new Error('Canvas kan niet naar JPEG.'));

            return;
        }
        canvas.toBlob((blob) => {
            if (!blob) {
                reject(new Error('JPEG ontbreekt.'));

                return;
            }
            blob.arrayBuffer()
                .then((buffer) => resolve(new Uint8Array(buffer)))
                .catch(reject);
        }, 'image/jpeg', quality);
    });
}

function roundPt(value) {
    return Math.round(Number(value) * 100) / 100;
}
