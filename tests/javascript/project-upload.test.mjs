import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { classifyFile, classifyFilename, TYPE_LABELS, typeLabel } from '../../resources/js/project-upload.js';

test('classifies material lists, snijmaten and drawings from the filename', () => {
    assert.deepEqual(classifyFilename('MaterialList...pdf'), { type: 'materialenstaat', confidence: 'high' });
    assert.deepEqual(classifyFilename('Snijmaten_BG.pdf'), { type: 'snijmaten', confidence: 'high' });
    assert.deepEqual(classifyFilename('Plattegrond...pdf'), { type: 'plattegrond', confidence: 'high' });
    assert.deepEqual(classifyFilename('Meetbon_Laakse.pdf'), { type: 'meetstaat', confidence: 'high' });
    assert.equal(typeLabel('materialenstaat'), 'Materialenstaat');
    assert.equal(typeLabel('calculatie'), 'Calculatie');
    assert.deepEqual(Object.keys(TYPE_LABELS).slice(0, 4), ['meetstaat', 'materialenstaat', 'plattegrond', 'calculatie']);
});

test('marks a spreadsheet without a keyword as uncertain other, never meetstaat', () => {
    assert.deepEqual(classifyFilename('ruimtes.csv'), { type: 'overig', confidence: 'low' });
    assert.deepEqual(classifyFilename('11-ericwesselink.xlsx'), { type: 'overig', confidence: 'low' });
    assert.deepEqual(classifyFilename('scan.pdf'), { type: 'overig', confidence: 'low' });
});

test('recognizes the ericwesselink workbook as a calculation', async () => {
    const bytes = await readFile(new URL('../fixtures/11-ericwesselink.xlsx', import.meta.url));
    const file = new File([bytes], '11-ericwesselink.xlsx', {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });

    const result = await classifyFile(file);

    assert.deepEqual(result, { type: 'calculatie', confidence: 'high' });
    assert.notEqual(result.type, 'meetstaat');
    assert.notEqual(result.type, 'overig');
});

test('does not treat a workbook without calculation columns as a calculation', async () => {
    const bytes = storedXlsx({
        'xl/worksheets/sheet1.xml': `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="inlineStr"><is><t>Omschrijving</t></is></c>
      <c r="B1" t="inlineStr"><is><t>Aantal</t></is></c>
      <c r="C1" t="inlineStr"><is><t>EH</t></is></c>
    </row>
  </sheetData>
</worksheet>`,
    });
    const file = new File([bytes], 'opdrachtlijst.xlsx', {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });

    const result = await classifyFile(file);

    assert.notEqual(result.type, 'calculatie');
    assert.deepEqual(result, { type: 'overig', confidence: 'low' });
});

function storedXlsx(files) {
    const encoder = new TextEncoder();
    const entries = Object.entries(files).map(([name, contents]) => ({
        name,
        data: encoder.encode(contents),
    }));
    const locals = [];
    const centrals = [];
    let offset = 0;

    for (const entry of entries) {
        const nameBytes = encoder.encode(entry.name);
        const crc = crc32(entry.data);
        const local = new Uint8Array(30 + nameBytes.length + entry.data.length);
        const localView = new DataView(local.buffer);
        localView.setUint32(0, 0x04034b50, true);
        localView.setUint16(4, 20, true);
        localView.setUint32(14, crc, true);
        localView.setUint32(18, entry.data.length, true);
        localView.setUint32(22, entry.data.length, true);
        localView.setUint16(26, nameBytes.length, true);
        local.set(nameBytes, 30);
        local.set(entry.data, 30 + nameBytes.length);
        locals.push(local);

        const central = new Uint8Array(46 + nameBytes.length);
        const centralView = new DataView(central.buffer);
        centralView.setUint32(0, 0x02014b50, true);
        centralView.setUint16(4, 20, true);
        centralView.setUint16(6, 20, true);
        centralView.setUint32(16, crc, true);
        centralView.setUint32(20, entry.data.length, true);
        centralView.setUint32(24, entry.data.length, true);
        centralView.setUint16(28, nameBytes.length, true);
        centralView.setUint32(42, offset, true);
        central.set(nameBytes, 46);
        centrals.push(central);
        offset += local.length;
    }

    const centralSize = centrals.reduce((sum, part) => sum + part.length, 0);
    const eocd = new Uint8Array(22);
    const eocdView = new DataView(eocd.buffer);
    eocdView.setUint32(0, 0x06054b50, true);
    eocdView.setUint16(8, entries.length, true);
    eocdView.setUint16(10, entries.length, true);
    eocdView.setUint32(12, centralSize, true);
    eocdView.setUint32(16, offset, true);

    const out = new Uint8Array(offset + centralSize + 22);
    let position = 0;
    for (const part of locals) {
        out.set(part, position);
        position += part.length;
    }
    for (const part of centrals) {
        out.set(part, position);
        position += part.length;
    }
    out.set(eocd, position);

    return out;
}

function crc32(bytes) {
    let crc = 0xffffffff;
    for (const byte of bytes) {
        crc ^= byte;
        for (let bit = 0; bit < 8; bit++) {
            crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
        }
    }

    return (crc ^ 0xffffffff) >>> 0;
}
