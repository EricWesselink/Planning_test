function minutes(value) {
    const match = /^(\d{1,2}):(\d{2})/.exec(value || "");
    if (!match) {
        return null;
    }

    return Number(match[1]) * 60 + Number(match[2]);
}

function formatTotal(hours) {
    const rounded = Math.round(hours * 100) / 100;
    const text = Number.isInteger(rounded)
        ? String(rounded)
        : String(rounded).replace(".", ",");

    return `Totaal ${text} uur`;
}

function bindClock(root) {
    const start = root.querySelector("[data-clock-start]");
    const end = root.querySelector("[data-clock-end]");
    const pause = root.querySelector("[data-clock-break]");
    const total = root.querySelector("[data-clock-total]");
    if (!start || !end || !total) {
        return;
    }

    const update = () => {
        const from = minutes(start.value);
        const to = minutes(end.value);
        const breakMinutes = Math.max(0, Number(pause?.value || 0));
        if (from === null || to === null || to < from || to - from < breakMinutes) {
            total.textContent = "Totaal —";
            return;
        }

        total.textContent = formatTotal((to - from - breakMinutes) / 60);
    };

    [start, end, pause].forEach((field) => field?.addEventListener("input", update));
    update();
}

document.querySelectorAll("[data-hours-clock]").forEach(bindClock);
