/**
 * @typedef {{id: number, name: string, selectable: boolean, status?: string, status_label?: string}} PreferredWorker
 * @typedef {{value: string, label: string, disabled: boolean, selected: boolean}} PreferredWorkerOption
 */

/**
 * @param {PreferredWorker[]} workers
 * @param {string} selectedId
 * @param {string} placeholderLabel
 * @returns {PreferredWorkerOption[]}
 */
export function preferredWorkerOptionList(workers, selectedId, placeholderLabel = 'Geen voorkeur') {
    const selected = String(selectedId ?? '');
    const current = workers.find((worker) => String(worker.id) === selected);
    const keepSelection = selected === '' || Boolean(current?.selectable);
    const effectiveSelected = keepSelection ? selected : '';

    return [
        {
            value: '',
            label: placeholderLabel,
            disabled: false,
            selected: effectiveSelected === '',
        },
        ...workers.map((worker) => {
            const value = String(worker.id);
            const isSelected = keepSelection && value === selected;
            const busy = ! worker.selectable && ! isSelected;

            return {
                value,
                label: worker.status_label && ! worker.selectable
                    ? `${worker.name} — ${worker.status_label}`
                    : worker.name,
                disabled: busy,
                selected: isSelected,
            };
        }),
    ];
}

/**
 * @param {HTMLSelectElement} select
 * @param {PreferredWorker[]} workers
 * @param {string} selectedId
 */
export function renderPreferredWorkerOptions(select, workers, selectedId) {
    const placeholder = select.querySelector('option[value=""]');
    const placeholderLabel = placeholder?.textContent || 'Geen voorkeur';
    const options = preferredWorkerOptionList(workers, selectedId, placeholderLabel);

    select.replaceChildren();
    options.forEach((item) => {
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.label;
        option.disabled = item.disabled;
        option.selected = item.selected;
        select.append(option);
    });
}

function datesFromForm(form) {
    const start = form.querySelector('[data-planning-date][name="start_date"]');
    const end = form.querySelector('[data-planning-date][name="klaar_date"]');

    return {
        start: start instanceof HTMLInputElement ? start.value : '',
        end: end instanceof HTMLInputElement ? end.value : '',
    };
}

function bindSelect(select) {
    const form = select.form;
    const url = select.dataset.availableUrl;
    if (! form || ! url) {
        return;
    }

    let timer = 0;
    const refresh = () => {
        const { start, end } = datesFromForm(form);
        const params = new URLSearchParams();
        if (start && end) {
            params.set('start_date', start);
            params.set('end_date', end);
        }
        if (select.dataset.projectId) {
            params.set('project_id', select.dataset.projectId);
        }

        const query = params.toString();
        fetch(query ? `${url}?${query}` : url, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                if (! payload || ! Array.isArray(payload.workers)) {
                    return;
                }
                renderPreferredWorkerOptions(select, payload.workers, select.value);
            })
            .catch(() => {});
    };

    const schedule = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(refresh, 200);
    };

    form.querySelectorAll('[data-planning-date], [data-planning-year], [data-planning-week-number]').forEach((input) => {
        input.addEventListener('change', schedule);
        input.addEventListener('input', schedule);
    });
}

export function bindPreferredWorker(root = document) {
    if (root == null || typeof root.querySelectorAll !== 'function') {
        return;
    }

    root.querySelectorAll('[data-preferred-worker]').forEach((select) => {
        if (select instanceof HTMLSelectElement) {
            bindSelect(select);
        }
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bindPreferredWorker());
    } else {
        bindPreferredWorker();
    }
}
