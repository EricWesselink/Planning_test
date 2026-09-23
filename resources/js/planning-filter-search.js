/**
 * @typedef {{value: string, label: string, search?: string, title?: string, meta?: string, finished?: boolean}} PlanningSearchOption
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
    const list = Array.isArray(options) ? options : [];
    const browsing = normalizePlanningSearch(query).trim() === "";

    return list.filter((option) => {
        if (!browsing && String(option?.value ?? "") === "") {
            return false;
        }

        return planningSearchMatches(planningOptionHaystack(option), query);
    });
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
        title: option.dataset.title ?? "",
        meta: option.dataset.meta ?? "",
        finished: option.dataset.finished === "1",
    }));
    const listId = `${select.name || "planning"}-search-list`;
    const wrap = document.createElement("div");
    wrap.className = "planning-search";

    const field = document.createElement("div");
    field.className = "planning-search-field";

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

    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "planning-search-toggle";
    toggle.setAttribute("aria-label", "Alle werken tonen");
    toggle.setAttribute("aria-controls", listId);
    toggle.setAttribute("aria-expanded", "false");
    toggle.innerHTML = '<span aria-hidden="true">▼</span>';

    const list = document.createElement("ul");
    list.id = listId;
    list.className = "planning-search-list";
    list.hidden = true;
    list.setAttribute("role", "listbox");

    select.hidden = true;
    select.before(wrap);
    field.append(input, toggle);
    wrap.append(field, list, select);

    let active = -1;
    let choosing = false;
    let editing = false;
    let suppressFocusOpen = false;

    const showSelected = () => {
        const selected = options.find((option) => option.value === select.value);
        const title = selected?.title || selected?.label || "";
        input.value = select.value === "" ? "" : title;
        input.title = [input.value, selected?.meta ?? ""].filter((part) => part !== "").join("\n");
    };

    const setOpen = (open) => {
        list.hidden = !open;
        wrap.classList.toggle("is-open", open);
        const expanded = open ? "true" : "false";
        input.setAttribute("aria-expanded", expanded);
        toggle.setAttribute("aria-expanded", expanded);
        if (open) {
            placeList();
        }
    };

    const placeList = () => {
        list.style.left = "0";
        list.style.right = "auto";
        list.style.maxHeight = "400px";
        const rect = list.getBoundingClientRect();
        if (rect.right > window.innerWidth - 8) {
            list.style.left = "auto";
            list.style.right = "0";
        }
        const room = window.innerHeight - list.getBoundingClientRect().top - 8;
        list.style.maxHeight = `${Math.max(160, Math.min(400, room))}px`;
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

            const line = document.createElement("span");
            line.className = "planning-search-line";
            const title = document.createElement("span");
            title.className = "planning-search-title";
            title.textContent = option.title || option.label;
            line.append(title);
            if (option.finished) {
                const badge = document.createElement("span");
                badge.className = "planning-search-badge";
                badge.textContent = "Afgerond";
                line.append(badge);
            }
            item.append(line);

            if (option.meta) {
                const meta = document.createElement("span");
                meta.className = "planning-search-meta";
                meta.textContent = option.meta;
                item.append(meta);
            }

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

    const showAll = () => {
        editing = false;
        showSelected();
        render("");
        setOpen(true);
        list.scrollTop = 0;
    };

    toggle.addEventListener("mousedown", (event) => {
        event.preventDefault();
    });

    toggle.addEventListener("click", () => {
        if (!list.hidden && !editing) {
            editing = false;
            showSelected();
            setOpen(false);
            return;
        }
        suppressFocusOpen = true;
        input.focus({ preventScroll: true });
        suppressFocusOpen = false;
        showAll();
    });

    input.addEventListener("focus", () => {
        if (suppressFocusOpen) {
            return;
        }
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
