import { isoDateFromWeek, isoWeekFromDate } from './planning-weeks.js';

const MONTH_NAMES = [
    'januari', 'februari', 'maart', 'april', 'mei', 'juni',
    'juli', 'augustus', 'september', 'oktober', 'november', 'december',
];
const WEEKDAY_LABELS = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];

export function isoWeeksInYear(year) {
    const parts = isoWeekFromDate(`${Number(year)}-12-28`);

    return parts?.week ?? 52;
}

export function workdaysForIsoWeek(year, week) {
    const isoYear = Number(year);
    const isoWeek = Number(week);
    if (! Number.isInteger(isoYear) || ! Number.isInteger(isoWeek) || isoWeek < 1 || isoWeek > isoWeeksInYear(isoYear)) {
        return null;
    }

    const start = isoDateFromWeek(isoYear, isoWeek, 1);
    const end = isoDateFromWeek(isoYear, isoWeek, 5);
    if (! start || ! end) {
        return null;
    }

    return { start, end, year: isoYear, week: isoWeek };
}

/**
 * @returns {{year: number, week: number, days: {date: string, inMonth: boolean, weekday: number}[]}[]}
 */
export function calendarWeeks(year, monthIndex) {
    const month = Number(monthIndex);
    const first = new Date(Date.UTC(Number(year), month, 1));
    if (Number.isNaN(first.getTime())) {
        return [];
    }

    const weekday = first.getUTCDay() || 7;
    const cursor = new Date(first);
    cursor.setUTCDate(1 - (weekday - 1));
    const weeks = [];

    for (let row = 0; row < 6; row += 1) {
        const days = [];
        for (let day = 0; day < 7; day += 1) {
            const iso = `${cursor.getUTCFullYear()}-${pad(cursor.getUTCMonth() + 1)}-${pad(cursor.getUTCDate())}`;
            days.push({
                date: iso,
                inMonth: cursor.getUTCMonth() === month,
                weekday: day + 1,
            });
            cursor.setUTCDate(cursor.getUTCDate() + 1);
        }
        const weekInfo = isoWeekFromDate(days[0].date);
        weeks.push({
            year: weekInfo?.year ?? Number(year),
            week: weekInfo?.week ?? 0,
            days,
        });
        if (cursor.getUTCMonth() !== month && (cursor.getUTCDay() || 7) === 1) {
            break;
        }
    }

    return weeks;
}

export function applyIsoWeekToRange(startInput, endInput, year, week) {
    const range = workdaysForIsoWeek(year, week);
    if (! range || ! startInput || ! endInput) {
        return null;
    }

    setDateValue(startInput, range.start);
    setDateValue(endInput, range.end);

    return range;
}

