function parseMeasurementAmount(value) {
    let text = String(value ?? '').trim();
    if (text.includes(',')) {
        text = text.replace(/\./g, '').replace(',', '.');
    }
    const number = Number.parseFloat(text);

    return Number.isFinite(number) ? number : 0;
}

function roundMeasurementQty(value) {
    return Math.round((Number(value) || 0) * 100) / 100;
}

function formatMeasurementQty(value) {
    return new Intl.NumberFormat('nl-NL', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(roundMeasurementQty(value));
}

function unitLabel(unit) {
    if (unit === 'm2') {
        return 'm²';
    }
    if (unit === 'm1') {
        return 'm¹';
    }

    return unit || '';
}

function remainingQuantity(available, othersUsed) {
    return Math.max(0, roundMeasurementQty(available - othersUsed));
}

function clampEnteredQuantity(entered, remaining) {
    if (entered <= remaining + 0.001) {
        return roundMeasurementQty(entered);
    }

    return remaining;
}

function clampMessage(productName, remaining, unit) {
    return `Maximaal nog ${formatMeasurementQty(remaining)} ${unitLabel(unit)} ${productName} beschikbaar.`;
}

function fullyAllocatedMessage(productName, available, unit) {
    const total = formatMeasurementQty(available);

    return `${productName} is volledig verdeeld (${total} / ${total} ${unitLabel(unit)}).`;
}

function productQuantitySuggestion(product, othersUsed) {
    if (! product || product.quantity <= 0.0001 || (product.unit !== 'm2' && product.unit !== 'm1')) {
        return { quantity: '', message: '' };
    }

    const remaining = remainingQuantity(product.quantity, othersUsed);
    if (remaining <= 0.001) {
        return {
            quantity: '',
            message: fullyAllocatedMessage(product.name, product.quantity, product.unit),
        };
    }

    return { quantity: formatMeasurementQty(remaining), message: '' };
}

function selectedFloorProducts(root) {
    const form = root.closest('form') || document;
    const products = [];
    form.querySelectorAll('[data-shop-floor-product]').forEach((row) => {
        if (! row.querySelector('[data-shop-activity-toggle]')?.checked) {
            return;
        }
        const name = (row.dataset.activityName || '').trim();
        if (name === '') {
            return;
        }
        products.push({
            name,
            quantity: parseMeasurementAmount(row.querySelector('[data-shop-activity-quantity]')?.value),
            unit: row.querySelector('[data-shop-activity-unit]')?.value || 'm2',
        });
    });

    return products;
}

function rowUnitForProduct(row, product) {
    const selected = row.querySelector('[data-measurement-unit]')?.value;

    return selected || product?.unit || '';
}

function allocatedByProduct(rows, products = []) {
    const byName = Object.fromEntries(products.map((product) => [product.name, product]));
    const allocated = {};
    rows.querySelectorAll('[data-measurement-row]').forEach((row) => {
        const name = row.querySelector('[data-measurement-product]')?.value?.trim();
        const quantity = parseMeasurementAmount(row.querySelector('[data-measurement-quantity]')?.value);
        const product = byName[name];
        const unit = rowUnitForProduct(row, product);
        if (! name || quantity <= 0 || (unit !== 'm2' && unit !== 'm1')) {
            return;
        }
        if (product && unit !== product.unit) {
            return;
        }
        allocated[name] = allocated[name] || {};
        allocated[name][unit] = (allocated[name][unit] || 0) + quantity;
    });

    return allocated;
}

function othersUsedFor(rows, currentRow, productName, unit, products = []) {
    const byName = Object.fromEntries(products.map((product) => [product.name, product]));
    let used = 0;
    rows.querySelectorAll('[data-measurement-row]').forEach((row) => {
        if (row === currentRow) {
            return;
        }
        const name = row.querySelector('[data-measurement-product]')?.value?.trim();
        if (name !== productName) {
            return;
        }
        const product = byName[name];
        const rowUnit = rowUnitForProduct(row, product);
        if (rowUnit !== unit) {
            return;
        }
        used += parseMeasurementAmount(row.querySelector('[data-measurement-quantity]')?.value);
    });

    return roundMeasurementQty(used);
}

function allocationLines(products, allocated) {
    return products
        .filter((product) => product.quantity > 0.0001 && (product.unit === 'm2' || product.unit === 'm1'))
        .map((product) => {
            const used = allocated[product.name]?.[product.unit] || 0;
            const remaining = remainingQuantity(product.quantity, used);
            const over = used > product.quantity + 0.001;
            const label = unitLabel(product.unit);
            const base = `${product.name}: ${formatMeasurementQty(used)} / ${formatMeasurementQty(product.quantity)} ${label} verdeeld`;

            return {
                name: product.name,
                used,
                available: product.quantity,
                remaining,
                unit: product.unit,
                over,
                text: remaining > 0.001 ? `${base} — nog ${formatMeasurementQty(remaining)} ${label}` : base,
                error: over
                    ? `Te veel ingevoerd. ${product.name}: ${formatMeasurementQty(product.quantity)} ${label} beschikbaar, ${formatMeasurementQty(used)} ${label} reeds verdeeld.`
                    : '',
            };
        });
}

function applyProductUnit(row, products) {
    const name = row.querySelector('[data-measurement-product]')?.value?.trim();
    const product = products.find((item) => item.name === name);
    const unitSelect = row.querySelector('[data-measurement-unit]');
    if (! product || ! unitSelect) {
        return;
    }
    unitSelect.value = product.unit;
}

function fillRowFromProduct(row, products, rows) {
    const input = row.querySelector('[data-measurement-quantity]');
    const name = row.querySelector('[data-measurement-product]')?.value?.trim();
    const product = products.find((item) => item.name === name);
    if (! input || ! name || ! product) {
        return '';
    }

    applyProductUnit(row, products);
    const suggestion = productQuantitySuggestion(
        product,
        othersUsedFor(rows, row, product.name, product.unit, products),
    );
    input.value = suggestion.quantity;

    return suggestion.message;
}

function clampRow(row, products, rows) {
    const input = row.querySelector('[data-measurement-quantity]');
    const name = row.querySelector('[data-measurement-product]')?.value?.trim();
    const product = products.find((item) => item.name === name);
    if (! input || ! product || product.quantity <= 0.0001) {
        return '';
    }
    applyProductUnit(row, products);
    const unit = rowUnitForProduct(row, product);
    if (unit !== product.unit) {
        return '';
    }
    if (String(input.value).trim() === '') {
        return '';
    }

    const remaining = remainingQuantity(product.quantity, othersUsedFor(rows, row, product.name, product.unit, products));
    const entered = parseMeasurementAmount(input.value);
    const clamped = clampEnteredQuantity(entered, remaining);
    if (entered <= remaining + 0.001) {
        return '';
    }
    input.value = formatMeasurementQty(clamped);

    return clampMessage(product.name, remaining, product.unit);
}

function syncProductOptions(select, products) {
    const current = select.value;
    const names = products.map((product) => product.name);
    if (current && ! names.includes(current)) {
        names.unshift(current);
    }
    const existing = [...select.options].map((option) => option.value);
    const next = ['', ...names];
    if (existing.length === next.length && existing.every((value, index) => value === next[index])) {
        return;
    }
    select.innerHTML = '';
    const blank = document.createElement('option');
    blank.value = '';
    select.append(blank);
    names.forEach((name) => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        if (name === current) {
            option.selected = true;
        }
        select.append(option);
    });
    if (current) {
        select.value = current;
    }
}

