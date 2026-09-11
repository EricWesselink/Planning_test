export const LABOR_FOLD_KEY = 'nicon.planning.laborFolded';

export function isLaborFolded(storage = globalThis.localStorage) {
    try {
        return storage?.getItem(LABOR_FOLD_KEY) === '1';
    } catch {
        return false;
    }
}

export function persistLaborFold(folded, storage = globalThis.localStorage) {
    try {
        storage?.setItem(LABOR_FOLD_KEY, folded ? '1' : '0');
    } catch {
        // Private mode or disabled storage should not break the board.
    }
}

export function applyLaborFold(board, folded) {
    if (!board) {
        return;
    }

    board.classList.toggle('plan-board--labor-collapsed', folded);

    board.ownerDocument?.querySelectorAll('[data-labor-fold]').forEach((button) => {
        button.setAttribute('aria-expanded', folded ? 'false' : 'true');
        button.setAttribute(
            'title',
            folded ? 'Urenkolommen tonen' : 'Urenkolommen invouwen',
        );
        button.setAttribute(
            'aria-label',
            folded ? 'Urenkolommen tonen' : 'Urenkolommen invouwen',
        );
    });
}

export function bindLaborFold(board) {
    if (!board?.classList.contains('plan-board--labor')) {
        return;
    }

    applyLaborFold(board, isLaborFolded());

    board.ownerDocument?.querySelectorAll('[data-labor-fold]').forEach((button) => {
        button.addEventListener('click', () => {
            const next = !board.classList.contains('plan-board--labor-collapsed');
            persistLaborFold(next);
            applyLaborFold(board, next);
        });
    });
}
