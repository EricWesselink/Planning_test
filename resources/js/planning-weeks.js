const pad = (value) => String(value).padStart(2, '0');

/**
 * @param {string} value
 * @returns {{year: number, week: number, weekday: number}|null}
 */
export function isoWeekFromDate(value) {
    if (! value) {
        return null;
    }

    const date = new Date(`${value}T00:00:00Z`);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const utc = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()));
    const weekday = utc.getUTCDay() || 7;
    utc.setUTCDate(utc.getUTCDate() + 4 - weekday);
    const year = utc.getUTCFullYear();
    const yearStart = new Date(Date.UTC(year, 0, 1));
    const week = Math.ceil((((utc - yearStart) / 86400000) + 1) / 7);

    return { year, week, weekday };
}

/**
 * @param {number} year
 * @param {number} week
 * @param {number} weekday
 * @returns {string|null}
 */
export function isoDateFromWeek(year, week, weekday = 1) {
    const isoYear = Number(year);
    const isoWeek = Number(week);
    const isoWeekday = Number(weekday);

    if (! Number.isInteger(isoYear) || isoYear < 2000 || isoYear > 2100 || ! Number.isInteger(isoWeek) || isoWeek < 1 || isoWeek > 53) {
        return null;
    }

    if (! Number.isInteger(isoWeekday) || isoWeekday < 1 || isoWeekday > 7) {
        return null;
    }

    const jan4 = new Date(Date.UTC(isoYear, 0, 4));
    const jan4Day = jan4.getUTCDay() || 7;
    const date = new Date(jan4);
    date.setUTCDate(jan4.getUTCDate() - jan4Day + 1 + ((isoWeek - 1) * 7) + (isoWeekday - 1));

    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

function bindGroup(group) {
    const weekday = Number(group.dataset.weekday || 1);
    const dateInput = group.querySelector('[data-planning-date]');
    const yearInput = group.querySelector('[data-planning-year]');
    const weekInput = group.querySelector('[data-planning-week-number]');

    if (! (dateInput instanceof HTMLInputElement) || ! (yearInput instanceof HTMLInputElement) || ! (weekInput instanceof HTMLInputElement)) {
        return;
    }

    const syncFromDate = () => {
        const parts = isoWeekFromDate(dateInput.value);
        if (! parts) {
            return;
        }

        yearInput.value = String(parts.year);
        weekInput.value = String(parts.week);
    };

    const syncFromWeek = () => {
        const next = isoDateFromWeek(yearInput.value, weekInput.value, weekday);
        if (! next) {
            return;
        }

        dateInput.value = next;
    };

    dateInput.addEventListener('change', syncFromDate);
    dateInput.addEventListener('input', syncFromDate);
    yearInput.addEventListener('change', syncFromWeek);
    yearInput.addEventListener('input', syncFromWeek);
    weekInput.addEventListener('change', syncFromWeek);
    weekInput.addEventListener('input', syncFromWeek);
}

export function bindPlanningWeeks(root = document) {
    if (root == null || typeof root.querySelectorAll !== 'function') {
        return;
    }

    root.querySelectorAll('[data-planning-week]').forEach(bindGroup);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bindPlanningWeeks());
    } else {
        bindPlanningWeeks();
    }
}