export function bindPlanningDatePickers(startInput, endInput, { onChange, document: doc = globalThis.document } = {}) {
    if (! (startInput instanceof HTMLInputElement) || ! (endInput instanceof HTMLInputElement) || ! doc) {
        return () => {};
    }

    const calendar = doc.createElement('div');
    calendar.className = 'plan-calendar hidden';
    calendar.hidden = true;
    calendar.setAttribute('role', 'dialog');
    calendar.setAttribute('aria-label', 'Kalender');
    const host = startInput.closest('dialog') || startInput.parentElement || doc.body;
    host.append(calendar);

    let viewYear = new Date().getFullYear();
    let viewMonth = new Date().getMonth();
    let activeInput = startInput;

    const close = () => {
        calendar.classList.add('hidden');
        calendar.hidden = true;
    };

    const emitChange = (input) => {
        input.dispatchEvent(new Event('change', { bubbles: true }));
        onChange?.();
    };

    const open = (input) => {
        activeInput = input;
        const current = parseIsoDate(input.value) || parseIsoDate(startInput.value) || new Date();
        viewYear = current.getUTCFullYear();
        viewMonth = current.getUTCMonth();
        render();
        place(input);
        calendar.classList.remove('hidden');
        calendar.hidden = false;
    };

    const place = (input) => {
        const rect = input.getBoundingClientRect();
        calendar.style.position = 'fixed';
        calendar.style.left = `${Math.max(8, rect.left)}px`;
        calendar.style.top = `${rect.bottom + 4}px`;
        calendar.style.zIndex = '80';
    };

    const render = () => {
        const weeks = calendarWeeks(viewYear, viewMonth);
        const selected = new Set([startInput.value, endInput.value].filter(Boolean));
        calendar.innerHTML = `
            <div class="plan-calendar-head">
                <button type="button" class="plan-calendar-nav" data-cal-nav="-1" aria-label="Vorige maand">‹</button>
                <div class="plan-calendar-title">${MONTH_NAMES[viewMonth]} ${viewYear}</div>
                <button type="button" class="plan-calendar-nav" data-cal-nav="1" aria-label="Volgende maand">›</button>
            </div>
            <div class="plan-calendar-grid">
                <span class="plan-calendar-week-head">Wk</span>
                ${WEEKDAY_LABELS.map((label) => `<span class="plan-calendar-day-head">${label}</span>`).join('')}
                ${weeks.map((week) => `
                    <button type="button" class="plan-calendar-week" data-cal-week="${week.week}" data-cal-week-year="${week.year}" title="Selecteer maandag t/m vrijdag van week ${week.week}">WK ${week.week}</button>
                    ${week.days.map((day) => {
                        const selectedClass = selected.has(day.date) ? ' is-selected' : '';
                        const mutedClass = day.inMonth ? '' : ' is-muted';
                        return `<button type="button" class="plan-calendar-day${selectedClass}${mutedClass}" data-cal-date="${day.date}">${Number(day.date.slice(-2))}</button>`;
                    }).join('')}
                `).join('')}
            </div>
        `;
    };

    calendar.addEventListener('mousedown', (event) => event.preventDefault());
    calendar.addEventListener('click', (event) => {
        const nav = event.target.closest('[data-cal-nav]');
        if (nav) {
            const next = new Date(Date.UTC(viewYear, viewMonth + Number(nav.dataset.calNav), 1));
            viewYear = next.getUTCFullYear();
            viewMonth = next.getUTCMonth();
            render();
            return;
        }
        const weekBtn = event.target.closest('[data-cal-week]');
        if (weekBtn) {
            applyIsoWeekToRange(startInput, endInput, Number(weekBtn.dataset.calWeekYear), Number(weekBtn.dataset.calWeek));
            emitChange(startInput);
            emitChange(endInput);
            close();
            return;
        }
        const dayBtn = event.target.closest('[data-cal-date]');
        if (! dayBtn) {
            return;
        }
        const date = dayBtn.dataset.calDate;
        setDateValue(activeInput, date);
        if (startInput.value && endInput.value && startInput.value > endInput.value) {
            if (activeInput === startInput) {
                setDateValue(endInput, date);
                emitChange(endInput);
            } else {
                setDateValue(startInput, date);
                emitChange(startInput);
            }
        }
        emitChange(activeInput);
        close();
    });

    const onPick = (event) => {
        event.preventDefault();
        open(event.currentTarget);
    };

    startInput.addEventListener('mousedown', onPick);
    endInput.addEventListener('mousedown', onPick);
    startInput.addEventListener('click', onPick);
    endInput.addEventListener('click', onPick);
    startInput.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'Enter') {
            event.preventDefault();
            open(startInput);
        }
    });
    endInput.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'Enter') {
            event.preventDefault();
            open(endInput);
        }
    });

    const onDoc = (event) => {
        if (calendar.hidden) {
            return;
        }
        if (calendar.contains(event.target) || event.target === startInput || event.target === endInput) {
            return;
        }
        close();
    };
    doc.addEventListener('mousedown', onDoc);

    return () => {
        startInput.removeEventListener('mousedown', onPick);
        endInput.removeEventListener('mousedown', onPick);
        doc.removeEventListener('mousedown', onDoc);
        calendar.remove();
    };
}

function pad(value) {
    return String(value).padStart(2, '0');
}

function parseIsoDate(value) {
    if (! value) {
        return null;
    }
    const date = new Date(`${value}T00:00:00Z`);

    return Number.isNaN(date.getTime()) ? null : date;
}

function setDateValue(input, value) {
    input.value = value;
}
