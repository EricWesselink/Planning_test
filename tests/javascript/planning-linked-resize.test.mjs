import assert from 'node:assert/strict';
import test from 'node:test';
import { linkedDayResize } from '../../resources/js/planning-hours.js';

function drag(overrides = {}) {
    return {
        mode: 'resize-end',
        internal: false,
        shiftId: '10',
        workerId: '3',
        projectId: '7',
        workItemId: '101',
        shareHours: '4',
        startDate: '2026-09-21',
        endDate: '2026-09-21',
        originStartOffset: 0,
        originEndOffset: 1,
        startOffset: 0,
        endOffset: 0.75,
        startTime: '08:00',
        endTime: '14:00',
        originStartTime: '08:00',
        originEndTime: '16:00',
        bars: [],
        ...overrides,
    };
}

test('a full-width 4u slice resized to 6u shrinks the other work of the same visit to 2u', () => {
    const payload = linkedDayResize(drag({
        bars: [
            { shiftId: '10', workItemId: '101', shareHours: '4', startOffset: 0, endOffset: 1, internal: false },
            { shiftId: '10', workItemId: '202', shareHours: '4', startOffset: 0, endOffset: 1, internal: false, workerId: '3', projectId: '7', startDate: '2026-09-21', endDate: '2026-09-21', startTime: '08:00', endTime: '16:00' },
        ],
    }));

    assert.deepEqual(payload, {
        work_hours: { 101: 6, 202: 2 },
    });
});

test('two overlapping assignments on the same day move the shared boundary', () => {
    const payload = linkedDayResize(drag({
        shareHours: '',
        originStartOffset: 0,
        originEndOffset: 1,
        bars: [
            {
                shiftId: '55',
                workerId: '3',
                projectId: '7',
                workItemId: '202',
                startDate: '2026-09-21',
                endDate: '2026-09-21',
                startTime: '08:00',
                endTime: '16:00',
                internal: false,
            },
        ],
    }));

    assert.deepEqual(payload, {
        neighbor: {
            id: 55,
            work_item_id: 202,
            start_time: '14:00',
            end_time: '16:00',
        },
    });
});

test('a third visit on the same project is not pulled into the resize', () => {
    const payload = linkedDayResize(drag({
        shareHours: '',
        bars: [
            { shiftId: '55', workerId: '3', projectId: '7', workItemId: '202', startDate: '2026-09-21', endDate: '2026-09-21', startTime: '08:00', endTime: '16:00', internal: false },
            { shiftId: '56', workerId: '3', projectId: '7', workItemId: '303', startDate: '2026-09-21', endDate: '2026-09-21', startTime: '08:00', endTime: '12:00', internal: false },
        ],
    }));

    assert.equal(payload, null);
});

test('another project on the same day stays untouched', () => {
    const payload = linkedDayResize(drag({
        shareHours: '',
        bars: [
            { shiftId: '55', workerId: '3', projectId: '8', workItemId: '202', startDate: '2026-09-21', endDate: '2026-09-21', startTime: '08:00', endTime: '16:00', internal: false },
        ],
    }));

    assert.equal(payload, null);
});

test('a contiguous 4u plus 4u boundary moves from 12:00 to 14:00', () => {
    const payload = linkedDayResize(drag({
        shareHours: '',
        originStartOffset: 0,
        originEndOffset: 0.5,
        startOffset: 0,
        endOffset: 0.75,
        originStartTime: '08:00',
        originEndTime: '12:00',
        bars: [
            { shiftId: '55', workerId: '3', projectId: '7', workItemId: '202', startDate: '2026-09-21', endDate: '2026-09-21', startTime: '12:00', endTime: '16:00', internal: false },
        ],
    }));

    assert.deepEqual(payload.neighbor, {
        id: 55,
        work_item_id: 202,
        start_time: '14:00',
        end_time: '16:00',
    });
});
