/**
 * @typedef {{id: number, name: string, people_count?: number, selectable?: boolean, status_label?: string}} PlanWhoCandidate
 * @typedef {{value: string, label: string, peopleCount: number, selectable: boolean, disabled: boolean, selected: boolean}} PlanWhoOption
 */

export const WHO_LOADING_LABEL = 'Beschikbaarheid laden...';

/**
 * Leftover week numbers from a previous dialog must not replace the clicked
 * Van/Tot dates. Only copy the week range when the planner just switched from
 * week mode to exact dates.
 *
 * @param {boolean} applyWeekRange
 * @param {string|number|null|undefined} startWeek
 * @param {string|number|null|undefined} endWeek
 */
export function shouldOverwriteDatesFromWeeks(applyWeekRange, startWeek, endWeek) {
    return Boolean(applyWeekRange && startWeek && endWeek);
}

/**
 * Keep the clicked Van/Tot when reopening the modal. Only replace them with the
 * leftover ISO-week range when the planner just switched from week numbers to
 * exact dates.
 *
 * @param {{startDate?: string, endDate?: string, applyWeekRange?: boolean, weekStartDate?: string, weekEndDate?: string}} input
 * @returns {{startDate: string, endDate: string}}
 */
export function datesForExactMode({
    startDate = '',
    endDate = '',
    applyWeekRange = false,
    weekStartDate = '',
    weekEndDate = '',
} = {}) {
    if (shouldOverwriteDatesFromWeeks(applyWeekRange, weekStartDate, weekEndDate)) {
        return { startDate: weekStartDate, endDate: weekEndDate };
    }

    return { startDate, endDate };
}

/**
 * @param {number} requestId
 * @param {number} latestId
 */
export function isLatestCandidatesRequest(requestId, latestId) {
    return requestId === latestId;
}

/**
 * @param {AbortSignal} [signal]
 * @returns {RequestInit}
 */
export function candidatesFetchInit(signal) {
    return {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        signal,
    };
}

/**
 * @returns {PlanWhoOption[]}
 */
export function whoLoadingOptionList() {
    return [{
        value: '',
        label: WHO_LOADING_LABEL,
        peopleCount: 0,
        selectable: false,
        disabled: false,
        selected: true,
    }];
}

/**
 * Build Wie-dropdown options for active planning candidates.
 *
 * A new planning only lists people who are suitable and free. The current
 * choice stays visible when editing, even if that person is now busy. Do not
 * map `selectable` to HTML `disabled`: native select popups ignore option
 * colors and paint those rows as unreadable GrayText.
 *
 * @param {PlanWhoCandidate[]} candidates
 * @param {string} selectedValue
 * @param {string} placeholderLabel
 * @returns {PlanWhoOption[]}
 */
export function whoOptionList(candidates, selectedValue = '', placeholderLabel = 'Kies vakman of team') {
    const current = String(selectedValue ?? '');
    const visible = candidates.filter((candidate) => {
        if (candidate.selectable) {
            return true;
        }

        return current === `worker:${candidate.id}`;
    });

    return [
        {
            value: '',
            label: placeholderLabel,
            peopleCount: 0,
            selectable: false,
            disabled: false,
            selected: current === '',
        },
        ...visible.map((candidate) => {
            const value = `worker:${candidate.id}`;

            return {
                value,
                label: `${candidate.name} — ${candidate.status_label ?? ''}`,
                peopleCount: Number(candidate.people_count || 1),
                selectable: Boolean(candidate.selectable),
                disabled: false,
                selected: value === current,
            };
        }),
    ];
}
