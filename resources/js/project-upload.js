export const TYPE_LABELS = {
    meetstaat: 'Meetstaat',
    afmetingen: 'Afmetingen',
    materialenstaat: 'Materialenstaat',
    snijmaten: 'Snijmaten',
    plattegrond: 'Plattegrond',
    overig: 'Overig',
};

export function classifyFilename(name) {
    const flat = String(name || '').toLowerCase();
    const extension = flat.split('.').pop() || '';

    if (/snijma(a)?t/.test(flat)) {
        return { type: 'snijmaten', confidence: 'high' };
    }
    if (/materiallist|materialenstaat|materiaalstaat|materialen[_-]?lijst|materiaallijst/.test(flat)) {
        return { type: 'materialenstaat', confidence: 'high' };
    }
    if (/plattegrond|tekening|floor.?plan|\bdrawing\b/.test(flat)) {
        return { type: 'plattegrond', confidence: 'high' };
    }
    if (/afmetingen|matenlijst|handgeschreven/.test(flat)) {
        return { type: 'afmetingen', confidence: 'high' };
    }
    if (/meetstaat|meetbon/.test(flat)) {
        return { type: 'meetstaat', confidence: 'high' };
    }
    if (['csv', 'xlsx', 'xls', 'xlsm', 'txt'].includes(extension)) {
        return { type: 'meetstaat', confidence: 'low' };
    }

    return { type: 'overig', confidence: 'low' };
}

export function typeLabel(type) {
    return TYPE_LABELS[type] || 'Overig';
}

export function bindProjectUpload(form) {
    const input = form.querySelector('[data-upload-input]');
    const dropzone = form.querySelector('[data-upload-dropzone]');
    const list = form.querySelector('[data-upload-list]');
    const empty = form.querySelector('[data-upload-empty]');
    if (! input || ! dropzone || ! list) {
        return;
    }

    const entries = [];

    function syncInput() {
        const transfer = new DataTransfer();
        entries.forEach((entry) => transfer.items.add(entry.file));
        input.files = transfer.files;
    }

    function render() {
        list.replaceChildren();
        entries.forEach((entry, index) => {
            list.append(rowFor(entry, index));
        });
        if (empty) {
            empty.classList.toggle('hidden', entries.length > 0);
        }
        syncInput();
    }

    function rowFor(entry, index) {
        const certain = entry.confidence === 'high' || entry.confidence === 'manual';
        const row = document.createElement('li');
        row.className = 'flex flex-col gap-2 border border-nicon-line px-3 py-2 sm:flex-row sm:items-center sm:gap-3';

        const summary = document.createElement('div');
        summary.className = 'min-w-0 grow text-sm';
        const mark = certain ? '✓' : '⚠';
        const status = certain ? typeLabel(entry.type) : 'Type controleren';
        summary.textContent = `${mark} ${entry.file.name} — ${status}`;
        if (! certain) {
            summary.classList.add('text-nicon-warn');
        }

        const typeField = document.createElement('select');
        typeField.name = 'types[]';
        typeField.className = 'border border-nicon-line bg-white px-2 py-1 text-sm';
        Object.entries(TYPE_LABELS).forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            if (value === entry.type) {
                option.selected = true;
            }
            typeField.append(option);
        });
        typeField.addEventListener('change', () => {
            entry.type = typeField.value;
            entry.confidence = 'manual';
            render();
        });

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'text-sm text-nicon-danger';
        remove.textContent = 'Verwijderen';
        remove.addEventListener('click', () => {
            entries.splice(index, 1);
            render();
        });

        row.append(summary, typeField, remove);

        return row;
    }

    function addFiles(fileList) {
        Array.from(fileList || []).forEach((file) => {
            if (entries.some((entry) => sameFile(entry.file, file))) {
                return;
            }
            const classified = classifyFilename(file.name);
            entries.push({
                file,
                type: classified.type,
                confidence: classified.confidence,
            });
        });
        render();
    }

    function sameFile(left, right) {
        return left.name === right.name && left.size === right.size && left.lastModified === right.lastModified;
    }

    input.addEventListener('change', () => {
        addFiles(input.files);
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('border-nicon-orange');
        });
    });
    ['dragleave', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('border-nicon-orange');
        });
    });
    dropzone.addEventListener('drop', (event) => {
        addFiles(event.dataTransfer?.files);
    });

    form.addEventListener('submit', () => {
        syncInput();
    });

    render();
}

if (typeof document !== 'undefined') {
    const uploadForm = document.querySelector('[data-project-upload]');
    if (uploadForm) {
        bindProjectUpload(uploadForm);
    }

    const workTypeSelect = document.querySelector('[data-work-type-select]');
    const workTypeCustom = document.querySelector('[data-work-type-custom]');
    if (workTypeSelect && workTypeCustom) {
        const syncCustom = () => {
            const show = workTypeSelect.value === '__custom__';
            workTypeCustom.classList.toggle('hidden', ! show);
            if (! show) {
                workTypeCustom.value = '';
            }
        };
        workTypeSelect.addEventListener('change', syncCustom);
        syncCustom();
    }
}
