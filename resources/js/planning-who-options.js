/**
 * @typedef {{id: number, name: string, people_count?: number, selectable?: boolean, status_label?: string}} PlanWhoCandidate
 * @typedef {{value: string, label: string, peopleCount: number, selectable: boolean, disabled: boolean, selected: boolean}} PlanWhoOption
 */

/**
 * Build Wie-dropdown options for active planning candidates.
 *
 * Candidates already exclude inactive workers. `selectable` is fit information
 * (skill / availability) and must not map to HTML `disabled`: native select
 * popups ignore option colors and paint those rows as unreadable GrayText.
 *
 * @param {PlanWhoCandidate[]} candidates
 * @param {string} selectedValue
 * @param {string} placeholderLabel
 * @returns {PlanWhoOption[]}
 */
export function whoOptionList(candidates, selectedValue = '', placeholderLabel = 'Kies vakman of team') {
    const current = String(selectedValue ?? '');

    return [
        {
            value: '',
            label: placeholderLabel,
            peopleCount: 0,
            selectable: false,
            disabled: false,
            selected: current === '',
        },
        ...candidates.map((candidate) => {
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
