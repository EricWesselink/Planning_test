import assert from 'node:assert/strict';
import test from 'node:test';
import {
    bindPlanningMobileChrome,
    isPlanningMobileViewport,
    planningMobileMediaQuery,
    setPlanningMobilePanel,
} from '../../resources/js/planning-mobile.js';

function button() {
    return {
        attrs: {},
        listeners: {},
        setAttribute(name, value) {
            this.attrs[name] = value;
        },
        addEventListener(name, fn) {
            this.listeners[name] = fn;
        },
    };
}

function pageFixture({ availability = true } = {}) {
    const classes = new Set();
    const filters = button();
    const availabilityButton = availability ? button() : null;
    const page = {
        classList: {
            contains: (name) => classes.has(name),
            toggle(name, on) {
                if (on) {
                    classes.add(name);
                } else {
                    classes.delete(name);
                }
            },
        },
        querySelector(selector) {
            if (selector === '#planning-mobile-filters') {
                return filters;
            }
            if (selector === '#planning-mobile-availability') {
                return availabilityButton;
            }

            return null;
        },
        classes,
        filters,
        availabilityButton,
    };

    return { page, classes, filters, availabilityButton };
}

test('mobile planning matches phones and short landscape screens', () => {
    assert.equal(
        planningMobileMediaQuery(),
        '(max-width: 768px), ((max-height: 520px) and (max-width: 1000px))',
    );
    assert.equal(
        isPlanningMobileViewport(() => ({ matches: true })),
        true,
    );
    assert.equal(
        isPlanningMobileViewport(() => ({ matches: false })),
        false,
    );
});

test('opening filters closes availability and a second tap collapses them', () => {
    const { page, classes, filters, availabilityButton } = pageFixture();

    setPlanningMobilePanel(page, 'availability');
    assert.equal(classes.has('is-availability-open'), true);
    assert.equal(availabilityButton.attrs['aria-expanded'], 'true');

    setPlanningMobilePanel(page, 'filters');
    assert.equal(classes.has('is-filters-open'), true);
    assert.equal(classes.has('is-availability-open'), false);
    assert.equal(filters.attrs['aria-expanded'], 'true');
    assert.equal(availabilityButton.attrs['aria-expanded'], 'false');

    setPlanningMobilePanel(page, 'filters');
    assert.equal(classes.has('is-filters-open'), false);
    assert.equal(filters.attrs['aria-expanded'], 'false');
});

test('opening availability closes filters', () => {
    const { page, classes, filters, availabilityButton } = pageFixture();

    setPlanningMobilePanel(page, 'filters');
    setPlanningMobilePanel(page, 'availability');

    assert.equal(classes.has('is-filters-open'), false);
    assert.equal(classes.has('is-availability-open'), true);
    assert.equal(filters.attrs['aria-expanded'], 'false');
    assert.equal(availabilityButton.attrs['aria-expanded'], 'true');
});

test('the mobile buttons toggle filters and availability', () => {
    const { page, classes, filters, availabilityButton } = pageFixture();

    bindPlanningMobileChrome({
        querySelector(selector) {
            return selector === '.planning-page' ? page : null;
        },
    });

    filters.listeners.click();
    assert.equal(classes.has('is-filters-open'), true);
    assert.equal(filters.attrs['aria-expanded'], 'true');

    availabilityButton.listeners.click();
    assert.equal(classes.has('is-availability-open'), true);
    assert.equal(classes.has('is-filters-open'), false);
    assert.equal(availabilityButton.attrs['aria-expanded'], 'true');
});

test('filters still toggle when the availability button is absent', () => {
    const { page, classes, filters } = pageFixture({ availability: false });

    bindPlanningMobileChrome({
        querySelector(selector) {
            return selector === '.planning-page' ? page : null;
        },
    });

    filters.listeners.click();

    assert.equal(classes.has('is-filters-open'), true);
    assert.equal(filters.attrs['aria-expanded'], 'true');
});
