import assert from 'node:assert/strict';
import test from 'node:test';
import {
    WHO_LOADING_LABEL,
    candidatesFetchInit,
    datesForExactMode,
    isLatestCandidatesRequest,
    preservedWhoValue,
    shouldOverwriteDatesFromWeeks,
    whoChoice,
    whoLoadingOptionList,
    whoOptionList,
    whoOptionName,
    whoValueForWorker,
    workerNameFromBarLabel,
} from '../../resources/js/planning-who-options.js';

const candidates = [
    { id: 1, name: 'H.D. Verwoert', people_count: 4, selectable: false, status_label: '0/4 geschikt en beschikbaar' },
    { id: 2, name: 'Max Project', people_count: 1, selectable: false, status_label: 'Geen vakkennis: PVC' },
    { id: 3, name: 'Team 4 Lukasz', people_count: 1, selectable: true, status_label: 'Beschikbaar' },
    { id: 4, name: 'Team 5 Lukas', people_count: 3, selectable: true, status_label: '3/3 geschikt en beschikbaar' },
];

test('omits unavailable vakmensen from a new planning picker', () => {
    const options = whoOptionList(candidates);

    assert.deepEqual(options.slice(1).map((option) => option.label), [
        'Team 4 Lukasz — Beschikbaar',
        'Team 5 Lukas — 3/3 geschikt en beschikbaar',
    ]);
    assert.equal(options.some((option) => option.label.includes('0/4')), false);
    assert.equal(options.some((option) => option.label.includes('Geen vakkennis')), false);
});

test('keeps a previously chosen weaker-fit vakman selected', () => {
    const options = whoOptionList(candidates, 'worker:2');
    const chosen = options.find((option) => option.value === 'worker:2');

    assert.equal(chosen?.selected, true);
    assert.equal(chosen?.disabled, false);
    assert.equal(chosen?.label, 'Max Project — Geen vakkennis: PVC');
    assert.equal(options.filter((option) => option.selected).length, 1);
    assert.equal(options.some((option) => option.label.includes('H.D. Verwoert')), false);
});

test('replaces stale who-options with a loading row', () => {
    const options = whoLoadingOptionList();

    assert.deepEqual(options, [{
        value: '',
        label: WHO_LOADING_LABEL,
        peopleCount: 0,
        selectable: false,
        disabled: false,
        selected: true,
        external: false,
    }]);
    assert.equal(options.some((option) => option.label.includes('Wespro')), false);
});

test('does not copy leftover week numbers onto a clicked monday', () => {
    assert.equal(shouldOverwriteDatesFromWeeks(false, '38', '38'), false);
    assert.equal(shouldOverwriteDatesFromWeeks(true, '38', '38'), true);
    assert.equal(shouldOverwriteDatesFromWeeks(true, '', '38'), false);
});

test('reopening the same monday keeps that day even with leftover week bounds', () => {
    const leftoverWeek = { weekStartDate: '2026-09-14', weekEndDate: '2026-09-18' };

    for (let opening = 0; opening < 10; opening++) {
        const bounds = datesForExactMode({
            startDate: '2026-09-14',
            endDate: '2026-09-14',
            applyWeekRange: false,
            ...leftoverWeek,
        });

        assert.deepEqual(bounds, { startDate: '2026-09-14', endDate: '2026-09-14' });
    }
});

test('applies the week range only after switching from week numbers to exact dates', () => {
    const bounds = datesForExactMode({
        startDate: '2026-09-14',
        endDate: '2026-09-14',
        applyWeekRange: true,
        weekStartDate: '2026-09-14',
        weekEndDate: '2026-09-18',
    });

    assert.deepEqual(bounds, { startDate: '2026-09-14', endDate: '2026-09-18' });
});

test('ignores an older candidates response after a newer request started', () => {
    assert.equal(isLatestCandidatesRequest(1, 2), false);
    assert.equal(isLatestCandidatesRequest(2, 2), true);
});

test('asks the browser not to reuse a previous candidates response', () => {
    const init = candidatesFetchInit();

    assert.equal(init.cache, 'no-store');
    assert.equal(init.headers.Accept, 'application/json');
});

test('keeps the assigned team selected after the native select was emptied', () => {
    assert.equal(preservedWhoValue('worker:12', ''), 'worker:12');
    assert.equal(preservedWhoValue('', 'worker:9'), 'worker:9');
    assert.equal(whoValueForWorker(12), 'worker:12');
});

test('shows the clicked team immediately even before candidates load', () => {
    const options = whoOptionList([], 'worker:12', 'Kies vakman of team', whoChoice('Het Vloerenhuis', 3));
    const chosen = options.find((option) => option.value === 'worker:12');

    assert.equal(chosen?.selected, true);
    assert.equal(chosen?.label, 'Het Vloerenhuis');
    assert.equal(options[0].selected, false);
});

test('marks zzp candidates as external for the planner dialog', () => {
    const options = whoOptionList([
        { id: 9, name: 'ZZP Jansen', people_count: 1, selectable: true, status_label: 'Beschikbaar', external: true },
        { id: 10, name: 'Team 2', people_count: 2, selectable: true, status_label: 'Beschikbaar', external: false },
    ]);

    assert.equal(options.find((option) => option.value === 'worker:9')?.external, true);
    assert.equal(options.find((option) => option.value === 'worker:10')?.external, false);
});

test('reads the team name from a planning bar label', () => {
    assert.equal(
        workerNameFromBarLabel('Het Vloerenhuis · Rick Wellink, Bjorn, Fins · 16u'),
        'Het Vloerenhuis',
    );
    assert.equal(whoOptionName('Het Vloerenhuis — 2/3 geschikt en beschikbaar'), 'Het Vloerenhuis');
});
