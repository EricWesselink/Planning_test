import assert from 'node:assert/strict';
import test from 'node:test';
import { jpegsToPdf, printPdfFilename } from '../../resources/js/calculation-print-pdf.js';

const jpeg = Uint8Array.from(
    atob('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='),
    (char) => char.charCodeAt(0),
);

test('print pdf filename keeps the calculation name', () => {
    assert.equal(printPdfFilename('COA Oisterwijk 3 gebouwen'), 'COA Oisterwijk 3 gebouwen.pdf');
});

test('jpeg pages become a pdf without extra header text', () => {
    const bytes = jpegsToPdf([{
        bytes: jpeg,
        pixelWidth: 1,
        pixelHeight: 1,
        widthPt: 1190.55,
        heightPt: 841.89,
    }]);
    const text = new TextDecoder('latin1').decode(bytes);

    assert.equal(text.startsWith('%PDF-1.4'), true);
    assert.equal(text.includes('/DCTDecode'), true);
    assert.equal(text.includes('MediaBox [0 0 1190.55 841.89]'), true);
    assert.equal(text.includes('COA Oisterwijk'), false);
    assert.equal(text.includes('nicon-planning'), false);
});
