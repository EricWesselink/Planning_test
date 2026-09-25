export const WORKDAY_HOURS = 8;
export const SNAP_HOURS = 2;
export const DAY_START = '08:00';
export const DAY_END = '16:00';
export const SNAP_FRACTION = SNAP_HOURS / WORKDAY_HOURS;

export function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

export function snapHours(hours) {
    const snapped = Math.round(hours / SNAP_HOURS) * SNAP_HOURS;
    return clamp(snapped, SNAP_HOURS, WORKDAY_HOURS);
}

export function snapPosition(pos, max) {
    const snapped = Math.round(clamp(pos, 0, max) / SNAP_FRACTION) * SNAP_FRACTION;
    return Math.round(snapped * 1000) / 1000;
}

export function snapDelta(delta) {
    const snapped = Math.round(delta / SNAP_FRACTION) * SNAP_FRACTION;
    return Math.round(snapped * 1000) / 1000;
}

export const DAY_TIME_MARKS = ['08:00', '10:00', '12:00', '14:00', '16:00'];

/**
 * @param {number} hours
 * @returns {{start: string, end: string, label: string}[]}
 */
export function slotOptions(hours) {
    const snapped = snapHours(hours);
    if (snapped >= WORKDAY_HOURS) {
        return [{ start: DAY_START, end: DAY_END, label: `${DAY_START}–${DAY_END}` }];
    }
    if (snapped === 2) {
        return [
            { start: '08:00', end: '10:00', label: '08:00–10:00' },
            { start: '10:00', end: '12:00', label: '10:00–12:00' },
            { start: '12:00', end: '14:00', label: '12:00–14:00' },
            { start: '14:00', end: '16:00', label: '14:00–16:00' },
        ];
    }
    if (snapped === 6) {
        return [
            { start: '08:00', end: '14:00', label: '08:00–14:00' },
            { start: '10:00', end: '16:00', label: '10:00–16:00' },
        ];
    }
    return [
        { start: '08:00', end: '12:00', label: '08:00–12:00' },
        { start: '12:00', end: '16:00', label: '12:00–16:00' },
    ];
}

export function plannedHoursFromBox(box) {
    if (box.span === 1) {
        return snapHours((box.endOffset - box.startOffset) * WORKDAY_HOURS);
    }
    const first = (1 - box.startOffset) * WORKDAY_HOURS;
    const last = box.endOffset * WORKDAY_HOURS;
    const middle = Math.max(0, box.span - 2) * WORKDAY_HOURS;

    return first + middle + last;
}

export function intervalLabel(box) {
    const start = timeFromFraction(box.startOffset);
    const end = timeFromFraction(box.endOffset);
    return `${start} - ${end} · ${hoursLabel(plannedHoursFromBox(box))}`;
}

export function shiftBox(start, span, startOffset, endOffset, delta, dayCount) {
    const { startPos, endPos } = positionsFromBox(start, span, startOffset, endOffset);
    const duration = endPos - startPos;
    let nextStart = snapPosition(startPos + snapDelta(delta), dayCount);
    nextStart = clamp(nextStart, 0, Math.max(0, dayCount - duration));
    nextStart = snapPosition(nextStart, dayCount);
    if (nextStart + duration > dayCount + 1e-9) {
        nextStart = snapPosition(Math.max(0, dayCount - duration), dayCount);
    }

    return boxFromPositions(nextStart, nextStart + duration);
}

