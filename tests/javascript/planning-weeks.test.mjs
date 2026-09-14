import assert from 'node:assert/strict';
import test from 'node:test';
import { isoDateFromWeek, isoWeekFromDate } from '../../resources/js/planning-weeks.js';

test('maps 28 september 2026 to ISO week 40', () => {
    assert.deepEqual(isoWeekFromDate('2026-09-28'), { year: 2026, week: 40, weekday: 1 });
});

test('maps a wednesday in week 42 to that ISO week', () => {
    assert.deepEqual(isoWeekFromDate('2026-10-14'), { year: 2026, week: 42, weekday: 3 });
});

test('keeps new year days in the previous ISO week year', () => {
    assert.deepEqual(isoWeekFromDate('2027-01-01'), { year: 2026, week: 53, weekday: 5 });
});

test('builds the monday of week 40 in 2026', () => {
    assert.equal(isoDateFromWeek(2026, 40, 1), '2026-09-28');
});

test('builds the saturday of week 44 in 2026', () => {
    assert.equal(isoDateFromWeek(2026, 44, 6), '2026-10-31');
});

test('ignores an incomplete week', () => {
    assert.equal(isoDateFromWeek('', 40, 1), null);
    assert.equal(isoWeekFromDate(''), null);
});
