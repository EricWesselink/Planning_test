function parseShopAmount(value) {
    let text = String(value ?? '').trim();
    if (text.includes(',')) {
        text = text.replace(/\./g, '').replace(',', '.');
    }
    const number = Number.parseFloat(text);

    return Number.isFinite(number) ? number : 0;
}

function roundShopQty(value) {
    return Math.round((Number(value) || 0) * 100) / 100;
}

function formatShopQty(value) {
    return new Intl.NumberFormat('nl-NL', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(roundShopQty(value));
}

/**
 * @param {Array<{checked?: boolean, unit?: string, quantity?: string|number}>} rows
 */
function floorCoveringSquareMeters(rows) {
    return roundShopQty((rows ?? []).reduce((total, row) => {
        if (! row?.checked || row.unit !== 'm2') {
            return total;
        }

        return total + parseShopAmount(row.quantity);
    }, 0));
}

function shouldAskLeveling(floorChecked, levelingEnabled, declined) {
    return Boolean(floorChecked) && ! levelingEnabled && ! declined;
}

function formatEuro(value) {
    const rounded = Math.round(value * 100) / 100;
    const decimals = Math.abs(rounded - Math.round(rounded)) < 0.001 ? 0 : 2;

    return new Intl.NumberFormat('nl-NL', {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(rounded);
}

function setOpen(row, enabled) {
    row.querySelectorAll('[data-shop-activity-details]').forEach((el) => {
        el.classList.toggle('hidden', ! enabled);
        el.querySelectorAll('input, select').forEach((input) => {
            input.disabled = ! enabled;
        });
    });
}

function coveringSnapshot(root) {
    return [...root.querySelectorAll('[data-shop-floor-covering]')].map((row) => ({
        checked: Boolean(row.querySelector('[data-shop-activity-toggle]')?.checked),
        unit: row.querySelector('[data-shop-activity-unit]')?.value ?? '',
        quantity: row.querySelector('[data-shop-activity-quantity]')?.value ?? '',
    }));
}

function prepRow(root, slug) {
    return root.querySelector(`[data-shop-prep="${slug}"]`);
}

function isPrepEnabled(root, slug) {
    return Boolean(prepRow(root, slug)?.querySelector('[data-shop-activity-toggle]')?.checked);
}

function fillPrepRow(row, quantity) {
    if (! row) {
        return;
    }

    const toggle = row.querySelector('[data-shop-activity-toggle]');
    if (toggle) {
        toggle.checked = true;
    }
    setOpen(row, true);

    const qty = row.querySelector('[data-shop-activity-quantity]');
    const unit = row.querySelector('[data-shop-activity-unit]');
    if (qty) {
        qty.value = quantity > 0.0001 ? formatShopQty(quantity) : '';
    }
    if (unit) {
        unit.value = 'm2';
    }
}

function hideLevelingPrompts(root) {
    root.querySelectorAll('[data-shop-leveling-prompt]').forEach((el) => {
        el.classList.add('hidden');
    });
}

function showLevelingPrompt(row) {
    const prompt = row?.querySelector('[data-shop-leveling-prompt]');
    if (! prompt) {
        return;
    }
    prompt.classList.remove('hidden');
}

export function bindShopActivities(root = document) {
    if (root == null || typeof root.querySelectorAll !== 'function') {
        return;
    }

    root.querySelectorAll('[data-shop-activities]').forEach((form) => bindShopActivitiesForm(form));
}

function bindShopActivitiesForm(root) {
    if (root.dataset.shopActivitiesBound === '1') {
        return;
    }
    root.dataset.shopActivitiesBound = '1';

    const rateInput = document.querySelector('#basis_uurtarief');
    const fallbackRate = parseShopAmount(root.dataset.shopHourlyRate) || 48;
    let levelingLinked = false;
    let levelingDeclined = false;
    let syncing = false;

    const updateCosts = () => {
        const rate = parseShopAmount(rateInput?.value) || fallbackRate;
        root.querySelectorAll('[data-shop-activity]').forEach((row) => {
            const checked = row.querySelector('[data-shop-activity-toggle]')?.checked;
            const hours = parseShopAmount(row.querySelector('[data-shop-activity-hours]')?.value);
            const quantity = parseShopAmount(row.querySelector('[data-shop-activity-quantity]')?.value);
            const unitSelect = row.querySelector('[data-shop-activity-unit]');
            const unit = unitSelect?.value;
            const out = row.querySelector('[data-shop-activity-cost]');
            if (! out) {
                return;
            }
            if (! checked || hours <= 0.0001) {
                out.textContent = '';

                return;
            }
            const total = hours * rate;
            const parts = [formatEuro(total)];
            if ((unit === 'm2' || unit === 'm1') && quantity > 0.0001) {
                const unitLabel = unitSelect?.selectedOptions?.[0]?.text ?? (unit === 'm2' ? 'm²' : 'm¹');
                parts.push(`${formatEuro(total / quantity)}/${unitLabel}`);
            }
            out.textContent = parts.join(' · ');
        });
    };

    const syncPrepFromFloors = () => {
        if (! levelingLinked) {
            return;
        }
        const quantity = floorCoveringSquareMeters(coveringSnapshot(root));
        syncing = true;
        fillPrepRow(prepRow(root, 'egaliseren'), quantity);
        fillPrepRow(prepRow(root, 'primen'), quantity);
        syncing = false;
        updateCosts();
    };

    const acceptLeveling = () => {
        levelingDeclined = false;
        levelingLinked = true;
        hideLevelingPrompts(root);
        syncPrepFromFloors();
    };

    const declineLeveling = () => {
        levelingDeclined = true;
        levelingLinked = false;
        hideLevelingPrompts(root);
    };

    const refreshPrompt = (row) => {
        hideLevelingPrompts(root);
        if (! row || ! shouldAskLeveling(
            Boolean(row.querySelector('[data-shop-activity-toggle]')?.checked),
            isPrepEnabled(root, 'egaliseren'),
            levelingDeclined,
        )) {
            return;
        }
        showLevelingPrompt(row);
    };

    root.querySelectorAll('[data-shop-activity-toggle]').forEach((input) => {
        const row = input.closest('[data-shop-activity]');
        if (row) {
            setOpen(row, input.checked);
        }
        input.addEventListener('change', () => {
            if (row) {
                setOpen(row, input.checked);
            }
            updateCosts();
            if (syncing) {
                return;
            }
            if (row?.hasAttribute('data-shop-prep') && row.dataset.shopPrep === 'egaliseren' && ! input.checked) {
                levelingLinked = false;
                hideLevelingPrompts(root);

                return;
            }
            if (row?.hasAttribute('data-shop-floor-covering')) {
                if (levelingLinked) {
                    syncPrepFromFloors();
                }
                refreshPrompt(row);
            }
        });
    });
    root.querySelectorAll('[data-shop-activity-hours], [data-shop-activity-quantity]').forEach((input) => {
        input.addEventListener('input', () => {
            updateCosts();
            if (syncing) {
                return;
            }
            const row = input.closest('[data-shop-floor-covering]');
            if (row && levelingLinked) {
                syncPrepFromFloors();
            }
        });
    });
    root.querySelectorAll('[data-shop-activity-unit]').forEach((input) => {
        input.addEventListener('change', () => {
            updateCosts();
            if (syncing) {
                return;
            }
            if (input.closest('[data-shop-floor-covering]') && levelingLinked) {
                syncPrepFromFloors();
            }
        });
    });
    root.querySelectorAll('[data-shop-leveling-yes]').forEach((button) => {
        button.addEventListener('click', acceptLeveling);
    });
    root.querySelectorAll('[data-shop-leveling-no]').forEach((button) => {
        button.addEventListener('click', declineLeveling);
    });
    rateInput?.addEventListener('input', updateCosts);
    updateCosts();

    if (isPrepEnabled(root, 'egaliseren')) {
        return;
    }
    const firstCovering = [...root.querySelectorAll('[data-shop-floor-covering]')].find((row) => (
        row.querySelector('[data-shop-activity-toggle]')?.checked
    ));
    if (firstCovering) {
        refreshPrompt(firstCovering);
    }
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bindShopActivities());
    } else {
        bindShopActivities();
    }
}

export {
    floorCoveringSquareMeters,
    formatShopQty,
    parseShopAmount,
    shouldAskLeveling,
};