export function padTime(hours, minutes) {
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

export function timeFromFraction(fraction) {
    const minutes = Math.round(clamp(fraction, 0, 1) * WORKDAY_HOURS * 60);
    const startMinutes = 8 * 60 + minutes;
    return padTime(Math.floor(startMinutes / 60), startMinutes % 60);
}

export function fractionFromTime(time) {
    const [hours, minutes] = String(time || DAY_START).split(':').map(Number);
    const fromStart = ((hours || 8) * 60 + (minutes || 0) - 8 * 60) / 60;
    return clamp(fromStart / WORKDAY_HOURS, 0, 1);
}

export function timesFromHours(hours, slot = 'morning') {
    const snapped = snapHours(hours);
    if (snapped >= WORKDAY_HOURS || slot === 'full') {
        return { start: DAY_START, end: DAY_END, hours: WORKDAY_HOURS };
    }
    if (slot === 'afternoon') {
        const endMinutes = 16 * 60;
        const startMinutes = endMinutes - snapped * 60;
        return {
            start: padTime(Math.floor(startMinutes / 60), startMinutes % 60),
            end: DAY_END,
            hours: snapped,
        };
    }
    const endMinutes = 8 * 60 + snapped * 60;
    return {
        start: DAY_START,
        end: padTime(Math.floor(endMinutes / 60), endMinutes % 60),
        hours: snapped,
    };
}

export function hoursLabel(hours) {
    const rounded = Math.round(hours * 10) / 10;
    return Number.isInteger(rounded) ? `${rounded}u` : `${String(rounded).replace('.', ',')}u`;
}

export function boxFromPositions(startPos, endPos) {
    const startDay = Math.floor(startPos + 1e-9);
    const startOffset = Math.round((startPos - startDay) * 1000) / 1000;
    let endDay = Math.floor(endPos - 1e-9);
    let endOffset = Math.round((endPos - endDay) * 1000) / 1000;
    if (endOffset < 1e-9) {
        endOffset = 1;
        endDay -= 1;
    }
    endDay = Math.max(startDay, endDay);
    return {
        start: startDay,
        span: endDay - startDay + 1,
        startOffset: clamp(startOffset, 0, 1),
        endOffset: clamp(endOffset, 0, 1),
    };
}

export function positionsFromBox(start, span, startOffset, endOffset) {
    return {
        startPos: start + startOffset,
        endPos: start + span - (1 - endOffset),
    };
}

export function edgeHours(box, mode) {
    const duration = box.span === 1
        ? (box.endOffset - box.startOffset) * WORKDAY_HOURS
        : (mode === 'resize-start' ? (1 - box.startOffset) : box.endOffset) * WORKDAY_HOURS;
    return snapHours(duration);
}

export function barStyle(start, span, startOffset, endOffset, dayCount) {
    const left = start + startOffset;
    const width = Math.max(SNAP_FRACTION, span - startOffset - (1 - endOffset));
    return {
        left: `calc(${left} * 100% / ${dayCount} + 1px)`,
        width: `calc(${width} * 100% / ${dayCount} - 2px)`,
    };
}

function dateFromIso(iso) {
    return new Date(`${iso}T12:00:00`);
}

function isoFromDate(date) {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${month}-${day}`;
}

export function countsOnDate(iso, includeSaturday = false, includeSunday = false) {
    const weekday = dateFromIso(iso).getDay();
    if (weekday === 6) {
        return includeSaturday;
    }
    if (weekday === 0) {
        return includeSunday;
    }

    return true;
}

export function roundHours(value) {
    return Math.round(Number(value) * 10) / 10;
}

export function sliceHours(startOffset, endOffset) {
    return roundHours((Number(endOffset) - Number(startOffset)) * WORKDAY_HOURS);
}

function clock(value) {
    return String(value || "").slice(0, 5);
}

function shareOf(bar) {
    const share = Number(bar.shareHours);
    if (Number.isFinite(share) && share > 0) {
        return share;
    }

    return sliceHours(bar.startOffset ?? 0, bar.endOffset ?? 1);
}

/**
 * Payload for a mouse resize of one slice inside an 8-hour day.
 * The partner is the other work of the same assignment, or the one
 * assignment that touches this slice. A bar on another project, date
 * or visit is left alone.
 *
 * @param {object} drag
 * @returns {{work_hours: Record<string, number>}|{neighbor: {id: number, work_item_id: number|null, start_time: string, end_time: string}}|null}
 */
export function linkedDayResize(drag) {
    if (drag.internal || drag.mode === "move") {
        return null;
    }
    const oldShare = shareOf({
        shareHours: drag.shareHours,
        startOffset: drag.originStartOffset,
        endOffset: drag.originEndOffset,
    });
    const nextHours = sliceHours(drag.startOffset, drag.endOffset);
    const delta = roundHours(nextHours - oldShare);
    if (Math.abs(delta) < 0.05) {
        return null;
    }

    const sameVisit = (drag.bars || []).filter((bar) => (
        !bar.internal
        && String(bar.shiftId) === String(drag.shiftId)
        && bar.workItemId
        && String(bar.workItemId) !== String(drag.workItemId)
    ));
    const originStart = clock(drag.originStartTime);
    const originEnd = clock(drag.originEndTime);
    let partner = null;
    if (sameVisit.length === 1) {
        partner = sameVisit[0];
    } else if (sameVisit.length > 1) {
        partner = sameVisit.find((bar) => clock(bar.startTime) === originEnd || clock(bar.endTime) === originStart) || null;
    }
    if (partner) {
        return {
            work_hours: {
                [drag.workItemId]: nextHours,
                [partner.workItemId]: roundHours(shareOf(partner) - delta),
            },
        };
    }

    const sameDay = (drag.bars || []).filter((bar) => (
        !bar.internal
        && String(bar.shiftId) !== String(drag.shiftId)
        && String(bar.workerId) === String(drag.workerId)
        && String(bar.projectId) === String(drag.projectId)
        && bar.startDate === drag.startDate
        && bar.endDate === drag.endDate
    ));
    const touching = sameDay.filter((bar) => {
        const start = clock(bar.startTime);
        const end = clock(bar.endTime);
        return start === originEnd || end === originStart;
    });
    if (touching.length === 1) {
        const neighbor = touching[0];
        const neighborStart = clock(neighbor.startTime);
        const neighborEnd = clock(neighbor.endTime);
        return {
            neighbor: {
                id: Number(neighbor.shiftId),
                work_item_id: neighbor.workItemId ? Number(neighbor.workItemId) : null,
                start_time: neighborStart === originEnd ? drag.endTime : neighborStart,
                end_time: neighborEnd === originStart ? drag.startTime : neighborEnd,
            },
        };
    }
    if (touching.length === 0 && sameDay.length === 1) {
        const neighbor = sameDay[0];
        const dayStart = originStart < clock(neighbor.startTime) ? originStart : clock(neighbor.startTime);
        const dayEnd = originEnd > clock(neighbor.endTime) ? originEnd : clock(neighbor.endTime);
        return {
            neighbor: {
                id: Number(neighbor.shiftId),
                work_item_id: neighbor.workItemId ? Number(neighbor.workItemId) : null,
                start_time: drag.mode === "resize-start" ? dayStart : drag.endTime,
                end_time: drag.mode === "resize-start" ? drag.startTime : dayEnd,
            },
        };
    }

    return null;
}

export function workdayCount(startIso, endIso, includeSaturday = false, includeSunday = false) {
    if (!startIso || !endIso || startIso > endIso) {
        return 0;
    }

    let count = 0;
    const cursor = dateFromIso(startIso);
    const last = dateFromIso(endIso);
    while (cursor <= last) {
        const iso = isoFromDate(cursor);
        if (countsOnDate(iso, includeSaturday, includeSunday)) {
            count += 1;
        }
        cursor.setDate(cursor.getDate() + 1);
    }

    return count;
}