function setLocationOpen(row, available, editable) {
    const location = row.querySelector('[data-measurement-location]');
    if (! location) {
        return;
    }
    location.hidden = ! available;
    location.classList.toggle('hidden', ! available);
    if (! available) {
        location.value = '';
    }
    location.disabled = ! editable || ! available;
}

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
    const allocation = root.querySelector('[data-measurement-allocation]');
    const allocationError = root.querySelector('[data-measurement-allocation-error]');
    const form = root.closest('form');
    const canEdit = root.dataset.measurementEditable !== '0';
    let notice = '';

    const renderAllocation = (products) => {
        const lines = allocationLines(products, allocatedByProduct(rows || root, products));
        if (allocation) {
            allocation.innerHTML = lines.map((line) => `<li${line.over ? ' class="text-nicon-danger"' : ''}>${line.text}</li>`).join('');
        }
        const error = notice || lines.find((line) => line.over)?.error || '';
        if (allocationError) {
            allocationError.hidden = error === '';
            allocationError.classList.toggle('hidden', error === '');
            allocationError.textContent = error;
        }

        return lines.find((line) => line.over)?.error || '';
    };

    const syncOptions = (products) => {
        rows?.querySelectorAll('[data-measurement-product]').forEach((select) => {
            syncProductOptions(select, products);
        });
        template?.content?.querySelectorAll('[data-measurement-product]').forEach((select) => {
            syncProductOptions(select, products);
        });
    };

    const refresh = (activeRow = null, clampAll = false, fillProduct = false) => {
        const products = selectedFloorProducts(root);
        syncOptions(products);
        if (! rows || ! canEdit) {
            return renderAllocation(products);
        }
        if (fillProduct && activeRow) {
            notice = fillRowFromProduct(activeRow, products, rows);
            rows.querySelectorAll('[data-measurement-row]').forEach((row) => {
                if (row === activeRow) {
                    return;
                }
                applyProductUnit(row, products);
                notice = clampRow(row, products, rows) || notice;
            });
        } else if (clampAll) {
            notice = '';
            rows.querySelectorAll('[data-measurement-row]').forEach((row) => {
                applyProductUnit(row, products);
                notice = clampRow(row, products, rows) || notice;
            });
        } else if (activeRow) {
            applyProductUnit(activeRow, products);
            notice = clampRow(activeRow, products, rows) || '';
        }

        return renderAllocation(products);
    };

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
        refresh(null, true);
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
        refresh(null, true);
    });

    root.addEventListener('change', (event) => {
        const available = event.target.closest('[data-measurement-available]');
        if (available) {
            const row = available.closest('[data-measurement-row]');
            if (row) {
                setLocationOpen(row, available.value === '1', canEdit);
            }
        }
        const row = event.target.closest('[data-measurement-row]');
        if (event.target.closest('[data-measurement-product]')) {
            refresh(row, false, true);

            return;
        }
        if (event.target.closest('[data-measurement-unit]')) {
            refresh(null, true);

            return;
        }
        refresh(row);
    });
    root.addEventListener('input', (event) => {
        const row = event.target.closest('[data-measurement-row]');
        if (event.target.closest('[data-measurement-quantity]')) {
            refresh(row);

            return;
        }
        refresh(row);
    });

    form?.addEventListener('change', (event) => {
        if (event.target.closest('[data-shop-activity]')) {
            refresh(null, true);
        }
    });
    form?.addEventListener('input', (event) => {
        if (event.target.closest('[data-shop-activity-quantity], [data-shop-activity-unit]')) {
            refresh(null, true);
        }
    });
    form?.addEventListener('submit', (event) => {
        const error = refresh(null, true);
        if (error && canEdit) {
            event.preventDefault();
            setOpen(true);
            allocationError?.scrollIntoView({ block: 'nearest' });
        }
    });

    rows?.querySelectorAll('[data-measurement-row]').forEach((row) => {
        const available = row.querySelector('[data-measurement-available]');
        setLocationOpen(row, available?.value === '1', canEdit);
    });
    refresh(null, canEdit);
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-measurement-form]').forEach((root) => bindMeasurementForm(root));
}

export {
    allocatedByProduct,
    allocationLines,
    clampEnteredQuantity,
    clampMessage,
    formatMeasurementQty,
    parseMeasurementAmount,
    productQuantitySuggestion,
    remainingQuantity,
    reindexMeasurementRows,
    selectedFloorProducts,
    syncProductOptions,
};
