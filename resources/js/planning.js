import {
    SNAP_FRACTION,
    WORKDAY_HOURS,
    barStyle,
    boxFromPositions,
    hoursLabel,
    intervalLabel,
    positionsFromBox,
    shiftBox,
    slotOptions,
    snapPosition,
    timeFromFraction,
    timesFromHours,
} from './planning-hours';

const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
const board = document.getElementById('plan-board');
if (board) {
    const readonly = board.dataset.readonly === '1';
    const dialog = document.getElementById('plan-dialog');
    const form = document.getElementById('plan-form');
    const whoSelect = document.getElementById('plan-who');
    const workSelect = document.getElementById('plan-work');
    const startInput = document.getElementById('plan-start');
    const endInput = document.getElementById('plan-end');
    const menInput = document.getElementById('plan-men');
    const menWrap = document.getElementById('plan-men-wrap');
    const crewBox = document.getElementById('plan-crew');
    const crewList = document.getElementById('plan-crew-list');
    const hoursSelect = document.getElementById('plan-hours');
    const slotWrap = document.getElementById('plan-slot-wrap');
    const slotList = document.getElementById('plan-slot-list');
    const hoursSummary = document.getElementById('plan-hours-summary');
    const hoursHint = document.getElementById('plan-hours-hint');
    const projectInput = document.getElementById('plan-project-id');
    const titleEl = document.getElementById('plan-dialog-title');
    const deleteBtn = document.getElementById('plan-delete');
    const workItems = JSON.parse(board.dataset.workItems || '{}');
    const crews = JSON.parse(board.dataset.crews || '{}');
    const candidatesUrl = board.dataset.candidatesUrl || '';
    const dates = [...board.querySelectorAll('.plan-line--head [data-date]')].map((el) => el.dataset.date);
    let lastCandidates = [];
    let candidatesAbort = null;
    const dayCount = dates.length;
    const dayWidth = () => (board.querySelector('.plan-day')?.getBoundingClientRect().width || 132);

    async function request(url, method, body) {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
                'X-HTTP-Method-Override': method,
            },
            body: JSON.stringify(body),
        });
        const json = await response.json().catch(() => ({}));
        return { response, json };
    }

    async function save(url, method, body) {
        const send = async (confirmConflict = false) => {
            const { response, json } = await request(url, method, { ...body, confirm_conflict: confirmConflict });
            if (response.status === 409 && json.conflict) {
                if (window.confirm(json.message)) {
                    return send(true);
                }
                return false;
            }
            if (!response.ok) {
                const firstError = json.errors ? Object.values(json.errors).flat()[0] : null;
                window.alert(firstError || json.message || 'Opslaan is niet gelukt.');
                return false;
            }
            return true;
        };

        if (await send(false)) {
            return true;
        }
        return false;
    }

    function assignmentUrl(id) {
        return `${board.dataset.assignmentUrl.replace(/\/$/, '')}/${id}`;
    }

    function addDays(iso, days) {
        const date = new Date(`${iso}T12:00:00`);
        date.setDate(date.getDate() + days);
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${date.getFullYear()}-${month}-${day}`;
    }

    function applyBarBox(bar, start, span, startOffset = 0, endOffset = 1) {
        bar.dataset.start = String(start);
        bar.dataset.span = String(span);
        bar.dataset.startOffset = String(startOffset);
        bar.dataset.endOffset = String(endOffset);
        const style = barStyle(start, span, startOffset, endOffset, dayCount);
        bar.style.left = style.left;
        bar.style.width = style.width;
        const startTime = timeFromFraction(startOffset);
        const endTime = timeFromFraction(endOffset);
        bar.dataset.startTime = startTime;
        bar.dataset.endTime = endTime;
    }

    function fillWorkItems(projectId, selectedId) {
        const items = workItems[projectId] || workItems[String(projectId)] || [];
        const grouped = {};
        items.forEach((item) => {
            const key = item.group || '';
            grouped[key] = grouped[key] || [];
            grouped[key].push(item);
        });
        workSelect.innerHTML = Object.entries(grouped).map(([label, rows]) => {
            const options = rows.map((item) => `<option value="${item.id}">${item.name}</option>`).join('');
            if (!label || rows.length === 1) {
                return options;
            }
            return `<optgroup label="${label}">${options}</optgroup>`;
        }).join('');
        if (!items.length) {
            workSelect.innerHTML = '<option value="">Geen werkzaamheden</option>';
        }
        if (selectedId) {
            workSelect.value = String(selectedId);
        }
    }

    function workerCrew(workerId) {
        return crews[workerId] || crews[String(workerId)] || [];
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }

    function candidateByWorkerId(workerId) {
        return lastCandidates.find((row) => String(row.id) === String(workerId)) || null;
    }

    function crewFit(workerId, personId) {
        const candidate = candidateByWorkerId(workerId);
        if (!candidate?.crew) {
            return null;
        }

        return candidate.crew.find((row) => Number(row.id) === Number(personId)) || null;
    }

    function renderWhoOptions(preserveValue) {
        const current = preserveValue || whoSelect.value;
        const options = lastCandidates.map((candidate) => {
            const value = `worker:${candidate.id}`;
            const disabled = candidate.selectable ? '' : ' disabled';
            const selected = value === current && candidate.selectable ? ' selected' : '';

            return `<option value="${value}" data-men="${candidate.people_count}" data-selectable="${candidate.selectable ? '1' : '0'}"${disabled}${selected}>${escapeHtml(`${candidate.name} — ${candidate.status_label}`)}</option>`;
        }).join('');
        whoSelect.innerHTML = `<option value="">Kies team</option>${options}`;
        const stillValid = current
            && whoSelect.querySelector(`option[value="${CSS.escape(current)}"]:not(:disabled)`);
        whoSelect.value = stillValid ? current : '';
    }

    async function refreshCandidates() {
        if (!candidatesUrl || !workSelect.value || !startInput.value || !endInput.value) {
            return;
        }
        const times = selectedTimes();
        const params = new URLSearchParams({
            work_item_id: workSelect.value,
            start_date: startInput.value,
            end_date: endInput.value,
            start_time: times.start,
            end_time: times.end,
            hours: String(times.hours),
            slot: times.hours >= WORKDAY_HOURS ? 'full' : selectedSlot(),
        });
        if (form.dataset.assignmentId) {
            params.set('assignment_id', form.dataset.assignmentId);
        }
        if (candidatesAbort) {
            candidatesAbort.abort();
        }
        candidatesAbort = new AbortController();
        try {
            const response = await fetch(`${candidatesUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                signal: candidatesAbort.signal,
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                return;
            }
            lastCandidates = json.workers || [];
            renderWhoOptions(whoSelect.value);
            const workerId = (whoSelect.value || '').split(':')[1];
            if (workerId && workerCrew(workerId).length >= 2) {
                renderCrew(workerId, selectedCrewIds(), selectedCrewHours());
            } else if (whoSelect.value) {
                syncMenFromWho();
            }
        } catch (error) {
            if (error?.name !== 'AbortError') {
                throw error;
            }
        }
    }

    function selectedCrewIds() {
        return [...crewList.querySelectorAll('input[type="checkbox"]:checked')].map((input) => Number(input.value));
    }

    function selectedSlotInput() {
        return slotList?.querySelector('input[name="slot"]:checked') || null;
    }

    function selectedSlot() {
        const value = selectedSlotInput()?.value || 'morning';
        if (value === 'afternoon' || value === 'full') {
            return value;
        }
        return 'morning';
    }

    function selectedHours() {
        return Number(hoursSelect?.value || WORKDAY_HOURS);
    }

    function selectedTimes() {
        const hours = selectedHours();
        if (hours >= WORKDAY_HOURS) {
            return timesFromHours(WORKDAY_HOURS, 'full');
        }
        const chosen = selectedSlotInput();
        if (chosen?.dataset.start && chosen?.dataset.end) {
            return {
                start: chosen.dataset.start,
                end: chosen.dataset.end,
                hours,
            };
        }
        return timesFromHours(hours, selectedSlot());
    }

    function renderSlotOptions(hours, preferredStart = '08:00') {
        if (!slotList) {
            return;
        }
        const options = slotOptions(hours);
        const want = String(preferredStart || '08:00').slice(0, 5);
        let matched = options.findIndex((option) => option.start === want);
        if (matched < 0) {
            matched = 0;
        }
            slotList.innerHTML = options.map((option, index) => {
            const id = `plan-slot-${option.start.replace(':', '')}`;
            const checked = index === matched ? ' checked' : '';
            const slotValue = option.start >= '12:00' ? 'afternoon' : 'morning';
            return `<label class="flex items-center gap-1.5"><input type="radio" name="slot" id="${id}" value="${slotValue}" data-start="${option.start}" data-end="${option.end}"${checked}> ${option.label}</label>`;
        }).join('');
        slotList.querySelectorAll('input[name="slot"]').forEach((input) => {
            input.addEventListener('change', () => {
                syncHoursSummary();
                refreshCandidates();
            });
        });
    }

    function selectedCrewHours() {
        const hours = {};
        crewList.querySelectorAll('select[data-crew-hours]').forEach((select) => {
            const id = Number(select.dataset.crewHours);
            if (id > 0) {
                hours[id] = Number(select.value);
            }
        });
        return hours;
    }

    function syncHoursSummary() {
        const times = selectedTimes();
        if (hoursSummary) {
            hoursSummary.textContent = `${times.start}–${times.end} · ${hoursLabel(times.hours)}`;
        }
        if (slotWrap) {
            slotWrap.classList.toggle('hidden', selectedHours() >= WORKDAY_HOURS);
        }
    }

    function setHoursUi(hours, startTime) {
        const value = String([2, 4, 6, 8].includes(Number(hours)) ? Number(hours) : WORKDAY_HOURS);
        hoursSelect.value = value;
        renderSlotOptions(Number(value), startTime);
        syncHoursSummary();
    }

    function renderCrew(workerId, selectedIds = [], hoursById = {}) {
        const people = workerCrew(workerId);
        const selected = new Set((selectedIds || []).map((id) => Number(id)).filter((id) => id > 0));
        crewList.innerHTML = '';
        if (people.length < 2) {
            crewBox.classList.add('hidden');
            menWrap.classList.remove('hidden');
            menInput.readOnly = false;
            return;
        }

        people.forEach((person) => {
            const fit = crewFit(workerId, person.id);
            const blocked = fit ? !fit.selectable : false;
            const row = document.createElement('label');
            row.className = 'plan-crew-row flex items-center justify-between gap-2 text-sm leading-tight';
            if (blocked) {
                row.classList.add('plan-crew-row--blocked');
            }
            const left = document.createElement('span');
            left.className = 'flex items-center gap-2 min-w-0';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = String(person.id);
            input.disabled = blocked;
            input.checked = !blocked && selected.has(Number(person.id));
            input.addEventListener('change', syncMenFromCrew);
            const text = document.createElement('span');
            text.className = 'min-w-0 truncate';
            text.textContent = person.name;
            left.append(input, text);
            const right = document.createElement('span');
            right.className = 'flex items-center gap-2 shrink-0';
            if (fit?.status_label) {
                const status = document.createElement('span');
                status.className = 'text-xs text-nicon-muted';
                status.textContent = fit.status_label;
                right.append(status);
            }
            const hours = document.createElement('select');
            hours.dataset.crewHours = String(person.id);
            hours.className = 'border border-nicon-line bg-white px-1 py-0.5 text-sm';
            hours.disabled = blocked;
            [2, 4, 6, 8].forEach((value) => {
                const option = document.createElement('option');
                option.value = String(value);
                option.textContent = `${value}u`;
                hours.append(option);
            });
            hours.value = String(hoursById[person.id] || selectedHours());
            right.append(hours);
            row.append(left, right);
            crewList.append(row);
        });
        crewBox.classList.remove('hidden');
        menWrap.classList.add('hidden');
        menInput.readOnly = true;
        syncMenFromCrew();
    }

    function syncMenFromCrew() {
        const people = workerCrew((whoSelect.value || '').split(':')[1]);
        if (people.length < 2) {
            return;
        }
        const count = Math.max(1, selectedCrewIds().length || 0);
        menInput.value = String(count);
    }

    function syncMenFromWho() {
        const option = whoSelect.selectedOptions[0];
        const isTeam = (whoSelect.value || '').startsWith('team:');
        const workerId = (whoSelect.value || '').split(':')[1];
        const people = isTeam ? [] : workerCrew(workerId);
        if (people.length >= 2) {
            renderCrew(workerId, []);
            return;
        }
        crewBox.classList.add('hidden');
        crewList.innerHTML = '';
        menWrap.classList.remove('hidden');
        const men = Number(option?.dataset.men || 1);
        menInput.value = String(Math.max(1, men));
        menInput.readOnly = isTeam;
    }

    function openAdd(projectId, workItemId, date, startTime = '08:00', hours = WORKDAY_HOURS) {
        form.dataset.assignmentId = '';
        titleEl.textContent = 'Iemand inplannen';
        deleteBtn.classList.add('hidden');
        whoSelect.value = '';
        whoSelect.disabled = false;
        projectInput.value = projectId;
        fillWorkItems(projectId, workItemId);
        whoSelect.querySelectorAll('option[value^="team:"]').forEach((option) => {
            option.hidden = false;
        });
        startInput.value = date;
        endInput.value = date;
        menInput.value = '1';
        menInput.readOnly = false;
        crewBox.classList.add('hidden');
        crewList.innerHTML = '';
        menWrap.classList.remove('hidden');
        setHoursUi(hours, startTime);
        dialog.showModal();
        whoSelect.focus();
        refreshCandidates();
    }

    function openEdit(bar) {
        form.dataset.assignmentId = bar.dataset.shiftId;
        titleEl.textContent = 'Inzet aanpassen';
        deleteBtn.classList.remove('hidden');
        whoSelect.disabled = false;
        whoSelect.querySelectorAll('option[value^="team:"]').forEach((option) => {
            option.hidden = true;
        });
        whoSelect.value = `worker:${bar.dataset.workerId}`;
        projectInput.value = bar.dataset.projectId;
        fillWorkItems(bar.dataset.projectId, bar.dataset.workItemId);
        startInput.value = bar.dataset.startDate;
        endInput.value = bar.dataset.endDate;
        menInput.value = String(Math.max(1, Number(bar.dataset.peopleCount || 1)));
        const selectedIds = (bar.dataset.crewIds || '')
            .split(',')
            .map((id) => Number(id))
            .filter((id) => id > 0);
        const planned = Number(bar.dataset.plannedHours || WORKDAY_HOURS);
        const dayHours = bar.dataset.startDate === bar.dataset.endDate
            ? planned
            : WORKDAY_HOURS;
        setHoursUi(dayHours, bar.dataset.startTime);
        const hoursById = {};
        selectedIds.forEach((id) => {
            hoursById[id] = dayHours;
        });
        renderCrew(bar.dataset.workerId, selectedIds, hoursById);
        if (workerCrew(bar.dataset.workerId).length < 2) {
            menInput.readOnly = false;
        }
        dialog.showModal();
        refreshCandidates();
    }

    let drag = null;
    let justDragged = false;

    function clearDropHighlight() {
        board.querySelectorAll('.plan-line--drop').forEach((line) => line.classList.remove('plan-line--drop'));
    }

    function hideHoursHint() {
        hoursHint?.classList.add('hidden');
    }

    function showHoursHint(clientX, clientY, label) {
        if (!hoursHint) {
            return;
        }
        hoursHint.textContent = label;
        hoursHint.style.left = `${clientX}px`;
        hoursHint.style.top = `${clientY}px`;
        hoursHint.classList.remove('hidden');
    }

    function restoreBar(current) {
        applyBarBox(current.bar, current.originStart, current.originSpan, current.originStartOffset, current.originEndOffset);
        current.bar.dataset.workItemId = current.originWorkItemId;
        current.bar.dataset.startDate = current.startDate;
        current.bar.dataset.endDate = current.endDate;
        if (current.originStack) {
            current.originStack.appendChild(current.bar);
            current.bar.style.top = current.originTop;
        }
    }

    function placeBarInStack(bar, stack) {
        if (bar.parentElement !== stack) {
            stack.appendChild(bar);
        }
        const others = [...stack.querySelectorAll('.person-bar')].filter((el) => el !== bar).length;
        bar.style.top = `${4 + (others * 24)}px`;
        bar.dataset.workItemId = stack.dataset.workItemId || '';
    }

    function stackAt(clientX, clientY, bar) {
        bar.style.pointerEvents = 'none';
        const el = document.elementFromPoint(clientX, clientY);
        bar.style.pointerEvents = '';
        const workLine = el?.closest('.plan-line--work');
        const stack = workLine?.querySelector('.person-stack') || el?.closest('.person-stack');
        if (!stack?.dataset.workItemId) {
            return null;
        }
        if (stack.dataset.projectId !== bar.dataset.projectId) {
            return null;
        }
        return stack;
    }

    function previewDropTarget(clientX, clientY) {
        const stack = stackAt(clientX, clientY, drag.bar);
        clearDropHighlight();
        stack?.closest('.plan-line--work')?.classList.add('plan-line--drop');
        if (stack && stack !== drag.targetStack) {
            drag.targetStack = stack;
            placeBarInStack(drag.bar, stack);
        }
    }

    function pointerToPos(clientX, stack) {
        const rect = (stack || drag?.originStack)?.getBoundingClientRect();
        if (!rect) {
            return 0;
        }
        return snapPosition((clientX - rect.left) / (rect.width / dayCount), dayCount);
    }

    function previewDrag(clientX, clientY) {
        if (!drag) {
            return;
        }
        if (Math.abs(clientX - drag.startX) > 4 || Math.abs(clientY - drag.startY) > 4) {
            drag.moved = true;
        }
        if (drag.mode === 'move') {
            const delta = (clientX - drag.startX) / dayWidth();
            const box = shiftBox(
                drag.originStart,
                drag.originSpan,
                drag.originStartOffset,
                drag.originEndOffset,
                delta,
                dayCount,
            );
            applyBarBox(drag.bar, box.start, box.span, box.startOffset, box.endOffset);
            showHoursHint(clientX, clientY, intervalLabel(box));
            if (drag.moved) {
                previewDropTarget(clientX, clientY);
            }
            return;
        }
        let startPos = drag.originStartPos;
        let endPos = drag.originEndPos;
        const pos = pointerToPos(clientX, drag.originStack);
        if (drag.mode === 'resize-start') {
            startPos = Math.min(pos, endPos - SNAP_FRACTION);
        } else {
            endPos = Math.max(pos, startPos + SNAP_FRACTION);
        }
        const box = boxFromPositions(startPos, endPos);
        applyBarBox(drag.bar, box.start, box.span, box.startOffset, box.endOffset);
        showHoursHint(clientX, clientY, intervalLabel(box));
    }

    async function finishDrag() {
        if (!drag) {
            return;
        }
        const current = drag;
        drag = null;
        current.bar.classList.remove('is-dragging');
        clearDropHighlight();
        hideHoursHint();
        if (!current.moved) {
            if (current.mode === 'move') {
                openEdit(current.bar);
            }
            return;
        }
        justDragged = true;
        const startIdx = Number(current.bar.dataset.start);
        const span = Number(current.bar.dataset.span);
        const startOffset = Number(current.bar.dataset.startOffset || 0);
        const endOffset = Number(current.bar.dataset.endOffset || 1);
        const dayDelta = startIdx - current.originStart;
        const startDate = addDays(current.startDate, dayDelta);
        const endDate = addDays(startDate, Math.max(0, span - 1));
        const startTime = timeFromFraction(startOffset);
        const endTime = timeFromFraction(endOffset);
        const workItemId = current.bar.dataset.workItemId || '';
        const workItemChanged = workItemId !== current.originWorkItemId;
        if (
            startDate === current.startDate
            && endDate === current.endDate
            && startTime === String(current.startTime || '').slice(0, 5)
            && endTime === String(current.endTime || '').slice(0, 5)
            && !workItemChanged
        ) {
            restoreBar(current);
            return;
        }
        const ok = await save(assignmentUrl(current.bar.dataset.shiftId), 'PATCH', {
            worker_id: Number(current.bar.dataset.workerId),
            work_item_id: workItemId ? Number(workItemId) : null,
            start_date: startDate,
            end_date: endDate,
            start_time: startTime,
            end_time: endTime,
        });
        if (ok) {
            window.location.reload();
            return;
        }
        restoreBar(current);
    }

    if (! readonly) {
    board.querySelectorAll('.person-bar').forEach((bar) => {
        bar.addEventListener('pointerdown', (event) => {
            const handle = event.target.closest('.bar-handle');
            const originStack = bar.closest('.person-stack');
            const originStart = Number(bar.dataset.start);
            const originSpan = Number(bar.dataset.span);
            const originStartOffset = Number(bar.dataset.startOffset || 0);
            const originEndOffset = Number(bar.dataset.endOffset || 1);
            const positions = positionsFromBox(originStart, originSpan, originStartOffset, originEndOffset);
            drag = {
                mode: handle ? (handle.dataset.edge === 'start' ? 'resize-start' : 'resize-end') : 'move',
                bar,
                startX: event.clientX,
                startY: event.clientY,
                originStart,
                originSpan,
                originStartOffset,
                originEndOffset,
                originStartPos: positions.startPos,
                originEndPos: positions.endPos,
                originStack,
                originTop: bar.style.top,
                originWorkItemId: bar.dataset.workItemId || '',
                targetStack: originStack,
                startDate: bar.dataset.startDate,
                endDate: bar.dataset.endDate,
                startTime: bar.dataset.startTime || '08:00',
                endTime: bar.dataset.endTime || '16:00',
                moved: false,
            };
            bar.classList.add('is-dragging');
            bar.setPointerCapture(event.pointerId);
            event.preventDefault();
        });
        bar.addEventListener('pointermove', (event) => previewDrag(event.clientX, event.clientY));
        bar.addEventListener('pointerup', (event) => {
            if (drag?.mode === 'move' && drag.moved) {
                previewDropTarget(event.clientX, event.clientY);
            }
            finishDrag();
        });
        bar.addEventListener('pointercancel', () => {
            if (!drag) {
                return;
            }
            const current = drag;
            drag = null;
            current.bar.classList.remove('is-dragging');
            clearDropHighlight();
            hideHoursHint();
            restoreBar(current);
        });
    });

    board.querySelectorAll('.person-stack').forEach((stack) => {
        stack.addEventListener('click', (event) => {
            if (justDragged) {
                justDragged = false;
                return;
            }
            if (event.target.closest('.person-bar')) {
                return;
            }
            const cell = event.target.closest('.drop-day');
            if (!cell || !stack.dataset.projectId) {
                return;
            }
            const rect = cell.getBoundingClientRect();
            const fraction = snapPosition((event.clientX - rect.left) / Math.max(1, rect.width), 1);
            const startTime = timeFromFraction(Math.min(0.75, fraction));
            openAdd(
                stack.dataset.projectId,
                stack.dataset.workItemId || '',
                cell.dataset.date,
                startTime,
                WORKDAY_HOURS,
            );
        });
    });
    }

    whoSelect.addEventListener('change', syncMenFromWho);
    workSelect.addEventListener('change', refreshCandidates);
    startInput.addEventListener('change', refreshCandidates);
    endInput.addEventListener('change', refreshCandidates);
    hoursSelect?.addEventListener('change', () => {
        const hours = selectedHours();
        const currentStart = selectedSlotInput()?.dataset.start || '08:00';
        renderSlotOptions(hours, currentStart);
        crewList.querySelectorAll('select[data-crew-hours]').forEach((select) => {
            select.value = String(hours);
        });
        syncHoursSummary();
        refreshCandidates();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const [kind, id] = (whoSelect.value || '').split(':');
        if (!kind || !id) {
            return;
        }
        const assignmentId = form.dataset.assignmentId;
        const crewIds = selectedCrewIds();
        const people = workerCrew(id);
        if (people.length >= 2 && crewIds.length === 0) {
            window.alert('Vink aan wie er naar dit project gaat.');
            return;
        }
        const times = selectedTimes();
        const body = {
            project_id: Number(projectInput.value),
            work_item_id: Number(workSelect.value),
            start_date: startInput.value,
            end_date: endInput.value,
            people_count: Math.max(1, Number(menInput.value || 1)),
            hours: times.hours,
            slot: times.hours >= WORKDAY_HOURS ? 'full' : selectedSlot(),
            start_time: times.start,
            end_time: times.end,
        };
        if (people.length >= 2) {
            body.crew_member_ids = crewIds;
            body.people_count = crewIds.length;
            body.crew_hours = selectedCrewHours();
        }
        if (startInput.value > endInput.value) {
            window.alert('De einddatum moet op of na de startdatum liggen.');
            return;
        }
        if (assignmentId) {
            body.worker_id = Number(id);
            if (await save(assignmentUrl(assignmentId), 'PATCH', body)) {
                window.location.reload();
            }
            return;
        }
        if (kind === 'team') {
            body.team_id = Number(id);
        } else {
            body.worker_id = Number(id);
        }
        if (await save(board.dataset.storeUrl, 'POST', body)) {
            window.location.reload();
        }
    });

    document.getElementById('plan-cancel').addEventListener('click', () => dialog.close());

    deleteBtn.addEventListener('click', async () => {
        const assignmentId = form.dataset.assignmentId;
        if (!assignmentId || !window.confirm('Deze inzet uit de planning halen?')) {
            return;
        }
        if (await save(assignmentUrl(assignmentId), 'DELETE', {})) {
            window.location.reload();
        }
    });

    let focusTimer = null;
    function clearFocusBars() {
        board.querySelectorAll('.person-bar.is-focus').forEach((bar) => bar.classList.remove('is-focus'));
    }

    function focusConflictBars(workerId) {
        clearFocusBars();
        if (focusTimer) {
            window.clearTimeout(focusTimer);
        }
        const selector = `.person-bar.double[data-worker-id="${workerId}"]`;
        let bars = [...board.querySelectorAll(selector)];
        if (bars.length === 0) {
            bars = [...board.querySelectorAll(`.person-bar[data-worker-id="${workerId}"]`)];
        }
        if (bars.length === 0) {
            return;
        }
        bars.forEach((bar) => bar.classList.add('is-focus'));
        bars[0].scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        focusTimer = window.setTimeout(clearFocusBars, 2800);
    }

    document.querySelectorAll('[data-focus-worker]').forEach((button) => {
        button.addEventListener('click', () => {
            focusConflictBars(button.dataset.focusWorker);
        });
    });
}
