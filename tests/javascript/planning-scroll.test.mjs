import assert from 'node:assert/strict';
import test from 'node:test';
import {
    PLANNING_SCROLL_KEY,
    applyPlanningScroll,
    bindPlanningScrollRestore,
    clearPlanningScroll,
    loadPlanningScroll,
    persistPlanningScroll,
    readPlanningScroll,
    reloadPlanningBoard,
} from '../../resources/js/planning-scroll.js';

function memoryStorage(start = {}) {
    const data = { ...start };

    return {
        getItem(key) {
            return Object.hasOwn(data, key) ? data[key] : null;
        },
        setItem(key, value) {
            data[key] = String(value);
        },
        removeItem(key) {
            delete data[key];
        },
    };
}

function fakeWindow({
    href = '/planning?week=2026-09-07',
    readyState = 'complete',
    scrollX = 0,
    scrollY = 0,
} = {}) {
    const [pathname, search = ''] = href.split('?');
    const listeners = {};

    return {
        location: {
            pathname,
            search: search ? `?${search}` : '',
            reloaded: false,
            reload() {
                this.reloaded = true;
            },
        },
        history: { scrollRestoration: 'auto' },
        document: { readyState },
        scrollX,
        scrollY,
        scrollTo(left, top) {
            this.scrollX = left;
            this.scrollY = top;
        },
        requestAnimationFrame(fn) {
            fn();
        },
        addEventListener(name, fn) {
            listeners[name] = fn;
        },
        listeners,
    };
}

test('reads horizontal and vertical scroll from the board and window', () => {
    const scroller = { scrollLeft: 240, scrollTop: 880 };
    const win = fakeWindow({ href: '/planning?week=2026-09-07', scrollX: 12, scrollY: 40 });

    assert.deepEqual(readPlanningScroll(scroller, win), {
        left: 240,
        top: 880,
        windowLeft: 12,
        windowTop: 40,
        href: '/planning?week=2026-09-07',
    });
});

test('restores a saved position only on the same planning url', () => {
    const storage = memoryStorage();
    const win = fakeWindow({ href: '/planning?week=2026-09-07' });
    const position = {
        left: 180,
        top: 640,
        windowLeft: 0,
        windowTop: 0,
        href: '/planning?week=2026-09-07',
    };

    persistPlanningScroll(position, storage);

    assert.deepEqual(loadPlanningScroll(storage, win), position);

    const otherWeek = fakeWindow({ href: '/planning?week=2026-09-14' });
    persistPlanningScroll(position, storage);
    assert.equal(loadPlanningScroll(storage, otherWeek), null);
    assert.equal(storage.getItem(PLANNING_SCROLL_KEY), null);
});

test('applies both board and window scroll positions', () => {
    const scroller = { scrollLeft: 0, scrollTop: 0 };
    const win = fakeWindow();

    applyPlanningScroll(scroller, {
        left: 310,
        top: 1200,
        windowLeft: 4,
        windowTop: 16,
    }, win);

    assert.equal(scroller.scrollLeft, 310);
    assert.equal(scroller.scrollTop, 1200);
    assert.equal(win.scrollX, 4);
    assert.equal(win.scrollY, 16);
});

test('reload stores the current position then reloads the page', () => {
    const scroller = {
        scrollLeft: 90,
        scrollTop: 450,
        getAttribute(name) {
            return name === 'data-scroll-key' ? PLANNING_SCROLL_KEY : null;
        },
    };
    const win = fakeWindow({ href: '/planning?weeks=2', scrollX: 0, scrollY: 8 });
    const storage = memoryStorage();

    reloadPlanningBoard(scroller, win, storage);

    assert.equal(win.location.reloaded, true);
    assert.equal(win.history.scrollRestoration, 'manual');
    assert.deepEqual(JSON.parse(storage.getItem(PLANNING_SCROLL_KEY)), {
        left: 90,
        top: 450,
        windowLeft: 0,
        windowTop: 8,
        href: '/planning?weeks=2',
    });
});

test('restore reapplies the saved position and then clears it', () => {
    const scroller = {
        scrollLeft: 0,
        scrollTop: 0,
        getAttribute() {
            return PLANNING_SCROLL_KEY;
        },
    };
    const win = fakeWindow({ href: '/planning', readyState: 'complete' });
    const storage = memoryStorage();
    persistPlanningScroll({
        left: 55,
        top: 700,
        windowLeft: 0,
        windowTop: 0,
        href: '/planning',
    }, storage);

    bindPlanningScrollRestore(scroller, win, storage);

    assert.equal(scroller.scrollLeft, 55);
    assert.equal(scroller.scrollTop, 700);
    assert.equal(storage.getItem(PLANNING_SCROLL_KEY), null);
});

test('restore waits for load when the document is still loading', () => {
    const scroller = {
        scrollLeft: 0,
        scrollTop: 0,
        getAttribute() {
            return PLANNING_SCROLL_KEY;
        },
    };
    const win = fakeWindow({ href: '/planning', readyState: 'interactive' });
    const storage = memoryStorage();
    persistPlanningScroll({
        left: 20,
        top: 300,
        windowLeft: 0,
        windowTop: 0,
        href: '/planning',
    }, storage);

    bindPlanningScrollRestore(scroller, win, storage);

    assert.equal(scroller.scrollLeft, 20);
    assert.equal(scroller.scrollTop, 300);
    assert.equal(storage.getItem(PLANNING_SCROLL_KEY) !== null, true);

    win.listeners.load();

    assert.equal(scroller.scrollLeft, 20);
    assert.equal(storage.getItem(PLANNING_SCROLL_KEY), null);
});

test('clears a stored position', () => {
    const storage = memoryStorage({ [PLANNING_SCROLL_KEY]: '{}' });

    clearPlanningScroll(storage);

    assert.equal(storage.getItem(PLANNING_SCROLL_KEY), null);
});
