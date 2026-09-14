import assert from 'node:assert/strict';
import test from 'node:test';
import {
    barStyle,
    boxFromPositions,
    edgeHours,
    fractionFromTime,
    hoursLabel,
    intervalLabel,
    positionsFromBox,
    shiftBox,
    slotOptions,
    snapHours,
    snapPosition,
    timeFromFraction,
    timesFromHours,
    workdayCount,
} from '../../resources/js/planning-hours.js';

test('shifts a two-hour bar within the day by a quarter day', () => {
    const box = shiftBox(0, 1, 0.25, 0.5, 0.25, 6);
    assert.deepEqual(box, { start: 0, span: 1, startOffset: 0.5, endOffset: 0.75 });
    assert.equal(intervalLabel(box), '12:00 - 14:00 · 2u');
});

test('lists two-hour slot options across the workday', () => {
    assert.deepEqual(slotOptions(2).map((option) => option.label), [
        '08:00–10:00',
        '10:00–12:00',
        '12:00–14:00',
        '14:00–16:00',
    ]);
});

test('snaps hours to two-hour steps', () => {
    assert.equal(snapHours(3), 4);
    assert.equal(snapHours(8), 8);
    assert.equal(snapHours(1), 2);
});

test('maps a half day to fifty percent of the cell', () => {
    const box = boxFromPositions(0, 0.5);
    assert.deepEqual(box, { start: 0, span: 1, startOffset: 0, endOffset: 0.5 });
    const style = barStyle(box.start, box.span, box.startOffset, box.endOffset, 6);
    assert.equal(style.width, 'calc(0.5 * 100% / 6 - 2px)');
    assert.equal(style.left, 'calc(0 * 100% / 6 + 1px)');
});

test('maps an afternoon half day to the right half of the cell', () => {
    const box = boxFromPositions(0.5, 1);
    assert.deepEqual(box, { start: 0, span: 1, startOffset: 0.5, endOffset: 1 });
});

test('converts times and fractions for an eight-hour day', () => {
    assert.equal(timeFromFraction(0), '08:00');
    assert.equal(timeFromFraction(0.5), '12:00');
    assert.equal(timeFromFraction(1), '16:00');
    assert.equal(fractionFromTime('12:00'), 0.5);
    assert.deepEqual(timesFromHours(4, 'afternoon'), { start: '12:00', end: '16:00', hours: 4 });
});

test('shows snapped edge hours while resizing', () => {
    assert.equal(edgeHours({ start: 0, span: 1, startOffset: 0, endOffset: 0.5 }, 'resize-end'), 4);
    assert.equal(edgeHours({ start: 0, span: 1, startOffset: 0, endOffset: 0.75 }, 'resize-end'), 6);
    assert.equal(hoursLabel(4), '4u');
});

test('keeps a two-day range when the last day is a half day', () => {
    const box = boxFromPositions(0, 1.5);
    assert.deepEqual(box, { start: 0, span: 2, startOffset: 0, endOffset: 0.5 });
    const positions = positionsFromBox(box.start, box.span, box.startOffset, box.endOffset);
    assert.equal(positions.startPos, 0);
    assert.equal(positions.endPos, 1.5);
});

test('snaps pointer positions to quarter days', () => {
    assert.equal(snapPosition(0.12, 6), 0);
    assert.equal(snapPosition(0.3, 6), 0.25);
    assert.equal(snapPosition(0.55, 6), 0.5);
});

test('counts weekdays through a weekend unless weekends are included', () => {
    assert.equal(workdayCount('2026-09-14', '2026-09-25'), 10);
    assert.equal(workdayCount('2026-09-14', '2026-09-25', true), 12);
    assert.equal(workdayCount('2026-09-19', '2026-09-20'), 0);
});
