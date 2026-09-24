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
    workdayCount,
} from "./planning-hours";
import { bindLaborFold } from "./planning-labor-fold";
import {
    bindPlanningMobileChrome,
    isPlanningMobileViewport,
} from "./planning-mobile";
import {
    bindPlanningScrollRestore,
    reloadPlanningBoard,
} from "./planning-scroll";
import {
    bindPlanningDatePickers,
    workdaysForIsoWeek,
} from "./planning-datepicker.js";
import { isoWeekFromDate } from "./planning-weeks.js";
import {
    planningCheckedWorkIds,
    planningProjectWorkItems,
    planningWorkChoices,
} from "./planning-work-choices.js";
import { bindPlanningFilterSearch } from "./planning-filter-search.js";
import {
    candidatesFetchInit,
    datesForExactMode,
    isLatestCandidatesRequest,
    preservedWhoValue,
    whoChoice,
    whoOptionList,
    whoValueForWorker,
    workerNameFromBarLabel,
} from "./planning-who-options.js";

const token = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute("content");
const board = document.getElementById("plan-board");
if (board) {
    function fitPlanningBarLabels() {
        board.querySelectorAll(".bar-label[data-label-full]").forEach((label) => {
            const full = label.dataset.labelFull || "";
            const short = label.dataset.labelShort || full;
            label.textContent = full;
            if (short !== full && label.scrollWidth > label.clientWidth + 1) {
                label.textContent = short;
            }
        });
    }

    fitPlanningBarLabels();
    window.addEventListener("resize", fitPlanningBarLabels);

    const scroller = document.getElementById("plan-scroller");
    bindPlanningMobileChrome(document);
    bindPlanningScrollRestore(scroller);
    const readonly = board.dataset.readonly === "1";
    const dialog = document.getElementById("plan-dialog");
    const form = document.getElementById("plan-form");
    const whoSelect = document.getElementById("plan-who");
    const workSelect = document.getElementById("plan-work");
    const workList = document.getElementById("plan-work-list");
    const startInput = document.getElementById("plan-start");
    const endInput = document.getElementById("plan-end");
    const menInput = document.getElementById("plan-men");
    const menWrap = document.getElementById("plan-men-wrap");
    const crewBox = document.getElementById("plan-crew");
    const crewList = document.getElementById("plan-crew-list");
    const crewHeading = document.getElementById("plan-crew-heading");
    const crewHint = document.getElementById("plan-crew-hint");
    const rolesBox = document.getElementById("plan-roles");
    const foremanSelect = document.getElementById("plan-foreman");
    const workTicketHolderSelect = document.getElementById(
        "plan-work-ticket-holder",
    );
    const hoursSelect = document.getElementById("plan-hours");
    const hoursWrap = document.getElementById("plan-hours-wrap");
    const datesWrap = document.getElementById("plan-dates-wrap");
    const weeksWrap = document.getElementById("plan-weeks-wrap");
    const whenDatesInput = document.getElementById("plan-when-dates");
    const whenWeeksInput = document.getElementById("plan-when-weeks");
    const startWeekInput = document.getElementById("plan-start-week");
    const endWeekInput = document.getElementById("plan-end-week");
    const weekYearInput = document.getElementById("plan-week-year");
    const slotWrap = document.getElementById("plan-slot-wrap");
    const slotList = document.getElementById("plan-slot-list");
    const hoursSummary = document.getElementById("plan-hours-summary");
    const includeSaturdayInput = document.getElementById(
        "plan-include-saturday",
    );
    const includeSundayInput = document.getElementById("plan-include-sunday");
    const hoursHint = document.getElementById("plan-hours-hint");
    const projectInput = document.getElementById("plan-project-id");
    const titleEl = document.getElementById("plan-dialog-title");
    const deleteBtn = document.getElementById("plan-delete");
    const ticketWrap = document.getElementById("plan-ticket-wrap");
    const ticketLink = document.getElementById("plan-ticket-link");
    const ticketExisting = document.getElementById("plan-ticket-existing");
    const workItems = JSON.parse(board.dataset.workItems || "{}");
    const crews = JSON.parse(board.dataset.crews || "{}");
    const candidatesUrl = board.dataset.candidatesUrl || "";
    const dates = [
        ...board.querySelectorAll(".plan-line--head [data-date]"),
    ].map((el) => el.dataset.date);
    const whoMeta = {};
    [...whoSelect.options].forEach((option) => {
        if (option.value) {
            whoMeta[option.value] = {
                name: option.textContent.trim(),
                peopleCount: Number(option.dataset.men || 1),
                external: option.dataset.external === "1",
            };
        }
    });
    let lastCandidates = [];
    let candidatesAbort = null;
    let candidatesRequestId = 0;
    let selectedWho = "";
    let selectedWhoChoice = null;
    let openedWorkIds = [];
    let saving = false;
    const submitBtn = form.querySelector('button[type="submit"]');
    const dayCount = dates.length;
    const dayWidth = () =>
        board.querySelector(".plan-day")?.getBoundingClientRect().width || 132;

    async function request(url, method, body) {
        const response = await fetch(url, {
            method,
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": token,
                "X-HTTP-Method-Override": method,
            },
            body: JSON.stringify(body),
        });
        const json = await response.json().catch(() => ({}));
        return { response, json };
    }

    async function save(url, method, body) {
        if (saving) {
            return false;
        }
        saving = true;
        submitBtn?.setAttribute("disabled", "disabled");

        const send = async (confirmConflict = false) => {
            const { response, json } = await request(url, method, {
                ...body,
                confirm_conflict: confirmConflict,
            });
            if (response.status === 409 && json.conflict) {
                if (window.confirm(json.message)) {
                    return send(true);
                }
                return false;
            }
            if (!response.ok) {
                const firstError = json.errors
                    ? Object.values(json.errors).flat()[0]
                    : null;
                window.alert(
                    firstError || json.message || "Opslaan is niet gelukt.",
                );
                return false;
            }
            return true;
        };

        try {
            return await send(false);
        } finally {
            saving = false;
            submitBtn?.removeAttribute("disabled");
        }
    }

    function assignmentUrl(id) {
        return `${board.dataset.assignmentUrl.replace(/\/$/, "")}/${id}`;
    }

    function addDays(iso, days) {
        const date = new Date(`${iso}T12:00:00`);
        date.setDate(date.getDate() + days);
        const month = String(date.getMonth() + 1).padStart(2, "0");
        const day = String(date.getDate()).padStart(2, "0");
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

    function selectedWorkIds() {
        const checked = [
            ...(workList?.querySelectorAll('input[type="checkbox"]:checked') ||
                []),
        ].map((input) => ({
            id: Number(input.value),
            checked: true,
            memberIds: String(input.dataset.memberIds || input.value)
                .split(",")
                .map((id) => Number(id))
                .filter((id) => id > 0),
        }));

        return planningCheckedWorkIds(checked, openedWorkIds);
    }

    function fillWorkItems(projectId, selectedId) {
        const selected = new Set(
            (Array.isArray(selectedId) ? selectedId : [selectedId])
                .map((id) => Number(id))
                .filter((id) => id > 0),
        );
        openedWorkIds = [...selected];
        const choices = planningWorkChoices(
            planningProjectWorkItems(workItems, projectId),
        );
        if (workList) {
            workList.innerHTML = "";
        }
        choices.forEach((choice) => {
            if (!workList) {
                return;
            }
            const row = document.createElement("label");
            row.className = "flex items-center gap-2 text-sm leading-tight";
            const input = document.createElement("input");
            input.type = "checkbox";
            input.value = String(choice.id);
            input.dataset.projectId =
                choice.projectId || String(projectId || "");
            input.dataset.memberIds = choice.memberIds.join(",");
            input.checked = choice.memberIds.some((id) => selected.has(id));
            input.addEventListener("change", () => {
                if (input.checked) {
                    const projectKey = input.dataset.projectId;
                    workList
                        .querySelectorAll('input[type="checkbox"]:checked')
                        .forEach((other) => {
                            if (
                                other !== input &&
                                other.dataset.projectId !== projectKey
                            ) {
                                other.checked = false;
                            }
                        });
                }
                const ids = selectedWorkIds();
                workSelect.value = ids[0] ? String(ids[0]) : "";
                syncProjectFromWork();
                refreshCandidates();
            });
            const text = document.createElement("span");
            text.className = "min-w-0 truncate";
            text.textContent = choice.label;
            row.append(input, text);
            workList.append(row);
        });
        if (workList && workList.children.length === 0) {
            const empty = document.createElement("p");
            empty.className = "text-sm text-nicon-muted";
            empty.textContent = "Geen werkzaamheden";
            workList.append(empty);
        }
        workSelect.value = selectedWorkIds()[0]
            ? String(selectedWorkIds()[0])
            : "";
        syncProjectFromWork();
    }

    function syncProjectFromWork() {
        const checked = workList?.querySelector(
            'input[type="checkbox"]:checked',
        );
        const projectId = checked?.dataset.projectId;
        if (projectId) {
            projectInput.value = projectId;
        }
    }

    function workerCrew(workerId) {
        return crews[workerId] || crews[String(workerId)] || [];
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;");
    }

    function candidateByWorkerId(workerId) {
        return (
            lastCandidates.find((row) => String(row.id) === String(workerId)) ||
            null
        );
    }

    function crewFit(workerId, personId) {
        const candidate = candidateByWorkerId(workerId);
        if (!candidate?.crew) {
            return null;
        }

        return (
            candidate.crew.find((row) => Number(row.id) === Number(personId)) ||
            null
        );
    }

    function paintWhoOptions(options) {
        whoSelect.innerHTML = options
            .map((item) => {
                const extra = item.value
                    ? ` data-men="${item.peopleCount}" data-selectable="${item.selectable ? "1" : "0"}" data-external="${item.external ? "1" : "0"}"`
                    : "";
                const disabled = item.disabled ? " disabled" : "";
                const selected = item.selected ? " selected" : "";

                return `<option value="${escapeHtml(item.value)}"${extra}${disabled}${selected}>${escapeHtml(item.label)}</option>`;
            })
            .join("");
    }

    function choiceForWho(value, fallbackName = "", fallbackPeople = 1) {
        if (!value) {
            return null;
        }
        const meta = whoMeta[value];

        return whoChoice(
            meta?.name || fallbackName,
            meta?.peopleCount || fallbackPeople,
        );
    }

    function rememberWho(value, choice = null) {
        selectedWho = value || "";
        selectedWhoChoice = choice || choiceForWho(selectedWho);
    }

    function renderWhoOptions(preserveValue) {
        const current = preservedWhoValue(
            preserveValue,
            selectedWho || whoSelect.value,
        );
        paintWhoOptions(
            whoOptionList(
                lastCandidates,
                current,
                "Kies vakman of team",
                selectedWhoChoice,
            ),
        );
        const stillValid =
            current &&
            whoSelect.querySelector(
                `option[value="${CSS.escape(current)}"]:not(:disabled)`,
            );
        whoSelect.value = stillValid ? current : "";
    }

    function invalidateWhoCandidates() {
        candidatesRequestId += 1;
        if (candidatesAbort) {
            candidatesAbort.abort();
            candidatesAbort = null;
        }
        lastCandidates = [];
    }

    async function refreshCandidates() {
        const preserveValue = preservedWhoValue(selectedWho, whoSelect.value);
        const requestId = ++candidatesRequestId;
        lastCandidates = [];
        renderWhoOptions(preserveValue);

        if (weekMode()) {
            syncDatesFromWeeks();
        }
        if (
            !candidatesUrl ||
            !workSelect.value ||
            !startInput.value ||
            !endInput.value
        ) {
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
            slot: times.hours >= WORKDAY_HOURS ? "full" : selectedSlot(),
            include_saturday: includeSaturday() ? "1" : "0",
            include_sunday: includeSunday() ? "1" : "0",
        });
        if (form.dataset.assignmentId) {
            params.set("assignment_id", form.dataset.assignmentId);
        }
        if (candidatesAbort) {
            candidatesAbort.abort();
        }
        candidatesAbort = new AbortController();
        try {
            const response = await fetch(
                `${candidatesUrl}?${params}`,
                candidatesFetchInit(candidatesAbort.signal),
            );
            if (!isLatestCandidatesRequest(requestId, candidatesRequestId)) {
                return;
            }
            const json = await response.json().catch(() => ({}));
            if (!isLatestCandidatesRequest(requestId, candidatesRequestId)) {
                return;
            }
            if (!response.ok) {
                return;
            }
            lastCandidates = Array.isArray(json.workers)
                ? json.workers.slice()
                : [];
            renderWhoOptions(preserveValue);
            const workerId = (whoSelect.value || "").split(":")[1];
            if (workerId && workerCrew(workerId).length >= 2) {
                renderCrew(workerId, selectedCrewIds(), selectedCrewHours());
            } else if (whoSelect.value) {
                syncMenFromWho();
            }
        } catch (error) {
            if (error?.name !== "AbortError") {
                throw error;
            }
        }
    }

    function selectedCrewIds() {
        return [
            ...crewList.querySelectorAll('input[type="checkbox"]:checked'),
        ].map((input) => Number(input.value));
    }

    function selectedSlotInput() {
        return slotList?.querySelector('input[name="slot"]:checked') || null;
    }

    function selectedSlot() {
        const value = selectedSlotInput()?.value || "morning";
        if (value === "afternoon" || value === "full") {
            return value;
        }
        return "morning";
    }

    function selectedHours() {
        return Number(hoursSelect?.value || WORKDAY_HOURS);
    }

    function selectedTimes() {
        const hours = selectedHours();
        if (hours >= WORKDAY_HOURS) {
            return timesFromHours(WORKDAY_HOURS, "full");
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

    function renderSlotOptions(hours, preferredStart = "08:00") {
        if (!slotList) {
            return;
        }
        const options = slotOptions(hours);
        const want = String(preferredStart || "08:00").slice(0, 5);
        let matched = options.findIndex((option) => option.start === want);
        if (matched < 0) {
            matched = 0;
        }
        slotList.innerHTML = options
            .map((option, index) => {
                const id = `plan-slot-${option.start.replace(":", "")}`;
                const checked = index === matched ? " checked" : "";
                const slotValue =
                    option.start >= "12:00" ? "afternoon" : "morning";
                return `<label class="flex items-center gap-1.5"><input type="radio" name="slot" id="${id}" value="${slotValue}" data-start="${option.start}" data-end="${option.end}"${checked}> ${option.label}</label>`;
            })
            .join("");
        slotList.querySelectorAll('input[name="slot"]').forEach((input) => {
            input.addEventListener("change", () => {
                syncHoursSummary();
                refreshCandidates();
            });
        });
    }

    function selectedCrewHours() {
        const hours = {};
        crewList
            .querySelectorAll("select[data-crew-hours]")
            .forEach((select) => {
                const id = Number(select.dataset.crewHours);
                if (id > 0) {
                    hours[id] = Number(select.value);
                }
            });
        return hours;
    }

    function defaultWeekYear() {
        return Number(board.dataset.weekYear || new Date().getFullYear());
    }

    function weekMode() {
        return Boolean(whenWeeksInput?.checked);
    }

    function syncWeeksFromDates() {
        const start = isoWeekFromDate(startInput.value);
        const end = isoWeekFromDate(endInput.value) || start;
        if (weekYearInput) {
            weekYearInput.value = String(start?.year || defaultWeekYear());
        }
        if (startWeekInput) {
            startWeekInput.value = start ? String(start.week) : "";
        }
        if (endWeekInput) {
            endWeekInput.value = end ? String(end.week) : "";
        }
    }

    function syncDatesFromWeeks() {
        const year = Number(weekYearInput?.value || defaultWeekYear());
        const fromWeek = Number(startWeekInput?.value);
        const toWeek = Number(endWeekInput?.value || fromWeek);
        const start = workdaysForIsoWeek(year, fromWeek);
        const end = workdaysForIsoWeek(year, toWeek);
        if (start) {
            startInput.value = start.start;
        }
        if (end) {
            endInput.value = end.end;
        }
    }

    function setWhenMode(mode, applyWeekRange = false) {
        const weeks = mode === "weeks";
        if (whenDatesInput) {
            whenDatesInput.checked = !weeks;
        }
        if (whenWeeksInput) {
            whenWeeksInput.checked = weeks;
        }
        datesWrap?.classList.toggle("hidden", weeks);
        weeksWrap?.classList.toggle("hidden", !weeks);
        hoursWrap?.classList.toggle("hidden", weeks);
        startInput.required = !weeks;
        endInput.required = !weeks;
        if (startWeekInput) {
            startWeekInput.required = weeks;
        }
        if (endWeekInput) {
            endWeekInput.required = weeks;
        }
        if (weekYearInput) {
            weekYearInput.required = weeks;
        }
        if (weeks) {
            if (!weekYearInput?.value) {
                if (weekYearInput) {
                    weekYearInput.value = String(defaultWeekYear());
                }
            }
            if (startInput.value && endInput.value) {
                syncWeeksFromDates();
            } else {
                syncDatesFromWeeks();
            }
            refreshCandidates();
            return;
        }
        const year = Number(weekYearInput?.value || defaultWeekYear());
        const fromWeek = workdaysForIsoWeek(
            year,
            Number(startWeekInput?.value),
        );
        const toWeek = workdaysForIsoWeek(
            year,
            Number(endWeekInput?.value || startWeekInput?.value),
        );
        const bounds = datesForExactMode({
            startDate: startInput.value,
            endDate: endInput.value,
            applyWeekRange,
            weekStartDate: fromWeek?.start || "",
            weekEndDate: toWeek?.end || "",
        });
        startInput.value = bounds.startDate;
        endInput.value = bounds.endDate;
        syncHoursSummary();
        refreshCandidates();
    }

    function includeSaturday() {
        return Boolean(includeSaturdayInput?.checked);
    }

    function includeSunday() {
        return Boolean(includeSundayInput?.checked);
    }

    function setWeekendDays(saturday, sunday) {
        if (includeSaturdayInput) {
            includeSaturdayInput.checked = Boolean(saturday);
        }
        if (includeSundayInput) {
            includeSundayInput.checked = Boolean(sunday);
        }
    }

    function syncHoursSummary() {
        const times = selectedTimes();
        if (hoursSummary) {
            const days = workdayCount(
                startInput.value,
                endInput.value,
                includeSaturday(),
                includeSunday(),
            );
            if (days > 1) {
                hoursSummary.textContent = `${times.start}–${times.end} · ${hoursLabel(times.hours)} × ${days} dagen · ${hoursLabel(times.hours * days)}`;
            } else {
                hoursSummary.textContent = `${times.start}–${times.end} · ${hoursLabel(times.hours)}`;
            }
        }
        if (slotWrap) {
            slotWrap.classList.toggle(
                "hidden",
                selectedHours() >= WORKDAY_HOURS,
            );
        }
    }

    function setHoursUi(hours, startTime) {
        const value = String(
            [2, 4, 6, 8].includes(Number(hours))
                ? Number(hours)
                : WORKDAY_HOURS,
        );
        hoursSelect.value = value;
        renderSlotOptions(Number(value), startTime);
        syncHoursSummary();
    }

    function renderCrew(workerId, selectedIds = [], hoursById = {}) {
        const people = workerCrew(workerId);
        const selected = new Set(
            (selectedIds || []).map((id) => Number(id)).filter((id) => id > 0),
        );
        crewList.innerHTML = "";
        if (people.length < 2) {
            crewBox.classList.add("hidden");
            menWrap.classList.remove("hidden");
            menInput.readOnly = false;
            return;
        }

        people.forEach((person) => {
            const fit = crewFit(workerId, person.id);
            const blocked = fit ? !fit.selectable : false;
            const row = document.createElement("label");
            row.className =
                "plan-crew-row flex items-center justify-between gap-2 text-sm leading-tight";
            if (blocked) {
                row.classList.add("plan-crew-row--blocked");
            }
            const left = document.createElement("span");
            left.className = "flex items-center gap-2 min-w-0";
            const input = document.createElement("input");
            input.type = "checkbox";
            input.value = String(person.id);
            input.disabled = blocked;
            input.checked = !blocked && selected.has(Number(person.id));
            input.addEventListener("change", syncMenFromCrew);
            const text = document.createElement("span");
            text.className = "min-w-0 truncate";
            text.textContent = person.name;
            left.append(input, text);
            const right = document.createElement("span");
            right.className = "flex items-center gap-2 shrink-0";
            if (fit?.status_label) {
                const status = document.createElement("span");
                status.className = "text-xs text-nicon-muted";
                status.textContent = fit.status_label;
                right.append(status);
            }
            const hours = document.createElement("select");
            hours.dataset.crewHours = String(person.id);
            hours.className =
                "border border-nicon-line bg-white px-1 py-0.5 text-sm";
            hours.disabled = blocked;
            [2, 4, 6, 8].forEach((value) => {
                const option = document.createElement("option");
                option.value = String(value);
                option.textContent = `${value}u`;
                hours.append(option);
            });
            hours.value = String(hoursById[person.id] || selectedHours());
            right.append(hours);
            row.append(left, right);
            crewList.append(row);
        });
        crewBox.classList.remove("hidden");
        menWrap.classList.add("hidden");
        menInput.readOnly = true;
        syncMenFromCrew();
        syncRoleSelects();
    }

    function selectedRolePeople() {
        const workerId = (whoSelect.value || "").split(":")[1];
        const people = workerCrew(workerId);
        if (people.length >= 2) {
            const selected = new Set(selectedCrewIds());
            return people.filter((person) => selected.has(Number(person.id)));
        }

        return people;
    }

    function fillRoleSelect(select, people, currentId) {
        if (!select) {
            return;
        }
        const current =
            currentId === undefined ? select.value : String(currentId || "");
        const placeholder = select.options[0]?.textContent || "Kies…";
        select.innerHTML = "";
        const empty = document.createElement("option");
        empty.value = "";
        empty.textContent = placeholder;
        select.append(empty);
        people.forEach((person) => {
            const option = document.createElement("option");
            option.value = String(person.id);
            option.textContent = person.name;
            select.append(option);
        });
        const allowed = new Set(people.map((person) => String(person.id)));
        select.value = allowed.has(current) ? current : "";
    }

    function selectedWorkerIsExternal() {
        const option = whoSelect.selectedOptions[0];
        if (option?.dataset.external === "1") {
            return true;
        }
        const workerId = (whoSelect.value || "").split(":")[1];
        const candidate = candidateByWorkerId(workerId);

        return Boolean(
            candidate?.external || whoMeta[whoSelect.value]?.external,
        );
    }

    function syncRoleSelects(foremanId, holderId) {
        const people = selectedRolePeople();
        const hideRoles = selectedWorkerIsExternal() || people.length === 0;
        if (rolesBox) {
            rolesBox.classList.toggle("hidden", hideRoles);
        }
        if (hideRoles) {
            return;
        }
        fillRoleSelect(foremanSelect, people, foremanId);
        fillRoleSelect(workTicketHolderSelect, people, holderId);
    }

    function syncMenFromCrew() {
        const people = workerCrew((whoSelect.value || "").split(":")[1]);
        if (people.length < 2) {
            syncRoleSelects();
            return;
        }
        const count = Math.max(1, selectedCrewIds().length || 0);
        menInput.value = String(count);
        syncRoleSelects();
    }

    function syncMenFromWho() {
        const option = whoSelect.selectedOptions[0];
        const isTeam = (whoSelect.value || "").startsWith("team:");
        const workerId = (whoSelect.value || "").split(":")[1];
        const people = isTeam ? [] : workerCrew(workerId);
        if (people.length >= 2) {
            renderCrew(workerId, []);
            return;
        }
        crewBox.classList.add("hidden");
        crewList.innerHTML = "";
        menWrap.classList.remove("hidden");
        const men = Number(option?.dataset.men || 1);
        menInput.value = String(Math.max(1, men));
        menInput.readOnly = isTeam;
        syncRoleSelects();
    }

    function openAdd(
        projectId,
        workItemId,
        date,
        startTime = "08:00",
        hours = WORKDAY_HOURS,
        whoValue = "",
    ) {
        invalidateWhoCandidates();
        form.dataset.assignmentId = "";
        titleEl.textContent = "Iemand inplannen";
        deleteBtn.classList.add("hidden");
        ticketWrap?.classList.add("hidden");
        if (ticketLink) {
            ticketLink.removeAttribute("href");
        }
        if (ticketExisting) {
            ticketExisting.classList.add("hidden");
            ticketExisting.removeAttribute("href");
            ticketExisting.textContent = "";
        }
        if (crewHeading) {
            crewHeading.textContent = "Vakmannen";
        }
        crewHint?.classList.add("hidden");
        rememberWho(whoValue || "", choiceForWho(whoValue || ""));
        renderWhoOptions(selectedWho);
        whoSelect.disabled = false;
        projectInput.value = projectId;
        fillWorkItems(projectId, workItemId);
        if (
            workList &&
            !workList.querySelector('input[type="checkbox"]:checked')
        ) {
            const first = workList.querySelector('input[type="checkbox"]');
            if (first) {
                first.checked = true;
                workSelect.value = selectedWorkIds()[0]
                    ? String(selectedWorkIds()[0])
                    : "";
                syncProjectFromWork();
            }
        }
        whoSelect
            .querySelectorAll('option[value^="team:"]')
            .forEach((option) => {
                option.hidden = false;
            });
        startInput.value = date;
        endInput.value = date;
        setWeekendDays(false, false);
        menInput.value = "1";
        menInput.readOnly = false;
        crewBox.classList.add("hidden");
        crewList.innerHTML = "";
        rolesBox?.classList.add("hidden");
        menWrap.classList.remove("hidden");
        setHoursUi(hours, startTime);
        if (weekYearInput) {
            weekYearInput.value = String(defaultWeekYear());
        }
        syncWeeksFromDates();
        setWhenMode("dates");
        if (whoValue) {
            syncMenFromWho();
        }
        dialog.showModal();
        whoSelect.focus();
        refreshCandidates();
    }

    let openInternalFromBar = () => {};

    function openEdit(bar) {
        if (bar.dataset.internal === "1") {
            openInternalFromBar(bar);
            return;
        }
        invalidateWhoCandidates();
        form.dataset.assignmentId = bar.dataset.shiftId;
        titleEl.textContent = "Inzet aanpassen";
        deleteBtn.classList.remove("hidden");
        if (ticketLink && ticketWrap) {
            const base = board.dataset.ticketUrl || board.dataset.assignmentUrl;
            ticketLink.href = `${base}/${bar.dataset.shiftId}/werkbonnen/nieuw`;
            ticketLink.textContent = bar.dataset.ticketLabel || "Werkbon maken";
            ticketWrap.classList.remove("hidden");
        }
        if (ticketExisting) {
            const existingLabel = bar.dataset.ticketExisting || "";
            const existingUrl = bar.dataset.ticketShowUrl || "";
            if (existingLabel && existingUrl) {
                ticketExisting.textContent = existingLabel;
                ticketExisting.href = existingUrl;
                ticketExisting.classList.remove("hidden");
            } else {
                ticketExisting.classList.add("hidden");
                ticketExisting.removeAttribute("href");
                ticketExisting.textContent = "";
            }
        }
        if (crewHeading) {
            crewHeading.textContent = "Vakmannen";
        }
        crewHint?.classList.remove("hidden");
        whoSelect.disabled = false;
        whoSelect
            .querySelectorAll('option[value^="team:"]')
            .forEach((option) => {
                option.hidden = true;
            });
        const whoValue = whoValueForWorker(bar.dataset.workerId);
        rememberWho(
            whoValue,
            choiceForWho(
                whoValue,
                workerNameFromBarLabel(
                    bar.querySelector(".bar-label")?.textContent ||
                        bar.title ||
                        "",
                ),
                bar.dataset.peopleCount,
            ),
        );
        renderWhoOptions(selectedWho);
        projectInput.value = bar.dataset.projectId;
        fillWorkItems(
            bar.dataset.projectId,
            (
                bar.dataset.workItemIds ||
                bar.dataset.workItemId ||
                bar.closest(".person-stack")?.dataset.workItemId ||
                ""
            ).split(","),
        );
        startInput.value = bar.dataset.startDate;
        endInput.value = bar.dataset.endDate;
        setWeekendDays(
            bar.dataset.includeSaturday === "1",
            bar.dataset.includeSunday === "1",
        );
        menInput.value = String(
            Math.max(1, Number(bar.dataset.peopleCount || 1)),
        );
        const selectedIds = (bar.dataset.crewIds || "")
            .split(",")
            .map((id) => Number(id))
            .filter((id) => id > 0);
        const planned = Number(bar.dataset.plannedHours || WORKDAY_HOURS);
        const dayHours =
            bar.dataset.startDate === bar.dataset.endDate
                ? planned
                : WORKDAY_HOURS;
        setHoursUi(dayHours, bar.dataset.startTime);
        const hoursById = {};
        selectedIds.forEach((id) => {
            hoursById[id] = dayHours;
        });
        renderCrew(bar.dataset.workerId, selectedIds, hoursById);
        syncRoleSelects(bar.dataset.foremanId, bar.dataset.workTicketHolderId);
        if (workerCrew(bar.dataset.workerId).length < 2) {
            menInput.readOnly = false;
        }
        setWhenMode(bar.dataset.provisional === "1" ? "weeks" : "dates");
        syncWeeksFromDates();
        dialog.showModal();
        refreshCandidates();
    }

    let drag = null;
    let justDragged = false;

    function clearDropHighlight() {
        board
            .querySelectorAll(".plan-line--drop")
            .forEach((line) => line.classList.remove("plan-line--drop"));
    }

    function hideHoursHint() {
        hoursHint?.classList.add("hidden");
    }

    function showHoursHint(clientX, clientY, label) {
        if (!hoursHint) {
            return;
        }
        hoursHint.textContent = label;
        hoursHint.style.left = `${clientX}px`;
        hoursHint.style.top = `${clientY}px`;
        hoursHint.classList.remove("hidden");
    }

    function siblingSegments(bar, stack) {
        const id = bar.dataset.shiftId;
        const root = stack || bar.closest(".person-stack");
        if (!id || !root) {
            return [];
        }

        return [...root.querySelectorAll(`.person-bar[data-shift-id="${id}"]`)].filter(
            (el) => el !== bar,
        );
    }

    function hideSiblingSegments(bar) {
        siblingSegments(bar, drag?.originStack).forEach((el) =>
            el.classList.add("hidden"),
        );
    }

    function showSiblingSegments(bar, stack) {
        siblingSegments(bar, stack).forEach((el) => el.classList.remove("hidden"));
    }

    function restoreBar(current) {
        applyBarBox(
            current.bar,
            current.originStart,
            current.originSpan,
            current.originStartOffset,
            current.originEndOffset,
        );
        current.bar.dataset.workItemId = current.originWorkItemId;
        current.bar.dataset.startDate = current.startDate;
        current.bar.dataset.endDate = current.endDate;
        showSiblingSegments(current.bar, current.originStack);
        if (current.originStack) {
            current.originStack.appendChild(current.bar);
            current.bar.style.top = current.originTop;
        }
    }

    function placeBarInStack(bar, stack) {
        if (bar.parentElement !== stack) {
            stack.appendChild(bar);
        }
        const others = [...stack.querySelectorAll(".person-bar")].filter(
            (el) => el !== bar && !el.classList.contains("hidden"),
        ).length;
        bar.style.top = `${4 + others * 24}px`;
        bar.dataset.workItemId = stack.dataset.workItemId || "";
    }

    function stackAt(clientX, clientY, bar) {
        bar.style.pointerEvents = "none";
        const el = document.elementFromPoint(clientX, clientY);
        bar.style.pointerEvents = "";
        const workLine = el?.closest(".plan-line--work");
        const stack =
            workLine?.querySelector(".person-stack") ||
            el?.closest(".person-stack");
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
        stack?.closest(".plan-line--work")?.classList.add("plan-line--drop");
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
        return snapPosition(
            (clientX - rect.left) / (rect.width / dayCount),
            dayCount,
        );
    }

    function previewDrag(clientX, clientY) {
        if (!drag) {
            return;
        }
        if (
            Math.abs(clientX - drag.startX) > 4 ||
            Math.abs(clientY - drag.startY) > 4
        ) {
            drag.moved = true;
        }
        if (drag.mode === "move") {
            const delta = (clientX - drag.startX) / dayWidth();
            const box = shiftBox(
                drag.originStart,
                drag.originSpan,
                drag.originStartOffset,
                drag.originEndOffset,
                delta,
                dayCount,
            );
            applyBarBox(
                drag.bar,
                box.start,
                box.span,
                box.startOffset,
                box.endOffset,
            );
            showHoursHint(clientX, clientY, intervalLabel(box));
            if (drag.moved) {
                hideSiblingSegments(drag.bar);
                previewDropTarget(clientX, clientY);
            }
            return;
        }
        let startPos = drag.originStartPos;
        let endPos = drag.originEndPos;
        const pos = pointerToPos(clientX, drag.originStack);
        if (drag.mode === "resize-start") {
            startPos = Math.min(pos, endPos - SNAP_FRACTION);
        } else {
            endPos = Math.max(pos, startPos + SNAP_FRACTION);
        }
        const box = boxFromPositions(startPos, endPos);
        applyBarBox(
            drag.bar,
            box.start,
            box.span,
            box.startOffset,
            box.endOffset,
        );
        showHoursHint(clientX, clientY, intervalLabel(box));
    }

    async function finishDrag() {
        if (!drag) {
            return;
        }
        const current = drag;
        drag = null;
        current.bar.classList.remove("is-dragging");
        clearDropHighlight();
        hideHoursHint();
        if (!current.moved) {
            if (current.mode === "move") {
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
        let startDate = current.startDate;
        let endDate = current.endDate;
        if (current.mode === "move") {
            startDate = addDays(current.startDate, dayDelta);
            endDate = addDays(current.endDate, dayDelta);
        } else if (current.mode === "resize-start") {
            startDate = dates[startIdx] || addDays(current.startDate, dayDelta);
        } else {
            const endIdx = startIdx + Math.max(1, span) - 1;
            endDate = dates[endIdx] || addDays(current.endDate, dayDelta);
        }
        const startTime = timeFromFraction(startOffset);
        const endTime = timeFromFraction(endOffset);
        const workItemId = current.bar.dataset.workItemId || "";
        const workItemChanged = workItemId !== current.originWorkItemId;
        if (
            startDate === current.startDate &&
            endDate === current.endDate &&
            startTime === String(current.startTime || "").slice(0, 5) &&
            endTime === String(current.endTime || "").slice(0, 5) &&
            !workItemChanged
        ) {
            restoreBar(current);
            return;
        }
        const ok = await save(
            assignmentUrl(current.bar.dataset.shiftId),
            "PATCH",
            {
                worker_id: Number(current.bar.dataset.workerId),
                work_item_id: workItemId ? Number(workItemId) : null,
                start_date: startDate,
                end_date: endDate,
                start_time: startTime,
                end_time: endTime,
                include_saturday: current.bar.dataset.includeSaturday === "1",
                include_sunday: current.bar.dataset.includeSunday === "1",
                is_provisional: current.bar.dataset.provisional === "1",
            },
        );
        if (ok) {
            reloadPlanningBoard(scroller);
            return;
        }
        restoreBar(current);
    }

    if (!readonly) {
        const followFinger =
            isPlanningMobileViewport((query) => window.matchMedia(query));

        if (followFinger) {
            board.querySelectorAll(".person-bar").forEach((bar) => {
                bar.addEventListener("click", (event) => {
                    if (bar.dataset.locked === "1") {
                        return;
                    }
                    event.stopPropagation();
                    openEdit(bar);
                });
            });
        } else {
        board.querySelectorAll(".person-bar").forEach((bar) => {
            bar.addEventListener("pointerdown", (event) => {
                if (bar.dataset.locked === "1") {
                    return;
                }
                const handle = event.target.closest(".bar-handle");
                const originStack = bar.closest(".person-stack");
                const originStart = Number(bar.dataset.start);
                const originSpan = Number(bar.dataset.span);
                const originStartOffset = Number(bar.dataset.startOffset || 0);
                const originEndOffset = Number(bar.dataset.endOffset || 1);
                const positions = positionsFromBox(
                    originStart,
                    originSpan,
                    originStartOffset,
                    originEndOffset,
                );
                drag = {
                    mode: handle
                        ? handle.dataset.edge === "start"
                            ? "resize-start"
                            : "resize-end"
                        : "move",
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
                    originWorkItemId: bar.dataset.workItemId || "",
                    targetStack: originStack,
                    startDate: bar.dataset.startDate,
                    endDate: bar.dataset.endDate,
                    startTime: bar.dataset.startTime || "08:00",
                    endTime: bar.dataset.endTime || "16:00",
                    moved: false,
                };
                bar.classList.add("is-dragging");
                bar.setPointerCapture(event.pointerId);
                event.preventDefault();
            });
            bar.addEventListener("pointermove", (event) =>
                previewDrag(event.clientX, event.clientY),
            );
            bar.addEventListener("pointerup", (event) => {
                if (drag?.mode === "move" && drag.moved) {
                    previewDropTarget(event.clientX, event.clientY);
                }
                finishDrag();
            });
            bar.addEventListener("pointercancel", () => {
                if (!drag) {
                    return;
                }
                const current = drag;
                drag = null;
                current.bar.classList.remove("is-dragging");
                clearDropHighlight();
                hideHoursHint();
                restoreBar(current);
            });
        });
        }

        board.querySelectorAll(".person-stack").forEach((stack) => {
            stack.addEventListener("click", (event) => {
                if (justDragged) {
                    justDragged = false;
                    return;
                }
                if (event.target.closest(".person-bar")) {
                    return;
                }
                const cell = event.target.closest(".drop-day");
                if (!cell || !stack.dataset.projectId) {
                    return;
                }
                const rect = cell.getBoundingClientRect();
                const fraction = snapPosition(
                    (event.clientX - rect.left) / Math.max(1, rect.width),
                    1,
                );
                const startTime = timeFromFraction(Math.min(0.75, fraction));
                const hours = Number(stack.dataset.hours || WORKDAY_HOURS);
                openAdd(
                    stack.dataset.projectId,
                    stack.dataset.workItemId || "",
                    cell.dataset.date,
                    startTime,
                    Number.isFinite(hours) && hours > 0 ? hours : WORKDAY_HOURS,
                );
            });
        });
    }

    whoSelect.addEventListener("change", () => {
        const option = whoSelect.selectedOptions[0];
        rememberWho(
            whoSelect.value,
            choiceForWho(
                whoSelect.value,
                option?.textContent || "",
                option?.dataset.men,
            ),
        );
        syncMenFromWho();
    });
    workSelect?.addEventListener("change", () => {
        syncProjectFromWork();
        refreshCandidates();
    });
    startInput.addEventListener("change", () => {
        syncWeeksFromDates();
        syncHoursSummary();
        refreshCandidates();
    });
    endInput.addEventListener("change", () => {
        syncWeeksFromDates();
        syncHoursSummary();
        refreshCandidates();
    });
    includeSaturdayInput?.addEventListener("change", () => {
        syncHoursSummary();
        refreshCandidates();
    });
    includeSundayInput?.addEventListener("change", () => {
        syncHoursSummary();
        refreshCandidates();
    });
    hoursSelect?.addEventListener("change", () => {
        const hours = selectedHours();
        const currentStart = selectedSlotInput()?.dataset.start || "08:00";
        renderSlotOptions(hours, currentStart);
        crewList
            .querySelectorAll("select[data-crew-hours]")
            .forEach((select) => {
                select.value = String(hours);
            });
        syncHoursSummary();
        refreshCandidates();
    });
    whenDatesInput?.addEventListener("change", () => {
        if (whenDatesInput.checked) {
            setWhenMode("dates", true);
        }
    });
    whenWeeksInput?.addEventListener("change", () => {
        if (whenWeeksInput.checked) {
            setWhenMode("weeks");
        }
    });
    [startWeekInput, endWeekInput, weekYearInput].forEach((input) => {
        const syncWeekRange = () => {
            if (weekMode()) {
                syncDatesFromWeeks();
                refreshCandidates();
            }
        };
        input?.addEventListener("change", syncWeekRange);
        input?.addEventListener("input", syncWeekRange);
    });
    bindPlanningDatePickers(startInput, endInput, {
        onChange: () => {
            syncWeeksFromDates();
            syncHoursSummary();
            refreshCandidates();
        },
    });

    function assignmentBody() {
        const [kind, id] = (whoSelect.value || "").split(":");
        if (!kind || !id) {
            return null;
        }
        const assignmentId = form.dataset.assignmentId;
        const crewIds = selectedCrewIds();
        const people = workerCrew(id);
        if (people.length >= 2 && crewIds.length === 0) {
            window.alert(
                assignmentId
                    ? "Vink aan wie er naar dit werk gaat."
                    : "Vink aan wie er naar dit project gaat.",
            );
            return null;
        }
        const workIds = selectedWorkIds();
        if (workIds.length === 0) {
            window.alert("Kies minstens één werkzaamheid.");
            return null;
        }
        const usingWeeks = weekMode();
        if (usingWeeks) {
            const year = Number(weekYearInput?.value || defaultWeekYear());
            const fromWeek = Number(startWeekInput?.value);
            const toWeek = Number(endWeekInput?.value);
            if (!fromWeek || !toWeek || !year) {
                window.alert("Vul Van week, Tot week en jaar in.");
                return null;
            }
            if (toWeek < fromWeek) {
                window.alert("Tot week moet op of na Van week liggen.");
                return null;
            }
            if (
                !workdaysForIsoWeek(year, fromWeek) ||
                !workdaysForIsoWeek(year, toWeek)
            ) {
                window.alert(`Dit weeknummer bestaat niet in ${year}.`);
                return null;
            }
            syncDatesFromWeeks();
        } else if (startInput.value > endInput.value) {
            window.alert("De einddatum moet op of na de startdatum liggen.");
            return null;
        }
        const times = selectedTimes();
        const body = {
            project_id: Number(projectInput.value),
            work_item_id: workIds[0],
            work_item_ids: workIds,
            start_date: startInput.value,
            end_date: endInput.value,
            people_count: Math.max(1, Number(menInput.value || 1)),
            include_saturday: includeSaturday(),
            include_sunday: includeSunday(),
            when: usingWeeks ? "weeks" : "dates",
            is_provisional: usingWeeks,
        };
        if (usingWeeks) {
            body.start_week = Number(startWeekInput.value);
            body.end_week = Number(endWeekInput.value);
            body.year = Number(weekYearInput.value || defaultWeekYear());
        } else {
            body.hours = times.hours;
            body.slot = times.hours >= WORKDAY_HOURS ? "full" : selectedSlot();
            body.start_time = times.start;
            body.end_time = times.end;
        }
        if (people.length >= 2) {
            body.crew_member_ids = crewIds;
            body.people_count = crewIds.length;
            if (!usingWeeks) {
                body.crew_hours = selectedCrewHours();
            }
        }
        if (foremanSelect && !rolesBox?.classList.contains("hidden")) {
            body.foreman_crew_member_id = foremanSelect.value
                ? Number(foremanSelect.value)
                : null;
        }
        if (workTicketHolderSelect && !rolesBox?.classList.contains("hidden")) {
            body.work_ticket_crew_member_id = workTicketHolderSelect.value
                ? Number(workTicketHolderSelect.value)
                : null;
        }
        if (assignmentId) {
            body.worker_id = Number(id);
        } else if (kind === "team") {
            body.team_id = Number(id);
        } else {
            body.worker_id = Number(id);
        }

        return body;
    }

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (saving) {
            return;
        }
        const body = assignmentBody();
        if (!body) {
            return;
        }
        const assignmentId = form.dataset.assignmentId;
        if (assignmentId) {
            if (await save(assignmentUrl(assignmentId), "PATCH", body)) {
                reloadPlanningBoard(scroller);
            }
            return;
        }
        if (await save(board.dataset.storeUrl, "POST", body)) {
            reloadPlanningBoard(scroller);
        }
    });

    ticketLink?.addEventListener("click", (event) => {
        const hrefAttr = ticketLink.getAttribute("href");
        if (!form.dataset.assignmentId || !hrefAttr || hrefAttr === "#") {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        window.location.assign(ticketLink.href);
    });
    ticketExisting?.addEventListener("click", (event) => {
        const hrefAttr = ticketExisting.getAttribute("href");
        if (!hrefAttr || hrefAttr === "#") {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        window.location.assign(ticketExisting.href);
    });

    document
        .getElementById("plan-cancel")
        .addEventListener("click", () => dialog.close());
    dialog.addEventListener("close", () => {
        invalidateWhoCandidates();
        rememberWho("", null);
    });

    deleteBtn.addEventListener("click", async () => {
        const assignmentId = form.dataset.assignmentId;
        if (
            !assignmentId ||
            !window.confirm("Deze inzet uit de planning halen?")
        ) {
            return;
        }
        if (await save(assignmentUrl(assignmentId), "DELETE", {})) {
            reloadPlanningBoard(scroller);
        }
    });

    const availabilityRoot = document.querySelector("[data-plan-avail]");
    if (availabilityRoot && !readonly) {
        availabilityRoot.addEventListener("click", (event) => {
            const pick = event.target.closest("[data-plan-avail-pick]");
            if (!pick) {
                return;
            }

            event.preventDefault();
            pick.closest("[popover]")?.hidePopover?.();
            const workerId = pick.dataset.workerId;
            const date = pick.dataset.date;
            if (!workerId || !date) {
                return;
            }

            const hours = snapHours(
                Number(pick.dataset.hours || WORKDAY_HOURS),
            );
            const stack = board.querySelector(".person-stack[data-project-id]");
            openAdd(
                stack?.dataset.projectId || "",
                stack?.dataset.workItemId || "",
                date,
                "08:00",
                hours,
                `worker:${workerId}`,
            );
            const crewId = Number(pick.dataset.crewId || 0);
            if (crewId > 0 && workerCrew(workerId).length >= 2) {
                renderCrew(workerId, [crewId], { [crewId]: hours });
            }
        });
    }

    bindInternalDeployment();
    bindLaborFold(board);
    bindPlanningPrint();
    bindWeekplanningExport();

    function bindInternalDeployment() {
        const dialog = document.getElementById("internal-dialog");
        const form = document.getElementById("internal-form");
        const openBtn = document.getElementById("internal-open");
        const unit = document.getElementById("internal-unit");
        const contact = document.getElementById("internal-contact");
        if (!dialog || !form || !openBtn || !unit || !contact) {
            return;
        }

        const who = document.getElementById("internal-who");
        const crewBox = document.getElementById("internal-crew");
        const crewList = document.getElementById("internal-crew-list");
        const description = document.getElementById("internal-description");
        const notes = document.getElementById("internal-notes");
        const start = document.getElementById("internal-start");
        const end = document.getElementById("internal-end");
        const saturday = document.getElementById("internal-saturday");
        const sunday = document.getElementById("internal-sunday");
        const deleteBtn = document.getElementById("internal-delete");
        const title = document.getElementById("internal-dialog-title");
        openBtn.addEventListener("click", (event) => {
            event.preventDefault();
            openCreate();
        });

        const selectedCrew = () =>
            [
                ...crewList.querySelectorAll(
                    'input[name="crew_member_ids"]:checked',
                ),
            ].map((input) => Number(input.value));

        const renderCrew = (workerId, selectedIds) => {
            const people = workerId ? workerCrew(workerId) : [];
            crewList.innerHTML = "";
            if (people.length < 1) {
                crewBox.classList.add("hidden");
                return;
            }
            crewBox.classList.remove("hidden");
            people.forEach((person) => {
                const label = document.createElement("label");
                label.className = "flex items-center gap-2 text-sm";
                const input = document.createElement("input");
                input.type = "checkbox";
                input.name = "crew_member_ids";
                input.value = String(person.id);
                input.checked = selectedIds.includes(person.id);
                label.append(input, document.createTextNode(` ${person.name}`));
                crewList.append(label);
            });
        };

        const workerIdFromWho = () => {
            const value = who.value || "";
            return value.startsWith("worker:")
                ? value.slice("worker:".length)
                : "";
        };

        who?.addEventListener("change", () => {
            const workerId = workerIdFromWho();
            const people = workerId ? workerCrew(workerId) : [];
            renderCrew(
                workerId,
                people.map((person) => person.id),
            );
        });

        const openCreate = () => {
            form.dataset.assignmentId = "";
            title.textContent = "Interne inzet";
            deleteBtn.classList.add("hidden");
            who.value = "";
            unit.value = "";
            contact.value = "";
            description.value = "";
            notes.value = "";
            start.value = dates[0] || "";
            end.value = dates[0] || "";
            saturday.checked = false;
            sunday.checked = false;
            renderCrew("", []);
            dialog.showModal();
        };

        openInternalFromBar = (bar) => {
            form.dataset.assignmentId = bar.dataset.shiftId || "";
            title.textContent = "Interne inzet aanpassen";
            deleteBtn.classList.remove("hidden");
            who.value = `worker:${bar.dataset.workerId}`;
            unit.value = bar.dataset.businessUnit || "";
            contact.value = bar.dataset.contactName || "";
            description.value = bar.dataset.description || "";
            notes.value = bar.dataset.notes || "";
            start.value = bar.dataset.startDate || "";
            end.value = bar.dataset.endDate || "";
            saturday.checked = bar.dataset.includeSaturday === "1";
            sunday.checked = bar.dataset.includeSunday === "1";
            const selected = (bar.dataset.crewIds || "")
                .split(",")
                .filter(Boolean)
                .map(Number);
            const people = workerCrew(bar.dataset.workerId);
            renderCrew(
                bar.dataset.workerId,
                selected.length ? selected : people.map((person) => person.id),
            );
            dialog.showModal();
        };

        bindPlanningDatePickers(start, end);
        document
            .getElementById("internal-cancel")
            ?.addEventListener("click", () => dialog.close());

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const workerId = workerIdFromWho();
            if (
                !workerId ||
                !unit.value ||
                !contact.value.trim() ||
                !description.value ||
                !start.value ||
                !end.value
            ) {
                return;
            }
            const body = {
                worker_id: Number(workerId),
                business_unit: unit.value,
                contact_name: contact.value.trim(),
                description: description.value,
                notes: notes.value,
                start_date: start.value,
                end_date: end.value,
                include_saturday: saturday.checked,
                include_sunday: sunday.checked,
                crew_member_ids: selectedCrew(),
            };
            const assignmentId = form.dataset.assignmentId;
            const url = assignmentId
                ? assignmentUrl(assignmentId)
                : board.dataset.internalStoreUrl;
            const method = assignmentId ? "PATCH" : "POST";
            if (await save(url, method, body)) {
                reloadPlanningBoard(scroller);
            }
        });

        deleteBtn.addEventListener("click", async () => {
            const assignmentId = form.dataset.assignmentId;
            if (
                !assignmentId ||
                !window.confirm("Deze interne inzet uit de planning halen?")
            ) {
                return;
            }
            if (await save(assignmentUrl(assignmentId), "DELETE", {})) {
                reloadPlanningBoard(scroller);
            }
        });
    }
}

function bindPlanningPrint() {
    const dialog = document.getElementById("planning-print-dialog");
    const form = document.getElementById("planning-print-form");
    const open = document.getElementById("planning-print-open");
    const cancel = document.getElementById("planning-print-cancel");
    const project = document.getElementById("planning-print-project");
    const period = document.getElementById("planning-print-period");
    if (!dialog || !form || !open) {
        return;
    }

    open.addEventListener("click", () => dialog.showModal());
    cancel?.addEventListener("click", () => dialog.close());
    project?.addEventListener("change", () => {
        if (project.value !== "" && period && period.value === "week") {
            period.value = "work";
        }
    });
    form.addEventListener("submit", () => dialog.close());
}

function bindWeekplanningExport() {
    const dialog = document.getElementById("weekplanning-dialog");
    const form = document.getElementById("weekplanning-form");
    const open = document.getElementById("weekplanning-open");
    const cancel = document.getElementById("weekplanning-cancel");
    const mail = document.getElementById("weekplanning-mail");
    const mailDialog = document.getElementById("weekplanning-mail-dialog");
    const all = document.getElementById("weekplanning-all");
    if (!dialog || !form || !open) {
        return;
    }

    const teams = () => [...form.querySelectorAll(".weekplanning-team")];

    open.addEventListener("click", () => dialog.showModal());
    cancel?.addEventListener("click", () => dialog.close());

    all?.addEventListener("change", () => {
        if (all.checked) {
            teams().forEach((input) => {
                input.checked = false;
            });
        }
    });

    form.addEventListener("change", (event) => {
        if (
            !(event.target instanceof HTMLInputElement) ||
            !event.target.classList.contains("weekplanning-team")
        ) {
            return;
        }
        if (event.target.checked && all) {
            all.checked = false;
        }
        if (all && teams().every((input) => !input.checked)) {
            all.checked = true;
        }
    });

    mail?.addEventListener("click", () => {
        if (!mailDialog) {
            return;
        }
        const mailForm = mailDialog.querySelector("form");
        const selected = teams().filter((input) => input.checked);
        const useAll = !all || all.checked || selected.length === 0;
        mailForm?.querySelectorAll('input[name="all"], input[name="teams[]"]').forEach((input) => input.remove());
        if (useAll) {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = "all";
            input.value = "1";
            mailForm?.append(input);
        } else {
            selected.forEach((team) => {
                const input = document.createElement("input");
                input.type = "hidden";
                input.name = "teams[]";
                input.value = team.value;
                mailForm?.append(input);
            });
        }
        const attachment = mailDialog.querySelector("[data-mail-attachment]");
        if (attachment) {
            const base = attachment.dataset.base || attachment.textContent || "";
            if (!attachment.dataset.base) {
                attachment.dataset.base = base;
            }
            attachment.textContent = selected.length === 1
                ? `Weekplanning – ${selected[0].parentElement?.textContent?.trim() || "geselecteerd team"}`
                : attachment.dataset.base;
        }
        dialog.close();
        mailDialog.showModal();
    });
    mailDialog?.querySelector("[data-mail-cancel]")?.addEventListener("click", () => mailDialog.close());

    form.addEventListener("submit", () => {
        const useAll =
            !all || all.checked || teams().every((input) => !input.checked);
        teams().forEach((input) => {
            input.disabled = useAll;
        });
        if (all) {
            all.disabled = !useAll;
            all.checked = useAll;
        }
        dialog.close();
        window.setTimeout(() => {
            teams().forEach((input) => {
                input.disabled = false;
            });
            if (all) {
                all.disabled = false;
            }
        }, 0);
    });
}

bindPlanningFilterSearch(document);
