import assert from 'node:assert/strict';
import test from 'node:test';
import {
    floorCoveringSquareMeters,
    formatShopQty,
    parseShopAmount,
    shouldAskLeveling,
} from '../../resources/js/shop-activities.js';

test('parses dutch decimals for shop quantities', () => {
    assert.equal(parseShopAmount('12,5'), 12.5);
    assert.equal(parseShopAmount('141,08'), 141.08);
    assert.equal(parseShopAmount(''), 0);
});

test('formats compact dutch shop quantities', () => {
    assert.equal(formatShopQty(12), '12');
    assert.equal(formatShopQty(141.08), '141,08');
});

test('sums checked floor covering square meters and ignores plinten', () => {
    assert.equal(floorCoveringSquareMeters([
        { checked: true, unit: 'm2', quantity: '12' },
        { checked: true, unit: 'm1', quantity: '60' },
        { checked: false, unit: 'm2', quantity: '8' },
        { checked: true, unit: 'm2', quantity: '6,25' },
    ]), 18.25);
});

test('asks egaliseren only when a floor is checked and leveling is still open', () => {
    assert.equal(shouldAskLeveling(true, false, false), true);
    assert.equal(shouldAskLeveling(true, true, false), false);
    assert.equal(shouldAskLeveling(true, false, true), false);
    assert.equal(shouldAskLeveling(false, false, false), false);
});
