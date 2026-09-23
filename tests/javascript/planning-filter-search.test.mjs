import assert from 'node:assert/strict';
import test from 'node:test';
import { filterPlanningOptions, planningSearchMatches } from '../../resources/js/planning-filter-search.js';

const zeewolde = 'Projectnr.\u00a011P260521\u00a0·\u00a0Werk\u00a02026-001 — Zeewolde, Bouw Havenkwartier Zuyd';
const groningen = 'Projectnr. 11P250925 · Werk 2026-002 — Groningen, Feringa Building';
const eding = 'Werk 2026-007 — Eding - Reeve';

const options = [
    { value: '', label: 'Alle' },
    { value: '1', label: zeewolde },
    { value: '2', label: groningen },
    { value: '3', label: eding },
];

test('matches a place, a name, or any other part of the option text', () => {
    assert.equal(planningSearchMatches(zeewolde, 'zeewolde'), true);
    assert.equal(planningSearchMatches(zeewolde, 'Havenkwartier'), true);
    assert.equal(planningSearchMatches(zeewolde, '11P260521'), true);
    assert.equal(planningSearchMatches(zeewolde, '2026-001'), true);
    assert.equal(planningSearchMatches(zeewolde, 'bouw zuyd'), true);
    assert.equal(planningSearchMatches(eding, 'reeve'), true);
});

test('drops the empty choice once a search is typed and keeps it while browsing', () => {
    assert.deepEqual(filterPlanningOptions(options, '').map((option) => option.value), ['', '1', '2', '3']);
    assert.deepEqual(filterPlanningOptions(options, 'alle').map((option) => option.value), []);
    assert.deepEqual(filterPlanningOptions(options, 'groningen').map((option) => option.value), ['2']);
});

test('keeps every option when the query is empty and drops text that is not in the label', () => {
    assert.deepEqual(filterPlanningOptions(options, '').map((option) => option.value), ['', '1', '2', '3']);
    assert.deepEqual(filterPlanningOptions(options, '   ').map((option) => option.value), ['', '1', '2', '3']);
    assert.deepEqual(filterPlanningOptions(options, 'ijsselmuiden').map((option) => option.value), []);
    assert.deepEqual(filterPlanningOptions(options, 'groningen feringa').map((option) => option.value), ['2']);
});

test('matches opdrachtgever, adres and winkelwerk that are not in the visible label', () => {
    const withSearch = [
        ...options,
        {
            value: '4',
            label: 'Werk 2026-008 — Dussen - IJsselmuiden',
            search: 'Dussen Scheepswerf 25, 8271 VD IJsselmuiden WINKEL Vloeren · Plinten',
        },
    ];

    assert.deepEqual(filterPlanningOptions(withSearch, 'scheepswerf').map((option) => option.value), ['4']);
    assert.deepEqual(filterPlanningOptions(withSearch, '8271 VD').map((option) => option.value), ['4']);
    assert.deepEqual(filterPlanningOptions(withSearch, 'plinten').map((option) => option.value), ['4']);
    assert.deepEqual(filterPlanningOptions(withSearch, 'winkel').map((option) => option.value), ['4']);
});
