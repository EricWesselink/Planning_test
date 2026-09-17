function reindexMeasurementRows(rows) {
    rows.querySelectorAll('[data-measurement-row]').forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace(/measurement\[rows\]\[[^\]]+\]/, `measurement[rows][${index}]`);
        });
    });
}

function bindMeasurementForm(root) {
    const panel = root.querySelector('[data-measurement-panel]');
    const toggle = root.querySelector('[data-measurement-toggle]');
    const rows = root.querySelector('[data-measurement-rows]');
    const template = root.querySelector('[data-measurement-row-template]');
    const add = root.querySelector('[data-measurement-add]');

    const setOpen = (open) => {
        if (! panel) {
            return;
        }
        panel.hidden = ! open;
        panel.classList.toggle('hidden', ! open);
        if (toggle) {
            toggle.textContent = open
                ? (toggle.dataset.openLabel || 'Inmeetformulier verbergen')
                : (toggle.dataset.closedLabel || 'Inmeetformulier invullen');
        }
    };

    toggle?.addEventListener('click', () => {
        const open = panel?.hidden || panel?.classList.contains('hidden');
        setOpen(Boolean(open));
    });

    add?.addEventListener('click', () => {
        const node = template?.content?.firstElementChild?.cloneNode(true);
        if (! node || ! rows) {
            return;
        }
        rows.append(node);
        reindexMeasurementRows(rows);
        setOpen(true);
    });

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-measurement-remove]');
        if (! button || ! root.contains(button)) {
            return;
        }
        const row = button.closest('[data-measurement-row]');
        if (! row || ! rows) {
            return;
        }
        row.remove();
        if (rows.querySelectorAll('[data-measurement-row]').length === 0 && template?.content?.firstElementChild) {
            rows.append(template.content.firstElementChild.cloneNode(true));
        }
        reindexMeasurementRows(rows);
    });
}

document.querySelectorAll('[data-measurement-form]').forEach((root) => bindMeasurementForm(root));

export { reindexMeasurementRows };
