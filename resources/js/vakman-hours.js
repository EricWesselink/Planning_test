function minutes(value) {
    const match = /^(\d{1,2}):(\d{2})/.exec(value || "");
    if (!match) {
        return null;
    }

    return Number(match[1]) * 60 + Number(match[2]);
}

function hourText(hours) {
    const rounded = Math.round(hours * 100) / 100;
    const text = Number.isInteger(rounded)
        ? String(rounded)
        : String(rounded).replace(".", ",");

    return `${text} uur`;
}

function clockNet(start, end, pause) {
    const from = minutes(start.value);
    const to = minutes(end.value);
    const breakMinutes = Math.max(0, Number(pause?.value || 0));
    if (from === null || to === null || to < from || to - from < breakMinutes) {
        return null;
    }

    return Math.round(((to - from - breakMinutes) / 60) * 100) / 100;
}

function bindClock(root) {
    const start = root.querySelector("[data-clock-start]");
    const end = root.querySelector("[data-clock-end]");
    const pause = root.querySelector("[data-clock-break]");
    const total = root.querySelector("[data-clock-total]");
    const inputs = [...root.querySelectorAll("[data-split-hours]")];
    const remainder = root.querySelector("[data-split-remainder]");
    const button = root.querySelector("[data-split-submit]");
    if (!start || !end || !total) {
        return;
    }

    const update = () => {
        const net = clockNet(start, end, pause);
        if (net === null) {
            total.textContent = "Totaal —";
        } else {
            total.textContent = `Totaal: ${hourText(net)}`;
        }

        if (inputs.length === 1 && inputs[0].dataset.touched !== "1" && net !== null && (inputs[0].value === "" || inputs[0].dataset.autofilled === "1")) {
            inputs[0].value = String(net);
            inputs[0].dataset.autofilled = "1";
        }

        if (!remainder) {
            return;
        }

        if (net === null) {
            remainder.textContent = "";
            remainder.className = "vakman-hours-remainder";
            if (button) {
                button.disabled = true;
            }
            return;
        }

        const sum = Math.round(inputs.reduce((totalHours, input) => totalHours + Number(input.value || 0), 0) * 100) / 100;
        const delta = Math.round((net - sum) * 100) / 100;
        if (Math.abs(delta) < 0.01) {
            remainder.textContent = `Totaal verdeeld: ${hourText(sum)}`;
            remainder.className = "vakman-hours-remainder is-ok";
            if (button) {
                button.disabled = false;
            }
            return;
        }

        remainder.textContent = delta > 0
            ? `Nog ${hourText(delta)} verdelen`
            : `${hourText(Math.abs(delta))} te veel verdeeld`;
        remainder.className = "vakman-hours-remainder is-bad";
        if (button) {
            button.disabled = true;
        }
    };

    inputs.forEach((input) => {
        input.addEventListener("input", () => {
            input.dataset.touched = "1";
            input.dataset.autofilled = "0";
            update();
        });
    });
    [start, end, pause].forEach((field) => field?.addEventListener("input", update));
    update();
}

document.querySelectorAll("[data-hours-clock]").forEach(bindClock);
