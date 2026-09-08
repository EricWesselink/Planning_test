@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('voucher-form');
    const tbody = document.getElementById('voucher-lines');
    const template = document.getElementById('voucher-line-template');
    const addButton = document.getElementById('voucher-add-line');
    if (!form || !tbody) return;

    const money = (value) => '€ ' + value.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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

    const recount = () => {
        let total = 0;
        tbody.querySelectorAll('.voucher-line').forEach((row) => {
            syncKind(row);
            const kind = row.querySelector('.voucher-kind')?.value || 'unit';
            const qty = number(row.querySelector('.voucher-qty')?.value);
            const price = number(row.querySelector('.voucher-price')?.value);
            const amountInput = row.querySelector('.voucher-fixed-amount');
            let amount = qty * price;
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

    form.addEventListener('input', recount);
    form.addEventListener('change', recount);
    tbody.addEventListener('click', (event) => {
        const button = event.target.closest('.voucher-remove');
        if (!button) return;
        const rows = tbody.querySelectorAll('.voucher-line');
        if (rows.length <= 1) {
            const row = button.closest('.voucher-line');
            row.querySelectorAll('input[type="text"]').forEach((input) => { input.value = ''; });
            recount();
            return;
        }
        button.closest('.voucher-line')?.remove();
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
