/**
 * @typedef {{value: string, label: string, search?: string}} PlanningSearchOption
 */

/**
 * @param {string} value
 */
export function normalizePlanningSearch(value) {
    return String(value ?? "")
        .replace(/[\u00a0\u202f]/g, " ")
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase();
}

/**
 * Every typed word must occur somewhere in the option text.
 *
 * @param {string} label
 * @param {string} query
 */
export function planningSearchMatches(label, query) {
    const haystack = normalizePlanningSearch(label);
    const tokens = normalizePlanningSearch(query)
        .split(/\s+/)
        .filter((token) => token !== "");

    return tokens.every((token) => haystack.includes(token));
}

/**
 * @param {PlanningSearchOption} option
 */
export function planningOptionHaystack(option) {
    return [option?.label ?? "", option?.search ?? ""]
        .filter((part) => part !== "")
        .join(" ");
}

/**
 * @param {PlanningSearchOption[]} options
 * @param {string} query
 * @returns {PlanningSearchOption[]}
 */
export function filterPlanningOptions(options, query) {
    return (Array.isArray(options) ? options : []).filter((option) =>
        planningSearchMatches(planningOptionHaystack(option), query),
    );
}

/**
 * @param {ParentNode} [root]
 */
export function bindPlanningFilterSearch(root = document) {
    root.querySelectorAll("select[data-planning-search]").forEach((select) => {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }
        if (select.dataset.planningSearchReady === "1") {
            return;
        }
        select.dataset.planningSearchReady = "1";
        mountPlanningSearch(select);
    });
}

/**
 * @param {HTMLSelectElement} select
 */
function mountPlanningSearch(select) {
    const options = [...select.options].map((option) => ({
        value: option.value,
        label: option.textContent?.trim() ?? "",
        search: option.dataset.search ?? "",
    }));
    const listId = `${select.name || "planning"}-search-list`;
    const wrap = document.createElement("div");
    wrap.className = "planning-search";

    const input = document.createElement("input");
    input.type = "text";
    input.className = "planning-filter planning-search-input";
    const placeholder =
        select.dataset.searchPlaceholder ||
        "Zoek op projectnr., werk, opdrachtgever of adres";
    input.placeholder = placeholder;
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-expanded", "false");
    input.setAttribute("aria-controls", listId);
    input.setAttribute("aria-label", placeholder);
    input.autocomplete = "off";
    input.spellcheck = false;

    const list = document.createElement("ul");
    list.id = listId;
    list.className = "planning-search-list";
    list.hidden = true;
    list.setAttribute("role", "listbox");

    select.hidden = true;
    select.before(wrap);
    wrap.append(input, list, select);

    let active = -1;
    let choosing = false;
    let editing = false;

    const labelFor = (value) =>
        options.find((option) => option.value === value)?.label ?? "";

    const showSelected = () => {
        input.value = select.value === "" ? "" : labelFor(select.value);
        input.title = input.value;
    };

    const setOpen = (open) => {
        list.hidden = !open;
        wrap.classList.toggle("is-open", open);
        input.setAttribute("aria-expanded", open ? "true" : "false");
        if (open) {
            placeList();
        }
    };

    const placeList = () => {
        list.style.left = "0";
        list.style.right = "auto";
        const rect = list.getBoundingClientRect();
        if (rect.right > window.innerWidth - 8) {
            list.style.left = "auto";
            list.style.right = "0";
        }
    };

    const items = () => [...list.querySelectorAll(".planning-search-option")];

    const setActive = (index) => {
        const rows = items();
        active = rows.length === 0 ? -1 : Math.max(0, Math.min(index, rows.length - 1));
        rows.forEach((row, rowIndex) => {
            const on = rowIndex === active;
            row.classList.toggle("is-active", on);
            row.setAttribute("aria-selected", on ? "true" : "false");
            if (on) {
                input.setAttribute("aria-activedescendant", row.id);
                const top = row.offsetTop;
                const bottom = top + row.offsetHeight;
                if (top < list.scrollTop) {
                    list.scrollTop = top;
                } else if (bottom > list.scrollTop + list.clientHeight) {
                    list.scrollTop = bottom - list.clientHeight;
                }
            }
        });
        if (active < 0) {
            input.removeAttribute("aria-activedescendant");
        }
    };

    const render = (query) => {
        const matches = filterPlanningOptions(options, query);
        list.replaceChildren();
        if (matches.length === 0) {
            const empty = document.createElement("li");
            empty.className = "planning-search-empty";
            empty.textContent = "Niets gevonden";
            list.append(empty);
            active = -1;
            input.removeAttribute("aria-activedescendant");
            return;
        }

        matches.forEach((option, index) => {
            const item = document.createElement("li");
            item.className = "planning-search-option";
            item.id = `${listId}-${index}`;
            item.setAttribute("role", "option");
            item.dataset.value = option.value;
            item.textContent = option.label;
            item.addEventListener("mousedown", (event) => {
                event.preventDefault();
                choose(option.value);
            });
            list.append(item);
        });

        if (query.trim() !== "") {
            setActive(0);
            return;
        }

        const current = matches.findIndex((option) => option.value === select.value);
        setActive(current >= 0 ? current : 0);
    };

    const choose = (value) => {
        choosing = true;
        const unchanged = select.value === value;
        select.value = value;
        showSelected();
        setOpen(false);
        if (!unchanged) {
            select.form?.submit();
        }
        choosing = false;
    };

    input.addEventListener("focus", () => {
        editing = false;
        render("");
        setOpen(true);
        input.select();
    });

    input.addEventListener("input", () => {
        editing = true;
        render(input.value);
        setOpen(true);
    });

    input.addEventListener("keydown", (event) => {
        const printable = event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey;
        if (printable && !editing) {
            const allSelected = input.selectionStart === 0 && input.selectionEnd === input.value.length && input.value !== "";
            if (!allSelected) {
                event.preventDefault();
                input.value = event.key;
                editing = true;
                render(input.value);
                setOpen(true);
                return;
            }
        }

        if (!editing && (event.key === "Backspace" || event.key === "Delete")) {
            event.preventDefault();
            input.value = "";
            editing = true;
            render("");
            setOpen(true);
            return;
        }

        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();
            if (list.hidden) {
                render(editing ? input.value : "");
                setOpen(true);
            }
            const rows = items();
            if (rows.length === 0) {
                return;
            }
            const next =
                event.key === "ArrowDown"
                    ? active + 1
                    : active - 1;
            setActive((next + rows.length) % rows.length);
            return;
        }

        if (event.key === "Enter") {
            event.preventDefault();
            const chosen = items()[active];
            if (chosen) {
                choose(chosen.dataset.value ?? "");
            }
            return;
        }

        if (event.key === "Escape") {
            event.preventDefault();
            editing = false;
            showSelected();
            setOpen(false);
        }
    });

    input.addEventListener("blur", () => {
        if (choosing) {
            return;
        }
        editing = false;
        showSelected();
        setOpen(false);
    });

    showSelected();
}
