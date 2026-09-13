@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('voucher-form');
    const tbody = document.getElementById('voucher-lines');
    const template = document.getElementById('voucher-line-template');
    const addButton = document.getElementById('voucher-add-line');
    if (!form || !tbody) return;

    const money = (value) => '€ ' + value.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const qty = (value) => value.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const number = (value) => Number(String(value).replace(/\s/g, '').replace(',', '.')) || 0;

    const syncKind = (row) => {
        const kind = row.querySelector('.voucher-kind')?.value || 'unit';
        const priceWrap = row.querySelector('.voucher-unit-price-wrap');
        const amountInput = row.querySelector('.voucher-fixed-amount');
        const amountCell = row.querySelector('.voucher-amount');
        const isFixed = kind === 'fixed';
        if (priceWrap) {
            priceWrap.classList.toggle('hidden', isFixed);
        }
        if (amountInput) {
            amountInput.toggleAttribute('readonly', ! isFixed);
            amountInput.closest('div')?.classList.toggle('hidden', ! isFixed);
        }
        if (amountCell) {
            amountCell.classList.toggle('hidden', isFixed);
        }
    };

    const roomsOf = (groupKey) => tbody.querySelectorAll('.voucher-room[data-group="'+groupKey+'"]');

    const fillHourlyRate = (header) => {
        const unit = header.querySelector('.voucher-unit')?.value || 'm2';
        const priceInput = header.querySelector('.voucher-price');
        const hourly = number(form.dataset.hourlyRate);
        if (unit === 'uren' && hourly > 0 && priceInput && number(priceInput.value) <= 0) {
            priceInput.value = String(hourly).replace('.', ',');
        }
    };

    const syncHoursMode = (header) => {
        const isHours = (header.querySelector('.voucher-unit')?.value || 'm2') === 'uren';
        const wasHours = header.dataset.hoursMode === '1';
        const knownMode = header.dataset.hoursMode === '1' || header.dataset.hoursMode === '0';
        header.querySelector('.voucher-group-qty')?.classList.toggle('hidden', isHours);
        header.querySelector('.voucher-hours-qty')?.classList.toggle('hidden', ! isHours);
        roomsOf(header.dataset.group).forEach((row) => {
            const qtyInput = row.querySelector('.voucher-qty');
            qtyInput?.classList.toggle('hidden', isHours);
            if (knownMode && wasHours && ! isHours && qtyInput && row.dataset.specM2) {
                qtyInput.value = String(row.dataset.specM2).replace('.', ',');
            }
            const spec = row.querySelector('.voucher-room-spec');
            if (spec) {
                spec.classList.toggle('hidden', ! isHours);
                if (isHours && spec.textContent.trim() === '') {
                    const raw = row.dataset.specM2 || qtyInput?.value || '';
                    const meters = number(raw);
                    spec.textContent = meters > 0 ? qty(meters) + ' m²' : '';
                }
            }
            const unitLabel = row.querySelector('.voucher-room-unit');
            if (unitLabel) {
                unitLabel.textContent = isHours ? 'm²' : (header.querySelector('.voucher-unit')?.selectedOptions[0]?.textContent || '');
            }
        });
        header.dataset.hoursMode = isHours ? '1' : '0';
        if (isHours) {
            fillHourlyRate(header);
        }
    };

    const syncGroup = (header) => {
        syncKind(header);
        syncHoursMode(header);
        const groupKey = header.dataset.group;
        const kind = header.querySelector('.voucher-kind')?.value || 'unit';
        const unit = header.querySelector('.voucher-unit')?.value || 'm2';
        const isHours = unit === 'uren';
        const name = header.querySelector('.voucher-activity-name')?.value || '';
        const price = number(header.querySelector('.voucher-price')?.value);
        const rooms = [...roomsOf(groupKey)];
        const roomQty = rooms.reduce((sum, row) => sum + number(row.querySelector('.voucher-qty')?.value), 0);
        const totalQty = isHours ? number(header.querySelector('.voucher-hours-qty')?.value) : roomQty;
        let groupAmount = totalQty * price;
        if (kind === 'fixed') {
            groupAmount = number(header.querySelector('.voucher-fixed-amount')?.value);
        }

        const qtyCell = header.querySelector('.voucher-group-qty');
        if (qtyCell && ! isHours) qtyCell.textContent = qty(roomQty);
        const amountCell = header.querySelector('.voucher-amount');
        if (amountCell) amountCell.textContent = money(groupAmount);

        let allocated = 0;
        rooms.forEach((row, index) => {
            const billedQty = isHours
                ? (index === 0 ? totalQty : 0)
                : number(row.querySelector('.voucher-qty')?.value);
            const descriptionField = row.querySelector('.voucher-activity-description');
            if (descriptionField) descriptionField.value = name;
            const unitField = row.querySelector('.voucher-unit-value');
            if (unitField) unitField.value = unit;
            const kindField = row.querySelector('.voucher-kind-value');
            if (kindField) kindField.value = kind;
            const priceField = row.querySelector('.voucher-price');
            if (priceField) priceField.value = header.querySelector('.voucher-price')?.value ?? '';
            let roomAmount = billedQty * price;
            if (kind === 'fixed') {
                if (index === rooms.length - 1) {
                    roomAmount = Math.round((groupAmount - allocated) * 100) / 100;
                } else if (totalQty > 0) {
                    roomAmount = Math.round((billedQty / totalQty) * groupAmount * 100) / 100;
                    allocated += roomAmount;
                } else {
                    roomAmount = 0;
                }
            }
            const amountField = row.querySelector('.voucher-fixed-amount');
            if (amountField) amountField.value = roomAmount > 0 ? roomAmount.toFixed(2).replace('.', ',') : '';
        });

        return groupAmount;
    };

    const recount = () => {
        let total = 0;
        tbody.querySelectorAll('.voucher-group').forEach((header) => {
            total += syncGroup(header);
        });
        tbody.querySelectorAll('.voucher-line:not(.voucher-room)').forEach((row) => {
            syncKind(row);
            const kind = row.querySelector('.voucher-kind')?.value || 'unit';
            const qtyValue = number(row.querySelector('.voucher-qty')?.value);
            const price = number(row.querySelector('.voucher-price')?.value);
            const amountInput = row.querySelector('.voucher-fixed-amount');
            let amount = qtyValue * price;
            if (kind === 'fixed') {
                amount = number(amountInput?.value);
            } else if (amountInput && document.activeElement !== amountInput) {
                amountInput.value = amount > 0 ? amount.toFixed(2).replace('.', ',') : '';
            }
            total += amount;
            const cell = row.querySelector('.voucher-amount');
            if (cell) cell.textContent = money(amount);
        });
        const totalCell = document.getElementById('voucher-total');
        if (totalCell) totalCell.textContent = money(total);
    };

    const nextIndex = () => {
        let max = -1;
        tbody.querySelectorAll('.voucher-line [name^="lines["]').forEach((input) => {
            const match = input.name.match(/^lines\[(\d+)\]/);
            if (match) max = Math.max(max, Number(match[1]));
        });
        return max + 1;
    };

    const ensureLine = () => {
        if (tbody.querySelector('.voucher-line') || !template) return;
        const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex()));
        tbody.insertAdjacentHTML('beforeend', html);
    };

    form.addEventListener('input', recount);
    form.addEventListener('change', recount);
    tbody.addEventListener('click', (event) => {
        const groupButton = event.target.closest('.voucher-remove-group');
        if (groupButton) {
            const header = groupButton.closest('.voucher-group');
            const groupKey = header?.dataset.group;
            header?.remove();
            roomsOf(groupKey).forEach((row) => row.remove());
            ensureLine();
            recount();
            return;
        }
        const button = event.target.closest('.voucher-remove');
        if (!button) return;
        const row = button.closest('.voucher-line');
        const groupKey = row?.dataset.group;
        const rows = tbody.querySelectorAll('.voucher-line');
        if (rows.length <= 1) {
            row.querySelectorAll('input[type="text"]').forEach((input) => { input.value = ''; });
            recount();
            return;
        }
        row?.remove();
        if (groupKey && roomsOf(groupKey).length === 0) {
            tbody.querySelector('.voucher-group[data-group="'+groupKey+'"]')?.remove();
            ensureLine();
        }
        recount();
    });

    addButton?.addEventListener('click', () => {
        if (!template) return;
        const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex()));
        tbody.insertAdjacentHTML('beforeend', html);
        recount();
    });

    recount();
});
</script>
@endpush
