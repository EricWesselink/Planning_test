export const PLANNING_SCROLL_KEY = 'nicon.planning.scroll';

export function pageHref(win = globalThis) {
    const location = win.location;

    if (!location) {
        return '';
    }

    return `${location.pathname || ''}${location.search || ''}`;
}

export function scrollStorageKey(scroller) {
    return scroller?.getAttribute?.('data-scroll-key') || PLANNING_SCROLL_KEY;
}

export function readPlanningScroll(scroller, win = globalThis) {
    return {
        left: Number(scroller?.scrollLeft) || 0,
        top: Number(scroller?.scrollTop) || 0,
        windowLeft: Number(win.scrollX) || 0,
        windowTop: Number(win.scrollY) || 0,
        href: pageHref(win),
    };
}

export function persistPlanningScroll(position, storage = globalThis.sessionStorage, key = PLANNING_SCROLL_KEY) {
    try {
        storage?.setItem(key, JSON.stringify(position));
    } catch {
        // Private mode or disabled storage should not block a save.
    }
}

export function clearPlanningScroll(storage = globalThis.sessionStorage, key = PLANNING_SCROLL_KEY) {
    try {
        storage?.removeItem(key);
    } catch {
        // Ignore storage failures.
    }
}

export function loadPlanningScroll(storage = globalThis.sessionStorage, win = globalThis, key = PLANNING_SCROLL_KEY) {
    try {
        const raw = storage?.getItem(key);
        if (!raw) {
            return null;
        }

        const position = JSON.parse(raw);
        if (!position || position.href !== pageHref(win)) {
            clearPlanningScroll(storage, key);

            return null;
        }

        return position;
    } catch {
        clearPlanningScroll(storage, key);

        return null;
    }
}

export function applyPlanningScroll(scroller, position, win = globalThis) {
    if (!position) {
        return false;
    }

    const left = Number(position.left);
    const top = Number(position.top);
    if (scroller && Number.isFinite(left) && Number.isFinite(top)) {
        scroller.scrollLeft = left;
        scroller.scrollTop = top;
    }

    const windowLeft = Number(position.windowLeft);
    const windowTop = Number(position.windowTop);
    if (typeof win.scrollTo === 'function' && Number.isFinite(windowLeft) && Number.isFinite(windowTop)) {
        win.scrollTo(windowLeft, windowTop);
    }

    return true;
}

export function reloadPlanningBoard(scroller, win = globalThis, storage = globalThis.sessionStorage) {
    const key = scrollStorageKey(scroller);
    persistPlanningScroll(readPlanningScroll(scroller, win), storage, key);

    try {
        if (win.history && 'scrollRestoration' in win.history) {
            win.history.scrollRestoration = 'manual';
        }
    } catch {
        // Ignore browsers that expose a read-only scrollRestoration.
    }

    win.location.reload();
}

export function bindPlanningScrollRestore(scroller, win = globalThis, storage = globalThis.sessionStorage) {
    const key = scrollStorageKey(scroller);
    const restore = () => applyPlanningScroll(scroller, loadPlanningScroll(storage, win, key), win);

    restore();
    win.requestAnimationFrame?.(() => restore());

    const finish = () => {
        restore();
        clearPlanningScroll(storage, key);
    };

    if (win.document?.readyState === 'complete') {
        finish();

        return;
    }

    win.addEventListener?.('load', finish, { once: true });
}
