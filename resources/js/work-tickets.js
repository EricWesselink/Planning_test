function parseJson(value, fallback) {
    try {
        return JSON.parse(value || '');
    } catch (error) {
        return fallback;
    }
}

function selectedAreaIds(form) {
    const ids = [];
    form.querySelectorAll('[data-floor-block]').forEach((block) => {
        const included = block.querySelector('.work-ticket-floor');
        if (! included?.checked) {
            return;
        }
        const entireInput = block.querySelector('.work-ticket-scope[value="entire"]');
        const hiddenScope = block.querySelector('input[name$="[scope]"][type="hidden"]');
        const entire = entireInput ? entireInput.checked : hiddenScope?.value === 'entire';
        const rooms = [...block.querySelectorAll('.work-ticket-area')];
        if (entire) {
            rooms.forEach((input) => ids.push(Number(input.value)));
            return;
        }
        rooms.filter((input) => input.checked).forEach((input) => ids.push(Number(input.value)));
    });

    return ids;
}

function selectedWorkIds(form) {
    return [...form.querySelectorAll('.work-ticket-work:checked')].map((input) => Number(input.value));
}

function formatQty(value) {
    return new Intl.NumberFormat('nl-NL', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(value);
}

function formatMoney(value) {
    return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(value);
}

function sumQuantity(quantities, areaIds, workItemId) {
    return areaIds.reduce((total, areaId) => {
        const row = quantities[areaId]?.[workItemId];
        return total + (row ? Number(row.quantity) : 0);
    }, 0);
}

function syncFloorUi(block) {
    const included = block.querySelector('.work-ticket-floor');
    const entire = block.querySelector('.work-ticket-scope[value="entire"]');
    const hiddenScope = block.querySelector('input[name$="[scope]"][type="hidden"]');
    const rooms = block.querySelectorAll('.work-ticket-area');
    const enabled = Boolean(included?.checked);
    block.querySelectorAll('.work-ticket-scope').forEach((input) => {
        input.disabled = ! enabled;
    });
    const lockRooms = enabled && (Boolean(entire?.checked) || hiddenScope?.value === 'entire');
    rooms.forEach((input) => {
        input.disabled = ! enabled || lockRooms;
        if (lockRooms) {
            input.checked = true;
        }
    });
}

function suggestDrawings(form) {
    if (form.querySelector('.work-ticket-drawing[data-touched="1"]')) {
        return;
    }
    const selectedNames = [...form.querySelectorAll('[data-floor-block]')].flatMap((block) => {
        const included = block.querySelector('.work-ticket-floor');
        return included?.checked ? [String(block.dataset.floorName || '').toLowerCase()] : [];
    });
    form.querySelectorAll('.work-ticket-drawing').forEach((input) => {
        const floor = String(input.dataset.floor || '').toLowerCase();
        input.checked = floor !== '' && selectedNames.some((name) => name.includes(floor) || floor.includes(name));
    });
}

function renderPreview(form) {
    const quantities = parseJson(form.dataset.quantities, {});
    const workItems = parseJson(form.dataset.workItems, []);
    const areaIds = selectedAreaIds(form);
    const workIds = selectedWorkIds(form);
    const billing = form.querySelector('.work-ticket-billing:checked')?.value || 'unit';
    const showPrices = form.dataset.external === '1' && billing === 'unit';
    const body = form.querySelector('#work-ticket-preview');
    if (! body) {
        return;
    }

    const rows = workItems.filter((item) => workIds.includes(Number(item.id))).map((item) => {
        const quantity = sumQuantity(quantities, areaIds, Number(item.id));
        const priceInputName = `unit_prices[${item.id}]`;
        const existing = form.querySelector(`[name="${priceInputName}"]`);
        const price = existing ? existing.value : (item.suggested_price ?? '');
        const amount = showPrices && price !== '' ? Number(quantity) * Number(String(price).replace(',', '.')) : null;

        return { item, quantity, price, amount };
    }).filter((row) => row.quantity > 0.0001);

    if (rows.length === 0) {
        const cols = form.dataset.external === '1' ? 4 : 2;
        body.innerHTML = `<tr><td class="px-4 py-3 text-nicon-muted" colspan="${cols}">Selecteer ruimtes en werkzaamheden.</td></tr>`;
        return;
    }

    body.innerHTML = rows.map((row) => {
        const priceCell = showPrices
            ? `<td class="px-4 py-2 text-right">
                    <input type="text" inputmode="decimal" name="unit_prices[${row.item.id}]" value="${row.price ?? ''}" class="work-ticket-price w-24 border border-nicon-line px-2 py-1 text-right">
                    <span class="text-nicon-muted">/${row.item.unit_label}</span>
               </td>
               <td class="px-4 py-2 text-right">${row.amount === null || Number.isNaN(row.amount) ? '—' : formatMoney(row.amount)}</td>`
            : (form.dataset.external === '1' ? '<td class="px-4 py-2"></td><td class="px-4 py-2"></td>' : '');

        return `<tr class="border-t border-nicon-line">
            <td class="px-4 py-2">${row.item.name}</td>
            <td class="px-4 py-2 text-right">${formatQty(row.quantity)} ${row.item.unit_label}</td>
            ${priceCell}
        </tr>`;
    }).join('');
}

function syncBilling(form) {
    const billing = form.querySelector('.work-ticket-billing:checked')?.value || 'unit';
    form.querySelector('.work-ticket-hourly')?.classList.toggle('hidden', billing !== 'hourly');
    form.querySelector('.work-ticket-fixed')?.classList.toggle('hidden', billing !== 'fixed');
    form.querySelectorAll('.work-ticket-price-col').forEach((cell) => {
        cell.classList.toggle('hidden', billing !== 'unit');
    });
}

function initWorkTicketForm(form) {
    form.querySelectorAll('[data-floor-block]').forEach(syncFloorUi);
    syncBilling(form);
    suggestDrawings(form);
    renderPreview(form);

    form.addEventListener('change', (event) => {
        const target = event.target;
        if (! (target instanceof HTMLElement)) {
            return;
        }
        if (target.classList.contains('work-ticket-floor') || target.classList.contains('work-ticket-scope')) {
            const block = target.closest('[data-floor-block]');
            if (block) {
                syncFloorUi(block);
            }
            suggestDrawings(form);
        }
        if (target.classList.contains('work-ticket-area') && target instanceof HTMLInputElement && target.checked) {
            const block = target.closest('[data-floor-block]');
            const included = block?.querySelector('.work-ticket-floor');
            if (included instanceof HTMLInputElement) {
                included.checked = true;
            }
        }
        if (target.classList.contains('work-ticket-drawing')) {
            target.dataset.touched = '1';
        }
        if (target.classList.contains('work-ticket-billing')) {
            syncBilling(form);
        }
        renderPreview(form);
    });
}

const form = document.getElementById('work-ticket-form');
if (form instanceof HTMLFormElement) {
    initWorkTicketForm(form);
}

export { selectedAreaIds, sumQuantity };
