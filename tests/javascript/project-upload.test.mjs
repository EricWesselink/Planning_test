import assert from 'node:assert/strict';
import test from 'node:test';
import { classifyFilename, typeLabel } from '../../resources/js/project-upload.js';

test('classifies material lists, snijmaten and drawings from the filename', () => {
    assert.deepEqual(classifyFilename('MaterialList...pdf'), { type: 'materialenstaat', confidence: 'high' });
    assert.deepEqual(classifyFilename('Snijmaten_BG.pdf'), { type: 'snijmaten', confidence: 'high' });
    assert.deepEqual(classifyFilename('Plattegrond...pdf'), { type: 'plattegrond', confidence: 'high' });
    assert.deepEqual(classifyFilename('Meetbon_Laakse.pdf'), { type: 'meetstaat', confidence: 'high' });
    assert.equal(typeLabel('materialenstaat'), 'Materialenstaat');
});

test('marks a spreadsheet without a keyword as an uncertain meetstaat', () => {
    assert.deepEqual(classifyFilename('ruimtes.csv'), { type: 'meetstaat', confidence: 'low' });
    assert.deepEqual(classifyFilename('scan.pdf'), { type: 'overig', confidence: 'low' });
});
