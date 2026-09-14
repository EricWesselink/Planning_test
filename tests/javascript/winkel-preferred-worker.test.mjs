import assert from 'node:assert/strict';
import test from 'node:test';
import { preferredWorkerOptionList } from '../../resources/js/winkel-preferred-worker.js';

test('keeps a free vakman selected and disables busy ones', () => {
    const options = preferredWorkerOptionList([
        { id: 1, name: 'Kees Jansen', selectable: true, status_label: 'Beschikbaar' },
        { id: 2, name: 'Nick Seine', selectable: false, status_label: 'Bezet 08:00-16:00' },
    ], '1');

    assert.equal(options[1].selected, true);
    assert.equal(options[1].disabled, false);
    assert.equal(options[2].disabled, true);
    assert.equal(options[2].label, 'Nick Seine — Bezet 08:00-16:00');
});

test('clears the choice when the selected vakman is gone', () => {
    const options = preferredWorkerOptionList([
        { id: 2, name: 'Nick Seine', selectable: true, status_label: 'Beschikbaar' },
    ], '1');

    assert.equal(options[0].selected, true);
    assert.equal(options.some((option) => option.selected && option.value !== ''), false);
});
