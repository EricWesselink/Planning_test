import assert from 'node:assert/strict';
import test from 'node:test';
import {
    applyIsoWeekToRange,
    calendarMarkup,
    calendarWeeks,
    disableNativeDatePicker,
    isoWeeksInYear,
    workdaysForIsoWeek,
} from '../../resources/js/planning-datepicker.js';

test('maps ISO week 40 in 2026 to monday through friday', () => {
    assert.deepEqual(workdaysForIsoWeek(2026, 40), {
        start: '2026-09-28',
        end: '2026-10-02',
        year: 2026,
        week: 40,
    });
});

test('maps week 53 across the new year to monday through friday', () => {
    assert.deepEqual(workdaysForIsoWeek(2026, 53), {
        start: '2026-12-28',
        end: '2027-01-01',
        year: 2026,
        week: 53,
    });
});

test('rejects week 53 in a 52-week year', () => {
    assert.equal(workdaysForIsoWeek(2025, 53), null);
});

test('counts 53 ISO weeks in 2026', () => {
    assert.equal(isoWeeksInYear(2026), 53);
    assert.equal(isoWeeksInYear(2025), 52);
});

test('shows week 53 of 2026 in the january 2027 calendar', () => {
    const weeks = calendarWeeks(2027, 0);
    const first = weeks[0];

    assert.equal(first.year, 2026);
    assert.equal(first.week, 53);
    assert.equal(first.days[0].date, '2026-12-28');
    assert.equal(first.days[0].inMonth, false);
    assert.equal(first.days[4].date, '2027-01-01');
    assert.equal(first.days[4].inMonth, true);
});

test('shows week 40 beside the days of late september 2026', () => {
    const weeks = calendarWeeks(2026, 8);
    const week40 = weeks.find((row) => row.week === 40);

    assert.ok(week40);
    assert.equal(week40.year, 2026);
    assert.deepEqual(week40.days.map((day) => day.date), [
        '2026-09-28',
        '2026-09-29',
        '2026-09-30',
        '2026-10-01',
        '2026-10-02',
        '2026-10-03',
        '2026-10-04',
    ]);
});

test('clicking a week number fills monday through friday on van and tot', () => {
    const start = { value: '' };
    const end = { value: '' };

    const range = applyIsoWeekToRange(start, end, 2026, 40);

    assert.deepEqual(range, {
        start: '2026-09-28',
        end: '2026-10-02',
        year: 2026,
        week: 40,
    });
    assert.equal(start.value, '2026-09-28');
    assert.equal(end.value, '2026-10-02');
});

test('calendar markup shows ISO week numbers monday through sunday', () => {
    const html = calendarMarkup(2026, 8);

    assert.match(html, /WK 37/);
    assert.match(html, /WK 38/);
    assert.match(html, /WK 39/);
    assert.match(html, /WK 40/);
    assert.match(html, />ma</);
    assert.match(html, />zo</);
    assert.match(html, /data-cal-week="40"/);
    assert.match(html, /data-cal-date="2026-09-28"/);
});

test('replaces a native date input so the browser calendar cannot open', () => {
    const attrs = { type: 'date', placeholder: '' };
    const input = {
        type: 'date',
        value: '2026-09-28',
        getAttribute(name) {
            return attrs[name] ?? null;
        },
        setAttribute(name, value) {
            attrs[name] = value;
            if (name === 'type') {
                this.type = value;
            }
        },
    };

    disableNativeDatePicker(input);

    assert.equal(input.type, 'text');
    assert.equal(attrs.placeholder, 'jjjj-mm-dd');
});
