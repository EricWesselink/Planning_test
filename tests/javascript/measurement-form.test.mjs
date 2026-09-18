import assert from 'node:assert/strict';
import test from 'node:test';
import {
    allocationLines,
    clampEnteredQuantity,
    clampMessage,
    formatMeasurementQty,
    parseMeasurementAmount,
    productQuantitySuggestion,
    remainingQuantity,
} from '../../resources/js/measurement-form.js';

test('parses dutch decimals for measurement quantities', () => {
    assert.equal(parseMeasurementAmount('12,5'), 12.5);
    assert.equal(parseMeasurementAmount('100'), 100);
    assert.equal(parseMeasurementAmount(''), 0);
});

test('formats compact allocation totals', () => {
    assert.equal(formatMeasurementQty(80), '80');
    assert.equal(formatMeasurementQty(12.5), '12,5');
});

test('leaves remaining quantity after other rooms', () => {
    assert.equal(remainingQuantity(100, 80), 20);
    assert.equal(remainingQuantity(100, 100), 0);
    assert.equal(remainingQuantity(100, 110), 0);
});

test('clamps a room to the remaining shop quantity', () => {
    assert.equal(clampEnteredQuantity(30, 20), 20);
    assert.equal(clampEnteredQuantity(20, 20), 20);
    assert.equal(clampEnteredQuantity(10, 20), 10);
    assert.equal(clampEnteredQuantity(80, 0), 0);
});

test('explains the remaining quantity after a clamp', () => {
    assert.equal(
        clampMessage('PVC banen', 20, 'm2'),
        'Maximaal nog 20 m² PVC banen beschikbaar.',
    );
});

test('sums m2 per product and allows an exact shop total', () => {
    const lines = allocationLines(
        [{ name: 'PVC banen', quantity: 100, unit: 'm2' }],
        { 'PVC banen': { m2: 100 } },
    );

    assert.equal(lines.length, 1);
    assert.equal(lines[0].over, false);
    assert.equal(lines[0].text, 'PVC banen: 100 / 100 m² verdeeld');
    assert.equal(lines[0].error, '');
});

test('shows how many square meters are still available', () => {
    const lines = allocationLines(
        [{ name: 'PVC banen', quantity: 100, unit: 'm2' }],
        { 'PVC banen': { m2: 80 } },
    );

    assert.equal(lines[0].remaining, 20);
    assert.equal(lines[0].text, 'PVC banen: 80 / 100 m² verdeeld — nog 20 m²');
});

test('rejects rooms that exceed the shop quantity', () => {
    const lines = allocationLines(
        [{ name: 'PVC banen', quantity: 100, unit: 'm2' }],
        { 'PVC banen': { m2: 110 } },
    );

    assert.equal(lines[0].over, true);
    assert.equal(lines[0].text, 'PVC banen: 110 / 100 m² verdeeld');
    assert.equal(
        lines[0].error,
        'Te veel ingevoerd. PVC banen: 100 m² beschikbaar, 110 m² reeds verdeeld.',
    );
});

test('suggests the remaining shop quantity when a product is chosen', () => {
    const firstRoom = productQuantitySuggestion(
        { name: 'PVC banen', quantity: 100, unit: 'm2' },
        0,
    );
    const secondRoom = productQuantitySuggestion(
        { name: 'PVC banen', quantity: 100, unit: 'm2' },
        80,
    );
    const lastRoom = productQuantitySuggestion(
        { name: 'PVC banen', quantity: 100, unit: 'm2' },
        95,
    );

    assert.equal(firstRoom.quantity, '100');
    assert.equal(firstRoom.message, '');
    assert.equal(secondRoom.quantity, '20');
    assert.equal(lastRoom.quantity, '5');
});

test('leaves quantity empty when the chosen product is fully allocated', () => {
    const suggestion = productQuantitySuggestion(
        { name: 'PVC banen', quantity: 100, unit: 'm2' },
        100,
    );

    assert.equal(suggestion.quantity, '');
    assert.equal(suggestion.message, 'PVC banen is volledig verdeeld (100 / 100 m²).');
});

test('suggests remaining linear meters for an m1 product', () => {
    const suggestion = productQuantitySuggestion(
        { name: 'Plinten', quantity: 40, unit: 'm1' },
        25,
    );

    assert.equal(suggestion.quantity, '15');
    assert.equal(suggestion.message, '');
});

test('does not add m1 rooms to an m2 budget', () => {
    const lines = allocationLines(
        [{ name: 'PVC banen', quantity: 100, unit: 'm2' }],
        { 'PVC banen': { m2: 80, m1: 40 } },
    );

    assert.equal(lines[0].used, 80);
    assert.equal(lines[0].over, false);
});
