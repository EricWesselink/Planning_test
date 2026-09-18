import assert from 'node:assert/strict';
import test from 'node:test';
import {
    autosaveFormFromEvent,
    firstAutosaveError,
} from '../../resources/js/project-list.js';

test('uses the first validation error from an autosave payload', () => {
    assert.equal(firstAutosaveError({
        message: 'The customer name field is required.',
        errors: { customer_name: ['Vul een opdrachtgever in.'] },
    }), 'Vul een opdrachtgever in.');
});

test('falls back to the payload message when there are no field errors', () => {
    assert.equal(firstAutosaveError({ message: 'Dit mag je niet wijzigen.' }), 'Dit mag je niet wijzigen.');
});

test('falls back to a visible failure when the payload is empty', () => {
    assert.equal(firstAutosaveError({}), 'Opslaan mislukt');
});

test('finds the autosave form via the associated input, not the form DOM parent', () => {
    const form = {
        hasAttribute: (name) => name === 'data-autosave',
    };
    const field = { tagName: 'INPUT', type: 'text', form };

    assert.equal(autosaveFormFromEvent({ type: 'change', target: field }), form);
});

test('ignores search filters and other forms without data-autosave', () => {
    const form = {
        hasAttribute: () => false,
    };
    const field = { tagName: 'INPUT', type: 'search', form };

    assert.equal(autosaveFormFromEvent({ type: 'change', target: field }), null);
});

test('treats Enter on a text field as an autosave trigger', () => {
    const form = {
        hasAttribute: (name) => name === 'data-autosave',
    };
    const field = { tagName: 'INPUT', type: 'text', form };

    assert.equal(autosaveFormFromEvent({ type: 'keydown', key: 'Enter', target: field }), form);
});

test('does not treat Enter on a date picker as an autosave trigger', () => {
    const form = {
        hasAttribute: (name) => name === 'data-autosave',
    };
    const field = { tagName: 'INPUT', type: 'date', form };

    assert.equal(autosaveFormFromEvent({ type: 'keydown', key: 'Enter', target: field }), null);
});
