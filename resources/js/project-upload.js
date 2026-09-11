export const TYPE_LABELS = {
    meetstaat: 'Meetstaat',
    materialenstaat: 'Materialenstaat',
    plattegrond: 'Plattegrond',
    calculatie: 'Calculatie',
    afmetingen: 'Afmetingen',
    snijmaten: 'Snijmaten',
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
    if (/calculatie|kalkulatie/.test(flat)) {
        return { type: 'calculatie', confidence: 'high' };
    }
    if (['csv', 'xlsx', 'xls', 'xlsm', 'txt'].includes(extension)) {
        return { type: 'overig', confidence: 'low' };
    }

    return { type: 'overig', confidence: 'low' };
}

export function looksLikeCalculationText(text) {
    const flat = String(text || '').toLowerCase().replace(/[\u0000-\u001f]+/g, ' ');
    const hasMu = /m\s*\/\s*u/.test(flat);
    const hasQuantity = /\baantal\b/.test(flat);
    const hasCost = /\bkostprijs\b/.test(flat);
    const hasDescription = /\bomschrijving\b/.test(flat) || /\bproductie\b/.test(flat);

    return hasMu && hasQuantity && hasCost && hasDescription;
}

export async function classifyFile(file) {
    const named = classifyFilename(file?.name || '');
    const extension = String(file?.name || '').toLowerCase().split('.').pop() || '';

    if (['csv', 'txt', 'xlsx', 'xls', 'xlsm'].includes(extension)) {
        const text = await spreadsheetText(file);
        if (looksLikeCalculationText(text)) {
            return { type: 'calculatie', confidence: 'high' };
        }
        if (named.confidence === 'high') {
            return named;
        }

        return { type: 'overig', confidence: 'low' };
    }

    return named;
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

    async function addFiles(fileList) {
        const incoming = Array.from(fileList || []).filter(
            (file) => ! entries.some((entry) => sameFile(entry.file, file)),
        );

        for (const file of incoming) {
            const classified = await classifyFile(file);
            entries.push({
                file,
                type: classified.type,
                confidence: classified.confidence,
            });
        }

        render();
    }

    function sameFile(left, right) {
        return left.name === right.name && left.size === right.size && left.lastModified === right.lastModified;
    }

    input.addEventListener('change', () => {
        void addFiles(input.files);
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
        void addFiles(event.dataTransfer?.files);
    });

    form.addEventListener('submit', () => {
        syncInput();
    });

    render();
}

async function spreadsheetText(file) {
    const buffer = new Uint8Array(await file.arrayBuffer());
    const extension = String(file?.name || '').toLowerCase().split('.').pop() || '';

    if (['xlsx', 'xlsm'].includes(extension) || isZip(buffer)) {
        const xml = await xlsxXmlText(buffer);
        if (xml !== '') {
            return xml;
        }
    }

    return new TextDecoder('utf-8', { fatal: false }).decode(buffer);
}

function isZip(bytes) {
    return bytes.length >= 4 && bytes[0] === 0x50 && bytes[1] === 0x4b;
}

async function xlsxXmlText(bytes) {
    const parts = [];
    let offset = 0;

    while (offset + 30 <= bytes.length) {
        if (bytes[offset] !== 0x50 || bytes[offset + 1] !== 0x4b) {
            break;
        }
        if (bytes[offset + 2] === 0x01 && bytes[offset + 3] === 0x02) {
            break;
        }
        if (bytes[offset + 2] !== 0x03 || bytes[offset + 3] !== 0x04) {
            break;
        }

        const view = new DataView(bytes.buffer, bytes.byteOffset + offset, 30);
        const method = view.getUint16(8, true);
        const compressedSize = view.getUint32(18, true);
        const nameLength = view.getUint16(26, true);
        const extraLength = view.getUint16(28, true);
        const nameStart = offset + 30;
        const nameEnd = nameStart + nameLength;
        const dataStart = nameEnd + extraLength;
        const dataEnd = dataStart + compressedSize;

        if (nameEnd > bytes.length || dataEnd > bytes.length) {
            break;
        }

        const name = new TextDecoder().decode(bytes.slice(nameStart, nameEnd));
        if (/xl\/(sharedStrings\.xml|worksheets\/sheet\d+\.xml)$/i.test(name) && compressedSize > 0) {
            const payload = bytes.slice(dataStart, dataEnd);
            try {
                const xml = method === 0 ? payload : await inflateRaw(payload);
                parts.push(new TextDecoder('utf-8', { fatal: false }).decode(xml));
            } catch {
                // Skip entries that cannot be inflated.
            }
        }

        offset = dataEnd;
        if (compressedSize === 0 && nameLength === 0) {
            break;
        }
    }

    return parts.join('\n');
}

async function inflateRaw(payload) {
    const stream = new Blob([payload]).stream().pipeThrough(new DecompressionStream('deflate-raw'));

    return new Uint8Array(await new Response(stream).arrayBuffer());
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
