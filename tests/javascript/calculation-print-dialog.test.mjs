import assert from 'node:assert/strict';
import test from 'node:test';
import { printDocumentUrl } from '../../resources/js/calculation-print-dialog.js';

test('print url keeps includes drawings materials and output', () => {
    const url = printDocumentUrl('/calculaties/4/print', {
        include: ['colored', 'legend'],
        drawingIds: ['12', '15'],
        materialMode: 'selected',
        materialKeys: ['v01.d', 'v09'],
        output: 'pdf',
    });

    assert.equal(url.includes('include%5B%5D=colored'), true);
    assert.equal(url.includes('include%5B%5D=legend'), true);
    assert.equal(url.includes('drawing_ids%5B%5D=12'), true);
    assert.equal(url.includes('drawing_ids%5B%5D=15'), true);
    assert.equal(url.includes('material_mode=selected'), true);
    assert.equal(url.includes('material_keys%5B%5D=v01.d'), true);
    assert.equal(url.includes('output=pdf'), true);
    assert.equal(url.includes('material_keys%5B%5D=v04'), false);
});

test('print url uses all materials unless selected mode is on', () => {
    const url = printDocumentUrl('/calculaties/4/print', {
        include: ['area_totals'],
        drawingIds: [],
        materialMode: 'all',
        materialKeys: ['v04'],
        output: 'print',
    });

    assert.equal(url.includes('material_mode=all'), true);
    assert.equal(url.includes('material_keys'), false);
    assert.equal(url.includes('output=print'), true);
});
