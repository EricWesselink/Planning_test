/**
 * @param {unknown} payload
 * @returns {string}
 */
export function firstAutosaveError(payload) {
    const errors = payload && typeof payload === 'object' ? payload.errors : null;
    if (errors && typeof errors === 'object') {
        const first = Object.values(errors).flat()[0];
        if (first) {
            return String(first);
        }
    }

    if (payload && typeof payload === 'object' && 'message' in payload && payload.message) {
        return String(payload.message);
    }

    return 'Opslaan mislukt';
}

/**
 * Inputs live in other table cells and only point at the form via the form="" attribute.
 * Their change/keydown events therefore never reach the <form> itself.
 *
 * @param {{target?: EventTarget|null, type?: string, key?: string}} event
 * @returns {HTMLFormElement|null}
 */
export function autosaveFormFromEvent(event) {
    const field = event.target;
    if (! field || field.tagName !== 'INPUT') {
        return null;
    }

    const form = field.form;
    if (! form || typeof form.hasAttribute !== 'function' || ! form.hasAttribute('data-autosave')) {
        return null;
    }

    if (event.type === 'keydown' && (event.key !== 'Enter' || field.type === 'date')) {
        return null;
    }

    return form;
}

/**
 * @param {HTMLFormElement} form
 * @param {(input: RequestInfo, init?: RequestInit) => Promise<Response>} fetchImpl
 * @returns {Promise<{ok: boolean, message: string}>}
 */
export async function autosaveProjectForm(form, fetchImpl = fetch) {
    const token = csrfToken(form);
    const response = await fetchImpl(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token,
        },
    });
    const payload = await response.json().catch(() => ({}));
    if (! response.ok) {
        return { ok: false, message: firstAutosaveError(payload) };
    }

    return { ok: true, message: 'Opgeslagen ✓' };
}

/**
 * @param {ParentNode|null} root
 * @param {(input: RequestInfo, init?: RequestInit) => Promise<Response>} fetchImpl
 * @param {(form: HTMLFormElement, result: {ok: boolean, message: string}) => void} onStatus
 */
export function bindProjectListAutosave(
    root = document,
    fetchImpl = fetch,
    onStatus = showAutosaveStatus,
) {
    if (root == null || typeof root.addEventListener !== 'function') {
        return;
    }

    /** @type {WeakMap<HTMLFormElement, {saving: boolean, again: boolean, run: () => Promise<void>}>} */
    const savers = new WeakMap();

    const saverFor = (form) => {
        let saver = savers.get(form);
        if (saver) {
            return saver;
        }

        saver = {
            saving: false,
            again: false,
            run: async () => {
                if (saver.saving) {
                    saver.again = true;

                    return;
                }

                saver.saving = true;
                try {
                    do {
                        saver.again = false;
                        const result = await autosaveProjectForm(form, fetchImpl);
                        onStatus(form, result);
                    } while (saver.again);
                } finally {
                    saver.saving = false;
                }
            },
        };
        savers.set(form, saver);

        return saver;
    };

    root.addEventListener('change', (event) => {
        const form = autosaveFormFromEvent(event);
        if (form) {
            saverFor(form).run();
        }
    });
    root.addEventListener('keydown', (event) => {
        const form = autosaveFormFromEvent(event);
        if (! form) {
            return;
        }

        event.preventDefault();
        if (event.target instanceof HTMLInputElement) {
            event.target.blur();
        }
    });
    root.addEventListener('submit', (event) => {
        const form = event.target;
        if (! form || typeof form.hasAttribute !== 'function' || ! form.hasAttribute('data-autosave')) {
            return;
        }

        event.preventDefault();
        saverFor(form).run();
    });
}

/**
 * @param {HTMLFormElement} form
 * @returns {string}
 */
function csrfToken(form) {
    const fromForm = form.querySelector?.('input[name="_token"]')?.value;
    if (fromForm) {
        return String(fromForm);
    }

    if (typeof document === 'undefined') {
        return '';
    }

    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * @param {HTMLFormElement} form
 * @param {{ok: boolean, message: string}} result
 */
export function showAutosaveStatus(form, result) {
    const row = form.closest?.('tr');
    const locals = [
        row?.querySelector?.('[data-autosave-status]'),
        typeof document !== 'undefined' ? document.querySelector('[data-autosave-status-global]') : null,
    ].filter(Boolean);

    locals.forEach((el) => {
        el.textContent = result.ok
            ? 'Opgeslagen ✓'
            : (result.message && result.message !== 'Opslaan mislukt'
                ? 'Opslaan mislukt · '+result.message
                : 'Opslaan mislukt');
        el.classList.toggle('text-nicon-ok', result.ok);
        el.classList.toggle('text-nicon-danger', ! result.ok);
        el.classList.remove('hidden');
    });

    if (result.ok) {
        window.setTimeout(() => {
            locals.forEach((el) => {
                if (el.textContent === 'Opgeslagen ✓') {
                    el.textContent = '';
                    el.classList.add('hidden');
                }
            });
        }, 2500);
    }
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bindProjectListAutosave());
    } else {
        bindProjectListAutosave();
    }
}
